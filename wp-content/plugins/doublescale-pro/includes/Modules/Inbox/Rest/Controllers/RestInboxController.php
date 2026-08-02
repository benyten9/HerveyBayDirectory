<?php
/**
 * RestInboxController class.
 *
 * Provides a centralized inbox view for inbound messages (email, Sms, WhatsApp).
 * Scopes messages to prevent users seeing other users' personal mailbox emails:
 * - Shared messages (user_id IS NULL) are visible to all CRM users
 * - Per-user mailbox emails (user_id = X) are only visible to user X
 * - Sms/Whatsapp are always shared (user_id is always NULL)
 *
 * @since 1.7.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Rest\Controllers;

use DoubleScale\Modules\Activities\Models\ActivityModel;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestInboxController class.
 *
 * @since 1.7.0
 */
class RestInboxController extends WP_REST_Controller {

	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'doublescale/v1';

	/**
	 * REST Base
	 *
	 * @since 1.7.0
	 *
	 * @var string
	 */
	protected $rest_base = 'inbox';

	/**
	 * All inbound message activity types.
	 *
	 * @var array
	 */
	private const INBOUND_TYPES = array( 'email_received', 'sms_received', 'whatsapp_received' );

	/**
	 * Map of channel filter values to activity types.
	 *
	 * @var array
	 */
	private const CHANNEL_TYPE_MAP = array(
		'email'    => 'email_received',
		'sms'      => 'sms_received',
		'whatsapp' => 'whatsapp_received',
	);

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.7.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// GET /inbox - Get paginated inbound messages.
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_inbox' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 15,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'channel'  => array(
							'default'           => null,
							'type'              => 'string',
							'enum'              => array( 'email', 'sms', 'whatsapp' ),
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// GET /inbox/unread-count - Get unread count (lightweight for heartbeat).
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/unread-count",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_unread_count' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		// POST /inbox/mark-read - Mark all inbox items as read.
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/mark-read",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_as_read' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission check for inbox endpoints
	 *
	 * @since 1.7.0
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'doublescale_access' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access the inbox.', 'doublescale'),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Get the privacy-scoped base query for inbox items.
	 *
	 * Ensures users only see:
	 * - Shared messages (user_id IS NULL) — visible to all CRM users
	 * - Their own personal mailbox emails (user_id = current user) — private
	 * Sms/Whatsapp are always shared (user_id is always NULL), so they
	 * always pass the whereNull branch.
	 *
	 * @since 1.7.0
	 *
	 * @param int         $user_id Current WordPress user ID.
	 * @param string|null $channel Optional channel filter ('email', 'sms', 'whatsapp').
	 *
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	private function get_inbox_base_query( $user_id, $channel = null ) {
		if ( $channel && isset( self::CHANNEL_TYPE_MAP[ $channel ] ) ) {
			$types = array( self::CHANNEL_TYPE_MAP[ $channel ] );
		} else {
			$types = self::INBOUND_TYPES;
		}

		return ActivityModel::query()
			->byType( $types )
			->where(
				function ( $q ) use ( $user_id ) {
					$q->whereNull( 'user_id' )
						->orWhere( 'user_id', $user_id );
				}
			);
	}

	/**
	 * Extract a clean text preview from email HTML body.
	 *
	 * Strips quoted reply content (blockquotes, Gmail/Outlook quote wrappers,
	 * "On … wrote:" attributions), style/head blocks, and HTML tags to produce
	 * a short plain-text preview of the sender's actual message.
	 *
	 * @since 1.7.0
	 *
	 * @param string $html Raw email body HTML.
	 *
	 * @return string Plain text preview, truncated to 20 words.
	 */
	private function extract_body_preview( $html ) {
		if ( empty( $html ) ) {
			return '';
		}

		// Remove <style> and <head> blocks.
		$text = preg_replace( '#<(style|head)\b[^>]*>.*?</\1>#is', '', $html );

		// Truncate at quoted-reply markers (everything from the marker onward is the previous thread).
		$text = preg_replace( '#<blockquote\b.*$#is', '', $text );
		$text = preg_replace( '#<div\s[^>]*class="[^"]*gmail_quote[^"]*".*$#is', '', $text );
		$text = preg_replace( '#<div\s[^>]*id="(appendonsend|divRplyFwdMsg)"[^>]*>.*$#is', '', $text );

		// Replace <br> and block-level closing tags with spaces so words don't concatenate.
		$text = preg_replace( '#<br\s*/?>|</(?:p|div|li|tr|td|h[1-6])>#i', ' ', $text );

		// Strip remaining HTML tags.
		$text = wp_strip_all_tags( $text );

		// Decode HTML entities (&lt; &amp; etc.).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Remove "On <date/name> wrote:" attributions and everything after.
		$text = preg_replace( '/\s*On\s+.{10,300}\s+wrote:\s*.*/is', '', $text );

		// Remove "---------- Forwarded message ----------" and everything after.
		$text = preg_replace( '/\s*-{3,}\s*Forwarded message\s*-{3,}.*/is', '', $text );

		// Remove text-mode quoted lines ("> …").
		$text = preg_replace( '/^>+\s*.*/m', '', $text );

		// Collapse whitespace.
		$text = preg_replace( '/\s+/', ' ', trim( $text ) );

		return wp_trim_words( $text, 20, "\u{2026}" );
	}

