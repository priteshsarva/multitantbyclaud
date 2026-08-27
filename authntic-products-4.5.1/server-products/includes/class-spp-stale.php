<?php
if (!defined('ABSPATH')) exit;

/**
 * STALE-PRODUCT SWEEP
 * Marks plugin-managed products OUT OF STOCK when the scraper has not refreshed
 * them for N days, on the reasoning that a product our scrapers stopped seeing
 * has probably been delisted or sold out at the supplier.
 *
 * SAFETY RAILS (this touches live stock levels, so each one matters):
 *   1. MANAGED ONLY. Every query is filtered on SPP_MANAGED = '1'. Products
 *      added by hand in WooCommerce have no such meta and are never touched.
 *   2. NEVER DELETES. Only stock status changes; the product stays visible and
 *      flips back to in-stock automatically the moment sync sees it again.
 *   3. DRY RUN. Preview the exact count before anything is written.
 *   4. MASS-MARK GUARD. If a run would mark more than a configurable share of
 *      the catalogue, it aborts — that pattern means the scrapers stopped
 *      running, not that the whole catalogue sold out.
 *   5. LOGGED. Every run records when, what triggered it, how many were scanned
 *      and marked, and whether the guard fired.
 *
 * THE TIMESTAMP: `_spp_ts` holds the server's productLastUpdated as ms-epoch
 * (e.g. 1785010678235). Compared numerically against a cutoff in ms.
 */
class SPP_Stale {

    const OPT_ENABLED   = 'spp_stale_enabled';      // 'yes' | 'no'
    const OPT_DAYS      = 'spp_stale_days';         // staleness threshold, days
    const OPT_EVERY     = 'spp_stale_every_days';   // auto-run interval, days
    const OPT_MAXPCT    = 'spp_stale_max_pct';      // abort if run would exceed this % of managed catalogue
    const OPT_LAST_RUN  = 'spp_stale_last_run';     // unix seconds
    const OPT_LOG       = 'spp_stale_log';          // array of recent runs (newest first)
    const BATCH         = 200;                      // products per query page
    /** wall-clock seconds one sweep may spend marking, before it stops and resumes later */
    const RUN_BUDGET    = 20;

    public static function enabled()  { return get_option(self::OPT_ENABLED, 'no') === 'yes'; }
    public static function days()     { return max(1, (int) get_option(self::OPT_DAYS, 3)); }
    public static function every()    { return max(1, (int) get_option(self::OPT_EVERY, 2)); }
    public static function max_pct()  { return min(100, max(1, (int) get_option(self::OPT_MAXPCT, 40))); }
    public static function last_run() { return (int) get_option(self::OPT_LAST_RUN, 0); }
    public static function log()      { $l = get_option(self::OPT_LOG, array()); return is_array($l) ? $l : array(); }

    public static function init() {
        add_action('admin_post_spp_stale_run', array(__CLASS__, 'handle_button'));
    }

    /** cutoff in ms-epoch: anything older than this is stale */
    public static function cutoff_ms() {
        return (time() - self::days() * DAY_IN_SECONDS) * 1000;
    }

    /** due for its automatic run? */
    public static function due() {
        if (!self::enabled()) return false;
        $last = self::last_run();
        if ($last === 0) return true;                       // never run
        return (time() - $last) >= self::every() * DAY_IN_SECONDS;
    }

