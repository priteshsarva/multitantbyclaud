<?php
if (!defined('ABSPATH')) exit;

/**
 * COD / Prepaid checkout module. OFF by default (enable in settings).
 *
 * FEATURES (each individually configurable):
 *   1. COD vs UPI(prepaid) chooser above payment methods, with a configurable
 *      DEFAULT selection (none / cod / prepaid).
 *   2. PREPAID DISCOUNT: optional negative fee on full online payment
 *      (toggle + amount + label).
 *   3. COD EXTRA CHARGE: optional positive fee on COD orders
 *      (amount + label; e.g. order 5000 + 200 = 5200 payable at delivery).
 *   4. COD ADVANCE: optional "pay X online now, rest at delivery" (fixed or %).
 *      Blank/0 = plain 100% COD. The collection engine is BUILT IN — see
 *      class-spp-partial.php, which owns everything partial-payment related
 *      (charging only the advance, restoring the total, Partial Paid status,
 *      all Paid/Remaining displays, coupons, and the admin payments list).
 *      It stands down automatically if old WooBooster is still active.
 *   5. Clear order totals: on partial-COD orders the thank-you page, emails,
 *      admin and My Account show "Advance Paid Online" + "To Pay at Delivery"
 *      rows, and the stray discount row is removed. (This is the fix for the
 *      old "remaining amount is not clear" bug.)
 *
 * Gateway ids are configurable because UPI plugins differ
 * (default: cod / upi-payment).
 *
 * MONEY LOGIC — always test one COD and one prepaid order on staging first.
 */
class SPP_Checkout {

    // ---------- settings ----------
    public static function enabled()        { return get_option('spp_checkout_enabled', 'no') === 'yes'; }
    public static function default_choice() {
        $c = get_option('spp_checkout_default', 'none');
        return in_array($c, array('none', 'cod', 'prepaid'), true) ? $c : 'none';
    }
    public static function discount_on()  { return get_option('spp_prepaid_discount_on', 'yes') === 'yes'; }
    public static function discount()     { return max(0, (float) get_option('spp_checkout_discount', 199)); }
    public static function label()        { return (string) get_option('spp_checkout_label', 'Online Payment Discount'); }
    public static function cod_fee()      { return max(0, (float) get_option('spp_cod_fee_amount', 0)); }
    public static function cod_fee_label(){ return (string) get_option('spp_cod_fee_label', 'COD Charges'); }
    public static function cod_advance()  { return max(0, (float) get_option('spp_cod_advance', 0)); }
    public static function cod_gateway()  { return sanitize_html_class(get_option('spp_cod_gateway_id', 'cod')); }
    public static function upi_gateway()  { return sanitize_html_class(get_option('spp_upi_gateway_id', 'upi-payment')); }

    /** the partial engine is BUILT IN now (SPP_Partial). It only stands down
     *  while the old WooBooster plugin is still active, to avoid double-charging. */
    public static function has_partial_engine() { return !SPP_Partial::conflict(); }
    /** advance collection is live when an amount is set and no conflict exists */
    public static function advance_active() { return self::cod_advance() > 0 && self::has_partial_engine(); }

    public static function init() {
        if (!self::enabled()) return;
        add_action('woocommerce_review_order_before_payment', array(__CLASS__, 'render_choice'), 9);
        add_action('woocommerce_before_checkout_form',        array(__CLASS__, 'choice_js'));
        add_action('woocommerce_checkout_create_order',       array(__CLASS__, 'persist'), 10, 2);
        add_action('woocommerce_cart_calculate_fees',         array(__CLASS__, 'apply_fees'), 20, 1);
        add_action('wp_ajax_spp_save_cod',                    array(__CLASS__, 'save_session'));
        add_action('wp_ajax_nopriv_spp_save_cod',             array(__CLASS__, 'save_session'));
        add_action('woocommerce_thankyou',                    array(__CLASS__, 'clear_session'));
        add_filter('woocommerce_get_order_item_totals',       array(__CLASS__, 'tidy_totals'), 9999, 2);
        add_filter('woocommerce_order_get_payment_method_title', array(__CLASS__, 'rename_method'), 10, 2);
    }

