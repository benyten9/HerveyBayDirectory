<?php
/**
 * Admin: Class Admin Loader
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 * @subpackage admin
 */

namespace DoubleScale\Admin;

defined( 'ABSPATH' ) || exit;


/**
 * Admin Loader Class.
 *
 * @since 1.0.0
 */
class AdminLoader {

	/**
	 * Class Instance.
	 *
	 * @var AdminLoader
	 *
	 * @since 1.0.0
	 */
	private static $instance;

	/**
	 * Admin Instance.
	 *
	 * Instantiates or reuses an instance of AdminLoader.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @see AdminLoader()
	 *
	 * @return self - Single instance
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Returns true if we are on a JS powered admin page.
	 */
	public static function is_admin_page(): bool {
		$current_screen = get_current_screen();
		if ( ! $current_screen ) {
			return false;
		}
		$menu_slug = apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );
		if ( false === strpos( $current_screen->id, 'doublescale' ) && false === strpos( $current_screen->id, (string) $menu_slug ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Constructor.
	 * Since this is a singleton class, it is better to have its constructor as a private.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Enqueue admin scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'add_inline_scripts' ), 14 );

		// Remove notices.
		add_action( 'admin_notices', array( $this, 'remove_notices' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'inject_before_notices' ), -9999 );
		add_action( 'admin_notices', array( __CLASS__, 'inject_after_notices' ), PHP_INT_MAX );

