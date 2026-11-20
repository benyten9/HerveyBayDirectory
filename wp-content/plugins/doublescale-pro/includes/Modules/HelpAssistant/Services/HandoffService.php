<?php
/**
 * Hand a help assistant conversation to the support team as a ticket.
 *
 * The ticket is opened through free's TicketService, so everything a ticket
 * normally does still happens (the customer's "we received your request"
 * email with the follow-up link, team notifications, automations):
 *
 *   - title:   the conversation's first question (or the one question asked
 *              when the assistant refused before a conversation existed)
 *   - message: the whole conversation, each turn labelled, with the source
 *              articles the assistant cited — written by the customer
 *              (no agent as author), source "chat"
 *   - mailbox: the one chosen in the assistant's settings, else the default
 *
 * The follow-up link is never handed back: only the customer's email gets it.
 * A visitor may type anyone's address, and the ticket joins the contact with
 * that address — returning the link would let them read replies meant for
 * that person.
 *
 * Fires `doublescale_help_chat_handed_off( $ticket, $conversation )`.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;

/**
 * HandoffService class.
 */
final class HandoffService {

	/**
	 * Longest ticket title taken from a question.
	 */
	private const TITLE_CHARS = 150;

	/**
	 * Whether free ships what a handoff needs.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( TicketService::class ) && class_exists( ContactResolver::class );
	}

	/**
	 * Open the ticket.
	 *
	 * @param array<string, mixed>|null $conversation Conversation row, or null.
	 * @param string                    $question     The last question: the whole ticket when there is no
	 *                                                conversation; added at its end when the assistant
	 *                                                refused it before storing it (busy, daily cap).
	 * @param array<string, mixed>      $customer     contact_id, or email + first_name + last_name.
	 * @return \DoubleScale\Modules\Support\Models\TicketModel|WP_Error
	 */
	public static function open( ?array $conversation, string $question, array $customer ) {
		$messages = null !== $conversation ? ConversationStore::transcript( (int) $conversation['id'] ) : array();
		$asked = '';
		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$asked = (string) $message['content'];
			}
		}
		if ( '' !== trim( $question ) && trim( $asked ) !== trim( $question ) ) {
			$messages[] = array(
				'role'      => 'user',
				'content'   => $question,
				'citations' => null,
			);
		}

		$first = '';
		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$first = (string) $message['content'];
				break;
			}
		}
		if ( '' === trim( $first ) ) {
			return new WP_Error( 'help_handoff_empty', __( 'There is nothing to send to the support team yet.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$data = array_merge(
			$customer,
			array(
				'title'          => self::title( $first ),
				'content'        => self::transcript( $messages ),
				'source'         => 'chat',
				// The customer wrote it, even if someone is signed in.
				'author_user_id' => null,
			)
		);

		$mailbox = (int) HelpSettings::get()['handoff_mailbox'];
		if ( $mailbox > 0 && MailboxModel::find( $mailbox ) ) {
			$data['mailbox_id'] = $mailbox;
		}

		// The address was typed (or is the signed-in account's) and no confirmed
		// portal contact stands behind it: nobody proved it is theirs. The ticket
		// is flagged (see UnverifiedContact) and, when it belongs to an existing
		// contact, the team is told before they share that customer's account or
		// order details in the chat.
		$flagged = self::unproven( $customer );
		$known   = $flagged && class_exists( ContactModel::class )
			&& null !== ContactModel::where( 'email', strtolower( trim( (string) $customer['email'] ) ) )->first();
		if ( $flagged ) {
			// For Free versions that read it while creating the ticket.
			$data['unverified_contact'] = true;
		}

		try {
			$tickets = new TicketService( new ContactResolver() );
			$ticket  = $tickets->create_ticket( $data );
		} catch ( \Throwable $e ) {
			// A database or listener failure: logged by the caller, never shown.
			// Never the exception's text (it can quote a query); its class is enough to find it.
			return new WP_Error( 'help_handoff_exception', __( 'The ticket could not be created.', 'doublescale' ), array( 'status' => 500, 'exception' => get_class( $e ) ) );
		}
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		if ( $flagged ) {
			UnverifiedContact::set( $ticket );
		}
		if ( $known ) {
			$tickets->add_note(
				(int) $ticket->id,
				array(
					'content'        => wpautop( esc_html( self::unverified_note() ) ),
					'author_user_id' => null,
				)
			);
		}

		if ( null !== $conversation ) {
			ConversationStore::update(
				(int) $conversation['id'],
				array(
					'status'        => 'handed_off',
					'ticket_id'     => (int) $ticket->id,
					'contact_id'    => (int) $ticket->contact_id,
					'visitor_email' => isset( $customer['email'] ) ? (string) $customer['email'] : null,
				)
			);
		}

		/**
		 * Fires after a help assistant conversation became a support ticket.
		 *
		 * @param \DoubleScale\Modules\Support\Models\TicketModel $ticket       The new ticket.
		 * @param array<string, mixed>|null                       $conversation The conversation row (null when the
		 *                                                                      assistant refused before one existed).
		 */
		do_action( 'doublescale_help_chat_handed_off', $ticket, $conversation );

		return $ticket;
	}

	/**
	 * Whether the address on a handoff was never proven: a typed email, or a
	 * signed-in account's email without a confirmed portal contact. A confirmed
	 * portal contact (`contact_id`) is proven.
	 *
	 * @param array<string, mixed> $customer contact_id, or email + names.
	 * @return bool
	 */
	public static function unproven( array $customer ): bool {
		return empty( $customer['contact_id'] ) && ! empty( $customer['email'] );
	}

	/**
	 * The team's note on a handoff whose email belongs to an existing contact.
	 *
	 * @return string
	 */
	private static function unverified_note(): string {
		if ( is_user_logged_in() ) {
			return __( 'Email not verified: this is the email of the signed-in website account, which is not a confirmed client, and it belongs to an existing contact. Make sure it is really them before sharing account or order details.', 'doublescale' );
		}

		return __( 'Email not verified: the visitor typed this email in the chat without signing in, and it belongs to an existing contact. Make sure it is really them before sharing account or order details.', 'doublescale' );
	}

	/**
	 * @param string $question First question.
	 * @return string
	 */
	private static function title( string $question ): string {
		$question = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $question ) ) );

		return mb_strlen( $question, 'UTF-8' ) > self::TITLE_CHARS
			? rtrim( mb_substr( $question, 0, self::TITLE_CHARS - 1, 'UTF-8' ) ) . '…'
			: $question;
	}

	/**
	 * The conversation as the ticket's opening message (HTML; every text is
	 * escaped, links are the server-built article URLs).
	 *
	 * @param array<int, array<string, mixed>> $messages Messages, oldest first.
	 * @return string
	 */
	private static function transcript( array $messages ): string {
		$html = '<p><strong>' . esc_html__( 'Conversation with the help assistant', 'doublescale' ) . '</strong></p>';

		foreach ( $messages as $message ) {
			$who   = 'user' === $message['role'] ? __( 'Customer', 'doublescale' ) : __( 'Assistant', 'doublescale' );
			// dir="auto": an Arabic turn reads right to left in an English ticket.
			$html .= '<p dir="auto"><strong>' . esc_html( $who ) . ':</strong><br>' . nl2br( esc_html( (string) $message['content'] ) ) . '</p>';

			$citations = is_string( $message['citations'] ?? null ) ? json_decode( (string) $message['citations'], true ) : null;
			if ( is_array( $citations ) && array() !== $citations ) {
				$html .= '<ul>';
				foreach ( $citations as $citation ) {
					if ( ! is_array( $citation ) ) {
						continue;
					}
					$label = (string) ( $citation['title'] ?? '' );
					// Numbered like the [n] marks in the answer.
					if ( ! empty( $citation['n'] ) ) {
						$label = '[' . (int) $citation['n'] . '] ' . $label;
					}
					$url = (string) ( $citation['url'] ?? '' );
					$html .= '<li>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label ) ) . '</li>';
				}
				$html .= '</ul>';
			}
		}

		return $html;
	}
}
