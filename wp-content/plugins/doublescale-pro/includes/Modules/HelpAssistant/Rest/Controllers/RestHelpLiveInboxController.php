<?php
/**
 * GET /doublescale/v1/help/live-chats — live chats with something new from
 * the customer, for the chat icon in the admin header (and the mobile app).
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChat;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveInbox;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpLiveInboxController class.
 */
class RestHelpLiveInboxController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'help/live-chats';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/help/team-typing',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'team_typing' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'ticket_id' => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'limit' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 50,
						),
						// Every open live chat (the ticket screen's list), not only those with news.
						'all'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * The team member is typing a reply on a live chat's ticket (real-time
	 * relay on): the customer's chat shows "Typing…". A mark only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function team_typing( $request ) {
		$ticket_id = (int) $request->get_param( 'ticket_id' );
		$ticket    = class_exists( '\\DoubleScale\\Modules\\Support\\Models\\TicketModel' ) ? \DoubleScale\Modules\Support\Models\TicketModel::find( $ticket_id ) : null;
		// Only on a ticket this person may open (every ticket, or theirs).
		$may = $ticket && ( Permissions::can_manage_all_tickets() || (int) $ticket->agent_user_id === get_current_user_id() );
		if ( $may && LiveInbox::enabled() ) {
			$conversation = ConversationStore::find_by_ticket( $ticket_id );
			if ( null !== $conversation && 'live' === LiveChat::status( $conversation ) ) {
				ConversationStore::mark_typing( (int) $conversation['id'], 'team', get_current_user_id() );
			}
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Helpdesk staff only. Wrapped: WordPress passes the request first.
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return is_user_logged_in() && class_exists( Permissions::class ) && Permissions::has_support_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		if ( ! LiveInbox::enabled() ) {
			return new WP_REST_Response(
				array(
					'enabled' => false,
					'unread'  => 0,
					'chats'   => array(),
				),
				200
			);
		}
		Schema::ensure();

		return new WP_REST_Response(
			array( 'enabled' => true ) + LiveInbox::for_user( get_current_user_id(), (int) $request->get_param( 'limit' ), (bool) $request->get_param( 'all' ) ),
			200
		);
	}
}
