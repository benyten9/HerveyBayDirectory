<?php
/**
 * "Was this helpful?" on a help assistant answer.
 *
 *   POST /doublescale/v1/help/public/feedback
 *     conversation  Hash of the conversation the answer is in.
 *     message       The answer's id (sent with the answer).
 *     helpful       true / false — a second vote changes the first.
 *
 *   POST /doublescale/v1/help/public/satisfaction
 *     conversation  Hash of a conversation the team marked solved or closed.
 *     score         1 to 5 (the chat's five faces).
 *     comment       Optional words; a second rating replaces the first.
 *
 * Only whoever started the conversation may rate its answers (the same
 * check as continuing it), never in a staff preview; the conversation keeps
 * the latest vote as its rating for the log.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatGuard;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChat;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpFeedbackController class.
 */
class RestHelpFeedbackController extends RestController {

	/**
	 * Votes per visitor an hour.
	 */
	private const HOURLY = 60;

	/**
	 * @var string
	 */
	protected $rest_base = 'help/public/feedback';

	/**
	 * @return void
	 */
	public function register_routes() {
		// How the team's help was, once the ticket is solved.
		register_rest_route(
			$this->namespace,
			'/help/public/satisfaction',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'satisfaction' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation' => array(
							'type'     => 'string',
							'required' => true,
						),
						'score'        => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
							'maximum'  => 5,
						),
						'comment'      => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 1000,
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'feedback' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation' => array(
							'type'     => 'string',
							'required' => true,
						),
						'message'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'helpful'      => array(
							'type'     => 'boolean',
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
	public function feedback( $request ) {
		nocache_headers();
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( ! ChatGuard::available() ) {
			return self::not_found();
		}
		Schema::ensure();

		$hits = RateLimiter::hit( 'help_fb|' . ClientIp::bucket(), HOUR_IN_SECONDS );
		if ( $hits < 1 || $hits > self::HOURLY ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment and try again.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$conversation = ConversationStore::find( sanitize_key( (string) $request->get_param( 'conversation' ) ) );
		if ( ! $conversation || 'admin' === $conversation['channel'] || (int) $conversation['user_id'] !== get_current_user_id() ) {
			return self::not_found();
		}

		$helpful = rest_sanitize_boolean( $request->get_param( 'helpful' ) ) ? 1 : 0;
		if ( ! ConversationStore::rate( (int) $conversation['id'], (int) $request->get_param( 'message' ), $helpful ) ) {
			return self::not_found();
		}

		return new WP_REST_Response( array( 'recorded' => true ), 200 );
	}

	/**
	 * The visitor rates the team's help (LiveChat::rate decides when).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function satisfaction( $request ) {
		nocache_headers();
		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		Schema::ensure();
		$hits = RateLimiter::hit( 'help_fb|' . ClientIp::bucket(), HOUR_IN_SECONDS );
		if ( $hits < 1 || $hits > self::HOURLY ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment and try again.', 'doublescale' ), array( 'status' => 429 ) );
		}
		$conversation = ConversationStore::find( sanitize_key( (string) $request->get_param( 'conversation' ) ) );
		if ( ! $conversation || 'admin' === $conversation['channel'] || (int) $conversation['user_id'] !== get_current_user_id() || empty( $conversation['ticket_id'] ) ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$rated = LiveChat::rate( $conversation, (int) $request->get_param( 'score' ), (string) $request->get_param( 'comment' ) );
		if ( is_wp_error( $rated ) ) {
			return $rated;
		}

		return new WP_REST_Response( array( 'recorded' => true ), 200 );
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'not_found', __( 'Answer not found.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
