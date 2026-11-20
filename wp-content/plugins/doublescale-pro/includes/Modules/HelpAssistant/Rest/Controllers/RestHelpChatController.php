<?php
/**
 * Help assistant chat route.
 *
 *   POST /doublescale/v1/help/chat
 *     message       Question (required).
 *     conversation  Hash of an earlier conversation to continue (optional).
 *     audience      public | portal | internal — whose articles to answer from.
 *     stream        false returns one JSON answer instead of a stream.
 *
 * Helpdesk staff only, for the admin "Try the assistant" screen, where staff
 * may ask as a visitor, a portal client or the team. Customers ask through
 * {@see RestHelpPublicChatController}, whose audience comes from KbAudience,
 * never from the request.
 *
 * Streamed answers are Server-Sent Events, the same names the AI assistant
 * panel reads: conversation, ping, text_delta, citations, handoff_offer,
 * done, error.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatEngine;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HandoffService;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use DoubleScale\Pro\Modules\HelpAssistant\Services\StreamResponder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpChatController class.
 */
class RestHelpChatController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'help/chat';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'chat' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'message'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'conversation' => array( 'type' => 'string' ),
						'audience'     => array(
							'type'    => 'string',
							'enum'    => KbArticleModel::visibilities(),
							'default' => KbArticleModel::VISIBILITY_PUBLIC,
						),
						'stream'       => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Helpdesk staff only. Wrapped: WordPress passes the request as the first
	 * argument, which KbAccess would read as a user id.
	 *
	 * @return bool
	 */
	public function permissions_check(): bool {
		return KbAccess::can_read();
	}

	/**
	 * Answer one question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|void Streams and exits when streaming.
	 */
	public function chat( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$ai = Settings::get( 'ai', array() );
		if ( empty( $ai['provider'] ) && null === apply_filters( 'doublescale_help_chat_provider', null ) ) {
			return new WP_Error( 'ai_not_configured', __( 'Connect an AI provider in Settings → AI first.', 'doublescale' ), array( 'status' => 400 ) );
		}

		if ( '' === trim( wp_strip_all_tags( (string) $request->get_param( 'message' ) ) ) ) {
			// Refused before any conversation or message row is written.
			return new WP_Error( 'help_empty_question', __( 'Please type a question.', 'doublescale' ), array( 'status' => 400 ) );
		}

		Schema::ensure();

		$visibilities = self::visibilities_for( (string) $request->get_param( 'audience' ) );
		$conversation = $this->conversation( $request );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$stream = rest_sanitize_boolean( $request->get_param( 'stream' ) );
		$engine = new ChatEngine();
		$start  = microtime( true );
		$id     = (int) $conversation['id'];
		$text   = (string) $request->get_param( 'message' );

		$history = ConversationStore::history( $id, ChatEngine::HISTORY );
		ConversationStore::add_message( $id, 'user', mb_substr( wp_strip_all_tags( $text ), 0, ChatEngine::MAX_QUESTION_CHARS, 'UTF-8' ) );

		if ( ! $stream ) {
			$result = $engine->respond( $history, $text, $visibilities, $this->options() );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$this->record_answer( $id, $result, $start );

			return new WP_REST_Response(
				array(
					'conversation' => (string) $conversation['hash'],
					'answer'       => $result['answer'],
					'answered'     => $result['answered'],
					'citations'    => $result['citations'],
					'suggestions'  => $result['suggestions'] ?? array(),
					'usage'        => $result['usage'],
				),
				200
			);
		}

		StreamResponder::start();
		StreamResponder::emit( 'conversation', array( 'conversation' => (string) $conversation['hash'] ) );
		StreamResponder::emit( 'ping', array() );

		$streamed = '';
		$result   = $engine->respond(
			$history,
			$text,
			$visibilities,
			$this->options(),
			function ( $delta ) use ( &$streamed ) {
				$streamed .= $delta;
				StreamResponder::emit( 'text_delta', array( 'text' => $delta ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			// The provider's own words (redacted) only for whoever may change
			// the AI settings; the rest of the team sees a generic message.
			StreamResponder::emit(
				'error',
				array(
					'message' => \DoubleScale\Pro\Modules\HelpAssistant\Module::can_change_ai()
						? \DoubleScale\Pro\Modules\Ai\AiHealth::redact( $result->get_error_message() )
						: __( 'The assistant could not answer right now. Ask a site administrator to check Settings → AI.', 'doublescale' ),
				)
			);
			if ( '' !== $streamed ) {
				ConversationStore::add_message( $id, 'assistant', $streamed, array( 'answered' => false ) );
			}
			exit;
		}

		// A provider that could not stream (no cURL) answered in one piece
		// without calling back: send that answer now.
		if ( '' === $streamed && '' !== (string) $result['answer'] ) {
			StreamResponder::emit( 'text_delta', array( 'text' => (string) $result['answer'] ) );
		}

		$this->record_answer( $id, $result, $start );
		// With the final text: its [n] marks were matched to the sources
		// after the model streamed its own numbering.
		StreamResponder::emit(
			'citations',
			array(
				'items' => $result['citations'],
				'text'  => (string) $result['answer'],
			)
		);
		if ( ! empty( $result['suggestions'] ) ) {
			StreamResponder::emit( 'suggestions', array( 'items' => $result['suggestions'] ) );
		}
		if ( ! $result['answered'] || ! empty( $result['handoff'] ) ) {
			StreamResponder::emit( 'handoff_offer', array() );
		}
		StreamResponder::emit(
			'done',
			array(
				'answered' => $result['answered'],
				'usage'    => $result['usage'],
			)
		);
		exit;
	}

	/**
	 * Visibilities for an audience: each tier also reads the tiers below it.
	 *
	 * @param string $audience public, portal or internal.
	 * @return string[]
	 */
	public static function visibilities_for( string $audience ): array {
		switch ( $audience ) {
			case KbArticleModel::VISIBILITY_INTERNAL:
				return KbArticleModel::visibilities();
			case KbArticleModel::VISIBILITY_PORTAL:
				return array( KbArticleModel::VISIBILITY_PUBLIC, KbArticleModel::VISIBILITY_PORTAL );
			default:
				return array( KbArticleModel::VISIBILITY_PUBLIC );
		}
	}

	/**
	 * The conversation to continue, or a new one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function conversation( WP_REST_Request $request ) {
		$hash     = sanitize_key( (string) $request->get_param( 'conversation' ) );
		$audience = (string) $request->get_param( 'audience' );

		if ( '' !== $hash ) {
			$row = ConversationStore::find( $hash );
			// A staff preview continues only its own conversation.
			if ( ! $row || (int) $row['user_id'] !== get_current_user_id() ) {
				return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
			return $row;
		}

		return ConversationStore::create( 'admin', $audience, get_current_user_id() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function options(): array {
		$settings = (array) get_option( 'doublescale_help_assistant_settings', array() );

		return array(
			'name'     => (string) ( $settings['name'] ?? '' ),
			'fallback' => (string) ( $settings['fallback'] ?? '' ),
			'handoff'  => HandoffService::available(),
		);
	}

	/**
	 * @param int                  $conversation_id Conversation.
	 * @param array<string, mixed> $result          Engine result.
	 * @param float                $start           microtime() at start.
	 * @return void
	 */
	private function record_answer( int $conversation_id, array $result, float $start ): void {
		ConversationStore::add_message(
			$conversation_id,
			'assistant',
			(string) $result['answer'],
			array(
				'citations'  => $result['citations'],
				'answered'   => $result['answered'],
				'tokens_in'  => (int) $result['usage']['input'],
				'tokens_out' => (int) $result['usage']['output'],
				'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
			)
		);
	}
}
