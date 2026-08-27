<?php
/**
 * Quick View - Child Theme External Images
 *
 * @package          Flatsome/WooCommerce/Templates
 * @flatsome-version 3.19.7
 */

defined( 'ABSPATH' ) || exit;

global $post, $product;

if ( post_password_required() ) {
	echo '<div class="product-quick-view-container inner-padding">';
	echo get_the_password_form(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
	return;
}

do_action( 'flatsome_before_single_product_lightbox' );
do_action_deprecated( 'wc_quick_view_before_single_product', array(), '3.18.0', 'flatsome_before_single_product_lightbox' );
?>
<div class="product-quick-view-container">
	<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'row row-collapse mb-0', $product ); ?>>

		<div class="product-gallery large-6 col">
			<div class="slider slider-show-nav product-gallery-slider main-images mb-0">

				<?php
				// =============================
				// NEW: External Images Code
				// =============================

				$featured_img_url = get_post_meta($product->get_id(), 'featuredimg', true);
				$gallery_images = get_post_meta($product->get_id(), 'imageUrl', true);
// 				$gallery_images = json_decode($gallery_images, true);
				if ( ! empty( $gallery_images_raw ) && is_string( $gallery_images_raw ) ) {
    $gallery_images = json_decode( $gallery_images_raw, true );
}

				if ($featured_img_url) {
					echo '<div class="slide first">';
					echo '<img loading="lazy" decoding="async" src="' . esc_url($featured_img_url) . '" alt="' . esc_attr($product->get_title()) . '" />';
					echo '</div>';
				} else {
					// fallback placeholder
					echo '<div class="slide first">';
					echo '<img src="' . wc_placeholder_img_src('woocommerce_single') . '" alt="' . esc_attr__('Awaiting product image', 'woocommerce') . '" />';
					echo '</div>';
				}

				if (is_array($gallery_images) && !empty($gallery_images)) {
					foreach ($gallery_images as $img_url) {
						if ($img_url) {
							echo '<div class="slide">';
							echo '<img loading="lazy" decoding="async" src="' . esc_url($img_url) . '" alt="' . esc_attr($product->get_title()) . '" />';
							echo '</div>';
						}
					}
				}

				/*
				// =============================
				// OLD CODE COMMENTED
				// =============================
				if ( has_post_thumbnail() ) :

					$image_title = esc_attr( get_the_title( get_post_thumbnail_id() ) );
					$image_link  = wp_get_attachment_url( get_post_thumbnail_id() );
					$image       = get_the_post_thumbnail( $post->ID, apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' ), array(
						'title' => $image_title,
					) );

					echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', sprintf( '<div class="slide first">%s</div>', $image ), $post->ID );

					$attachment_ids = $product->get_gallery_image_ids();
					if ( $attachment_ids ) {
						foreach ( $attachment_ids as $attachment_id ) {
							$image_title = esc_attr( get_the_title( $attachment_id ) );
							$image = wp_get_attachment_image( $attachment_id, apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' ), array(
								'title' => $image_title,
								'alt'   => $image_title,
							) );
							echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', sprintf( '<div class="slide">%s</div>', $image ), $attachment_id );
						}
					}
				else :
					echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', sprintf( '<img src="%s" alt="%s" />', wc_placeholder_img_src( 'woocommerce_single' ), esc_html__( 'Awaiting product image', 'woocommerce' ) ), $post->ID );
				endif;
				*/
				?>
			</div>

			<?php do_action( 'flatsome_single_product_lightbox_product_gallery' ); ?>
			<?php do_action_deprecated( 'woocommerce_before_single_product_lightbox_summary', array(), '3.18.0', 'flatsome_single_product_lightbox_product_gallery' ); ?>
		</div>

		<div class="product-info summary large-6 col entry-summary" style="font-size:90%;">
			<div class="product-lightbox-inner" style="padding: 30px;">
				<a class="plain" href="<?php the_permalink(); ?>"><h1><?php 				
// 					the_title(); 
				
	$title = get_the_title();
$clean_title = ucwords(str_replace("_", " ", ltrim($title, "_"))); // remove leading underscore, replace _ with space, capitalize
echo esc_html($clean_title);
	
					?></h1></a>
				<div class="is-divider small"></div>

				<?php do_action( 'flatsome_single_product_lightbox_summary' ); ?>
				<?php do_action_deprecated( 'woocommerce_single_product_lightbox_summary', array(), '3.18.0', 'flatsome_single_product_lightbox_summary' ); ?>
			</div>
		</div>
	</div>
</div>

<?php
do_action( 'flatsome_after_single_product_lightbox' );
do_action_deprecated( 'wc_quick_view_after_single_product', array(), '3.18.0', 'flatsome_after_single_product_lightbox' );
?>
