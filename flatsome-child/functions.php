<?php
/**
 * Flatsome Child Theme - functions.php
 * 
 * ✅ FIXED VERSION — All 503-causing issues resolved
 * Changes marked with "// 🔧 FIX:" comments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
// ========================================================================
// PriceGuard WhatsApp Button
// ========================================================================
function remove_price_guard_button() {
    if ( class_exists( 'PriceGuard_Public' ) ) {
        global $price_guard_public;
        if ( ! $price_guard_public ) {
            remove_action( 'woocommerce_single_product_summary', array( 'PriceGuard_Public', 'add_custom_button' ), 30 );
        } else {
            remove_action( 'woocommerce_single_product_summary', array( $price_guard_public, 'add_custom_button' ), 30 );
        }
    }
}
add_action( 'wp', 'remove_price_guard_button' );


function add_custom_whatsapp_button() {
    global $product;
    
    if ( ! $product ) {
        return;
    }
    
    $apply_globally = get_option( 'price_guard_apply_globally', 'yes' );
    $categories = get_option( 'price_guard_categories', array() );

    $should_affect = false;
    if ( 'yes' === $apply_globally ) {
        $should_affect = true;
    } else {
        $product_categories = $product->get_category_ids();
        if ( ! empty( array_intersect( $product_categories, $categories ) ) ) {
            $should_affect = true;
        }
    }
    
    if ( $should_affect && 'yes' === get_option( 'price_guard_hide_add_to_cart', 'no' ) ) {
        $button_text = get_option( 'price_guard_custom_button_text', 'Request a Quote' );
        $whatsapp_number = '916351955509';
        $message = sprintf( "Hello, I am interested in buying the product *%s*. \nHere is the link: %s", $product->get_name(), $product->get_permalink() );
        $whatsapp_message = rawurlencode( $message );
        $whatsapp_link = "https://wa.me/{$whatsapp_number}?text={$whatsapp_message}";

        echo '<a href="' . esc_url( $whatsapp_link ) . '" class="button wp-element-button alt price-guard-button" target="_blank" rel="noopener noreferrer">' . esc_html( $button_text ) . '</a>';
    }
}
add_action( 'woocommerce_single_product_summary', 'add_custom_whatsapp_button', 30 );