    // ---------- the chooser ----------
    public static function render_choice() {
        $d       = self::discount();
        $dOn     = self::discount_on() && $d > 0;
        $fee     = self::cod_fee();
        $advance = self::advance_active() ? self::cod_advance() : 0;
        // This block sits INSIDE #order_review, which WooCommerce replaces wholesale
        // on every update_order_review — so the radios come back blank after each
        // recalculation. Stamp the current selection server-side or the shopper
        // watches their own tick disappear every time the totals refresh.
        $sel     = self::current_choice();
        ?>
        <div id="spp-pay-choice" style="margin:12px 0;padding:12px;border:1px solid #e4e4e4;border-radius:8px;background:#fafafa;">
          <div style="font-weight:600;margin-bottom:8px;">Choose payment option</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <label style="display:flex;flex-direction:column;align-items:flex-start;padding:8px 10px;border:1px solid #ddd;border-radius:6px;cursor:pointer;background:#fff;">
              <span style="display:flex;align-items:center;gap:8px;">
                <input type="radio" name="spp_pay_choice" id="spp_choice_cod" value="cod" <?php checked($sel, 'cod'); ?>><span style="font-weight:600;">Cash On Delivery</span>
              </span>
              <?php if ($advance > 0): ?>
                <span style="font-size:12px;color:#92400e;background:#fef3c7;padding:3px 8px;border-radius:999px;margin-top:4px;">
                  Pay ₹<?php echo esc_html($advance); ?> now to confirm — rest at delivery</span>
              <?php endif; ?>
              <?php if ($fee > 0): ?>
                <span style="font-size:12px;color:#7c2d12;margin-top:4px;">+ ₹<?php echo esc_html($fee); ?> <?php echo esc_html(self::cod_fee_label()); ?></span>
              <?php endif; ?>
            </label>
            <label style="display:flex;flex-direction:column;align-items:flex-start;padding:8px 10px;border:1px solid #0f766e;border-radius:6px;cursor:pointer;background:#ecfdf5;">
              <?php if ($dOn): ?>
              <span style="font-size:12px;font-weight:600;color:#065f46;background:#bbf7d0;padding:4px 8px;border-radius:999px;margin-bottom:4px;">
                Get a ₹<?php echo esc_html($d); ?> discount on full prepaid orders</span>
              <?php endif; ?>
              <span style="display:flex;align-items:center;gap:8px;">
                <input type="radio" name="spp_pay_choice" id="spp_choice_prepaid" value="prepaid" <?php checked($sel, 'prepaid'); ?>>
                <span style="font-weight:600;color:#065f46;">UPI Payment - Gpay, Paytm, PhonePe, etc</span>
              </span>
            </label>
          </div>
        </div>
        <?php
    }

