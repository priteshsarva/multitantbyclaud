<?php
/**
 * Substituted for the theme's own single-product/product-image.php, but ONLY for
 * plugin-managed products that have image URLs, and only while the built-in
 * gallery setting is on. Everything else keeps the theme's template untouched —
 * see SPP_Gallery::locate_template().
 *
 * This exists because Flatsome renders product images by loading this template
 * from its layout part, not through woocommerce_before_single_product_summary.
 * Hooking that action alone makes the gallery silently never appear.
 *
 * Deliberately thin: all markup lives in SPP_Gallery so there is one source of
 * truth whichever route reaches it.
 */

defined('ABSPATH') || exit;

if (class_exists('SPP_Gallery')) {
    SPP_Gallery::render_current();
}
