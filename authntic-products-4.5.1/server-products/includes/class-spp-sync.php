<?php
if (!defined('ABSPATH')) exit;

class SPP_Sync {

    const LIMIT = 200; // fewer round-trips = faster backfill

    // How far back the incremental 'updates' sweep looks. The backfill phases are
    // deliberately NOT windowed — they must be able to see the whole catalogue.
    // A gap can only open if this store is offline longer than the window; the
    // scheduled full resync replays the backfill and closes it.
    const WINDOW_AUTO   = 3;    // days — background heartbeat
    const WINDOW_MANUAL = 7;    // days — "Sync now" reaches further back
    const NEW_DAYS      = 1;    // days — the hourly "what's new" pass
    const NEW_INTERVAL  = 3600; // seconds between "what's new" passes
    const NEW_MAX_PAGES = 5;    // hard bound so a first run can't eat the budget
    const OPT_NEW_AT    = 'spp_new_check_at';

    /** how far back the 'updates' sweep looks, in days, for this trigger */
    private static function window_days($trigger) {
        return ($trigger === 'manual' || $trigger === 'full-resync')
            ? self::WINDOW_MANUAL
            : self::WINDOW_AUTO;
    }

    /** true when the hourly new-product pass is due */
    private static function new_check_due() {
        return (time() - (int) get_option(self::OPT_NEW_AT, 0)) >= self::NEW_INTERVAL;
    }

    /**
     * Hourly priority pass: products the scraper FIRST SAW in the last 24h,
     * in-stock only, newest catalogue additions ahead of the normal rotation.
     * Keyed on productDateCreation server-side, so it is unaffected by the
     * productLastUpdated quirk (that column only moves when a field changes).
     * New out-of-stock products are left to the regular rotation by design.
     */
    private static function new_products_page($cat) {
        $after = 0; $total = 0; $pages = 0; $count = 0;
        do {
            $resp = SPP_API::sync_feed('id', $after, self::LIMIT, $cat, 'in', 0, self::NEW_DAYS);
            if (is_wp_error($resp)) { self::log($resp->get_error_message()); return $total; }
            $rows  = isset($resp['results']) ? $resp['results'] : array();
            $count = count($rows);
            foreach ($rows as $row) {
                $pid = isset($row['productId']) ? (int) $row['productId'] : 0;
                $after = max($after, $pid);   // advance first — a bad row can't wedge the pass
                self::safe_upsert($row);
            }
            $total += $count;
        } while ($count === self::LIMIT && ++$pages < self::NEW_MAX_PAGES);
        return $total;
    }

    public static function init() {
        // cron + settings drive this; nothing to hook here yet
    }

    /**
     * HOW MUCH CPU THIS PLUGIN IS ALLOWED TO TAKE.
     *
     * The original defaults ran run_batch(40) on a 60-second cron — 40 seconds of
     * WooCommerce writes out of every 60, forever. That is a 67% duty cycle on a
     * shared host, which pins the CPU allowance all day and gets sites suspended.
     * It is invisible in the usual metrics because it is one or two processes at
     * full tilt, not many: PHP workers and process counts stay low while CPU sits
     * at 80%+.
     *
     * Defaults are now 10s of work every 300s (~3%). Both are adjustable so a
     * first-time backfill can be pushed through quickly and then turned back down.
     */
    const DEFAULT_TICK_SECONDS = 300;
    const DEFAULT_TICK_BUDGET  = 10;

    /** seconds between heartbeats */
    public static function tick_seconds() {
        return min(3600, max(60, (int) get_option('spp_tick_seconds', self::DEFAULT_TICK_SECONDS)));
    }

    /**
     * Long WordPress loops leak by design: every get/save populates the object
     * cache, and nothing ever evicts it inside a single request. Over thousands
     * of products that walks straight into memory_limit and the request dies —
     * which is what a "site crashed" fatal usually is. Call this between pages.
     */
    public static function free_memory() {
        global $wpdb, $wp_object_cache;
        if (isset($wpdb->queries)) $wpdb->queries = array();   // only populated with SAVEQUERIES
        if (is_object($wp_object_cache)) {
            foreach (array('group_ops', 'stats', 'memcache_debug', 'cache') as $prop) {
                if (isset($wp_object_cache->$prop)) $wp_object_cache->$prop = array();
            }
            // persistent-cache backends re-prime their local copy through this
            if (method_exists($wp_object_cache, '__remoteset')) $wp_object_cache->__remoteset();
        }
    }

