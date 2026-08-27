<?php
if (!defined('ABSPATH')) exit;

/**
 * Health + diagnostics. Everything the store owner needs to answer
 * "is this actually working?" without guessing:
 *   - live connection test to the server
 *   - is WP-Cron firing, and when did the heartbeat last run
 *   - what the sync engine is doing right now (per-category cursors)
 *   - a one-click self-test that pinpoints the first broken link
 */
class SPP_Diag {

    /** run a full self-test; returns an ordered list of check results */
    public static function selftest() {
        $out = array();

        // 1. key present
        $key = SPP_API::key();
        $out[] = self::row('Enrollment key', $key !== '',
            $key !== '' ? 'Set (' . substr($key, 0, 12) . '…)' : 'No key entered — paste your key and save.');
        if ($key === '') return $out; // nothing else can pass without a key

        // 2. server reachable + key valid (pull 1 row, no category)
        $probe = SPP_API::sync_feed('id', 0, 1);
        if (is_wp_error($probe)) {
            $out[] = self::row('Server connection', false,
                $probe->get_error_message() . self::hint($probe));
            return $out; // can't test anything downstream if the server is unreachable
        }
        $sample = isset($probe['results']) && is_array($probe['results']) ? count($probe['results']) : 0;
        $out[] = self::row('Server connection', true,
            'Connected. Test pull returned ' . $sample . ' row' . ($sample === 1 ? '' : 's') . '.');

        // 3. status / expiry
        $st = SPP_API::status();
        if (is_wp_error($st)) {
            $out[] = self::row('Subscription status', false, $st->get_error_message());
        } else {
            $rs   = isset($st['status']) ? $st['status'] : '?';
            $days = isset($st['days_left']) ? $st['days_left'] : null;
            $ok   = ($rs === 'active');
            $msg  = 'Status: ' . $rs . ($days !== null ? ' — ' . intval($days) . ' day(s) left.' : '.');
            $out[] = self::row('Subscription status', $ok, $msg);

            // 4. sources / categories the store is entitled to
            $srcs = isset($st['sources']) && is_array($st['sources']) ? $st['sources'] : array();
            $cats = array();
            foreach ($srcs as $s) if (!empty($s['category'])) $cats[$s['category']] = 1;
            $out[] = self::row('Sources & categories', !empty($srcs),
                !empty($srcs)
                    ? count($srcs) . ' source(s) across categories: ' . implode(', ', array_keys($cats))
                    : 'No sources returned. The server has not linked any suppliers to this key yet.');
        }

        // 5. cron alive?
        $out[] = self::cron_row();

        // 6. products present
        $count = self::managed_count();
        $out[] = self::row('Products imported', $count > 0,
            $count > 0 ? number_format($count) . ' managed product(s) in the store.'
                       : 'No products yet. Run "Sync now" — the first backfill can take several minutes.');

        // 7. pricing sanity — is at least one rule set, and do quote products have a WhatsApp number?
        $out[] = self::pricing_row();

        return $out;
    }

    private static function cron_row() {
        $next = wp_next_scheduled('spp_cron_sync');
        $st   = get_option(SPP_OPT_STATUS, array());
        $last = isset($st['heartbeat_at']) ? $st['heartbeat_at'] : '';
        $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;

        if (!$next) {
            return self::row('Background updates (cron)', false,
                'Heartbeat is NOT scheduled. Deactivate and reactivate the plugin to restore it.');
        }
        $when = human_time_diff(time(), $next);
        $msg  = 'Next run in ' . $when . '.';
        if ($last) $msg .= ' Last heartbeat: ' . self::ago($last) . '.';
        if ($disabled) {
            $msg .= ' NOTE: DISABLE_WP_CRON is on — real cron must be wired to wp-cron.php, or updates only run on page visits.';
            return self::row('Background updates (cron)', false, $msg);
        }
        // if it never ran but is scheduled, that's a soft warning
        $ok = $last !== '';
        if (!$ok) $msg .= ' It is scheduled but has not fired yet — visit the site front-end once to prime WP-Cron.';
        return self::row('Background updates (cron)', $ok, $msg);
    }

    private static function pricing_row() {
        $tiers   = SPP_Margin::tiers();
        $hasRule = is_array($tiers) && !empty($tiers);
        $err     = $hasRule ? SPP_Margin::validate($tiers) : 'No price rules configured.';
        if ($err !== '') return self::row('Price rules', false, $err);

        // any quote band present?
        $hasQuote = false;
        foreach ($tiers as $t) if ($t['margin'] === '' || $t['margin'] === null) { $hasQuote = true; break; }
        foreach (SPP_Margin::category_rules() as $rules) {
            foreach ((array) $rules as $t) if (($t['margin'] ?? '') === '') { $hasQuote = true; break 2; }
        }
        if ($hasQuote) {
            $wa = preg_replace('/[^0-9]/', '', (string) get_option('spp_quote_whatsapp', ''));
            if ($wa === '') {
                return self::row('Price rules', false,
                    'A "quote" band is set (blank margin) but no WhatsApp number is configured, so the Request Quote button cannot appear. Add a number under Request Quote settings.');
            }
            return self::row('Price rules', true, 'Valid. Quote band active, WhatsApp number set (' . $wa . ').');
        }
        return self::row('Price rules', true, 'Valid. ' . count($tiers) . ' band(s), no quote band.');
    }

    public static function managed_count() {
        $c = wp_count_posts('product');
        // count via meta is more accurate; do a cheap query
        $q = new WP_Query(array(
            'post_type' => 'product', 'post_status' => 'any',
            'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => false,
            'meta_query' => array(array('key' => SPP_MANAGED, 'value' => '1')),
        ));
        return (int) $q->found_posts;
    }

    private static function row($label, $ok, $detail) {
        return array('label' => $label, 'ok' => (bool) $ok, 'detail' => $detail);
    }

    private static function hint($err) {
        $code = is_wp_error($err) ? $err->get_error_code() : '';
        if ($code === 'spp_auth')      return ' → the key is wrong or was revoked.';
        if ($code === 'spp_forbidden') return ' → the subscription is inactive/expired, or the domain does not match.';
        if ($code === 'spp_http')      return ' → the server is up but returned an error; try again shortly.';
        if (strpos((string) $err->get_error_message(), 'cURL') !== false
            || strpos((string) $err->get_error_message(), 'timed out') !== false) {
            return ' → the store could not reach the server (firewall / server down / wrong URL).';
        }
        return '';
    }

    public static function ago($mysql) {
        $t = strtotime($mysql);
        if (!$t) return $mysql;
        return human_time_diff($t, time()) . ' ago';
    }
}
