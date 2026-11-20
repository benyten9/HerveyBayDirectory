<?php
/**
 * The help assistant for customers.
 *
 *   POST /doublescale/v1/help/public/chat
 *     message       Question (required, 1000 characters at most).
 *     channel       portal | help_page | widget — where it is asked.
 *     conversation  Hash of an earlier conversation to continue (optional).
 *     stream        false returns one JSON answer instead of a stream.
 *     dshc_hp       Hidden field: people leave it empty, bots fill it in. Not
 *                   named like a real field ("website", "email"): browsers
 *                   and password managers autofill those for real people.
 *
 * Open to anyone, so everything a request may do is decided here:
 *
 *   - Which articles the answer may draw on comes from free's KbAudience
 *     (visitor: public; portal client or staff: public + portal). The request
 *     has no say; team-only articles never reach the model from this route.
 *   - A channel answers only while the owner has it on (404 otherwise), and
 *     the portal channel only for a portal client.
 *   - A conversation continues only for the person who started it, never a
 *     staff preview.
 *   - Limits ({@see ChatGuard}): the hidden field, per-visitor, per
 *     conversation, daily, and concurrent answers — all before the model is
 *     called; a refused request leaves no conversation behind. A first
 *     question already answered for this audience is served from
 *     {@see AnswerCache} without the daily cap or a slot.
 *   - A failure of the AI provider reaches the customer as one fixed
 *     "cannot answer now" (503, support offered), never the provider's text.
 *
 * Visitors send no REST nonce (a cached page would carry a stale one), so
 * WordPress treats them as logged out. Signed-in widget users fetch a fresh
 * nonce when they open the chat; portal requests carry the portal's.
 *
 * Streamed answers use the same events as the staff route: conversation,
 * ping, text_delta, citations, handoff_offer, done, error.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Pro\Modules\HelpAssistant\Services\AnswerCache;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatEngine;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatGuard;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatPush;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatWords;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HandoffService;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChat;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HelpSettings;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use DoubleScale\Pro\Modules\HelpAssistant\Services\StreamResponder;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpPublicChatController class.
 */
class RestHelpPublicChatController extends RestController {

	/**
	 * The chat's language for this request ('ar' or '').
	 *
	 * @var string
	 */
	private $lang = '';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'help/public/chat';

	/**
	 * The hidden field bots fill in.
	 */
	public const TRAP = 'dshc_hp';

	/**
	 * Reads one conversation may make a minute: the chat reads every 3 s at
	 * most (20), with room for a second tab.
	 */
	private const UPDATES_PER_CONVERSATION = 40;

	/**
	 * Reads one address may make a minute: many visitors share one address
	 * (an office, a phone network), so this is only the outer fence.
	 */
	private const UPDATES_PER_MINUTE = 300;

	/**
	 * Files one address may upload a minute.
	 */
	private const UPLOADS_PER_MINUTE = 20;