	/**
	 * Format a single inbox item for the Api response.
	 *
	 * Normalizes fields across email, Sms, and WhatsApp channels:
	 * - Email: subject + HTML body preview + from_email
	 * - Sms/Whatsapp: no subject, plain text body, phone number as "from"
	 *
	 * @since 1.7.0
	 *
	 * @param ActivityModel $activity  Activity model instance.
	 * @param string         $last_read User's last-read timestamp (empty if never read).
	 *
	 * @return array|null Formatted item, or null if contact was deleted.
	 */
	private function format_inbox_item( $activity, $last_read ) {
		$contact = $activity->contact;

		// Skip items where the contact has been deleted.
		if ( ! $contact ) {
			return null;
		}

		$data = $activity->data;

		// Derive channel from activity_type: 'email_received' → 'email'.
		$channel = str_replace( '_received', '', $activity->activity_type );

		// Channel-specific field normalization.
		if ( 'email' === $channel ) {
			$subject      = $data['subject'] ?? '';
			$body_preview = $this->extract_body_preview( $data['body'] ?? '' );
			$from         = $data['from_email'] ?? '';
		} else {
			// Sms / WhatsApp: no subject, plain text body, phone number as "from".
			$subject      = '';
			$body_preview = wp_trim_words( wp_strip_all_tags( $data['body'] ?? '' ), 20, "\u{2026}" );
			$from         = $data['from'] ?? '';
		}

		return array(
			'id'            => $activity->id,
			'channel'       => $channel,
			'contact_id'    => $activity->contact_id,
			'contact_name'  => trim( ( $contact->first_name ?? '' ) . ' ' . ( $contact->last_name ?? '' ) ),
			'contact_email' => $contact->email,
			'avatar_url'    => $contact->avatar_url,
			'subject'       => $subject,
			'body_preview'  => $body_preview,
			'from'          => $from,
			'is_unread'     => ! $last_read || $activity->created_at > $last_read,
			'created_at'    => $activity->created_at,
			'created_at_ts' => $activity->created_at ? strtotime( $activity->created_at ) : null,
		);
	}

	/**
	 * Get paginated inbox items for current user
	 *
	 * @since 1.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_inbox( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$page     = $request->get_param( 'page' );
		$per_page = max( 1, min( $request->get_param( 'per_page' ), 50 ) );
		$channel  = $request->get_param( 'channel' );

		$last_read = get_user_meta( $user_id, '_doublescale_inbox_last_read_at', true );

		// Count total.
		$total = $this->get_inbox_base_query( $user_id, $channel )->count();

		// Count unread.
		$unread_query = $this->get_inbox_base_query( $user_id, $channel );
		if ( $last_read ) {
			$unread_query->where( 'created_at', '>', $last_read );
		}
		$unread_count = $unread_query->count();

		// Fetch page — ids first, then rows. Sorting SELECT * pulls full JSON
		// bodies into the sort buffer and can OOM on modest sort_buffer_size.
		$activities = $this->fetch_inbox_page_activities( $user_id, $channel, $page, $per_page );

		$items = array();
		foreach ( $activities as $activity ) {
			$item = $this->format_inbox_item( $activity, $last_read );
			if ( $item ) {
				$items[] = $item;
			}
		}

		return new WP_REST_Response(
			array(
				'items'        => $items,
				'total'        => $total,
				'page'         => $page,
				'per_page'     => $per_page,
				'total_pages'  => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
				'unread_count' => $unread_count,
			),
			200
			);
	}

	/**
	 * Load one inbox page without sorting wide activity rows.
	 *
	 * Inbound email bodies live in the JSON `data` column; ORDER BY created_at on
	 * SELECT * can exceed MySQL sort_buffer_size. Fetch lean ids first, then load
	 * full models (with contact) for that page only.
	 *
	 * @param int         $user_id  Current WordPress user ID.
	 * @param string|null $channel  Optional channel filter.
	 * @param int         $page     1-based page number.
	 * @param int         $per_page Page size.
	 *
	 * @return \Illuminate\Support\Collection<int, ActivityModel>
	 */
	private function fetch_inbox_page_activities( $user_id, $channel, $page, $per_page ) {
		$page_ids = $this->get_inbox_base_query( $user_id, $channel )
			->select( 'id' )
			->orderBy( 'created_at', 'desc' )
			->skip( ( $page - 1 ) * $per_page )
			->take( $per_page )
			->pluck( 'id' )
			->map( 'intval' )
			->filter()
			->values()
			->all();

		if ( empty( $page_ids ) ) {
			return collect();
		}

		$id_list = implode( ',', $page_ids );

		return ActivityModel::query()
			->with( 'contact' )
			->whereIn( 'id', $page_ids )
			->orderByRaw( "FIELD(id, {$id_list})" )
			->get();
	}

	/**
	 * Get unread count for current user
	 *
	 * @since 1.7.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_unread_count() {
		$user_id   = get_current_user_id();
		$last_read = get_user_meta( $user_id, '_doublescale_inbox_last_read_at', true );

		$query = $this->get_inbox_base_query( $user_id );
		if ( $last_read ) {
			$query->where( 'created_at', '>', $last_read );
		}

		return new WP_REST_Response(
			array(
				'unread_count' => $query->count(),
			),
			200
		);
	}

	/**
	 * Mark all inbox items as read
	 *
	 * Updates the user's "last read" watermark to the current UTC time.
	 * All inbound messages created before this timestamp are considered read.
	 * Single watermark applies across all channels (email, Sms, WhatsApp).
	 *
	 * @since 1.7.0
	 *
	 * @return WP_REST_Response
	 */
	public function mark_as_read() {
		$user_id = get_current_user_id();

		update_user_meta( $user_id, '_doublescale_inbox_last_read_at', current_time( 'mysql', true ) );

		return new WP_REST_Response(
			array(
				'success' => true,
			),
			200
		);
	}
}
