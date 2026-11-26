<?php
/**
 * REST routes for the Latest Posts email block (builder only).
 *
 * GET  /email-blocks/posts/options             post types and their taxonomies
 * GET  /email-blocks/posts/terms?taxonomy=x    terms of one taxonomy
 * POST /email-blocks/posts/preview             the posts the block would show
 *
 * @package DoubleScale\Pro\Modules\Emails\Rest
 */

namespace DoubleScale\Pro\Modules\Emails\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\Emails\Services\LatestPosts;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Latest Posts block endpoints.
 */
class RestLatestPostsController {

	private const NS = 'doublescale/v1';

	public static function register_routes(): void {
		$can = array( self::class, 'can_use' );

		register_rest_route(
			self::NS,
			'/email-blocks/posts/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return new WP_REST_Response( array( 'postTypes' => LatestPosts::post_types() ), 200 );
				},
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			self::NS,
			'/email-blocks/posts/terms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					return new WP_REST_Response( array( 'terms' => LatestPosts::terms( (string) $request->get_param( 'taxonomy' ) ) ), 200 );
				},
				'permission_callback' => $can,
				'args'                => array(
					'taxonomy' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/email-blocks/posts/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => static function ( WP_REST_Request $request ) {
					$props = $request->get_param( 'props' );
					return new WP_REST_Response( array( 'items' => LatestPosts::items( is_array( $props ) ? $props : array(), true ) ), 200 );
				},
				'permission_callback' => $can,
				'args'                => array(
					'props' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	public static function can_use(): bool {
		return Permissions::can_use_campaigns();
	}
}
