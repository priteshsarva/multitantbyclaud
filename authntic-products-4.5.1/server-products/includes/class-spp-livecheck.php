<?php
if (!defined('ABSPATH')) exit;

/**
 * LIVE STOCK CHECK — re-verify quiet in-stock products one at a time.
 *
 * WHAT IT DOES
 *   Walks IN-STOCK managed products whose data hasn't changed in N days and asks
 *   the server to re-scrape each one live (GET /product/refresh-one). The point is
 *   to catch products that sold out at the supplier without the catalogue noticing.
 *
 * WHY IN-STOCK ONLY
 *   An out-of-stock product has nothing to lose by staying out of stock — it isn't
 *   selling and can't disappoint a customer. Re-checking it burns a Chrome launch
 *   for no benefit. Every query here therefore pins _stock_status = 'instock'.
 *   (Products come back in stock through the normal sync feed, not through here.)
 *
 * WHY IT IS DELIBERATELY SLOW
 *   Each refresh-one queues a real Puppeteer scrape on the server, and that queue
 *   runs ONE job at a time shared with the catalogue rotator. Firing hundreds of
 *   these would starve the rotator and stall the whole catalogue. So this runs a
 *   handful per tick, and the settings screen shows how long a full cycle takes at
 *   the chosen rate.
 *
 * HOW THE ANSWER COMES BACK
 *   refresh-one returns immediately ('fresh' or 'refreshing') — the scrape happens
 *   in the background on the server. The corrected stock lands in this store later,
 *   through the ordinary sync feed. So this class asks the question; SPP_Sync
 *   delivers the answer. Nothing here writes stock directly.
 *
 * SAFETY
 *   Never touches a product without _spp_managed = '1'. Never marks anything out of
 *   stock itself. Stamps _spp_live_checked on every attempt (success or failure) so
 *   one unreachable product can't wedge the queue on the same id forever.
 */
class SPP_LiveCheck {

    const OPT_ENABLED   = 'spp_livecheck_enabled';   // 'yes' | 'no'
    const OPT_DAYS      = 'spp_livecheck_days';      // only products quiet this long
    const OPT_PER_RUN   = 'spp_livecheck_per_run';   // products per tick
    const OPT_EVERY_MIN = 'spp_livecheck_every_min'; // minutes between ticks
    const OPT_LAST_RUN  = 'spp_livecheck_last_run';
    const OPT_LOG       = 'spp_livecheck_log';

    /** when this store last ASKED the server to re-scrape this product (ms epoch) */
    const META_CHECKED  = '_spp_live_checked';

    /** don't ask about the same product again within this many days */
    const RECHECK_DAYS  = 7;

    const LOG_KEEP      = 20;

    // ---------- settings ----------
    public static function enabled()  { return get_option(self::OPT_ENABLED, 'no') === 'yes'; }
    public static function days()     { return max(1, (int) get_option(self::OPT_DAYS, 3)); }
    public static function per_run()  { return max(1, min(100, (int) get_option(self::OPT_PER_RUN, 5))); }
    public static function every()    { return max(1, (int) get_option(self::OPT_EVERY_MIN, 15)); }
    public static function last_run() { return (int) get_option(self::OPT_LAST_RUN, 0); }
    public static function log()      { $l = get_option(self::OPT_LOG, array()); return is_array($l) ? $l : array(); }

    /** products untouched since this moment are candidates (ms epoch) */
    public static function cutoff_ms() {
        return (time() - self::days() * DAY_IN_SECONDS) * 1000;
    }

    /** re-ask about a product only once it is older than this (ms epoch) */
    public static function recheck_cutoff_ms() {
        return (time() - self::RECHECK_DAYS * DAY_IN_SECONDS) * 1000;
    }

    /** due for its automatic tick? */
    public static function due() {
        if (!self::enabled()) return false;
        $last = self::last_run();
        if ($last === 0) return true;
        return (time() - $last) >= self::every() * MINUTE_IN_SECONDS;
    }

