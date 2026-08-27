<?php
/**
 * Product gallery thumbnails.
 *
 * @package          Flatsome/WooCommerce/Templates
 * @flatsome-version 3.20.8
 */

global $post, $product;

$attachment_ids = $product->get_gallery_image_ids();
$post_thumbnail = has_post_thumbnail();
$thumb_count    = count( $attachment_ids );

if ( $post_thumbnail ) $thumb_count++;
$render_without_attachments = apply_filters( 'flatsome_single_product_thumbnails_render_without_attachments', false, $product, array( 'thumb_count' => $thumb_count ) );

// Disable thumbnails if there is only one extra image.
if ( $post_thumbnail && $thumb_count == 1 && ! $render_without_attachments ) {
	return;
}

$rtl              = 'false';
$thumb_cell_align = 'left';

if ( is_rtl() ) {
	$rtl              = 'true';
	$thumb_cell_align = 'right';
}

if ( $attachment_ids || $render_without_attachments ) {
	$loop          = 0;
	$image_size    = 'thumbnail';
	$gallery_class = array( 'product-thumbnails', 'thumbnails' );

	// Check if custom gallery thumbnail size is set and use that.
	$image_check = wc_get_image_size( 'gallery_thumbnail' );
	if ( $image_check['width'] !== 100 ) {
		$image_size = 'gallery_thumbnail';
	}

	$gallery_thumbnail = wc_get_image_size( apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );

	if ( $thumb_count < 5 ) {
		$gallery_class[] = 'slider-no-arrows';
	}

	$gallery_class[] = 'slider row row-small row-slider slider-nav-small small-columns-4';
	$gallery_class   = apply_filters( 'flatsome_single_product_thumbnails_classes', $gallery_class );
	?>
	<div class="<?php echo esc_attr( implode( ' ', $gallery_class ) ); ?>"
		data-flickity-options='{
			"cellAlign": "<?php echo esc_attr( $thumb_cell_align ); ?>",
			"wrapAround": false,
			"autoPlay": false,
			"prevNextButtons": true,
			"asNavFor": ".product-gallery-slider",
			"percentPosition": true,
			"imagesLoaded": true,
			"pageDots": false,
			"rightToLeft": <?php echo esc_attr( $rtl ); ?>,
			"contain": true
		}'>
		<?php


		if ( $post_thumbnail ) :
			?>
			<div class="col is-nav-selected first">
				<a>
					<?php
					$image_id  = get_post_thumbnail_id( $post->ID );
					$image_alt = trim( wp_strip_all_tags( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) );
					if ( fl_woocommerce_version_check( '9.3.3' ) && function_exists( 'woocommerce_get_alt_from_product_title_and_position' ) && $product ) {
						$image_alt = empty( $image_alt ) ? woocommerce_get_alt_from_product_title_and_position( $product->get_title(), false, -1 ) : $image_alt;
					}
					echo wp_get_attachment_image( $image_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ), false, array( 'alt' => esc_attr( $image_alt ) ) );
					?>
				</a>
			</div>
		<?php
		endif;
		foreach ( $attachment_ids as $attachment_id ) {

			$classes     = array( '' );
			$image_class = esc_attr( implode( ' ', $classes ) );
			$image       = wp_get_attachment_image_src( $attachment_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );

			if ( empty( $image ) ) {
				continue;
			}

			$image_alt = trim( wp_strip_all_tags( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );
			if ( fl_woocommerce_version_check( '9.3.3' ) && function_exists( 'woocommerce_get_alt_from_product_title_and_position' ) && $product ) {
				$image_alt = empty( $image_alt ) ? woocommerce_get_alt_from_product_title_and_position( $product->get_title(), false, $loop ) : $image_alt;
			}
			echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', sprintf( '<div class="col"><a>%s</a></div>', wp_get_attachment_image( $attachment_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ), false, array( 'alt' => esc_attr( $image_alt ) ) ) ), $attachment_id, $post->ID, $image_class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$loop++;
		}
		?>
	</div>
	<?php
}
