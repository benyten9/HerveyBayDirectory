<?php
/**
 * Hand a help assistant conversation to the support team.
 *
 *   POST /doublescale/v1/help/public/handoff
 *     conversation  Hash of the conversation (optional: the assistant may have
 *                   refused before one existed — busy, daily cap).
 *     message       The question, when there is no conversation.
 *     channel       portal | help_page | widget.
 *     name, email   A visitor's; a portal client needs neither.
 *     consent       A visitor agrees their conversation and email go to the team.
 *     dshc_hp       Hidden field bots fill in.
 *
 * Answers 201 { ticket, message } — the ticket number and a sentence to show,
 * never the ticket's hash or link: the follow-up link only goes to the
 * customer's email ({@see HandoffService}). 422 help_handoff_needs_contact
 * tells the chat to ask a visitor for their name and email.
 *
 * Open to anyone, so: the channel must be on, a conversation continues only
 * for whoever started it (never a staff preview), it is handed over once
 * (a second request answers with the same ticket), and each visitor opens at
 * most HOURLY tickets an hour, each email address receives at most
 * PER_INBOX_DAILY a day, and the whole site opens a bounded number an hour
 * and a day (filters doublescale_help_handoff_site_hourly / _daily) — the
 * email typed may be someone else's, so this must not become a way to flood
 * a stranger's inbox. A conversation being handed over is claimed first, and
 * the same question sent again without a conversation within ten minutes is
 * refused, so a double click never opens two tickets.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatEngine;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ChatGuard;
use DoubleScale\Pro\Modules\HelpAssistant\Services\ConversationStore;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HandoffService;
use DoubleScale\Pro\Modules\HelpAssistant\Services\HelpSettings;
use DoubleScale\Pro\Modules\HelpAssistant\Services\LiveChat;
use DoubleScale\Pro\Modules\Support\Services\TeamPresence;
use DoubleScale\Pro\Modules\HelpAssistant\Services\Schema;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestHelpHandoffController class.
 */
class RestHelpHandoffController extends RestController {

	/**
	 * Tickets one visitor may open from the assistant in an hour.
	 */
	private const HOURLY = 3;

	/**
	 * Requests one email address may receive in a day: the address typed may
	 * be a stranger's, who then gets "we received your request" emails.
	 */
	private const PER_INBOX_DAILY = 2;

	/**
	 * Longest email the conversation can keep (visitor_email column).
	 */
	private const EMAIL_CHARS = 191;

