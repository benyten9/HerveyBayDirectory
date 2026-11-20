<?php
/**
 * Closes the client portal when Settings → Modules turns it off.
 *
 * The portal module then does not boot, but other modules (bookings, sales
 * documents, projects…) register their own `portal/*` endpoints, and a page may
 * still hold the portal shortcode. This gate runs whatever modules are on.
 *
 * @package DoubleScale\Core\Services
 */

namespace DoubleScale\Core\Services;

defined( 'ABSPATH' ) || exit;

final class ClientPortalGate {

	/** Shortcode the portal page renders (kept in step with the portal module). */
	private const SHORTCODE = 'doublescale_client_portal';

	/**
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_pre_dispatch', array( self::class, 'block_portal_routes' ), 5, 3 );
		// After modules boot: the portal module registers the real shortcode when it is on.
		add_action( 'init', array( self::class, 'replace_shortcode' ), 99 );
		// Last, after Pro and add-ons said what the portal can show.
		add_filter( 'doublescale_admin_config', array( self::class, 'admin_config' ), 999 );
	}

	/**
	 * With the portal off, the team's screens offer nothing to show in it
	 * (Projects' "Show tasks / time / notes in the client portal").
	 *
	 * @param mixed $config Admin config.
	 * @return mixed
	 */
	public static function admin_config( $config ) {
		if ( ! is_array( $config ) || doublescale_client_portal_enabled() ) {
			return $config;
		}
		foreach ( array( 'portalProjectTasks', 'portalProjectTime', 'portalTimeNotes' ) as $key ) {
			if ( array_key_exists( $key, $config ) ) {
				$config[ $key ] = false;
			}
		}

		return $config;
	}

	/**
	 * Whether a REST route belongs to the client portal: the client's own
	 * endpoints and the team's per-contact portal access.
	 *
	 * @param string $route Route, e.g. /doublescale/v1/portal/contact.
	 * @return bool
	 */
	public static function is_portal_route( string $route ): bool {
		return (bool) preg_match( '#^/doublescale/v1/(portal(/|$)|contacts/\d+/portal-)#', $route );
	}

	/**
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function block_portal_routes( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || doublescale_client_portal_enabled() ) {
			return $result;
		}
		if ( ! self::is_portal_route( (string) $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error(
			'doublescale_portal_disabled',
			__( 'The client portal is turned off.', 'doublescale' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * With the portal off, its page shows nothing to visitors (not the raw
	 * shortcode), and tells an admin why.
	 *
	 * @return void
	 */
	public static function replace_shortcode() {
		if ( doublescale_client_portal_enabled() ) {
			return;
		}
		remove_shortcode( self::SHORTCODE );
		add_shortcode(
			self::SHORTCODE,
			static function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return '';
				}

				return '<p class="doublescale-portal-off">' . esc_html__( 'The client portal is turned off. Turn it on in DoubleScale → Settings → Modules.', 'doublescale' ) . '</p>';
			}
		);
	}
}
