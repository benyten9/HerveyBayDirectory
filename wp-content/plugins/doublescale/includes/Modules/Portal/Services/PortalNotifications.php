<?php
/**
 * The client portal's bell: what a client is told inside the portal.
 *
 * One row per thing the team did for the client — sent them an invoice,
 * proposal, contract or credit note, replied on a ticket, and (from Pro) wrote
 * to them on a project task or shared a file. A row stays unread until the
 * client opens it or the page it points to, and is never listed while its
 * area is hidden from them (the row is kept for when it comes back).
 *
 * Email: documents and ticket replies are already emailed by the team, so
 * their rows send nothing. Team updates follow the client's choice
 * ({@see PortalEmailPreferences}): at once, in one daily summary, or not.
 *
 * Add-ons and Pro add rows with {@see self::add()} (or the
 * `doublescale_portal_notify` action).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Emails\Emails;

/**
 * PortalNotifications.
 */
final class PortalNotifications {

	/** Things the team sent on purpose: in the bell only. */
	public const ACTIVITY = 'activity';

	/** Team comments and shared files: in the bell, emailed as the client chose. */
	public const TEAM_UPDATES = 'team_updates';

	/** Rows a list returns at most. */
	private const PAGE_SIZE = 30;

	/** Read rows older than this go. */
	private const KEEP_READ_DAYS = 90;