    // ---------- candidate selection ----------
    /**
     * IN-STOCK, managed, quiet for N days, and not already asked about recently.
     * _spp_ts is the server's productLastUpdated. Products with no _spp_ts are
     * excluded: a missing timestamp means "never synced properly", not "stale".
     */
    private static function query_args($limit) {
        return array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => $limit > 0,
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => SPP_MANAGED,    'value' => '1'),
                array('key' => '_stock_status', 'value' => 'instock'),   // IN STOCK ONLY
                array('key' => '_spp_ts', 'compare' => 'EXISTS'),
                array('key' => '_spp_ts', 'value' => (string) self::cutoff_ms(), 'compare' => '<', 'type' => 'NUMERIC'),
                array(
                    'relation' => 'OR',
                    array('key' => self::META_CHECKED, 'compare' => 'NOT EXISTS'),
                    array('key' => self::META_CHECKED, 'value' => (string) self::recheck_cutoff_ms(), 'compare' => '<', 'type' => 'NUMERIC'),
                ),
            ),
        );
    }

    /** next batch of product ids to verify */
    public static function candidates($limit) {
        $q = new WP_Query(self::query_args($limit));
        return $q->posts;
    }

    /**
     * How many products are waiting, in total.
     * Memoised: this is a counted meta_query over the whole catalogue, and the
     * settings screen asks for it more than once per render.
     */
    private static $count_cache = null;
    public static function candidate_count($fresh = false) {
        if (!$fresh && self::$count_cache !== null) return self::$count_cache;

        // A counted 5-clause meta_query across a 77k-product catalogue is far too
        // heavy to run on every settings page view. Cache it between views; the
        // run itself passes $fresh so it never acts on a stale figure.
        if (!$fresh) {
            $cached = get_transient('spp_livecheck_waiting');
            if ($cached !== false) { self::$count_cache = (int) $cached; return self::$count_cache; }
        }

        $args = self::query_args(1);
        $args['posts_per_page'] = 1;
        $args['no_found_rows']  = false;
        $q = new WP_Query($args);
        self::$count_cache = (int) $q->found_posts;
        set_transient('spp_livecheck_waiting', self::$count_cache, 10 * MINUTE_IN_SECONDS);
        return self::$count_cache;
    }

    // ---------- the run ----------
    /**
     * Ask the server to re-scrape up to per_run() products.
     * @param bool $dry true = count only, send nothing
     * @return array{checked:int,queued:int,fresh:int,failed:int,waiting:int,dry:bool}
     */
    public static function run($dry = false, $trigger = 'auto') {
        $waiting = self::candidate_count(true);
        $result  = array(
            'at'       => time(),
            'at_human' => date_i18n('Y-m-d H:i', time() + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)),
            'trigger'  => $trigger,
            'dry'      => (bool) $dry,
            'checked'  => 0,
            'queued'   => 0,
            'fresh'    => 0,
            'failed'   => 0,
            'waiting'  => $waiting,
        );

        if ($dry || $waiting === 0) {
            self::remember($result);
            if (!$dry) update_option(self::OPT_LAST_RUN, time(), false);
            return $result;
        }

        foreach (self::candidates(self::per_run()) as $pid) {
            // belt and braces: never touch a product this plugin doesn't own
            if (!SPP_Product::is_managed($pid)) continue;

            $sourceId = get_post_meta($pid, SPP_SOURCE_ID, true);
            $sourceDb = get_post_meta($pid, SPP_SOURCE_DB, true);

            // Stamp BEFORE the call. A product the server can't scrape must still
            // drop out of the queue, or every tick retries the same broken id.
            update_post_meta($pid, self::META_CHECKED, (string) (time() * 1000));
            $result['checked']++;

            if ($sourceId === '' || $sourceDb === '') { $result['failed']++; continue; }

            $res = SPP_API::refresh_one($sourceId, $sourceDb);
            if (is_wp_error($res)) { $result['failed']++; continue; }

            // server answers 'fresh' (already current) or 'refreshing' (scrape queued)
            $status = is_array($res) && !empty($res['status']) ? $res['status'] : '';
            if ($status === 'fresh')            $result['fresh']++;
            elseif ($status === 'refreshing')   $result['queued']++;
            else                                $result['queued']++;   // older server, no status field
        }

        update_option(self::OPT_LAST_RUN, time(), false);
        self::remember($result);
        return $result;
    }

    private static function remember($entry) {
        $log = self::log();
        array_unshift($log, $entry);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_KEEP), false);
    }

    // ---------- wiring ----------
    public static function init() {
        add_action('admin_post_spp_livecheck_run', array(__CLASS__, 'handle_button'));
    }

    // ---------- manual trigger (admin-post) ----------
    public static function handle_button() {
        check_admin_referer('spp_livecheck_run');
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
        $dry = isset($_POST['dry']) && $_POST['dry'] === '1';
        $r   = self::run($dry, $dry ? 'manual-dry' : 'manual');
        $msg = $dry
            ? sprintf('dry:%d', $r['waiting'])
            : sprintf('done:%d:%d', $r['checked'], max(0, $r['waiting'] - $r['checked']));
        wp_safe_redirect(admin_url('admin.php?page=server-products&spp_livecheck=' . rawurlencode($msg)));
        exit;
    }

    /** notice shown after the button runs */
    public static function admin_notice() {
        if (empty($_GET['spp_livecheck'])) return;
        $p    = explode(':', sanitize_text_field(wp_unslash($_GET['spp_livecheck'])));
        $kind = $p[0];
        if ($kind === 'dry') {
            printf('<div class="notice notice-info"><p><strong>Live check (dry run):</strong> %d in-stock product(s) have been quiet for more than %d days and are waiting to be verified. Nothing was sent.</p></div>',
                (int) ($p[1] ?? 0), self::days());
        } elseif ($kind === 'done') {
            printf('<div class="notice notice-success"><p><strong>Live check sent.</strong> Asked the server to re-scrape %d product(s); %d still queued. The corrected stock arrives with the next sync passes, not immediately.</p></div>',
                (int) ($p[1] ?? 0), (int) ($p[2] ?? 0));
        }
    }

    /** rough time to work through the whole queue at the current rate */
    public static function cycle_estimate() {
        $waiting = self::candidate_count(true);
        if ($waiting === 0) return 'nothing waiting';
        $perDay = (1440 / self::every()) * self::per_run();
        if ($perDay <= 0) return 'never';
        $days = $waiting / $perDay;
        if ($days < 1) return sprintf('about %d hour(s) for all %s', max(1, (int) round($days * 24)), number_format_i18n($waiting));
        return sprintf('about %s day(s) for all %s', number_format_i18n(round($days, 1)), number_format_i18n($waiting));
    }
}
