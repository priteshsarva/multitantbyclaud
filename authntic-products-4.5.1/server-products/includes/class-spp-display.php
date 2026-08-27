<?php
if (!defined('ABSPATH')) exit;

/**
 * VIRTUAL ATTACHMENTS — make managed products genuinely "have" a featured image
 * and a gallery, without importing a single file, so every theme and WooCommerce
 * path renders them natively.
 *
 * THE PROBLEM THIS REPLACES
 *   Managed products store their images only as URL meta (featuredimg / imageUrl).
 *   The old approach filtered woocommerce_product_get_image + post_thumbnail_html
 *   — string filters that a theme only hits if it calls $product->get_image().
 *   Flatsome does not: its shop cards and single-product gallery read the product
 *   ATTACHMENT directly (get_image_id, get_the_post_thumbnail, wc_get_gallery_image_html).
 *   With no attachment, those paths showed a placeholder — which is exactly why
 *   deleting the child-theme templates made images disappear, and why the custom
 *   gallery template never took.
 *
 * THE FIX (documented WordPress technique — see "Featured Image from URL")
 *   Hook the LOW-LEVEL functions every image path funnels through, and answer for
 *   a set of virtual attachment ids that we mint per request:
 *     - get_post_metadata(_thumbnail_id)        -> has_post_thumbnail() == true
 *     - woocommerce_product_get_image_id        -> WC featured image id
 *     - woocommerce_product_get_gallery_image_ids -> WC gallery ids (when enabled)
 *     - image_downsize                          -> [url, w, h] for a virtual id
 *       (wp_get_attachment_image_src, wp_get_attachment_image, the_post_thumbnail,
 *        srcset, og:image, admin, REST — ALL call image_downsize)
 *     - wp_get_attachment_url / _image_url      -> the url (templates that call these)
 *   The result: shop cards, cart, mini-cart, widgets, related, the single-product
 *   main image AND the theme's own gallery slider (zoom + lightbox + thumbnails)
 *   all render the external images. No template files, no theme coupling.
 *
 * WHY VIRTUAL IDS ARE SAFE
 *   They are minted above the realistic attachment-id range and only ever exist in
 *   this class's request-scoped map. Every filter passes through untouched unless
 *   the id is one we minted, so nothing else on the site is affected.
 */
class SPP_Display {

    /** virtual-id => absolute url, for this request only */
    private static $vmap  = array();
    /** product-id => array('feat' => vid, 'gallery' => array(vid,...)), memoised */
    private static $vmemo = array();
    /** id counter, started well above any realistic real attachment id */
    private static $vseq  = 1000000001;

    public static function init() {
        // ---- id injection: make the product "have" an image + gallery ----
        add_filter('get_post_metadata',                       [__CLASS__, 'inject_thumbnail_id'], 10, 4);
        add_filter('woocommerce_product_get_image_id',        [__CLASS__, 'inject_image_id'], 10, 2);
        add_filter('woocommerce_product_get_gallery_image_ids',[__CLASS__, 'inject_gallery_ids'], 10, 2);

        // ---- resolution: turn a virtual id into a real URL everywhere ----
        add_filter('image_downsize',                 [__CLASS__, 'downsize'], 10, 3);
        add_filter('wp_get_attachment_image_src',    [__CLASS__, 'image_src'], 10, 3);
        add_filter('wp_get_attachment_url',          [__CLASS__, 'attachment_url'], 10, 2);
        add_filter('wp_get_attachment_image_url',    [__CLASS__, 'attachment_url'], 10, 2);
        add_filter('wp_get_attachment_image_attributes', [__CLASS__, 'image_attrs'], 10, 3);

        // One diagnostic line in the footer of a single-product page. Costs nothing
        // and turns "still doesn't work" into a fact you can read in View Source.
        add_action('wp_footer', [__CLASS__, 'debug_comment'], 99);
    }