	/**
	 * Wire the team's events and the daily summary.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_sales_invoice_sent', array( self::class, 'on_invoice_sent' ) );
		add_action( 'doublescale_sales_proposal_sent', array( self::class, 'on_proposal_sent' ) );
		add_action( 'doublescale_sales_contract_sent', array( self::class, 'on_contract_sent' ) );
		add_action( 'doublescale_sales_credit_note_sent', array( self::class, 'on_credit_note_sent' ) );
		add_action( 'doublescale_support_reply_created', array( self::class, 'on_ticket_reply' ), 10, 2 );
		add_action( 'doublescale_portal_notify', array( self::class, 'add' ), 10, 2 );
		add_action( 'doublescale_document_client_changed', array( self::class, 'forget_document' ), 10, 3 );
	}

	/**
	 * A document moved to another client: the first client's notifications
	 * about it go, from the bell and from the summary still to be sent.
	 *
	 * @param string $segment    Route segment (invoice, proposal, contract, credit-note).
	 * @param int    $contact_id The client who had it.
	 * @param string $hash       Its old link.
	 * @return void
	 */
	public static function forget_document( string $segment, int $contact_id, string $hash ): void {
		$route = self::clean_route( '/documents/' . $segment . '/' . $hash );
		if ( $contact_id <= 0 || '' === $route ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'contact_id' => $contact_id,
				'route'      => $route,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Tell a client something in their portal.
	 *
	 * @param int                  $contact_id Contact.
	 * @param array<string, mixed> $note       category, type, area (portal feature key), title, body, route.
	 * @return int Row id, 0 when nothing was stored (no portal account, no title).
	 */
	public static function add( int $contact_id, array $note ): int {
		// Portal turned off: no bell to ring, and no update emails to the client.
		if ( function_exists( 'doublescale_client_portal_enabled' ) && ! doublescale_client_portal_enabled() ) {
			return 0;
		}
		$title = trim( wp_strip_all_tags( (string) ( $note['title'] ?? '' ) ) );
		if ( $contact_id <= 0 || '' === $title ) {
			return 0;
		}
		$contact = ContactModel::find( $contact_id );
		// A contact who cannot log in has no bell to ring.
		if ( ! $contact || ! PortalIdentity::user_for_contact( $contact ) ) {
			return 0;
		}

		global $wpdb;
		$category = self::TEAM_UPDATES === ( $note['category'] ?? '' ) ? self::TEAM_UPDATES : self::ACTIVITY;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'contact_id' => $contact_id,
				'category'   => $category,
				'type'       => sanitize_key( (string) ( $note['type'] ?? 'update' ) ),
				'area'       => sanitize_key( (string) ( $note['area'] ?? '' ) ),
				'title'      => mb_substr( $title, 0, 255 ),
				'body'       => mb_substr( trim( wp_strip_all_tags( (string) ( $note['body'] ?? '' ) ) ), 0, 1000 ),
				'route'      => self::clean_route( (string) ( $note['route'] ?? '' ) ),
				'is_read'    => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$id = (int) $wpdb->insert_id;

		if ( $id ) {
			/**
			 * A notification was added to a client's bell (Pro's real-time relay
			 * tells their open portal to show it).
			 *
			 * @param int $id         Notification id.
			 * @param int $contact_id Client.
			 */
			do_action( 'doublescale_portal_notification_added', $id, $contact_id );
		}

		if ( $id && self::TEAM_UPDATES === $category && PortalEmailPreferences::EVERY === PortalEmailPreferences::mode( $contact_id, $category ) ) {
			$row = self::find( $id );
			// Like the bell and the summary: nothing about an area hidden from the client.
			if ( $row && ! in_array( (string) $row['area'], self::hidden_areas( $contact ), true ) && self::email( $contact, array( $row ), false ) ) {
				self::mark_emailed( array( $id ) );
			}
		}

		return $id;
	}

	/**
	 * The client's notifications, newest first, without hidden areas.
	 *
	 * @param ContactModel $contact Client.
	 * @return array{data: array<int, array<string, mixed>>, unread: int}
	 */
	public static function list_for( ContactModel $contact ): array {
		global $wpdb;
		$hidden = self::hidden_areas( $contact );
		$where  = self::visible_where( (int) $contact->id, $hidden );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM " . self::table() . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT " . self::PAGE_SIZE, ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE {$where} AND is_read = 0" );

		return array(
			'data'   => array_map( array( self::class, 'shape' ), (array) $rows ),
			'unread' => $unread,
		);
	}

	/**
	 * Mark the client's notifications read: some by id, those pointing to a
	 * page they opened, or all of them.
	 *
	 * @param ContactModel         $contact Client.
	 * @param array<string, mixed> $which   ids | route | all.
	 * @return void
	 */
	public static function mark_read( ContactModel $contact, array $which ): void {
		global $wpdb;
		$table = self::table();
		$cid   = (int) $contact->id;

		if ( ! empty( $which['all'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE contact_id = %d AND is_read = 0", $cid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		if ( ! empty( $which['route'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE contact_id = %d AND is_read = 0 AND route = %s", $cid, self::clean_route( (string) $which['route'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $which['ids'] ?? array() ) ) ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET is_read = 1 WHERE contact_id = %d AND id IN ({$in})", $cid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Daily: one email per client who chose a summary, with the team updates
	 * they have not read or been emailed yet; then old read rows go.
	 *
	 * @return void
	 */
	public static function send_daily_summaries(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$contacts = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT contact_id FROM {$table} WHERE category = %s AND is_read = 0 AND emailed_at IS NULL", self::TEAM_UPDATES ) );

		foreach ( $contacts as $contact_id ) {
			$contact_id = (int) $contact_id;
			if ( PortalEmailPreferences::DAILY !== PortalEmailPreferences::mode( $contact_id, self::TEAM_UPDATES ) ) {
				continue;
			}
			$contact = ContactModel::find( $contact_id );
			if ( ! $contact ) {
				continue;
			}
			$hidden = self::hidden_areas( $contact );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d AND category = %s AND is_read = 0 AND emailed_at IS NULL ORDER BY created_at ASC, id ASC", $contact_id, self::TEAM_UPDATES ), ARRAY_A );
			$rows = array_values( array_filter( (array) $rows, static fn( $row ) => ! in_array( (string) $row['area'], $hidden, true ) ) );
			if ( $rows && self::email( $contact, $rows, true ) ) {
				self::mark_emailed( array_map( 'intval', array_column( $rows, 'id' ) ) );
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE is_read = 1 AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_READ_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* -------- The team's events -------- */

	/**
	 * @param object $invoice Invoice.
	 * @return void
	 */
	public static function on_invoice_sent( $invoice ): void {
		self::document( $invoice, 'invoice_sent', 'documents', 'invoice', (string) ( $invoice->invoice_number ?? '' ), __( 'New invoice: %s', 'doublescale' ) );
	}

	/**
	 * @param object $proposal Proposal.
	 * @return void
	 */
	public static function on_proposal_sent( $proposal ): void {
		$name = (string) ( $proposal->subject ?? '' );
		$name = '' !== $name ? $name : (string) ( $proposal->proposal_number ?? '' );
		self::document( $proposal, 'proposal_sent', 'documents', 'proposal', $name, __( 'New proposal: %s', 'doublescale' ) );
	}

	/**
	 * @param object $contract Contract.
	 * @return void
	 */
	public static function on_contract_sent( $contract ): void {
		// A contract is named by its subject (it has no title column).
		$name = trim( (string) ( $contract->subject ?? '' ) );
		$name = '' !== $name ? $name : (string) ( $contract->contract_number ?? '' );
		self::document( $contract, 'contract_sent', 'contracts', 'contract', $name, __( 'Contract to sign: %s', 'doublescale' ) );
	}

	/**
	 * @param object $credit_note Credit note.
	 * @return void
	 */
	public static function on_credit_note_sent( $credit_note ): void {
		self::document( $credit_note, 'credit_note_sent', 'credit_notes', 'credit-note', (string) ( $credit_note->credit_note_number ?? '' ), __( 'New credit note: %s', 'doublescale' ) );
	}

	/**
	 * A team reply on a ticket (the client's own replies carry no user).
	 *
	 * @param object $activity Reply.
	 * @param object $ticket   Ticket.
	 * @return void
	 */
	public static function on_ticket_reply( $activity, $ticket ): void {
		if ( ! is_object( $activity ) || empty( $activity->user_id ) || ! is_object( $ticket ) ) {
			return;
		}
		self::add_for(
			$ticket,
			array(
				'type'  => 'ticket_reply',
				'area'  => 'requests',
				/* translators: %s: ticket subject */
				'title' => sprintf( __( 'New reply on "%s"', 'doublescale' ), (string) ( $ticket->title ?? '' ) ),
				'route' => '/tickets/' . (int) ( $ticket->id ?? 0 ),
			)
		);
	}

	/**
	 * Notify a record's client, and the colleagues who follow it when the
	 * record is their company's (DoubleScale Pro answers the filter).
	 *
	 * @param object               $record Ticket or document.
	 * @param array<string, mixed> $note   Notification.
	 * @return void
	 */
	private static function add_for( $record, array $note ): void {
		$contact_id = (int) ( $record->contact_id ?? 0 );
		$ids        = array( $contact_id );
		if ( ! empty( $record->company_id ) ) {
			/**
			 * Filters who gets a portal notification about a company record.
			 *
			 * @param int[]                $ids    Contact ids (the record's contact first).
			 * @param string               $type   Notification type, e.g. ticket_reply, invoice_sent.
			 * @param object               $record Ticket or document.
			 */
			$ids = (array) apply_filters( 'doublescale_portal_notification_recipients', $ids, (string) $note['type'], $record );
		}
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 ) {
				self::add( $id, $note );
			}
		}
	}

	/* -------- Inside -------- */

	/**
	 * @param object $doc     Document.
	 * @param string $type    Notification type.
	 * @param string $area    Portal feature key.
	 * @param string $segment Route segment.
	 * @param string $name    Number or subject.
	 * @param string $format  Title with %s.
	 * @return void
	 */
	private static function document( $doc, string $type, string $area, string $segment, string $name, string $format ): void {
		if ( ! is_object( $doc ) || empty( $doc->hash ) ) {
			return;
		}
		self::add_for(
			$doc,
			array(
				'type'  => $type,
				'area'  => $area,
				'title' => sprintf( $format, $name ),
				'route' => '/documents/' . $segment . '/' . (string) $doc->hash,
			)
		);
	}

	/**
	 * One email: a single update, or the day's summary.
	 *
	 * @param ContactModel                     $contact Client.
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param bool                             $summary Daily summary.
	 * @return bool Sent.
	 */
	private static function email( ContactModel $contact, array $rows, bool $summary ): bool {
		$to = (string) $contact->email;
		if ( ! is_email( $to ) ) {
			return false;
		}
		$settings = PortalUrl::get_route_url( 'notifications' );

		$items = '';
		foreach ( $rows as $row ) {
			$url    = '' !== (string) $row['route'] ? PortalUrl::get_route_url( ltrim( (string) $row['route'], '/' ) ) : '';
			$title  = esc_html( (string) $row['title'] );
			$items .= '<p><strong>' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . $title . '</a>' : $title ) . '</strong>'
				. ( '' !== (string) $row['body'] ? '<br />' . nl2br( esc_html( (string) $row['body'] ) ) : '' ) . '</p>';
		}

		$subject = $summary
			/* translators: %d: number of updates */
			? sprintf( _n( '%d update on your projects', '%d updates on your projects', count( $rows ), 'doublescale' ), count( $rows ) )
			: (string) $rows[0]['title'];
		$body    = ( $summary ? '<p>' . esc_html__( 'Here is what the team shared with you today:', 'doublescale' ) . '</p>' : '' )
			. $items
			. ( '' !== $settings
				? '<p style="font-size:12px;color:#6b6c76;"><a href="' . esc_url( $settings ) . '">' . esc_html__( 'Change how we email you', 'doublescale' ) . '</a></p>'
				: '' );

		try {
			return (bool) ( new Emails() )->send( $to, $subject, $body );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error(
					'Portal notification email failed',
					array(
						'contact_id' => (int) $contact->id,
						'error'      => $e->getMessage(),
					)
				);
			}
			return false;
		}
	}

	/**
	 * Areas hidden from the client now.
	 *
	 * @param ContactModel $contact Client.
	 * @return string[]
	 */
	private static function hidden_areas( ContactModel $contact ): array {
		$hidden = array();
		foreach ( PortalAccess::for_contact( $contact ) as $key => $visible ) {
			if ( ! $visible ) {
				$hidden[] = (string) $key;
			}
		}
		return $hidden;
	}

	/**
	 * @param int      $contact_id Contact.
	 * @param string[] $hidden     Hidden areas.
	 * @return string SQL condition.
	 */
	private static function visible_where( int $contact_id, array $hidden ): string {
		global $wpdb;
		$where = $wpdb->prepare( 'contact_id = %d', $contact_id );
		if ( $hidden ) {
			$list   = implode( ',', array_map( static fn( $a ) => $wpdb->prepare( '%s', $a ), $hidden ) );
			$where .= " AND area NOT IN ({$list})";
		}
		return $where;
	}

	/**
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'category'   => (string) $row['category'],
			'title'      => (string) $row['title'],
			'body'       => (string) $row['body'],
			'route'      => (string) $row['route'],
			'is_read'    => (bool) (int) $row['is_read'],
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	private static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int[] $ids Rows.
	 * @return void
	 */
	private static function mark_emailed( array $ids ): void {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET emailed_at = %s WHERE id IN ({$in})", current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A portal route: "/a/b/12", nothing else.
	 *
	 * @param string $route Route.
	 * @return string
	 */
	private static function clean_route( string $route ): string {
		$route = '/' . ltrim( trim( $route ), '/' );
		return preg_match( '#^/[a-z0-9/_-]*$#i', $route ) ? untrailingslashit( $route ) : '';
	}

	/**
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'doublescale_portal_notifications';
	}
}