	/**
	 * @var string
	 */
	protected $rest_base = 'help/public/handoff';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handoff' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'conversation'                    => array( 'type' => 'string' ),
						'message'                         => array( 'type' => 'string' ),
						'channel'                         => array(
							'type'    => 'string',
							'enum'    => HelpSettings::CHANNELS,
							'default' => 'widget',
						),
						'name'                            => array( 'type' => 'string' ),
						'email'                           => array( 'type' => 'string' ),
						'consent'                         => array(
							'type'    => 'boolean',
							'default' => false,
						),
						RestHelpPublicChatController::TRAP => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Open the ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handoff( $request ) {
		nocache_headers();

		$disabled = $this->require_module( 'support' );
		if ( $disabled ) {
			return $disabled;
		}
		// Also gone when the owner runs the AI alone with no contact form.
		if ( ! HelpSettings::handoff_offered() ) {
			return self::not_found();
		}

		if ( '' !== trim( (string) $request->get_param( RestHelpPublicChatController::TRAP ) ) ) {
			return new WP_Error( 'help_rejected', __( 'Your message could not be sent.', 'doublescale' ), array( 'status' => 400 ) );
		}

		Schema::ensure();

		$conversation = null;
		$started      = 0;
		$hash         = sanitize_key( (string) $request->get_param( 'conversation' ) );
		if ( '' !== $hash ) {
			$conversation = ConversationStore::find( $hash );
			if ( ! $conversation || 'admin' === $conversation['channel'] || (int) $conversation['user_id'] !== get_current_user_id() ) {
				return new WP_Error( 'not_found', __( 'Conversation not found.', 'doublescale' ), array( 'status' => 404 ) );
			}
		}

		$channel = null !== $conversation ? (string) $conversation['channel'] : (string) $request->get_param( 'channel' );
		if ( ! HelpSettings::channel_on( $channel ) ) {
			return self::not_found();
		}

		// Handed over already: the same ticket, no second one.
		if ( null !== $conversation && ! empty( $conversation['ticket_id'] ) ) {
			return new WP_REST_Response(
				array(
					'ticket'  => (int) $conversation['ticket_id'],
					'message' => self::done_message( (int) $conversation['ticket_id'], (string) ( $conversation['visitor_email'] ?? '' ), (string) $request->get_param( 'lang' ) ),
				),
				200
			);
		}

		$question = trim( wp_strip_all_tags( (string) $request->get_param( 'message' ) ) );
		if ( mb_strlen( $question, 'UTF-8' ) > ChatEngine::MAX_QUESTION_CHARS ) {
			$question = mb_substr( $question, 0, ChatEngine::MAX_QUESTION_CHARS, 'UTF-8' );
		}
		if ( null === $conversation && '' === $question ) {
			return new WP_Error( 'help_handoff_empty', __( 'There is nothing to send to the support team yet.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$customer = $this->customer( $request, $channel );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$limited = $this->limits( $customer );
		if ( $limited ) {
			return $limited;
		}

		if ( null !== $conversation ) {
			if ( ! ConversationStore::claim_handoff( (int) $conversation['id'] ) ) {
				$now = ConversationStore::find( (string) $conversation['hash'] );
				if ( $now && ! empty( $now['ticket_id'] ) ) {
					return new WP_REST_Response(
						array(
							'ticket'  => (int) $now['ticket_id'],
							'message' => self::done_message( (int) $now['ticket_id'], (string) ( $now['visitor_email'] ?? '' ), (string) $request->get_param( 'lang' ) ),
						),
						200
					);
				}
				return new WP_Error( 'help_handoff_in_progress', __( 'Your request is already being sent.', 'doublescale' ), array( 'status' => 409 ) );
			}
		} elseif ( ! RateLimiter::once( 'help_handoff_same|' . md5( ClientIp::bucket() . '|' . wp_json_encode( $customer ) . '|' . $question ), 10 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'help_handoff_duplicate', __( 'We already sent this request to our team.', 'doublescale' ), array( 'status' => 409 ) );
		} elseif ( LiveChat::enabled() ) {
			// No conversation yet (the chat goes straight to the team, or the
			// assistant refused before one existed): start one with the
			// message, so the chat stays open on the ticket (live chat).
			$conversation = RestHelpPublicChatController::create_conversation( $channel, KbAudience::current() );
			ConversationStore::add_message( (int) $conversation['id'], 'user', $question );
			ConversationStore::claim_handoff( (int) $conversation['id'] );
			$started = (int) $conversation['id'];
		}

		$ticket = HandoffService::open( $conversation, $question, $customer );
		if ( is_wp_error( $ticket ) && ! empty( $started ) ) {
			// Nothing was sent: the conversation started here goes too.
			ConversationStore::delete( $started );
			$conversation = null;
		} elseif ( is_wp_error( $ticket ) && null !== $conversation ) {
			ConversationStore::update( (int) $conversation['id'], array( 'status' => 'open' ) );
		}
		if ( is_wp_error( $ticket ) ) {
			if ( 'help_handoff_empty' === $ticket->get_error_code() ) {
				return $ticket;
			}
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Help assistant could not open a support ticket',
					array(
						'code'      => $ticket->get_error_code(),
						'exception' => (string) ( is_array( $ticket->get_error_data() ) ? ( $ticket->get_error_data()['exception'] ?? '' ) : '' ),
					)
				);
			}
			return new WP_Error(
				'help_handoff_failed',
				__( 'We could not send your request right now. Please try again later.', 'doublescale' ),
				array( 'status' => 503 )
			);
		}

		$body = array(
			'ticket'  => (int) $ticket->id,
			'message' => self::done_message( (int) $ticket->id, (string) ( $customer['email'] ?? '' ), (string) $request->get_param( 'lang' ) ),
		);
		// The conversation started here: the chat follows it from now on.
		if ( ! empty( $started ) && null !== $conversation ) {
			$body['conversation'] = (string) $conversation['hash'];
		}

		return new WP_REST_Response( $body, 201 );
	}

	/**
	 * Who the ticket is for: the signed-in portal client, or the visitor's
	 * name and email (with consent).
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $channel Channel.
	 * @return array<string, mixed>|WP_Error
	 */
	private function customer( WP_REST_Request $request, string $channel ) {
		$contact = is_user_logged_in() && class_exists( PortalIdentity::class ) ? PortalIdentity::current_contact() : null;
		if ( $contact ) {
			return array( 'contact_id' => (int) $contact->id );
		}
		if ( 'portal' === $channel ) {
			return new WP_Error( 'rest_forbidden', __( 'Sign in to the client portal to ask here.', 'doublescale' ), array( 'status' => 403 ) );
		}

		// Signed in, but not (yet) a confirmed client: their account already
		// says who they are, so the chat does not ask for a name and email
		// again. The same as a ticket opened from the support portal, which
		// uses the account's email and name. Asking for "consent" is for
		// visitors typing details in: a signed-in customer clicked to reach
		// the team themselves.
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$email = sanitize_email( (string) $user->user_email );
			if ( is_email( $email ) ) {
				$first = trim( (string) $user->first_name );
				$last  = trim( (string) $user->last_name );
				if ( '' === $first ) {
					$parts = preg_split( '/\s+/u', trim( (string) $user->display_name ), 2 );
					$first = (string) ( $parts[0] ?? '' );
					$last  = '' !== $last ? $last : (string) ( $parts[1] ?? '' );
				}

				return array(
					'email'      => $email,
					'first_name' => $first,
					'last_name'  => $last,
				);
			}
		}

		$name  = trim( sanitize_text_field( (string) $request->get_param( 'name' ) ) );
		$email = trim( (string) $request->get_param( 'email' ) );
		if ( '' === $name && '' === $email ) {
			return new WP_Error( 'help_handoff_needs_contact', __( 'Tell us how to reach you.', 'doublescale' ), array( 'status' => 422 ) );
		}
		if ( '' === $name || mb_strlen( $name, 'UTF-8' ) > 100 ) {
			return new WP_Error( 'help_handoff_name', __( 'Please type your name.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) || strlen( $email ) > self::EMAIL_CHARS ) {
			return new WP_Error( 'help_handoff_email', __( 'Please type a valid email address.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( ! rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			return new WP_Error( 'help_handoff_consent', __( 'Please agree to send this conversation to our support team.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$parts = preg_split( '/\s+/u', $name, 2 );

		return array(
			'email'      => sanitize_email( $email ),
			'first_name' => (string) ( $parts[0] ?? $name ),
			'last_name'  => (string) ( $parts[1] ?? '' ),
		);
	}

	/**
	 * Count this request against the visitor, the inbox and the site.
	 *
	 * @param array<string, mixed> $customer Customer (email when a visitor).
	 * @return WP_Error|null
	 */
	private function limits( array $customer ): ?WP_Error {
		$counts = array(
			array( 'help_handoff|' . ClientIp::bucket(), HOUR_IN_SECONDS, self::HOURLY ),
			array( 'help_handoff_site_h|' . gmdate( 'YmdH' ), HOUR_IN_SECONDS, (int) apply_filters( 'doublescale_help_handoff_site_hourly', 30 ) ),
			array( 'help_handoff_site_d|' . gmdate( 'Ymd' ), DAY_IN_SECONDS + HOUR_IN_SECONDS, (int) apply_filters( 'doublescale_help_handoff_site_daily', 200 ) ),
		);
		if ( isset( $customer['email'] ) ) {
			$counts[] = array( 'help_handoff_mail|' . md5( strtolower( (string) $customer['email'] ) ), DAY_IN_SECONDS, self::PER_INBOX_DAILY );
		}

		foreach ( $counts as $count ) {
			$hits = RateLimiter::hit( $count[0], $count[1] );
			if ( $hits < 1 ) {
				return ChatGuard::unavailable();
			}
			if ( $hits > $count[2] ) {
				return new WP_Error(
					'help_handoff_limited',
					__( 'You have already sent several requests. Please wait a little before sending another one.', 'doublescale' ),
					array( 'status' => 429 )
				);
			}
		}

		return null;
	}

	/**
	 * The sentence the chat shows once the ticket exists. Never a link. Short
	 * and honest: "in a few minutes" while the team is online, "as soon as
	 * they're back" when not; the number last; in the chat's language.
	 *
	 * @param int    $ticket Ticket number.
	 * @param string $email  Visitor's email ('' for a portal client).
	 * @param string $lang   The chat's language ('ar' or '').
	 * @return string
	 */
	private static function done_message( int $ticket, string $email, string $lang = '' ): string {
		// The team does not answer in the chat: the answer comes by email.
		if ( ! LiveChat::enabled() ) {
			if ( 'ar' === $lang ) {
				return '' !== $email
					? 'شكراً! سيرد عليك فريقنا بالبريد الإلكتروني على ' . $email . '. (طلب رقم ' . $ticket . ')'
					: 'شكراً! سيرد فريقنا بالبريد الإلكتروني، وستجده أيضاً في تذاكر الدعم. (طلب رقم ' . $ticket . ')';
			}

			return '' !== $email
				/* translators: 1: the visitor's email address, 2: request number */
				? sprintf( __( 'Thanks! Our team will reply to %1$s by email. (Request #%2$d)', 'doublescale' ), $email, $ticket )
				/* translators: %d: support ticket number */
				: sprintf( __( "Thanks! Our team will reply by email. You'll also find it under Support Tickets. (Ticket #%d)", 'doublescale' ), $ticket );
		}

		$online = TeamPresence::online();

		if ( 'ar' === $lang ) {
			$when = $online ? 'شكراً! سيرد عليك أحد أعضاء الفريق هنا خلال دقائق' : 'شكراً! سيرد فريقنا هنا فور عودته';
			if ( '' !== $email ) {
				return $when . '، وعلى ' . $email . '. (طلب رقم ' . $ticket . ')';
			}

			return $when . '.' . ( $online ? '' : ' ستجده أيضاً في تذاكر الدعم.' ) . ' (طلب رقم ' . $ticket . ')';
		}

		if ( '' !== $email ) {
			return $online
				/* translators: 1: the visitor's email address, 2: request number */
				? sprintf( __( 'Thanks! A team member will reply right here in a few minutes, and to %1$s. (Request #%2$d)', 'doublescale' ), $email, $ticket )
				/* translators: 1: the visitor's email address, 2: request number */
				: sprintf( __( "Thanks! Our team will reply right here as soon as they're back, and to %1\$s. (Request #%2\$d)", 'doublescale' ), $email, $ticket );
		}

		return $online
			/* translators: %d: support ticket number */
			? sprintf( __( 'Thanks! A team member will reply right here in a few minutes. (Ticket #%d)', 'doublescale' ), $ticket )
			/* translators: %d: support ticket number */
			: sprintf( __( "Thanks! Our team will reply right here as soon as they're back. You'll also find it under Support Tickets. (Ticket #%d)", 'doublescale' ), $ticket );
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
