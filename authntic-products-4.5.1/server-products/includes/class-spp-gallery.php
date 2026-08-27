<?php
if (!defined('ABSPATH')) exit;

/**
 * Flickity single-product gallery for managed products, rendered from the
 * stored image-URL meta (imageUrl JSON array + featuredimg) instead of
 * WooCommerce attachments — so no 100k-image import is needed.
 *
 * WHY THIS EXISTS SEPARATELY FROM SPP_Display:
 * SPP_Display emits standard WooCommerce gallery markup
 * (.woocommerce-product-gallery__image). The flatsome-child theme replaced the
 * single-product image template with a FLICKITY structure
 * (.product-gallery-slider + .product-thumbnails, synced via asNavFor, driven by
 * js/custom-gallery.js). Those two structures collide. This module makes the
 * PLUGIN emit that Flickity markup so the child-theme template can be DELETED,
 * making the gallery theme-independent.
 *
 * TRANSITION SAFETY:
 * Controlled by the `spp_flickity_gallery` option (Settings → Authntic Products).
 * OFF by default. While the child-theme product-image.php still exists, leaving
 * this OFF avoids a double gallery. Turn it ON only after deleting:
 *   flatsome-child/woocommerce/single-product/product-image.php
 *   flatsome-child/woocommerce/single-product/product-gallery-thumbnails.php
 *   flatsome-child/js/custom-gallery.js  (enqueue)
 * When ON, this fully replaces WooCommerce's default single-product gallery
 * output for managed products.
 */
class SPP_Gallery {

    const OPT_ENABLED = 'spp_flickity_gallery';

    /** rendered once per request, whichever path got there first */
    private static $rendered = false;
    /** which route produced the gallery — surfaced by debug_comment() */
    private static $path = '';

    /**
     * RETIRED as of 4.7.0 — deliberately does nothing.
     *
     * This module used to render its own Flickity gallery and force it into the
     * page by swapping the theme's product-image template. That fought the theme
     * (two galleries, template-loader guesswork, "toggle does nothing" reports).
     *
     * SPP_Display now exposes the images as VIRTUAL ATTACHMENTS, so the theme's
     * OWN native gallery renders every image with its built-in zoom, lightbox and
     * thumbnails — no template swap, no second slider. The OPT_ENABLED toggle
     * still exists and still means "show the full multi-image gallery"; it is now
     * read by SPP_Display::inject_gallery_ids(). The old render/template/asset
     * methods below are kept only so nothing that references them can fatal.
     */
    public static function init() {
        // intentionally empty — see the class comment
    }

    /** wc_get_template_part('single-product/product-image') — note: no .php, no $name */
    public static function locate_part($template, $slug, $name = '') {
        $want = $name ? "{$slug}-{$name}" : $slug;
        if ($want !== 'single-product/product-image') return $template;
        if (!self::current_is_ours()) return $template;
        $file = SPP_DIR . 'templates/single-product/product-image.php';
        if (!file_exists($file)) return $template;
        self::$path = 'template_part';
        return $file;
    }

    /**
     * One HTML comment in the footer of a single-product page saying exactly what
     * this module decided and why. Costs nothing, and turns "it doesn't work" into
     * a single line you can read in View Source.
     */
    public static function debug_comment() {
        if (!function_exists('is_product') || !is_product()) return;
        global $product;
        $pid     = ($product && is_object($product) && method_exists($product, 'get_id')) ? $product->get_id() : 0;
        $managed = $pid ? get_post_meta($pid, SPP_MANAGED, true) : '';
        $imgs    = $pid ? self::images($pid) : array();
        printf(
            "\n<!-- SPP-GALLERY enabled=%s product=%d managed=%s images=%d rendered=%s path=%s dir=%s template=%s -->\n",
            get_option(self::OPT_ENABLED) === 'yes' ? 'yes' : 'NO',
            (int) $pid,
            $managed === '1' ? 'yes' : 'NO(' . esc_html((string) $managed) . ')',
            count($imgs),
            self::$rendered ? 'yes' : 'NO',
            self::$path !== '' ? esc_html(self::$path) : 'none',
            esc_html(SPP_DIR),
            file_exists(SPP_DIR . 'templates/single-product/product-image.php') ? 'found' : 'MISSING'
        );
    }

