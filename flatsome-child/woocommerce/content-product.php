<?php
/**
 * The template for displaying product content within loops
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/content-product.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see              https://woocommerce.com/document/template-structure/
 * @package          WooCommerce\Templates
 * @version          9.4.0
 * @flatsome-version 3.20.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

// Check if the product is a valid WooCommerce product and ensure its visibility before proceeding.
if ( ! is_a( $product, WC_Product::class ) || ! $product->is_visible() ) {
	return;
}

// Check stock status.
$out_of_stock = ! $product->is_in_stock();

// Extra post classes.
$classes   = array();
$classes[] = 'product-small';
$classes[] = 'col';
$classes[] = 'has-hover';

if ( $out_of_stock ) $classes[] = 'out-of-stock';

?><div <?php wc_product_class( $classes, $product ); ?>>
	<div class="col-inner">
		<style>
			.product-small>a{
			
    display: block;

			}
		</style>
	<?php do_action( 'woocommerce_before_shop_loop_item' ); ?>
	<div class="product-small box <?php echo flatsome_product_box_class(); ?>">
		<div class="box-image ">
			<div class="<?php echo flatsome_product_box_image_class(); ?>" >
				<a href="<?php echo get_the_permalink(); ?>" target="_blank">
					<?php
						/**
						 *
						 * @hooked woocommerce_get_alt_product_thumbnail - 11
						 * @hooked woocommerce_template_loop_product_thumbnail - 10
						 */
						// do_action( 'flatsome_woocommerce_shop_loop_images' );
					?>
					<?php
                        // 1. Featured image (from custom field or fallback)                         
                        $featured_img_url = get_post_meta($product->get_id(), 'featuredimg', true);
                        if (!empty($featured_img_url)) {
                            // Use custom featured image 
                            echo '<img loading="lazy" decoding="async" src="' . esc_url($featured_img_url) . '" data-src="' . esc_url($featured_img_url) . '" width="240" height="240" alt="' . esc_attr($product->get_title()) . '" class="attachment-woocommerce_gallery_thumbnail size-woocommerce_gallery_thumbnail lazy-load-active" style="  width: auto; object-fit: cover; aspect-ratio: 1 / 1;" />';
                        } else {
                            // Fallback to WooCommerce default product image 
                            echo $product->get_image('woocommerce_gallery_thumbnail');
                        }
                        // 2. Hover image: second image from imageUrl field
//                         $gallery_images = get_post_meta($product->get_id(), 'imageUrl', true);
//                         if (!empty($gallery_images)) {
//                             $gallery_images = json_decode($gallery_images, true);
//                             if (is_array($gallery_images) && count($gallery_images) >= 2) {
//                                 $hover_img = esc_url($gallery_images[1]);
//                                 echo '<img loading="lazy" decoding="async" src="' . $hover_img . '" data-src="' . $hover_img . '" width="200" height="240" alt="Alternative view of ' . esc_attr($product->get_title()) . '" class="show-on-hover absolute fill hide-for-small back-image lazy-load-active" aria-hidden="true" style=" width: 200px; height: 240px; object-fit: cover; " />';
//                             }
//                         }
//                         
                       // 2. Hover image: second image from imageUrl field
                       $gallery_images = get_post_meta($product->get_id(), 'imageUrl', true);

// Only decode if it's a string (not already an array)
if (!empty($gallery_images) && is_string($gallery_images)) {
    $gallery_images = json_decode($gallery_images, true);
}

// Now check if it's an array and has enough images
if (is_array($gallery_images) && count($gallery_images) >= 2) {
    $hover_img = esc_url($gallery_images[1]);
    echo '<img loading="lazy" decoding="async" src="' . $hover_img . '" data-src="' . $hover_img . '" width="200" height="240" alt="Alternative view of ' . esc_attr($product->get_title()) . '" class="show-on-hover absolute fill hide-for-small back-image lazy-load-active" aria-hidden="true" style=" height: 240px; object-fit: cover; " />';
}

					?>
				</a>
			</div>
			<div class="image-tools is-small top right show-on-hover">
				<?php do_action( 'flatsome_product_box_tools_top' ); ?>
			</div>
			<div class="image-tools is-small hide-for-small bottom left show-on-hover">
				<?php do_action( 'flatsome_product_box_tools_bottom' ); ?>
			</div>
			<div class="image-tools <?php echo flatsome_product_box_actions_class(); ?>">
				<?php do_action( 'flatsome_product_box_actions' ); ?>
			</div>
			<?php if ( $out_of_stock ) { ?><div class="out-of-stock-label"><?php _e( 'Out of stock', 'woocommerce' ); ?></div><?php } ?>
		</div>

		<div class="box-text <?php echo flatsome_product_box_text_class(); ?>">
			<?php
				do_action( 'woocommerce_before_shop_loop_item_title' );

				echo '<div class="title-wrapper">';
				do_action( 'woocommerce_shop_loop_item_title' );
				echo '</div>';


				echo '<div class="price-wrapper">';
				do_action( 'woocommerce_after_shop_loop_item_title' );
				echo '</div>';

				do_action( 'flatsome_product_box_after' );

			?>
		</div>
	</div>
	<?php do_action( 'woocommerce_after_shop_loop_item' ); ?>
	</div>
</div><?php /* empty PHP to avoid whitespace */ ?>