	/**
	 * Files one address may send the team in a day (each one is kept on disk
	 * until the daily clean-up).
	 */
	public const UPLOADS_PER_DAY = 100;

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
					'permission_callback' => '__return_true',
					'args'                => array(
						'message'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'channel'      => array(
							'type'    => 'string',
							'enum'    => HelpSettings::CHANNELS,
							'default' => 'widget',
						),
						'conversation' => array( 'type' => 'string' ),
						'stream'       => array(
							'type'    => 'boolean',
							'default' => true,
						),
						self::TRAP     => array( 'type' => 'string' ),
					),
				),
			)
		);

		// A file for the team, while they have the conversation.
		register_rest_route(
			$this->namespace,
			'/help/public/upload',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation'  => array(
							'type'     => 'string',
							'required' => true,
						),
						'pending_count' => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
					),
				),
			)
		);

		// The visitor is typing (real-time relay on): a mark only, never the
		// words; the team's ticket screen shows "The customer is typing…".
		register_rest_route(
			$this->namespace,
			'/help/public/typing',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'typing' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// What the team (or the customer by email) wrote since the chat last looked.
		register_rest_route(
			$this->namespace,
			'/help/public/updates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'updates' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation' => array(
							'type'     => 'string',
							'required' => true,
						),
						'after'        => array(
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						),
						// The chat is on the visitor's screen: what it shows is seen.
						'seen'         => array(
							'type'    => 'boolean',
							'default' => false,
						),
						// The visitor is typing (a mark only, never the words).
						'typing'       => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	/**
	 * A file the visitor sends the team: only while the team has the
	 * conversation (the assistant reads no files). Limited per address.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $request ) {
		nocache_headers();

		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if (
			RateLimiter::hit( 'help_upload|' . ClientIp::bucket(), MINUTE_IN_SECONDS ) > self::UPLOADS_PER_MINUTE
			|| RateLimiter::hit( 'help_upload_day|' . ClientIp::bucket() . '|' . gmdate( 'Ymd' ), DAY_IN_SECONDS + HOUR_IN_SECONDS ) > self::UPLOADS_PER_DAY
		) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment.', 'doublescale' ), array( 'status' => 429 ) );
		}

		Schema::ensure();
		$conversation = $this->existing_conversation( $request );
		if ( null === $conversation || is_wp_error( $conversation ) || ! LiveChat::active( $conversation ) ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'No file was uploaded.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$stored = LiveChat::upload( $conversation, $files['file'], (int) $request->get_param( 'pending_count' ) );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return new WP_REST_Response( $stored, 201 );
	}

	/**
	 * Messages the chat did not write, after the last one it has, and whether
	 * the team has the conversation. Cheap, and limited per address.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updates( $request ) {
		nocache_headers();

		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( RateLimiter::hit( 'help_updates|' . ClientIp::bucket(), MINUTE_IN_SECONDS ) > self::UPDATES_PER_MINUTE ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment.', 'doublescale' ), array( 'status' => 429 ) );
		}

		Schema::ensure();
		$conversation = $this->existing_conversation( $request );
		if ( null === $conversation ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		// Counted per real conversation only: a made-up one leaves nothing behind.
		if ( RateLimiter::hit( 'help_updates_c|' . (string) $conversation['hash'], MINUTE_IN_SECONDS ) > self::UPDATES_PER_CONVERSATION ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment.', 'doublescale' ), array( 'status' => 429 ) );
		}

		$after    = (int) $request->get_param( 'after' );
		$status   = LiveChat::status( $conversation );
		$messages = ConversationStore::arrived( (int) $conversation['id'], $after );
		// The team's replies reached the customer's chat ("Delivered").
		LiveChat::delivered( $messages, $conversation );
		if ( $request->get_param( 'seen' ) && in_array( $status, array( 'live', 'resolved' ), true ) ) {
			// What the chat has and what it shows now.
			LiveChat::visitor_saw( $conversation, max( array_merge( array( $after ), array_column( $messages, 'id' ) ) ) );
		}
		// Typing reaches the team only while they have the conversation.
		if ( $request->get_param( 'typing' ) && 'live' === $status ) {
			ConversationStore::mark_typing( (int) $conversation['id'], 'visitor' );
		}
		$fresh = ConversationStore::find_by_id( (int) $conversation['id'] );

		return new WP_REST_Response(
			array(
				'status'    => $status,
				'messages'  => $messages,
				// Who answers: the chat's header shows them while the team has it.
				'agent'     => ConversationStore::last_agent( (int) $conversation['id'] ),
				// The last message the team had on screen: "Seen" up to it.
				'team_seen' => (int) ( $fresh['team_seen_id'] ?? 0 ),
				// Solved or closed: the chat asks for a rating until there is one.
				'rated'     => null !== ( $fresh['satisfaction'] ?? null ),
				// A team member is writing a reply now (no words, only that).
				'typing'    => 'live' === $status && ConversationStore::is_typing( (int) $conversation['id'], 'team' ),
				// While the team has it: listen for their replies instead of asking (null = keep asking).
				'realtime'  => in_array( $status, array( 'live', 'resolved' ), true ) ? ChatPush::visitor_client( $conversation ) : null,
			),
			200
		);
	}

	/**
	 * The visitor is typing in a live chat. Counted with the updates reads.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function typing( $request ) {
		nocache_headers();

		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		if ( RateLimiter::hit( 'help_updates|' . ClientIp::bucket(), MINUTE_IN_SECONDS ) > self::UPDATES_PER_MINUTE ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment.', 'doublescale' ), array( 'status' => 429 ) );
		}
		$conversation = $this->existing_conversation( $request );
		if ( null === $conversation ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}
		if ( RateLimiter::hit( 'help_updates_c|' . (string) $conversation['hash'], MINUTE_IN_SECONDS ) > self::UPDATES_PER_CONVERSATION ) {
			return new WP_Error( 'help_rate_limited', __( 'You are asking a little too fast. Please wait a moment.', 'doublescale' ), array( 'status' => 429 ) );
		}
		if ( 'live' === LiveChat::status( $conversation ) ) {
			ConversationStore::mark_typing( (int) $conversation['id'], 'visitor' );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Answer one customer question.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error|void Streams and exits when streaming.
	 */
	public function chat( $request ) {
		nocache_headers();

		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}

		$channel = (string) $request->get_param( 'channel' );
		// The chat shows here (the AI assistant or the team answers in it).
		if ( ! HelpSettings::channel_on( $channel ) || ! ChatGuard::available() ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'doublescale' ), array( 'status' => 404 ) );
		}

		$visibilities = KbAudience::current();
		if ( 'portal' === $channel && ! in_array( KbArticleModel::VISIBILITY_PORTAL, $visibilities, true ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to the client portal to ask here.', 'doublescale' ), array( 'status' => 403 ) );
		}

		if ( '' !== trim( (string) $request->get_param( self::TRAP ) ) ) {
			return new WP_Error( 'help_rejected', __( 'Your message could not be sent.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$text = trim( wp_strip_all_tags( (string) $request->get_param( 'message' ) ) );
		// The chat's language, for replies to a message without words.
		$this->lang = 'ar' === $request->get_param( 'lang' ) ? 'ar' : '';
		// Files uploaded for this message (a live conversation only).
		$files = array_slice( array_values( array_filter( array_map( 'sanitize_key', (array) $request->get_param( 'attachments' ) ) ) ), 0, 10 );
		if ( '' === $text && array() === $files ) {
			return new WP_Error( 'help_empty_question', __( 'Please type a question.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $text, 'UTF-8' ) > ChatEngine::MAX_QUESTION_CHARS ) {
			return new WP_Error(
				'help_question_too_long',
				/* translators: %d: most characters a question may have */
				sprintf( __( 'Please keep your question under %d characters.', 'doublescale' ), ChatEngine::MAX_QUESTION_CHARS ),
				array( 'status' => 400 )
			);
		}

		$guard   = ChatGuard::from_settings();
		$limited = $guard->visitor();
		if ( $limited ) {
			return $limited;
		}

		Schema::ensure();

		$conversation = $this->existing_conversation( $request );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		// Handed to the team and still open: the message is the customer's
		// reply on the ticket, and the assistant stays silent.
		if ( null !== $conversation && LiveChat::active( $conversation ) ) {
			return $this->live( $conversation, $text, rest_sanitize_boolean( $request->get_param( 'stream' ) ), $files );
		}
		// No AI answering (switched off, or no AI provider): the chat goes
		// straight to the team. The chat opens a ticket through the handoff
		// route instead, which asks a visitor for their name and email.
		if ( ! HelpSettings::assistant_answers() ) {
			return new WP_Error( 'help_team_only', __( 'Send your message to our team.', 'doublescale' ), array( 'status' => 409 ) );
		}
		// Files go to the team only; the assistant needs words.
		if ( '' === $text ) {
			return new WP_Error( 'help_empty_question', __( 'Please type a question.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$id      = null !== $conversation ? (int) $conversation['id'] : 0;
		$history = $id > 0 ? ConversationStore::history( $id, ChatEngine::HISTORY ) : array();
		if ( $id > 0 ) {
			$full = $guard->conversation( ConversationStore::count_questions( $id ) );
			if ( $full ) {
				return $full;
			}
		}

		// Taken now, before the model is asked: see AnswerCache.
		$cache_key = array() === $history ? AnswerCache::key( $text, $visibilities ) : null;
		$kept      = AnswerCache::get( $cache_key );
		$token     = '';
		if ( null === $kept ) {
			// The slot first: a request refused as busy spends nothing of the day.
			$token = $guard->acquire();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$capped = $guard->daily();
			if ( $capped ) {
				$guard->release( $token );
				return $capped;
			}
		}

		// Every check passed: only now does a new conversation get a row.
		if ( null === $conversation ) {
			$conversation = self::create_conversation( $channel, $visibilities );
			$id           = (int) $conversation['id'];
		}

		$start  = microtime( true );
		$stream = rest_sanitize_boolean( $request->get_param( 'stream' ) );
		$state  = (object) array(
			'streamed' => '',
			'done'     => false,
		);

		// Whatever happens next (an answer, an error, a reader who leaves and
		// stops the script mid-stream, a fatal error or time-out), the slot is
		// freed and the words already written are stored.
		register_shutdown_function(
			static function () use ( $guard, $token, $state, $id ) {
				if ( $state->done ) {
					return;
				}
				if ( '' !== $token ) {
					$guard->release( $token );
				}
				if ( '' !== $state->streamed ) {
					ConversationStore::add_message( $id, 'assistant', $state->streamed, array( 'answered' => false ) );
				}
			}
		);

		ConversationStore::add_message( $id, 'user', $text );

		if ( ! $stream ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			try {
				$result = null !== $kept ? $kept + array( 'usage' => self::no_usage() ) : $this->answer( $history, $text, $visibilities );
			} finally {
				$this->finish( $token, $guard, $state );
			}
			if ( is_wp_error( $result ) ) {
				return self::failed( $id, $result );
			}
			$message = $this->record( $id, $result, $start, null === $kept ? $cache_key : null );

			return new WP_REST_Response(
				array(
					'conversation' => (string) $conversation['hash'],
					'message_id'   => $message,
					'answer'       => $result['answer'],
					'answered'     => $result['answered'],
					'handoff'      => ! empty( $result['handoff'] ),
					'citations'    => $result['citations'],
					'suggestions'  => $result['suggestions'] ?? array(),
				),
				200
			);
		}

		StreamResponder::start();
		StreamResponder::emit( 'conversation', array( 'conversation' => (string) $conversation['hash'] ) );
		StreamResponder::emit( 'ping', array() );

		if ( null !== $kept ) {
			$result = $kept + array( 'usage' => self::no_usage() );
			StreamResponder::emit( 'text_delta', array( 'text' => $result['answer'] ) );
		} else {
			$result = $this->answer(
				$history,
				$text,
				$visibilities,
				static function ( $delta ) use ( $state ) {
					$state->streamed .= $delta;
					StreamResponder::emit( 'text_delta', array( 'text' => $delta ) );
				}
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->finish( $token, $guard, $state );
			$error = self::failed( $id, $result, $state->streamed );
			StreamResponder::emit(
				'error',
				array(
					'message' => ChatWords::localize( $error, $this->lang )->get_error_message(),
					'code'    => $error->get_error_code(),
					'handoff' => true,
				)
			);
			exit;
		}

		// A provider that could not stream answered in one piece.
		if ( null === $kept && '' === $state->streamed && '' !== (string) $result['answer'] ) {
			StreamResponder::emit( 'text_delta', array( 'text' => (string) $result['answer'] ) );
		}

		$this->finish( $token, $guard, $state );
		$message = $this->record( $id, $result, $start, null === $kept ? $cache_key : null );
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
				'answered'   => $result['answered'],
				'handoff'    => ! empty( $result['handoff'] ),
				'message_id' => $message,
			)
		);
		exit;
	}

	/**
	 * The engine failed: store a reply so the conversation keeps taking turns
	 * (some providers refuse two customer messages in a row), and tell the
	 * customer only that the assistant cannot answer now.
	 *
	 * @param int      $id      Conversation.
	 * @param WP_Error $error   Engine error.
	 * @param string   $partial Words already streamed, if any.
	 * @return WP_Error
	 */
	private static function failed( int $id, WP_Error $error, string $partial = '' ): WP_Error {
		$shown = self::unavailable( $error );
		ConversationStore::add_message( $id, 'assistant', '' !== $partial ? $partial : $shown->get_error_message(), array( 'answered' => false ) );

		return $shown;
	}

	/**
	 * What a customer sees when the engine failed: never the provider's own
	 * words (they can name the key, the quota or the endpoint). The real
	 * error goes to the log.
	 *
	 * @param WP_Error $error Engine error.
	 * @return WP_Error
	 */
	private static function unavailable( WP_Error $error ): WP_Error {
		if ( function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->error(
				'Help assistant could not answer a customer',
				array(
					'code'    => $error->get_error_code(),
					'message' => \DoubleScale\Pro\Modules\Ai\AiHealth::redact( $error->get_error_message() ),
				)
			);
		}

		return ChatGuard::unavailable();
	}

	/**
	 * Whether an AI provider is connected (or a test provider is filtered in).
	 *
	 * @return bool
	 */
	public static function ai_ready(): bool {
		if ( null !== apply_filters( 'doublescale_help_chat_provider', null ) ) {
			return true;
		}

		// Configured, and not refused by the provider (wrong key, model,
		// no credit): a failing setup sends customers to the team instead.
		return class_exists( '\\DoubleScale\\Admin\\AdminConfig' ) && \DoubleScale\Admin\AdminConfig::is_ai_configured()
			&& ! \DoubleScale\Pro\Modules\Ai\AiHealth::failing();
	}

	/**
	 * @param array<int, array{role: string, content: string}> $history      History.
	 * @param string                                           $text         Question.
	 * @param string[]                                         $visibilities Visibilities.
	 * @param callable|null                                    $on_delta     Stream callback.
	 * @return array<string, mixed>|WP_Error
	 */
	private function answer( array $history, string $text, array $visibilities, ?callable $on_delta = null ) {
		$settings = HelpSettings::get();

		return ( new ChatEngine() )->respond(
			$history,
			$text,
			$visibilities,
			array(
				'name'     => $settings['name'],
				'fallback' => $settings['fallback'],
				'handoff'  => HandoffService::available() && ChatGuard::available(),
				'lang'     => $this->lang,
			),
			$on_delta
		);
	}

	/**
	 * Free the slot as soon as the model is done (not at shutdown).
	 *
	 * @param string    $token Slot token.
	 * @param ChatGuard $guard Guard.
	 * @param object    $state Shared state.
	 * @return void
	 */
	private function finish( string $token, ChatGuard $guard, $state ): void {
		$state->done = true;
		if ( '' !== $token ) {
			$guard->release( $token );
		}
	}

	/**
	 * Store the answer; keep a fresh first answer for the next asker.
	 *
	 * @param int                  $id        Conversation.
	 * @param array<string, mixed> $result    Engine result.
	 * @param float                $start     microtime() at start.
	 * @param string|null          $cache_key Key taken before the model was asked; null keeps nothing.
	 * @return int The stored answer's id (what a customer rates); 0 for small talk.
	 */
	private function record( int $id, array $result, float $start, ?string $cache_key ): int {
		$message = ConversationStore::add_message(
			$id,
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

		AnswerCache::put( $cache_key, $result );

		// A greeting's reply is no help answer: nothing to rate.
		return empty( $result['small_talk'] ) ? $message : 0;
	}

	/**
	 * A message to the team in a live conversation.
	 *
	 * @param array<string, mixed> $conversation Conversation.
	 * @param string               $text         Message.
	 * @param bool                 $stream       Answer as a stream.
	 * @param string[]             $files        Hashes of files uploaded for it.
	 * @return WP_REST_Response|WP_Error|void
	 */
	private function live( array $conversation, string $text, bool $stream, array $files = array() ) {
		$message = LiveChat::customer_message( $conversation, $text, $files );
		if ( is_wp_error( $message ) && 'help_empty_question' === $message->get_error_code() ) {
			return $message;
		}
		if ( is_wp_error( $message ) ) {
			return new WP_Error( 'help_live_failed', __( 'Your message could not be sent. Please try again.', 'doublescale' ), array( 'status' => 500 ) );
		}
		// Where it stands after this message: a new problem opens a solved
		// conversation, "thanks" keeps it solved. The chat knows at once.
		$fresh = ConversationStore::find_by_id( (int) $conversation['id'] );
		$now   = null !== $fresh ? LiveChat::status( $fresh ) : 'live';

		if ( ! $stream ) {
			return new WP_REST_Response(
				array(
					'conversation' => (string) $conversation['hash'],
					'live'         => true,
					'answer'       => '',
					'answered'     => true,
					'citations'    => array(),
					'attachments'  => $message['attachments'],
					// The visitor's message: "Seen" once the team reaches it.
					'message_id'   => (int) $message['id'],
					'status'       => $now,
					'reopened'     => ! empty( $message['reopened'] ),
				),
				200
			);
		}

		StreamResponder::start();
		StreamResponder::emit( 'conversation', array( 'conversation' => (string) $conversation['hash'] ) );
		StreamResponder::emit(
			'live',
			array(
				'message_id'  => (int) $message['id'],
				'attachments' => $message['attachments'],
				'status'      => $now,
				'reopened'    => ! empty( $message['reopened'] ),
			)
		);
		StreamResponder::emit(
			'done',
			array(
				'answered' => true,
				'live'     => true,
			)
		);
		exit;
	}

	/**
	 * The conversation to continue: null for a new one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>|WP_Error|null
	 */
	private function existing_conversation( WP_REST_Request $request ) {
		$hash = sanitize_key( (string) $request->get_param( 'conversation' ) );
		if ( '' === $hash ) {
			return null;
		}

		$row = ConversationStore::find( $hash );
		if ( ! $row || 'admin' === $row['channel'] || (int) $row['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
		}

		return $row;
	}

	/**
	 * @param string   $channel      Channel.
	 * @param string[] $visibilities Reader's visibilities.
	 * @return array<string, mixed>
	 */
	public static function create_conversation( string $channel, array $visibilities ): array {
		$audience = in_array( KbArticleModel::VISIBILITY_PORTAL, $visibilities, true ) ? KbArticleModel::VISIBILITY_PORTAL : KbArticleModel::VISIBILITY_PUBLIC;
		$contact  = 'portal' === $channel && class_exists( PortalIdentity::class ) ? PortalIdentity::current_contact() : null;

		return ConversationStore::create(
			$channel,
			$audience,
			get_current_user_id(),
			array(
				'contact_id' => $contact ? (int) $contact->id : null,
				'ip_hash'    => wp_hash( ClientIp::bucket() ),
			)
		);
	}

	/**
	 * @return array{input: int, output: int}
	 */
	private static function no_usage(): array {
		return array(
			'input'  => 0,
			'output' => 0,
		);
	}
}