    // ---------- checkout JS ----------
    // Loop-safe rules:
    //  * The default is applied at most ONCE PER PAGE LOAD (plain var, not
    //    sessionStorage — that persisted across visits and killed the default).
    //  * State changes fire real 'change' events so WooCommerce switches the
    //    gateway itself; WC then runs its own single checkout update.
    //  * The 'updated_checkout' handler only MIRRORS state. It never applies,
    //    never triggers — that one-way rule is what prevents the refresh loop.
    public static function choice_js() {
        if (!function_exists('wc_enqueue_js')) return;
        $default = self::default_choice();
        $codId   = self::cod_gateway();
        $upiId   = self::upi_gateway();
        $useAdv  = self::advance_active() ? '1' : '0';
        wc_enqueue_js("
        (function(\$){
          var DEF='" . esc_js($default) . "', COD='#payment_method_" . esc_js($codId) . "', UPI='#payment_method_" . esc_js($upiId) . "', ADV=" . $useAdv . ";
          var applied=false, last='';
          function save(c){ return \$.post(wc_checkout_params.ajax_url,{action:'spp_save_cod',choice:c}); }
          function reflect(c){ \$('#spp_choice_cod').prop('checked',c==='cod'); \$('#spp_choice_prepaid').prop('checked',c==='prepaid'); }
          function infer(){
            // With an advance, both choices sit on the online gateway, so the
            // gateway radios can't tell us — trust the last explicit choice.
            // Return '' rather than guessing: showing a selection the server was
            // never told about is what makes the totals look wrong on reload.
            if(ADV===1){ var r=\$('input[name=\\\"spp_pay_choice\\\"]:checked'); return r.length?r.val():last; }
            if(\$(COD).length && \$(COD).is(':checked')) return 'cod';
            if(\$(UPI).length && \$(UPI).is(':checked')) return 'prepaid';
            return last;
          }
          function setState(c){
            // With an advance configured (ADV=1), even the COD choice pays its
            // advance ONLINE — so the online gateway is selected for both paths
            // (the plain COD gateway is hidden by SPP_Partial in that mode).
            // Record the choice BEFORE anything can start a recalculation, so the
            // session can never describe the PREVIOUS selection.
            var \$g=\$((c==='cod' && ADV!==1)?COD:UPI);
            save(c).always(function(){
              if(\$g.length && !\$g.is(':checked')){ \$g.prop('checked',true).trigger('change'); }
              else { \$(document.body).trigger('update_checkout'); }
            });
          }
          function choose(c){ last=c; reflect(c); setState(c); }
          \$(document).on('change','input[name=\\\"spp_pay_choice\\\"]',function(){ choose(\$(this).val()); });
          \$(function(){
            if(DEF!=='none' && !applied){ applied=true; choose(DEF); }
            else { reflect(infer()); }
          });
          \$(document.body).on('updated_checkout',function(){ reflect(infer()); });
        })(jQuery);
        ");
    }

    // ---------- session + fees ----------
    public static function save_session() {
        if (!WC()->session) { echo 'no-session'; wp_die(); }
        $c = isset($_POST['choice']) ? sanitize_text_field(wp_unslash($_POST['choice'])) : '';
        // back-compat with the old cod_selected=0/1 payload
        if ($c === '' && isset($_POST['cod_selected'])) $c = $_POST['cod_selected'] === '1' ? 'cod' : 'prepaid';
        if (in_array($c, array('cod', 'prepaid'), true)) WC()->session->set('spp_pay_choice', $c);
        echo 'ok'; wp_die();
    }
    public static function clear_session() {
        if (WC()->session) WC()->session->set('spp_pay_choice', '');
    }

    /** what the shopper has currently chosen ('' if unknown) */
    private static function current_choice() {
        // ORDER MATTERS. The chooser radios live INSIDE <form class="checkout">,
        // so WooCommerce serialises them into post_data on every recalculation.
        // That is the only signal guaranteed to describe the CURRENT click.
        //
        // Reading the session first (as this did until 4.1.1) inverted the totals:
        // choose() fires the gateway 'change' event — which starts
        // update_order_review immediately — before the spp_save_cod AJAX has
        // landed, so the session still held the PREVIOUS choice. Pick prepaid,
        // get the COD split; pick COD, get the prepaid discount.
        if (isset($_POST['spp_pay_choice'])) { // final place-order submit
            $c = sanitize_text_field(wp_unslash($_POST['spp_pay_choice']));
            if (in_array($c, array('cod', 'prepaid'), true)) return $c;
        }
        if (isset($_POST['post_data'])) {      // during update_order_review
            parse_str(wp_unslash($_POST['post_data']), $pd);
            if (!empty($pd['spp_pay_choice'])
                && in_array($pd['spp_pay_choice'], array('cod', 'prepaid'), true)) {
                return $pd['spp_pay_choice'];
            }
            // gateway fallback, for the plain (no-advance) setup where the two
            // choices really do sit on different gateways
            if (!empty($pd['payment_method'])) {
                if ($pd['payment_method'] === self::cod_gateway()) return 'cod';
                if ($pd['payment_method'] === self::upi_gateway()) return 'prepaid';
            }
        }
        if (isset($_POST['payment_method'])) {
            $pm = sanitize_text_field(wp_unslash($_POST['payment_method']));
            if ($pm === self::cod_gateway()) return 'cod';
            if ($pm === self::upi_gateway()) return 'prepaid';
        }
        // Session LAST: nothing was posted, so this is a fresh page load and the
        // session is the only memory of what the shopper picked earlier.
        if (WC()->session) {
            $sc = WC()->session->get('spp_pay_choice');
            if (in_array($sc, array('cod', 'prepaid'), true)) return $sc;
        }
        return '';
    }

    public static function apply_fees($cart) {
        if ((is_admin() && !defined('DOING_AJAX')) || !is_checkout()) return;
        $c = self::current_choice();
        if ($c === 'prepaid' && self::discount_on() && self::discount() > 0) {
            $cart->add_fee(self::label(), -self::discount(), false);
        } elseif ($c === 'cod' && self::cod_fee() > 0) {
            $cart->add_fee(self::cod_fee_label(), self::cod_fee(), false);
        }
        // c === '' -> no default chosen yet and nothing selected: no fee either way
    }

    // ---------- order meta ----------
    public static function persist($order, $data) {
        $choice = self::current_choice();
        if ($choice !== '') $order->update_meta_data('_spp_pay_choice', $choice);
        // The exact split amounts are stamped by SPP_Partial::stamp_order (runs
        // right after this at priority 25) — it owns _is_partial_cod and
        // _partial_paid_amount so there is exactly ONE source of truth.
        if (!($choice === 'cod' && self::advance_active())) {
            $order->update_meta_data('_is_partial_cod', 'no');
            $order->delete_meta_data('_partial_paid_amount');
        }
    }

    // ---------- clear, honest order totals ----------
    public static function tidy_totals($rows, $order) {
        if (!$order instanceof WC_Order) return $rows;
        $partial = $order->get_meta('_is_partial_cod') === 'yes'
            || stripos((string) $order->get_payment_method_title(), 'Partial Cash on Delivery') !== false;
        if (!$partial) return $rows;

        // 1) drop the stray discount row that used to confuse partial-COD orders
        $needle = strtolower(trim(self::label()));
        foreach ($rows as $k => $row) {
            $label = isset($row['label']) ? strtolower(trim(rtrim(wp_strip_all_tags($row['label']), ':'))) : '';
            if ($label === $needle || strpos($label, 'online payment') !== false) unset($rows[$k]);
            if ($k === 'payment_method') $rows[$k]['value'] = esc_html__('Partial Cash on Delivery', 'woocommerce');
        }

        // 2) make the split unmissable: paid now vs due at delivery
        $paid = (float) $order->get_meta('_partial_paid_amount');
        if ($paid > 0) {
            $due = max(0, (float) $order->get_total() - $paid);
            $extra = array(
                'spp_advance_paid' => array(
                    'label' => __('Advance Paid Online:', 'woocommerce'),
                    'value' => wc_price($paid, array('currency' => $order->get_currency())),
                ),
                'spp_due_delivery' => array(
                    'label' => '<strong>' . __('To Pay at Delivery:', 'woocommerce') . '</strong>',
                    'value' => '<strong>' . wc_price($due, array('currency' => $order->get_currency())) . '</strong>',
                ),
            );
            // insert just before the final total row so the maths reads naturally
            $out = array();
            foreach ($rows as $k => $row) {
                if ($k === 'order_total') { foreach ($extra as $ek => $ev) $out[$ek] = $ev; }
                $out[$k] = $row;
            }
            if (!isset($out['spp_due_delivery'])) $out = array_merge($rows, $extra); // no order_total key? append
            return $out;
        }
        return $rows;
    }

    public static function rename_method($title, $order) {
        if (function_exists('is_order_received_page') && is_order_received_page()
            && $order instanceof WC_Order && $order->get_meta('_is_partial_cod') === 'yes') {
            return __('Partial Cash on Delivery', 'woocommerce');
        }
        return $title;
    }
}
