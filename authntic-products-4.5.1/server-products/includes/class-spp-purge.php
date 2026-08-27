<?php
if (!defined('ABSPATH')) exit;

/**
 * DELETE LONG-DEAD PRODUCTS
 * Permanently deletes plugin-managed products that have been OUT OF STOCK for
 * more than N days (default 30). Companion to SPP_Stale, which only MARKS items
 * out of stock; this reaps the ones that have then stayed out of stock long
 * enough to be considered gone for good.
 *
 * SAME SAFETY RAILS AS THE STALE SWEEP (this DELETES, so they matter even more):
 *   1. MANAGED ONLY   — every query is filtered on SPP_MANAGED = '1'. Products
 *                       created by hand in WooCommerce are never touched.
 *   2. OUT OF STOCK ONLY — re-verified on each product immediately before delete.
 *   3. DRY RUN        — preview the exact count before anything is removed.
 *   4. MASS-DELETE GUARD — abort if a run would remove more than a configurable
 *                       share of the catalogue (that pattern means the scrapers
 *                       stopped, not that the whole shop sold out).
 *   5. BUDGETED       — a wall-clock budget + memory guard, resumes next run.
 *   6. LOGGED         — every run is recorded.
 *
 * DELETE CRITERIA — a product is removed only when ALL hold:
 *   • it's ours              (SPP_MANAGED / SPP- SKU)
 *   • out of stock           (_stock_status = 'outofstock', which is exactly the
 *                             server "availability" flag — sync maps availability
 *                             -> WooCommerce stock status)
 *   • stale                  (_spp_ts, the scraper's last-update ms-epoch, is
 *                             older than the threshold — the product hasn't been
 *                             refreshed/seen for N days). Uses the REAL timestamp,
 *                             so it works on existing products immediately.
 * Products with no _spp_ts are excluded, so nothing un-timestamped is ever nuked.
 */
class SPP_Purge {

    const OPT_ENABLED  = 'spp_purge_enabled';    // 'yes' | 'no' — automatic runs
    const OPT_EVERY    = 'spp_purge_every_days'; // auto-run interval, days
    const OPT_DAYS     = 'spp_purge_days';       // out-of-stock threshold, days
    const OPT_MAXPCT   = 'spp_purge_max_pct';    // abort above this % of catalogue
    const OPT_LAST_RUN = 'spp_purge_last_run';   // unix seconds
    const OPT_LOG      = 'spp_purge_log';         // recent runs (newest first)
    const OPT_DRAINING = 'spp_purge_draining';   // 'yes' while a backlog is still being cleared
    const META_OOS     = '_spp_oos_since';        // ms-epoch when it went out of stock
    const BATCH        = 200;
    const RUN_BUDGET   = 20;                       // seconds of deleting per request

    public static function enabled()  { return get_option(self::OPT_ENABLED, 'no') === 'yes'; }
    public static function every()    { return max(1, (int) get_option(self::OPT_EVERY, 7)); }
    public static function days()     { return max(1, (int) get_option(self::OPT_DAYS, 30)); }
    public static function max_pct()  { return min(100, max(1, (int) get_option(self::OPT_MAXPCT, 40))); }
    public static function last_run() { return (int) get_option(self::OPT_LAST_RUN, 0); }
    public static function log()      { $l = get_option(self::OPT_LOG, array()); return is_array($l) ? $l : array(); }

    public static function init() {
        add_action('admin_post_spp_purge_run', array(__CLASS__, 'handle_button'));
    }

    /** due for an automatic run? (only when enabled and the interval has elapsed) */
    public static function due() {
        if (!self::enabled()) return false;
        $last = self::last_run();
        if ($last === 0) return true;                       // enabled but never run
        return (time() - $last) >= self::every() * DAY_IN_SECONDS;
    }

    /** anything with _spp_oos_since older than this (ms-epoch) is deletable */
    public static function cutoff_ms() {
        return (time() - self::days() * DAY_IN_SECONDS) * 1000;
    }

