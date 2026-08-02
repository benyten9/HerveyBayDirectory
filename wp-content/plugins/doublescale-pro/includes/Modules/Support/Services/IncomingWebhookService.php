<?php
/**
 * Create-or-reply handler for the incoming support webhook.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Support\Constants\TicketPriority;
use DoubleScale\Modules\Support\Constants\TicketStatus;
use DoubleScale\Modules\Support\Models\MailboxModel;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;
use WP_Error;

/**
 * IncomingWebhookService class.
 */
final class IncomingWebhookService {

	/**
	 * @var WebhookTokenService
	 */
	private $token_service;

	/**
	 * @param WebhookTokenService $token_service Mailbox token helper.
	 */
	public function __construct( WebhookTokenService $token_service ) {
		$this->token_service = $token_service;
	}

	/**
	 * Static field reference for the admin settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_field_definitions(): array {
		return array(
			array(
				'key'      => 'title',
				'label'    => __( 'Title', 'doublescale' ),
				'type'     => 'text',
				'required' => true,
			),
			array(
				'key'      => 'content',
				'label'    => __( 'Content', 'doublescale' ),
				'type'     => 'textarea',
				'required' => true,
			),
			array(
				'key'      => 'priority',
				'label'    => __( 'Priority', 'doublescale' ),
				'type'     => 'text',
				'required' => false,
			),
			array(
				'key'      => 'sender[first_name]',
				'label'    => __( 'Sender first name', 'doublescale' ),
				'type'     => 'text',
				'required' => false,
			),
			array(
				'key'      => 'sender[last_name]',
				'label'    => __( 'Sender last name', 'doublescale' ),
				'type'     => 'text',
				'required' => false,
			),
			array(
				'key'      => 'sender[email]',
				'label'    => __( 'Sender email', 'doublescale' ),
				'type'     => 'email',
				'required' => true,
			),
		);
	}

	/**
	 * Build the admin config payload for a mailbox.
	 *
	 * @param MailboxModel $mailbox Mailbox row.
	 * @return array<string, mixed>
	 */
	public function get_admin_config( MailboxModel $mailbox ): array {
		$token = $this->token_service->get_or_create_token( $mailbox );

		return array(
			'url'               => $this->token_service->build_webhook_url( $mailbox, $token ),
			'fields'            => $this->get_field_definitions(),
			'sample_body'       => $this->get_sample_body(),
			'sample_reply_body' => $this->get_sample_reply_body(),
		);
	}

	/**
	 * Example JSON payload for Postman / API clients (create ticket).
	 *
	 * @return array<string, mixed>
	 */
	public function get_sample_body(): array {
		return array(
			'title'    => __( 'Need help with my order', 'doublescale' ),
			'content'  => __( 'I placed an order yesterday and have not received a confirmation email.', 'doublescale' ),
			'priority' => 'normal',
			'sender'   => array(
				'first_name' => 'Jane',
				'last_name'  => 'Doe',
				'email'      => '[email protected]',
			),
		);
	}

	/**
	 * Example JSON payload for appending a reply to an existing ticket.
	 *
	 * Uses the same title + sender email as {@see get_sample_body()} with new
	 * content — the webhook threads onto the matching open ticket instead of
	 * opening a new one.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sample_reply_body(): array {
		$create = $this->get_sample_body();

		return array(
			'title'   => $create['title'],
			'content' => __( 'Here is my order number: #12345. Please let me know when it ships.', 'doublescale' ),
			'sender'  => $create['sender'],
		);
	}

	/**
	 * Rotate the webhook token and return the new URL.
	 *
	 * @param MailboxModel $mailbox Mailbox row.
	 * @return array<string, string>
	 */
	public function regenerate_config( MailboxModel $mailbox ): array {
		$token = $this->token_service->regenerate( $mailbox );

		return array(
			'url' => $this->token_service->build_webhook_url( $mailbox, $token ),
		);
	}

