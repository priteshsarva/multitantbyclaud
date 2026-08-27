<?php
if (!defined('ABSPATH')) exit;

/**
 * Quote-only products (blank-margin band) show no price and no add-to-cart, and
 * instead a "Request Quote" button that opens WhatsApp pre-filled with a custom
 * message + product name + link. The price is replaced with "Price on request"
 * everywhere (single, shop, category, related), and the button uses the theme's
 * own button styling.
 */
class SPP_Quote {

    public static function init() {
        // both filters, at max priority so nothing re-adds the price after us
        add_filter('woocommerce_get_price_html',   array(__CLASS__, 'price_html'), PHP_INT_MAX, 2);
        add_filter('woocommerce_empty_price_html', array(__CLASS__, 'price_html'), PHP_INT_MAX, 2);
        add_filter('woocommerce_is_purchasable',   array(__CLASS__, 'not_purchasable'), PHP_INT_MAX, 2);

        // tag quote products with a class + CSS, so the price is hidden even when the
        // theme renders it outside the standard price filter (Flatsome, cached markup, etc.)
        add_filter('post_class',              array(__CLASS__, 'post_class'), 10, 3);
        add_filter('woocommerce_post_class',  array(__CLASS__, 'wc_post_class'), 10, 2);
        add_action('wp_head',                 array(__CLASS__, 'hide_price_css'));

        // Quote button in place of add-to-cart — SINGLE PRODUCT PAGE ONLY.
        // Deliberately NOT hooked to woocommerce_after_shop_loop_item: on shop,
        // category and search listings the card shows no button, so the shopper
        // opens the product to enquire. WooCommerce still renders its own
        // "Read more" link on the card, because quote products are not purchasable.
        add_action('woocommerce_single_product_summary', array(__CLASS__, 'single_button'), 29);
    }

    public static function post_class($classes, $class, $post_id) {
        if ($post_id && self::is_quote($post_id)) $classes[] = 'spp-quote-product';
        return $classes;
    }
    public static function wc_post_class($classes, $product) {
        if (self::is_quote($product)) $classes[] = 'spp-quote-product';
        return $classes;
    }

    public static function hide_price_css() {
        echo '<style id="spp-quote-css">'
           . '.spp-quote-product .price .woocommerce-Price-amount,'
           . '.spp-quote-product .price del,'
           . '.spp-quote-product .price ins{display:none !important;}'
           . '.spp-quote-product .price .spp-quote-price{display:inline-block !important;}'
           . '</style>';
    }

    public static function is_quote($product) {
        if (!$product) return false;
        $id = is_object($product) ? $product->get_id() : (int) $product;
        // primary: the flag set at sync/reprice time
        if (get_post_meta($id, '_spp_quote', true) === 'yes') return true;
        // fallback: compute live from the stored original price + this product's
        // category rules, so a product synced before a quote band existed still
        // shows correctly even if a re-price hasn't run yet.
        $orig = get_post_meta($id, 'productOriginalPrice', true);
        if ($orig === '' || $orig === null) return false;
        $cat  = (string) get_post_meta($id, '_spp_cat', true);
        return SPP_Margin::is_quote_for($orig, $cat);
    }

    private static function label() {
        return '<span class="spp-quote-price">' . esc_html(get_option('spp_quote_price_label', 'Price on request')) . '</span>';
    }

    // used for BOTH woocommerce_get_price_html and woocommerce_empty_price_html
    public static function price_html($html, $product) {
        return self::is_quote($product) ? self::label() : $html;
    }

    public static function not_purchasable($purchasable, $product) {
        return self::is_quote($product) ? false : $purchasable;
    }

    // wa.me link with message + product name + link
    public static function whatsapp_url($product) {
        $number = preg_replace('/[^0-9]/', '', (string) get_option('spp_quote_whatsapp', ''));
        if (!$number) return '';
        $msg  = trim((string) get_option('spp_quote_message', 'Hi, I would like a quote for this product:'));
        $parts = array();
        if ($msg !== '') $parts[] = $msg;
        if (get_option('spp_quote_include_name', 'yes') === 'yes') $parts[] = $product->get_name();
        if (get_option('spp_quote_include_link', 'yes') === 'yes') $parts[] = get_permalink($product->get_id());
        return 'https://wa.me/' . $number . '?text=' . rawurlencode(implode("\n", $parts));
    }

    // inherits the theme's button styling via the standard "button" class — no inline styles
    private static function button_html($product, $extra_class = '') {
        $url   = self::whatsapp_url($product);
        $label = get_option('spp_quote_button_label', 'Request Quote');
        $class = trim('button spp-quote-btn ' . $extra_class);
        if ($url) {
            return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener nofollow" class="' . esc_attr($class) . '">'
                 . esc_html($label) . '</a>';
        }
        // No WhatsApp number configured: don't leave a dead end. Show the price
        // label as plain text so the customer at least sees it's on request.
        // (The admin health check flags the missing number so this gets fixed.)
        if (current_user_can('manage_woocommerce')) {
            return '<span class="' . esc_attr($class) . '" style="opacity:.6;pointer-events:none">'
                 . esc_html($label) . ' — set a WhatsApp number in settings</span>';
        }
        return '';
    }

    public static function single_button() {
        global $product;
        if (!self::is_quote($product)) return;
        $btn = self::button_html($product, 'alt');
        if ($btn) echo '<p class="spp-quote-wrap">' . $btn . '</p>';
    }

    /**
     * NOT HOOKED — kept only so the archive/card button can be restored by
     * re-adding the woocommerce_after_shop_loop_item action in init().
     * By design the quote button appears on the single product page only.
     */
    public static function loop_button() {
        global $product;
        if (!self::is_quote($product)) return;
        // WooCommerce already rendered a "Read more" link for non-purchasable items;
        // add the quote button alongside it.
        $btn = self::button_html($product);
        if ($btn) echo ' ' . $btn;
    }
}
