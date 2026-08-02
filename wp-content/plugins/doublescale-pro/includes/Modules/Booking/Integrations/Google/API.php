<?php
/**
 * Google Calendar API client (Calendar API v3 + OAuth userinfo).
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google;

use DoubleScale\Modules\Booking\Integration\API as Abstract_API;

defined( 'ABSPATH' ) || exit;

/**
 * Google Integration API class
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
	 * @param App|null    $app           App (required for refresh and integration context).
	 * @param string|int|null $account_id Account id for token persistence.
	 */
	public function __construct( $access_token, $refresh_token = null, $app = null, $account_id = null ) {
		if ( ! $app instanceof App ) {
			throw new \InvalidArgumentException( 'Google API requires a valid App instance.' );
		}
		parent::__construct( $app->get_integration() );
		$this->endpoint      = 'https://www.googleapis.com';
		$this->app           = $app;
		$this->access_token  = $access_token;
		$this->refresh_token = (string) $refresh_token;
		$this->account_id    = $account_id;
	}

	/**
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_account_info() {
		return $this->get( '/oauth2/v1/userinfo' );
	}

	/**
	 * @param array<string, mixed> $args Query args (e.g. maxResults, pageToken).
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_calendars( $args = array() ) {
		return $this->get( '/calendar/v3/users/me/calendarList', $args );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $args        Query args.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_events( $calendar_id, $args = array() ) {
		return $this->get( "/calendar/v3/calendars/{$calendar_id}/events", $args );
	}

	/**
	 * @param list<string>         $calendars Calendar IDs.
	 * @param array<string, mixed> $args      freeBusy request body (timeMin, timeMax, …); items are appended.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function get_free_busy( $calendars, $args = array() ) {
		foreach ( $calendars as $calendar ) {
			$args['items'][] = array( 'id' => $calendar );
		}

		return $this->post( '/calendar/v3/freeBusy', $args );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param array<string, mixed> $args         Event resource.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function add_event( $calendar_id, $args = array() ) {
		$url = "/calendar/v3/calendars/{$calendar_id}/events";

		if ( ! empty( $args['conferenceData'] ) ) {
			$url .= '?conferenceDataVersion=1';
		}

		return $this->post( $url, $args );
	}

	/**
	 * @param string               $calendar_id Calendar ID.
	 * @param string               $event_id    Event ID.
	 * @param array<string, mixed> $args        Patch body.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function update_event( $calendar_id, $event_id, $args = array() ) {
		$url = "/calendar/v3/calendars/{$calendar_id}/events/{$event_id}";

		if ( ! empty( $args['conferenceData'] ) ) {
			$url .= '?conferenceDataVersion=1';
		}

		return $this->patch( $url, $args );
	}

	/**
	 * @param string $calendar_id Calendar ID.
	 * @param string $event_id    Event ID.
	 * @return array{success: bool, code: mixed, data: mixed}
	 */
	public function delete_event( $calendar_id, $event_id ) {
		return $this->delete( "/calendar/v3/calendars/{$calendar_id}/events/{$event_id}" );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string      $method Method.
	 * @param string      $path   Path.
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
}
