<?php
if (!defined('ABSPATH')) exit;

/**
 * PARTIAL COD ENGINE — full replacement for "WooBooster Partial COD".
 * One file owns everything partial-payment related, so future changes to the
 * advance/partial behaviour only ever touch THIS file.
 *
 * How the money actually moves (ported 1:1 from WooBooster):
 *   1. Shopper picks COD in the SPP chooser while an advance is configured.
 *   2. At review, the advance (fixed ₹ or %) and the remaining COD amount are
 *      computed, shown under the total, and stashed in the session.
 *   3. On order creation the FULL total + split amounts are stamped as meta.
 *   4. After checkout processing the order total is temporarily set to the
 *      ADVANCE — that is what the online gateway charges.
 *   5. Once paid (pre_payment_complete + thank-you fallback) the full total is
 *      restored, status becomes "partial-paid", and an order note records the
 *      split. Emails/admin/thank-you all show Paid vs Remaining rows.
 *
 * Meta keys are kept IDENTICAL to WooBooster so historical orders placed under
 * the old plugin keep rendering everywhere after it is deactivated:
 *   _partial_payment            yes/no
 *   _original_order_total       full total while the swap is in flight
 *   partial_cod_total_amount    full order total
 *   partial_cod_paid_amount     advance paid online
 *   partial_cod_pending_amount  to collect at delivery
 *
 * SAFETY: if WooBooster is still active, this engine goes dormant and shows an
 * admin notice — two engines double-charging fees is worse than one old one.
 */
class SPP_Partial {

    // ---------- state ----------

    /** WooBooster still running? then we stand down. */
    public static function conflict() {
        if (!function_exists('is_plugin_active')) include_once ABSPATH . 'wp-admin/includes/plugin.php';
        return is_plugin_active('wb-partial-cod-for-woocommerce/woobooster-partial-cod.php')
            || is_plugin_active('wb-partial-cod-for-woocommerce/wb-partial-cod-for-woocommerce.php')
            || function_exists('woobooster_partial_cod_display_checkbox');
    }

    public static function advance()      { return max(0, (float) get_option('spp_cod_advance', 0)); }
    public static function advance_type() { return get_option('spp_cod_advance_type', 'fixed') === 'percentage' ? 'percentage' : 'fixed'; }

    /** the engine runs only when checkout module is on, an advance is set, and WooBooster is gone */
    public static function active() {
        return SPP_Checkout::enabled() && self::advance() > 0 && !self::conflict();
    }

    /** advance for a given order total (handles fixed and percentage) */
    public static function advance_for($total) {
        $a = self::advance();
        if (self::advance_type() === 'percentage') $a = $total * ($a / 100);
        return round(min($a, $total), wc_get_price_decimals());
    }

    /** is the current checkout session going down the partial path? */
    private static function session_is_partial() {
        return self::active() && WC()->session && WC()->session->get('spp_pay_choice') === 'cod';
    }

