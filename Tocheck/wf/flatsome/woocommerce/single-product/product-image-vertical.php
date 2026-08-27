<?php
/**
 * Single Product Image
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/product-image.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see              https://woocommerce.com/document/template-structure/
 * @package          WooCommerce\Templates
 * @version          10.5.0
 * @flatsome-version 3.20.8
 */

use Automattic\WooCommerce\Enums\ProductType;

defined( 'ABSPATH' ) || exit;

// FL: Disable check, Note: `wc_get_gallery_image_html` was added in WC 3.3.2 and did not exist prior. This check protects against theme overrides being used on older versions of WC.
//if ( ! function_exists( 'wc_get_gallery_image_html' ) ) {
//	return;
//}

global $product;

$columns           = apply_filters( 'woocommerce_product_thumbnails_columns', 4 );
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

$slider_classes = array('product-gallery-slider','slider','slider-nav-small','mb-0');
$rtl = 'false';
if(is_rtl()) $rtl = 'true';

if ( get_theme_mod( 'product_gallery_slider_type' ) === 'fade' ) {
	$slider_classes[] = 'slider-type-fade';
}

// Image Zoom
if(get_theme_mod('product_zoom', 0)){
  $slider_classes[] = 'has-image-zoom';
}

?>
<div class="row row-small">
<div class="col large-10">
<?php do_action('flatsome_before_product_images'); ?>

<div class="<?php echo esc_attr( implode( ' ', array_map( 'sanitize_html_class', $wrapper_classes ) ) ); ?> relative mb-half has-hover" data-columns="<?php echo esc_attr( $columns ); ?>">

  <?php do_action('flatsome_sale_flash'); ?>

  <div class="image-tools absolute top show-on-hover right z-3">
    <?php do_action('flatsome_product_image_tools_top'); ?>
  </div>

  <div class="woocommerce-product-gallery__wrapper <?php echo implode(' ', $slider_classes); ?>"
        data-flickity-options='{
                "cellAlign": "center",
                "wrapAround": true,
                "autoPlay": false,
                "prevNextButtons":true,
                "adaptiveHeight": true,
                "imagesLoaded": true,
                "lazyLoad": 1,
                "dragThreshold" : 15,
                "pageDots": false,
                "rightToLeft": <?php echo $rtl; ?>
       }'>
    <?php
    if ( $post_thumbnail_id ) {
      $html  = flatsome_wc_get_gallery_image_html( $post_thumbnail_id, true );
    } else {
		$wrapper_classname = $product->is_type( fl_woocommerce_version_check( '9.7.0' ) ? ProductType::VARIABLE : 'variable' ) && ! empty( $product->get_visible_children() ) && '' !== $product->get_price() ?
			'woocommerce-product-gallery__image woocommerce-product-gallery__image--placeholder' :
			'woocommerce-product-gallery__image--placeholder';
		$html              = sprintf( '<div class="%s">', esc_attr( $wrapper_classname ) );
		$html             .= sprintf( '<img src="%s" alt="%s" class="wp-post-image" />', esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ), esc_html__( 'Awaiting product image', 'woocommerce' ) );
		$html             .= '</div>';
    }

		echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', $html, $post_thumbnail_id ); // phpcs:disable WordPress.XSS.EscapeOutput.OutputNotEscaped

    do_action( 'woocommerce_product_thumbnails' );

    ?>
  </div>

  <div class="image-tools absolute bottom left z-3">
    <?php do_action('flatsome_product_image_tools_bottom'); ?>
  </div>
</div>
<?php do_action('flatsome_after_product_images'); ?>
</div>

<?php

  $attachment_ids = $product->get_gallery_image_ids();
  $thumb_count = count($attachment_ids)+1;
  $render_without_attachments = apply_filters( 'flatsome_single_product_thumbnails_render_without_attachments', false, $product, array( 'thumb_count' => $thumb_count ) );

  $rtl = 'false';

  if(is_rtl()) $rtl = 'true';

  $thumb_cell_align = "left";

  if ( $attachment_ids || $render_without_attachments ) {
	  $loop              = 0;
	  $image_size        = 'gallery_thumbnail';
	  $gallery_class     = array( 'product-thumbnails', 'thumbnails' );
	  $gallery_thumbnail = wc_get_image_size( apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_' . $image_size ) );

    if($thumb_count <= 5){
      $gallery_class[] = 'slider-no-arrows';
    }

    $gallery_class[] = 'slider row row-small row-slider slider-nav-small small-columns-4';
	$gallery_class   = apply_filters( 'flatsome_single_product_thumbnails_classes', $gallery_class );

    ?>
    <div class="col large-2 large-col-first vertical-thumbnails pb-0">

    <div class="<?php echo implode(' ', $gallery_class); ?>"
      data-flickity-options='{
                "cellAlign": "left",
                "wrapAround": false,
                "autoPlay": false,
                "prevNextButtons": false,
                "asNavFor": ".product-gallery-slider",
                "percentPosition": true,
                "imagesLoaded": true,
                "pageDots": false,
                "rightToLeft": <?php echo $rtl; ?>,
                "contain":  true
            }'
      ><?php

       if ( has_post_thumbnail() ) :
		   ?>
        <div class="col is-nav-selected first">
          <a>
            <?php
              $image_id  = get_post_thumbnail_id( $post->ID );
              $image_alt = trim( wp_strip_all_tags( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) );
              if ( fl_woocommerce_version_check( '9.3.3' ) && function_exists( 'woocommerce_get_alt_from_product_title_and_position' ) && $product ) {
                $image_alt = empty( $image_alt ) ? woocommerce_get_alt_from_product_title_and_position( $product->get_title(), false, -1 ) : $image_alt;
              }
              echo wp_get_attachment_image( $image_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_'.$image_size ), false, array( 'alt' => esc_attr( $image_alt ) ) );
            ?>
          </a>
        </div>
      <?php endif;

      foreach ( $attachment_ids as $attachment_id ) {

        $classes = array( '' );
        $image_class = esc_attr( implode( ' ', $classes ) );
        $image =  wp_get_attachment_image_src( $attachment_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_'.$image_size ));

		  if ( empty( $image ) ) {
			  continue;
		  }

        $image_alt = trim( wp_strip_all_tags( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );
        if ( fl_woocommerce_version_check( '9.3.3' ) && function_exists( 'woocommerce_get_alt_from_product_title_and_position' ) && $product ) {
          $image_alt = empty( $image_alt ) ? woocommerce_get_alt_from_product_title_and_position( $product->get_title(), false, $loop ) : $image_alt;
        }
        echo apply_filters( 'woocommerce_single_product_image_thumbnail_html', sprintf( '<div class="col"><a>%s</a></div>', wp_get_attachment_image( $attachment_id, apply_filters( 'woocommerce_gallery_thumbnail_size', 'woocommerce_'.$image_size ), false, array( 'alt' => esc_attr( $image_alt ) ) ) ), $attachment_id, $post->ID, $image_class );

        $loop++;
      }
      ?>
    </div>
    </div>
<?php } ?>
</div>
