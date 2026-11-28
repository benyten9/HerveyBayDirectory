<?php
/**
 * Scoped administration assets, using the BasePlugin asset-manifest pattern.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Assets;

use DirectoristAIPro\Admin\Dashboard;
use DirectoristAIPro\Traits\Singleton;

/**
 * Enqueues assets for the authorized product screen.
 */
class Assets {
	use Singleton;

	/**
	 * Register the scoped enqueue callback.
	 */
	protected function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Load the built app only on the authorized product screen.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$dashboard_hook = Dashboard::get_instance()->get_hook_suffix();
		if ( ! current_user_can( 'manage_options' ) || '' === $dashboard_hook || $dashboard_hook !== $hook_suffix ) {
			return;
		}
		$relative = 'apps/dashboard/build/';
		$path     = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . $relative;
		$url      = plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . $relative;
		if ( ! file_exists( $path . 'index.js' ) || ! file_exists( $path . 'index.asset.php' ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
			return;
		}
		$asset = require $path . 'index.asset.php';
		wp_enqueue_script( 'directorist-ai-pro-dashboard', $url . 'index.js', array_values( array_unique( array_merge( $asset['dependencies'], array( 'wp-block-editor' ) ) ) ), $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_media();
		wp_enqueue_style( 'directorist-ai-pro-dashboard', $url . 'index.css', array( 'wp-components', 'wp-block-editor' ), $asset['version'] );
		wp_add_inline_script(
			'directorist-ai-pro-dashboard',
			'window.directoristAIProAdmin = ' . wp_json_encode(
				array(
					'version'            => DIRECTORIST_AI_PRO_VERSION,
					'wordpressVersion'   => get_bloginfo( 'version' ),
					'directoristVersion' => ATBDP_VERSION,
					'aiSupported'        => function_exists( 'wp_supports_ai' ) && wp_supports_ai(),
					'chatStylesUrl'      => add_query_arg( 'ver', $asset['version'], $url . 'chat.css' ),
					'apiPath'            => '/directorist-ai/v1',
					'homeUrl'            => esc_url_raw( home_url( '/' ) ),
					'language'           => \DirectoristAIPro\AI\LanguagePolicy::configuration(),
					'userId'             => get_current_user_id(),
					'directories'        => $this->directories(),
					'listings'           => $this->editable_listings(),
					'builderPages'       => $this->editable_pages(),
					'builderTargets'     => $this->editable_builder_targets(),
					'builderDirectories' => $this->builder_directories(),
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'directorist-ai-pro-dashboard', 'directorist-ai', plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'languages' );
	}

	/** Current native directory choices for the guided create path. */
	private function directories(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => ATBDP_DIRECTORY_TYPE,
				'hide_empty' => false
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static fn( $term ): array => array(
				'id'   => (int) $term->term_id,
				'name' => $term->name
			),
			$terms
		);
	}

	/** Return a bounded admin-authorized listing selector without field data. */
	private function editable_listings(): array {
		$posts  = get_posts(
			array(
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'private', 'expired', 'rejected' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$result = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$result[] = array(
				'id'          => (int) $post->ID,
				'title'       => $post->post_title,
				'status'      => $post->post_status,
				'directoryId' => directorist_get_listing_directory( $post->ID ),
				'editUrl'     => get_edit_post_link( $post->ID, 'raw' ),
			);
		}
		return $result;
	}

	/** Return a bounded native page selector; draft authority is checked again server-side. */
	private function editable_pages(): array {
		$posts  = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$result = array();
		foreach ( $posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$result[] = array(
				'id'      => (int) $post->ID,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'editUrl' => get_edit_post_link( $post->ID, 'raw' ),
			);
		}
		return $result;
	}

	/** Let active integrations offer only sources their native reader can identify. */
	private function editable_builder_targets(): array {
		$candidates = apply_filters( 'directorist_ai_pro_builder_source_targets', array(), $this->editable_pages() );
		if ( ! is_array( $candidates ) ) {
			return array();
		}
		$result = array();
		$seen   = array();
		foreach ( $candidates as $target ) {
			if ( ! is_array( $target ) || ! in_array( $target['builder'] ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $target['nativeKind'] ?? null ) || ! preg_match( '/^(page|template:[a-z_]+|template_part)$/D', $target['nativeKind'] ) || ! is_string( $target['nativeId'] ?? null ) || ! preg_match( '/^(post:[1-9][0-9]*|template:(wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+)$/D', $target['nativeId'] ) || ! is_string( $target['title'] ?? null ) || ! is_string( $target['status'] ?? null ) || ! ( null === ( $target['directoryId'] ?? null ) || ( is_int( $target['directoryId'] ) && 0 < $target['directoryId'] ) ) ) {
				continue;
			}
			$key = $target['builder'] . '|' . $target['nativeKind'] . '|' . $target['nativeId'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$result[]     = array(
				'builder'     => $target['builder'],
				'nativeKind'  => $target['nativeKind'],
				'nativeId'    => $target['nativeId'],
				'title'       => sanitize_text_field( $target['title'] ),
				'status'      => sanitize_key( $target['status'] ),
				'directoryId' => $target['directoryId'] ?? null,
			);
			if ( 400 <= count( $result ) ) {
				break;
			}
		}
		return $result;
	}

	/** Give the page workflow current directory choices without listing private fields. */
	private function builder_directories(): array {
		$taxonomy = defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types';
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		return array_values(
			array_map(
				static fn( \WP_Term $term ): array => array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
				),
				$terms
			)
		);
	}

	/**
	 * Explain an incomplete development package.
	 *
	 * @return void
	 */
	public function render_missing_build_notice(): void {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Directorist AI administration assets are missing. Install a complete plugin build.', 'directorist-ai' ) . '</p></div>';
	}
}