    public static function init() {
        // Always: the custom order status must exist so historical partial-paid
        // orders keep their label even when the engine itself is dormant.
        add_filter('woocommerce_register_shop_order_post_statuses', array(__CLASS__, 'register_status'));
        add_filter('wc_order_statuses',                             array(__CLASS__, 'add_status'));

        // Always: display of EXISTING partial orders (thank-you, emails, admin)
        // must keep working even if the engine is off/conflicted.
        add_action('woocommerce_order_details_after_order_table', array(__CLASS__, 'thankyou_table'));
        add_action('woocommerce_email_after_order_table',         array(__CLASS__, 'email_info'), 10, 4);
        add_action('woocommerce_admin_order_totals_after_total',  array(__CLASS__, 'admin_totals'));
        add_filter('woocommerce_order_get_total',                 array(__CLASS__, 'email_total'), 10, 2);
        add_action('admin_menu',                                  array(__CLASS__, 'admin_menu'));

        if (self::conflict()) {
            add_action('admin_notices', array(__CLASS__, 'conflict_notice'));
            return; // never run two engines at once
        }
        if (!SPP_Checkout::enabled() || self::advance() <= 0) return;

        // ---- the live engine ----
        add_action('woocommerce_review_order_after_order_total', array(__CLASS__, 'review_rows'));
        add_filter('woocommerce_available_payment_gateways',     array(__CLASS__, 'hide_plain_cod'));
        add_action('woocommerce_checkout_create_order',          array(__CLASS__, 'stamp_order'), 25, 2);
        add_action('woocommerce_checkout_order_processed',       array(__CLASS__, 'charge_advance_only'), 10, 3);
        add_action('woocommerce_pre_payment_complete',           array(__CLASS__, 'restore_total'), 1);
        add_action('woocommerce_thankyou',                       array(__CLASS__, 'finalize'), 10, 1);
        // Make the split part of the ORDER DATA, not just the rendered page, so the
        // WordPress / WooCommerce mobile apps and any other REST consumer can see it.
        add_filter('woocommerce_rest_prepare_shop_order_object',  array(__CLASS__, 'rest_fields'), 10, 3);
        // coupon rules (ported: restrict-for-partial / only-for-partial)
        add_action('woocommerce_coupon_options_usage_restriction', array(__CLASS__, 'coupon_fields'));
        add_action('woocommerce_coupon_options_save',              array(__CLASS__, 'coupon_save'));
        add_filter('woocommerce_coupon_is_valid',                  array(__CLASS__, 'coupon_valid'), 10, 3);
    }

    public static function conflict_notice() {
        echo '<div class="notice notice-warning"><p><strong>Authntic Products:</strong> the built-in Partial COD engine is paused because '
           . '<em>WooBooster Partial COD</em> is still active. Deactivate WooBooster to let Authntic Products take over '
           . '(historical orders keep working — the meta keys are compatible).</p></div>';
    }

