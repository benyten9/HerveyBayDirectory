<?php
/**
 * POST /doublescale/v1/smtp/alerts/test — send a test Slack / webhook / Discord alert.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\Smtp\Alerts\SmtpAlertDispatcher;
use DoubleScale\Modules\Smtp\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for testing SMTP delivery alerts.
 */
class RestSmtpAlertsTestController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'smtp/alerts/test';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_test' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'slug' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'slack', 'webhook', 'discord' ),
					),
					'data' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_test( $request ) {
		$slug = (string) $request->get_param( 'slug' );
		$data = (string) $request->get_param( 'data' );
		$out  = SmtpAlertDispatcher::test( $slug, $data );
		if ( is_wp_error( $out ) ) {
			return $out;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Alert sent successfully.', 'doublescale' ),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return Settings::user_can_manage_smtp_rest();
	}
}
