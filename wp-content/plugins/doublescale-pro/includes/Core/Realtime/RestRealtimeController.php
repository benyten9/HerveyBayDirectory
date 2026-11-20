<?php
/**
 * GET /doublescale/v1/realtime/config — a fresh connection for the signed-in
 * staff member: their own channels plus any extra `channels[]` they may read.
 * The admin app calls it when its tokens are about to lapse or the relay
 * refused the connection.
 *
 * @since 1.5.11
 * @package DoubleScale\Pro\Core\Realtime
 */

namespace DoubleScale\Pro\Core\Realtime;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestRealtimeController class.
 */
final class RestRealtimeController {

	/**
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			'doublescale/v1',
			'/realtime/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'config' ),
				'permission_callback' => static function () {
					return current_user_can( 'doublescale_access' );
				},
				'args'                => array(
					'channels' => array(
						'description' => __( 'Extra channels to subscribe to.', 'doublescale' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'default'     => array(),
					),
					'refresh'  => array(
						'description' => __( 'Ask the relay again now (after it refused the connection).', 'doublescale' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function config( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		if ( ! Realtime::available() || $request->get_param( 'refresh' ) ) {
			// Registers or renews when needed; waits RETRY_AFTER after a failure unless asked.
			Realtime::refresh( (bool) $request->get_param( 'refresh' ) && ! Realtime::available() );
		}

		$channels = Realtime::staff_channels( $user_id );
		foreach ( (array) $request->get_param( 'channels' ) as $channel ) {
			$channel = (string) $channel;
			if ( Realtime::can_subscribe( $channel, $user_id ) ) {
				$channels[] = $channel;
			}
		}

		return new WP_REST_Response(
			array(
				'status' => Realtime::status(),
				'client' => Realtime::client_config( $channels ),
			)
		);
	}
}