    /**
     * A single diagnostic line in the page footer, on single-product AND shop/
     * archive pages. On an archive it actually runs $product->get_image() on the
     * first managed product in the loop and reports whether a REAL image, a
     * PLACEHOLDER, or nothing came out — which pinpoints why loop images are or
     * aren't showing, straight from the live site's View Source.
     *
     *   <!-- SPP-IMG v=4.7.2 ctx=archive product=77139 managed=yes featuredimg=yes
     *        imageUrl=3 image_id=1000000001 has_thumb=yes gallery_on=yes
     *        loop_get_image=REAL theme=flatsome-child child_override=absent -->
     */
    public static function debug_comment() {
        if (!function_exists('is_product')) return;

        $ctx = '';
        $pid = 0;
        if (is_product()) {
            $ctx = 'single';
            global $product;
            if ($product && is_object($product) && method_exists($product, 'get_id')) $pid = $product->get_id();
        } elseif ((function_exists('is_shop') && is_shop())
               || (function_exists('is_product_category') && is_product_category())
               || (function_exists('is_product_tag') && is_product_tag())) {
            $ctx = 'archive';
            // first managed product in the main loop
            global $wp_query;
            if (!empty($wp_query->posts)) {
                foreach ($wp_query->posts as $p) {
                    $id = is_object($p) ? $p->ID : (int) $p;
                    if (SPP_Product::is_managed($id)) { $pid = $id; break; }
                }
                if (!$pid && isset($wp_query->posts[0])) {
                    $pid = is_object($wp_query->posts[0]) ? $wp_query->posts[0]->ID : (int) $wp_query->posts[0];
                }
            }
        } else {
            return; // not a product-listing context
        }
        if (!$pid) { echo "\n<!-- SPP-IMG v=" . (defined('SPP_VERSION') ? SPP_VERSION : '?') . " ctx=$ctx product=0 (no product found) -->\n"; return; }

        $managed = SPP_Product::is_managed($pid);
        $feat    = trim((string) get_post_meta($pid, 'featuredimg', true)) !== '';
        $raw     = get_post_meta($pid, 'imageUrl', true);
        $imgN    = 0;
        if (is_string($raw) && $raw !== '') { $d = json_decode($raw, true); if (is_array($d)) $imgN = count($d); }

        $imageId  = 0;
        $loopImg  = 'n/a';
        if (function_exists('wc_get_product')) {
            $prod = wc_get_product($pid);
            if ($prod) {
                $imageId = (int) $prod->get_image_id();
                // the real test: what does the loop actually render?
                $html = $prod->get_image('woocommerce_thumbnail');
                if     ($html === '')                              $loopImg = 'EMPTY';
                elseif (stripos($html, 'placeholder') !== false)   $loopImg = 'PLACEHOLDER';
                elseif ($feat && strpos($html, 'featuredimg') === false) $loopImg = 'REAL';
                else                                               $loopImg = 'REAL';
            }
        }
        $hasThumb = has_post_thumbnail($pid) ? 'yes' : 'no';

        $override = 'absent';
        if (function_exists('get_stylesheet_directory')) {
            $f = get_stylesheet_directory() . '/woocommerce/single-product/product-image.php';
            if (file_exists($f)) $override = 'PRESENT(' . $f . ')';
        }

        printf(
            "\n<!-- SPP-IMG v=%s ctx=%s product=%d managed=%s featuredimg=%s imageUrl=%d image_id=%d has_thumb=%s gallery_on=%s loop_get_image=%s theme=%s child_override=%s -->\n",
            defined('SPP_VERSION') ? SPP_VERSION : '?',
            esc_html($ctx),
            (int) $pid,
            $managed ? 'yes' : 'NO',
            $feat ? 'yes' : 'NO',
            (int) $imgN,
            $imageId,
            $hasThumb,
            self::gallery_on() ? 'yes' : 'no',
            esc_html($loopImg),
            esc_html(function_exists('get_stylesheet') ? get_stylesheet() : '?'),
            esc_html($override)
        );
    }

    // ------------------------------------------------------------------ helpers

    private static function is_managed($pid) {
        return $pid && SPP_Product::is_managed($pid);
    }

    /** true when the store wants the FULL gallery (multi image), not just featured */
    private static function gallery_on() {
        return get_option(SPP_Gallery::OPT_ENABLED) === 'yes';
    }

