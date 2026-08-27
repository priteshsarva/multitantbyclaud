<?php
if (!defined('ABSPATH')) exit;

/**
 * Live on-view refresh. On a single product page the page renders instantly with
 * current data; in the background the browser asks the plugin to re-check that
 * product. If the server re-scrapes it and the price/stock actually changed, the
 * page reloads once to show the fresh data. Fresh/unchanged products never reload.
 */
class SPP_Live {

    public static function init() {
        add_action('wp_ajax_spp_refresh',        array(__CLASS__, 'ajax_refresh'));
        add_action('wp_ajax_nopriv_spp_refresh', array(__CLASS__, 'ajax_refresh'));
        add_action('wp_footer',                  array(__CLASS__, 'footer_script'));
    }

    // print the tiny client script on single product pages for managed products
    public static function footer_script() {
        if (!function_exists('is_product') || !is_product()) return;
        $pid = get_the_ID();
        if (!$pid || !SPP_Product::is_managed($pid)) return;

        $ajax  = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('spp_refresh_' . $pid);
        ?>
        <script>
        (function () {
          var PID = <?php echo (int) $pid; ?>;
          var AJAX = <?php echo wp_json_encode($ajax); ?>;
          var NONCE = <?php echo wp_json_encode($nonce); ?>;
          var mark = 'spp_reloaded_' + PID;
          try { if (sessionStorage.getItem(mark)) return; } catch (e) {}

          var tries = 0, MAX = 10; // poll up to ~30s, then give up (sync will catch it)
          function paintPrice(html) {
            if (!html) return false;
            var el = document.querySelector(
              '.single-product .product-main .price, .single-product .entry-summary .price, ' +
              '.single-product .summary .price, .single-product div.product > .price'
            );
            if (!el) return false;
            el.innerHTML = html;
            return true;
          }
          function poll() {
            var body = new URLSearchParams();
            body.set('action', 'spp_refresh');
            body.set('product_id', PID);
            body.set('nonce', NONCE);
            fetch(AJAX, {
              method: 'POST',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: body.toString(),
              credentials: 'same-origin'
            })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (!d) return;
                if (d.done) {
                  if (d.stock_changed) {
                    // in/out of stock changes the add-to-cart area — safest to reload
                    try { sessionStorage.setItem(mark, '1'); } catch (e) {}
                    location.reload();
                  } else if (d.price_changed) {
                    // show the fresh price instantly, no reload; fall back to reload if not found
                    if (!paintPrice(d.price_html)) {
                      try { sessionStorage.setItem(mark, '1'); } catch (e) {}
                      location.reload();
                    }
                  }
                  return; // done
                }
                if (++tries < MAX) setTimeout(poll, 3000); // still refreshing -> check again
              })
              .catch(function () {});
          }
          poll();
        })();
        </script>
        <?php
    }

    // AJAX: 'refreshing' -> keep polling. Once fresh, send the price to the browser
    // to paint IN PLACE, then write to WooCommerce in the background (after responding).
    public static function ajax_refresh() {
        $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if (!$pid) wp_send_json(array('done' => true));
        if (!check_ajax_referer('spp_refresh_' . $pid, 'nonce', false)) wp_send_json(array('done' => true));
        if (!SPP_Product::is_managed($pid)) wp_send_json(array('done' => true));

        $sourceId = get_post_meta($pid, SPP_SOURCE_ID, true);
        $db       = get_post_meta($pid, SPP_SOURCE_DB, true);
        if (!$sourceId || !$db) wp_send_json(array('done' => true));

        $resp = SPP_API::refresh_one($sourceId, $db);
        if (is_wp_error($resp) || empty($resp['product'])) wp_send_json(array('done' => true));

        // scrape still running in the background -> poll again
        if (isset($resp['status']) && $resp['status'] === 'refreshing') {
            wp_send_json(array('done' => false));
        }

        // fresh data available — work out the display values
        $product   = $resp['product'];
        $orig      = isset($product['productOriginalPrice']) ? $product['productOriginalPrice'] : null;
        $new_price = ($orig !== null && $orig !== '') ? SPP_Margin::final_price($orig) : null;
        $new_stock = self::stock_from($product);

        $before_price = (string) get_post_meta($pid, '_price', true);
        $before_stock = (string) get_post_meta($pid, '_stock_status', true);

        $out = array(
            'done'          => true,
            'price_changed' => ($new_price !== null && (string) $new_price !== $before_price),
            'stock_changed' => ($new_stock !== null && $new_stock !== $before_stock),
            'price_html'    => ($new_price !== null && function_exists('wc_price')) ? wc_price($new_price) : '',
        );

        // 1) send the result to the browser NOW so it can paint in place
        echo wp_json_encode($out);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 2) persist to WooCommerce in the background (after the response is flushed)
        SPP_Product::upsert($product);
        exit;
    }

    // normalize the source availability into a WooCommerce stock status
    private static function stock_from($product) {
        if (!isset($product['availability'])) return null;
        $s = strtolower((string) $product['availability']);
        $in = in_array($s, array('1', 'true', 'instock', 'in_stock', 'yes'), true);
        return $in ? 'instock' : 'outofstock';
    }
}
