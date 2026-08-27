<?php
if (!defined('ABSPATH')) exit;

/**
 * DUPLICATE CLEANUP — one product per SKU.
 *
 * WHY DUPLICATES EXIST
 *   WP-Cron can fire the same hook twice under traffic. The old sync lock was a
 *   get_transient() check followed by a set_transient() write, so two concurrent
 *   runs could both read "unlocked", both walk the same feed page, and both
 *   create the product. The tell-tale sign is pairs of consecutive post IDs
 *   holding the same SPP- SKU. SPP_Sync now takes the lock with a single atomic
 *   INSERT, so no NEW duplicates should appear — this class clears out the ones
 *   already on the site.
 *
 * WHICH COPY SURVIVES
 *   The LOWEST post id, always. It is the original: it holds the view counts,
 *   any reviews, and the permalink customers and Google already have. The newer
 *   copies are the accidents. SPP_Product::find_by_sku() also resolves to the
 *   lowest id, so sync and cleanup agree on which product is canonical.
 *
 * SAFETY
 *   - Only SKUs starting 'SPP-' are considered. Your own products are invisible
 *     to this, whatever their SKU.
 *   - Every copy it removes must ALSO carry _spp_managed = '1'.
 *   - Copies go to TRASH, not permanent deletion, so a mistake is recoverable.
 *   - Anything with an order against it is skipped — removing a product a
 *     customer bought would damage that order's history.
 */
class SPP_Dedupe {

    const OPT_LOG   = 'spp_dedupe_log';
    const PER_RUN   = 200;   // SKUs examined per click; keeps the request short
    const LOG_KEEP  = 10;

    public static function init() {
        add_action('admin_post_spp_dedupe_run', array(__CLASS__, 'handle_button'));
    }

    /**
     * SKUs that map to more than one live product.
     * @return array [ sku => [ids...] ] with ids ascending (survivor first)
     */
    public static function find_duplicates($limit = self::PER_RUN) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.meta_value AS sku, GROUP_CONCAT(p.ID ORDER BY p.ID ASC) AS ids, COUNT(*) AS n
               FROM {$wpdb->postmeta} m
               INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
              WHERE m.meta_key = '_sku'
                AND m.meta_value LIKE 'SPP-%%'
                AND p.post_type = 'product'
                AND p.post_status NOT IN ('trash','auto-draft')
              GROUP BY m.meta_value
             HAVING n > 1
              LIMIT %d",
            $limit
        ));
        $out = array();
        foreach ($rows as $r) {
            $out[$r->sku] = array_map('intval', explode(',', $r->ids));
        }
        return $out;
    }

    /**
     * Total number of SKUs currently duplicated (for display).
     *
     * This is a GROUP BY across every _sku row in postmeta — on a 77k-product
     * store that is not something to run on every admin page view, which is what
     * the settings screen was doing. Cached briefly; the cleanup itself always
     * re-counts for real, so the button can never act on a stale number.
     */
    public static function duplicate_sku_count($fresh = false) {
        $cached = get_transient('spp_dupe_count');
        if (!$fresh && $cached !== false) return (int) $cached;
        $count = self::count_duplicates_uncached();
        set_transient('spp_dupe_count', $count, 10 * MINUTE_IN_SECONDS);
        return $count;
    }

    private static function count_duplicates_uncached() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT m.meta_value
                  FROM {$wpdb->postmeta} m
                  INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_sku'
                   AND m.meta_value LIKE 'SPP-%'
                   AND p.post_type = 'product'
                   AND p.post_status NOT IN ('trash','auto-draft')
                 GROUP BY m.meta_value
                HAVING COUNT(*) > 1
             ) d"
        );
    }

    /** has this product ever been ordered? then leave it alone */
    private static function has_orders($pid) {
        global $wpdb;
        $t = $wpdb->prefix . 'woocommerce_order_itemmeta';
        // guard: on stores with HPOS/lookup differences the table may be absent
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) !== $t) return false;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$t} WHERE meta_key IN ('_product_id','_variation_id') AND meta_value = %d LIMIT 1",
            $pid
        ));
    }

    /**
     * @param bool $dry true = report only, trash nothing
     * @return array{skus:int,trashed:int,skipped:int,kept:int,dry:bool}
     */
    public static function run($dry = false, $trigger = 'manual') {
        $dupes  = self::find_duplicates();
        $result = array(
            'at'       => time(),
            'at_human' => date_i18n('Y-m-d H:i', time() + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS)),
            'trigger'  => $trigger,
            'dry'      => (bool) $dry,
            'skus'     => count($dupes),
            'kept'     => 0,
            'trashed'  => 0,
            'skipped'  => 0,
            'remaining'=> self::duplicate_sku_count(true),
        );

        foreach ($dupes as $ids) {
            $survivor = array_shift($ids);   // lowest id — the original
            $result['kept']++;
            foreach ($ids as $pid) {
                // never touch a product this plugin didn't create
                if (!SPP_Product::is_managed($pid)) { $result['skipped']++; continue; }
                if (self::has_orders($pid))                          { $result['skipped']++; continue; }
                $result['trashed']++;
                if (!$dry) wp_trash_post($pid);
            }
        }

        if (!$dry) $result['remaining'] = self::duplicate_sku_count(true);
        self::remember($result);
        return $result;
    }

    private static function remember($entry) {
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) $log = array();
        array_unshift($log, $entry);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_KEEP), false);
    }

    public static function log() {
        $l = get_option(self::OPT_LOG, array());
        return is_array($l) ? $l : array();
    }

    // ---------- admin ----------
    public static function handle_button() {
        check_admin_referer('spp_dedupe_run');
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');
        $dry = isset($_POST['dry']) && $_POST['dry'] === '1';
        $r   = self::run($dry, $dry ? 'manual-dry' : 'manual');
        $msg = $dry
            ? sprintf('dry:%d:%d', $r['skus'], $r['trashed'])
            : sprintf('done:%d:%d', $r['trashed'], $r['remaining']);
        wp_safe_redirect(admin_url('admin.php?page=server-products&spp_dedupe=' . rawurlencode($msg)));
        exit;
    }

    public static function admin_notice() {
        if (empty($_GET['spp_dedupe'])) return;
        $p    = explode(':', sanitize_text_field(wp_unslash($_GET['spp_dedupe'])));
        $kind = $p[0];
        if ($kind === 'dry') {
            printf('<div class="notice notice-info"><p><strong>Duplicate scan:</strong> %d SKU(s) have more than one product; %d extra copies would be moved to Trash. Nothing was changed.</p></div>',
                (int) ($p[1] ?? 0), (int) ($p[2] ?? 0));
        } elseif ($kind === 'done') {
            printf('<div class="notice notice-success"><p><strong>Duplicates cleaned.</strong> %d copy/copies moved to Trash. %d duplicated SKU(s) still remain — run it again to continue.</p></div>',
                (int) ($p[1] ?? 0), (int) ($p[2] ?? 0));
        }
    }
}
