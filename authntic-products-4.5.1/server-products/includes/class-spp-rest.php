<?php
if (!defined('ABSPATH')) exit;

/**
 * Verification callback. The server fetches https://<this-domain>/wp-json/spp/v1/verify
 * to confirm this domain genuinely runs the plugin holding the configured key.
 * Returns a SHA-256 hash of the key (never the key itself) plus the domain, so the
 * binding can be proven without exposing the credential.
 */
class SPP_Rest {
    public static function init() {
        add_action('rest_api_init', function () {
            register_rest_route('spp/v1', '/verify', array(
                'methods'  => 'GET',
                'permission_callback' => '__return_true',
                'callback' => array(__CLASS__, 'verify'),
            ));
        });
    }

    public static function verify() {
        $key = trim((string) get_option(SPP_OPT_KEY, ''));
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = $host ? preg_replace('/^www\./', '', strtolower($host)) : '';
        return array(
            'domain'    => $host,
            'plugin'    => 'server-products',
            'version'   => defined('SPP_VERSION') ? SPP_VERSION : '',
            'has_key'   => $key !== '',
            'key_hash'  => $key !== '' ? hash('sha256', $key) : '',
        );
    }
}
