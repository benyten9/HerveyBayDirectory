<?php
/**
 * Customer-facing contract renderer.
 *
 * Renders the `[doublescale_contract]` shortcode. Visitors open a contract
 * via `?doublescale_contract_hash={hash}` on the page that contains the shortcode.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Contracts\Services\ContractUrl;

/**
 * ContractFrontendHandler class.
 */
final class ContractFrontendHandler {

	public const SHORTCODE_NAME = 'doublescale_contract';

	private const SHORTCODE = self::SHORTCODE_NAME;
	private const MOUNT_ID  = 'doublescale-contract-view';
	private const HANDLE    = 'doublescale-contract-renderer';

	/**
	 * @return void
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'save_post_page', array( $this, 'maybe_flush_page_url_cache' ), 10, 1 );
	}

	/**
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function add_body_class( array $classes ): array {
		if ( $this->current_page_has_shortcode() && '' !== $this->current_contract_hash() ) {
			$classes[] = 'doublescale-contract-page';
		}
		return $classes;
	}

	/**
	 * @param int $post_id Saved post ID.
	 * @return void
	 */
	public function maybe_flush_page_url_cache( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type || empty( $post->post_content ) ) {
			return;
		}
		if (
			has_shortcode( $post->post_content, self::SHORTCODE_NAME )
			|| false !== strpos( $post->post_content, self::SHORTCODE_NAME )
		) {
			ContractUrl::flush_cache();
		}
	}

	/**
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ): string {
		unset( $atts );

		$hash = $this->current_contract_hash();
		if ( '' === $hash ) {
			return '<div class="doublescale-contract-placeholder" style="padding:1.5rem;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-family:sans-serif;">'
				. esc_html__( 'Contract link is invalid or missing.', 'doublescale' )
				. '</div>';
		}

		return sprintf(
			'<div id="%s" data-contract-hash="%s"></div>',
			esc_attr( self::MOUNT_ID ),
			esc_attr( $hash )
		);
	}

	/**
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( ! $this->current_page_has_shortcode() || '' === $this->current_contract_hash() ) {
			return;
		}

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';

		$asset_file = $plugin_dir . 'build/renderer/contract/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$deps       = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;

		wp_register_script(
			self::HANDLE,
			$plugin_url . 'build/renderer/contract/index.js',
			$deps,
			$ver,
			true
		);

		wp_register_style(
			self::HANDLE,
			$plugin_url . 'build/renderer/contract/style.css',
			array(),
			$ver
		);

		$config = array(
			'public_rest_url' => esc_url_raw( rest_url( 'doublescale/v1/sales/public/contracts' ) ),
			'lang'            => get_locale(),
			'mount_id'        => self::MOUNT_ID,
		);

		wp_localize_script( self::HANDLE, 'doublescale_contract_config', $config );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * @return bool
	 */
	private function current_page_has_shortcode(): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}
		return has_shortcode( $post->post_content, self::SHORTCODE )
			|| false !== strpos( $post->post_content, self::SHORTCODE_NAME );
	}

	/**
	 * @return string
	 */
	private function current_contract_hash(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public hash is the bearer token.
		if ( empty( $_GET[ ContractUrl::HASH_QUERY_ARG ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hash = sanitize_text_field( wp_unslash( (string) $_GET[ ContractUrl::HASH_QUERY_ARG ] ) );
		return preg_match( '/^[a-f0-9]{32}$/', $hash ) ? $hash : '';
	}
}