    /** is the product being rendered right now one we should take over? */
    private static function current_is_ours() {
        global $product;
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) return false;
        $pid = $product->get_id();
        return self::is_managed($pid) && self::images($pid);
    }

    /** swap the theme's product-image template for ours, managed products only */
    public static function locate_template($template, $template_name) {
        if ($template_name !== 'single-product/product-image.php') return $template;
        if (!self::current_is_ours()) return $template;
        $file = SPP_DIR . 'templates/single-product/product-image.php';
        if (!file_exists($file)) return $template;
        self::$path = 'locate_template';
        return $file;
    }

    /** the one entry point both paths funnel through */
    public static function render_current() {
        if (self::$rendered) return;
        global $product;
        if (!self::current_is_ours()) return;
        $pid  = $product->get_id();
        $imgs = self::images($pid);
        self::$rendered = true;
        self::render($imgs, self::video($pid), $product->get_name());
    }

    private static function is_managed($pid) {
        return $pid && SPP_Product::is_managed($pid);
    }

    /** image URLs: imageUrl JSON array, else the single featuredimg */
    private static function images($pid) {
        $raw  = get_post_meta($pid, 'imageUrl', true);
        $imgs = [];
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) $imgs = $d;
        }
        if (empty($imgs)) {
            $f = (string) get_post_meta($pid, 'featuredimg', true);
            if ($f !== '') $imgs = [$f];
        }
        return array_values(array_filter(array_map('strval', $imgs)));
    }

    private static function video($pid) {
        return (string) get_post_meta($pid, 'videoUrl', true);
    }

    public static function maybe_render() {
        if (!self::current_is_ours()) return;

        // Stop WooCommerce's default gallery from ALSO printing for this product.
        // (No-op on Flatsome, which never registered it — see init() PATH 2.)
        remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);

        if (self::$path === '') self::$path = 'action';
        self::render_current();
    }

    /**
     * Full gallery output, matched against the ACTUAL child-theme templates
     * (single-product/product-image.php + product-gallery-thumbnails.php) rather
     * than inferred from custom-gallery.js as the first version was.
     *
     * That comparison turned up four things the inferred markup dropped, all of
     * which are visible losses once the child theme is deleted:
     *   1. flatsome_sale_flash          — the sale badge
     *   2. flatsome_product_image_tools_top/bottom — wishlist, share, zoom icons
     *   3. the photoSwipe anchors       — clicking an image opened the lightbox
     *   4. Flatsome's wrapper/slider classes — .product-images, zoom + lightbox
     *                                     modifiers, and the thumbnail row grid
     */
    private static function render($imgs, $video, $name) {
        $alt     = esc_attr($name);
        $columns = (int) apply_filters('woocommerce_product_thumbnails_columns', 4);
        $rtl     = is_rtl() ? 'true' : 'false';

        $slider = array('product-gallery-slider', 'slider', 'slider-nav-small', 'mb-half');
        if (get_theme_mod('product_zoom', 0))                          $slider[] = 'has-image-zoom';
        if (get_theme_mod('product_gallery_slider_type') === 'fade')    $slider[] = 'slider-type-fade';
        if (get_theme_mod('product_lightbox', 'default') === 'disabled') $slider[] = 'disable-lightbox';

        do_action('flatsome_before_product_images');
        ?>
        <div class="product-images relative mb-half has-hover spp-gallery-wrap woocommerce-product-gallery woocommerce-product-gallery--with-images woocommerce-product-gallery--columns-<?php echo esc_attr($columns); ?> images"
             data-columns="<?php echo esc_attr($columns); ?>">

          <?php do_action('flatsome_sale_flash'); ?>

          <div class="image-tools absolute top show-on-hover right z-3">
            <?php do_action('flatsome_product_image_tools_top'); ?>
          </div>

          <div class="woocommerce-product-gallery__wrapper <?php echo esc_attr(implode(' ', $slider)); ?>"
               data-spp-flickity="main"
               data-flickity-options='{
                 "cellAlign": "center", "wrapAround": true, "autoPlay": false,
                 "prevNextButtons": true, "adaptiveHeight": true, "imagesLoaded": true,
                 "lazyLoad": 1, "dragThreshold": 15, "pageDots": false,
                 "rightToLeft": <?php echo $rtl; ?>
               }'>
            <?php foreach ($imgs as $i => $src): ?>
              <div class="woocommerce-product-gallery__image product-gallery-cell<?php echo $i === 0 ? ' woocommerce-product-gallery__image--featured' : ''; ?>">
                <a href="<?php echo esc_url($src); ?>"
                   class="<?php echo $i === 0 ? 'woocommerce-main-image' : 'woocommerce-gallery-image'; ?>"
                   data-rel="photoSwipe[product-gallery]" data-index="<?php echo esc_attr($i); ?>">
                  <img src="<?php echo esc_url($src); ?>" alt="<?php echo $alt; ?>"
                       class="<?php echo $i === 0 ? 'wp-post-image' : ''; ?>"
                       data-large_image="<?php echo esc_url($src); ?>"
                       data-large_image_width="1000" data-large_image_height="1000" />
                </a>
              </div>
            <?php endforeach; ?>
            <?php if ($video !== ''): ?>
              <div class="woocommerce-product-gallery__image product-gallery-cell product-gallery-video">
                <video controls preload="metadata" playsinline src="<?php echo esc_url($video); ?>"></video>
              </div>
            <?php endif; ?>
          </div>

          <div class="image-tools absolute bottom left z-3">
            <?php do_action('flatsome_product_image_tools_bottom'); ?>
          </div>
        </div>
        <?php
        do_action('flatsome_after_product_images');

        self::thumbnails($imgs, $video, $alt, $rtl);
    }

    /** thumbnail strip — Flatsome's row/col grid, asNavFor the main slider */
    private static function thumbnails($imgs, $video, $alt, $rtl) {
        $count = count($imgs) + ($video !== '' ? 1 : 0);
        if ($count < 2) return;   // a single image needs no nav

        $classes = array('product-thumbnails', 'thumbnails', 'slider', 'row', 'row-small',
                         'row-slider', 'slider-nav-small', 'small-columns-4');
        if ($count < 5) $classes[] = 'slider-no-arrows';
        $classes = apply_filters('flatsome_single_product_thumbnails_classes', $classes);

        // same sizing rule the child theme used, so thumbs keep their dimensions
        $size  = 'thumbnail';
        $check = wc_get_image_size('gallery_thumbnail');
        if (isset($check['width']) && $check['width'] !== 100) $size = 'gallery_thumbnail';
        $dim = wc_get_image_size(apply_filters('woocommerce_gallery_thumbnail_size', 'woocommerce_' . $size));
        $w   = isset($dim['width'])  ? (int) $dim['width']  : 100;
        $h   = isset($dim['height']) ? (int) $dim['height'] : 100;
        $align = is_rtl() ? 'right' : 'left';
        ?>
        <div class="<?php echo esc_attr(implode(' ', array_map('sanitize_html_class', $classes))); ?>"
             data-spp-flickity="thumbs"
             data-flickity-options='{
               "cellAlign": "<?php echo esc_attr($align); ?>", "wrapAround": false, "autoPlay": false,
               "prevNextButtons": true, "asNavFor": ".product-gallery-slider",
               "percentPosition": true, "imagesLoaded": true, "pageDots": false,
               "rightToLeft": <?php echo $rtl; ?>, "contain": true
             }'>
          <?php foreach ($imgs as $i => $src): ?>
            <?php // data-index on BOTH col and anchor: the child theme's JS read it
                  // from .col, the template only put it on the <a>. ?>
            <div class="col<?php echo $i === 0 ? ' is-nav-selected first' : ''; ?>" data-index="<?php echo esc_attr($i); ?>">
              <a data-index="<?php echo esc_attr($i); ?>">
                <img src="<?php echo esc_url($src); ?>" alt="<?php echo $alt; ?>"
                     width="<?php echo esc_attr($w); ?>" height="<?php echo esc_attr($h); ?>"
                     class="attachment-woocommerce_thumbnail" loading="lazy" />
              </a>
            </div>
          <?php endforeach; ?>
          <?php if ($video !== ''): $vi = count($imgs); ?>
            <div class="col spp-thumb-video" data-index="<?php echo esc_attr($vi); ?>">
              <a data-index="<?php echo esc_attr($vi); ?>"><span class="spp-play">&#9658;</span></a>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }


    public static function assets() {
        if (!is_product()) return;

        // Flatsome bundles Flickity; enqueue defensively in case a build strips it.
        if (!wp_script_is('flickity', 'registered') && !wp_script_is('flickity', 'enqueued')) {
            wp_enqueue_script(
                'flickity',
                'https://unpkg.com/flickity@2/dist/flickity.pkgd.min.js',
                ['jquery'], '2.3.0', true
            );
            wp_enqueue_style(
                'flickity',
                'https://unpkg.com/flickity@2/dist/flickity.min.css',
                [], '2.3.0'
            );
        }

        // Ported from flatsome-child/js/custom-gallery.js, hardened so it only
        // binds when the plugin gallery is on the page.
        // The markup now carries data-flickity-options and Flatsome's .slider class,
        // exactly like the child-theme template did — which means FLATSOME may
        // already have initialised these sliders by the time this runs. So work
        // through Flickity.data() and only initialise what nobody else has, or the
        // gallery gets built twice and the thumbnails desync.
        $js = <<<'JS'
jQuery(function($){
  var $main = $('.product-gallery-slider[data-spp-flickity="main"]');
  if(!$main.length || typeof window.Flickity === 'undefined') return;

  function inst(node, opts){
    var i = Flickity.data(node);
    if(!i && opts) { try { i = new Flickity(node, opts); } catch(e){ return null; } }
    return i || null;
  }

  var main = inst($main[0], {
    cellAlign:'center', wrapAround:true, prevNextButtons:true,
    pageDots:false, adaptiveHeight:true, imagesLoaded:true, lazyLoad:1
  });
  if(!main) return;

  var $t = $('.product-thumbnails[data-spp-flickity="thumbs"]');
  if($t.length){
    inst($t[0], {
      asNavFor:'.product-gallery-slider[data-spp-flickity="main"]',
      contain:true, pageDots:false, prevNextButtons:true,
      cellAlign:'left', percentPosition:true, imagesLoaded:true
    });
    $t.on('click', '.col', function(){
      var i = $(this).data('index');
      if(i === undefined) i = $(this).find('a').data('index');
      if(i !== undefined){
        main.select(i);
        $t.find('.col').removeClass('is-nav-selected');
        $(this).addClass('is-nav-selected');
      }
    });
    main.on('change', function(i){
      $t.find('.col').removeClass('is-nav-selected');
      $t.find('.col[data-index="'+i+'"]').addClass('is-nav-selected');
    });
  }

  // pause any gallery video when the slide changes away from it
  main.on('change', function(){
    $main.find('video').each(function(){ this.pause && this.pause(); });
  });

  // A <video> reports 0px until its metadata loads, which makes adaptiveHeight
  // collapse the cell. Re-fit the slider once the real dimensions are known.
  $main.find('video').on('loadedmetadata loadeddata', function(){
    try { main.resize(); } catch(e){}
  });
  // Also re-fit after all images load (lazyLoad reports late on first paint).
  $(window).on('load', function(){ try { main.resize(); } catch(e){} });
});
JS;
        wp_add_inline_script('flickity', $js);

        $css = '.spp-gallery-wrap .product-gallery-cell{width:100%}'
             . '.spp-gallery-wrap .product-gallery-cell img{width:100%;display:block}'
             // Video: fill the cell width but cap height to the slider so a tall/
             // portrait video (or a not-yet-loaded one reporting 0px) can never
             // shrink the cell. object-fit keeps the aspect ratio inside that box.
             . '.spp-gallery-wrap .product-gallery-video{display:flex;align-items:center;justify-content:center;background:#000}'
             . '.spp-gallery-wrap .product-gallery-cell video{width:100%;max-height:70vh;height:auto;display:block;object-fit:contain;background:#000}'
             . '.spp-gallery-wrap .product-thumbnails .col{width:80px;margin-right:8px;cursor:pointer;opacity:.6;transition:opacity .15s}'
             . '.spp-gallery-wrap .product-thumbnails .col.is-nav-selected{opacity:1}'
             . '.spp-gallery-wrap .product-thumbnails .col img{width:100%;display:block}'
             . '.spp-gallery-wrap .spp-thumb-video{display:flex;align-items:center;justify-content:center;background:#111;color:#fff;height:80px}';
        wp_register_style('spp-gallery-inline', false);
        wp_enqueue_style('spp-gallery-inline');
        wp_add_inline_style('spp-gallery-inline', $css);
    }
}
