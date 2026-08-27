<?php
/**
 * Product gallery thumbnails (Merged: External + Default)
 *
 * Uses external URLs from custom fields 'featuredimg' and 'imageUrl' when available,
 * otherwise falls back to the default WooCommerce gallery.
 *
 * @package          Flatsome/WooCommerce/Templates
 * @flatsome-version 3.19.10
 */

defined( 'ABSPATH' ) || exit;

global $post, $product;

// === External Image Logic ===
$featured_img = get_post_meta( $product->get_id(), 'featuredimg', true );
$gallery_imgs = get_post_meta( $product->get_id(), 'imageUrl', true );

if ( ! empty( $gallery_imgs ) && is_string( $gallery_imgs ) ) {
	$gallery_imgs = json_decode( $gallery_imgs, true );
}

$external_imgs = [];
if ( $featured_img ) $external_imgs[] = $featured_img;
if ( is_array( $gallery_imgs ) ) $external_imgs = array_merge( $external_imgs, $gallery_imgs );

// === Fallback ===
$attachment_ids = $product->get_gallery_image_ids();
$post_thumbnail = has_post_thumbnail();
$thumb_count    = count( $attachment_ids );
if ( $post_thumbnail ) $thumb_count++;

$render_without_attachments = apply_filters( 'flatsome_single_product_thumbnails_render_without_attachments', false, $product, [ 'thumb_count' => $thumb_count ] );

// === Disable thumbnails if single image ===
if ( $post_thumbnail && $thumb_count == 1 && ! $render_without_attachments && empty( $external_imgs ) ) {
	return;
}

// === Slider Settings ===
$rtl              = is_rtl() ? 'true' : 'false';
$thumb_cell_align = is_rtl() ? 'right' : 'left';

$gallery_class = [ 'product-thumbnails', 'thumbnails', 'slider', 'row', 'row-small', 'row-slider', 'slider-nav-small', 'small-columns-4' ];
if ( $thumb_count < 5 ) $gallery_class[] = 'slider-no-arrows';
$gallery_class = apply_filters( 'flatsome_single_product_thumbnails_classes', $gallery_class );

// === Thumbnail size ===
$image_size = 'thumbnail';
$image_check = wc_get_image_size( 'gallery_thumbnail' );
if ( $image_check['width'] !== 100 ) {
	$image_size = 'gallery_thumbnail';
}
$gallery_thumbnail = wc_get_image_size( apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );

?>

<div class="<?php echo implode( ' ', $gallery_class ); ?>"
	data-flickity-options='{
		"cellAlign": "<?php echo $thumb_cell_align; ?>",
		"wrapAround": false,
		"autoPlay": false,
		"prevNextButtons": true,
		"asNavFor": ".product-gallery-slider",
		"percentPosition": true,
		"imagesLoaded": true,
		"pageDots": false,
		"rightToLeft": <?php echo $rtl; ?>,
		"contain": true
	}'>

	<?php
	// === CASE 1: External Images Exist ===
	if ( ! empty( $external_imgs ) ) :
		foreach ( $external_imgs as $index => $img_url ) :
			?>
			<div class="col <?php echo $index === 0 ? 'is-nav-selected first' : ''; ?>">
				<a data-index="<?php echo esc_attr( $index ); ?>">
					<img src="<?php echo esc_url( $img_url ); ?>"
						 alt="<?php echo esc_attr( get_the_title() ); ?>"
						 width="<?php echo esc_attr( $gallery_thumbnail['width'] ); ?>"
						 height="<?php echo esc_attr( $gallery_thumbnail['height'] ); ?>"
						 class="attachment-woocommerce_thumbnail" />
				</a>
			</div>
			<?php
		endforeach;

	// === CASE 2: Fallback to Default WooCommerce Thumbnails ===
	elseif ( $attachment_ids || $render_without_attachments ) :

		if ( $post_thumbnail ) :
			?>
			<div class="col is-nav-selected first">
				<a>
					<?php
					$image_id  = get_post_thumbnail_id( $post->ID );
					$image     = wp_get_attachment_image_src( $image_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );
					$image_alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
					if ( ! empty( $image ) ) {
						printf(
							'<img src="%s" alt="%s" width="%d" height="%d" class="attachment-woocommerce_thumbnail" />',
							esc_url( $image[0] ),
							esc_attr( $image_alt ),
							esc_attr( $gallery_thumbnail['width'] ),
							esc_attr( $gallery_thumbnail['height'] )
						);
					}
					?>
				</a>
			</div>
			<?php
		endif;

		foreach ( $attachment_ids as $attachment_id ) :
			$image = wp_get_attachment_image_src( $attachment_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );
			if ( empty( $image ) ) continue;
			$image_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			?>
			<div class="col">
				<a>
					<img src="<?php echo esc_url( $image[0] ); ?>"
						 alt="<?php echo esc_attr( $image_alt ); ?>"
						 width="<?php echo esc_attr( $gallery_thumbnail['width'] ); ?>"
						 height="<?php echo esc_attr( $gallery_thumbnail['height'] ); ?>"
						 class="attachment-woocommerce_thumbnail" />
				</a>
			</div>
			<?php
		endforeach;

	endif;
	?>
</div>
