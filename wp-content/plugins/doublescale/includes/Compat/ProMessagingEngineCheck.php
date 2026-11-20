<?php
/**
 * Tell sites whose DoubleScale Pro predates Pro's own WhatsApp engine to update
 * it: WhatsApp is turned off there until they do.
 *
 * WhatsApp's campaign engine moved from DoubleScale into DoubleScale Pro: Pro
 * 1.3.0 ships its own, and DoubleScale 1.8.0 stopped shipping free's. A site
 * that keeps an older Pro (an expired licence keeps Pro running but stops its
 * updates) gets stand-ins instead ({@see LegacyWhatsapp}): nothing breaks, and
 * nothing WhatsApp sends.
 *
 * Such a site sees DoubleScale's admin through its older Pro's bundle, which
 * cannot show anything this release adds, and DoubleScale's own screens hide
 * admin notices. So the warning is printed by PHP: a notice on the Dashboard
 * and Plugins screens, a banner on every DoubleScale screen, a Site Health
 * recommendation and a line under a DoubleScale update row.
 *
 * Pro 1.3.0+ answers true on `doublescale_pro_messaging_engine_capable`.
 *
 * @package DoubleScale\Compat
 */

namespace DoubleScale\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * ProMessagingEngineCheck.
 */
final class ProMessagingEngineCheck {

	/** First Pro release that ships its own WhatsApp engine. */
	const PRO_ENGINE_VERSION = \DoubleScale\Modules\Campaigns\Services\ChannelEngineRegistry::PRO_ENGINE_VERSION;

	/** First DoubleScale release that no longer ships the WhatsApp engine. */
	const RELEASE_WITHOUT_ENGINE = '1.8.0';

	/** User meta: the Pro version the notice was dismissed for. */
	const DISMISS_META = 'doublescale_dismissed_pro_engine_notice';

	/** Query argument carrying a dismissal. */
	const DISMISS_ARG = 'doublescale_dismiss_pro_engine_notice';

	/** Site Health test id. */
	const SITE_HEALTH_TEST = 'doublescale_pro_messaging_engine';

	/**
	 * Hook everything in.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'admin_init', array( self::class, 'maybe_dismiss' ) );
		add_filter( 'site_status_tests', array( self::class, 'add_site_health_test' ) );
		add_filter( 'auto_update_plugin', array( self::class, 'filter_auto_update' ), 10, 2 );
		add_action( 'in_plugin_update_message-' . plugin_basename( DOUBLESCALE_PLUGIN_FILE ), array( self::class, 'render_update_row_message' ), 10, 2 );
	}

	/**
	 * Whether Pro is active but predates its own WhatsApp engine.
	 *
	 * @return bool
	 */
	public static function pro_needs_update() {
		if ( ! function_exists( 'doublescale_is_pro_addon_active' ) || ! doublescale_is_pro_addon_active() ) {
			return false;
		}

		/**
		 * Whether the active DoubleScale Pro ships its own WhatsApp engine.
		 *
		 * @param bool $capable Pro 1.3.0+ answers true.
		 */
		return ! apply_filters( 'doublescale_pro_messaging_engine_capable', false );
	}

	/**
	 * The installed Pro version, '' when it cannot be read.
	 *
	 * @return string
	 */
	public static function installed_pro_version() {
		return defined( 'DOUBLESCALE_PRO_VERSION' ) ? (string) DOUBLESCALE_PRO_VERSION : '';
	}

	/**
	 * What a dismissal is stored against: the installed Pro version, or
	 * 'unknown' when it cannot be read — never '', which is also what an
	 * absent dismissal reads as.
	 *
	 * @return string
	 */
	public static function dismissal_version() {
		$version = self::installed_pro_version();

		return '' !== $version ? $version : 'unknown';
	}

	/**
	 * The message itself, shared by the notice and Site Health.
	 *
	 * @return string
	 */
	private static function message() {
		return sprintf(
			/* translators: %s: DoubleScale Pro version, e.g. "1.3.0". */
			__( 'WhatsApp is turned off on this site. WhatsApp campaigns and automations now run in DoubleScale Pro: update DoubleScale Pro to %s or newer to turn WhatsApp back on.', 'doublescale' ),
			self::PRO_ENGINE_VERSION
		);
	}

	/**
	 * Dashboard and Plugins screens only, for people who can update plugins,
	 * until they dismiss it for the installed Pro version.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'update_plugins' ) || ! self::pro_needs_update() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		$version = self::dismissal_version();
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $version ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> &middot; <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'DoubleScale:', 'doublescale' ),
			esc_html( self::message() ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Update now', 'doublescale' ),
			esc_url( wp_nonce_url( add_query_arg( self::DISMISS_ARG, rawurlencode( $version ) ), self::DISMISS_ARG ) ),
			esc_html__( 'Dismiss', 'doublescale' )
		);
	}

	/**
	 * A banner on DoubleScale's own screens, printed by PHP so an older Pro's
	 * admin bundle shows it too. Same audience and dismissal as the notice.
	 *
	 * @return void
	 */
	public static function render_app_banner() {
		if ( ! current_user_can( 'update_plugins' ) || ! self::pro_needs_update() ) {
			return;
		}

		$version = self::dismissal_version();
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $version ) {
			return;
		}