	/**
	 * Create a ticket or append a reply from webhook form-data.
	 *
	 * @param MailboxModel         $mailbox Target mailbox.
	 * @param array<string, mixed> $params  Request params.
	 * @return array<string, mixed>|WP_Error
	 */
	public function ingest( MailboxModel $mailbox, array $params ) {
		$normalized = $this->normalize_payload( $params );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$service = $this->ticket_service();
		if ( ! $service ) {
			return new WP_Error( 'service_unavailable', __( 'Support service is unavailable.', 'doublescale' ), array( 'status' => 503 ) );
		}

		try {
			$contact = $this->contact_resolver()->find_or_create(
				$normalized['email'],
				$normalized['first_name'],
				$normalized['last_name']
			);
		} catch ( \InvalidArgumentException $e ) {
			return new WP_Error( 'invalid_email', __( 'A valid sender email is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$ticket = $this->find_matching_ticket(
			(int) $mailbox->id,
			(int) $contact->id,
			$normalized['title']
		);

		if ( $ticket ) {
			$activity = $service->add_reply(
				$ticket,
				array(
					'content' => $normalized['content'],
					'source'  => 'web',
				)
			);
			if ( is_wp_error( $activity ) ) {
				return $activity;
			}

			if ( in_array( $ticket->status, array( TicketStatus::RESOLVED, TicketStatus::CLOSED ), true ) ) {
				$service->update_ticket( $ticket, array( 'status' => TicketStatus::OPEN ) );
			}

			$ticket->refresh();

			return array(
				'action'      => 'replied',
				'ticket_id'   => (int) $ticket->id,
				'ticket_hash' => (string) $ticket->hash,
			);
		}

		$result = $service->create_ticket(
			array(
				'title'      => $normalized['title'],
				'content'    => $normalized['content'],
				'email'      => $normalized['email'],
				'first_name' => $normalized['first_name'],
				'last_name'  => $normalized['last_name'],
				'priority'   => $normalized['priority'],
				'mailbox_id' => (int) $mailbox->id,
				'source'     => 'web',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'action'      => 'created',
			'ticket_id'   => (int) $result->id,
			'ticket_hash' => (string) $result->hash,
		);
	}

	/**
	 * @param array<string, mixed> $params Request params.
	 * @return array<string, string>|WP_Error
	 */
	private function normalize_payload( array $params ) {
		$title = isset( $params['title'] ) ? $this->sanitize_title( (string) $params['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'Ticket title is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$content = isset( $params['content'] ) ? wp_kses_post( (string) $params['content'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'missing_content', __( 'Opening message content is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$sender = isset( $params['sender'] ) && is_array( $params['sender'] ) ? $params['sender'] : array();
		$email  = isset( $sender['email'] ) ? strtolower( trim( (string) $sender['email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'missing_email', __( 'A valid sender email is required.', 'doublescale' ), array( 'status' => 400 ) );
		}

		$priority = isset( $params['priority'] ) ? sanitize_key( (string) $params['priority'] ) : TicketPriority::NORMAL;
		if ( ! in_array( $priority, TicketPriority::all(), true ) ) {
			$priority = TicketPriority::NORMAL;
		}

		return array(
			'title'      => $title,
			'content'    => $content,
			'priority'   => $priority,
			'email'      => $email,
			'first_name' => isset( $sender['first_name'] ) ? sanitize_text_field( (string) $sender['first_name'] ) : '',
			'last_name'  => isset( $sender['last_name'] ) ? sanitize_text_field( (string) $sender['last_name'] ) : '',
		);
	}

	/**
	 * @param int    $mailbox_id Mailbox id.
	 * @param int    $contact_id Contact id.
	 * @param string $title      Ticket title.
	 * @return TicketModel|null
	 */
	private function find_matching_ticket( int $mailbox_id, int $contact_id, string $title ): ?TicketModel {
		$ticket = TicketModel::byMailbox( $mailbox_id )
			->byContact( $contact_id )
			->where( 'title', $title )
			->active()
			->orderBy( 'id', 'desc' )
			->first();

		return $ticket instanceof TicketModel ? $ticket : null;
	}

	/**
	 * @param string $title Raw title.
	 * @return string
	 */
	private function sanitize_title( string $title ): string {
		return mb_substr( sanitize_text_field( $title ), 0, 255 );
	}

	/**
	 * @return TicketService|null
	 */
	private function ticket_service(): ?TicketService {
		if ( ! function_exists( 'doublescale_resolve' ) ) {
			return null;
		}
		$service = doublescale_resolve( TicketService::class );
		return $service instanceof TicketService ? $service : null;
	}

	/**
	 * @return ContactResolver
	 */
	private function contact_resolver(): ContactResolver {
		if ( function_exists( 'doublescale_resolve' ) ) {
			$resolver = doublescale_resolve( ContactResolver::class );
			if ( $resolver instanceof ContactResolver ) {
				return $resolver;
			}
		}
		return new ContactResolver();
	}
}
