<?php
if (!defined('ABSPATH')) exit;

/**
 * Everything that used to live in the Flatsome CHILD THEME, ported here so the
 * plugin is the only thing you need when you change themes.
 *
 * Replaces:
 *   - functions.php: video shortcodes, admin meta panel, admin thumbnails,
 *     title cleanup, AJAX search, featured-first ordering, brand filters,
 *     hidden category, target=_blank
 *   - woocommerce/ template overrides: external images in loop, single, cart,
 *     mini-cart, widget, wishlist, checkout  (all done with filters instead of
 *     template files, so no template ever needs copying again)
 */
class SPP_Theme {

    public static function init() {
        // ---- external images (replaces every template override) ----
        add_filter('woocommerce_cart_item_thumbnail',    array(__CLASS__, 'cart_thumb'), 20, 3);
        add_filter('woocommerce_cart_item_name',         array(__CLASS__, 'checkout_item_image'), 9999, 3);
        add_filter('woocommerce_widget_cart_item_quantity', array(__CLASS__, 'noop'), 10, 1);

        // ---- titles: strip underscores everywhere ----
        add_filter('woocommerce_product_get_name', array(__CLASS__, 'clean_name'), 10, 2);
        add_filter('the_title',                    array(__CLASS__, 'clean_title'), 10, 2);

        // ---- product links open in a new tab ----
        if (self::opt('spp_theme_target_blank', 'yes') === 'yes') {
            add_filter('post_type_link', array(__CLASS__, 'noop_link'), 10, 2);
            add_action('wp_footer',      array(__CLASS__, 'target_blank_js'));
        }

        // ---- live video shortcodes ----
        add_shortcode('show_live_video_btn', array(__CLASS__, 'sc_video_button'));
        add_shortcode('live_video_player',   array(__CLASS__, 'sc_video_player'));

        // ---- admin: source meta panel on the product page ----
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'admin_meta_panel'), 25);

        // ---- admin: thumbnails from featuredimg ----
        add_filter('woocommerce_admin_order_item_thumbnail', array(__CLASS__, 'admin_order_thumb'), 10, 3);
        add_filter('manage_edit-product_columns',            array(__CLASS__, 'admin_thumb_column'), 20);
        add_action('manage_product_posts_custom_column',     array(__CLASS__, 'admin_thumb_cell'), 10, 2);
        add_action('admin_head',                             array(__CLASS__, 'admin_thumb_css'));

        // ---- Flatsome AJAX search using featuredimg + in-stock only ----
        if (self::opt('spp_theme_search', 'yes') === 'yes') {
            add_action('init', array(__CLASS__, 'takeover_search'), 20);
        }

        // ---- featured products first ----
        if (self::opt('spp_theme_featured_first', 'yes') === 'yes') {
            add_filter('posts_orderby', array(__CLASS__, 'featured_first'), 10, 2);
            add_action('woocommerce_update_product', function () { delete_transient('spp_featured_ids'); });
        }

        // ---- brand filtering ----
        add_filter('woocommerce_layered_nav_terms',       array(__CLASS__, 'brands_by_category'), 10, 4);
        add_filter('woocommerce_brand_layered_nav_terms', array(__CLASS__, 'limit_brands'), 10, 2);

        // ---- hide a category (and its descendants) everywhere ----
        if (self::hidden_slug()) {
            add_action('woocommerce_product_query', array(__CLASS__, 'exclude_hidden'), 10, 1);
            add_action('pre_get_posts',             array(__CLASS__, 'exclude_hidden_search'), 10, 1);
            add_filter('woocommerce_product_categories_widget_args', array(__CLASS__, 'exclude_hidden_widget'));
            foreach (array('edited_product_cat', 'created_product_cat', 'delete_product_cat') as $h) {
                add_action($h, function () { delete_transient('spp_hidden_cat_ids'); });
            }
        }
    }

    private static function opt($k, $d = '') { return get_option($k, $d); }
    public  static function noop($x) { return $x; }
    public  static function noop_link($l) { return $l; }

    private static function is_managed($pid) { return SPP_Product::is_managed($pid); }

    /** first external image for a product: featuredimg, else first of imageUrl */
    public static function image_url($pid) {
        $f = get_post_meta($pid, 'featuredimg', true);
        if (!empty($f)) return $f;
        $g = get_post_meta($pid, 'imageUrl', true);
        if (!empty($g) && is_string($g)) $g = json_decode($g, true);
        if (is_array($g) && !empty($g[0])) return $g[0];
        return '';
    }

    /** all gallery images as an array */
    public static function gallery($pid) {
        $g = get_post_meta($pid, 'imageUrl', true);
        if (!empty($g) && is_string($g)) $g = json_decode($g, true);
        return is_array($g) ? $g : array();
    }

    // ---------- cart + checkout images (replaces cart.php / mini-cart.php) ----------
    public static function cart_thumb($html, $cart_item, $key) {
        $p = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$p || !is_object($p)) return $html;
        $pid = $p->get_id();
        if (!self::is_managed($pid)) return $html;
        $url = self::image_url($pid);
        if (!$url) return $html;
        return '<img src="' . esc_url($url) . '" alt="' . esc_attr($p->get_name()) . '" class="attachment-woocommerce_thumbnail" style="object-fit:cover;" />';
    }

    /** checkout order-review rows show only the name — prepend the external image */
    public static function checkout_item_image($name, $cart_item, $key) {
        if (!function_exists('is_checkout') || !is_checkout()) return $name;
        $p = isset($cart_item['data']) ? $cart_item['data'] : null;
        if (!$p || !is_object($p)) return $name;
        $pid = $p->get_id();
        if (!self::is_managed($pid)) return $name;
        $url = self::image_url($pid);
        if (!$url) return $name;
        $img = '<img src="' . esc_url($url) . '" alt="' . esc_attr($p->get_name()) . '" width="50" height="50" '
             . 'style="margin-right:10px;vertical-align:middle;display:inline-block;object-fit:cover;" '
             . 'referrerpolicy="no-referrer" loading="lazy" />';
        return $img . $name;
    }

    // ---------- titles ----------
    public static function clean_name($title, $product = null) {
        return str_replace('_', ' ', ltrim((string) $title, '_'));
    }
    public static function clean_title($title, $id = null) {
        if (!$id || get_post_type($id) !== 'product') return $title;
        if (!self::is_managed($id)) return $title;
        return ucwords(str_replace('_', ' ', ltrim((string) $title, '_')));
    }

    public static function target_blank_js() {
        if (is_admin()) return;
        // permalink base differs per site (/product/, /shop/, custom) — detect it
        $base = '/product/';
        $struct = get_option('woocommerce_permalinks');
        if (is_array($struct) && !empty($struct['product_base'])) {
            $base = '/' . trim($struct['product_base'], '/') . '/';
        }
        ?><script>(function(){
          var BASE=<?php echo wp_json_encode($base); ?>;
          function apply(){
            var as=document.querySelectorAll('a[href*="'+BASE+'"]');
            for(var i=0;i<as.length;i++){
              var a=as[i];
              // only product links inside content areas — skip nav menus & the
              // single product page's own gallery/lightbox anchors
              if(a.closest('nav,.menu,.site-header,header,.woocommerce-product-gallery,.spp-gallery-wrap,#wpadminbar')) continue;
              if(a.getAttribute('target')!=='_blank'){ a.setAttribute('target','_blank'); a.setAttribute('rel','noopener'); }
            }
          }
          if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',apply); } else { apply(); }
          // re-apply after ANY ajax content injection (infinite scroll, filters,
          // blocks) — cheap, and covers every theme without knowing its events
          if(window.MutationObserver){
            var t=null, mo=new MutationObserver(function(){ clearTimeout(t); t=setTimeout(apply,150); });
            mo.observe(document.body,{childList:true,subtree:true});
          }
          if(window.jQuery){ jQuery(document.body).on('updated_wc_div post-load yith_infs_added_elem updated_products',apply); }
        })();</script><?php
    }

    // ---------- live video ----------
    public static function sc_video_button() {
        $p = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        if (!$p) return '';
        $v = get_post_meta($p->get_id(), 'videoUrl', true);
        if (empty($v)) return '';
        return do_shortcode('[button text="Watch Live Video" id="LiveVideo" class="LiveVideo"]');
    }
    public static function sc_video_player() {
        $p = function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null;
        if (!$p) return '';
        $v = get_post_meta($p->get_id(), 'videoUrl', true);
        if (empty($v)) return '';
        return '<video controls muted style="height:600px;width:auto;max-width:100%;display:block;margin:0 auto;object-fit:contain;">'
             . '<source src="' . esc_url($v) . '" type="video/mp4"></video>';
    }

    // ---------- admin meta panel ----------
    public static function admin_meta_panel() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return;
        global $product;
        if (!$product || !is_a($product, 'WC_Product')) return;
        $id  = $product->get_id();
        $op  = get_post_meta($id, 'productOriginalPrice', true);
        $url = get_post_meta($id, 'productUrl', true);
        $ff  = get_post_meta($id, 'productFetchedFrom', true);
        if (!$op && !$url && !$ff) return;
        echo '<div class="admin-product-meta" style="margin:20px 0;background:#f7f7f7;padding:15px;border:1px solid #ddd;border-radius:8px;">';
        echo '<h4 style="margin-bottom:10px;">🔒 Admin Product Details</h4>';
        if ($op)  echo '<p><strong>Original Price:</strong> ₹' . esc_html($op) . '</p>';
        if ($url) echo '<p><strong>Product URL:</strong> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($url) . '</a></p>';
        if ($ff)  echo '<p><strong>Fetched From:</strong> ' . esc_html($ff) . '</p>';
        echo '</div>';
    }

    // ---------- admin thumbnails ----------
    public static function admin_order_thumb($thumb, $item_id, $item) {
        if (!is_object($item) || !method_exists($item, 'get_product')) return $thumb;
        $p = $item->get_product();
        if (!$p || !$p->exists()) return $thumb;
        $url = self::image_url($p->get_id());
        if (!$url) return $thumb;
        return '<img src="' . esc_url($url) . '" width="60" height="60" alt="' . esc_attr($p->get_title())
             . '" style="object-fit:cover;aspect-ratio:1/1;border-radius:4px;" />';
    }
    public static function admin_thumb_column($cols) {
        if (isset($cols['thumb'])) unset($cols['thumb']);
        $out = array();
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'cb') $out['spp_thumb'] = '<span style="width:52px;display:inline-block;">Image</span>';
        }
        return $out;
    }
    public static function admin_thumb_cell($col, $pid) {
        if ($col !== 'spp_thumb') return;
        $url = self::image_url($pid);
        echo '<a href="' . esc_url(get_edit_post_link($pid)) . '">';
        if ($url) {
            echo '<img src="' . esc_url($url) . '" width="60" height="60" alt="" style="object-fit:cover;width:60px;height:60px;border-radius:4px;">';
        } elseif (has_post_thumbnail($pid)) {
            echo get_the_post_thumbnail($pid, array(60, 60), array('style' => 'object-fit:cover;border-radius:4px;'));
        } elseif (function_exists('wc_placeholder_img_src')) {
            echo '<img src="' . esc_url(wc_placeholder_img_src()) . '" width="60" height="60" alt="" style="opacity:.6;border-radius:4px;">';
        }
        echo '</a>';
    }
    public static function admin_thumb_css() {
        echo '<style>.wp-list-table .column-spp_thumb{width:52px;min-width:52px}'
           . '.wp-list-table .column-spp_thumb img{width:50px;height:50px;object-fit:cover;display:block;margin:auto;border-radius:4px}</style>';
    }

    // ---------- Flatsome AJAX search ----------
    public static function takeover_search() {
        remove_action('wp_ajax_flatsome_ajax_search_products', 'flatsome_ajax_search');
        remove_action('wp_ajax_nopriv_flatsome_ajax_search_products', 'flatsome_ajax_search');
        add_action('wp_ajax_flatsome_ajax_search_products', array(__CLASS__, 'ajax_search'));
        add_action('wp_ajax_nopriv_flatsome_ajax_search_products', array(__CLASS__, 'ajax_search'));
    }
    public static function ajax_search() {
        $term = isset($_REQUEST['query']) ? sanitize_text_field(wp_unslash($_REQUEST['query'])) : '';
        $args = array(
            'post_type' => 'product', 'post_status' => 'publish', 's' => $term,
            'posts_per_page' => 20, 'ignore_sticky_posts' => true, 'no_found_rows' => true,
            'meta_query' => array(array('key' => '_stock_status', 'value' => 'instock')),
        );
        $hidden = self::hidden_ids();
        if (!empty($hidden)) {
            $args['tax_query'] = array(array(
                'taxonomy' => 'product_cat', 'field' => 'term_id',
                'terms' => $hidden, 'operator' => 'NOT IN',
            ));
        }
        $q = new WP_Query($args);
        $out = array();
        while ($q->have_posts()) {
            $q->the_post();
            $p = wc_get_product(get_the_ID());
            if (!$p) continue;
            $img = self::image_url($p->get_id());
            if (!$img) $img = get_the_post_thumbnail_url($p->get_id(), 'thumbnail');
            $out[] = array(
                'type' => 'Product', 'id' => $p->get_id(), 'value' => $p->get_name(),
                'url' => get_permalink($p->get_id()), 'img' => $img ? $img : '',
                'price' => $p->get_price_html(),
            );
        }
        wp_reset_postdata();
        if (empty($out)) $out[] = array('id' => -1, 'value' => __('No products found.', 'woocommerce'), 'url' => '');
        wp_send_json(array('suggestions' => $out));
    }

    // ---------- featured first ----------
    public static function featured_first($order_by, $query) {
        if (is_admin() || !$query->is_main_query()) return $order_by;
        if (!function_exists('is_woocommerce') || !is_woocommerce()) return $order_by;
        $ids = get_transient('spp_featured_ids');
        if (false === $ids) {
            $ids = function_exists('wc_get_featured_product_ids') ? wc_get_featured_product_ids() : array();
            set_transient('spp_featured_ids', $ids, HOUR_IN_SECONDS);
        }
        if (empty($ids)) return $order_by;
        global $wpdb;
        $clause = "FIELD({$wpdb->posts}.ID," . implode(',', array_map('intval', $ids)) . ') DESC';
        return empty($order_by) ? $clause : $clause . ', ' . $order_by;
    }

    // ---------- brands ----------
    public static function brands_by_category($terms, $tax, $query_type, $query_var) {
        if ($tax !== 'product_brand') return $terms;
        $cat = get_query_var('product_cat');
        if (!$cat && !empty($_GET['product_cat'])) $cat = sanitize_text_field($_GET['product_cat']);
        if (!$cat) return $terms;

        $tk  = 'spp_brands_cat_' . md5($cat);
        $ids = get_transient($tk);
        if (false === $ids) {
            global $wpdb;
            $ct = get_term_by('slug', $cat, 'product_cat');
            if (!$ct) return $terms;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT tb.term_taxonomy_id
                   FROM {$wpdb->term_relationships} tb
                   INNER JOIN {$wpdb->term_relationships} tc ON tb.object_id = tc.object_id
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tb.term_taxonomy_id = tt.term_taxonomy_id
                  WHERE tc.term_taxonomy_id = %d AND tt.taxonomy = 'product_brand'",
                $ct->term_taxonomy_id
            ));
            set_transient($tk, !empty($ids) ? $ids : array('none'), 12 * HOUR_IN_SECONDS);
        }
        if (empty($ids) || (count($ids) === 1 && $ids[0] === 'none')) return array();
        return array_filter($terms, function ($t) use ($ids) {
            return in_array($t->term_taxonomy_id, $ids) || in_array($t->term_id, $ids);
        });
    }

    public static function limit_brands($terms, $taxonomy) {
        if ($taxonomy !== 'product_brand') return $terms;
        $raw = trim((string) self::opt('spp_theme_allowed_brands', ''));
        if ($raw === '') return $terms;                       // empty = show all
        $allowed = array_filter(array_map('trim', explode(',', strtolower($raw))));
        if (empty($allowed)) return $terms;
        return array_filter($terms, function ($t) use ($allowed) {
            return in_array(strtolower($t->slug), $allowed, true);
        });
    }

    // ---------- hidden category ----------
    public static function hidden_slug() { return trim((string) self::opt('spp_theme_hidden_cat', '')); }

    public static function hidden_ids() {
        $slug = self::hidden_slug();
        if (!$slug) return array();
        $c = get_transient('spp_hidden_cat_ids');
        if (false !== $c) return $c;

        global $wpdb;
        $parent = $wpdb->get_var($wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t
               JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
              WHERE t.slug = %s AND tt.taxonomy = 'product_cat' LIMIT 1", $slug
        ));
        if (!$parent) { set_transient('spp_hidden_cat_ids', array(), 12 * HOUR_IN_SECONDS); return array(); }

        $all   = array((int) $parent);
        $queue = array((int) $parent);
        while (!empty($queue)) {
            $cur  = array_shift($queue);
            $kids = $wpdb->get_col($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent = %d AND taxonomy = 'product_cat'", $cur
            ));
            if (!empty($kids)) {
                $kids = array_map('intval', $kids);
                $all   = array_merge($all, $kids);
                $queue = array_merge($queue, $kids);
            }
        }
        set_transient('spp_hidden_cat_ids', $all, 12 * HOUR_IN_SECONDS);
        return $all;
    }

    private static function add_not_in($q) {
        $ids = self::hidden_ids();
        if (empty($ids)) return;
        $tq = $q->get('tax_query') ?: array();
        $tq[] = array('taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $ids, 'operator' => 'NOT IN');
        $q->set('tax_query', $tq);
    }
    public static function exclude_hidden($q)        { if (!is_admin()) self::add_not_in($q); }
    public static function exclude_hidden_search($q) { if (!is_admin() && $q->is_main_query() && $q->is_search()) self::add_not_in($q); }
    public static function exclude_hidden_widget($args) {
        $ids = self::hidden_ids();
        if (!empty($ids)) {
            $ex = isset($args['exclude']) ? (array) $args['exclude'] : array();
            $args['exclude'] = array_merge($ex, $ids);
        }
        return $args;
    }
}
