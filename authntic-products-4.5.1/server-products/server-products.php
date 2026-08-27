<?php
/**
 * Plugin Name: Authntic Products
 * Description: Pulls this store's catalogue from the Authntic Products portal into WooCommerce using the site's enrollment key. Creates real, buyable WooCommerce products.
 * Version: 4.9.4
 * Author: Authntic Products
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('SPP_VERSION', '4.9.4');
define('SPP_DIR', plugin_dir_path(__FILE__));
define('SPP_URL', plugin_dir_url(__FILE__));

// --- option keys ---
define('SPP_OPT_KEY',      'spp_enrollment_key');
define('SPP_OPT_SERVER',   'spp_server_url');
define('SPP_OPT_MARGINS',  'spp_margin_tiers');
define('SPP_OPT_CAT_MARGINS', 'spp_category_margins');  // per-category price rules
define('SPP_OPT_CURSOR',   'spp_sync_cursor');
define('SPP_OPT_AUTOSYNC', 'spp_autosync_enabled');
define('SPP_OPT_REMOVING', 'spp_removing');
define('SPP_OPT_STATUS',   'spp_status');
define('SPP_OPT_KNOWN',    'spp_known_sources');   // last-seen source set (source_id => search_key)
define('SPP_OPT_PURGE',    'spp_purge_keys');      // search_keys of removed sources, products pending deletion
define('SPP_OPT_REPRICE',       'spp_reprice');        // 'yes' while a local re-price pass runs
define('SPP_OPT_REPRICE_AFTER', 'spp_reprice_after');  // product ID cursor for the re-price pass

// --- product meta flags ---
define('SPP_MANAGED',   '_spp_managed');
define('SPP_SOURCE_ID', '_spp_source_id');
define('SPP_SOURCE_DB', '_spp_source_db');

// default server (raw IP for testing; set your domain in settings)
define('SPP_DEFAULT_SERVER', 'http://43.204.135.214:3002');

require_once SPP_DIR . 'includes/class-spp-margin.php';
require_once SPP_DIR . 'includes/class-spp-api.php';
require_once SPP_DIR . 'includes/class-spp-product.php';
require_once SPP_DIR . 'includes/class-spp-sync.php';
require_once SPP_DIR . 'includes/class-spp-display.php';
require_once SPP_DIR . 'includes/class-spp-gallery.php';
require_once SPP_DIR . 'includes/class-spp-rest.php';
require_once SPP_DIR . 'includes/class-spp-settings.php';
require_once SPP_DIR . 'includes/class-spp-live.php';
require_once SPP_DIR . 'includes/class-spp-quote.php';
require_once SPP_DIR . 'includes/class-spp-theme.php';
require_once SPP_DIR . 'includes/class-spp-compat.php';
require_once SPP_DIR . 'includes/class-spp-checkout.php';
require_once SPP_DIR . 'includes/class-spp-partial.php';
require_once SPP_DIR . 'includes/class-spp-stale.php';
require_once SPP_DIR . 'includes/class-spp-purge.php';
require_once SPP_DIR . 'includes/class-spp-livecheck.php';
require_once SPP_DIR . 'includes/class-spp-dedupe.php';
require_once SPP_DIR . 'includes/class-spp-diag.php';

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Authntic Products</strong> needs WooCommerce active.</p></div>';
        });
        return;
    }
    SPP_Settings::init();
    if (is_admin()) {
        add_action('admin_notices',      array('SPP_Stale', 'admin_notice'));
        add_action('admin_notices',      array('SPP_Purge', 'admin_notice'));
        add_action('admin_notices',      array('SPP_LiveCheck', 'admin_notice'));
        add_action('admin_notices',      array('SPP_Dedupe', 'admin_notice'));
        add_action('add_meta_boxes',     array('SPP_Stale', 'meta_box'));
    }
    SPP_Sync::init();
    SPP_Display::init();
    SPP_Gallery::init();
    SPP_Rest::init();
    SPP_Live::init();
    SPP_Quote::init();
    SPP_Theme::init();
    SPP_Compat::init();
    SPP_Checkout::init();
    SPP_Partial::init();
    SPP_Stale::init();
    SPP_Purge::init();
    SPP_LiveCheck::init();
    SPP_Dedupe::init();
});

// Heartbeat schedule. The name is historical — the interval is a setting now,
// because a fixed 60s tick paired with a 40s work budget was taking ~67% of a
// shared-hosting CPU allowance around the clock. See SPP_Sync::tick_seconds().
add_filter('cron_schedules', function ($s) {
    $secs = class_exists('SPP_Sync') ? SPP_Sync::tick_seconds() : 300;
    $s['spp_minute'] = [
        'interval' => $secs,
        'display'  => sprintf('Every %d seconds (Authntic Products)', $secs),
    ];
    return $s;
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('spp_cron_sync')) {
        wp_schedule_event(time() + 60, 'spp_minute', 'spp_cron_sync');
    }
    // arm the full-resync clock from "now" so the first scheduled one is a full
    // interval away, not immediate.
    if (!get_option('spp_last_full_resync')) {
        update_option('spp_last_full_resync', time(), false);
    }
});

// deactivation only stops the cron — it never deletes products (use "Remove all" for that)
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('spp_cron_sync');
});

// the heartbeat: removal takes priority, else sync when auto-sync is on
add_action('spp_cron_sync', function () {
    // record that the heartbeat fired + what it decided to do (visibility)
    $act = 'idle';
    SPP_Sync::check_expiry(); // refresh status + trigger grace removal if past expiry+3d

    // Self-heal ownership: adopt any "SPP-" SKU products missing the managed flag
    // so EVERY feature manages them (even ones whose source site was removed).
    // Drains fast in a few ticks, then only re-checks once a day.
    $adopt_last = (int) get_option('spp_adopt_last', 0);
    if (get_option('spp_adopt_complete') !== 'yes' || (time() - $adopt_last) >= DAY_IN_SECONDS) {
        $n = SPP_Product::adopt_orphans(500);
        if ($n > 0) SPP_Sync::log(sprintf('Adopted %d SPP- product(s) into management (by SKU).', $n));
        if ($n < 500) { update_option('spp_adopt_complete', 'yes', false); }
        update_option('spp_adopt_last', time(), false);
    }

    // scheduled full resync (default every 12h) — resets cursors so the next
    // passes re-pull everything. Skipped while removing/repricing so it doesn't fight them.
    if (get_option(SPP_OPT_REMOVING) !== 'yes'
        && get_option(SPP_OPT_REPRICE) !== 'yes'
        && SPP_Sync::full_resync_due()) {
        SPP_Sync::start_full_resync('scheduled');
    }

    // stale sweep (default every 2 days) — cheap, runs before the sync work so a
    // busy backfill can never starve it.
    if (SPP_Stale::due()) {
        $r = SPP_Stale::sweep(false, 'auto');
        $act = 'stale-sweep';
        SPP_Sync::log(sprintf('Stale sweep: %d marked out of stock (%d candidates, %d managed)%s',
            $r['marked'], $r['candidates'], $r['managed'], $r['blocked'] ? ' — ABORTED by safety limit' : ''));
    }

    // auto-delete long-dead products (default OFF). Runs after the stale sweep:
    // items it just marked out of stock get _spp_oos_since = now, so they are NOT
    // eligible until they've stayed out of stock past the threshold. Skipped
    // while removing/repricing so it never competes with a bulk operation.
    // Runs when the interval is due (auto enabled), OR whenever a backlog is still
    // draining from a previous run (manual or auto) — so a big cleanup finishes by
    // itself across ticks instead of needing hundreds of button clicks.
    if (get_option(SPP_OPT_REMOVING) !== 'yes'
        && get_option(SPP_OPT_REPRICE) !== 'yes'
        && (SPP_Purge::due() || get_option(SPP_Purge::OPT_DRAINING) === 'yes')) {
        $pr = SPP_Purge::purge(false, 'auto');
        $act = 'purge';
        SPP_Sync::log(sprintf('Auto-delete: %d deleted, %d remaining (%d managed)%s',
            $pr['deleted'], $pr['remaining'] ?? 0, $pr['managed'], $pr['blocked'] ? ' — ABORTED by safety limit' : ''));
    }

    // live stock check — asks the server to re-scrape a few quiet IN-STOCK products.
    // Cheap on this side (a handful of HTTP calls that return immediately); the
    // actual scraping is queued on the server. Skipped while removing/repricing so
    // it never competes with a bulk operation.
    if (get_option(SPP_OPT_REMOVING) !== 'yes'
        && get_option(SPP_OPT_REPRICE) !== 'yes'
        && SPP_LiveCheck::due()) {
        $lc = SPP_LiveCheck::run(false, 'auto');
        if ($lc['checked'] > 0) {
            $act = 'live-check';
            SPP_Sync::log(sprintf('Live check: asked the server to re-scrape %d in-stock product(s) quiet for %d+ days (%d still queued).',
                $lc['checked'], SPP_LiveCheck::days(), max(0, $lc['waiting'] - $lc['checked'])));
        }
    }

    if (get_option(SPP_OPT_REMOVING) === 'yes') {
        SPP_Sync::remove_batch(15); $act = 'removing';
    } else {
        $purge = get_option(SPP_OPT_PURGE, array());
        if (is_array($purge) && !empty($purge)) {
            SPP_Sync::reconcile_batch(12); $act = 'reconciling';
        } elseif (get_option(SPP_OPT_REPRICE) === 'yes') {
            SPP_Sync::reprice_batch(15); $act = 'repricing';
        } elseif (get_option(SPP_OPT_AUTOSYNC) === 'yes') {
            // Budget is a setting — see SPP_Sync::tick_budget(). It used to be a
            // hard-coded 40 against a 60-second tick, which is the reason this
            // plugin could hold a shared host at ~80% CPU indefinitely.
            SPP_Sync::run_batch(SPP_Sync::tick_budget(), 'auto'); $act = 'syncing';
        }
    }
    update_option(SPP_OPT_STATUS, array_merge(get_option(SPP_OPT_STATUS, array()), array(
        'heartbeat_at'  => current_time('mysql'),
        'heartbeat_act' => $act,
    )), false);
});

// Safety net: re-add the schedule if a host cleared it, AND re-arm it if the
// interval no longer matches the setting.
//
// The second half matters on upgrade. WordPress stores the interval ON the
// scheduled event, not just in cron_schedules — so a site that was already
// running the old every-60-seconds tick keeps running it after updating, and the
// new interval silently never takes effect until the event is recreated.
add_action('admin_init', function () {
    if (!wp_next_scheduled('spp_cron_sync')) {
        wp_schedule_event(time() + 60, 'spp_minute', 'spp_cron_sync');
        return;
    }
    if (!function_exists('wp_get_scheduled_event') || !class_exists('SPP_Sync')) return;
    $event = wp_get_scheduled_event('spp_cron_sync');
    if ($event && isset($event->interval) && (int) $event->interval !== SPP_Sync::tick_seconds()) {
        wp_clear_scheduled_hook('spp_cron_sync');
        wp_schedule_event(time() + 60, 'spp_minute', 'spp_cron_sync');
    }
});