    /**
     * Keep `_spp_oos_since` correct. Call wherever stock status is set:
     *   - going OUT of stock  -> stamp it (only if not already stamped, so the
     *                            original out-of-stock time is preserved)
     *   - back IN stock       -> clear it (the clock resets)
     */
    public static function mark_oos_meta($pid, $stock_status) {
        if ($stock_status === 'outofstock') {
            if (get_post_meta($pid, self::META_OOS, true) === '') {
                update_post_meta($pid, self::META_OOS, (string) (time() * 1000));
            }
        } elseif (get_post_meta($pid, self::META_OOS, true) !== '') {
            delete_post_meta($pid, self::META_OOS);
        }
    }

    private static function mem_guard() {
        return method_exists('SPP_Sync', 'memory_exhausted') ? SPP_Sync::memory_exhausted() : false;
    }

    /** total managed products (denominator for the mass-delete guard) */
    public static function managed_total() {
        $q = new WP_Query(array(
            'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => 1, 'no_found_rows' => false,
            'meta_query' => array(array('key' => SPP_MANAGED, 'value' => '1')),
        ));
        return (int) $q->found_posts;
    }

    /**
     * The delete set: a product qualifies when ALL are true —
     *   1. ours          (SPP_MANAGED)
     *   2. out of stock  (_stock_status = outofstock — this is exactly the
     *                     server "availability" flag; sync maps availability -> stock)
     *   3. stale         (_spp_ts, the scraper's last-update ms-epoch, is older
     *                     than the threshold — i.e. it hasn't been refreshed/seen
     *                     for N days). Products with no _spp_ts are EXCLUDED, just
     *                     like the stale sweep, so nothing un-timestamped is nuked.
     */
    private static function find($limit) {
        $q = new WP_Query(array(
            'post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids',
            'posts_per_page' => $limit, 'no_found_rows' => false,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => SPP_MANAGED, 'value' => '1'),
                array('key' => '_stock_status', 'value' => 'outofstock'),
                array('key' => '_spp_ts', 'compare' => 'EXISTS'),
                array('key' => '_spp_ts', 'value' => (string) self::cutoff_ms(), 'compare' => '<', 'type' => 'NUMERIC'),
            ),
        ));
        return array('ids' => $q->posts, 'total' => (int) $q->found_posts);
    }

    /**
     * @param bool   $dry      true = count only, delete nothing
     * @param string $trigger  'manual' | 'manual-dry' | 'auto'
     */
    public static function purge($dry = false, $trigger = 'manual') {
        $started    = time();
        $days       = self::days();

        // adopt any SPP- SKU products missing the managed flag, so this run also
        // covers products whose source site was removed (SKU is the ownership proof)
        if (class_exists('SPP_Product')) SPP_Product::adopt_orphans(2000);

        // budget clock starts AFTER adoption, so the whole window goes to deletes
        $started_at = microtime(true);

        $first      = self::find(self::BATCH);
        $candidates = $first['total'];
        $managed    = self::managed_total();

        // ---- mass-delete guard ----
        $pct     = $managed > 0 ? ($candidates / $managed) * 100 : 0;
        $blocked = (!$dry && $managed > 0 && $pct > self::max_pct());

        $deleted = 0;
        if (!$dry && !$blocked && $candidates > 0) {
            // let a big pass run long without the host killing the request mid-way;
            // every wp_delete_post commits immediately, so progress is never lost.
            @set_time_limit(0);
            if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
            if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(true);
            if (function_exists('wp_defer_term_counting'))    wp_defer_term_counting(true);

            $seen  = array();
            $guard = 0;
            while ($guard++ < 500) {
                if ((microtime(true) - $started_at) > self::RUN_BUDGET) break;
                if (self::mem_guard()) break;

                $batch = self::find(self::BATCH);
                if (empty($batch['ids'])) break;

                $fresh = 0;
                foreach ($batch['ids'] as $pid) {
                    if (isset($seen[$pid])) continue; // deleted rows leave the set; guard progress anyway
                    $seen[$pid] = true; $fresh++;
                    if ((microtime(true) - $started_at) > self::RUN_BUDGET) break;

                    // re-verify managed + still out of stock right before deleting
                    if (!SPP_Product::is_managed($pid)) continue;
                    if (get_post_meta($pid, '_stock_status', true) !== 'outofstock') continue;

                    wp_delete_post($pid, true); // permanent (bypass trash)
                    $deleted++;
                }
                if ($fresh === 0) break;
            }

            if (function_exists('wp_defer_term_counting'))    wp_defer_term_counting(false);
            if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(false);
        }

        // how many still qualify after this pass. On a real run, if there are more
        // AND we weren't blocked, set the drain flag so the heartbeat keeps deleting
        // in the background until it's clear — no need to click 200 times.
        $remaining = $dry ? max(0, $candidates - $deleted) : self::find(1)['total'];
        if (!$dry) {
            update_option(self::OPT_DRAINING, ($remaining > 0 && !$blocked) ? 'yes' : 'no', false);
        }

        $entry = array(
            'at'         => $started,
            'at_human'   => date_i18n('Y-m-d H:i', $started + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)),
            'trigger'    => $trigger,
            'dry'        => (bool) $dry,
            'days'       => $days,
            'managed'    => $managed,
            'candidates' => $candidates,
            'deleted'    => $deleted,
            'remaining'  => $remaining,
            'blocked'    => $blocked,
            'pct'        => round($pct, 1),
            'duration'   => time() - $started,
        );

        if (!$dry) {
            update_option(self::OPT_LAST_RUN, $started, false);
            $log = self::log();
            array_unshift($log, $entry);
            update_option(self::OPT_LOG, array_slice($log, 0, 20), false);
        }
        return $entry;
    }

    /** admin button handler (Settings -> Authntic Products) */
    public static function handle_button() {
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
        check_admin_referer('spp_purge_run');
        $dry = isset($_POST['dry']) && $_POST['dry'] === '1';
        $r   = self::purge($dry, $dry ? 'manual-dry' : 'manual');

        $msg = $dry
            ? sprintf('dry:%d:%d', $r['candidates'], $r['managed'])
            : ($r['blocked'] ? sprintf('blocked:%d:%s', $r['candidates'], $r['pct'])
                             : sprintf('done:%d:%d', $r['deleted'], $r['remaining']));
        wp_safe_redirect(add_query_arg('spp_purge', rawurlencode($msg),
            admin_url('admin.php?page=server-products')));
        exit;
    }

    /** notice shown after the button runs */
    public static function admin_notice() {
        if (empty($_GET['spp_purge'])) return;
        $parts = explode(':', sanitize_text_field(wp_unslash($_GET['spp_purge'])));
        $kind  = $parts[0];
        if ($kind === 'dry') {
            printf('<div class="notice notice-info"><p><strong>Dry run:</strong> %d of %d managed products are out of stock and have not been updated by the scraper in more than %d days, and would be <strong>deleted</strong>. No products were deleted.</p></div>',
                (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0), self::days());
        } elseif ($kind === 'blocked') {
            printf('<div class="notice notice-error"><p><strong>Delete aborted.</strong> It would have deleted %d products (%s%% of the catalogue), above the %d%% safety limit. That usually means the scrapers stopped rather than the stock selling out — check the server before re-running.</p></div>',
                (int) ($parts[1] ?? 0), esc_html($parts[2] ?? '?'), self::max_pct());
        } elseif ($kind === 'done') {
            $del = (int) ($parts[1] ?? 0); $rem = (int) ($parts[2] ?? 0);
            if ($rem > 0) {
                printf('<div class="notice notice-success"><p><strong>Cleanup running.</strong> %s deleted; %s more still qualify and will be removed <strong>automatically in the background</strong> over the next runs — no need to keep clicking. (You still can, to speed it up.)</p></div>',
                    number_format_i18n($del), number_format_i18n($rem));
            } else {
                printf('<div class="notice notice-success"><p><strong>Cleanup complete.</strong> %s long-dead product(s) deleted. Nothing left to remove.</p></div>', number_format_i18n($del));
            }
        }
    }
}
