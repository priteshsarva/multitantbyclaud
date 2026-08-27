<?php
/**
 * Single Product Image (Custom External Images)
 *
 * Compatible with:
 * @version          9.7.0
 * @flatsome-version 3.19.10
 *
 * This version keeps Flatsome’s zoom, slider, and lightbox functionality
 * but replaces default WooCommerce image handling with external URLs
 * from custom fields: 'featuredimg' and 'imageUrl'
 */

use Automattic\WooCommerce\Enums\ProductType;

defined( 'ABSPATH' ) || exit;



global $product;

// === Custom External Images ===
$featured_img = get_post_meta( $product->get_id(), 'featuredimg', true );
$gallery_imgs = get_post_meta( $product->get_id(), 'imageUrl', true );

// Safely decode only if it's a JSON string
if ( !empty($gallery_imgs) && is_string($gallery_imgs) ) {
    $gallery_imgs = json_decode($gallery_imgs, true);
}

// Ensure it's an array
if ( ! is_array( $gallery_imgs ) ) {
    $gallery_imgs = [];
}

// === Fallback ===
$placeholder = wc_placeholder_img_src( 'woocommerce_single' );

// === Columns + Classes ===
$columns         = apply_filters( 'woocommerce_product_thumbnails_columns', 4 );
$post_thumbnail_id = $product->get_image_id();
$wrapper_classes   = apply_filters(
	'woocommerce_single_product_image_gallery_classes',
	array(
		'woocommerce-product-gallery',
		'woocommerce-product-gallery--' . ( $post_thumbnail_id ? 'with-images' : 'without-images' ),
		'woocommerce-product-gallery--columns-' . absint( $columns ),
		'images',
	)
);

$slider_classes = array( 'product-gallery-slider', 'slider', 'slider-nav-small', 'mb-half' );

// === Zoom ===
if ( get_theme_mod( 'product_zoom', 0 ) ) {
	$slider_classes[] = 'has-image-zoom';
}

// === RTL ===
$rtl = is_rtl() ? 'true' : 'false';

// === Slider Type ===
if ( get_theme_mod( 'product_gallery_slider_type' ) === 'fade' ) {
	$slider_classes[] = 'slider-type-fade';
}

// === Lightbox ===
if ( get_theme_mod( 'product_lightbox', 'default' ) == 'disabled' ) {
	$slider_classes[] = 'disable-lightbox';
}
?>

<?php do_action( 'flatsome_before_product_images' ); ?>

<div class="product-images relative mb-half has-hover <?php echo esc_attr( implode( ' ', array_map( 'sanitize_html_class', $wrapper_classes ) ) ); ?>" data-columns="<?php echo esc_attr( $columns ); ?>">

	<?php do_action( 'flatsome_sale_flash' ); ?>

	<div class="image-tools absolute top show-on-hover right z-3">
		<?php do_action( 'flatsome_product_image_tools_top' ); ?>
	</div>

	<div class="woocommerce-product-gallery__wrapper <?php echo esc_attr( implode( ' ', $slider_classes ) ); ?>"
		data-flickity-options='{
			"cellAlign": "center",
			"wrapAround": true,
			"autoPlay": false,
			"prevNextButtons": true,
			"adaptiveHeight": true,
			"imagesLoaded": true,
			"lazyLoad": 1,
			"dragThreshold": 15,
			"pageDots": false,
			"rightToLeft": <?php echo $rtl; ?>
		}'>

		<?php
// === FEATURED IMAGE ===
if ( $featured_img ) {
	echo '<div class="woocommerce-product-gallery__image woocommerce-product-gallery__image--featured">';
	echo '<a href="' . esc_url( $featured_img ) . '" class="woocommerce-main-image" data-rel="photoSwipe[product-gallery]" data-index="0">';
	echo '<img src="' . esc_url( $featured_img ) . '" alt="' . esc_attr( get_the_title() ) . '" class="wp-post-image" data-large_image="' . esc_url( $featured_img ) . '" data-large_image_width="1000" data-large_image_height="1000" />';
	echo '</a></div>';
	
	// === GALLERY IMAGES ===
if ( ! empty( $gallery_imgs ) ) {
	$index = 1;
	foreach ( $gallery_imgs as $img_url ) {
		echo '<div class="woocommerce-product-gallery__image">';
		echo '<a href="' . esc_url( $img_url ) . '" class="woocommerce-gallery-image" data-rel="photoSwipe[product-gallery]" data-index="' . esc_attr( $index ) . '">';
		echo '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( get_the_title() ) . '" data-large_image="' . esc_url( $img_url ) . '" data-large_image_width="1000" data-large_image_height="1000" />';
		echo '</a></div>';
		$index++;
	}
}
} else {
	
	 if ( $post_thumbnail_id ) {
      $html  = flatsome_wc_get_gallery_image_html( $post_thumbnail_id, true );
    } else {
		$wrapper_classname = $product->is_type( fl_woocommerce_version_check( '9.7.0' ) ? ProductType::VARIABLE : 'variable' ) && ! empty( $product->get_available_variations( 'image' ) ) ?
			'woocommerce-product-gallery__image woocommerce-product-gallery__image--placeholder' :
			'woocommerce-product-gallery__image--placeholder';
		$html              = sprintf( '<div class="%s">', esc_attr( $wrapper_classname ) );
		$html             .= sprintf( '<img src="%s" alt="%s" class="wp-post-image" />', esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ), esc_html__( 'Awaiting product image', 'woocommerce' ) );
		$html             .= '</div>';
    }

		echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', $html, $post_thumbnail_id ); // phpcs:disable WordPress.XSS.EscapeOutput.OutputNotEscaped

    do_action( 'woocommerce_product_thumbnails' );
}


?>



	</div>

	<div class="image-tools absolute bottom left z-3">
		<?php do_action( 'flatsome_product_image_tools_bottom' ); ?>
	</div>
</div>

<?php do_action( 'flatsome_after_product_images' ); ?>

<?php wc_get_template( 'single-product/product-gallery-thumbnails.php' ); ?>