    /** bytes of PHP's memory_limit, or 0 when unlimited/unreadable */
    private static function memory_limit_bytes() {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') return 0;
        $unit = strtolower(substr($raw, -1));
        $val  = (float) $raw;
        if     ($unit === 'g') $val *= 1024 * 1024 * 1024;
        elseif ($unit === 'm') $val *= 1024 * 1024;
        elseif ($unit === 'k') $val *= 1024;
        return (int) $val;
    }

    /**
     * True once we are close enough to the limit that continuing risks a fatal.
     * Stopping early is always safe here — every loop in this plugin is resumable
     * from a stored cursor, so the work simply continues on the next tick.
     */
    public static function memory_exhausted($headroom = 0.8) {
        $limit = self::memory_limit_bytes();
        if ($limit <= 0) return false;
        return memory_get_usage(true) > ($limit * $headroom);
    }

    /** seconds of work allowed inside one heartbeat */
    public static function tick_budget() {
        // never allow a budget that outlives its own interval — that guarantees
        // overlap, and the lock would then simply drop every second tick.
        return min(self::tick_seconds() - 5, max(1, (int) get_option('spp_tick_budget', self::DEFAULT_TICK_BUDGET)));
    }

    const LOCK_OPTION = 'spp_sync_lock';

    /**
     * Take the sync lock, atomically. Returns false if someone else holds it.
     *
     * Uses a bare INSERT against wp_options: option_name carries a UNIQUE index,
     * so the database itself decides the winner. This is the whole point — a
     * read-then-write check (get_transient/set_transient) lets two concurrent
     * cron runs both see "free" and both proceed, which duplicated products.
     *
     * A crashed run must not wedge sync forever, so an EXPIRED lock can be stolen
     * — but only via a compare-and-swap on the stored expiry, so simultaneous
     * stealers can't both win.
     */
    private static function acquire_lock($ttl) {
        global $wpdb;
        $now     = time();
        $expires = (string) ($now + $ttl);

        // suppress the duplicate-key warning; a clash is the expected "someone
        // else has it" answer, not an error worth logging.
        $prev = $wpdb->suppress_errors(true);
        $got  = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::LOCK_OPTION, $expires
        ));
        $wpdb->suppress_errors($prev);
        if ($got) { wp_cache_delete('alloptions', 'options'); return true; }

        $held = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION
        ));
        if ($held > $now) return false;              // still live — back off

        // expired: exactly one caller can flip it, because the WHERE pins the old value
        $stolen = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $expires, self::LOCK_OPTION, (string) $held
        ));
        if ($stolen) { wp_cache_delete('alloptions', 'options'); return true; }
        return false;
    }

    private static function release_lock() {
        global $wpdb;
        $wpdb->delete($wpdb->options, array('option_name' => self::LOCK_OPTION));
        wp_cache_delete('alloptions', 'options');
    }

    private static function default_cursor() {
        return array(
            'phase'        => 'backfill_in',  // backfill_in -> backfill_out -> updates
            'backfill_id'  => 0,
            'ts_watermark' => 0,
            'id_watermark' => 0,
            'sweep'        => 'ts',       // alternate ts/id during updates
        );
    }

    // cursors are stored per category (db): array( category => cursor ).
    // Detects + discards the old single-cursor format so a clean per-category backfill runs.
    private static function cursors() {
        $c = get_option(SPP_OPT_CURSOR, array());
        if (!is_array($c)) return array();
        if (isset($c['phase'])) return array();       // legacy flat cursor -> reset
        foreach ($c as $cat => $cur) {
            if (isset($cur['phase']) && $cur['phase'] === 'backfill') {
                $c[$cat]['phase'] = 'backfill_in';
            }
        }
        return $c;
    }

    // categories this store should sync, taken from the cached /status source list.
    // If the cache is empty (e.g. right after install, before the first cron tick),
    // fetch status once so a manual "Sync now" has categories to work with.
    private static function sync_categories() {
        $st = get_option(SPP_OPT_STATUS, array());
        $cats = self::cats_from_status($st);
        if (empty($cats)) {
            $fresh = SPP_API::status();
            if (!is_wp_error($fresh) && is_array($fresh)) {
                update_option(SPP_OPT_STATUS, array_merge($st, array(
                    'remote_status' => $fresh['status']      ?? '',
                    'expiry_date'   => $fresh['expiry_date']  ?? '',
                    'days_left'     => $fresh['days_left']    ?? null,
                    'sources'       => $fresh['sources']      ?? array(),
                    'status_checked'=> current_time('mysql'),
                )), false);
                $cats = self::cats_from_status($fresh);
            }
        }
        return $cats;
    }

    private static function cats_from_status($st) {
        $cats = array();
        foreach ((isset($st['sources']) ? $st['sources'] : array()) as $s) {
            if (!empty($s['category']) && !in_array($s['category'], $cats, true)) $cats[] = $s['category'];
        }
        return $cats;
    }

    // one page of work for a single category; mutates $cursor, returns rows processed
    private static function sync_page($cat, &$cursor, $trigger = 'auto') {
        $phase = $cursor['phase'];
        if ($phase === 'backfill_in' || $phase === 'backfill_out') {
            $stock = ($phase === 'backfill_in') ? 'in' : 'out';
            $resp  = SPP_API::sync_feed('id', $cursor['backfill_id'], self::LIMIT, $cat, $stock);
            if (is_wp_error($resp)) { self::log($resp->get_error_message()); return 0; }
            $rows = $resp['results'] ?? array();
            foreach ($rows as $row) {
                $pid = isset($row['productId']) ? (int) $row['productId'] : 0;
                // advance the cursor FIRST, so a product that always fails can never
                // wedge the backfill on the same id forever.
                $cursor['backfill_id']  = max($cursor['backfill_id'],  $pid);
                $cursor['id_watermark'] = max($cursor['id_watermark'], $pid);
                $cursor['ts_watermark'] = max($cursor['ts_watermark'], (int) ($row['productLastUpdated'] ?? 0));
                self::safe_upsert($row);
            }
            if (count($rows) < self::LIMIT) {
                if ($phase === 'backfill_in') {
                    // in-stock done -> out-of-stock pass from the start (watermarks kept)
                    $cursor['phase']       = 'backfill_out';
                    $cursor['backfill_id'] = 0;
                } else {
                    $cursor['phase'] = 'updates';
                }
            }
            return count($rows);
        }
        // updates: alternate a ts-sweep (catches edits) and an id-sweep (catches new),
        // bounded to a recent window so the steady state stays cheap
        $by    = $cursor['sweep'];
        $after = $by === 'ts' ? $cursor['ts_watermark'] : $cursor['id_watermark'];
        $resp  = SPP_API::sync_feed($by, $after, self::LIMIT, $cat, '', self::window_days($trigger));
        if (is_wp_error($resp)) { self::log($resp->get_error_message()); return 0; }
        $rows = $resp['results'] ?? array();
        foreach ($rows as $row) {
            $pid = isset($row['productId']) ? (int) $row['productId'] : 0;
            $cursor['ts_watermark'] = max($cursor['ts_watermark'], (int) ($row['productLastUpdated'] ?? 0));
            $cursor['id_watermark'] = max($cursor['id_watermark'], $pid);
            self::safe_upsert($row);
        }
        if (count($rows) < self::LIMIT) $cursor['sweep'] = $by === 'ts' ? 'id' : 'ts';
        return count($rows);
    }

    /**
     * Upsert one product, but never let a single bad row take down the whole
     * request (which shows as WordPress's "critical error" page). Any error is
     * caught, recorded against the product id, and the sync moves on.
     */
    private static function safe_upsert($row) {
        try {
            SPP_Product::upsert($row);
        } catch (\Throwable $e) {  // \Throwable = both Exceptions and fatal Errors (PHP 7+)
            $pid = isset($row['productId']) ? $row['productId'] : '?';
            self::log('Skipped product ' . $pid . ': ' . $e->getMessage());
            if (function_exists('error_log')) {
                error_log('[SPP] upsert failed for product ' . $pid . ': ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }
    }

    /**
     * Do up to $budget seconds of sync work across ALL the store's categories
     * (each category is a separate database with its own cursor), then return.
     */
    public static function run_batch($budget = 15, $trigger = 'auto') {
        $start = microtime(true);
        SPP_API::$trigger = $trigger;

        // must have a key
        if (SPP_API::key() === '') { self::log('Sync skipped: no enrollment key set.'); return 0; }

        // Overlap lock: a bigger budget can outlive the 1-min heartbeat, and
        // WP-Cron happily fires the same hook twice under traffic. Two batches on
        // the same cursor read the same feed page and BOTH create products —
        // that is where duplicate SKUs come from.
        //
        // get_transient()-then-set_transient() cannot prevent this: both callers
        // read "unlocked" before either writes. acquire_lock() is a single atomic
        // INSERT instead, so exactly one process can ever hold it.
        if (!self::acquire_lock(max(30, (int) $budget + 30))) return 0;

        // Bulk upserts otherwise pile rows into the in-memory object cache and can
        // exhaust PHP's memory on big catalogues (another cause of "critical error").
        // Suspending cache addition during the batch keeps memory flat.
        if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(true);
        // Defer term counting: otherwise every category/brand assignment recounts
        // the whole taxonomy — one of the slowest parts of an import.
        if (function_exists('wp_defer_term_counting')) wp_defer_term_counting(true);
        SPP_Product::$skipped = 0;

        $processed = 0;
        try {
            $cats  = self::sync_categories();
            $noCats = empty($cats);
            if ($noCats) $cats = array('');   // fallback: single stream, no category param

            // Priority pass: anything the scraper first saw in the last 24h goes in
            // ahead of the normal rotation, at most once an hour. Runs before the
            // budget loop so a busy backfill can never starve new arrivals.
            if (self::new_check_due()) {
                foreach ($cats as $cat) $processed += self::new_products_page($cat);
                update_option(self::OPT_NEW_AT, time(), false);
            }

            $cursors = self::cursors();
            $i = 0;
            $rotationWork = 0;
            $n = count($cats);

            while ((microtime(true) - $start) < $budget) {
                $cat = $cats[$i % $n];
                $cursor = wp_parse_args(isset($cursors[$cat]) ? $cursors[$cat] : array(), self::default_cursor());

                $did = self::sync_page($cat, $cursor, $trigger);
                $cursors[$cat] = $cursor;
                update_option(SPP_OPT_CURSOR, $cursors, false);

                $processed    += $did;
                $rotationWork += $did;
                $i++;

                // Suspending cache ADDITION is not enough on its own: WooCommerce
                // product saves still populate plenty, and none of it is ever
                // evicted within one request. Drop it between pages, and bail out
                // entirely if we are near the limit — the cursor is saved above,
                // so the next tick simply resumes.
                self::free_memory();
                if (self::memory_exhausted()) {
                    self::log(sprintf('Sync paused early: memory at %s of PHP\'s limit. It resumes next tick.',
                        size_format(memory_get_usage(true))));
                    break;
                }

                // after a full rotation with no work anywhere, everything is caught up — stop.
                if ($i % $n === 0) {
                    if ($rotationWork === 0) break;
                    $rotationWork = 0;
                }
            }

            $first = !empty($cursors) ? reset($cursors) : self::default_cursor();
            self::set_status($processed, $first);

            if ($processed === 0 && $noCats) {
                self::progress('Connected, but no categories are linked to this key yet (server returned no sources). Nothing to import.');
            }
        } catch (\Throwable $e) {
            self::log('Sync stopped: ' . $e->getMessage());
            if (function_exists('error_log')) {
                error_log('[SPP] run_batch fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        } finally {
            if (function_exists('wp_defer_term_counting')) wp_defer_term_counting(false); // flush counts once
            if (function_exists('wp_suspend_cache_addition')) wp_suspend_cache_addition(false);
            self::release_lock();
        }

        return $processed;
    }

    /**
     * Start a full resync: reset all per-category cursors so the next sync passes
     * re-pull the ENTIRE catalogue from scratch (idempotent upserts refresh price +
     * stock and catch anything the incremental sweeps missed). Does NOT delete
     * products. $why is recorded so server logs show manual vs scheduled.
     */
    public static function start_full_resync($why = 'manual') {
        update_option(SPP_OPT_CURSOR, array(), false);
        update_option('spp_last_full_resync', time(), false);
        SPP_API::$trigger = 'full-resync';
        self::progress('Full resync started (' . $why . ') — re-pulling the whole catalogue.');
    }

    /** how many hours between automatic full resyncs (0 = disabled). Default 12. */
    public static function full_resync_hours() {
        $h = get_option('spp_full_resync_hours', 12);
        return is_numeric($h) ? max(0, (int) $h) : 12;
    }

    /** true if a scheduled full resync is due now */
    public static function full_resync_due() {
        $hours = self::full_resync_hours();
        if ($hours <= 0) return false;
        $last = (int) get_option('spp_last_full_resync', 0);
        if ($last === 0) return false; // first one is armed on activation/first sync, not immediately
        return (time() - $last) >= ($hours * HOUR_IN_SECONDS);
    }

    /** timestamp of the next scheduled full resync, or 0 if disabled/unknown */
    public static function next_full_resync() {
        $hours = self::full_resync_hours();
        if ($hours <= 0) return 0;
        $last = (int) get_option('spp_last_full_resync', 0);
        if ($last === 0) return 0;
        return $last + ($hours * HOUR_IN_SECONDS);
    }

    /** delete managed products in batches until none remain, then clear the flag */
    public static function remove_batch($budget = 15) {
        $start   = microtime(true);
        $deleted = 0;

        while ((microtime(true) - $start) < $budget) {
            $q = new WP_Query([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => 30,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => [['key' => SPP_MANAGED, 'value' => '1']],
            ]);
            if (empty($q->posts)) {
                update_option(SPP_OPT_REMOVING, 'no');
                update_option(SPP_OPT_CURSOR, []); // reset so a future sync backfills cleanly
                break;
            }
            foreach ($q->posts as $pid) {
                SPP_Product::delete($pid);
                $deleted++;
            }
        }

        update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, []), [
            'last_removed'    => $deleted,
            'last_removed_at' => current_time('mysql'),
        ]), false);
        return $deleted;
    }

    public static function set_status($processed, $cursor) {
        $now = current_time('mysql');
        update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, []), [
            'last_run'         => $now,
            'last_processed'   => $processed,
            'phase'            => $cursor['phase'] ?? '',
            'backfill_id'      => $cursor['backfill_id'] ?? 0,
            'ts_watermark'     => $cursor['ts_watermark'] ?? 0,
            'id_watermark'     => $cursor['id_watermark'] ?? 0,
            'last_activity'    => 'Sync pass processed ' . intval($processed) . ' product(s)'
                                  . (SPP_Product::$skipped > 0 ? ' (' . intval(SPP_Product::$skipped) . ' unchanged, skipped)' : '') . '.',
            'last_activity_at' => $now,
            'last_error'       => '', // clear past error on a good run
            'last_error_at'    => '',
        ]), false);
    }

    public static function log($msg) {
        update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, []), [
            'last_error'    => $msg,
            'last_error_at' => current_time('mysql'),
        ]), false);
    }

    // progress/info messages — NOT errors. Kept separate so the UI can show
    // "what it's doing" without making normal activity look like a failure.
    public static function progress($msg) {
        update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, []), [
            'last_activity'    => $msg,
            'last_activity_at' => current_time('mysql'),
        ]), false);
    }

    // --- expiry awareness + 3-day grace removal ---
    // Pulls /product/status, caches it for the settings UI, and once the key has
    // been expired for more than 3 days, kicks off automatic product removal.
    public static function check_expiry() {
        $st = SPP_API::status();
        if (is_wp_error($st)) {
            self::log('status check: ' . $st->get_error_message());
            return;
        }
        update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, array()), array(
            'remote_status' => isset($st['status']) ? $st['status'] : '',
            'expiry_date'   => isset($st['expiry_date']) ? $st['expiry_date'] : '',
            'days_left'     => isset($st['days_left']) ? $st['days_left'] : null,
            'sources'       => isset($st['sources']) ? $st['sources'] : array(),
            'status_checked'=> current_time('mysql'),
        )), false);

        // --- detect sources that were removed in the portal, and purge their products ---
        $current = array();
        foreach ((isset($st['sources']) ? $st['sources'] : array()) as $s) {
            if (!empty($s['source_id']) && !empty($s['search_key'])) {
                $current[$s['source_id']] = $s['search_key'];
            }
        }
        // Guard: only reconcile when we got a real, non-empty source list. A transient
        // empty response must never trigger mass deletion of the whole catalogue.
        if (!empty($current)) {
            $known = get_option(SPP_OPT_KNOWN, array());
            if (!is_array($known)) $known = array();
            $purge = get_option(SPP_OPT_PURGE, array());
            if (!is_array($purge)) $purge = array();
            foreach ($known as $sid => $sk) {
                if (!isset($current[$sid]) && $sk && !in_array($sk, $purge, true)) {
                    $purge[] = $sk;
                    self::progress('Source removed (' . $sid . ') — queued its products for removal.');
                }
            }
            update_option(SPP_OPT_PURGE, array_values($purge), false);
            update_option(SPP_OPT_KNOWN, $current, false);
        }

        // grace removal: 3 days past expiry -> remove all managed products
        $expiry = isset($st['expiry_date']) ? strtotime($st['expiry_date']) : 0;
        $expired = (isset($st['status']) && $st['status'] === 'expired') || ($expiry && time() > $expiry);
        if ($expired && $expiry) {
            $grace_ends = $expiry + (3 * DAY_IN_SECONDS);
            if (time() > $grace_ends && get_option(SPP_OPT_REMOVING) !== 'yes') {
                update_option(SPP_OPT_REMOVING, 'yes');
                update_option(SPP_OPT_AUTOSYNC, 'no');
                self::progress('Expired over 3 days — auto-removing products.');
            }
        }
    }


    // Deletes products belonging to a removed source, in safe batches.
    // Matches the server's own rule: productFetchedFrom LIKE %search_key%.
    public static function reconcile_batch($budget_seconds = 12) {
        $purge = get_option(SPP_OPT_PURGE, array());
        if (!is_array($purge) || empty($purge)) return;

        $key = $purge[0]; // one removed source at a time
        $q = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 30,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => SPP_MANAGED, 'value' => '1'),
                array('key' => 'productFetchedFrom', 'value' => $key, 'compare' => 'LIKE'),
            ),
        ));

        if (empty($q->posts)) {
            // nothing left for this source — drop it from the queue
            array_shift($purge);
            update_option(SPP_OPT_PURGE, array_values($purge), false);
            self::progress('Finished removing products for source key: ' . $key);
            return;
        }

        $start = time();
        $n = 0;
        foreach ($q->posts as $pid) {
            wp_delete_post($pid, true);
            $n++;
            if (time() - $start >= $budget_seconds) break;
        }
        self::progress('Removed ' . $n . ' products from removed source (' . $key . ').');
    }


    // Fast local re-price: recompute every managed product's price from the
    // original price we already stored, using the current margins. No re-fetch,
    // and no full WC_Product save (direct price meta) so 25k products go quickly.
    private static function managed_ids_after($after, $limit) {
        $flt = function ($where) use ($after) {
            global $wpdb;
            return $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after);
        };
        add_filter('posts_where', $flt);
        $q = new WP_Query(array(
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(array('key' => SPP_MANAGED, 'value' => '1')),
        ));
        remove_filter('posts_where', $flt);
        return $q->posts;
    }

    public static function reprice_batch($budget = 15) {
        if (get_option(SPP_OPT_REPRICE) !== 'yes') return;
        $after = (int) get_option(SPP_OPT_REPRICE_AFTER, 0);
        $start = time();
        $done = false;
        $n = 0;

        while (time() - $start < $budget) {
            $ids = self::managed_ids_after($after, 100);
            if (empty($ids)) { $done = true; break; }
            foreach ($ids as $pid) {
                $orig = get_post_meta($pid, 'productOriginalPrice', true);
                if ($orig !== '' && $orig !== null) {
                    $cat = (string) get_post_meta($pid, '_spp_cat', true);
                    if (SPP_Margin::is_quote_for($orig, $cat)) {
                        update_post_meta($pid, '_spp_quote', 'yes');
                        update_post_meta($pid, '_regular_price', '');
                        update_post_meta($pid, '_price', '');
                    } else {
                        update_post_meta($pid, '_spp_quote', 'no');
                        $new = SPP_Margin::final_price_for($orig, $cat);
                        update_post_meta($pid, '_regular_price', (string) $new);
                        update_post_meta($pid, '_price', (string) $new);
                    }
                    if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                    $n++;
                }
                $after = (int) $pid;
            }
            update_option(SPP_OPT_REPRICE_AFTER, $after, false);
        }

        if ($done) {
            update_option(SPP_OPT_REPRICE, 'no', false);
            update_option(SPP_OPT_REPRICE_AFTER, 0, false);
            self::progress('Re-price pass complete.');
        } else {
            self::progress('Re-priced ' . $n . ' products (continuing next tick).');
        }
    }

}
