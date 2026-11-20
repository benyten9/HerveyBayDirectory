<?php
/**
 * The account's Meta WhatsApp limits, for the settings card and campaigns.
 *
 * Same routes free used to serve (`campaigns/whatsapp-limits`,
 * `campaigns/whatsapp-quota`), so the admin screens call them unchanged.
 *
 * @package DoubleScale\Pro\Modules\Campaigns\Whatsapp
 */

namespace DoubleScale\Pro\Modules\Campaigns\Whatsapp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestWhatsappLimitsController class
 */
class RestWhatsappLimitsController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'campaigns';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Free still serving these (main before the move) keeps them.
		if ( class_exists( MetaTierGuard::FREE_COPY ) ) {
			return;
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/whatsapp-limits',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_limits' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'refresh' => array(
							'description' => __( 'Ask Meta now instead of using the cached answer.', 'doublescale' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/whatsapp-quota',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'get_quota' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'filters' => array(
							'description' => __( 'Recipient filter groups', 'doublescale' ),
							'type'        => 'array',
						),
					),
				),
			)
		);
	}

	/**
	 * Same access as the campaigns list: module on, CRM manager.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error|bool
	 */
	public function permissions_check( $request ) {
		$module = $this->require_module( 'campaigns' );
		if ( $module instanceof WP_Error ) {
			return $module;
		}

		return \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::can_use_campaigns();
	}

	/**
	 * The account's Meta WhatsApp limits and today's usage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_limits( $request ) {
		return rest_ensure_response( MetaTierGuard::status( (bool) $request->get_param( 'refresh' ) ) );
	}

	/**
	 * How much of a campaign's audience fits in today's Meta quota.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quota( $request ) {
		$filters = $request->get_param( 'filters' );

		try {
			return rest_ensure_response( MetaTierGuard::campaign_quota( is_array( $filters ) ? $filters : array(), \DoubleScale\Pro\Core\UserRoles\PermissionsCompat::campaign_audience_owner() ) );
		} catch ( \Exception $e ) {
			return new WP_Error( 'whatsapp_quota_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
	}
}