    // ---------- order status ----------
    public static function register_status($statuses) {
        $statuses['wc-partial-paid'] = array(
            'label'                     => _x('Partial Paid', 'Order status', 'woocommerce'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Partial Paid (%s)', 'Partial Paid (%s)', 'woocommerce'),
        );
        return $statuses;
    }
    public static function add_status($statuses) {
        $statuses['wc-partial-paid'] = _x('Partial Paid', 'Order status', 'woocommerce');
        return $statuses;
    }

    // ---------- checkout: compute + show + stash the split ----------
    public static function review_rows() {
        if (!self::session_is_partial()) return;
        $cart = WC()->cart;
        if (!$cart) return;

        $fees = 0;
        foreach ($cart->get_fees() as $f) $fees += $f->amount; // includes SPP COD charge
        $total = ($cart->get_subtotal('edit') + $fees + $cart->get_shipping_total() + $cart->get_taxes_total())
               - $cart->get_discount_total();
        $total = (float) preg_replace('/[^.\d]/', '', (string) $total);

        $paid = self::advance_for($total);
        $due  = max(0, $total - $paid);

        if ($paid <= 0 || $due <= 0) {
            // advance >= total: partial makes no sense — behave as normal order
            WC()->session->__unset('spp_partial_total');
            WC()->session->__unset('spp_partial_paid');
            WC()->session->__unset('spp_partial_due');
            return;
        }

        WC()->session->set('spp_partial_total', $total);
        WC()->session->set('spp_partial_paid',  $paid);
        WC()->session->set('spp_partial_due',   $due);

        echo '<tr class="spp-partial-row" style="background:#f6f6f6;"><th>' . esc_html__('Pay Now (Online)', 'woocommerce')
           . '</th><td>' . wc_price($paid) . '</td></tr>';
        echo '<tr class="spp-partial-row" style="background:#f6f6f6;"><th>' . esc_html__('To Pay at Delivery', 'woocommerce')
           . '</th><td>' . wc_price($due) . '</td></tr>';
    }

    /** the plain COD gateway makes no sense when the advance must be paid online */
    public static function hide_plain_cod($gateways) {
        if (self::session_is_partial()) unset($gateways[SPP_Checkout::cod_gateway()]);
        return $gateways;
    }

    // ---------- order lifecycle ----------
    public static function stamp_order($order, $data) {
        if (!self::session_is_partial()) { $order->update_meta_data('_partial_payment', 'no'); return; }
        $total = (float) WC()->session->get('spp_partial_total');
        $paid  = (float) WC()->session->get('spp_partial_paid');
        $due   = (float) WC()->session->get('spp_partial_due');
        if ($paid <= 0 || $due <= 0) { $order->update_meta_data('_partial_payment', 'no'); return; }

        $order->update_meta_data('_partial_payment', 'yes');
        $order->update_meta_data('partial_amount',        self::advance());
        $order->update_meta_data('partial_amount_type',   self::advance_type());
        $order->update_meta_data('partial_cod_total_amount',   wc_format_decimal($total));
        $order->update_meta_data('partial_cod_paid_amount',    wc_format_decimal($paid));
        $order->update_meta_data('partial_cod_pending_amount', wc_format_decimal($due));
        // mirror to the SPP keys SPP_Checkout::tidy_totals reads
        $order->update_meta_data('_is_partial_cod', 'yes');
        $order->update_meta_data('_partial_paid_amount', wc_format_decimal($paid));

        WC()->session->__unset('spp_partial_total');
        WC()->session->__unset('spp_partial_paid');
        WC()->session->__unset('spp_partial_due');
    }

    /** THE MONEY TRICK: gateway charges get_total() — shrink it to the advance */
    public static function charge_advance_only($order_id, $posted, $order) {
        if (!$order || $order->get_meta('_partial_payment') !== 'yes') return;
        $paid = (float) $order->get_meta('partial_cod_paid_amount');
        if ($paid <= 0) return;
        $order->update_meta_data('_original_order_total', $order->get_total());
        $order->set_total($paid);
        $order->save();
    }

    /** put the full total back BEFORE payment-complete emails fire */
    public static function restore_total($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_partial_payment') !== 'yes') return;
        $orig = (float) $order->get_meta('_original_order_total');
        if ($orig > 0 && (float) $order->get_total() !== $orig) {
            $order->set_total($orig);
            $order->save();
        }
    }

    /** thank-you fallback (gateways that never call payment_complete) + status */
    public static function finalize($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_partial_payment') !== 'yes') return;

        self::restore_total($order_id);
        $order = wc_get_order($order_id); // fresh after save

        if ($order->get_status() === 'processing') {
            $order->update_status('partial-paid', __('Advance received — order moved to Partial Paid.', 'woocommerce'));
        }
        // The note is written regardless of status. It used to be tied to
        // 'processing', so an order left on-hold or completed by the gateway got
        // no record of the split at all.
        self::note_split($order);
    }

    /**
     * Write the paid/remaining split into an ORDER NOTE, exactly once.
     *
     * Every other place the split appears — thank-you page, emails, admin order
     * screen — is a display hook, and display hooks do not run for the REST API.
     * The WordPress and WooCommerce mobile apps read orders over REST, so they
     * show the restored full total with nothing to say an advance was already
     * paid. An order note is plain order data, so it travels everywhere the
     * order does, including the apps.
     */
    public static function note_split($order) {
        if (!$order instanceof WC_Order) return;
        if ($order->get_meta('_spp_partial_noted') === 'yes') return;   // once only
        $paid = (float) $order->get_meta('partial_cod_paid_amount');
        $due  = (float) $order->get_meta('partial_cod_pending_amount');
        if ($paid <= 0) return;

        $order->add_order_note(sprintf(
            'Partial COD: %s paid online in advance. %s still to collect on delivery. (Order total shown is the full amount.)',
            wp_strip_all_tags(wc_price($paid)), wp_strip_all_tags(wc_price($due))
        ));
        $order->update_meta_data('_spp_partial_noted', 'yes');
        $order->save();
    }

    /**
     * Add an explicit partial-payment block to the order's REST representation.
     * `total` deliberately stays the full amount (that is what is owed in total);
     * these fields say how much of it has already been collected.
     */
    public static function rest_fields($response, $order, $request) {
        if (!$order instanceof WC_Order) return $response;
        if ($order->get_meta('_partial_payment') !== 'yes') return $response;
        $data = $response->get_data();
        $data['spp_partial'] = array(
            'is_partial'   => true,
            'total'        => wc_format_decimal($order->get_meta('partial_cod_total_amount')),
            'paid_online'  => wc_format_decimal($order->get_meta('partial_cod_paid_amount')),
            'due_on_delivery' => wc_format_decimal($order->get_meta('partial_cod_pending_amount')),
        );
        $response->set_data($data);
        return $response;
    }

    /** during emails, always report the FULL total, never the shrunk one */
    public static function email_total($total, $order) {
        if (is_admin()) return $total;
        if (did_action('woocommerce_email_header')) {
            $orig = $order->get_meta('_original_order_total');
            if ($orig) return $orig;
        }
        return $total;
    }

    // ---------- display: thank-you / email / admin ----------
    private static function split($order) {
        if (!$order instanceof WC_Order) return null;
        if ($order->get_meta('_partial_payment') !== 'yes') return null;
        $total = (float) $order->get_meta('partial_cod_total_amount');
        $paid  = (float) $order->get_meta('partial_cod_paid_amount');
        $due   = (float) $order->get_meta('partial_cod_pending_amount');
        if ($paid <= 0) return null;
        return array('total' => $total, 'paid' => $paid, 'due' => $due);
    }

    public static function thankyou_table($order) {
        $s = self::split($order);
        if (!$s) return;
        echo '<h2>' . esc_html__('Partial COD Order Information', 'woocommerce') . '</h2>'
           . '<table class="shop_table order_details"><thead><tr>'
           . '<th>' . esc_html__('Total Amount', 'woocommerce') . '</th>'
           . '<th>' . esc_html__('Paid Amount', 'woocommerce') . '</th>'
           . '<th>' . esc_html__('Remaining Amount to Pay', 'woocommerce') . '</th>'
           . '</tr></thead><tbody><tr>'
           . '<th scope="row">' . wc_price($s['total']) . '</th>'
           . '<th scope="row" style="color:green">' . wc_price($s['paid']) . '</th>'
           . '<th scope="row" style="color:red">' . wc_price($s['due']) . '</th>'
           . '</tr></tbody></table>';
    }

    public static function email_info($order, $sent_to_admin, $plain_text, $email) {
        $s = self::split($order);
        if (!$s) return;
        if ($plain_text) {
            echo "\n" . __('Partial COD Information', 'woocommerce') . "\n";
            echo __('Order Total: ', 'woocommerce')   . wp_strip_all_tags(wc_price($s['total'])) . "\n";
            echo __('Paid Online: ', 'woocommerce')   . wp_strip_all_tags(wc_price($s['paid']))  . "\n";
            echo __('To Pay at Delivery: ', 'woocommerce') . wp_strip_all_tags(wc_price($s['due'])) . "\n";
            return;
        }
        echo '<h2>' . esc_html__('Partial COD Information', 'woocommerce') . '</h2>'
           . '<p>' . esc_html__('Order Total Amount: ', 'woocommerce') . wc_price($s['total']) . '</p>'
           . '<p style="color:green">' . esc_html__('Partial Paid Amount: ', 'woocommerce') . wc_price($s['paid']) . '</p>'
           . '<p style="color:red"><strong>' . esc_html__('Remaining Amount to Pay in COD: ', 'woocommerce') . wc_price($s['due']) . '</strong></p>';
    }

    public static function admin_totals($order_id) {
        $s = self::split(wc_get_order($order_id));
        if (!$s) return;
        echo '<tr class="order-total" style="color:green"><th>' . esc_html__('Partial Paid Amount:', 'woocommerce')
           . '</th><td>' . wc_price($s['paid']) . '</td></tr>'
           . '<tr class="order-total" style="color:red"><th>' . esc_html__('Remaining COD Amount:', 'woocommerce')
           . '</th><td>' . wc_price($s['due']) . '</td></tr>';
    }

    // ---------- coupons (ported behaviour) ----------
    public static function coupon_fields($coupon_id) {
        $coupon_id = absint($coupon_id);
        woocommerce_wp_checkbox(array(
            'id' => 'restrict_partial_cod',
            'label' => __('Restrict for Partial COD', 'woocommerce'),
            'description' => __('Prevent this coupon when Partial COD is selected.', 'woocommerce'),
            'value' => get_post_meta($coupon_id, 'restrict_partial_cod', true) === 'yes' ? 'yes' : 'no',
        ));
        woocommerce_wp_checkbox(array(
            'id' => 'only_partial_cod',
            'label' => __('Only for Partial COD', 'woocommerce'),
            'description' => __('Allow this coupon only for Partial COD orders.', 'woocommerce'),
            'value' => get_post_meta($coupon_id, 'only_partial_cod', true) === 'yes' ? 'yes' : 'no',
        ));
    }
    public static function coupon_save($coupon_id) {
        update_post_meta($coupon_id, 'restrict_partial_cod', isset($_POST['restrict_partial_cod']) ? 'yes' : 'no');
        update_post_meta($coupon_id, 'only_partial_cod',     isset($_POST['only_partial_cod']) ? 'yes' : 'no');
    }
    public static function coupon_valid($valid, $coupon, $discounts) {
        if (!$valid) return false;
        $restrict = get_post_meta($coupon->get_id(), 'restrict_partial_cod', true) === 'yes';
        $only     = get_post_meta($coupon->get_id(), 'only_partial_cod', true) === 'yes';
        $partial  = self::session_is_partial();
        if ($partial && $restrict) {
            wc_add_notice(__('This coupon cannot be used with Partial COD and has been removed.', 'woocommerce'), 'error');
            WC()->cart->remove_coupon($coupon->get_code());
            return false;
        }
        if (!$partial && $only) {
            wc_add_notice(__('This coupon can only be used with Partial COD.', 'woocommerce'), 'error');
            WC()->cart->remove_coupon($coupon->get_code());
            return false;
        }
        return $valid;
    }

    // ---------- admin: View Payments list ----------
    public static function admin_menu() {
        add_submenu_page(
            'woocommerce', 'Partial COD Payments', 'Partial COD',
            'manage_woocommerce', 'spp-partial-payments', array(__CLASS__, 'payments_page')
        );
    }
    public static function payments_page() {
        $orders = wc_get_orders(array(
            'limit' => 100, 'orderby' => 'ID', 'order' => 'DESC',
            'meta_key' => '_partial_payment', 'meta_value' => 'yes',
        ));
        echo '<div class="wrap"><h1>Partial Cash On Delivery (COD) Payments</h1>';
        echo '<table class="widefat striped"><thead><tr>'
           . '<th>Order</th><th>Customer</th><th>Date</th><th>Payment</th>'
           . '<th>Total</th><th>Paid Online</th><th>Pending COD</th><th>Status</th>'
           . '</tr></thead><tbody>';
        if (!$orders) echo '<tr><td colspan="8">No partial COD orders yet.</td></tr>';
        foreach ($orders as $o) {
            $s = self::split($o);
            if (!$s) continue;
            $link = admin_url('admin.php?page=wc-orders&action=edit&id=' . $o->get_id());
            echo '<tr>'
               . '<td><a href="' . esc_url($link) . '">#' . (int) $o->get_id() . '</a></td>'
               . '<td>' . esc_html($o->get_billing_first_name() . ' ' . $o->get_billing_last_name()) . '</td>'
               . '<td>' . esc_html($o->get_date_created() ? $o->get_date_created()->format('M j, Y H:i') : '') . '</td>'
               . '<td>' . esc_html($o->get_payment_method_title()) . '</td>'
               . '<td><strong>' . wc_price($s['total']) . '</strong></td>'
               . '<td style="color:green;font-weight:bold">' . wc_price($s['paid']) . '</td>'
               . '<td style="color:red;font-weight:bold">' . wc_price($s['due']) . '</td>'
               . '<td>' . esc_html(wc_get_order_status_name($o->get_status())) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
