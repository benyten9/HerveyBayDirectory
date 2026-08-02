<?php
/**
 * Customer-facing Client Portal renderer.
 *
 * Renders the `[doublescale_client_portal]` shortcode — the unified shell that
 * hosts the Tickets, Bookings, Dashboard (and gated Documents) sections.
 * Generalised from {@see \DoubleScale\Modules\Support\Renderer\PortalFrontendHandler}:
 *
 *   - Shortcode-driven (does NOT hijack `template_redirect`), so it renders
 *     inline on whatever page the admin pasted the shortcode onto.
 *   - Logged-out visitors see a login gate; support staff (an agent whose email
 *     is not also a contact) see a redirect notice; logged-in customers load the
 *     SPA bundle.
 *   - Portal is login-only — there is no guest-hash mode (that lives in the
 *     standalone support portal).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Portal\Services\PortalUrl;

/**
 * PortalFrontendHandler.
 */
final class PortalFrontendHandler {

	public const SHORTCODE_NAME = 'doublescale_client_portal';

	private const SHORTCODE = self::SHORTCODE_NAME;
	private const MOUNT_ID  = 'doublescale-client-portal';
	private const HANDLE    = 'doublescale-portal-renderer';

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_login_styles' ) );
		add_action( 'save_post_page', array( self::class, 'maybe_flush_portal_url_cache' ), 10, 1 );
	}

	/**
	 * Add portal-specific body classes for theme title suppression.
	 *
	 * @param array<int, string> $classes Existing body classes.
	 * @return array<int, string>
	 */
	public function add_body_class( array $classes ): array {
		if ( ! $this->current_page_has_shortcode() ) {
			return $classes;
		}

		$classes[] = 'doublescale-client-portal-page';

		if ( ! $this->should_load_portal_spa() ) {
			$classes[] = 'doublescale-client-portal-gate-view';
		}

		return $classes;
	}

	/**
	 * Invalidate the cached portal permalink when a page containing the
	 * shortcode is saved.
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
			PortalUrl::flush_cache();
		}
	}

	/**
	 * Shortcode callback. Mount node for logged-in customers; gate/notice
	 * otherwise. Optional `box_id` scopes the Tickets section to one mailbox;
	 * `title` overrides the brand heading on the gate.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
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

		if ( ! is_user_logged_in() ) {
			return $this->render_login_gate( $brand_title );
		}

		if ( Permissions::should_block_customer_portal() ) {
			return $this->render_staff_notice( $brand_title );
		}

		$box_id = max( 0, (int) $atts['box_id'] );

		// `alignfull` opts the portal into the block theme's full-bleed width.
		// Block themes constrain `.entry-content` children to the (narrow)
		// content size by default; the portal is a full app surface that owns
		// its page, so request the full size where supported. The shell caps its
		// own readable measure internally, so this widens the page without
		// running text edge-to-edge.
		return sprintf(
			'<div id="%s" class="alignfull" data-box-id="%d"></div>',
			esc_attr( self::MOUNT_ID ),
			$box_id
		);
	}

	/**
	 * Register + enqueue the renderer bundle on portal pages, for logged-in
	 * customers only.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( ! $this->should_load_portal_spa() || ! $this->current_page_has_shortcode() ) {
			return;
		}

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';

		$asset_file = $plugin_dir . 'build/renderer/portal/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$deps       = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;

		wp_register_script(
			self::HANDLE,
			$plugin_url . 'build/renderer/portal/index.js',
			$deps,
			$ver,
			true
		);

		wp_register_style(
			self::HANDLE,
			$plugin_url . 'build/renderer/portal/style.css',
			array(),
			$ver
		);

		wp_localize_script( self::HANDLE, 'doublescale_client_portal_config', $this->build_config() );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );

		// The portal app owns the whole page, so hide the theme's page title
		// ("Client Portal") and collapse the large header gap above it so the
		// app starts near the top. Scoped to the portal body class so every
		// other page keeps the theme's default title + spacing.
		wp_add_inline_style( self::HANDLE, $this->page_chrome_css() );
	}

	/**
	 * CSS that turns the host page into a clean, full-page app surface: it hides
	 * the theme's page title, site header (nav) and footer, then zeroes the top
	 * gap so the portal renders flush to the top (its own inner padding supplies
	 * the breathing room). Selectors cover block themes (`.wp-block-post-title`,
	 * `.wp-site-blocks > header|footer`, `*.wp-block-template-part`) and classic
	 * themes (`.entry-title`/`.site-header`/`.site-footer`); each is scoped to
	 * the portal body class so every other page keeps the theme's chrome, and is
	 * harmless on themes whose markup does not match. The WP admin bar is left
	 * intact (it is the logged-in customer's log-out affordance).
	 *
	 * @return string
	 */
	private function page_chrome_css(): string {
		$b = 'body.doublescale-client-portal-page ';

		return $b . '.wp-block-post-title,'
			. $b . '.entry-title,'
			. $b . '.page-title,'
			. $b . '.entry-header,'
			. $b . '.wp-site-blocks > header,'
			. $b . 'header.wp-block-template-part,'
			. $b . '.site-header,'
			. $b . '.wp-site-blocks > footer,'
			. $b . 'footer.wp-block-template-part,'
			. $b . '.site-footer{display:none!important}'
			. $b . 'main{margin-top:0!important}'
			. $b . 'main>.wp-block-group{padding-top:0!important}';
	}

	/**
	 * Build the localized config object handed to the renderer.
	 *
	 * Carries the shared REST root + nonce (so reused support hooks work) plus
	 * the portal namespace. Domain modules inject their own keys through the
	 * `doublescale_client_portal_config` filter (e.g. Support adds attachment
	 * limits + custom-fields flag) to keep this handler decoupled.
	 *
	 * @return array<string, mixed>
	 */
	private function build_config(): array {
		$user = wp_get_current_user();

		$config = array(
			'rest_root'            => esc_url_raw( rest_url() ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'portal_rest_url'      => esc_url_raw( rest_url( 'doublescale/v1/portal' ) ),
			'user'                 => array(
				'id'           => (int) $user->ID,
				'email'        => sanitize_email( $user->user_email ),
				'display_name' => $user->display_name ? sanitize_text_field( $user->display_name ) : '',
				'avatar'       => esc_url_raw( (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) ),
			),
			'lang'                 => get_locale(),
			'mount_id'             => self::MOUNT_ID,
			'is_guest'             => false,
			'guest_hash'           => '',
			'calendarWeekStartsOn' => \DoubleScale\Core\Settings\Settings::get_calendar_week_starts_on(),
		);

		/**
		 * Filter the Client Portal renderer config. Domain modules contribute
		 * their own keys (e.g. the Support module adds the support REST bases,
		 * attachment limits and custom-fields flag the reused ticket views need).
		 *
		 * @param array<string, mixed> $config Localized config.
		 * @param \WP_User             $user   Current user.
		 */
		return (array) apply_filters( 'doublescale_client_portal_config', $config, $user );
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
			body.doublescale-client-portal-gate-view .entry-header,
			body.doublescale-client-portal-gate-view .entry-title,
			body.doublescale-client-portal-gate-view .page-header,
			body.doublescale-client-portal-gate-view .wp-block-post-title {
				display: none;
			}
			.doublescale-client-portal-shell--login { max-width: 28rem; margin: 2rem auto; }
			.doublescale-client-portal-brand {
				margin: 0 0 1.5rem; text-align: center; font-size: 2rem; font-weight: 700;
				line-height: 1.2; color: #111827; letter-spacing: -0.02em;
			}
			.doublescale-client-portal-login {
				margin: 0; padding: 2rem 1.5rem; border: 1px solid #e5e7eb; border-radius: 0.75rem;
				background: #fff; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
			}
			.doublescale-client-portal-login-title {
				margin: 0 0 1.5rem; text-align: center; font-size: 1.125rem; font-weight: 600; line-height: 1.4;
			}
			.doublescale-client-portal-login #doublescale-client-portal-loginform p { margin-bottom: 1rem; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform label { display: block; margin-bottom: 0.25rem; font-weight: 500; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="text"],
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="password"] {
				width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem;
			}
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-remember { display: flex; align-items: center; gap: 0.5rem; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-remember label { margin: 0; font-weight: 400; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-submit { margin: 0; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="submit"] {
				width: 100%; padding: 0.625rem 1rem; border: 0; border-radius: 0.375rem;
				background: #3f3d9a; color: #fff; font-weight: 600; cursor: pointer;
			}
			.doublescale-client-portal-login-link { margin: 0.75rem 0 0; text-align: center; font-size: 0.875rem; }
		';

		wp_register_style( 'doublescale-client-portal-login', false, array(), defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0' );
		wp_enqueue_style( 'doublescale-client-portal-login' );
		wp_add_inline_style( 'doublescale-client-portal-login', $css );
	}

	/**
	 * Whether the portal SPA bundle should load (vs. the gate/notice HTML).
	 *
	 * @return bool
	 */
	private function should_load_portal_spa(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return ! Permissions::should_block_customer_portal();
	}

	/**
	 * True if the currently-rendering post contains our shortcode.
	 *
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

		return has_shortcode( $post->post_content, self::SHORTCODE );
	}

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
		 * Filter the brand title shown above the Client Portal login gate.
		 *
		 * @param string $title Brand heading text.
		 */
		return (string) apply_filters( 'doublescale_client_portal_brand_title', __( 'DoubleScale', 'doublescale' ) );
	}

	/**
	 * Login gate for logged-out visitors.
	 *
	 * @param string $brand_title Portal brand heading.
	 * @return string
	 */
	private function render_login_gate( string $brand_title ): string {
		$redirect = get_permalink();
		if ( ! $redirect ) {
			$redirect = home_url( '/' );
		}

		// Preserve an emailed deep-link target (e.g. ?doublescale_portal_path=bookings/78)
		// across the login round-trip. Without this the WordPress login redirect lands the
		// visitor on the bare portal page (dashboard) instead of the route they clicked.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect hint; value is sanitized below and only used as a same-page redirect target.
		$deep_link_path = isset( $_GET[ PortalUrl::PATH_QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ PortalUrl::PATH_QUERY_ARG ] ) ) : '';
		if ( '' !== $deep_link_path ) {
			$redirect = add_query_arg( PortalUrl::PATH_QUERY_ARG, rawurlencode( $deep_link_path ), $redirect );
		}

		/**
		 * Filter the headline shown above the portal login form.
		 *
		 * @param string $message Login gate message.
		 */
		$message = (string) apply_filters(
			'doublescale_client_portal_login_message',
			__( 'Please login or create an account to access your portal', 'doublescale' )
		);

		/**
		 * Filter arguments passed to {@see wp_login_form()} on the portal page.
		 *
		 * @param array  $args     wp_login_form() arguments.
		 * @param string $redirect Post-login redirect URL.
		 */
		$form_args = apply_filters(
			'doublescale_client_portal_login_form_args',
			array(
				'echo'           => false,
				'redirect'       => $redirect,
				'remember'       => true,
				'value_remember' => true,
				'form_id'        => 'doublescale-client-portal-loginform',
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
				'<p class="doublescale-client-portal-login-link">%s <a href="%s">%s</a></p>',
				esc_html__( 'Not registered?', 'doublescale' ),
				esc_url( wp_registration_url() ),
				esc_html__( 'Create an account', 'doublescale' )
			);
		}

		$links .= sprintf(
			'<p class="doublescale-client-portal-login-link">%s <a href="%s">%s</a></p>',
			esc_html__( 'Forgot your password?', 'doublescale' ),
			esc_url( wp_lostpassword_url( $redirect ) ),
			esc_html__( 'Reset password', 'doublescale' )
		);

		return sprintf(
			'<div class="doublescale-client-portal-shell doublescale-client-portal-shell--login">' .
			'<h1 class="doublescale-client-portal-brand">%s</h1>' .
			'<div class="doublescale-client-portal-gate doublescale-client-portal-login">' .
			'<p class="doublescale-client-portal-login-title">%s</p>%s%s</div></div>',
			esc_html( $brand_title ),
			esc_html( $message ),
			$form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_login_form() returns escaped HTML.
			$links
		);
	}

	/**
	 * Staff-facing notice when an agent visits the customer portal page.
	 *
	 * @param string $brand_title Portal brand heading.
	 * @return string
	 */
	private function render_staff_notice( string $brand_title ): string {
		/**
		 * Filter the message shown when staff visit the customer portal.
		 *
		 * @param string $message Notice text.
		 */
		$message = (string) apply_filters(
			'doublescale_client_portal_staff_notice',
			__( 'The Client Portal is only accessible by customers. Looks like you are a staff member.', 'doublescale' )
		);

		$menu_slug = (string) apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );
		$admin_url = admin_url( 'admin.php?page=' . rawurlencode( $menu_slug ) );

		return sprintf(
			'<div class="doublescale-client-portal-shell doublescale-client-portal-shell--login">' .
			'<h1 class="doublescale-client-portal-brand">%s</h1>' .
			'<div class="doublescale-client-portal-gate" style="text-align:center;padding:2rem 1rem;">' .
			'<h3>%s</h3><p><a href="%s">%s</a></p></div></div>',
			esc_html( $brand_title ),
			esc_html( $message ),
			esc_url( $admin_url ),
			esc_html__( 'Go to Admin', 'doublescale' )
		);
	}
}