    /** how many managed products exist at all (denominator for the guard) */
    public static function managed_total() {
        $q = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
            'meta_query'     => array(array('key' => SPP_MANAGED, 'value' => '1')),
        ));
        return (int) $q->found_posts;
    }

    /**
     * Managed + in-stock + timestamp older than the cutoff.
     * Products with NO _spp_ts at all are deliberately EXCLUDED: a missing
     * timestamp means "we never recorded one", not "it is ancient", and
     * treating it as stale would wipe out anything imported before this
     * feature existed.
     */
    private static function find_stale($limit, $offset = 0) {
        $q = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'no_found_rows'  => false,
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => SPP_MANAGED, 'value' => '1'),
                array('key' => '_spp_ts', 'compare' => 'EXISTS'),
                array('key' => '_spp_ts', 'value' => (string) self::cutoff_ms(), 'compare' => '<', 'type' => 'NUMERIC'),
                array('key' => '_stock_status', 'value' => 'instock'),
            ),
        ));
        return array('ids' => $q->posts, 'total' => (int) $q->found_posts);
    }

    /**
     * Run the sweep.
     * @param bool   $dry      true = count only, write nothing
     * @param string $trigger  'manual' | 'auto'
     */
    public static function sweep($dry = false, $trigger = 'manual') {
        $started  = time();
        $days     = self::days();
        // adopt SPP- SKU products missing the managed flag so this sweep covers them too
        if (class_exists('SPP_Product')) SPP_Product::adopt_orphans(2000);
        $first    = self::find_stale(self::BATCH, 0);
        $candidates = $first['total'];
        $managed  = self::managed_total();

        // ---- mass-mark guard -------------------------------------------------
        // Marking a huge share of the catalogue out of stock almost never means
        // the supplier delisted everything; it means the scrapers stopped, or a
        // feed broke. Refuse and tell the operator instead of nuking the shop.
        $pct = $managed > 0 ? ($candidates / $managed) * 100 : 0;
        $blocked = (!$dry && $managed > 0 && $pct > self::max_pct());

        $marked = 0;
        if (!$dry && !$blocked && $candidates > 0) {
            // Page from offset 0 each time: marking a product flips it out of
            // 'instock' so it leaves the result set. `$done` guarantees progress
            // even if that write does not propagate instantly (object cache, a
            // filter, an unusual product type) — without it the loop would
            // re-save the same rows until the iteration cap.
            // BUDGETED. This loop used to be bounded only by 500 iterations of
            // BATCH(200) — up to 100,000 wc_get_product() + save() calls inside a
            // SINGLE request, with no cache suspension. That is the most expensive
            // operation WooCommerce offers, run in the worst possible way: it pins
            // the CPU and climbs to memory_limit until the request is killed.
            //
            // Now it takes the same treatment run_batch() does — cache suspended,
            // term counting deferred, a wall-clock budget, and a memory guard.
            // Stopping early is harmless: the query re-selects whatever is still
            // stale on the next run, so progress simply continues.
            if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(true);
            if (function_exists('wp_defer_term_counting'))    wp_defer_term_counting(true);

            $started_at = microtime(true);
            $done  = array();
            $guard = 0;
            while ($guard++ < 500) {
                if ((microtime(true) - $started_at) > self::RUN_BUDGET) break;
                if (SPP_Sync::memory_exhausted()) break;

                $batch = self::find_stale(self::BATCH, 0);
                if (empty($batch['ids'])) break;

                $fresh = 0;
                foreach ($batch['ids'] as $pid) {
                    if (isset($done[$pid])) continue;   // already handled this run
                    $done[$pid] = true;
                    $fresh++;

                    if ((microtime(true) - $started_at) > self::RUN_BUDGET) break;

                    $p = wc_get_product($pid);
                    if (!$p) continue;
                    // paranoia: re-verify the managed flag before touching stock
                    if (!SPP_Product::is_managed($pid)) continue;

                    $p->set_stock_status('outofstock');
                    $p->save();
                    if (class_exists('SPP_Purge')) SPP_Purge::mark_oos_meta($pid, 'outofstock');
                    update_post_meta($pid, 'sppStaleMarkedAt', (string) (time() * 1000));
                    update_post_meta($pid, 'sppStaleMarkedAtHuman', SPP_Product::ms_to_human(time() * 1000));
                    $marked++;
                }
                SPP_Sync::free_memory();
                if ($fresh === 0) break; // nothing new in this page -> we are done
            }

            // flush the deferred term counts once, and put the cache back
            if (function_exists('wp_defer_term_counting'))    wp_defer_term_counting(false);
            if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(false);
        }

        $entry = array(
            'at'         => $started,
            'at_human'   => date_i18n('Y-m-d H:i', $started + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)),
            'trigger'    => $trigger,
            'dry'        => (bool) $dry,
            'days'       => $days,
            'managed'    => $managed,
            'candidates' => $candidates,
            'marked'     => $marked,
            'blocked'    => $blocked,
            'pct'        => round($pct, 1),
            'duration'   => time() - $started,
        );

        if (!$dry) {
            update_option(self::OPT_LAST_RUN, $started, false);
            $log = self::log();
            array_unshift($log, $entry);
            update_option(self::OPT_LOG, array_slice($log, 0, 20), false); // keep last 20 runs
        }
        return $entry;
    }

    /** admin button handler (Settings -> Authntic Products) */
    public static function handle_button() {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
        check_admin_referer('spp_stale_run');
        $dry = isset($_POST['dry']) && $_POST['dry'] === '1';
        $r   = self::sweep($dry, $dry ? 'manual-dry' : 'manual');

        $msg = $dry
            ? sprintf('dry:%d:%d', $r['candidates'], $r['managed'])
            : ($r['blocked'] ? sprintf('blocked:%d:%s', $r['candidates'], $r['pct'])
                             : sprintf('done:%d', $r['marked']));
        wp_safe_redirect(add_query_arg('spp_stale', rawurlencode($msg),
            admin_url('admin.php?page=server-products')));
        exit;
    }

    /** notice shown after the button runs */
    public static function admin_notice() {
        if (empty($_GET['spp_stale'])) return;
        $parts = explode(':', sanitize_text_field(wp_unslash($_GET['spp_stale'])));
        $kind  = $parts[0];
        if ($kind === 'dry') {
            printf('<div class="notice notice-info"><p><strong>Dry run:</strong> %d of %d managed products are older than %d days and would be marked out of stock. Nothing was changed.</p></div>',
                (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), self::days());
        } elseif ($kind === 'blocked') {
            printf('<div class="notice notice-error"><p><strong>Sweep aborted.</strong> It would have marked %d products (%s%% of the catalogue), above the %d%% safety limit. That usually means the scrapers stopped rather than the stock selling out — check the server before re-running.</p></div>',
                (int) ($parts[1] ?? 0), esc_html($parts[2] ?? '?'), self::max_pct());
        } elseif ($kind === 'done') {
            printf('<div class="notice notice-success"><p><strong>Sweep complete.</strong> %d product(s) marked out of stock.</p></div>',
                (int) ($parts[1] ?? 0));
        }
    }

    // ---------- per-product diagnostics box ----------
    public static function meta_box() {
        add_meta_box('spp_sync_info', 'Authntic Products — sync info',
            array(__CLASS__, 'render_box'), 'product', 'side', 'default');
    }

    public static function render_box($post) {
        if (!SPP_Product::is_managed($post->ID)) {
            echo '<p><em>Not an Authntic Products item — manually created, never touched by sync or the stale sweep.</em></p>';
            return;
        }
        $ts     = (string) get_post_meta($post->ID, '_spp_ts', true);
        $seen   = (string) get_post_meta($post->ID, 'sppLastSynced', true);
        $marked = (string) get_post_meta($post->ID, 'sppStaleMarkedAtHuman', true);
        $ageD   = $ts !== '' ? floor((time() - ($ts / 1000)) / DAY_IN_SECONDS) : null;
        $stale  = ($ageD !== null && $ageD >= self::days());

        echo '<table style="width:100%;font-size:12px;line-height:1.6">';
        printf('<tr><td>Scraper updated</td><td align="right"><strong>%s</strong></td></tr>',
            esc_html(SPP_Product::ms_to_human($ts) ?: '—'));
        printf('<tr><td>Age</td><td align="right"><strong style="color:%s">%s</strong></td></tr>',
            $stale ? '#b71c1c' : '#2e7d32',
            $ageD === null ? '—' : esc_html($ageD . ' day' . ($ageD === 1 ? '' : 's')));
        printf('<tr><td>Store last synced</td><td align="right">%s</td></tr>',
            esc_html(SPP_Product::ms_to_human($seen) ?: '—'));
        printf('<tr><td>Source id</td><td align="right">%s</td></tr>',
            esc_html((string) get_post_meta($post->ID, SPP_SOURCE_ID, true)));
        printf('<tr><td>Source db</td><td align="right">%s</td></tr>',
            esc_html((string) get_post_meta($post->ID, SPP_SOURCE_DB, true)));
        if ($marked !== '') {
            printf('<tr><td colspan="2" style="color:#b71c1c;padding-top:6px">Marked out of stock as stale on %s</td></tr>', esc_html($marked));
        }
        echo '</table>';
        printf('<p style="margin:8px 0 0;color:#666">Stale threshold: %d days. %s</p>',
            self::days(),
            self::enabled() ? 'Auto-sweep is ON.' : 'Auto-sweep is OFF.');
    }
}
