<?php
/** Editor warnings for URLs already covered by a redirect. */
namespace SEOPressPro\Actions\Admin;

defined( 'ABSPATH' ) || exit;

use SEOPress\Core\Hooks\ExecuteHooks;

class RedirectionConflict implements ExecuteHooks {
	public function hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'get_sample_permalink_html', array( $this, 'sample_permalink' ), 10, 5 );
	}

	public function enqueue() {
		global $post;
		$screen = get_current_screen();
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID )
			|| ! $screen || 'post' !== $screen->base || ! is_post_type_viewable( $post->post_type )
			|| '1' !== seopress_get_toggle_option( '404' ) ) {
			return;
		}
		wp_enqueue_script( 'seopress-redirection-conflict', SEOPRESS_PRO_ASSETS_DIR . '/js/seopress-redirection-conflict.js', array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-dom-ready' ), SEOPRESS_PRO_VERSION, true );
		wp_localize_script( 'seopress-redirection-conflict', 'seopressRedirectionConflict', array(
			'postId'       => $post->ID,
			'blockEditor'  => $screen->is_block_editor(),
			'message'      => __( 'This URL matches an active redirection rule and may not display this content for some visitors. Review the redirection before publishing.', 'wp-seopress-pro' ),
			'errorMessage' => __( 'The redirection check is unavailable. Try again by reloading the editor.', 'wp-seopress-pro' ),
		) );
	}

	/** Include the real sample URL, including draft slugs, in Classic AJAX updates. */
	public function sample_permalink( $html, $post_id, $title, $slug, $post ) {
		if ( ! current_user_can( 'edit_post', $post_id ) || '1' !== seopress_get_toggle_option( '404' ) ) {
			return $html;
		}
		$sample = get_sample_permalink( $post_id, $title, $slug );
		$url    = str_replace( array( '%postname%', '%pagename%' ), $sample[1], $sample[0] );
		return $html . '<span id="seopress-redirection-target" data-url="' . esc_url( $url ) . '" hidden></span>';
	}
}
