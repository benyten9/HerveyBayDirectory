<?php
/**
 * Customer satisfaction routes.
 *
 *   POST support/public/satisfaction           The customer's click on the rating page:
 *                                              { token, score 1-5, comment }. Public; the
 *                                              signed token is the proof. Limited per address.
 *   GET|POST support/satisfaction-settings     On/off and link days (helpdesk settings tier).
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Pro\Modules\Support\Services\SatisfactionLinks;
use DoubleScale\Pro\Modules\Support\Services\SatisfactionRecorder;
use DoubleScale\Pro\Modules\Support\Services\SatisfactionSettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSatisfactionController class.
 */
class RestSatisfactionController extends RestController {

	/**
	 * Ratings one address may send an hour.
	 */
	private const PER_HOUR = 30;

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/support/public/satisfaction',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rate' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'score'   => array(
							'type'     => 'integer',
							'required' => true,
						),
						'comment' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/support/satisfaction-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'settings_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rate( $request ) {
		nocache_headers();
		if ( ! SatisfactionSettings::available() ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( RateLimiter::hit( 'csat|' . ClientIp::bucket(), HOUR_IN_SECONDS ) > self::PER_HOUR ) {
			return new WP_Error( 'csat_rate_limited', __( 'Too many ratings from here. Please try again later.', 'doublescale' ), array( 'status' => 429 ) );
		}
		$score = (int) $request->get_param( 'score' );
		if ( $score < 1 || $score > 5 ) {
			return new WP_Error( 'csat_score', __( 'Please choose a face.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$ticket = SatisfactionLinks::verify( (string) $request->get_param( 'token' ) );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		if ( ! in_array( (string) $ticket->status, array( TicketStatus::RESOLVED, TicketStatus::CLOSED ), true ) ) {
			return new WP_Error( 'csat_open', __( 'This request is still open. You can rate it once it is solved.', 'doublescale' ), array( 'status' => 409 ) );
		}

		SatisfactionRecorder::record( $ticket, $score, (string) $request->get_param( 'comment' ), 'email' );

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( SatisfactionSettings::get(), 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( $request ) {
		$body = array_intersect_key( (array) $request->get_json_params(), array_flip( array( 'enabled', 'days' ) ) );

		return new WP_REST_Response( SatisfactionSettings::update( $body ), 200 );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function settings_permissions_check() {
		// An older free cannot keep ratings: no switch to show.
		if ( ! SatisfactionSettings::available() ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( Permissions::can_access_support_settings() ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'doublescale' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
