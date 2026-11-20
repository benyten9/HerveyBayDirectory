<?php
/**
 * Customer-facing Client Portal renderer.
 *
 * Renders the `[doublescale_client_portal]` shortcode — the unified shell that
 * hosts the Tickets, Bookings, Dashboard (and gated Documents) sections.
 * Generalised from {@see \DoubleScale\Modules\Support\Renderer\PortalFrontendHandler}:
 *
 *   - Shortcode-driven (does NOT hijack `template_redirect`), so it renders
 *     inline on whatever page the admin pasted the shortcode onto. Theme
 *     header/footer/content stay intact. The SPA mounts in a Shadow DOM so
 *     theme CSS cannot change portal colors, padding, or typography.
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
use DoubleScale\Modules\Portal\Services\PortalLoginSettings;
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

		// The business's own brand (Settings → Business), never the product's name.
		$brand_html = $this->brand_html( PortalLoginSettings::brand( (string) $atts['title'] ) );

		if ( ! is_user_logged_in() ) {
			// The page's content may not hold the shortcode (a synced pattern,
			// a page builder, a theme template): style the form from here.
			$this->enqueue_login_styles();
			return $this->render_login_gate( $brand_html );
		}

		if ( Permissions::should_block_customer_portal() ) {
			return $this->render_staff_notice( $brand_html );
		}

		// Same reason: without this the mount below stays empty.
		$this->enqueue_portal_assets_late();

		$box_id = max( 0, (int) $atts['box_id'] );

		return sprintf(
			'<div id="%s" data-box-id="%d"></div>',
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
		if ( ! $this->current_page_has_shortcode() ) {
			return;
		}

		$this->enqueue_host_isolation_styles();

		if ( ! $this->should_load_portal_spa() ) {
			return;
		}

		$this->enqueue_portal_assets();
	}

	/**
	 * Add the portal from the shortcode itself when the page's content did not
	 * hold it, so `wp_enqueue_scripts` skipped it. Scripts added this late print
	 * in the footer, after the mount node. The body class the host styles key
	 * on was printed already, so the script adds it.
	 *
	 * @return void
	 */
	private function enqueue_portal_assets_late(): void {
		if ( $this->portal_config_added() || wp_script_is( self::HANDLE, 'done' ) ) {
			return;
		}

		$this->enqueue_host_isolation_styles();
		$this->enqueue_portal_assets();
		wp_add_inline_script(
			self::HANDLE,
			'document.body.classList.add("doublescale-client-portal-page");',
			'before'
		);
	}

	/**
	 * Whether this page already carries the portal config. The script handle is
	 * shared with the document and support pages, so the script being enqueued
	 * does not say the portal is.
	 *
	 * @return bool
	 */
	private function portal_config_added(): bool {
		$data = wp_scripts()->get_data( self::HANDLE, 'data' );

		return is_string( $data ) && false !== strpos( $data, 'doublescale_client_portal_config' );
	}

	/**
	 * The portal script with its config and the business details documents show.
	 *
	 * @return void
	 */
	private function enqueue_portal_assets(): void {
		if ( $this->portal_config_added() ) {
			return;
		}

		$handle = \DoubleScale\Modules\Portal\Renderer\PublicFrontendAssets::enqueue_script();
		wp_localize_script( $handle, 'doublescale_client_portal_config', $this->build_config() );

		// Documents opened in the portal show the same company block as the
		// admin preview and the public proposal/invoice pages.
		$business = class_exists( \DoubleScale\Modules\Documents\Services\DocumentPdf::class )
			? \DoubleScale\Modules\Documents\Services\DocumentPdf::branding_config()
			: array(
				'business_name'    => '',
				'business_address' => '',
				'business_logo'    => '',
			);
		wp_add_inline_script(
			$handle,
			'window.doublescaleConfig = window.doublescaleConfig || {};'
			. 'window.doublescaleConfig.business = ' . wp_json_encode( $business ) . ';'
			. 'window.doublescaleConfig.blogName = ' . wp_json_encode( get_bloginfo( 'name' ) ) . ';',
			'before'
		);
		// Portal CSS is fetched and inlined into the Shadow DOM (see style_urls).
		// Do not print it in the light DOM or the theme can restyle escaped dialogs.
	}

	/**
	 * Size the shortcode host only. Portal UI lives in a Shadow DOM so theme
	 * CSS cannot reach colors/padding inside; this just keeps the host slot
	 * full-width and padding-free inside the theme content column.
	 *
	 * @return void
	 */
	private function enqueue_host_isolation_styles(): void {
		$version = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';
		$css     = '
			/* Fill the theme content column only — never force a wider rem max-width. */
			body.doublescale-client-portal-page .elementor-widget:has(#doublescale-client-portal),
			body.doublescale-client-portal-page .elementor-widget-container:has(#doublescale-client-portal),
			body.doublescale-client-portal-page .elementor-shortcode:has(#doublescale-client-portal),
			body.doublescale-client-portal-page .wp-block-shortcode:has(#doublescale-client-portal){
				width:100%!important;
				max-width:100%!important;
			}
			body.doublescale-client-portal-page .is-layout-constrained > #doublescale-client-portal,
			body.doublescale-client-portal-page .entry-content > #doublescale-client-portal,
			body.doublescale-client-portal-page .wp-block-post-content > #doublescale-client-portal,
			body.doublescale-client-portal-page #doublescale-client-portal{
				position:relative!important;
				z-index:1;
				display:block!important;
				float:none!important;
				clear:both;
				width:100%!important;
				max-width:100%!important;
				margin-left:0!important;
				margin-right:0!important;
				padding:0!important;
				border:0!important;
				background:transparent!important;
				box-shadow:none!important;
				left:auto!important;
				right:auto!important;
				transform:none!important;
				box-sizing:border-box!important;
			}
			body.doublescale-client-portal-page .doublescale-client-portal-shell{
				position:relative!important;
				z-index:1;
				display:block!important;
				width:100%!important;
				max-width:100%!important;
				margin-left:0!important;
				margin-right:0!important;
				box-sizing:border-box!important;
				font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif!important;
				font-size:16px!important;
				line-height:1.5!important;
				color:#29292e!important;
				background:transparent!important;
			}
		';

		wp_register_style( 'doublescale-client-portal-host', false, array(), $version );
		wp_enqueue_style( 'doublescale-client-portal-host' );
		wp_add_inline_style( 'doublescale-client-portal-host', $css );
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

		$plugin_dir = defined( 'DOUBLESCALE_PLUGIN_DIR' ) ? \DOUBLESCALE_PLUGIN_DIR : '';
		$plugin_url = defined( 'DOUBLESCALE_PLUGIN_URL' ) ? \DOUBLESCALE_PLUGIN_URL : '';
		$version    = defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0';
		$asset_file = $plugin_dir . 'build/renderer/portal/index.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : null;
		$ver        = isset( $asset['version'] ) ? $asset['version'] : $version;
		$style_file = is_rtl() ? 'style-rtl.css' : 'style.css';
		$style_url  = $plugin_url
			? add_query_arg( 'ver', $ver, $plugin_url . 'build/renderer/portal/' . $style_file )
			: '';

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
			// Dates as the site writes them (Settings → General → Date Format), in the site's language.
			'date_format'          => (string) get_option( 'date_format', 'F j, Y' ),
			// The site's Date / Time Format and week start for the shared helpers.
			'site_dates'           => \DoubleScale\Core\Settings\SiteDates::config(),
			'locale'               => str_replace( '_', '-', (string) get_locale() ),
			'welcome_message'      => \DoubleScale\Modules\Portal\Services\PortalWelcomeSettings::message(),
			// The calendar's type chips: only the kinds this client may see.
			'calendar_kinds'       => self::calendar_kinds(),
			// Full-screen page: the portal fills the window instead of a rounded box.
			'full_screen'          => \DoubleScale\Modules\Portal\Services\PortalPageLayout::is_standalone_request(),
			// Settings → Client Portal → Brand colour: the portal derives every shade from it.
			'brand_color'          => \DoubleScale\Modules\Portal\Services\PortalPageLayout::brand_color(),
			// The portal is the agency's: its name and logo top the sidebar, linking back to the site.
			'business'             => array(
				'name' => \DoubleScale\Modules\Portal\Services\PortalLoginSettings::default_heading(),
				'logo' => esc_url_raw( \DoubleScale\Modules\Portal\Services\PortalLoginSettings::business()['logo'] ),
			),
			'home_url'             => esc_url_raw( home_url( '/' ) ),
			// The client's contact owner, shown as "Your account manager".
			'account_manager'      => self::account_manager(),
			// The client's company, under the agency's name in the sidebar.
			'client_company'       => self::client_company(),
			// The company the client shares work with, and which areas it shares.
			'company'              => self::company(),
			// WordPress's own log-out link (with its nonce), back to this page:
			// the client lands on the portal's login form.
			'logout_url'           => esc_url_raw( wp_logout_url( $this->current_page_url() ) ),
			// The invoice page and its hash argument: the portal's Pay button opens
			// the invoice there (Stripe's card form cannot load in the shadow root).
			'invoice_page_url'     => esc_url_raw( \DoubleScale\Modules\Documents\Services\InvoiceUrl::get_page_url() ),
			'invoice_hash_arg'     => \DoubleScale\Modules\Documents\Services\InvoiceUrl::HASH_QUERY_ARG,
			// Shadow DOM mounts fetch these and rewrite rem→px so theme html font-size
			// / CSS variables cannot change portal colors or padding.
			'style_urls'           => $style_url ? array( esc_url_raw( $style_url ) ) : array(),
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
	 * Calendar kinds the signed-in client may see (the same rule the calendar
	 * feed applies), so the dashboard offers no chip for a hidden area.
	 *
	 * @return string[]
	 */
	private static function calendar_kinds(): array {
		$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();
		$access  = \DoubleScale\Modules\Portal\Services\PortalAccess::for_contact( $contact );
		// Deals are sales internals and never reach the portal: the client acts on the proposal instead.
		$kinds   = array( 'booking', 'contract', 'invoice', 'proposal', 'project' );

		return array_values(
			array_filter(
				$kinds,
				static fn( string $kind ): bool => \DoubleScale\Modules\Portal\Services\PortalAccessGate::visible( 'calendar_kinds', $kind, $access )
			)
		);
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

		$this->enqueue_login_styles();
	}

	/**
	 * Add the login gate's styles once.
	 *
	 * @return void
	 */
	private function enqueue_login_styles(): void {
		if ( wp_style_is( 'doublescale-client-portal-login', 'enqueued' ) || wp_style_is( 'doublescale-client-portal-login', 'done' ) ) {
			return;
		}

		$css = '
			body.doublescale-client-portal-gate-view .entry-header,
			body.doublescale-client-portal-gate-view .entry-title,
			body.doublescale-client-portal-gate-view .page-header,
			body.doublescale-client-portal-gate-view .wp-block-post-title {
				display: none;
			}
			.doublescale-client-portal-shell--login { max-width: 26.5rem; margin: 3rem auto; padding: 0 1rem; box-sizing: border-box; }
			.doublescale-client-portal-login {
				margin: 0; padding: 2.5rem 2.25rem 2rem; border: 1px solid #e7e8ef; border-radius: 1rem; background: #fff;
				box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 12px 32px -12px rgba(16, 24, 40, 0.18);
				font-family: inherit; color: #111827;
			}
			.doublescale-client-portal-brand { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; margin: 0 0 1.75rem; }
			.doublescale-client-portal-logo { display: block; max-width: 100%; height: auto; }
			.doublescale-client-portal-brand-name { margin: 0; font-size: 1.125rem; font-weight: 700; line-height: 1.3; letter-spacing: -0.01em; color: #111827; text-align: center; }
			.doublescale-client-portal-login-title { margin: 0 0 0.375rem; text-align: center; font-size: 1.375rem; font-weight: 700; line-height: 1.3; letter-spacing: -0.015em; color: #111827; }
			.doublescale-client-portal-login-subtitle { margin: 0 0 1.75rem; text-align: center; font-size: 0.9375rem; line-height: 1.5; color: #6b7280; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform p { margin: 0 0 1rem; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform label { display: block; margin-bottom: 0.375rem; font-size: 0.875rem; font-weight: 600; color: #374151; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="text"],
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="password"] {
				width: 100%; box-sizing: border-box; height: 2.75rem; padding: 0 0.875rem; border: 1px solid #d0d5dd; border-radius: 0.625rem;
				background: #fff; font-size: 0.9375rem; color: #111827; transition: border-color .15s ease, box-shadow .15s ease;
			}
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="text"]:focus,
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="password"]:focus {
				outline: none; border-color: {brand}; box-shadow: 0 0 0 4px {brand_ring};
			}
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-remember { display: flex; align-items: center; gap: 0.5rem; margin: 0.25rem 0 1.25rem; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-remember label { display: flex; align-items: center; gap: 0.5rem; margin: 0; font-weight: 500; color: #4b5563; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform .login-submit { margin: 0; }
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="submit"] {
				width: 100%; height: 2.875rem; padding: 0 1rem; border: 0; border-radius: 0.625rem;
				background: {brand} !important; color: {on_brand} !important; font-size: 0.9375rem; font-weight: 600; cursor: pointer;
				box-shadow: 0 1px 2px rgba(16, 24, 40, 0.08); transition: filter .15s ease;
			}
			.doublescale-client-portal-login #doublescale-client-portal-loginform input[type="submit"]:hover { filter: brightness(1.08); }
			.doublescale-client-portal-login-link { margin: 1rem 0 0; text-align: center; font-size: 0.875rem; color: #6b7280; }
			.doublescale-client-portal-login-link a { color: {brand}; font-weight: 600; text-decoration: none; }
			.doublescale-client-portal-login-link a:hover { text-decoration: underline; }
			@media (max-width: 480px) { .doublescale-client-portal-login { padding: 2rem 1.25rem 1.5rem; } }
			.doublescale-client-portal-login input[type="checkbox"] { accent-color: {brand}; }
			.doublescale-client-portal-login-art { display: none; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-shell.doublescale-client-portal-shell--login {
				display: grid !important; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); max-width: none !important; min-height: 100vh; margin: 0 !important; padding: 0 !important;
			}
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-art {
				display: flex; flex-direction: column; padding: 3rem 3.5rem; color: {on_brand};
				background: radial-gradient(600px 400px at 85% 15%, rgba(255,255,255,.18), transparent 60%), radial-gradient(500px 400px at 0% 100%, rgba(0,0,0,.18), transparent 60%), linear-gradient(150deg, {brand}, {brand_dark});
			}
			.doublescale-client-portal-login-art-name { margin: 0; font-size: 1.125rem; font-weight: 700; }
			.doublescale-client-portal-login-art-body { margin-top: auto; }
			.doublescale-client-portal-login-art-title { margin: 0; max-width: 32rem; font-size: 2.75rem; font-weight: 800; line-height: 1.08; letter-spacing: -0.03em; }
			.doublescale-client-portal-login-art-text { margin: 1rem 0 0; max-width: 29rem; font-size: 1.0625rem; line-height: 1.55; opacity: .85; }
			.doublescale-client-portal-login-art ul { display: flex; flex-wrap: wrap; gap: .625rem; margin: 1.75rem 0 0; padding: 0; list-style: none; }
			.doublescale-client-portal-login-art li { display: inline-flex; align-items: center; gap: .5rem; height: 2.375rem; padding: 0 .875rem; border-radius: 999px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.2); font-size: .875rem; font-weight: 600; }
			.doublescale-client-portal-login-art li span { display: inline-flex; align-items: center; justify-content: center; width: 1.125rem; height: 1.125rem; border-radius: 50%; background: #fff; color: {brand_dark}; font-size: .6875rem; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col { display: flex; align-items: center; justify-content: center; padding: 3rem 1.5rem; background: #fff; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-login { width: 100%; max-width: 25rem; padding: 0; border: 0; box-shadow: none; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-brand { align-items: flex-start; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-login-title,
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-login-subtitle,
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-brand-name { text-align: left; }
			body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-login-title { font-size: 1.875rem; letter-spacing: -0.02em; }
			@media (max-width: 900px) {
				body.doublescale-client-portal-standalone .doublescale-client-portal-shell.doublescale-client-portal-shell--login { grid-template-columns: 1fr; }
				body.doublescale-client-portal-standalone .doublescale-client-portal-login-art { display: none; }
				body.doublescale-client-portal-standalone .doublescale-client-portal-login-col { background: #f6f7fa; padding: 2rem 1rem; }
				body.doublescale-client-portal-standalone .doublescale-client-portal-login-col .doublescale-client-portal-login { max-width: 26.5rem; padding: 2.25rem 1.75rem 1.75rem; border: 1px solid #e7e8ef; border-radius: 1rem; background: #fff; box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 12px 32px -12px rgba(16, 24, 40, 0.18); }
			}
		';

		// Settings → Client Portal → Brand colour (always a valid #RRGGBB).
		$brand = \DoubleScale\Modules\Portal\Services\PortalPageLayout::brand_color();
		$rgb   = array_map( 'hexdec', str_split( substr( $brand, 1 ), 2 ) );
		// White text wherever it clears 3:1 (as the portal app does); dark only on light brands.
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$c    = $rgb[ $i ] / 255;
			$lum += $weight * ( $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 ) );
		}
		$css = strtr(
			$css,
			array(
				'{brand}'      => $brand,
				'{brand_ring}' => sprintf( 'rgba(%d, %d, %d, 0.14)', $rgb[0], $rgb[1], $rgb[2] ),
				'{on_brand}'   => ( 1.05 / ( $lum + 0.05 ) ) >= 3 ? '#fff' : '#121826',
				'{brand_dark}' => sprintf( '#%02X%02X%02X', $rgb[0] * 0.62, $rgb[1] * 0.62, $rgb[2] * 0.62 ),
			)
		);

		wp_register_style( 'doublescale-client-portal-login', false, array(), defined( 'DOUBLESCALE_VERSION' ) ? \DOUBLESCALE_VERSION : '1.0.0' );
		wp_enqueue_style( 'doublescale-client-portal-login' );
		wp_add_inline_style( 'doublescale-client-portal-login', $css );
	}

	/**
	 * The signed-in client's account manager: the owner of their contact, or
	 * null when the contact has none (or the owner's account is gone).
	 *
	 * @return array{name: string, email: string, avatar: string}|null
	 */
	private static function account_manager(): ?array {
		$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();
		$owner   = $contact && ! empty( $contact->owner_id ) ? get_userdata( (int) $contact->owner_id ) : false;
		if ( ! $owner ) {
			return null;
		}

		return array(
			'name'   => sanitize_text_field( $owner->display_name ? $owner->display_name : $owner->user_login ),
			'email'  => sanitize_email( $owner->user_email ),
			// A 404 instead of the grey silhouette: the portal shows initials then.
			'avatar' => esc_url_raw( (string) get_avatar_url( $owner->ID, array( 'size' => 96, 'default' => '404' ) ) ),
		);
	}

	/**
	 * The signed-in client's company name, or '' when the contact has none.
	 *
	 * @return string
	 */
	private static function client_company(): string {
		$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();

		return $contact ? sanitize_text_field( (string) $contact->company_name ) : '';
	}

	/**
	 * The signed-in client's company: its name (their main company), the
	 * areas where they see company work (their own portal access) and every
	 * company they belong to, or null.
	 *
	 * @return array{name: string, areas: string[], companies: array<int, array{id: int, name: string}>}|null
	 */
	private static function company(): ?array {
		$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();
		if ( ! $contact ) {
			return null;
		}

		/**
		 * Filters the signed-in client's company (DoubleScale Pro's companies).
		 *
		 * @param array{name: string, areas: string[], companies?: array}|null $company Null: no company.
		 * @param int                                       $contact_id Contact ID.
		 */
		$company = apply_filters( 'doublescale_portal_company', null, (int) $contact->id );
		if ( ! is_array( $company ) || empty( $company['name'] ) ) {
			return null;
		}

		// Every company they belong to, main first (older Pro: just the one).
		$companies = array();
		foreach ( (array) ( $company['companies'] ?? array() ) as $row ) {
			$id   = (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : 0 );
			$name = sanitize_text_field( (string) ( is_array( $row ) ? ( $row['name'] ?? '' ) : '' ) );
			if ( $id > 0 && '' !== $name ) {
				$companies[] = array(
					'id'   => $id,
					'name' => $name,
				);
			}
		}

		return array(
			'name'      => sanitize_text_field( (string) $company['name'] ),
			'areas'     => array_values( array_map( 'strval', (array) ( $company['areas'] ?? array() ) ) ),
			'companies' => $companies,
		);
	}

	/**
	 * The page the portal is on, or the site's home page.
	 *
	 * @return string
	 */
	private function current_page_url(): string {
		$page_id = is_singular() ? (int) get_queried_object_id() : 0;
		$url     = $page_id > 0 ? get_permalink( $page_id ) : '';

		return $url ? $url : home_url( '/' );
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
	 * The brand block above the login gate and staff notice: the business logo at the
	 * width set in Settings → Client Portal, and/or the business's heading.
	 *
	 * @param array{logo: string, logo_width: int, heading: string, alt: string} $brand From {@see PortalLoginSettings::brand()}.
	 * @return string
	 */
	private function brand_html( array $brand ): string {
		$html = '';
		if ( '' !== $brand['logo'] ) {
			$html .= sprintf(
				'<img class="doublescale-client-portal-logo" src="%s" alt="%s" style="width:%dpx" />',
				esc_url( $brand['logo'] ),
				esc_attr( $brand['alt'] ),
				(int) $brand['logo_width']
			);
		}
		if ( '' !== $brand['heading'] ) {
			$html .= sprintf( '<p class="doublescale-client-portal-brand-name">%s</p>', esc_html( $brand['heading'] ) );
		}
		return '' !== $html ? '<div class="doublescale-client-portal-brand">' . $html . '</div>' : '';
	}

	/**
	 * Login gate for logged-out visitors.
	 *
	 * @param string $brand_html Brand block (logo and/or heading).
	 * @return string
	 */
	private function render_login_gate( string $brand_html ): string {
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

		// Settings → Client Portal → Login page; defaults follow the portal's areas.
		$texts = PortalLoginSettings::texts();

		/**
		 * Filter the headline shown above the portal login form.
		 *
		 * @param string $message Login gate message.
		 */
		$message = (string) apply_filters(
			'doublescale_client_portal_login_message',
			$texts['title']
		);

		/**
		 * Filter the line under the login headline.
		 *
		 * @param string $subtitle Login gate subtitle ('' hides it).
		 */
		$subtitle = (string) apply_filters(
			'doublescale_client_portal_login_subtitle',
			$texts['subtitle']
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

		// The brand panel beside the form (full-screen page, wide windows): the
		// agency's name and what the portal is for, on the brand colour.
		$ticks = '';
		foreach ( $texts['points'] as $tick ) {
			$ticks .= '<li><span aria-hidden="true">&#10003;</span>' . esc_html( $tick ) . '</li>';
		}
		$art = sprintf(
			'<aside class="doublescale-client-portal-login-art" aria-hidden="true"><p class="doublescale-client-portal-login-art-name">%s</p>' .
			'<div class="doublescale-client-portal-login-art-body"><p class="doublescale-client-portal-login-art-title">%s</p>%s%s</div></aside>',
			esc_html( PortalLoginSettings::default_heading() ),
			esc_html( $texts['panel_title'] ),
			'' !== $texts['panel_text'] ? '<p class="doublescale-client-portal-login-art-text">' . nl2br( esc_html( $texts['panel_text'] ) ) . '</p>' : '',
			'' !== $ticks ? '<ul>' . $ticks . '</ul>' : ''
		);

		return sprintf(
			'<div class="doublescale-client-portal-shell doublescale-client-portal-shell--login">%s' .
			'<div class="doublescale-client-portal-login-col">' .
			'<div class="doublescale-client-portal-gate doublescale-client-portal-login">%s' .
			'<h1 class="doublescale-client-portal-login-title">%s</h1>%s%s%s</div></div></div>',
			$art, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$brand_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in brand_html().
			esc_html( $message ),
			'' !== $subtitle ? '<p class="doublescale-client-portal-login-subtitle">' . nl2br( esc_html( $subtitle ) ) . '</p>' : '',
			$form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_login_form() returns escaped HTML.
			$links
		);
	}

	/**
	 * Staff-facing notice when an agent visits the customer portal page.
	 *
	 * @param string $brand_html Brand block (logo and/or heading).
	 * @return string
	 */
	private function render_staff_notice( string $brand_html ): string {
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
			'<div class="doublescale-client-portal-gate doublescale-client-portal-login">%s' .
			'<p class="doublescale-client-portal-login-subtitle">%s</p><p class="doublescale-client-portal-login-link"><a href="%s">%s</a></p></div></div>',
			$brand_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in brand_html().
			esc_html( $message ),
			esc_url( $admin_url ),
			esc_html__( 'Go to Admin', 'doublescale' )
		);
	}
}