    /** ordered, de-duplicated image URLs for a product: featured first, then the rest */
    private static function image_urls($pid) {
        $feat = trim((string) get_post_meta($pid, 'featuredimg', true));

        $raw  = get_post_meta($pid, 'imageUrl', true);
        $list = array();
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) $list = $d;
        }
        $list = array_values(array_filter(array_map(function ($u) { return trim((string) $u); }, $list)));

        if ($feat === '' && !empty($list)) $feat = $list[0];
        if ($feat === '') return array();               // nothing to show

        // featured first, then every other image once
        $out = array($feat);
        foreach ($list as $u) {
            if ($u !== '' && $u !== $feat && !in_array($u, $out, true)) $out[] = $u;
        }
        return $out;
    }

    /**
     * Ensure virtual ids exist for this product and return them.
     * @return array{feat:int,gallery:int[]}|null
     */
    private static function registry($pid) {
        if (array_key_exists($pid, self::$vmemo)) return self::$vmemo[$pid];

        $urls = self::image_urls($pid);
        if (empty($urls)) return self::$vmemo[$pid] = null;

        $feat = self::mint($urls[0]);
        $gallery = array();
        foreach (array_slice($urls, 1) as $u) $gallery[] = self::mint($u);

        return self::$vmemo[$pid] = array('feat' => $feat, 'gallery' => $gallery);
    }

    private static function mint($url) {
        $vid = self::$vseq++;
        self::$vmap[$vid] = $url;
        return $vid;
    }

    /** url for a virtual id, or null if the id is not one of ours */
    private static function vurl($id) {
        $id = (int) $id;
        return isset(self::$vmap[$id]) ? self::$vmap[$id] : null;
    }

    /** best-effort width/height for a requested size (only used for zoom sizing) */
    private static function dims($size) {
        if (is_array($size) && count($size) >= 2) return array((int) $size[0], (int) $size[1]);
        if (is_string($size) && function_exists('wc_get_image_size')) {
            $key = preg_replace('/^woocommerce_/', '', $size);
            $s = wc_get_image_size($key);
            if (!empty($s['width'])) return array((int) $s['width'], (int) ($s['height'] ?: $s['width']));
        }
        if (is_string($size) && function_exists('wp_get_registered_image_subsizes')) {
            $all = wp_get_registered_image_subsizes();
            if (isset($all[$size]['width'])) return array((int) $all[$size]['width'], (int) $all[$size]['height']);
        }
        return array(1200, 1200); // 'full' and anything unknown
    }

    // ------------------------------------------------------------- id injection

    /**
     * Give managed products a featured-image id so has_post_thumbnail() is true
     * and get_post_thumbnail_id() returns something. Only fires for _thumbnail_id,
     * only for managed products, and returns null (no interference) otherwise.
     * The other metas we read here (_spp_managed, featuredimg, imageUrl) never
     * recurse into _thumbnail_id, so this is safe.
     */
    public static function inject_thumbnail_id($value, $object_id, $meta_key, $single) {
        if ($meta_key !== '_thumbnail_id') return $value;
        if (!self::is_managed($object_id))  return $value;
        $reg = self::registry($object_id);
        if (!$reg) return $value;
        // get_post_metadata expects an array; core takes [0] when $single.
        return array((string) $reg['feat']);
    }

    public static function inject_image_id($id, $product) {
        if (!$product || !self::is_managed($product->get_id())) return $id;
        $reg = self::registry($product->get_id());
        return $reg ? $reg['feat'] : $id;
    }

    public static function inject_gallery_ids($ids, $product) {
        if (!$product || !self::is_managed($product->get_id())) return $ids;
        if (!self::gallery_on()) return $ids;          // single-image mode: no gallery
        $reg = self::registry($product->get_id());
        return ($reg && $reg['gallery']) ? $reg['gallery'] : $ids;
    }

    // --------------------------------------------------------------- resolution

    /** THE hook that matters: every wp_get_attachment_image* call goes through this. */
    public static function downsize($out, $id, $size) {
        $url = self::vurl($id);
        if ($url === null) return $out;
        list($w, $h) = self::dims($size);
        return array($url, $w, $h, false);             // false = treat as a full-size image
    }

    public static function image_src($image, $attachment_id, $size) {
        $url = self::vurl($attachment_id);
        if ($url === null) return $image;
        list($w, $h) = self::dims($size);
        return array($url, $w, $h, false);
    }

    public static function attachment_url($url, $attachment_id) {
        $v = self::vurl($attachment_id);
        return $v !== null ? $v : $url;
    }

    /** virtual ids have no real metadata, so drop srcset/sizes to avoid broken output */
    public static function image_attrs($attr, $attachment, $size) {
        $id = is_object($attachment) && isset($attachment->ID) ? $attachment->ID : 0;
        if (self::vurl($id) === null) return $attr;
        unset($attr['srcset'], $attr['sizes']);
        return $attr;
    }
}
