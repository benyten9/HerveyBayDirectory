<?php
/**
 * Customer-facing support portal renderer.
 *
 * Renders the `[doublescale_support_portal]` shortcode. Mirrors the
 * Booking renderer's enqueue pattern ({@see Modules/Booking/Renderer/BookingFrontendHandler.php}),
 * with two key differences:
 *
 *   1. Portal is shortcode-driven, NOT URL-query-driven, so we do NOT
 *      hijack `template_redirect` or wipe the global styles queue —
 *      the portal renders inline on whatever page the admin pasted
 *      the shortcode onto (host theme stays intact).
 *
 *   2. Logged-out visitors without a guest ticket hash see a login gate
 *      (wp_login_form + register / reset links), mirroring Fluent Support.
 *      Guest hash URLs and logged-in customers load the portal SPA.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Renderer;

use DoubleScale\Core\UserRoles\Permissions;

defined( 'ABSPATH' ) || exit;

final class PortalFrontendHandler {

	public const SHORTCODE_NAME = 'doublescale_support_portal';

	private const SHORTCODE = self::SHORTCODE_NAME;
	private const MOUNT_ID  = 'doublescale-support-portal';
	private const HANDLE    = 'doublescale-support-renderer';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_login_styles' ) );
		add_action( 'save_post_page', array( self::class, 'maybe_flush_portal_url_cache' ), 10, 1 );
	}

	/**
	 * Add portal-specific body classes for theme title suppression and styling.
	 *
	 * @param array<int, string> $classes Existing body classes.
	 * @return array<int, string>
	 */
	public function add_body_class( array $classes ): array {
		if ( ! $this->current_page_has_shortcode() ) {
			return $classes;
		}

		$classes[] = 'doublescale-support-portal-page';

		$guest_hash = $this->current_guest_ticket_hash();
		if (
			( ! is_user_logged_in() && '' === $guest_hash )
			|| ( is_user_logged_in() && '' === $guest_hash && Permissions::should_block_customer_portal() )
		) {
			$classes[] = 'doublescale-support-portal-gate-view';
		}

		return $classes;
	}

	/**
	 * Invalidate the cached portal permalink when a page containing the shortcode is saved.
	 *
	 * @param int $post_id Saved post ID.
	 * @return void
	 */
	public static function maybe_flush_portal_url_cache( $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type || empty( $post->post_content ) ) {
			return;
		}
		if (
			has_shortcode( $post->post_content, self::SHORTCODE_NAME )
			|| false !== strpos( $post->post_content, self::SHORTCODE_NAME )
		) {
			\DoubleScale\Modules\Support\Services\PortalUrl::flush_cache();
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * Returns the mount markup for logged-in users; nothing for guests. The
	 * optional `box_id` attribute scopes the portal to one support mailbox:
	 * `[doublescale_support_portal box_id="3"]` makes tickets created here route
	 * to mailbox 3 (and the customer sees only that mailbox's tickets). The
	 * default `box_id="0"` leaves the portal unscoped. The id rides on the mount
	 * node as `data-box-id`, so multiple scoped portals can coexist on one page.
	 *
	 * We enqueue from `wp_enqueue_scripts` (not here) so the bundle is
	 * available even if the shortcode lives below content that hasn't
	 * been parsed yet by the time scripts are decided.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes; `box_id` (int) optional.
	 * @return string Portal mount node, login gate, or staff notice HTML.
	 */
	public function render_shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'box_id' => 0,
				'title'  => '',
			),
			$atts,
			self::SHORTCODE
		);

		$brand_title = $this->get_portal_brand_title( (string) $atts['title'] );
		$guest_hash  = $this->current_guest_ticket_hash();

		if ( ! is_user_logged_in() && '' === $guest_hash ) {
			return $this->render_login_gate( $brand_title );
		}

		if ( is_user_logged_in() && '' === $guest_hash && Permissions::should_block_customer_portal() ) {
			return $this->render_staff_notice( $brand_title );
		}

		$box_id = max( 0, (int) $atts['box_id'] );

		$attrs = sprintf( 'data-box-id="%d"', $box_id );
		if ( '' !== $guest_hash ) {
			$attrs .= sprintf( ' data-guest-hash="%s"', esc_attr( $guest_hash ) );
		}

		return sprintf(
			'<div id="%s" %s></div>',
			esc_attr( self::MOUNT_ID ),
			$attrs
		);
	}

	/**
	 * Register + enqueue the renderer bundle on pages that contain the
	 * shortcode AND are being viewed by a logged-in user. This keeps the
	 * JS off every page on the site (it's only needed where the admin
	 * actually placed the shortcode) and off logged-out visits entirely.
	 */
	public function maybe_enqueue(): void {
		$guest_hash = $this->current_guest_ticket_hash();
		if ( ! is_user_logged_in() && '' === $guest_hash ) {
			return;
		}

		if ( is_user_logged_in() && '' === $guest_hash && Permissions::should_block_customer_portal() ) {
			return;
		}

		if ( ! $this->current_page_has_shortcode() ) {
			return;
		}

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';

		$asset_file = $plugin_dir . 'build/renderer/support/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$deps       = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;

		wp_register_script(
			self::HANDLE,
			$plugin_url . 'build/renderer/support/index.js',
			$deps,
			$ver,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE, 'doublescale', $plugin_dir . 'languages' );
		}

		wp_register_style(
			self::HANDLE,
			$plugin_url . 'build/renderer/support/style.css',
			array(),
			$ver
		);

		$user     = wp_get_current_user();
		$is_guest = ! is_user_logged_in() && '' !== $guest_hash;
		$config   = array(
			'rest_url'              => esc_url_raw( rest_url( 'doublescale/v1/support/portal' ) ),
			'public_rest_url'       => esc_url_raw( rest_url( 'doublescale/v1/support/public' ) ),
			'rest_root'             => esc_url_raw( rest_url() ),
			'nonce'                 => wp_create_nonce( 'wp_rest' ),
			'user'                  => array(
				'id'           => (int) $user->ID,
				'email'        => sanitize_email( $user->user_email ),
				'display_name' => $user->display_name ? sanitize_text_field( $user->display_name ) : '',
			),
			'lang'                  => get_locale(),
			'mount_id'              => self::MOUNT_ID,
			'is_guest'              => $is_guest,
			'guest_hash'            => $is_guest ? $guest_hash : '',
			'custom_fields_enabled' => class_exists( '\\DoubleScale\\Pro\\Modules\\Support\\Services\\CustomFieldsService' ),
			// Attachment limits so the portal/guest uploader can show the caps and
			// pre-validate before sending a file.
			'attachment_limits'     => \DoubleScale\Modules\Support\Services\AttachmentSettings::to_payload(),
		);

		wp_localize_script(
			self::HANDLE,
			'doublescale_support_portal_config',
			$config
		);

		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Lightweight styles for the logged-out login gate on portal pages.
	 *
	 * @return void
	 */
	public function maybe_enqueue_login_styles(): void {
		if ( ! $this->current_page_has_shortcode() || $this->should_load_portal_spa() ) {
			return;
		}

		$css = '
			body.doublescale-support-portal-gate-view .entry-header,
			body.doublescale-support-portal-gate-view .entry-title,
			body.doublescale-support-portal-gate-view .page-header,
			body.doublescale-support-portal-gate-view .wp-block-post-title {
				display: none;
			}
			.doublescale-support-portal-shell--login {
				max-width: 28rem;
				margin: 2rem auto;
			}
			.doublescale-support-portal-brand {
				margin: 0 0 1.5rem;
				text-align: center;
				font-size: 2rem;
				font-weight: 700;
				line-height: 1.2;
				color: #111827;
				letter-spacing: -0.02em;
			}
			.doublescale-support-portal-login {
				margin: 0;
				padding: 2rem 1.5rem;
				border: 1px solid #e5e7eb;
				border-radius: 0.75rem;
				background: #fff;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
			}
			.doublescale-support-portal-login-title {
				margin: 0 0 1.5rem;
				text-align: center;
				font-size: 1.125rem;
				font-weight: 600;
				line-height: 1.4;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform p {
				margin-bottom: 1rem;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform label {
				display: block;
				margin-bottom: 0.25rem;
				font-weight: 500;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform input[type="text"],
			.doublescale-support-portal-login #doublescale-support-portal-loginform input[type="password"] {
				width: 100%;
				box-sizing: border-box;
				padding: 0.5rem 0.75rem;
				border: 1px solid #d1d5db;
				border-radius: 0.375rem;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform .login-remember {
				display: flex;
				align-items: center;
				gap: 0.5rem;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform .login-remember label {
				margin: 0;
				font-weight: 400;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform .login-submit {
				margin: 0;
			}
			.doublescale-support-portal-login #doublescale-support-portal-loginform input[type="submit"] {
				width: 100%;
				padding: 0.625rem 1rem;
				border: 0;
				border-radius: 0.375rem;
				background: #3f3d9a;
				color: #fff;
				font-weight: 600;
				cursor: pointer;
			}
			.doublescale-support-portal-login-link {
				margin: 0.75rem 0 0;
				text-align: center;
				font-size: 0.875rem;
			}
		';

		wp_register_style( 'doublescale-support-portal-login', false, array(), defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0' );
		wp_enqueue_style( 'doublescale-support-portal-login' );
		wp_add_inline_style( 'doublescale-support-portal-login', $css );
	}

	/**
	 * Whether the portal SPA bundle should load (vs. login / staff gate HTML).
	 *
	 * @return bool
	 */
	private function should_load_portal_spa(): bool {
		$guest_hash = $this->current_guest_ticket_hash();
		if ( '' !== $guest_hash ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return ! Permissions::should_block_customer_portal();
	}

	/**
	 * True if the currently-rendering post contains our shortcode.
	 *
	 * We check `$post->post_content` directly rather than parsing the
	 * full output, because `has_shortcode( get_the_content(), … )` is
	 * unreliable on archive / shop pages where the loop hasn't started
	 * yet at `wp_enqueue_scripts` time. The post object is already
	 * populated by then on singular views, which is where shortcodes
	 * live in practice.
	 */
	private function current_page_has_shortcode(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post || empty( $post->post_content ) ) {
			return false;
		}

		return has_shortcode( $post->post_content, self::SHORTCODE );
	}

	/**
	 * Login gate for logged-out visitors (no guest ticket hash).
	 *
	 * @return string
	 */
	/**
	 * Portal brand heading shown above the login gate and staff notice.
	 *
	 * @param string $override Optional shortcode `title` attribute.
	 * @return string
	 */
	private function get_portal_brand_title( string $override = '' ): string {
		$override = trim( $override );
		if ( '' !== $override ) {
			return $override;
		}

		/**
		 * Filter the brand title shown above the customer portal login gate.
		 *
		 * @param string $title Brand heading text.
		 */
		return (string) apply_filters(
			'doublescale_support_portal_brand_title',
			__( 'DoubleScale Support', 'doublescale' )
		);
	}

	/**
	 * @param string $brand_title Portal brand heading.
	 * @return string
	 */
	private function render_login_gate( string $brand_title ): string {
		$redirect = get_permalink();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		/**
		 * Filter the headline shown above the portal login form.
		 *
		 * @param string $message Login gate message.
		 */
		$message = (string) apply_filters(
			'doublescale_support_portal_login_message',
			__( 'Please login or create an account to access the Customer Support Portal', 'doublescale' )
		);

		/**
		 * Filter arguments passed to {@see wp_login_form()} on the portal page.
		 *
		 * @param array  $args     wp_login_form() arguments.
		 * @param string $redirect Post-login redirect URL.
		 */
		$form_args = apply_filters(
			'doublescale_support_portal_login_form_args',
			array(
				'echo'           => false,
				'redirect'       => $redirect,
				'remember'       => true,
				'value_remember' => true,
				'form_id'        => 'doublescale-support-portal-loginform',
				'label_username' => __( 'Username or Email Address', 'doublescale' ),
				'label_password' => __( 'Password', 'doublescale' ),
				'label_remember' => __( 'Remember Me', 'doublescale' ),
				'label_log_in'   => __( 'Log In', 'doublescale' ),
			),
			$redirect
		);

		$form  = wp_login_form( $form_args );
		$links = '';

		if ( get_option( 'users_can_register' ) ) {
			$links .= sprintf(
				'<p class="doublescale-support-portal-login-link">' .
				'%s <a href="%s">%s</a>' .
				'</p>',
				esc_html__( 'Not registered?', 'doublescale' ),
				esc_url( wp_registration_url() ),
				esc_html__( 'Create an account', 'doublescale' )
			);
		}

		$links .= sprintf(
			'<p class="doublescale-support-portal-login-link">' .
			'%s <a href="%s">%s</a>' .
			'</p>',
			esc_html__( 'Forgot your password?', 'doublescale' ),
			esc_url( wp_lostpassword_url( $redirect ) ),
			esc_html__( 'Reset password', 'doublescale' )
		);

		return sprintf(
			'<div class="doublescale-support-portal-shell doublescale-support-portal-shell--login">' .
			'<h1 class="doublescale-support-portal-brand">%s</h1>' .
			'<div class="doublescale-support-portal-gate doublescale-support-portal-login">' .
			'<p class="doublescale-support-portal-login-title">%s</p>' .
			'%s' .
			'%s' .
			'</div>' .
			'</div>',
			esc_html( $brand_title ),
			esc_html( $message ),
			$form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_login_form() returns escaped HTML.
			$links
		);
	}

	/**
	 * Staff-facing notice when a support agent visits the customer portal page.
	 *
	 * Mirrors Fluent Support's customer-portal gate: agents see a redirect
	 * to the admin inbox instead of the customer SPA.
	 *
	 * @return string
	 */
	/**
	 * @param string $brand_title Portal brand heading.
	 * @return string
	 */
	private function render_staff_notice( string $brand_title ): string {
		/**
		 * Filter the message shown when support staff visit the customer portal.
		 *
		 * @param string $message Notice text.
		 */
		$message = (string) apply_filters(
			'doublescale_support_portal_staff_notice',
			__( 'Customer Portal is only accessible by Customers. Looks like you are a support staff', 'doublescale' )
		);

		$admin_url = $this->get_support_admin_url();
		$link_text = __( 'Go to Support Admin Page', 'doublescale' );

		return sprintf(
			'<div class="doublescale-support-portal-shell doublescale-support-portal-shell--login">' .
			'<h1 class="doublescale-support-portal-brand">%s</h1>' .
			'<div class="doublescale-support-portal-gate" style="text-align:center;padding:2rem 1rem;">' .
			'<h3>%s</h3>' .
			'<p><a href="%s">%s</a></p>' .
			'</div>' .
			'</div>',
			esc_html( $brand_title ),
			esc_html( $message ),
			esc_url( $admin_url ),
			esc_html( $link_text )
		);
	}

	/**
	 * Deep link to the Support inbox inside the DoubleScale admin SPA.
	 *
	 * @return string
	 */
	private function get_support_admin_url(): string {
		$menu_slug = (string) apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		return admin_url( 'admin.php?page=' . rawurlencode( $menu_slug ) . '&path=support' );
	}

	/**
	 * Read a valid guest ticket hash from the current request.
	 *
	 * @return string 32-char hex hash or empty string.
	 */
	private function current_guest_ticket_hash(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public ticket hash is the bearer token.
		if ( empty( $_GET[ \DoubleScale\Modules\Support\Services\PortalUrl::TICKET_HASH_QUERY_ARG ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$hash = sanitize_text_field( wp_unslash( (string) $_GET[ \DoubleScale\Modules\Support\Services\PortalUrl::TICKET_HASH_QUERY_ARG ] ) );
		return preg_match( '/^[a-f0-9]{32}$/', $hash ) ? $hash : '';
	}
}