		// Remove admin footer text.
		add_filter( 'admin_footer_text', '__return_empty_string' );
		// Remove admin footer version.
		add_filter( 'update_footer', '__return_empty_string', 11 );
		add_filter( 'admin_body_class', array( __CLASS__, 'add_admin_body_class' ), PHP_INT_MAX );
	}

		/**
		 * Add admin body class.
		 *
		 * @since 1.0.0
		 *
		 * @param string $classes Body classes.
		 */
	public static function add_admin_body_class( $classes ) {
		if ( self::is_admin_page() ) {
			$classes .= ' js is-fullscreen-mode';
		}

		return $classes;
	}

	/**
	 * Runs before admin notices action and hides them.
	 *
	 * @since 1.0.0
	 */
	public static function inject_before_notices() {
		if ( ! self::is_admin_page() ) {
			return;
		}

		// Wrap the notices in a hidden div to prevent flickering before
		// they are moved elsewhere in the page by WordPress Core.
		echo '<div class="doublescale-layout__notice-list-hide" style="display: none;" id="wp__notice-list">';

		if ( self::is_admin_page() ) {
			// Capture all notices and hide them. WordPress Core looks for
			// `.wp-header-end` and appends notices after it if found.
			// https://github.com/WordPress/WordPress/blob/f6a37e7d39e2534d05b9e542045174498edfe536/wp-admin/js/common.js#L737 .
			echo '<div class="wp-header-end" id="doublescale-layout__notice-catcher"></div>';
		}
	}

	/**
	 * Runs after admin notices and closes div.
	 *
	 * @since 1.0.0
	 */
	public static function inject_after_notices() {
		if ( ! self::is_admin_page() ) {
				return;
		}
		// Close the hidden div used to prevent notices from flickering before
		// they are inserted elsewhere in the page.
		echo '</div>';
	}

	/**
	 * Remove Notices.
	 *
	 * @since 1.0.0
	 */
	public function remove_notices() {

		if ( ! self::is_admin_page() ) {
			return;
		}

		// Hello Dolly.
		if ( function_exists( 'hello_dolly' ) ) {
			remove_action( 'admin_notices', 'hello_dolly' );
		}
	}


	/**
	 * Add inline scripts.
	 *
	 * @since 1.0.0
	 */
	public function add_inline_scripts() {
		// The admin config blob (~15 managers, License/Store reads, many
		// current_user_can() calls) is only consumed by the DoubleScale SPA,
		// which loads only on DoubleScale screens. Skip the whole computation on
		// every other wp-admin page (Posts, Media, Settings, ...).
		if ( ! self::is_admin_page() ) {
			return;
		}
		AdminConfig::set_admin_config();
	}

	/**
	 * Enqueue Scripts.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		global $submenu;
		$user = wp_get_current_user();

		list( $bundle_dir, $bundle_url ) = self::get_admin_client_bundle_base();
		$asset_file                      = $bundle_dir . 'build/client/index.asset.php';
		$asset                           = file_exists( $asset_file ) ? require $asset_file : null;
		$dependencies                    = isset( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version                         = isset( $asset['version'] ) ? $asset['version'] : DOUBLESCALE_VERSION;

		// Register main script.
		wp_register_script(
			'doublescale-admin',
			$bundle_url . 'build/client/index.js',
			$dependencies,
			$version,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'doublescale-admin',
				'doublescale',
				DOUBLESCALE_PLUGIN_DIR . 'languages'
			);
		}

		// Register main stylesheet (SCSS-based styles).
		wp_register_style(
			'doublescale-admin',
			$bundle_url . 'build/client/style-index.css',
			array(),
			$version
		);
		wp_style_add_data( 'doublescale-admin', 'rtl', 'replace' );

		// Register entry-point stylesheet (CSS imports in JS).
		wp_register_style(
			'doublescale-admin-entry',
			$bundle_url . 'build/client/index.css',
			array(),
			$version
		);
		wp_style_add_data( 'doublescale-admin-entry', 'rtl', 'replace' );

		// Attach Pro config to the main script. Only the SPA (DoubleScale
		// screens) reads `doublescalePro`, so skip the nonce creation and
		// localize on every other wp-admin page.
		if ( self::is_admin_page() ) {
			$doublescale_pro_script_data = array(
				'version'   => DOUBLESCALE_VERSION,
				'pluginUrl' => DOUBLESCALE_PLUGIN_URL,
				'restUrl'   => rest_url( 'doublescale/v1/' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'isPro'     => self::admin_context_is_pro_plugin(),
			);
			if ( defined( 'DOUBLESCALE_PRO_PLUGIN_URL' ) ) {
				$doublescale_pro_script_data['proPluginUrl'] = DOUBLESCALE_PRO_PLUGIN_URL;
			}
			wp_localize_script(
				'doublescale-admin',
				'doublescalePro',
				$doublescale_pro_script_data
			);
		}

		// Enqueue WordPress media library for admin pages that need it.
		if ( self::is_admin_page() ) {
			wp_enqueue_media();

			if ( ! wp_style_is( 'doublescale-admin-loader', 'registered' ) ) {
				wp_register_style(
					'doublescale-admin-loader',
					DOUBLESCALE_PLUGIN_URL . 'assets/css/admin/loader.css',
					array(),
					DOUBLESCALE_VERSION
				);
			}
			// Splash styles must load in <head>. page_wrapper() runs after head is
			// printed, so enqueuing there lands loader.css in the footer and causes
			// a brief unstyled flash of the loading screen.
			wp_enqueue_style( 'doublescale-admin-loader' );
		}
	}

	/**
	 * Whether the Pro plugin is active in this request (admin SPA + sidebar).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function admin_context_is_pro_plugin(): bool {
		return function_exists( 'doublescale_is_pro_addon_active' )
			? doublescale_is_pro_addon_active()
			: defined( 'DOUBLESCALE_PRO_VERSION' );
	}

	/**
	 * Admin client bundle directory and URL (Pro replaces free when Pro is active and its build exists).
	 *
	 * If Pro is active but `build/client/` was never shipped or built, fall back to the free bundle
	 * so the SPA still loads; `doublescalePro.isPro` and JS filters still mark the install as Pro.
	 *
	 * @since 1.0.0
	 *
	 * @return array{0:string,1:string} Tuple of directory path and URL base (trailing slash).
	 */
	private static function get_admin_client_bundle_base(): array {
		if (
			self::admin_context_is_pro_plugin()
			&& defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' )
			&& defined( 'DOUBLESCALE_PRO_PLUGIN_URL' )
			&& is_readable( \DOUBLESCALE_PRO_PLUGIN_DIR . 'build/client/index.js' )
		) {
			return array( \DOUBLESCALE_PRO_PLUGIN_DIR, \DOUBLESCALE_PRO_PLUGIN_URL );
		}
		return array( \DOUBLESCALE_PLUGIN_DIR, \DOUBLESCALE_PLUGIN_URL );
	}

	/**
	 * Page Wrapper.
	 *
	 * @since 1.0.0
	 */
	public static function page_wrapper() {
		// Important to check for authentication.
		wp_auth_check_load();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Plugin-prefixed action hook; the prefix-detection rule misfires on the `doublescale_` prefix here.
		do_action( 'doublescale_admin_enqueue_scripts' );

		// Enqueue scripts and styles.
		wp_enqueue_script( 'doublescale-admin' );
		wp_enqueue_style( 'doublescale-admin' );
		wp_enqueue_style( 'doublescale-admin-entry' );
		wp_add_inline_style(
			'doublescale-admin',
			'.s0 { fill: url(#g1) } 
			.s1 { fill: url(#g2) } 
			.s2 { fill: #246193 } 
			.s3 { fill: #1e93cf } 
			.s4 { fill: url(#g3) } 
			.s5 { fill: url(#g4) } 
			.s6 { fill: url(#g5) } 
			.s7 { fill: url(#g6) } 
			.s8 { fill: url(#g7) } 
			.s9 { opacity: .3;fill: #373435 } 
			.s10 { fill: none } 
			.s11 { fill: url(#g8) }'
		);
		wp_enqueue_script( 'jquery' );

		// Ensure media library is available for the page
		wp_enqueue_media();

		if ( ! wp_style_is( 'doublescale-admin-loader', 'enqueued' ) ) {
			wp_enqueue_style( 'doublescale-admin-loader' );
		}

		?>
</head>
<body>
	<div class="doublescale-wrap">
	<div id="doublescale-admin-root">
		<div id="doublescale-admin-root__loader-container" style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;width:100%;min-height:100vh;background:linear-gradient(160deg,#1B1145 0%,#2D1B69 55%,#1B1145 100%);position:relative;overflow:hidden;">

		<div class="ds-logo-mark">
			<?php
			$default_logo = '<svg xmlns="http://www.w3.org/2000/svg" width="95" height="90" viewBox="189 -10 355 336" fill="none" preserveAspectRatio="xMidYMid meet">
            <path fill="white" d="M377.82 260.726C386.76 242.346 380.59 228.176 366.58 216.136C364.06 213.966 361.39 211.956 358.68 210.026C345.67 200.746 332.64 191.496 319.58 182.286C290.85 162.046 277.82 133.756 278.12 99.256C278.53 50.466 313.2 10.616 361.52 1.99603C395.59 -4.07397 426.56 4.02604 455.43 21.866C465.6 28.156 475.28 35.236 485.61 42.236C490.33 32.616 495.19 22.716 500.56 11.776C513.04 42.636 525.16 72.616 537.54 103.236C505.48 98.466 474.13 93.806 441.96 89.026C450.51 81.866 458.49 75.186 466.57 68.426C466.33 67.906 466.26 67.356 465.95 67.116C446.09 51.786 425.43 38.116 400.36 32.516C365.3 24.686 328.12 42.246 314.45 73.486C301.65 102.756 309.94 136.256 335.36 155.506C348.89 165.746 363.16 174.996 376.96 184.896C388.4 193.106 395.73 203.976 397.84 218.186C400 232.746 391.04 252.496 377.84 260.726H377.82Z"/>
            <path fill="rgba(196,181,253,0.85)" d="M247.9 272.236C242.92 282.256 238.18 291.776 233.43 301.276C233.3 301.546 232.94 301.696 232.23 302.276C219.81 272.606 207.45 243.096 194.76 212.776C226.83 217.416 258.05 221.936 290.51 226.636C281.92 233.576 273.93 240.036 265.34 246.976C268.83 249.616 272.04 252.156 275.35 254.536C293.41 267.516 312.23 278.946 334.39 283.686C366.67 290.596 396.6 279.506 414.38 251.876C433.56 222.076 427.52 182.816 398.23 160.806C384.81 150.716 370.62 141.646 356.96 131.856C345.89 123.916 338.3 113.636 336.07 99.736C333.17 81.646 340.56 68.206 354.38 57.316C350.24 66.656 349.66 75.876 354.41 85.156C358.63 93.406 365.19 99.506 372.55 104.756C387.05 115.096 401.71 125.226 416.31 135.426C439.66 151.756 451.28 174.956 455.1 202.486C461.42 247.946 434.57 294.336 386.57 310.536C359.69 319.606 332.85 317.666 306.51 307.406C286.64 299.666 268.82 288.516 252.15 275.386C250.86 274.366 249.51 273.416 247.92 272.236H247.9Z"/>
          </svg>';
			echo apply_filters( 'doublescale_loading_screen_logo', $default_logo ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>

		<?php
		$default_wordmark = '<div class="ds-wordmark">DoubleScale</div>';
		echo apply_filters( 'doublescale_loading_screen_wordmark', $default_wordmark ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filterable branding markup, same contract as doublescale_loading_screen_logo above; filters must return pre-escaped HTML.
		?>

		<div class="ds-bar"></div>

		</div>
	</div>
	</div>
		<?php
	}
}