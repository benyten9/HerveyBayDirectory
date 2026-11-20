<?php
/**
 * WordPress's personal data tools (Tools → Export / Erase Personal Data)
 * for help assistant conversations.
 *
 * A person's conversations are the ones with their email: typed when handing
 * a conversation to support (visitor_email), or of their account (user_id),
 * or of their contact (contact_id). Export lists every message; erase deletes
 * the conversations and their messages.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- our own tables.

/**
 * Privacy class.
 */
final class Privacy {

	public const KEY = 'doublescale-help-conversations';

	/**
	 * Conversations per export or erase page.
	 */
	private const PAGE = 20;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
	}

	/**
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function exporters( $exporters ) {
		$exporters                = (array) $exporters;
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Help assistant conversations', 'doublescale' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function erasers( $erasers ) {
		$erasers                = (array) $erasers;
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Help assistant conversations', 'doublescale' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email, $page = 1 ): array {
		$ids  = self::conversation_ids( (string) $email, (int) $page );
		$data = array();
		foreach ( $ids as $id ) {
			$row   = Insights::conversation( $id );
			$lines = array();
			foreach ( ConversationStore::transcript( $id ) as $message ) {
				$lines[] = self::speaker( $message ) . ': ' . $message['content'];
			}
			$data[] = array(
				'group_id'    => self::KEY,
				'group_label' => __( 'Help assistant conversations', 'doublescale' ),
				'item_id'     => 'help-conversation-' . $id,
				'data'        => array(
					array(
						'name'  => __( 'Started', 'doublescale' ),
						'value' => $row ? (string) $row['conversation']['created_at'] : '',
					),
					array(
						'name'  => __( 'Where', 'doublescale' ),
						'value' => $row ? (string) $row['conversation']['channel'] : '',
					),
					array(
						'name'  => __( 'Email you typed', 'doublescale' ),
						'value' => $row ? (string) $row['conversation']['visitor_email'] : '',
					),
					array(
						'name'  => __( 'Your name', 'doublescale' ),
						'value' => self::name_of( ConversationStore::find_by_id( $id ) ?? array() ),
					),
					array(
						'name'  => __( 'Messages', 'doublescale' ),
						'value' => implode( "\n", $lines ),
					),
				),
			);
			// The pages and cart the team saw beside their ticket (VisitorCapture).
			$context = ConversationStore::context( (array) ( ConversationStore::find_by_id( $id ) ?? array() ) );
			if ( ! empty( $context['pages'] ) && is_array( $context['pages'] ) ) {
				$data[ count( $data ) - 1 ]['data'][] = array(
					'name'  => __( 'Pages you visited', 'doublescale' ),
					'value' => implode( "\n", array_map( static fn( $p ) => (string) ( $p['path'] ?? '' ), $context['pages'] ) ),
				);
			}
			if ( ! empty( $context['cart']['items'] ) && is_array( $context['cart']['items'] ) ) {
				$data[ count( $data ) - 1 ]['data'][] = array(
					'name'  => __( 'Your cart', 'doublescale' ),
					'value' => implode( "\n", array_map( static fn( $i ) => (int) ( $i['quantity'] ?? 0 ) . ' × ' . (string) ( $i['name'] ?? '' ), $context['cart']['items'] ) ),
				);
			}
			// Their rating of the team's help, and their words with it.
			if ( $row && null !== $row['conversation']['satisfaction'] ) {
				$rating = LiveChat::score_label( (int) $row['conversation']['satisfaction'] ) . ' (' . (int) $row['conversation']['satisfaction'] . '/5)';
				if ( '' !== $row['conversation']['satisfaction_comment'] ) {
					$rating .= "\n" . $row['conversation']['satisfaction_comment'];
				}
				$data[ count( $data ) - 1 ]['data'][] = array(
					'name'  => __( 'Your rating', 'doublescale' ),
					'value' => $rating,
				);
			}
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * Who wrote a message, as the person reading their own export sees it:
	 * what they wrote (typed, or replied by email or portal) is "You", the
	 * team's reply carries the team member's first name.
	 *
	 * @param array<string, mixed> $message Message row (role, author).
	 * @return string
	 */
	private static function speaker( array $message ): string {
		switch ( (string) $message['role'] ) {
			case 'assistant':
				return __( 'Assistant', 'doublescale' );
			case 'agent':
				$author = trim( (string) ( $message['author'] ?? '' ) );
				return '' !== $author ? $author : __( 'Support team', 'doublescale' );
			default:
				// user, and customer (their reply by email or portal).
				return __( 'You', 'doublescale' );
		}
	}

	/**
	 * The name held for the conversation: the contact's, else the account's.
	 *
	 * @param array<string, mixed> $conversation Conversation row.
	 * @return string
	 */
	private static function name_of( array $conversation ): string {
		global $wpdb;
		if ( ! empty( $conversation['contact_id'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT first_name, last_name FROM {$wpdb->prefix}doublescale_contacts WHERE id = %d", (int) $conversation['contact_id'] ), ARRAY_A );
			if ( is_array( $row ) ) {
				$name = trim( (string) $row['first_name'] . ' ' . (string) $row['last_name'] );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}
		if ( ! empty( $conversation['user_id'] ) ) {
			$user = get_userdata( (int) $conversation['user_id'] );
			if ( $user ) {
				return (string) $user->display_name;
			}
		}

		return '';
	}

	/**
	 * Erase from the first page each time: the rows already erased are gone.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (unused).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email, $page = 1 ): array {
		$ids      = self::conversation_ids( (string) $email, 1 );
		$messages = array();
		foreach ( $ids as $id ) {
			$row = ConversationStore::find_by_id( $id );
			if ( $row && ! empty( $row['ticket_id'] ) ) {
				/* translators: %d: support ticket number */
				$messages[] = sprintf( __( 'A copy of a help assistant conversation stays in support ticket #%d; erase it from the ticket if needed.', 'doublescale' ), (int) $row['ticket_id'] );
			}
			ConversationStore::delete( $id );
		}

		return array(
			'items_removed'  => array() !== $ids,
			'items_retained' => array() !== $messages,
			'messages'       => $messages,
			'done'           => count( $ids ) < self::PAGE,
		);
	}

	/**
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return int[]
	 */
	private static function conversation_ids( string $email, int $page ): array {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}

		global $wpdb;
		$conv     = ConversationStore::conversations();
		$user     = get_user_by( 'email', $email );
		$contacts = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}doublescale_contacts WHERE email = %s", $email ) );

		$where  = array( 'visitor_email = %s' );
		$params = array( $email );
		if ( $user ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $user->ID;
		}
		foreach ( array_map( 'intval', (array) $contacts ) as $contact ) {
			$where[]  = 'contact_id = %d';
			$params[] = $contact;
		}
		$params[] = self::PAGE;
		$params[] = ( max( 1, $page ) - 1 ) * self::PAGE;

		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$conv} WHERE channel <> 'admin' AND ( " . implode( ' OR ', $where ) . ' ) ORDER BY id ASC LIMIT %d OFFSET %d', $params ) )
		);
	}
}
