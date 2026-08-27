<?php
if (!defined('ABSPATH')) exit;

class SPP_Settings {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_spp_save',   array(__CLASS__, 'save'));
        add_action('admin_post_spp_action', array(__CLASS__, 'action'));
        add_action('wp_ajax_spp_price_preview', array(__CLASS__, 'ajax_price_preview'));
    }

    /** live price preview: applies the SAVED rules to a test price + category */
    public static function ajax_price_preview() {
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();
        check_ajax_referer('spp_preview', 'nonce');
        $price = floatval($_POST['price'] ?? 0);
        $cat   = sanitize_text_field($_POST['cat'] ?? '');
        $rules = SPP_Margin::rules_for($cat);
        $usingCat = ($cat !== '' && isset(SPP_Margin::category_rules()[$cat]) && !empty(SPP_Margin::category_rules()[$cat]));
        $match = SPP_Margin::match_in($rules, $price);
        if ($match === null) {
            wp_send_json_success(array('quote' => false, 'price' => null, 'source' => $usingCat ? 'category' : 'general',
                'note' => 'No band covers ₹' . $price . ' — this price would keep the supplier price unchanged. Check your bands cover it.'));
        }
        $isQuote = ($match['margin'] === '' || $match['margin'] === null);
        $final   = $isQuote ? null : ($price + floatval($match['margin']));
        wp_send_json_success(array(
            'quote'  => $isQuote,
            'price'  => $final,
            'margin' => $isQuote ? null : floatval($match['margin']),
            'source' => $usingCat ? 'category' : 'general',
            'band'   => array('min' => $match['min'], 'max' => $match['max']),
        ));
    }

    public static function menu() {
        add_menu_page(
            'Authntic Products', 'Authntic Products', 'manage_woocommerce',
            'server-products', array(__CLASS__, 'render'), 'dashicons-database-import', 56
        );
    }

    public static function managed_count() {
        $q = new WP_Query(array(
            'post_type' => 'product', 'post_status' => 'any',
            'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => array(array('key' => SPP_MANAGED, 'value' => '1')),
        ));
        return (int) $q->found_posts;
    }

    // distinct categories used by this store's managed products (for per-category rules)
    public static function product_categories() {
        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true, 'fields' => 'names'));
        if (is_wp_error($terms) || empty($terms)) return array();
        sort($terms);
        return $terms;
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) return;

        $key      = get_option(SPP_OPT_KEY, '');
        $tiers    = SPP_Margin::tiers();
        $autosync = get_option(SPP_OPT_AUTOSYNC) === 'yes';
        $removing = get_option(SPP_OPT_REMOVING) === 'yes';
        $status   = get_option(SPP_OPT_STATUS, array());
        $count    = self::managed_count();

        // live status for the banner (cached value also kept by cron)
        $remote = $key ? SPP_API::status() : null;
        if (!is_wp_error($remote) && is_array($remote)) {
            $status = array_merge($status, array(
                'remote_status' => isset($remote['status']) ? $remote['status'] : '',
                'expiry_date'   => isset($remote['expiry_date']) ? $remote['expiry_date'] : '',
                'days_left'     => isset($remote['days_left']) ? $remote['days_left'] : null,
                'sources'       => isset($remote['sources']) ? $remote['sources'] : array(),
            ));
            update_option(SPP_OPT_STATUS, $status, false);
        }

        $rows = $tiers;
        if (empty($rows)) $rows[] = array('min' => 0, 'max' => '', 'margin' => '');

        $save_url = admin_url('admin-post.php');
        ?>
        <div class="wrap">
          <h1>Authntic Products</h1>

          <?php if (isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
          <?php if (isset($_GET['rule_error'])): ?><div class="notice notice-error"><p><strong>Price rules not saved:</strong> <?php echo esc_html(wp_unslash($_GET['rule_error'])); ?></p></div><?php endif; ?>
          <?php
          $flash = get_transient('spp_flash_' . get_current_user_id());
          if ($flash) {
              delete_transient('spp_flash_' . get_current_user_id());
              echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($flash) . '</p></div>';
          } elseif (isset($_GET['done'])) {
              echo '<div class="notice notice-success is-dismissible"><p>Done: <code>' . esc_html($_GET['done']) . '</code></p></div>';
          }
          ?>

          <?php self::status_banner($key, $remote, $status); ?>

          <style>
            /* Two columns: settings on the left, sync + sources on the right.
               A grid holds the split at any width above the breakpoint — the old
               flex + min-width:420px collapsed to one column as soon as the admin
               menu ate enough room, which is why the halves kept stacking. */
            .spp-cols{display:grid;gap:24px;align-items:start;grid-template-columns:minmax(0,1fr)}
            @media (min-width:1080px){ .spp-cols{grid-template-columns:minmax(0,1fr) 380px} }
            .spp-cols > *{min-width:0}                 /* let children shrink instead of overflowing */
            .spp-cols table{max-width:100%}
            .spp-col-main{background:#fff;border:1px solid #ccd0d4;padding:16px 20px}
          </style>
          <div class="spp-cols">

            <!-- settings form -->
            <form method="post" id="spp-settings-form" action="<?php echo esc_url($save_url); ?>" class="spp-col-main">
              <input type="hidden" name="action" value="spp_save" />
              <?php wp_nonce_field('spp_save'); ?>

              <h2>Connection</h2>
              <table class="form-table">
                <tr>
                  <th><label for="spp_key">Enrollment key</label></th>
                  <td>
                    <input name="spp_key" id="spp_key" type="text" class="regular-text code" value="<?php echo esc_attr($key); ?>" placeholder="spp_live_…" style="width:100%" />
                    <p class="description">Copy this from your portal (My Sites → the key for this domain).</p>
                  </td>
                </tr>
              </table>

              <h2>Price rules</h2>
              <p class="description">
                Each row covers a price band: <strong>from ≤ price ≤ to</strong>, plus a margin to add.
                Selling price = original + margin. Bands must be continuous with no gaps or overlaps
                (e.g. 0–500, then 501–1200). Leave the <em>last</em> row's “to” blank for “and above”.
                Leave a <strong>margin blank</strong> to mark that band as <strong>quote-only</strong> — those
                products show a “Request Quote” button instead of a price.
              </p>
              <table class="widefat" style="max-width:560px" id="spp-rules">
                <thead><tr><th>From (₹)</th><th>To (₹)</th><th>Margin (₹)</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td><input type="number" step="1" name="tier_min[]"    value="<?php echo esc_attr($r['min']); ?>" style="width:110px" /></td>
                      <td><input type="number" step="1" name="tier_max[]"    value="<?php echo esc_attr($r['max']); ?>" style="width:110px" placeholder="and above" /></td>
                      <td><input type="number" step="1" name="tier_margin[]" value="<?php echo esc_attr($r['margin']); ?>" style="width:110px" placeholder="blank = quote" /></td>
                      <td><button type="button" class="button-link spp-del" style="color:#b71c1c">✕</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p><button type="button" class="button" id="spp-add-rule">+ Add band</button>
                 <span id="spp-rule-warn" style="color:#b71c1c;font-size:12.5px;margin-left:8px"></span></p>

              <div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;margin:6px 0 4px">
                <strong>Price preview</strong>
                <span class="description">— check what a product would sell for under your current rules.</span>
                <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                  <label>Supplier price ₹ <input type="number" id="spp-pp-price" style="width:120px" value="7500"></label>
                  <?php $ppCats = self::product_categories(); if (!empty($ppCats)): ?>
                  <label>Category
                    <select id="spp-pp-cat">
                      <option value="">(general rules)</option>
                      <?php foreach ($ppCats as $c): ?><option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option><?php endforeach; ?>
                    </select>
                  </label>
                  <?php endif; ?>
                  <button type="button" class="button" id="spp-pp-go">Preview</button>
                  <span id="spp-pp-out" style="font-weight:600"></span>
                </div>
                <p class="description" id="spp-pp-note" style="margin:6px 0 0"></p>
              </div>

              <h2>Request Quote button</h2>
              <p class="description">Shown on quote-only products (bands with a blank margin).</p>
              <table class="form-table">
                <tr><th>WhatsApp number</th><td>
                  <input type="text" name="quote_whatsapp" value="<?php echo esc_attr(get_option('spp_quote_whatsapp', '')); ?>" class="regular-text" placeholder="e.g. 919876543210 (country code, no +)" />
                  <p class="description">Digits only, with country code. Leave empty to hide the button.</p>
                </td></tr>
                <tr><th>Button label</th><td>
                  <input type="text" name="quote_button" value="<?php echo esc_attr(get_option('spp_quote_button_label', 'Request Quote')); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Price replacement text</th><td>
                  <input type="text" name="quote_price_label" value="<?php echo esc_attr(get_option('spp_quote_price_label', 'Price on request')); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Custom message</th><td>
                  <textarea name="quote_message" rows="2" class="large-text"><?php echo esc_textarea(get_option('spp_quote_message', 'Hi, I would like a quote for this product:')); ?></textarea>
                  <label style="display:block;margin-top:6px"><input type="checkbox" name="quote_include_name" value="yes" <?php checked(get_option('spp_quote_include_name', 'yes'), 'yes'); ?> /> include product name</label>
                  <label style="display:block"><input type="checkbox" name="quote_include_link" value="yes" <?php checked(get_option('spp_quote_include_link', 'yes'), 'yes'); ?> /> include product link</label>
                </td></tr>
              </table>

              <script>
              (function(){
                var table = document.getElementById('spp-rules');
                function rowHtml(){
                  return '<tr>'
                    + '<td><input type="number" step="1" name="tier_min[]" style="width:110px"></td>'
                    + '<td><input type="number" step="1" name="tier_max[]" style="width:110px" placeholder="and above"></td>'
                    + '<td><input type="number" step="1" name="tier_margin[]" style="width:110px" placeholder="blank = quote"></td>'
                    + '<td><button type="button" class="button-link spp-del" style="color:#b71c1c">✕</button></td>'
                    + '</tr>';
                }
                document.getElementById('spp-add-rule').addEventListener('click', function(){
                  table.querySelector('tbody').insertAdjacentHTML('beforeend', rowHtml());
                });
                table.addEventListener('click', function(e){
                  if (e.target.classList.contains('spp-del')) {
                    if (table.querySelectorAll('tbody tr').length > 1) e.target.closest('tr').remove();
                    validate();
                  }
                });
                // live gap/overlap check (server also enforces on save)
                function rules(){
                  var out = [];
                  table.querySelectorAll('tbody tr').forEach(function(tr){
                    var i = tr.querySelectorAll('input');
                    out.push({min:i[0].value.trim(), max:i[1].value.trim(), margin:i[2].value.trim()});
                  });
                  return out;
                }
                function validate(){
                  var r = rules(), warn = document.getElementById('spp-rule-warn'), msg = '';
                  for (var k=1;k<r.length;k++){
                    var pMax = parseFloat(r[k-1].max), cMin = parseFloat(r[k].min);
                    if (isNaN(pMax) || isNaN(cMin)) continue;
                    if (cMin <= pMax) { msg = 'Rows '+k+' and '+(k+1)+' overlap.'; break; }
                    if (cMin > pMax + 1) { msg = 'Gap: '+(pMax+1)+'–'+(cMin-1)+' not covered (set row '+(k+1)+' from = '+(pMax+1)+').'; break; }
                  }
                  warn.textContent = msg;
                }
                table.addEventListener('input', validate);
                validate();
              })();

              // price preview
              (function(){
                var btn = document.getElementById('spp-pp-go');
                if (!btn) return;
                var NONCE = <?php echo wp_json_encode(wp_create_nonce('spp_preview')); ?>;
                var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                btn.addEventListener('click', function(){
                  var price = document.getElementById('spp-pp-price').value || '0';
                  var catEl = document.getElementById('spp-pp-cat');
                  var cat   = catEl ? catEl.value : '';
                  var out   = document.getElementById('spp-pp-out');
                  var note  = document.getElementById('spp-pp-note');
                  out.textContent = '…'; note.textContent = '';
                  var body = new URLSearchParams();
                  body.set('action','spp_price_preview');
                  body.set('nonce',NONCE); body.set('price',price); body.set('cat',cat);
                  fetch(AJAX,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
                    .then(function(r){return r.json();})
                    .then(function(d){
                      if(!d||!d.success){ out.textContent='error'; return; }
                      var x=d.data;
                      if(x.quote){
                        out.textContent='→ Request Quote (no price shown)';
                        note.textContent='Using '+x.source+' rules. This price falls in a quote band, so the product shows the Request Quote button.';
                      } else if(x.price===null){
                        out.textContent='→ ₹'+price+' (unchanged)';
                        note.textContent=x.note||'';
                      } else {
                        out.textContent='→ sells for ₹'+x.price+'  (supplier ₹'+price+' + ₹'+x.margin+')';
                        note.textContent='Using '+x.source+' rules'+(x.band?(', band '+x.band.min+'–'+(x.band.max===''?'∞':x.band.max)):'')+'.';
                      }
                    })
                    .catch(function(){ out.textContent='error'; });
                });
              })();
              </script>

              <h2>Category price rules <span style="font-weight:400;font-size:13px;color:#666">(optional)</span></h2>
              <p class="description">
                Override the rules above for specific categories. Same format —
                continuous bands, blank margin = quote. Categories left empty use the general rules.
                <br><strong>Tip:</strong> a single band of <code>0 → and above</code> with a blank margin makes <em>every</em> product in that category “Request Quote”, regardless of price. Use that only if you want the whole category to be inquiry-only.
              </p>
              <?php
              $catRules = SPP_Margin::category_rules();
              $allCats  = self::product_categories();
              ?>
              <?php if (empty($allCats)): ?>
                <p class="description"><em>No product categories found yet — sync some products first.</em></p>
              <?php else:
                // How many categories actually carry overrides — shown on the summary so
                // the section can stay shut without hiding that something is configured.
                $nCustom = 0;
                foreach ($allCats as $c) { if (!empty($catRules[$c])) $nCustom++; }
              ?>
                <?php // One outer accordion around every category. Collapsed <details> still
                      // submit the inputs inside them, so save() is completely unaffected. ?>
                <details style="margin:8px 0;border:1px solid #ccd0d4;background:#fbfbfb;padding:10px 14px" <?php echo $nCustom ? 'open' : ''; ?>>
                  <summary style="cursor:pointer;font-weight:600">
                    All categories (<?php echo count($allCats); ?>)
                    <?php if ($nCustom): ?>
                      <span style="color:#2e7d32;font-weight:400"> — <?php echo (int) $nCustom; ?> with custom rules</span>
                    <?php else: ?>
                      <span style="color:#666;font-weight:400"> — all using the general rules</span>
                    <?php endif; ?>
                  </summary>
                <div id="spp-cat-rules" style="margin-top:10px">
                  <?php foreach ($allCats as $cat): $rows2 = isset($catRules[$cat]) ? $catRules[$cat] : array(); ?>
                    <details style="margin:8px 0;border:1px solid #ccd0d4;background:#fff;padding:10px 14px" <?php echo !empty($rows2) ? 'open' : ''; ?>>
                      <summary style="cursor:pointer;font-weight:600">
                        <?php echo esc_html($cat); ?>
                        <?php if (!empty($rows2)): ?><span style="color:#2e7d32;font-weight:400"> — custom rules</span><?php endif; ?>
                      </summary>
                      <table class="widefat spp-cat-table" style="max-width:560px;margin-top:10px" data-cat="<?php echo esc_attr($cat); ?>">
                        <thead><tr><th>From (₹)</th><th>To (₹)</th><th>Margin (₹)</th><th></th></tr></thead>
                        <tbody>
                          <?php if (empty($rows2)) $rows2 = array(array('min'=>'','max'=>'','margin'=>'')); ?>
                          <?php foreach ($rows2 as $r2): ?>
                            <tr>
                              <td><input type="number" step="1" name="cat_min[<?php echo esc_attr($cat); ?>][]"    value="<?php echo esc_attr($r2['min']); ?>" style="width:110px" /></td>
                              <td><input type="number" step="1" name="cat_max[<?php echo esc_attr($cat); ?>][]"    value="<?php echo esc_attr($r2['max']); ?>" style="width:110px" placeholder="and above" /></td>
                              <td><input type="number" step="1" name="cat_margin[<?php echo esc_attr($cat); ?>][]" value="<?php echo esc_attr($r2['margin']); ?>" style="width:110px" placeholder="blank = quote" /></td>
                              <td><button type="button" class="button-link spp-cat-del" style="color:#b71c1c">✕</button></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                      <p><button type="button" class="button spp-cat-add" data-cat="<?php echo esc_attr($cat); ?>">+ Add band</button>
                         <span class="description">leave all rows empty to use the general rules</span></p>
                    </details>
                  <?php endforeach; ?>
                </div>
                </details>
                <script>
                (function(){
                  document.querySelectorAll('.spp-cat-add').forEach(function(btn){
                    btn.addEventListener('click', function(){
                      var cat = btn.getAttribute('data-cat');
                      var tb = document.querySelector('.spp-cat-table[data-cat="' + CSS.escape(cat) + '"] tbody');
                      tb.insertAdjacentHTML('beforeend',
                        '<tr>'
                        + '<td><input type="number" step="1" name="cat_min[' + cat + '][]" style="width:110px"></td>'
                        + '<td><input type="number" step="1" name="cat_max[' + cat + '][]" style="width:110px" placeholder="and above"></td>'
                        + '<td><input type="number" step="1" name="cat_margin[' + cat + '][]" style="width:110px" placeholder="blank = quote"></td>'
                        + '<td><button type="button" class="button-link spp-cat-del" style="color:#b71c1c">✕</button></td>'
                        + '</tr>');
                    });
                  });
                  document.getElementById('spp-cat-rules').addEventListener('click', function(e){
                    if (e.target.classList.contains('spp-cat-del')) {
                      var tb = e.target.closest('tbody');
                      if (tb.querySelectorAll('tr').length > 1) e.target.closest('tr').remove();
                      else e.target.closest('tr').querySelectorAll('input').forEach(function(i){ i.value=''; });
                    }
                  });
                })();
                </script>
              <?php endif; ?>

              <h2>Theme customizations <span style="font-weight:400;font-size:13px;color:#666">(replaces child-theme code)</span></h2>
              <p class="description">These were previously hard-coded in the theme. Set them per site here.</p>
              <table class="form-table">
                <tr><th>Hidden category slug</th><td>
                  <input type="text" name="theme_hidden_cat" value="<?php echo esc_attr(get_option('spp_theme_hidden_cat', '')); ?>" class="regular-text" placeholder="e.g. others" />
                  <p class="description">Hides that category <em>and all its sub-categories</em> from shop, search and widgets. Blank = nothing hidden.</p>
                </td></tr>
                <tr><th>Allowed brands</th><td>
                  <input type="text" name="theme_allowed_brands" value="<?php echo esc_attr(get_option('spp_theme_allowed_brands', '')); ?>" class="large-text" placeholder="edifice, hublot, casio, omega, tissot" />
                  <p class="description">Comma-separated brand slugs to show in the brand filter. Blank = show all brands.</p>
                </td></tr>
                <tr><th>Behaviour</th><td>
                  <label style="display:block"><input type="checkbox" name="theme_target_blank" value="yes" <?php checked(get_option('spp_theme_target_blank', 'yes'), 'yes'); ?> /> open product links in a new tab</label>
                  <label style="display:block"><input type="checkbox" name="theme_search" value="yes" <?php checked(get_option('spp_theme_search', 'yes'), 'yes'); ?> /> use custom Flatsome AJAX search (external images, in-stock only)</label>
                  <label style="display:block"><input type="checkbox" name="theme_featured_first" value="yes" <?php checked(get_option('spp_theme_featured_first', 'yes'), 'yes'); ?> /> show featured products first in shop</label>
                  <label style="display:block;margin-top:10px;padding-top:8px;border-top:1px solid #eee"><input type="checkbox" name="flickity_gallery" value="yes" <?php checked(get_option(SPP_Gallery::OPT_ENABLED, 'no'), 'yes'); ?> /> <strong>show the full product gallery</strong> (all product images, not just the first)</label>
                  <p class="description" style="margin-left:24px">Product images are now shown through your theme's own gallery — with its normal zoom, lightbox and thumbnails — so this works on any theme and needs no child-theme files. <strong>ON</strong> = the single-product page shows every image in the slider. <strong>OFF</strong> = only the main (featured) image. Either way, shop and cart images always appear. Safe to delete the old <code>flatsome-child/woocommerce/</code> image templates.</p>
                </td></tr>
              </table>

              <h2>Stale products &rarr; out of stock <span style="font-weight:400;font-size:13px;color:#b26a00">(changes live stock &mdash; dry-run first)</span></h2>
              <p class="description" style="max-width:760px">
                If the scraper has not refreshed a product for N days it has probably been delisted or sold out at the
                supplier, so it is marked <strong>out of stock</strong>. Products are never deleted and flip back to
                in-stock automatically the moment sync sees them again.
                <strong>Only Authntic Products items are affected</strong> &mdash; anything you added to WooCommerce by hand
                is never touched.
              </p>
              <table class="form-table">
                <tr><th>Enable auto-sweep</th><td>
                  <label><input type="checkbox" name="stale_enabled" value="yes" <?php checked(get_option(SPP_Stale::OPT_ENABLED, 'no'), 'yes'); ?> /> automatically mark stale products out of stock</label>
                </td></tr>
                <tr><th>Stale after</th><td>
                  <input type="number" min="1" step="1" name="stale_days" value="<?php echo esc_attr(get_option(SPP_Stale::OPT_DAYS, 3)); ?>" style="width:80px" /> days without a scraper update
                </td></tr>
                <tr><th>Run every</th><td>
                  <input type="number" min="1" step="1" name="stale_every_days" value="<?php echo esc_attr(get_option(SPP_Stale::OPT_EVERY, 2)); ?>" style="width:80px" /> days
                  <p class="description">Last run:
                    <?php $lr = SPP_Stale::last_run(); echo $lr ? esc_html(date_i18n('Y-m-d H:i', $lr + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS))) : 'never'; ?>
                  </p>
                </td></tr>
                <tr><th>Safety limit</th><td>
                  abort if a run would mark more than
                  <input type="number" min="1" max="100" step="1" name="stale_max_pct" value="<?php echo esc_attr(get_option(SPP_Stale::OPT_MAXPCT, 40)); ?>" style="width:70px" />% of the catalogue
                  <p class="description">Marking most of the catalogue out of stock nearly always means the scrapers stopped, not that everything sold out. The sweep refuses and logs it instead.</p>
                </td></tr>
                <tr><th>Run now</th><td>
                  <?php // These buttons drive forms declared AFTER </form> via the HTML5
                        // form= attribute. They must NOT be wrapped in their own <form>
                        // here: a nested <form> tag is ignored by the HTML parser, which
                        // dumped their action + nonce fields into the settings form. Being
                        // last, they won the $_POST, so "Save settings" posted the sweep's
                        // nonce, failed check_admin_referer('spp_save') and ran a real
                        // sweep instead of saving. ?>
                  <button type="submit" class="button" form="spp-stale-dry-form">Dry run (count only)</button>
                  <button type="submit" class="button button-primary" form="spp-stale-run-form" style="margin-left:6px"
                          onclick="return confirm('Mark all stale Authntic Products items out of stock now?');">Mark stale products out of stock</button>
                  <p class="description">Always dry-run first: it reports the exact count without changing anything.</p>
                </td></tr>
                <tr><th>Run log</th><td>
                  <?php $slog = SPP_Stale::log(); if (empty($slog)): ?>
                    <em>No runs yet.</em>
                  <?php else: ?>
                    <table class="widefat striped" style="max-width:760px">
                      <thead><tr><th>When</th><th>Trigger</th><th>Threshold</th><th>Candidates</th><th>Marked</th><th>Result</th></tr></thead>
                      <tbody>
                      <?php foreach ($slog as $e): ?>
                        <tr>
                          <td><?php echo esc_html($e['at_human'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['trigger'] ?? ''); ?></td>
                          <td><?php echo esc_html(($e['days'] ?? '') . 'd'); ?></td>
                          <td><?php echo (int) ($e['candidates'] ?? 0); ?></td>
                          <td><strong><?php echo (int) ($e['marked'] ?? 0); ?></strong></td>
                          <td><?php
                            if (!empty($e['blocked'])) echo '<span style="color:#b71c1c">aborted &mdash; ' . esc_html($e['pct'] ?? '?') . '% over limit</span>';
                            elseif (!empty($e['dry']))  echo '<span style="color:#555">dry run</span>';
                            else echo '<span style="color:#2e7d32">ok</span>';
                          ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </td></tr>
              </table>

              <h2>Delete long-dead products <span style="font-weight:400;font-size:13px;color:#b71c1c">(permanently DELETES — dry-run first)</span></h2>
              <p class="description">
                Deletes Authntic Products items that are <strong>out of stock</strong> AND whose
                <strong>last scraper update was more than the threshold ago</strong> (i.e. the product
                hasn't been refreshed/seen for that long). Only plugin-managed items are touched (never
                products you added by hand), and stock is re-checked at delete time. If a product comes
                back in stock on the server it simply syncs back in on the next run.
              </p>
              <table class="form-table">
                <tr><th>Automatic</th><td>
                  <label><input type="checkbox" name="purge_enabled" value="yes" <?php checked(get_option(SPP_Purge::OPT_ENABLED, 'no'), 'yes'); ?> /> automatically delete out-of-stock products</label>
                  <p class="description">Off by default. When on, it runs on the schedule below using the same threshold and safety limit as the manual button.</p>
                </td></tr>
                <tr><th>Run every</th><td>
                  <input type="number" min="1" step="1" name="purge_every_days" value="<?php echo esc_attr(get_option(SPP_Purge::OPT_EVERY, 7)); ?>" style="width:80px" /> days
                </td></tr>
                <tr><th>Delete after</th><td>
                  <input type="number" min="1" step="1" name="purge_days" value="<?php echo esc_attr(get_option(SPP_Purge::OPT_DAYS, 30)); ?>" style="width:80px" /> days since last update (while out of stock)
                  <p class="description">Age is the scraper's last-update time for the product; the clock effectively resets whenever the product is refreshed or comes back in stock.</p>
                </td></tr>
                <tr><th>Safety limit</th><td>
                  Never delete more than
                  <input type="number" min="1" max="100" step="1" name="purge_max_pct" value="<?php echo esc_attr(get_option(SPP_Purge::OPT_MAXPCT, 40)); ?>" style="width:70px" />% of the catalogue in one run
                  <p class="description">Aborts if a run would exceed this — a sign the scrapers stopped rather than the stock actually selling out.</p>
                </td></tr>
                <tr><th>Last run</th><td>
                  <?php $plr = SPP_Purge::last_run(); echo $plr ? esc_html(date_i18n('Y-m-d H:i', $plr + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS))) : 'never'; ?>
                </td></tr>
                <tr><th>Run now</th><td>
                  <?php // buttons drive the standalone forms declared after </form> (see the stale note) ?>
                  <button type="submit" class="button" form="spp-purge-dry-form">Dry run (count only)</button>
                  <button type="submit" class="button button-primary" form="spp-purge-run-form" style="margin-left:6px;background:#b71c1c;border-color:#b71c1c"
                          onclick="return confirm('Permanently DELETE every Authntic Products item that has been out of stock past the threshold? This cannot be undone (they re-sync if they come back in stock).');">Delete out-of-stock products</button>
                  <p class="description">Always dry-run first — it reports the exact count without deleting anything. A large backlog keeps deleting <strong>automatically in the background</strong> after the first click, so you don't have to keep pressing it.</p>
                </td></tr>
                <?php if (get_option(SPP_Purge::OPT_DRAINING) === 'yes'): ?>
                <tr><th>Status</th><td><span style="color:#b26a00">⏳ Background cleanup in progress — deleting remaining out-of-stock products across the next runs.</span></td></tr>
                <?php endif; ?>
                <tr><th>Run log</th><td>
                  <?php $plog = SPP_Purge::log(); if (empty($plog)): ?>
                    <em>No runs yet.</em>
                  <?php else: ?>
                    <table class="widefat striped" style="max-width:760px">
                      <thead><tr><th>When</th><th>Trigger</th><th>Threshold</th><th>Candidates</th><th>Deleted</th><th>Result</th></tr></thead>
                      <tbody>
                      <?php foreach ($plog as $e): ?>
                        <tr>
                          <td><?php echo esc_html($e['at_human'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['trigger'] ?? ''); ?></td>
                          <td><?php echo esc_html(($e['days'] ?? '') . 'd'); ?></td>
                          <td><?php echo (int) ($e['candidates'] ?? 0); ?></td>
                          <td><strong><?php echo (int) ($e['deleted'] ?? 0); ?></strong></td>
                          <td><?php
                            if (!empty($e['blocked'])) echo '<span style="color:#b71c1c">aborted &mdash; ' . esc_html($e['pct'] ?? '?') . '% over limit</span>';
                            elseif (!empty($e['dry']))  echo '<span style="color:#555">dry run</span>';
                            else echo '<span style="color:#2e7d32">ok</span>';
                          ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </td></tr>
              </table>

              <?php $dupSkus = SPP_Dedupe::duplicate_sku_count(); ?>
              <h2>Duplicate products
                <?php if ($dupSkus): ?><span style="font-weight:400;font-size:13px;color:#b71c1c">(<?php echo esc_html(number_format_i18n($dupSkus)); ?> SKU(s) affected)</span><?php endif; ?>
              </h2>
              <p class="description">
                One product per SKU is correct. Extra copies came from two sync runs overlapping —
                WP-Cron can fire the same job twice, and the old lock couldn't prevent it. That lock is
                now atomic, so no new duplicates should appear; this clears out the ones already here.
                <br>The <strong>oldest</strong> copy of each SKU is kept — it holds the views, reviews and
                the URL customers already have. Extra copies go to <strong>Trash</strong> (not deleted), and any
                copy that has been ordered, or that this plugin didn't create, is skipped.
              </p>
              <table class="form-table">
                <tr><th>Status</th><td>
                  <?php if ($dupSkus): ?>
                    <strong style="color:#b71c1c"><?php echo esc_html(number_format_i18n($dupSkus)); ?></strong> SKU(s) currently have more than one product.
                  <?php else: ?>
                    <span style="color:#2e7d32">No duplicates found.</span>
                  <?php endif; ?>
                </td></tr>
                <tr><th>Clean up</th><td>
                  <?php // no <form> here — see the note at the stale sweep's Run now row ?>
                  <button type="submit" class="button" form="spp-dedupe-dry-form">Dry run (count only)</button>
                  <button type="submit" class="button button-primary" form="spp-dedupe-run-form" style="margin-left:6px"
                          onclick="return confirm('Move duplicate copies to Trash? The oldest copy of each SKU is kept.');">Remove duplicates</button>
                  <p class="description">Works through up to <?php echo (int) SPP_Dedupe::PER_RUN; ?> SKUs per click, so it can't time out. Run it again until the count reaches zero.</p>
                </td></tr>
                <?php $dlog = SPP_Dedupe::log(); if (!empty($dlog)): ?>
                <tr><th>Run log</th><td>
                  <table class="widefat striped" style="max-width:700px">
                    <thead><tr><th>When</th><th>Trigger</th><th>SKUs seen</th><th>Trashed</th><th>Skipped</th><th>Still duplicated</th></tr></thead>
                    <tbody>
                    <?php foreach ($dlog as $e): ?>
                      <tr>
                        <td><?php echo esc_html($e['at_human'] ?? ''); ?></td>
                        <td><?php echo esc_html($e['trigger'] ?? ''); ?></td>
                        <td><?php echo (int) ($e['skus'] ?? 0); ?></td>
                        <td><strong><?php echo (int) ($e['trashed'] ?? 0); ?></strong></td>
                        <td><?php echo (int) ($e['skipped'] ?? 0); ?></td>
                        <td><?php echo (int) ($e['remaining'] ?? 0); ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </td></tr>
                <?php endif; ?>
              </table>

              <h2>Live stock check <span style="font-weight:400;font-size:13px;color:#666">(re-verify quiet in-stock products)</span></h2>
              <p class="description">
                Walks your <strong>in-stock</strong> products one at a time and asks the server to
                re-scrape each one from the supplier, so a product that quietly sold out gets caught.
                Out-of-stock products are never touched — they have nothing to lose by staying out of
                stock, and each check costs a real browser launch on the server.
                <br><strong>Note:</strong> the server scrapes in the background, so corrected stock
                arrives with a later sync — not the moment you press the button.
              </p>
              <table class="form-table">
                <tr><th>Enable</th><td>
                  <label><input type="checkbox" name="livecheck_enabled" value="yes" <?php checked(get_option(SPP_LiveCheck::OPT_ENABLED, 'no'), 'yes'); ?> /> keep re-verifying in-stock products in the background</label>
                </td></tr>
                <tr><th>Only if quiet for</th><td>
                  <input type="number" min="1" step="1" name="livecheck_days" value="<?php echo esc_attr(get_option(SPP_LiveCheck::OPT_DAYS, 3)); ?>" style="width:80px" /> days
                  <p class="description">A product whose data hasn't changed in this long is a candidate. Once asked about, it isn't asked again for <?php echo (int) SPP_LiveCheck::RECHECK_DAYS; ?> days.</p>
                </td></tr>
                <tr><th>Rate</th><td>
                  <input type="number" min="1" max="100" step="1" name="livecheck_per_run" value="<?php echo esc_attr(get_option(SPP_LiveCheck::OPT_PER_RUN, 5)); ?>" style="width:70px" /> product(s)
                  every <input type="number" min="1" step="1" name="livecheck_every_min" value="<?php echo esc_attr(get_option(SPP_LiveCheck::OPT_EVERY_MIN, 15)); ?>" style="width:70px" /> minutes
                  <p class="description">
                    Keep this low. Every check queues a real scrape on the server, and that queue runs
                    one job at a time shared with the catalogue rotator — turn it up too far and normal
                    syncing starves. Current pace: <strong><?php echo esc_html(SPP_LiveCheck::cycle_estimate()); ?></strong>.
                  </p>
                </td></tr>
                <tr><th>Waiting now</th><td>
                  <strong><?php echo esc_html(number_format_i18n(SPP_LiveCheck::candidate_count())); ?></strong> in-stock product(s) queued for verification.
                  <p class="description">Last run:
                    <?php $lcr = SPP_LiveCheck::last_run(); echo $lcr ? esc_html(date_i18n('Y-m-d H:i', $lcr + (int)(get_option('gmt_offset') * HOUR_IN_SECONDS))) : 'never'; ?>
                  </p>
                </td></tr>
                <tr><th>Run now</th><td>
                  <?php // Same rule as the stale sweep above: no <form> here. These target
                        // standalone forms declared after </form> via the form= attribute. ?>
                  <button type="submit" class="button" form="spp-livecheck-dry-form">Dry run (count only)</button>
                  <button type="submit" class="button button-primary" form="spp-livecheck-run-form" style="margin-left:6px">Check a batch now</button>
                  <p class="description">Sends one batch at the rate above, regardless of the schedule.</p>
                </td></tr>
                <tr><th>Run log</th><td>
                  <?php $lclog = SPP_LiveCheck::log(); if (empty($lclog)): ?>
                    <em>No runs yet.</em>
                  <?php else: ?>
                    <table class="widefat striped" style="max-width:760px">
                      <thead><tr><th>When</th><th>Trigger</th><th>Asked</th><th>Queued</th><th>Already fresh</th><th>Failed</th><th>Waiting</th></tr></thead>
                      <tbody>
                      <?php foreach ($lclog as $e): ?>
                        <tr>
                          <td><?php echo esc_html($e['at_human'] ?? ''); ?></td>
                          <td><?php echo esc_html($e['trigger'] ?? ''); ?><?php echo !empty($e['dry']) ? ' <span style="color:#555">(dry)</span>' : ''; ?></td>
                          <td><strong><?php echo (int) ($e['checked'] ?? 0); ?></strong></td>
                          <td><?php echo (int) ($e['queued'] ?? 0); ?></td>
                          <td><?php echo (int) ($e['fresh'] ?? 0); ?></td>
                          <td><?php echo (int) ($e['failed'] ?? 0); ?></td>
                          <td><?php echo (int) ($e['waiting'] ?? 0); ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </td></tr>
              </table>

              <h2>Checkout: COD / prepaid <span style="font-weight:400;font-size:13px;color:#b26a00">(money logic — test on staging)</span></h2>
              <table class="form-table">
                <tr><th>Enable</th><td>
                  <label><input type="checkbox" name="checkout_enabled" value="yes" <?php checked(get_option('spp_checkout_enabled', 'no'), 'yes'); ?> /> show the COD / UPI chooser on checkout</label>
                  <p class="description">Master switch for everything below.</p>
                </td></tr>
                <tr><th>Default selection</th><td>
                  <?php $dc = get_option('spp_checkout_default', 'none'); ?>
                  <select name="checkout_default" style="min-width:220px">
                    <option value="none"    <?php selected($dc, 'none'); ?>>No default (customer picks)</option>
                    <option value="cod"     <?php selected($dc, 'cod'); ?>>Cash on Delivery pre-selected</option>
                    <option value="prepaid" <?php selected($dc, 'prepaid'); ?>>UPI / Prepaid pre-selected</option>
                  </select>
                </td></tr>
                <tr><th>Prepaid discount</th><td>
                  <label><input type="checkbox" name="prepaid_discount_on" value="yes" <?php checked(get_option('spp_prepaid_discount_on', 'yes'), 'yes'); ?> /> give a discount on full online payment</label><br>
                  Amount ₹ <input type="number" step="1" min="0" name="checkout_discount" value="<?php echo esc_attr(get_option('spp_checkout_discount', 199)); ?>" style="width:110px" />
                  &nbsp; Label <input type="text" name="checkout_label" value="<?php echo esc_attr(get_option('spp_checkout_label', 'Online Payment Discount')); ?>" class="regular-text" />
                </td></tr>
                <tr><th>COD extra charge</th><td>
                  Amount ₹ <input type="number" step="1" min="0" name="cod_fee_amount" value="<?php echo esc_attr(get_option('spp_cod_fee_amount', 0)); ?>" style="width:110px" />
                  &nbsp; Label <input type="text" name="cod_fee_label" value="<?php echo esc_attr(get_option('spp_cod_fee_label', 'COD Charges')); ?>" class="regular-text" />
                  <p class="description">Added to COD orders (e.g. 5000 + 200 = 5200, collected at delivery). 0 = no charge.</p>
                </td></tr>
                <tr><th>COD advance</th><td>
                  Amount <input type="number" step="0.01" min="0" name="cod_advance" value="<?php echo esc_attr(get_option('spp_cod_advance', 0)); ?>" style="width:110px" />
                  <select name="cod_advance_type">
                    <option value="fixed"      <?php selected(get_option('spp_cod_advance_type', 'fixed'), 'fixed'); ?>>₹ fixed</option>
                    <option value="percentage" <?php selected(get_option('spp_cod_advance_type', 'fixed'), 'percentage'); ?>>% of order total</option>
                  </select>
                  <p class="description">"Pay X online now, rest at delivery." Blank/0 = plain 100% COD.
                  The partial engine is <strong>built into this plugin</strong>: it charges only the advance online, restores the full
                  total after payment, sets the order to <em>Partial Paid</em>, and shows Paid / Remaining everywhere
                  (thank-you, emails, admin, and the WooCommerce &rarr; Partial COD payments list).<br>
                  <?php if (SPP_Partial::conflict()): ?><span style="color:#b71c1c"><strong>WooBooster Partial COD is still active</strong> — the built-in engine is paused to avoid double-charging. Deactivate WooBooster; old orders keep working (same meta keys).</span>
                  <?php else: ?><span style="color:#2e7d32">Built-in engine active. WooBooster is no longer needed.</span><?php endif; ?></p>
                </td></tr>
                <tr><th>Gateway IDs</th><td>
                  COD <input type="text" name="cod_gateway_id" value="<?php echo esc_attr(get_option('spp_cod_gateway_id', 'cod')); ?>" style="width:150px" />
                  &nbsp; UPI/Prepaid <input type="text" name="upi_gateway_id" value="<?php echo esc_attr(get_option('spp_upi_gateway_id', 'upi-payment')); ?>" style="width:150px" />
                  <p class="description">Must match your payment gateway element ids (payment_method_<em>id</em>). Find them: checkout page &rarr; right-click a payment radio &rarr; Inspect. Common UPI ids: <code>upi-payment</code>, <code>upiwc</code>, <code>razorpay</code>.</p>
                </td></tr>
              </table>

              <p style="margin-top:16px"><button type="submit" class="button button-primary">Save settings</button></p>
              <p class="description" style="margin-top:4px">Saving price rules automatically re-prices all existing products.
              <?php if (get_option(SPP_OPT_REPRICE) === 'yes'): ?><strong style="color:#b26a00">Re-pricing in progress…</strong><?php endif; ?></p>
            </form>

            <?php // Stale-sweep forms live OUT here, targeted by the buttons above via
                  // form="…". Nesting them inside #spp-settings-form is what broke
                  // "Save settings" in 4.1.0 — see the comment at the Run now row. ?>
            <form method="post" id="spp-stale-dry-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_stale_run'); ?>
              <input type="hidden" name="action" value="spp_stale_run" />
              <input type="hidden" name="dry" value="1" />
            </form>
            <form method="post" id="spp-purge-dry-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_purge_run'); ?>
              <input type="hidden" name="action" value="spp_purge_run" />
              <input type="hidden" name="dry" value="1" />
            </form>
            <form method="post" id="spp-purge-run-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_purge_run'); ?>
              <input type="hidden" name="action" value="spp_purge_run" />
            </form>
            <form method="post" id="spp-stale-run-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_stale_run'); ?>
              <input type="hidden" name="action" value="spp_stale_run" />
              <input type="hidden" name="dry" value="0" />
            </form>
            <form method="post" id="spp-livecheck-dry-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_livecheck_run'); ?>
              <input type="hidden" name="action" value="spp_livecheck_run" />
              <input type="hidden" name="dry" value="1" />
            </form>
            <form method="post" id="spp-livecheck-run-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_livecheck_run'); ?>
              <input type="hidden" name="action" value="spp_livecheck_run" />
              <input type="hidden" name="dry" value="0" />
            </form>
            <form method="post" id="spp-dedupe-dry-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_dedupe_run'); ?>
              <input type="hidden" name="action" value="spp_dedupe_run" />
              <input type="hidden" name="dry" value="1" />
            </form>
            <form method="post" id="spp-dedupe-run-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:none">
              <?php wp_nonce_field('spp_dedupe_run'); ?>
              <input type="hidden" name="action" value="spp_dedupe_run" />
              <input type="hidden" name="dry" value="0" />
            </form>

            <!-- controls + sources -->
            <div class="spp-col-side">

              <?php
                $hb        = isset($status['heartbeat_at']) ? $status['heartbeat_at'] : '';
                $hbAct     = isset($status['heartbeat_act']) ? $status['heartbeat_act'] : '';
                $lastRun   = isset($status['last_run']) ? $status['last_run'] : '';
                $lastProc  = isset($status['last_processed']) ? intval($status['last_processed']) : 0;
                $phase     = isset($status['phase']) ? $status['phase'] : '';
                $act       = isset($status['last_activity']) ? $status['last_activity'] : '';
                $actAt     = isset($status['last_activity_at']) ? $status['last_activity_at'] : '';
                $err       = isset($status['last_error']) ? $status['last_error'] : '';
                $errAt     = isset($status['last_error_at']) ? $status['last_error_at'] : '';
                $next      = wp_next_scheduled('spp_cron_sync');
                $cronOff   = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
                $fullHrs   = SPP_Sync::full_resync_hours();
                $fullNext  = SPP_Sync::next_full_resync();
              ?>

              <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin-bottom:20px">
                <h2 style="margin-top:0">Sync</h2>
                <p>Auto-sync is <strong style="color:<?php echo $autosync ? '#2e7d32' : '#b71c1c'; ?>"><?php echo $autosync ? 'ON' : 'OFF'; ?></strong> — a heartbeat runs every minute and imports products continuously.</p>

                <table class="widefat striped" style="margin:8px 0 12px">
                  <tbody>
                    <tr><td style="width:150px">Heartbeat</td><td>
                      <?php if ($next): ?>next in <strong><?php echo esc_html(human_time_diff(time(), $next)); ?></strong><?php else: ?><span style="color:#b71c1c">not scheduled</span><?php endif; ?>
                      <?php if ($hb): ?> · last fired <?php echo esc_html(SPP_Diag::ago($hb)); ?><?php if ($hbAct): ?> (<?php echo esc_html($hbAct); ?>)<?php endif; ?><?php endif; ?>
                    </td></tr>
                    <tr><td>Last sync pass</td><td><?php echo $lastRun ? esc_html(SPP_Diag::ago($lastRun)) . ' · ' . $lastProc . ' product(s)' : '—'; ?></td></tr>
                    <tr><td>Phase</td><td><?php
    if ($phase === 'backfill_in' || $phase === 'backfill') echo 'Backfilling — in-stock products first';
    elseif ($phase === 'backfill_out') echo 'Backfilling — remaining out-of-stock products';
    elseif ($phase === 'updates') echo 'Up to date — watching for changes';
    else echo '—';
?></td></tr>
                    <tr><td>Current activity</td><td><?php echo $act ? esc_html($act) . ($actAt ? ' (' . esc_html(SPP_Diag::ago($actAt)) . ')' : '') : '—'; ?></td></tr>
                    <tr><td>Auto full resync</td><td>
                      <?php if ($fullHrs > 0): ?>every <strong><?php echo intval($fullHrs); ?>h</strong><?php echo $fullNext ? ' · next ' . esc_html(human_time_diff(time(), $fullNext)) . ($fullNext > time() ? ' from now' : ' (due)') : ''; ?><?php else: ?>disabled<?php endif; ?>
                    </td></tr>
                  </tbody>
                </table>

                <?php if ($cronOff): ?>
                  <div class="notice notice-warning inline" style="margin:8px 0"><p><strong>WP-Cron is disabled</strong> on this site (DISABLE_WP_CRON). Background updates only run if a real server cron calls <code>wp-cron.php</code>. Otherwise use “Sync now”, or ask your host to enable cron.</p></div>
                <?php endif; ?>

                <?php if ($err): ?>
                  <div class="notice notice-error inline" style="margin:8px 0">
                    <p><strong>Last error</strong><?php echo $errAt ? ' (' . esc_html(SPP_Diag::ago($errAt)) . ')' : ''; ?>:<br><?php echo esc_html($err); ?></p>
                  </div>
                  <?php self::button('clear_error', 'Clear error', 'button'); ?>
                <?php endif; ?>

                <p style="margin-top:10px">
                  <?php self::button('sync_now', 'Sync now', 'button button-primary'); ?>
                  <?php if ($autosync): ?><?php self::button('stop', 'Stop auto-sync', 'button'); ?>
                  <?php else: ?><?php self::button('start', 'Start auto-sync', 'button'); ?><?php endif; ?>
                  <?php self::button('resync', 'Full resync now', 'button', 'Re-pull the WHOLE catalogue from scratch now? Prices refresh as it goes. Runs in the background.'); ?>
                  <?php self::button('reprice', 'Re-price now', 'button', 'Re-price every product from the current rules?'); ?>
                </p>

                <details style="margin-top:10px">
                  <summary style="cursor:pointer;color:#2271b1">How does the sync cycle work?</summary>
                  <div style="font-size:13px;color:#444;margin-top:8px;line-height:1.6">
                    <strong>Two clocks run:</strong><br>
                    1. <strong>Every minute</strong> — a heartbeat does <em>one</em> job in priority order: remove products (if asked) → clean up a removed supplier → re-price → import the next batch. Importing runs as <strong>backfill</strong> (pulls the whole catalogue in batches of 100 per category) then switches to <strong>updates</strong> (repeatedly checks for edited and brand-new products), so the store stays current on its own between full syncs.<br>
                    2. <strong>Every <?php echo intval($fullHrs > 0 ? $fullHrs : 12); ?> hours</strong> — an automatic <strong>full resync</strong> re-pulls the entire catalogue from scratch (idempotent, no downtime) as a safety net to catch anything the minute-by-minute updates missed. Change the interval below, or set it to 0 to turn it off.
                    <br><br>
                    <strong>Buttons:</strong> “Sync now” runs one import pass immediately. “Full resync now” resets and re-pulls everything right away. Both keep auto-sync doing the rest.
                  </div>
                </details>

                <p style="margin-top:12px;padding-top:10px;border-top:1px solid #eee">
                  <label>Automatic full resync every
                    <input type="number" min="0" step="1" name="full_resync_hours" form="spp-settings-form" value="<?php echo esc_attr($fullHrs); ?>" style="width:70px"> hours
                  </label>
                  <span class="description">(0 = off; saved with the settings form on the left)</span>
                </p>

                <?php
                  $tickSecs   = SPP_Sync::tick_seconds();
                  $tickBudget = SPP_Sync::tick_budget();
                  $duty       = $tickSecs > 0 ? round(100 * $tickBudget / $tickSecs) : 0;
                ?>
                <p style="margin-top:10px;padding-top:10px;border-top:1px solid #eee">
                  <strong>Sync intensity</strong><br>
                  work for
                  <input type="number" min="1" max="120" step="1" name="tick_budget" form="spp-settings-form" value="<?php echo esc_attr($tickBudget); ?>" style="width:60px"> seconds,
                  every
                  <input type="number" min="60" max="3600" step="30" name="tick_seconds" form="spp-settings-form" value="<?php echo esc_attr($tickSecs); ?>" style="width:70px"> seconds
                  <span class="description" style="display:block;margin-top:4px">
                    This is the plugin's share of your CPU: currently about
                    <strong<?php echo $duty >= 40 ? ' style="color:#b71c1c"' : ''; ?>><?php echo (int) $duty; ?>%</strong> of every cycle,
                    continuously. The old default (40s of work every 60s) is 67% and will sit near the top of a
                    shared-hosting CPU allowance all day. 10s every 300s is about 3% and still imports a few
                    thousand products an hour once the first backfill is done.
                    <br>Raise it temporarily if you need a big backfill finished quickly, then put it back.
                  </span>
                </p>
              </div>

              <div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin-bottom:20px">
                <h2 style="margin-top:0">Catalogue</h2>
                <p><strong><?php echo number_format($count); ?></strong> managed products on this store.</p>
                <?php $purge = get_option(SPP_OPT_PURGE, array()); if (is_array($purge) && !empty($purge)): ?>
                  <p style="color:#b26a00"><strong>Removing products from a removed source…</strong> (<?php echo count($purge); ?> queued)</p>
                <?php endif; ?>
                <?php if (get_option(SPP_OPT_REPRICE) === 'yes'): ?>
                  <p style="color:#b26a00"><strong>Re-pricing in progress…</strong></p>
                <?php endif; ?>
                <?php if ($removing): ?>
                  <p style="color:#b71c1c"><strong>Removal in progress…</strong> reload to watch the count fall.</p>
                  <?php self::button('stop_removal', 'Stop removal', 'button'); ?>
                <?php else: ?>
                  <?php self::button('remove_all', 'Remove all products', 'button button-link-delete', 'Delete ALL products this plugin created? This cannot be undone.'); ?>
                <?php endif; ?>
              </div>

              <?php self::health_box(); ?>

              <?php self::sources_box($status); ?>

            </div>
          </div>
        </div>
        <?php
    }

    // expiry / status banner with countdown + renew (demo)
    private static function status_banner($key, $remote, $status) {
        if (!$key) {
            echo '<div class="notice notice-warning"><p>Paste your enrollment key below to connect this store.</p></div>';
            return;
        }
        // hard failures from the server (invalid key, server down, etc.)
        if (is_wp_error($remote)) {
            $msg  = $remote->get_error_message();
            $code = $remote->get_error_code();
            $headline = 'Not connected';
            $hint = $msg;
            if (stripos($msg, 'invalid enrollment key') !== false || $code === 'spp_auth') {
                $headline = 'Invalid key';
                $hint = 'This enrollment key isn’t recognized. Re-copy it from your portal (My Sites → the key for this domain).';
            } elseif ($code === 'spp_no_key') {
                $headline = 'No key set';
                $hint = 'Paste your enrollment key below.';
            }
            echo '<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #b71c1c;padding:14px 18px;margin:14px 0">';
            echo '<strong style="color:#b71c1c;font-size:15px">' . esc_html($headline) . '</strong><br>';
            echo '<span style="color:#42505f">' . esc_html($hint) . '</span>';
            echo '</div>';
            return;
        }
        // valid key, but plugged into the wrong site
        if (isset($remote['domain_ok']) && !$remote['domain_ok']) {
            $reg = isset($remote['registered_domain']) ? $remote['registered_domain'] : '';
            echo '<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #b71c1c;padding:14px 18px;margin:14px 0">';
            echo '<strong style="color:#b71c1c;font-size:15px">Wrong domain</strong><br>';
            echo '<span style="color:#42505f">This key is registered to <code>' . esc_html($reg) . '</code>, not this site (<code>' . esc_html(SPP_API::site_domain()) . '</code>). Products won’t sync here. Use the key issued for this domain.</span>';
            echo '</div>';
            return;
        }

        $rs    = isset($status['remote_status']) ? $status['remote_status'] : '';
        $days  = isset($status['days_left']) ? $status['days_left'] : null;
        $expiry= isset($status['expiry_date']) ? $status['expiry_date'] : '';
        $expDate = $expiry ? date_i18n(get_option('date_format'), strtotime($expiry)) : '—';

        $color = '#2e7d32'; $label = 'Active';
        if ($rs === 'expired' || ($days !== null && $days < 0)) { $color = '#b71c1c'; $label = 'Expired'; }
        elseif ($days !== null && $days <= 7) { $color = '#b26a00'; $label = 'Expiring soon'; }

        echo '<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid ' . $color . ';padding:14px 18px;margin:14px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">';
        echo '<div>';
        echo '<strong style="color:' . $color . ';font-size:15px">' . esc_html($label) . '</strong> &nbsp; ';
        if ($days !== null && $days >= 0) echo '<span>' . intval($days) . ' day' . ($days == 1 ? '' : 's') . ' left · expires ' . esc_html($expDate) . '</span>';
        elseif ($days !== null) echo '<span>expired ' . esc_html($expDate) . ' — products auto-remove 3 days after expiry</span>';
        echo '</div>';

        // Pay & renew — the button + gateway name come entirely from the server
        // (GET /product/pay-config). When online payment is on we show a real
        // "Pay" button; switching gateways in the portal needs no plugin change.
        $pc = SPP_API::pay_config();
        $payOn = !is_wp_error($pc) && is_array($pc) && !empty($pc['enabled']);
        $due   = $payOn && !empty($pc['due_invoice']) ? $pc['due_invoice'] : null;

        echo '<div style="display:flex;gap:8px;align-items:center">';
        if ($payOn && $due) {
            $amt = isset($due['amount']) ? $due['amount'] : '';
            $cur = isset($due['currency']) ? $due['currency'] : 'INR';
            $sym = ($cur === 'INR') ? '₹' : ($cur . ' ');
            $gw  = !empty($pc['title']) ? $pc['title'] : 'online';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
            echo '<input type="hidden" name="action" value="spp_action" /><input type="hidden" name="do" value="pay" />';
            wp_nonce_field('spp_action');
            echo '<button class="button button-primary">Pay ' . esc_html($sym . $amt) . ' &amp; renew</button>';
            echo '</form>';
            echo '<span style="color:#6b7280;font-size:12px">via ' . esc_html($gw) . '</span>';
        } elseif ($payOn && !$due) {
            echo '<span style="color:#6b7280;font-size:12px">No dues right now.</span>';
        }
        // demo renewal stays available as a fallback
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
        echo '<input type="hidden" name="action" value="spp_action" /><input type="hidden" name="do" value="renew" />';
        wp_nonce_field('spp_action');
        echo '<button class="button" onclick="return confirm(\'Run the demo renewal? (extends +1 month)\')">Renew (demo)</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    // the sources this site is enrolled for
    private static function health_box() {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin-bottom:20px">';
        echo '<h2 style="margin-top:0">Health check</h2>';
        echo '<p class="description" style="margin-top:0">A quick self-test of every part of the pipeline. Run it whenever something looks off.</p>';

        $run = isset($_GET['healthcheck']);
        if (!$run) {
            $url = wp_nonce_url(admin_url('admin.php?page=server-products&healthcheck=1'), 'spp_health');
            echo '<a href="' . esc_url($url) . '" class="button button-primary">Run health check</a>';
            echo '</div>';
            return;
        }
        check_admin_referer('spp_health');

        $rows = SPP_Diag::selftest();
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $r) {
            $icon = $r['ok'] ? '<span style="color:#2e7d32;font-weight:700">✓</span>'
                             : '<span style="color:#b71c1c;font-weight:700">✕</span>';
            echo '<tr>'
               . '<td style="width:26px;text-align:center">' . $icon . '</td>'
               . '<td style="width:170px"><strong>' . esc_html($r['label']) . '</strong></td>'
               . '<td>' . esc_html($r['detail']) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table>';
        $url = wp_nonce_url(admin_url('admin.php?page=server-products&healthcheck=1'), 'spp_health');
        echo '<p style="margin-top:10px"><a href="' . esc_url($url) . '" class="button">Re-run</a></p>';
        echo '</div>';
    }

    private static function sources_box($status) {
        $sources = isset($status['sources']) && is_array($status['sources']) ? $status['sources'] : array();
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px">';
        echo '<h2 style="margin-top:0">Enrolled sources</h2>';
        if (empty($sources)) {
            echo '<p style="color:#777">No sources on this site yet. Add them in your portal.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Source</th><th>Categories</th></tr></thead><tbody>';
            foreach ($sources as $s) {
                $cats = !empty($s['categories']) ? implode(', ', $s['categories']) : '(all)';
                echo '<tr><td>' . esc_html(isset($s['name']) ? $s['name'] : $s['source_id']) . '</td><td>' . esc_html($cats) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private static function button($do, $label, $class, $confirm = '') {
        $onclick = $confirm ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 6px 6px 0">';
        echo '<input type="hidden" name="action" value="spp_action" /><input type="hidden" name="do" value="' . esc_attr($do) . '" />';
        wp_nonce_field('spp_action');
        echo '<button type="submit" class="' . esc_attr($class) . '"' . $onclick . '>' . esc_html($label) . '</button>';
        echo '</form>';
    }

    public static function save() {
        check_admin_referer('spp_save');
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');

        update_option(SPP_OPT_KEY, sanitize_text_field($_POST['spp_key'] ?? ''));

        // --- price rules (preserve blank max = "and above", blank margin = quote) ---
        $mins = $_POST['tier_min'] ?? array(); $maxs = $_POST['tier_max'] ?? array(); $mgs = $_POST['tier_margin'] ?? array();
        $rules = array();
        for ($i = 0; $i < count($mins); $i++) {
            $min = trim((string) $mins[$i]); $max = trim((string) ($maxs[$i] ?? '')); $mg = trim((string) ($mgs[$i] ?? ''));
            if ($min === '' && $max === '' && $mg === '') continue; // skip fully-empty rows
            $rules[] = array(
                'min'    => ($min === '' ? '' : floatval($min)),
                'max'    => ($max === '' ? '' : floatval($max)),
                'margin' => ($mg  === '' ? '' : floatval($mg)),
            );
        }

        $err = SPP_Margin::validate($rules);
        if ($err !== '') {
            // don't save invalid rules — bounce back with the reason
            wp_safe_redirect(admin_url('admin.php?page=server-products&rule_error=' . rawurlencode($err)));
            exit;
        }

        update_option(SPP_OPT_MARGINS, $rules);

        // --- per-category rules (validated the same way; empty sets are dropped) ---
        $catMins = $_POST['cat_min'] ?? array();
        $catMaxs = $_POST['cat_max'] ?? array();
        $catMgs  = $_POST['cat_margin'] ?? array();
        $catMap  = array();
        foreach ($catMins as $cat => $mins) {
            $set = array();
            for ($i = 0; $i < count($mins); $i++) {
                $min = trim((string) $mins[$i]);
                $max = trim((string) ($catMaxs[$cat][$i] ?? ''));
                $mg  = trim((string) ($catMgs[$cat][$i] ?? ''));
                if ($min === '' && $max === '' && $mg === '') continue;
                $set[] = array(
                    'min'    => ($min === '' ? '' : floatval($min)),
                    'max'    => ($max === '' ? '' : floatval($max)),
                    'margin' => ($mg  === '' ? '' : floatval($mg)),
                );
            }
            if (empty($set)) continue;                 // no rows -> use the general rules
            $cerr = SPP_Margin::validate($set);
            if ($cerr !== '') {
                wp_safe_redirect(admin_url('admin.php?page=server-products&rule_error=' . rawurlencode('Category "' . $cat . '": ' . $cerr)));
                exit;
            }
            $catMap[$cat] = $set;
        }
        SPP_Margin::set_category_rules($catMap);

        update_option(SPP_OPT_REPRICE, 'yes', false);   // re-price existing products with the new rules
        update_option(SPP_OPT_REPRICE_AFTER, 0, false);

        // --- request-quote settings ---
        update_option('spp_quote_whatsapp',     preg_replace('/[^0-9]/', '', (string) ($_POST['quote_whatsapp'] ?? '')));
        update_option('spp_quote_button_label', sanitize_text_field($_POST['quote_button'] ?? 'Request Quote'));
        update_option('spp_quote_price_label',  sanitize_text_field($_POST['quote_price_label'] ?? 'Price on request'));
        update_option('spp_quote_message',      sanitize_textarea_field($_POST['quote_message'] ?? ''));
        update_option('spp_quote_include_name', isset($_POST['quote_include_name']) ? 'yes' : 'no');
        update_option('spp_quote_include_link', isset($_POST['quote_include_link']) ? 'yes' : 'no');

        // --- theme customization settings (per site) ---
        update_option('spp_theme_hidden_cat',     sanitize_title($_POST['theme_hidden_cat'] ?? ''));
        update_option('spp_theme_allowed_brands', sanitize_text_field($_POST['theme_allowed_brands'] ?? ''));
        update_option('spp_theme_target_blank',   isset($_POST['theme_target_blank']) ? 'yes' : 'no');
        update_option('spp_theme_search',         isset($_POST['theme_search']) ? 'yes' : 'no');
        update_option('spp_theme_featured_first', isset($_POST['theme_featured_first']) ? 'yes' : 'no');
        delete_transient('spp_hidden_cat_ids');
        delete_transient('spp_featured_ids');

        // --- checkout settings ---
        update_option(SPP_Gallery::OPT_ENABLED, isset($_POST['flickity_gallery']) ? 'yes' : 'no');
        update_option(SPP_Stale::OPT_ENABLED,  isset($_POST['stale_enabled']) ? 'yes' : 'no');
        update_option(SPP_Stale::OPT_DAYS,     max(1, (int) ($_POST['stale_days'] ?? 3)));
        update_option(SPP_Stale::OPT_EVERY,    max(1, (int) ($_POST['stale_every_days'] ?? 2)));
        update_option(SPP_Stale::OPT_MAXPCT,   min(100, max(1, (int) ($_POST['stale_max_pct'] ?? 40))));

        update_option(SPP_Purge::OPT_ENABLED,  isset($_POST['purge_enabled']) ? 'yes' : 'no');
        update_option(SPP_Purge::OPT_EVERY,    max(1, (int) ($_POST['purge_every_days'] ?? 7)));
        update_option(SPP_Purge::OPT_DAYS,     max(1, (int) ($_POST['purge_days'] ?? 30)));
        update_option(SPP_Purge::OPT_MAXPCT,   min(100, max(1, (int) ($_POST['purge_max_pct'] ?? 40))));

        // --- live stock check ---
        update_option(SPP_LiveCheck::OPT_ENABLED,   isset($_POST['livecheck_enabled']) ? 'yes' : 'no');
        update_option(SPP_LiveCheck::OPT_DAYS,      max(1, (int) ($_POST['livecheck_days'] ?? 3)));
        update_option(SPP_LiveCheck::OPT_PER_RUN,   min(100, max(1, (int) ($_POST['livecheck_per_run'] ?? 5))));
        update_option(SPP_LiveCheck::OPT_EVERY_MIN, max(1, (int) ($_POST['livecheck_every_min'] ?? 15)));
        update_option('spp_checkout_enabled',    isset($_POST['checkout_enabled']) ? 'yes' : 'no');
        update_option('spp_prepaid_discount_on', isset($_POST['prepaid_discount_on']) ? 'yes' : 'no');
        update_option('spp_checkout_discount',   max(0, floatval($_POST['checkout_discount'] ?? 199)));
        update_option('spp_checkout_label',      sanitize_text_field($_POST['checkout_label'] ?? 'Online Payment Discount'));
        update_option('spp_cod_fee_amount',      max(0, floatval($_POST['cod_fee_amount'] ?? 0)));
        update_option('spp_cod_fee_label',       sanitize_text_field($_POST['cod_fee_label'] ?? 'COD Charges'));
        update_option('spp_cod_advance',         max(0, floatval($_POST['cod_advance'] ?? 0)));
        update_option('spp_cod_advance_type',    ($_POST['cod_advance_type'] ?? 'fixed') === 'percentage' ? 'percentage' : 'fixed');
        update_option('spp_cod_gateway_id',      sanitize_text_field($_POST['cod_gateway_id'] ?? 'cod'));
        update_option('spp_upi_gateway_id',      sanitize_text_field($_POST['upi_gateway_id'] ?? 'upi-payment'));
        $cd = isset($_POST['checkout_default']) ? sanitize_text_field($_POST['checkout_default']) : 'none';
        if (!in_array($cd, array('none','cod','prepaid'), true)) $cd = 'none';
        update_option('spp_checkout_default', $cd);

        // --- automatic full resync interval ---
        $fh = isset($_POST['full_resync_hours']) ? (int) $_POST['full_resync_hours'] : 12;
        if ($fh < 0) $fh = 0;
        update_option('spp_full_resync_hours', $fh, false);

        // --- sync intensity (CPU share) ---
        $oldTick = SPP_Sync::tick_seconds();
        if (isset($_POST['tick_seconds'])) {
            update_option('spp_tick_seconds', min(3600, max(60, (int) $_POST['tick_seconds'])), false);
        }
        if (isset($_POST['tick_budget'])) {
            update_option('spp_tick_budget', min(120, max(1, (int) $_POST['tick_budget'])), false);
        }
        // The interval is baked into the registered schedule, so a changed value
        // does nothing until the event is re-armed. Without this the setting looks
        // saved but the old tick keeps firing.
        if (SPP_Sync::tick_seconds() !== $oldTick) {
            wp_clear_scheduled_hook('spp_cron_sync');
            wp_schedule_event(time() + 60, 'spp_minute', 'spp_cron_sync');
        }
        if (!get_option('spp_last_full_resync')) update_option('spp_last_full_resync', time(), false);

        wp_safe_redirect(admin_url('admin.php?page=server-products&saved=1'));
        exit;
    }

    public static function action() {
        check_admin_referer('spp_action');
        if (!current_user_can('manage_woocommerce')) wp_die('Not allowed');

        $do  = sanitize_text_field($_POST['do'] ?? '');
        $msg = '';
        @set_time_limit(60); // give manual batches room so they don't die mid-run

        switch ($do) {
            case 'sync_now':
                $n = SPP_Sync::run_batch(25, 'manual');
                $st = get_option(SPP_OPT_STATUS, array());
                if (!empty($st['last_error']) && $n === 0) {
                    $msg = 'Sync ran but reported: ' . $st['last_error'];
                } else {
                    $msg = 'Sync ran — processed ' . intval($n) . ' product(s) this pass. '
                         . 'Turn on auto-sync to keep pulling the rest automatically.';
                }
                break;
            case 'start':
                update_option(SPP_OPT_AUTOSYNC, 'yes');
                $msg = 'Auto-sync ON — the catalogue will keep importing every minute.';
                break;
            case 'stop':
                update_option(SPP_OPT_AUTOSYNC, 'no');
                $msg = 'Auto-sync OFF.';
                break;
            case 'resync':
                SPP_Sync::start_full_resync('manual');
                update_option(SPP_OPT_AUTOSYNC, 'yes');
                $n = SPP_Sync::run_batch(20, 'full-resync'); // kick off immediately
                $msg = 'Full resync started: re-pulling the whole catalogue from scratch (prices refresh as it goes). Processed ' . intval($n) . ' so far; the rest continues in the background.';
                break;
            case 'remove_all':
                update_option(SPP_OPT_REMOVING, 'yes');
                update_option(SPP_OPT_AUTOSYNC, 'no');
                // do one delete pass immediately so the user sees progress right away
                $d = SPP_Sync::remove_batch(20);
                $left = SPP_Diag::managed_count();
                $msg = $left > 0
                    ? 'Removing products: deleted ' . intval($d) . ' so far, ' . number_format($left) . ' remaining. The rest delete automatically each minute (reload to watch the count fall).'
                    : 'All plugin products removed.';
                break;
            case 'stop_removal':
                update_option(SPP_OPT_REMOVING, 'no');
                $msg = 'Removal stopped.';
                break;
            case 'reprice':
                update_option(SPP_OPT_REPRICE, 'yes');
                update_option(SPP_OPT_REPRICE_AFTER, 0);
                $d = SPP_Sync::reprice_batch(20); // immediate first pass
                $msg = 'Re-pricing all products from the current rules. '
                     . (get_option(SPP_OPT_REPRICE) === 'yes'
                        ? 'In progress — continues in the background.'
                        : 'Done.');
                break;
            case 'renew':
                $r = SPP_API::renew_demo();
                if (!is_wp_error($r)) {
                    update_option(SPP_OPT_AUTOSYNC, 'yes');
                    update_option(SPP_OPT_REMOVING, 'no');
                    $msg = 'Subscription renewed (demo).';
                } else {
                    $msg = 'Renew failed: ' . $r->get_error_message();
                }
                break;
            case 'pay':
                // Ask the SERVER for a pay link (built with the active gateway) and
                // send the browser there. No gateway keys ever touch the plugin.
                $r = SPP_API::pay_start();
                if (!is_wp_error($r) && !empty($r['pay_url'])) {
                    wp_redirect($r['pay_url']); // external gateway URL — not wp_safe_redirect
                    exit;
                }
                $msg = 'Could not start payment: ' . (is_wp_error($r) ? $r->get_error_message() : 'no pay link');
                break;
            case 'clear_error':
                $st = get_option(SPP_OPT_STATUS, array());
                unset($st['last_error'], $st['last_error_at']);
                update_option(SPP_OPT_STATUS, $st, false);
                $msg = 'Cleared the last error.';
                break;
        }

        set_transient('spp_flash_' . get_current_user_id(), $msg, 60);
        wp_safe_redirect(admin_url('admin.php?page=server-products&done=' . urlencode($do)));
        exit;
    }
}