		printf(
			'<div id="doublescale-pro-engine-banner" role="alert" style="position:fixed;right:24px;bottom:24px;z-index:100000;max-width:420px;padding:16px 18px;border-radius:14px;background:#fff;border:1px solid #F5C26B;border-left:5px solid #E8A33D;box-shadow:0 18px 40px rgba(27,17,69,.18);font:14px/1.5 -apple-system,BlinkMacSystemFont,Inter,sans-serif;color:#2B2245;">'
			. '<strong style="display:block;margin-bottom:4px;">%1$s</strong>%2$s'
			. '<div style="margin-top:12px;display:flex;gap:14px;align-items:center;"><a href="%3$s" style="background:#2D1B69;color:#fff;padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;">%4$s</a>'
			. '<a href="%5$s" style="color:#6B6787;text-decoration:none;">%6$s</a></div></div>',
			esc_html__( 'WhatsApp is turned off', 'doublescale' ),
			esc_html( self::message() ),
			esc_url( admin_url( 'plugins.php' ) ),
			esc_html__( 'Update DoubleScale Pro', 'doublescale' ),
			esc_url( wp_nonce_url( add_query_arg( self::DISMISS_ARG, rawurlencode( $version ) ), self::DISMISS_ARG ) ),
			esc_html__( 'Dismiss', 'doublescale' )
		);
	}

	/**
	 * Remember a dismissal for the installed Pro version, so a later, still
	 * older Pro brings the notice back.
	 *
	 * @return void
	 */
	public static function maybe_dismiss() {
		if ( ! isset( $_GET[ self::DISMISS_ARG ], $_GET['_wpnonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::DISMISS_ARG ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, self::dismissal_version() );
	}

	/**
	 * Add the Site Health check while Pro is active.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public static function add_site_health_test( $tests ) {
		if ( ! function_exists( 'doublescale_is_pro_addon_active' ) || ! doublescale_is_pro_addon_active() ) {
			return $tests;
		}

		$tests['direct'][ self::SITE_HEALTH_TEST ] = array(
			'label' => __( 'DoubleScale Pro and WhatsApp sending', 'doublescale' ),
			'test'  => array( self::class, 'site_health_result' ),
		);

		return $tests;
	}

	/**
	 * Site Health result.
	 *
	 * @return array
	 */
	public static function site_health_result() {
		$needs_update = self::pro_needs_update();

		return array(
			'label'       => $needs_update
				? __( 'DoubleScale Pro needs an update for WhatsApp sending', 'doublescale' )
				: __( 'DoubleScale Pro is ready for WhatsApp sending', 'doublescale' ),
			'status'      => $needs_update ? 'recommended' : 'good',
			'badge'       => array(
				'label' => __( 'DoubleScale', 'doublescale' ),
				'color' => $needs_update ? 'orange' : 'blue',
			),
			'description' => '<p>' . esc_html(
				$needs_update
					? self::message()
					: __( 'DoubleScale Pro runs WhatsApp campaign sending on this site.', 'doublescale' )
			) . '</p>',
			'actions'     => $needs_update
				? sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'plugins.php' ) ), esc_html__( 'Update DoubleScale Pro', 'doublescale' ) )
				: '',
			'test'        => self::SITE_HEALTH_TEST,
		);
	}

	/**
	 * Never install an engineless DoubleScale automatically next to an older Pro.
	 *
	 * @param bool|null $update Whether to update.
	 * @param object    $item   Update offer.
	 * @return bool|null
	 */
	public static function filter_auto_update( $update, $item ) {
		if ( ! self::is_engineless_free_update( $item ) ) {
			return $update;
		}

		return self::pro_needs_update() ? false : $update;
	}

	/**
	 * A line under DoubleScale's update row when the update drops the engine.
	 *
	 * @param array        $plugin_data Plugin header data.
	 * @param object|array $response    Update offer.
	 * @return void
	 */
	public static function render_update_row_message( $plugin_data, $response ) {
		if ( ! self::is_engineless_free_update( (object) $response ) || ! self::pro_needs_update() ) {
			return;
		}

		printf(
			'<br /><strong>%s</strong>',
			esc_html(
				sprintf(
					/* translators: %s: DoubleScale Pro version, e.g. "1.3.0". */
					__( 'Update DoubleScale Pro to %s or newer before this update, or WhatsApp is turned off on this site until you do.', 'doublescale' ),
					self::PRO_ENGINE_VERSION
				)
			)
		);
	}

	/**
	 * Whether an update offer is a DoubleScale release without the engine.
	 *
	 * @param mixed $item Update offer.
	 * @return bool
	 */
	private static function is_engineless_free_update( $item ) {
		if ( ! is_object( $item ) || empty( $item->new_version ) ) {
			return false;
		}

		// The plugin path identifies the offer; the slug only stands in when
		// an offer carries no path.
		$plugin = isset( $item->plugin ) ? (string) $item->plugin : '';
		if ( '' !== $plugin ) {
			if ( plugin_basename( DOUBLESCALE_PLUGIN_FILE ) !== $plugin ) {
				return false;
			}
		} elseif ( ! isset( $item->slug ) || 'doublescale' !== (string) $item->slug ) {
			return false;
		}

		// Only the step across the release that dropped the engine: once a site
		// runs it, later updates change nothing for WhatsApp and stay automatic.
		$installed = defined( 'DOUBLESCALE_VERSION' ) ? (string) DOUBLESCALE_VERSION : '0';

		/**
		 * The DoubleScale version the site runs (tests stand in an older one,
		 * since this check is about the step from below 1.8.0).
		 *
		 * @param string $installed Installed version.
		 */
		$installed = (string) apply_filters( 'doublescale_engine_check_installed_version', $installed );

		return version_compare( $installed, self::RELEASE_WITHOUT_ENGINE, '<' )
			&& version_compare( (string) $item->new_version, self::RELEASE_WITHOUT_ENGINE, '>=' );
	}
}
