<?php
/**
 * Read-only status of the workflow send queue.
 *
 * @package DoubleScale\Pro\Modules\SendQueue\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\SendQueue\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\SendQueue\SendQueue;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSendQueueController.
 */
class RestSendQueueController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'send-queue';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'get_status_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Per channel: messages due now, waiting (and until when), failed, and
	 * today's sends against the shared daily limit.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return new WP_REST_Response( SendQueue::status(), 200 );
	}

	/**
	 * @return bool
	 */
	public function get_status_permissions_check() {
		return Permissions::is_crm_manager();
	}
}
