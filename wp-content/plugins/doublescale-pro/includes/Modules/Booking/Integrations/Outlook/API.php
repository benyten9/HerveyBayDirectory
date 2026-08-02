<?php
/**
 * Microsoft Graph client for Outlook calendar / Teams.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Outlook;

use DoubleScale\Modules\Booking\Integration\API as Abstract_API;

defined( 'ABSPATH' ) || exit;

/**
 * Outlook Integration API class
 */
class API extends Abstract_API {

	/**
	 * @var App
	 */
	private $app;

	/**
	 * @var string
	 */
	private $access_token;

	/**
	 * @var string
	 */
	private $refresh_token;

	/**
	 * @var string|int|null
	 */
	private $account_id;

	/**
	 * @param string      $access_token  Access token.
	 * @param string|null $refresh_token Refresh token.
	 * @param App|null    $app           App instance.
	 * @param string|int|null $account_id Account id for token persistence.
	 */
	public function __construct( $access_token, $refresh_token = null, $app = null, $account_id = null ) {
		if ( ! $app instanceof App ) {
			throw new \InvalidArgumentException( 'Outlook API requires a valid App instance.' );
		}
		parent::__construct( $app->get_integration() );
		$this->endpoint      = 'https://graph.microsoft.com/v1.0';
		$this->app           = $app;
		$this->access_token  = $access_token;
		$this->refresh_token = (string) $refresh_token;
		$this->account_id    = $account_id;
	}

	/**
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_account_info() {
		return $this->get( 'me' );
	}

	/**
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_calendars() {
		return $this->get( 'me/calendars' );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $args        Query string for calendarView.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_events( $calendar_id, $args = array() ) {
		return $this->get( "me/calendars/{$calendar_id}/calendarview", $args );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $body        Event resource.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function create_event( $calendar_id, $body ) {
		return $this->post( "me/calendars/{$calendar_id}/events", $body );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param string               $event_id    Event ID.
	 * @param array<string, mixed> $body        Patch body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function update_event( $calendar_id, $event_id, $body ) {
		return $this->patch( "me/calendars/{$calendar_id}/events/{$event_id}", $body );
	}

	/**
	 * @param string $event_id Event ID.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function delete_event( $event_id ) {
		return $this->delete( "me/events/{$event_id}" );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string      $method Method.
	 * @param string      $path   Path (no leading slash required).
	 * @param string|null $body   JSON body.
	 * @return array|\WP_Error
	 */
	public function request_remote( $method, $path, $body = null ) {
		$path = ltrim( $path, '/' );

		return wp_remote_request(
			"{$this->endpoint}/{$path}",
			array(
				'method'  => $method,
				'body'    => $body,
				'headers' => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json; charset=' . get_option( 'blog_charset' ),
					'Cache-Control' => 'no-cache',
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);
	}

	/**
	 * @param string      $method              HTTP method.
	 * @param string      $path                Path.
	 * @param string|null $body                JSON body.
	 * @param bool        $maybe_refresh_token Retry once after refresh on 401.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function request( $method, $path, $body = null, $maybe_refresh_token = true ) {
		$response = $this->request_remote( $method, $path, $body );
		if ( is_wp_error( $response ) ) {
			return $this->prepare_response(
				false,
				null,
				array(
					'wp_error' => array(
						'code'    => $response->get_error_code(),
						'message' => $response->get_error_message(),
					),
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( $response['body'], true );

		if ( 401 === $response_code ) {
			if ( $maybe_refresh_token ) {
				$refreshed = $this->refresh_tokens();
				if ( $refreshed ) {
					return $this->request( $method, $path, $body, false );
				}
			}

			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		} elseif ( $response_code >= 300 ) {
			return $this->prepare_response(
				false,
				$response_code,
				$response_body
			);
		}

		if ( is_array( $response_body ) ) {
			unset( $response_body['_links'] );
		}
		return $this->prepare_response(
			true,
			$response_code,
			$response_body
		);
	}

	/**
	 * @return bool
	 */
	private function refresh_tokens() {
		$tokens = $this->app->refresh_tokens( $this->refresh_token, $this->account_id );
		if ( ! is_array( $tokens ) ) {
			return false;
		}

		$this->access_token  = $tokens['access_token'];
		$this->refresh_token = isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : $this->refresh_token;
		return true;
	}

	/**
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function check_teams_capabilities() {
		$response = $this->get( 'me/licenseDetails' );

		if ( ! $response['success'] ) {
			return $this->prepare_response(
				false,
				$response['code'],
				array(
					'message' => __( 'Failed to fetch license details', 'doublescale' ),
				)
			);
		}

		$license_data = $response['data'];

		if ( empty( $license_data['value'] ) ) {
			return $this->prepare_response(
				false,
				400,
				array(
					'message' => __( 'No valid Microsoft 365 license found. Teams meeting creation requires a valid Microsoft 365 license.', 'doublescale' ),
				)
			);
		}

		$has_teams_capability = false;
		$teams_service_plans    = array(
			'57ff2da0-773e-42df-b2af-ffb7a2317929',
			'6fd2c87f-b296-42f0-b197-1e91e994b900',
			'4a51bca5-1eff-43f5-878c-177680e19134',
			'0c266dff-15dd-4b49-8397-2bb16070ed52',
			'3b555118-da6a-4418-894f-7df1e2096870',
			'4de31727-a228-4ec3-a5bf-8e45b5ca48cc',
			'6a3f8d8b-2b1a-43b2-b555-2a1d1fdabcb9',
		);

		foreach ( $license_data['value'] as $license ) {
			if ( isset( $license['servicePlans'] ) ) {
				foreach ( $license['servicePlans'] as $plan ) {
					if (
						in_array( $plan['servicePlanId'], $teams_service_plans, true ) &&
						isset( $plan['provisioningStatus'] ) &&
						'Success' === $plan['provisioningStatus']
					) {
						$has_teams_capability = true;
						break 2;
					}
				}
			}
		}

		if ( ! $has_teams_capability ) {
			return $this->prepare_response(
				false,
				400,
				array(
					'message' => __( 'Your Microsoft 365 license does not include Teams meeting creation capabilities. Please upgrade your license to use Teams features.', 'doublescale' ),
				)
			);
		}

		return $this->prepare_response(
			true,
			200,
			array(
				'has_teams_capability' => true,
				'message'              => __( 'Teams meeting creation is available with your current license.', 'doublescale' ),
			)
		);
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $body        Event resource.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function create_teams_meeting( $calendar_id, $body ) {
		$teams_check = $this->check_teams_capabilities();
		if ( ! $teams_check['success'] ) {
			return $teams_check;
		}

		$body['onlineMeeting'] = array(
			'provider' => 'teamsForBusiness',
		);

		return $this->create_event( $calendar_id, $body );
	}
}
