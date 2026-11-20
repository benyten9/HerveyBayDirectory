<?php
/**
 * What the client has seen, for the team: a document they opened, the team's
 * ticket replies they read, a shared file they opened.
 *
 * Only the client counts. Public document and file links send no nonce, so
 * WordPress sees nobody; the login cookie still tells a teammate checking a
 * link apart from the client, with the portal's own rule for "team"
 * ({@see Permissions::should_block_customer_portal()}). The first time is
 * kept: "seen" answers "has the client seen it, and since when".
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Core\Models\AttachmentModel;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Activities\Models\ActivityModel;

/**
 * ClientSeen.
 */
final class ClientSeen {

	/** Activity data key: when the client first saw it. */
	public const SEEN_KEY = 'client_seen_at';

	/** Attachment meta key: when the client first opened the file. */
	public const OPENED_KEY = 'client_opened_at';

	/**
	 * Whoever is looking is someone of the team (signed in, or signed in by
	 * cookie on a public link), so it is not the client seeing it.
	 *
	 * @return bool
	 */
	public static function is_team_viewer(): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			$user_id = (int) wp_validate_auth_cookie( '', 'logged_in' );
		}
		return $user_id > 0 && Permissions::should_block_customer_portal( $user_id );
	}

	/**
	 * The client opened a ticket conversation: the team's replies in it are
	 * seen.
	 *
	 * @param iterable<ActivityModel> $activities     The conversation.
	 * @param string                  $customer_email The ticket's customer.
	 * @return void
	 */
	public static function ticket_replies_seen( iterable $activities, string $customer_email ): void {
		if ( self::is_team_viewer() ) {
			return;
		}
		$customer = strtolower( trim( $customer_email ) );
		foreach ( $activities as $activity ) {
			if ( ActivityTypes::SUPPORT_REPLY !== $activity->activity_type || (int) $activity->user_id <= 0 ) {
				continue;
			}
			// A reply from the customer's own account (or, on a company ticket,
			// the colleague reading it) is theirs.
			if ( (int) $activity->user_id === get_current_user_id() ) {
				continue;
			}
			$author = get_userdata( (int) $activity->user_id );
			if ( $author && '' !== $customer && strtolower( (string) $author->user_email ) === $customer ) {
				continue;
			}
			self::activity_seen( $activity, 'portal' );
		}
	}

	/**
	 * Mark one activity seen by the client (the first time only), and where
	 * (a ticket reply's chat or portal, for the ticket screen).
	 *
	 * One UPDATE that sets the time only while it is missing: never undoes
	 * another step written to the same reply at that moment
	 * ({@see \DoubleScale\Modules\Support\Services\ReplyDelivery}).
	 * Straight to the row: saving the model would stamp `updated_at` and fire
	 * the "activity updated" hooks for what is only a read.
	 *
	 * @param ActivityModel $activity Activity.
	 * @param string        $via      chat | portal | '' (not said).
	 * @return void
	 */
	public static function activity_seen( ActivityModel $activity, string $via = '' ): void {
		$data = is_array( $activity->data ) ? $activity->data : array();
		if ( ! empty( $data[ self::SEEN_KEY ] ) ) {
			return;
		}
		$id  = (int) $activity->id;
		$now = current_time( 'mysql', true ); // UTC, like the activity's own times.
		if ( ! \DoubleScale\Modules\Support\Services\ReplyDelivery::stamp( $id, self::SEEN_KEY, $now ) ) {
			return;
		}
		$data[ self::SEEN_KEY ] = $now;
		if ( '' !== $via ) {
			\DoubleScale\Modules\Support\Services\ReplyDelivery::stamp( $id, \DoubleScale\Modules\Support\Services\ReplyDelivery::SEEN_VIA, $via );
			$data[ \DoubleScale\Modules\Support\Services\ReplyDelivery::SEEN_VIA ] = $via;
		}
		$activity->setRawAttributes( array_merge( $activity->getAttributes(), array( 'data' => wp_json_encode( $data ) ) ), true );
	}

	/**
	 * The client opened a file the team shared with them.
	 *
	 * @param AttachmentModel $attachment File.
	 * @return void
	 */
	public static function file_opened( AttachmentModel $attachment ): void {
		$meta = is_array( $attachment->meta ) ? $attachment->meta : array();
		if ( empty( $meta['client_visible'] ) || ! empty( $meta[ self::OPENED_KEY ] ) || self::is_team_viewer() ) {
			return;
		}
		$meta[ self::OPENED_KEY ] = current_time( 'mysql', true ); // UTC, like client_visible_at.

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'doublescale_attachments',
			array( 'meta' => wp_json_encode( $meta ) ),
			array( 'id' => (int) $attachment->id )
		);
	}
}
