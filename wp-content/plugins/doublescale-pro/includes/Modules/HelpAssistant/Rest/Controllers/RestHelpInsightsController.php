<?php
/**
 * Helpdesk → Help Assistant: the customer conversations log and the questions
 * the articles held no answer to.
 *
 *   GET /doublescale/v1/help/conversations       channel, outcome, search, page, per_page
 *   GET /doublescale/v1/help/conversations/{id}  one conversation, every message
 *   GET /doublescale/v1/help/unanswered          days (default 30)
 *
 * The unanswered questions: everyone who reads the articles (Help Center
 * Agents included: they write the next article from them). The
 * conversations hold what customers wrote, so they stay with Helpdesk staff
 * (who read the tickets these conversations become) and administrators
 * ({@see self::can_view_conversations()}); Help Center Managers and Agents
 * do not see them.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HelpSettings;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Insights;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpInsightsController class.
 */
class RestHelpInsightsController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'help';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversations' ),
					'permission_callback' => array( $this, 'conversations_permissions_check' ),
					'args'                => array(
						'channel'  => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), HelpSettings::CHANNELS ),
						),
						'outcome'  => array(
							'type' => 'string',
							'enum' => array_merge( array( '' ), Insights::OUTCOMES ),
						),
						'search'   => array( 'type' => 'string' ),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/conversations/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'conversations_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unanswered',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_unanswered' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'days' => array(
							'type'    => 'integer',
							'default' => 30,
							'minimum' => 1,
							'maximum' => 365,
						),
					),
				),
			)
		);
	}

	/**
	 * Everyone who reads the articles. Wrapped: WordPress passes the request
	 * first, which KbAccess would read as a user id.
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return KbAccess::can_read();
	}

	/**
	 * The conversations log. Wrapped for the same reason.
	 *
	 * @return bool
	 */
	public function conversations_permissions_check(): bool {
		return self::can_view_conversations();
	}

	/**
	 * Who may read the customers' conversations: Helpdesk staff and
	 * administrators. The Help Center roles (Manager and Agent) write and
	 * manage articles without seeing customer data.
	 *
	 * @param int|null $user_id User ID (null for current user).
	 * @return bool
	 */
	public static function can_view_conversations( $user_id = null ): bool {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		return $user_id > 0 && ( Permissions::has_support_access( $user_id ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversations( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}
		Schema::ensure();

		$per_page = (int) $request->get_param( 'per_page' );
		$page     = (int) $request->get_param( 'page' );
		$result   = Insights::conversations(
			array(
				'channel'  => (string) $request->get_param( 'channel' ),
				'outcome'  => (string) $request->get_param( 'outcome' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		return new WP_REST_Response(
			array(
				'data'         => $result['data'],
				// How visitors rated the team's help, the last 30 days.
				'satisfaction' => Insights::satisfaction( 30 ),
				'meta'         => array(
					'total'        => $result['total'],
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) ),
				),
			),
			200
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}
		Schema::ensure();

		$one = Insights::conversation( (int) $request->get_param( 'id' ) );
		if ( null === $one ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $one, 200 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_unanswered( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}
		Schema::ensure();

		return new WP_REST_Response( array( 'data' => Insights::unanswered( (int) $request->get_param( 'days' ) ) ), 200 );
	}
}
