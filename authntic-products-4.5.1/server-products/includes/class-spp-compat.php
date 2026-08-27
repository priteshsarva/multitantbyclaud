<?php
if (!defined('ABSPATH')) exit;

/**
 * Server compatibility.
 *
 * IMPORTANT: your UltimateScrapper server calls the WooCommerce REST API with
 *   ?meta_key=productLastUpdated&meta_value=<ts>&meta_compare=<
 * (see the /dev/outofstock5days and /dev/clean-old-oos-products routes).
 * WooCommerce does NOT support meta_key/meta_value on that endpoint by default,
 * so without this filter those routes silently return everything and the
 * stale/out-of-stock cleanup breaks.
 *
 * This is the fixed version from the child theme: it exits early when no meta
 * params are present (the original ran on every REST call and caused thousands
 * of PHP warnings and held DB connections open — the 503 cause).
 */
class SPP_Compat {

    public static function init() {
        add_filter('woocommerce_rest_product_object_query', array(__CLASS__, 'meta_query'), 10, 2);
        // late, so the child theme has already registered its hooks
        add_action('wp', array(__CLASS__, 'drop_child_theme_quote_button'), 20);
    }

    /**
     * flatsome-child/functions.php still registers add_custom_whatsapp_button()
     * on woocommerce_single_product_summary at 30. SPP_Quote renders its own
     * button at 29, so on a quote product BOTH appear — two nearly identical
     * "Request a Quote" buttons stacked.
     *
     * The child theme's version also reads its number and visibility from the
     * separate PriceGuard plugin's options with the WhatsApp number hard-coded in
     * the theme file, whereas the plugin's reads from its own settings. Ours wins.
     *
     * Harmless no-op if the function isn't there — so this stays correct both
     * before and after the child theme is cleaned up.
     */
    public static function drop_child_theme_quote_button() {
        if (function_exists('add_custom_whatsapp_button')) {
            remove_action('woocommerce_single_product_summary', 'add_custom_whatsapp_button', 30);
        }
    }

    public static function meta_query($args, $request) {
        $key   = $request->get_param('meta_key');
        $value = $request->get_param('meta_value');

        // exit early when unused — this is the fix that stopped the 503s
        if (empty($key) || $value === null || $value === '') return $args;

        $compare = strtoupper((string) $request->get_param('meta_compare'));
        $allowed = array('=', '!=', 'LIKE', 'NOT LIKE', '>', '<', '>=', '<=');
        if (!in_array($compare, $allowed, true)) $compare = '=';

        $mq = array(
            'key'     => sanitize_text_field($key),
            'value'   => sanitize_text_field($value),
            'compare' => $compare,
        );
        if (in_array($compare, array('>', '<', '>=', '<='), true)) $mq['type'] = 'NUMERIC';

        $args['meta_query'] = array($mq);
        return $args;
    }
}
