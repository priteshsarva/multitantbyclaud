<?php
if (!defined('ABSPATH')) exit;

class SPP_API {

    // why the current request is happening: 'auto' | 'manual' | 'full-resync' | 'check'
    // Sent as x-spp-trigger so the server can log manual Sync-now / Full-resync separately.
    public static $trigger = 'auto';

    public static function server() {
        // Locked. Not shown or editable in the admin UI so store owners can't change it.
        // A developer with server access can override by defining SPP_SERVER_URL in wp-config.php.
        if (defined('SPP_SERVER_URL') && SPP_SERVER_URL) return untrailingslashit(SPP_SERVER_URL);
        return untrailingslashit(SPP_DEFAULT_SERVER);
    }

    public static function key() {
        return trim((string) get_option(SPP_OPT_KEY, ''));
    }

    // this site's domain (host only), sent so the server can domain-lock the key
    public static function site_domain() {
        $h = wp_parse_url(home_url(), PHP_URL_HOST);
        return $h ? preg_replace('/^www\./', '', strtolower($h)) : '';
    }

    private static function headers() {
        return array(
            'x-enrollment-key' => self::key(),
            'x-site-domain'    => self::site_domain(),
            'x-spp-trigger'    => self::$trigger,
            'x-spp-version'    => defined('SPP_VERSION') ? SPP_VERSION : '',
        );
    }

    /**
     * GET /product/sync-feed?by=&after=&limit=&category=&stock=&updated_days=&created_days=
     * Returns the decoded body { by, after, count, results } or WP_Error.
     *
     * $stock        'in' | 'out'  — in-stock rows are pulled first (see SPP_Sync)
     * $updatedDays  only rows changed in the last N days (0 = no limit)
     * $createdDays  only rows first seen by the scraper in the last N days
     *
     * An OLDER SERVER SILENTLY IGNORES the last three and returns everything.
     * The server echoes them back, so server_honours_windows() can detect that.
     */
    public static function sync_feed($by, $after, $limit, $category = '', $stock = '', $updatedDays = 0, $createdDays = 0) {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');

        $args = array('by' => $by, 'after' => $after, 'limit' => $limit);
        if ($category !== '')  $args['category']     = $category;
        if ($stock !== '')     $args['stock']        = $stock; // 'in' | 'out'
        if ($updatedDays > 0)  $args['updated_days'] = $updatedDays;
        if ($createdDays > 0)  $args['created_days'] = $createdDays;
        $url = add_query_arg($args, self::server() . '/product/sync-feed');

        $res = wp_remote_get($url, array('timeout' => 30, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);

        if ($code === 401) return new WP_Error('spp_auth', 'Invalid enrollment key.', array('status' => 401));
        if ($code === 403) {
            $msg = is_array($body) && !empty($body['error']) ? $body['error'] : 'Enrollment not active or expired.';
            return new WP_Error('spp_forbidden', $msg, array('status' => 403));
        }
        if ($code !== 200 || !is_array($body)) {
            return new WP_Error('spp_http', 'Server returned HTTP ' . $code . '.');
        }
        return $body;
    }

    /** GET /product/status -> { status, expiry_date, days_left, sources, ... } (works even when expired) */
    public static function status() {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');
        $res = wp_remote_get(self::server() . '/product/status', array('timeout' => 20, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body)) {
            $msg = is_array($body) && !empty($body['error']) ? $body['error'] : ('HTTP ' . $code);
            return new WP_Error('spp_status', $msg);
        }
        return $body;
    }

    /** POST /product/renew-demo -> extends expiry (DEMO). */
    public static function renew_demo() {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');
        $res = wp_remote_post(self::server() . '/product/renew-demo', array('timeout' => 20, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body)) return new WP_Error('spp_renew', 'Renewal failed (HTTP ' . $code . ').');
        return $body;
    }

    /**
     * GET /product/pay-config -> { enabled, provider, title, description, due_invoice }
     * Non-secret. Tells the plugin whether online renewal is on and what it's called.
     * The plugin holds NO gateway keys — switching gateways in the portal needs no
     * plugin update because this endpoint's response is all the plugin ever sees.
     */
    public static function pay_config() {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');
        $res = wp_remote_get(self::server() . '/product/pay-config', array('timeout' => 20, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body)) return new WP_Error('spp_pay', 'HTTP ' . $code);
        return $body;
    }

    /**
     * POST /product/pay-start -> { pay_url, invoice_no, amount, currency }
     * The SERVER builds the pay link with whatever gateway is currently active;
     * the plugin just redirects the browser to pay_url.
     */
    public static function pay_start() {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');
        $res = wp_remote_post(self::server() . '/product/pay-start', array('timeout' => 30, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body) || empty($body['pay_url'])) {
            $msg = is_array($body) && !empty($body['error']) ? $body['error'] : ('HTTP ' . $code);
            return new WP_Error('spp_pay_start', $msg);
        }
        return $body;
    }

    /** POST/GET /product/refresh-one -> { status, product } (re-scrapes if stale) */
    public static function refresh_one($productId, $db) {
        $key = self::key();
        if (!$key) return new WP_Error('spp_no_key', 'No enrollment key set.');
        $url = add_query_arg(
            array('productId' => $productId, 'category' => $db),
            self::server() . '/product/refresh-one'
        );
        // returns immediately now (scrape runs in background) — short timeout is fine
        $res = wp_remote_get($url, array('timeout' => 15, 'headers' => self::headers()));
        if (is_wp_error($res)) return $res;
        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if ($code !== 200 || !is_array($body)) return new WP_Error('spp_refresh', 'HTTP ' . $code);
        return $body;
    }

    /** lightweight check: pull a single row. true on success, WP_Error on failure. */
    public static function check() {
        $r = self::sync_feed('id', 0, 1);
        return is_wp_error($r) ? $r : true;
    }
}
