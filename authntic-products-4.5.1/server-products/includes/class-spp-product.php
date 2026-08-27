<?php
if (!defined('ABSPATH')) exit;

class SPP_Product {

    /** products skipped as unchanged during the current batch (visibility only) */
    public static $skipped = 0;

    /**
     * ms-epoch (e.g. 1785010678235) -> readable site-local date.
     * Values are milliseconds, not seconds — dividing by 1000 is required or
     * every date lands in 1970.
     */
    public static function ms_to_human($ms) {
        $ms = (float) $ms;
        if ($ms <= 0) return '';
        $secs = (int) round($ms / 1000);
        return date_i18n('Y-m-d H:i', $secs + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
    }

    /** coerce any value to a safe scalar string (arrays/objects -> '' or first scalar) */
    public static function scalar($v) {
        if (is_string($v))  return $v;
        if (is_int($v) || is_float($v)) return (string) $v;
        if (is_bool($v))    return $v ? '1' : '';
        if (is_null($v))    return '';
        if (is_array($v)) {
            // take the first scalar element if there is one, else empty
            foreach ($v as $x) { if (is_scalar($x)) return (string) $x; }
            return '';
        }
        return '';
    }

    /** coerce to a numeric value; strips currency symbols / commas / spaces */
    public static function num($v) {
        if (is_int($v) || is_float($v)) return $v;
        $s = self::scalar($v);
        if ($s === '') return 0;
        $s = preg_replace('/[^0-9.\-]/', '', $s); // drop ₹, commas, spaces, etc.
        return is_numeric($s) ? (float) $s : 0;
    }

    /** availability comes through as 0/1/"true"/"false" — normalize to WC stock status */
    public static function normalize_availability($v) {
        if ($v === 1 || $v === '1' || $v === true || $v === 'true' || $v === 'TRUE') return 'instock';
        return 'outofstock';
    }

    /** imageUrl is a JSON-string array; fall back to the featured image */
    public static function decode_images($imageUrl, $featured) {
        $imgs = [];
        if (is_array($imageUrl)) {
            $imgs = $imageUrl;
        } elseif (is_string($imageUrl) && $imageUrl !== '') {
            $d = json_decode($imageUrl, true);
            if (is_array($d)) $imgs = $d;
        }
        if (empty($imgs) && $featured) $imgs = [$featured];
        return array_values(array_filter(array_map('strval', $imgs)));
    }

    /** sizeName is a JSON-string array */
    public static function decode_sizes($sizeName) {
        if (is_array($sizeName)) return $sizeName;
        if (is_string($sizeName) && $sizeName !== '') {
            $d = json_decode($sizeName, true);
            if (is_array($d)) return $d;
        }
        return [];
    }

    /**
     * Locate an existing product by its namespaced SKU.
     *
     * wc_get_product_id_by_sku() reads wp_wc_product_meta_lookup through the object
     * cache. During a bulk run that cache is deliberately suspended, and if the
     * lookup table is ever out of step it returns 0 for a product that plainly
     * exists — at which point the sync creates a SECOND product with the same SKU.
     *
     * So: ask WooCommerce first, then fall back to the _sku postmeta itself, which
     * is the actual source of truth. Always return the LOWEST id, so that if
     * duplicates already exist every future sync converges on the same original
     * instead of ping-ponging between them.
     */
    public static function find_by_sku($sku) {
        if ($sku === '') return 0;

        $id = wc_get_product_id_by_sku($sku);
        if ($id) return (int) $id;

        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku'
              WHERE m.meta_value = %s
                AND p.post_type IN ('product','product_variation')
                AND p.post_status != 'trash'
              ORDER BY p.ID ASC LIMIT 1",
            $sku
        ));
        return $id ? (int) $id : 0;
    }

    /**
     * Create or update a WooCommerce product from one sync-feed row.
     * Returns the product id, or 0 on failure.
     */
    /**
     * Is this product managed by the plugin? True if it carries our managed flag
     * OR its SKU is one we mint (starts "SPP-"). The SKU is the DURABLE proof of
     * ownership: a product we created whose flag was later stripped (an old
     * import, a removed source, a migration) is still ours. Every feature should
     * gate on this, not on the flag alone.
     */
    public static function is_managed($pid) {
        if (!$pid) return false;
        if (get_post_meta($pid, SPP_MANAGED, true) === '1') return true;
        $sku = (string) get_post_meta($pid, '_sku', true);
        return strncmp($sku, 'SPP-', 4) === 0;
    }

    /**
     * Adopt any "SPP-" SKU products that are missing the managed flag by stamping
     * it (and backfilling source db/id from the SKU when absent). This is what
     * makes EVERY feature — all of which filter on the flag — manage products the
     * plugin created even after their source site was removed. Budgeted by
     * $limit; returns how many it adopted. Cheap and returns 0 once drained.
     */
    public static function adopt_orphans($limit = 500) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku' AND sku.meta_value LIKE 'SPP-%%'
          LEFT JOIN {$wpdb->postmeta} mgd ON mgd.post_id = p.ID AND mgd.meta_key = %s
              WHERE p.post_type = 'product'
                AND (mgd.meta_value IS NULL OR mgd.meta_value <> '1')
              LIMIT %d",
            SPP_MANAGED, (int) $limit
        ));
        if (empty($ids)) return 0;
        foreach ($ids as $pid) {
            $pid = (int) $pid;
            update_post_meta($pid, SPP_MANAGED, '1');
            // backfill source db/id from "SPP-<db>-<id>" (or "SPP-<id>") if missing
            if (get_post_meta($pid, SPP_SOURCE_DB, true) === '' || get_post_meta($pid, SPP_SOURCE_ID, true) === '') {
                $rest = substr((string) get_post_meta($pid, '_sku', true), 4); // after "SPP-"
                $pos  = strrpos($rest, '-');
                $db   = $pos !== false ? substr($rest, 0, $pos) : '';
                $id   = $pos !== false ? substr($rest, $pos + 1) : $rest;
                if ($db !== '' && get_post_meta($pid, SPP_SOURCE_DB, true) === '') update_post_meta($pid, SPP_SOURCE_DB, $db);
                if ($id !== '' && get_post_meta($pid, SPP_SOURCE_ID, true) === '') update_post_meta($pid, SPP_SOURCE_ID, $id);
            }
        }
        return count($ids);
    }

    public static function upsert($row) {
        if (empty($row['productId'])) return 0;
        $productId = (string) $row['productId'];
        $db        = self::scalar($row['dbName'] ?? '');

        // SKU is namespaced by database so the same productId in watches.db and
        // shoes.db never collide into one product. Format: SPP-<db>-<id>.
        $sku = $db !== '' ? ('SPP-' . $db . '-' . $productId) : ('SPP-' . $productId);

        $orig    = self::num($row['productOriginalPrice'] ?? 0);
        $catName = self::scalar($row['catName'] ?? '');
        $isQuote = SPP_Margin::is_quote_for($orig, $catName);
        $price   = $isQuote ? '' : SPP_Margin::final_price_for($orig, $catName);
        $stock  = self::normalize_availability($row['availability'] ?? 0);
        $sizes  = self::decode_sizes($row['sizeName'] ?? '');

        $existing = self::find_by_sku($sku);
        // lazy migration: adopt a product created under the OLD scheme (SPP-<id>),
        // but ONLY if it's the same database — otherwise a colliding id from another
        // category would be wrongly clobbered.
        if (!$existing && $db !== '') {
            $old = wc_get_product_id_by_sku('SPP-' . $productId);
            if ($old && (string) get_post_meta($old, SPP_SOURCE_DB, true) === $db) {
                $existing = (int) $old;
            }
        }

        // It was matched by its exact SPP- SKU, so it IS ours — even if the managed
        // flag went missing (old import, source removed, migration). Re-adopt it
        // rather than skipping, so every feature keeps managing it. (The SKU is our
        // durable fingerprint; a hand-made product would have to reuse a full
        // "SPP-<db>-<id>" SKU to reach here, which does not happen in practice.)
        if ($existing && get_post_meta($existing, SPP_MANAGED, true) !== '1') {
            update_post_meta($existing, SPP_MANAGED, '1');
        }

        // FAST PATH: existing product + unchanged feed timestamp -> skip the whole
        // WooCommerce save pipeline. This is what makes a full resync of 25k
        // products take minutes: most are unchanged and cost one meta read.
        // Safe because the server bumps productLastUpdated on every scraper write
        // (same assumption the ts-sweep relies on); price changes go through the
        // separate reprice queue, not this method.
        $ts = isset($row['productLastUpdated']) ? (string) $row['productLastUpdated'] : '';
        if ($existing && $ts !== '' && get_post_meta($existing, '_spp_ts', true) === $ts) {
            // Unchanged — skip the save pipeline, but still record that the feed
            // DID contain this product just now. Without this, a product that is
            // alive and present but simply unedited would look "never seen".
            update_post_meta($existing, 'sppLastSynced', (string) (time() * 1000));
            update_post_meta($existing, 'sppLastSyncedHuman', self::ms_to_human(time() * 1000));
            self::$skipped++;
            return $existing;
        }

        $product = $existing ? wc_get_product($existing) : null;
        if (!$product) $product = new WC_Product_Simple();

        $name = self::scalar($row['productName'] ?? '');
        $product->set_name($name !== '' ? $name : ('Product ' . $productId));
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_sku($sku);
        $product->set_stock_status($stock);
        if ($isQuote) {
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_price('');
        } elseif (isset($row['productOriginalPrice'])) {
            $product->set_regular_price((string) $price);
        }
        $shortDesc = self::scalar($row['productShortDescription'] ?? '');
        $desc      = self::scalar($row['productDescription'] ?? '');
        if ($shortDesc !== '') $product->set_short_description($shortDesc);
        if ($desc !== '')      $product->set_description($desc);

        // sizes as a visible (non-variation) attribute so customers see what's available.
        // flatten to plain scalar strings so a nested/odd array can never fatal.
        $sizeOpts = array();
        foreach ((array) $sizes as $sv) {
            $sv = self::scalar($sv);
            if ($sv !== '') $sizeOpts[] = $sv;
        }
        if (!empty($sizeOpts)) {
            $attr = new WC_Product_Attribute();
            $attr->set_name('Size');
            $attr->set_options($sizeOpts);
            $attr->set_visible(true);
            $attr->set_variation(false);
            $product->set_attributes(['size' => $attr]);
        }

        $pid = $product->save();
        if (!$pid) return 0;

        // managed flags
        update_post_meta($pid, SPP_MANAGED,   '1');
        // track when it went out of stock (cleared when back in stock) so the
        // "delete long-dead products" tool can age it accurately. See SPP_Purge.
        if (class_exists('SPP_Purge')) SPP_Purge::mark_oos_meta($pid, $stock);
        update_post_meta($pid, SPP_SOURCE_ID, $productId);
        update_post_meta($pid, SPP_SOURCE_DB, $row['dbName'] ?? '');
        update_post_meta($pid, '_spp_quote',  $isQuote ? 'yes' : 'no');
        update_post_meta($pid, '_spp_cat',    $catName);

        // theme meta — image URLs stored as meta (no attachment import at scale)
        update_post_meta($pid, 'featuredimg',          $row['featuredimg'] ?? '');
        update_post_meta($pid, 'imageUrl',             is_array($row['imageUrl'] ?? null) ? wp_json_encode($row['imageUrl']) : ($row['imageUrl'] ?? ''));
        update_post_meta($pid, 'videoUrl',             $row['videoUrl'] ?? '');
        update_post_meta($pid, 'sizeName',             is_array($row['sizeName'] ?? null) ? wp_json_encode($row['sizeName']) : ($row['sizeName'] ?? ''));
        update_post_meta($pid, 'productOriginalPrice', $row['productOriginalPrice'] ?? '');
        update_post_meta($pid, 'productUrl',           $row['productUrl'] ?? '');
        update_post_meta($pid, 'productFetchedFrom',   $row['productFetchedFrom'] ?? '');
        update_post_meta($pid, 'productBrand',         $row['productBrand'] ?? '');
        // visible availability field mirroring the server (1 = in stock, 0 = out),
        // kept in lock-step with WooCommerce's _stock_status set above.
        update_post_meta($pid, 'availability',         $stock === 'instock' ? '1' : '0');
        // ---- timestamps -------------------------------------------------
        // _spp_ts stays the hidden change-detector used by the fast-skip path.
        // The non-underscore copies are VISIBLE in the Custom Fields box next to
        // productFetchedFrom, so background behaviour can be verified per product.
        if (isset($row['productLastUpdated'])) {
            $ts = (string) $row['productLastUpdated'];
            update_post_meta($pid, '_spp_ts', $ts);
            update_post_meta($pid, 'productLastUpdated', $ts);              // raw ms epoch
            update_post_meta($pid, 'productLastUpdatedHuman', self::ms_to_human($ts));
        }
        // when THIS store last pulled the row (differs from the scraper's stamp)
        update_post_meta($pid, 'sppLastSynced', (string) (time() * 1000));
        update_post_meta($pid, 'sppLastSyncedHuman', self::ms_to_human(time() * 1000));

        // taxonomy terms
        if (!empty($row['catName'])) {
            self::assign_term($pid, 'product_cat', $row['catName']);
        }
        if (!empty($row['productBrand']) && taxonomy_exists('product_brand')) {
            self::assign_term($pid, 'product_brand', $row['productBrand']);
        }

        return $pid;
    }

    private static function assign_term($pid, $taxonomy, $name) {
        $name = trim((string) $name);
        if ($name === '') return;
        $term = get_term_by('name', $name, $taxonomy);
        if (!$term) {
            $new = wp_insert_term($name, $taxonomy);
            if (is_wp_error($new)) return;
            $term_id = (int) $new['term_id'];
        } else {
            $term_id = (int) $term->term_id;
        }
        wp_set_object_terms($pid, $term_id, $taxonomy, false);
    }

    public static function delete($pid) {
        wp_delete_post($pid, true);
    }
}
