<?php
/**
 * Authenticated owner entry point for guided native listing submissions.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Domain\MediaSuggestionService;

/** The visible selector is advisory; every operation rechecks native authority. */
final class OwnerPlacement {
	/**
	 * Count rendered mount instances.
	 *
	 * @var int
	 */
	private static int $mount = 0;

	/**
	 * Register the entry point hooks.
	 */
	public static function hooks(): void {
		add_shortcode( 'directorist_ai_owner', array( self::class, 'render' ) );
	}

	/**
	 * Render the owner entry point.
	 */
	public static function render(): string {
		if ( ! is_user_logged_in() ) {
			return '<p role="status">' . esc_html__( 'Sign in to create or improve your listings.', 'directorist-ai' ) . '</p>';
		}
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			return '<p role="status">' . esc_html__( 'AI listing assistance is temporarily paused.', 'directorist-ai' ) . '</p>';
		}
		$directory_terms = get_terms(
			array(
				'taxonomy'   => ATBDP_DIRECTORY_TYPE,
				'hide_empty' => false
			)
		);
		$directories     = array();
		if ( ! is_wp_error( $directory_terms ) ) {
			foreach ( $directory_terms as $term ) {
				$directories[] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name
				);
			}
		}
		$posts    = get_posts(
			array(
				'post_type'   => ATBDP_POST_TYPE,
				'post_status' => array( 'publish', 'pending', 'draft', 'private', 'expired', 'rejected' ),
				'author'      => get_current_user_id(),
				'numberposts' => 100,
				'orderby'     => 'modified',
				'order'       => 'DESC'
			)
		);
		$listings = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$listings[] = array(
				'id'          => (int) $post->ID,
				'title'       => $post->post_title,
				'status'      => $post->post_status,
				'directoryId' => (int) directorist_get_listing_directory( $post->ID ),
				'editUrl'     => class_exists( '\\ATBDP_Permalink' ) ? \ATBDP_Permalink::get_edit_listing_page_link( $post->ID ) : get_permalink( $post )
			);
		}
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		if ( ! file_exists( $path . 'owner.js' ) || ! file_exists( $path . 'owner.asset.php' ) ) {
			return '<p role="status">' . esc_html__( 'AI listing assistance is unavailable.', 'directorist-ai' ) . '</p>';
		}
		$asset = require $path . 'owner.asset.php';
		$url   = plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'apps/dashboard/build/';
		wp_enqueue_script( 'directorist-ai-pro-owner', $url . 'owner.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		if ( file_exists( $path . 'owner.css' ) ) {
			wp_enqueue_style( 'directorist-ai-pro-owner', $url . 'owner.css', array( 'wp-components' ), $asset['version'] );
		}
		wp_set_script_translations( 'directorist-ai-pro-owner', 'directorist-ai', plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'languages' );
		$id              = 'directorist-ai-owner-' . ++self::$mount . '-' . wp_unique_id();
		$image_available = MediaSuggestionService::image_provider_available();
		$config          = array(
			'apiPath'                  => '/directorist-ai/v1',
			'nonce'                    => wp_create_nonce( 'wp_rest' ),
			'userId'                   => get_current_user_id(),
			'directories'              => $directories,
			'listings'                 => $listings,
			'listingsUrl'              => class_exists( '\\ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : '',
			'imageGenerationAvailable' => $image_available
		);
		return '<div id="' . esc_attr( $id ) . '" class="directorist-ai-pro-owner directorist-ai-pro-app" data-directorist-ai-owner="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
	}
}
