<?php
/**
 * Polylang integration.
 *
 * @author      UX Themes
 * @package     Flatsome/Integrations
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies post content and title for Polylang translations.
 *
 * @param string  $content Default post content or title.
 * @param WP_Post $post    Post object.
 * @return string Modified content or title.
 */
function ux_copy_post_translation( $content, $post ) {
	$from_post_id = isset( $_GET['from_post'] ) ? (int) $_GET['from_post'] : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $content == '' && $from_post_id ) {
		$from_post = get_post( $from_post_id );

		if ( $from_post && current_user_can( 'read_post', $from_post->ID ) ) {
			switch ( current_filter() ) {
				case 'default_content':
					$content = $from_post->post_content;
					break;
				case 'default_title':
					$content = $from_post->post_title;
					break;
				default:
					break;
			}
		}
	}
	return $content;
}

add_filter( 'default_content', 'ux_copy_post_translation', 100, 2 );
add_filter( 'default_title', 'ux_copy_post_translation', 100, 2 );
