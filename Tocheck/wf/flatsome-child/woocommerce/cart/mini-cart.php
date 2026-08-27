<?php
/**
 * Mini-cart – External Images Version
 *
 * Overrides the default WooCommerce mini-cart template to use:
 * - featuredimg (custom main product image)
 * - imageUrl (custom gallery images JSON)
 *
 * Copy this file to yourtheme/woocommerce/cart/mini-cart.php
 *
 * @package WooCommerce\Templates
 * @version 10.0.0
 * @flatsome-version 3.19.15
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_mini_cart' ); ?>

<?php if ( WC()->cart && ! WC()->cart->is_empty() ) : ?>

	<ul class="woocommerce-mini-cart cart_list product_list_widget <?php echo esc_attr( $args['list_class'] ); ?>">
		<?php
		do_action( 'woocommerce_before_mini_cart_contents' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product   = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
			$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_widget_cart_item_visible', true, $cart_item, $cart_item_key ) ) {

				$product_name      = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
				$product_price     = apply_filters( 'woocommerce_cart_item_price', WC()->cart->get_product_price( $_product ), $cart_item, $cart_item_key );
				$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );

				// ✅ Get external image
				$featured_img = get_post_meta( $product_id, 'featuredimg', true );
				$gallery_imgs = get_post_meta( $product_id, 'imageUrl', true );
				if (!empty($gallery_images) && is_string($gallery_images)) {
  				  $gallery_images = json_decode($gallery_images, true);
				}
		
				if ( ! empty( $featured_img ) ) {
					$thumbnail = '<img src="' . esc_url( $featured_img ) . '" alt="' . esc_attr( $_product->get_name() ) . '" class="mini-cart-product-img" />';
				} elseif ( ! empty( $gallery_imgs ) && is_array( $gallery_imgs ) ) {
					$thumbnail = '<img src="' . esc_url( $gallery_imgs[0] ) . '" alt="' . esc_attr( $_product->get_name() ) . '" class="mini-cart-product-img" />';
				} else {
					// fallback to default WooCommerce image
					$thumbnail = $_product->get_image();
				}
				?>
		
				<li class="woocommerce-mini-cart-item <?php echo esc_attr( apply_filters( 'woocommerce_mini_cart_item_class', 'mini_cart_item', $cart_item, $cart_item_key ) ); ?>">
					<?php
					echo apply_filters(
						'woocommerce_cart_item_remove_link',
						sprintf(
							'<a role="button" href="%s" class="remove remove_from_cart_button" aria-label="%s" data-product_id="%s" data-cart_item_key="%s" data-product_sku="%s" data-success_message="%s">&times;</a>',
							esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
							esc_attr( sprintf( __( 'Remove %s from cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) ),
							esc_attr( $product_id ),
							esc_attr( $cart_item_key ),
							esc_attr( $_product->get_sku() ),
							esc_attr( sprintf( __( '&ldquo;%s&rdquo; has been removed from your cart', 'woocommerce' ), wp_strip_all_tags( $product_name ) ) )
						),
						$cart_item_key
					);
					?>
					<?php if ( empty( $product_permalink ) ) : ?>
						<?php echo $thumbnail . wp_kses_post( $product_name ); ?>
					<?php else : ?>
						<a href="<?php echo esc_url( $product_permalink ); ?>">
							<?php echo $thumbnail . wp_kses_post( $product_name ); ?>
						</a>
					<?php endif; ?>

					<?php echo wc_get_formatted_cart_item_data( $cart_item ); ?>
					<?php echo apply_filters( 'woocommerce_widget_cart_item_quantity', '<span class="quantity">' . sprintf( '%s &times; %s', $cart_item['quantity'], $product_price ) . '</span>', $cart_item, $cart_item_key ); ?>
				</li>
				<?php
			}
		}

		do_action( 'woocommerce_mini_cart_contents' );
		?>
	</ul>

	<?php do_action( 'flatsome_after_mini_cart_contents' ); ?>

	<div class="ux-mini-cart-footer">
		<?php do_action( 'flatsome_before_mini_cart_total' ); ?>
		<p class="woocommerce-mini-cart__total total">
			<?php do_action( 'woocommerce_widget_shopping_cart_total' ); ?>
		</p>
		<?php do_action( 'woocommerce_widget_shopping_cart_before_buttons' ); ?>
		<p class="woocommerce-mini-cart__buttons buttons"><?php do_action( 'woocommerce_widget_shopping_cart_buttons' ); ?></p>
		<?php do_action( 'woocommerce_widget_shopping_cart_after_buttons' ); ?>
	</div>

<?php else : ?>

	<div class="ux-mini-cart-empty flex flex-row-col text-center pt pb">
		<?php do_action( 'flatsome_before_mini_cart_empty_message' ); ?>
		<p class="woocommerce-mini-cart__empty-message empty"><?php esc_html_e( 'No products in the cart.', 'woocommerce' ); ?></p>
		<?php do_action( 'flatsome_after_mini_cart_empty_message' ); ?>
	</div>

<?php endif; ?>

<?php do_action( 'woocommerce_after_mini_cart' ); ?>
