<?php /** * The template for displaying product widget entries. * * This template can be overridden by copying it to yourtheme/woocommerce/content-widget-product.php. * * @see https://docs.woocommerce.com/document/template-structure/ * @package WooCommerce/Templates * @version 3.5.5 * @flatsome-version 3.16.0 */ 
// 

if (!defined('ABSPATH')) {
	exit;
}
global $product;
if (!is_a($product, 'WC_Product')) {
	return;
} ?>
<li> <?php do_action('woocommerce_widget_product_item_start', $args); ?> <a
		href="<?php echo esc_url($product->get_permalink()); ?>">
		<?php
		// Get the custom featured image (from post meta) or fallback to default product image 
		$featured_img_url = get_post_meta($product->get_id(), 'featuredimg', true);
		if (!empty($featured_img_url)) {
			// Use custom image
			echo '<img loading="lazy" decoding="async" src="' . esc_url($featured_img_url) . '" data-src="' . esc_url($featured_img_url) . '" alt="' . esc_attr($product->get_title()) . '" class="attachment-woocommerce_gallery_thumbnail size-woocommerce_gallery_thumbnail lazy-load-active" sizes="auto, (max-width: 100px) 100vw, 100px" />';
		} else {
			// Fallback to default WooCommerce thumbnai
			echo $product->get_image('woocommerce_gallery_thumbnail');
		}
		?>
		<span class="product-title"><?php 
// echo wp_kses_post($product->get_name()); 
$title = $product->get_name(); // Get product name
	$title = str_replace("_", " ", $title); // Replace underscores with spaces
	echo wp_kses_post($title); // Safely output
?></span> 
</a>


	<?php if (!empty($show_rating) && $product->get_average_rating() > 0): ?>
		<?php echo wc_get_rating_html($product->get_average_rating()); ?> <?php endif; ?>
	<?php echo $product->get_price_html(); ?> <?php do_action('woocommerce_widget_product_item_end', $args); ?> </li>