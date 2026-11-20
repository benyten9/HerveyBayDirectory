<?php
/**
 * GET support/tickets/{id}/visitor — "About the visitor" beside a ticket
 * (VisitorPanel): recent pages, cart, last orders.
 *
 * Who may open the ticket may read it: support access, and the ticket is
 * theirs unless they may handle every ticket (the same rule as free's ticket
 * routes).
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Pro\Modules\Support\Services\VisitorPanel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestVisitorPanelController class.
 */
class RestVisitorPanelController extends RestController {

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/support/tickets/(?P<id>\d+)/visitor',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_panel' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_panel( $request ) {
		// Helpdesk off: its tables may not exist, so there is no ticket to show.
		if ( function_exists( 'doublescale_module_data_ready' ) && ! doublescale_module_data_ready( 'support' ) ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$ticket = TicketModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $ticket ) {
			return new WP_Error( 'not_found', __( 'Ticket not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( ! Permissions::can_manage_all_tickets() && (int) $ticket->agent_user_id !== (int) get_current_user_id() ) {
			return new WP_Error( 'not_allowed', __( 'You can only access tickets assigned to you.', 'doublescale' ), array( 'status' => 403 ) );
		}

		return new WP_REST_Response( VisitorPanel::for_ticket( $ticket ), 200 );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'doublescale' ), array( 'status' => 401 ) );
		}
		if ( Permissions::has_support_access() ) {
			return true;
		}

		return new WP_Error( 'not_allowed', __( 'You do not have permission to access support tickets.', 'doublescale' ), array( 'status' => 403 ) );
	}
}
