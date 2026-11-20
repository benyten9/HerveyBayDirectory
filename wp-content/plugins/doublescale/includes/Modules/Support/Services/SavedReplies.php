<?php
/**
 * Saved replies: who sees, changes and uses them, and how a reply is filled
 * in for a ticket.
 *
 * - Anyone who works tickets (doublescale_view_support) saves replies for
 *   themselves. Shared replies (the whole team) are added and changed by
 *   whoever may see every ticket (support manager, administrator).
 * - Their list: their own and the shared ones, most used first.
 * - Merge tags: the platform's own ({{group:slug}}, with an optional
 *   {{group:slug|fallback}}), filled by MergeTagsManager like everywhere.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\MergeTags\MergeTagsManager;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Support\Migrations\SupportSavedRepliesTable;
use DoubleScale\Modules\Support\Models\SavedReplyModel;
use DoubleScale\Modules\Support\Models\TicketModel;

/**
 * SavedReplies class.
 */
final class SavedReplies {

	/**
	 * Longest reply kept, in bytes of HTML: a long answer is a few thousand.
	 */
	public const MAX_CONTENT = 20000;

	/** Most replies listed at once. */
	private const LIST_MAX = 200;

	/**
	 * @return void
	 */
	public static function ensure_table(): void {
		SupportSavedRepliesTable::ensure();
	}

	/**
	 * @param int $user_id User.
	 * @return bool May add and change shared replies.
	 */
	public static function can_share( int $user_id ): bool {
		return Permissions::can_manage_all_tickets( $user_id );
	}

	/**
	 * @param SavedReplyModel $reply   Reply.
	 * @param int             $user_id User.
	 * @return bool May use (see and insert) it.
	 */
	public static function can_use( SavedReplyModel $reply, int $user_id ): bool {
		return (bool) $reply->shared || (int) $reply->user_id === $user_id;
	}

	/**
	 * @param SavedReplyModel $reply   Reply.
	 * @param int             $user_id User.
	 * @return bool May change or delete it.
	 */
	public static function can_change( SavedReplyModel $reply, int $user_id ): bool {
		return $reply->shared ? self::can_share( $user_id ) : (int) $reply->user_id === $user_id;
	}

	/**
	 * The user's replies and the shared ones, most used first.
	 *
	 * @param int    $user_id User.
	 * @param string $search  Words in the title or text, '' for all.
	 * @return SavedReplyModel[]
	 */
	public static function list_for( int $user_id, string $search = '' ): array {
		$query = SavedReplyModel::query()->where(
			static function ( $where ) use ( $user_id ) {
				$where->where( 'shared', 1 )->orWhere( 'user_id', $user_id );
			}
		);
		$search = trim( $search );
		if ( '' !== $search ) {
			$like = '%' . $search . '%';
			$query->where(
				static function ( $where ) use ( $like ) {
					$where->where( 'title', 'like', $like )->orWhere( 'content', 'like', $like );
				}
			);
		}

		return $query->orderBy( 'use_count', 'desc' )->orderBy( 'title', 'asc' )->limit( self::LIST_MAX )->get()->all();
	}

	/**
	 * The reply's text for a ticket: the platform's merge tags filled, as in
	 * emails, campaigns and automations (MergeTagsManager). The context is
	 * the one an automation carries: the ticket's contact for the contact
	 * group ({{contact:first_name}}, {{contact:city|fallback}}) and the
	 * ticket for the Helpdesk group ({{support:ticket_id}}, Pro).
	 *
	 * @param SavedReplyModel $reply   Reply.
	 * @param TicketModel     $ticket  Ticket.
	 * @param int             $user_id Who uses it.
	 * @return string HTML.
	 */
	public static function fill( SavedReplyModel $reply, TicketModel $ticket, int $user_id ): string {
		unset( $user_id );
		if ( ! class_exists( MergeTagsManager::class ) || ! class_exists( AutomationContactModel::class ) ) {
			return (string) $reply->content;
		}
		$context             = new AutomationContactModel();
		$context->contact_id = (int) $ticket->contact_id;
		$context->data       = array( 'ticket_id' => (int) $ticket->id );
		$context->setRelation( 'contact', $ticket->contact );

		$tags = MergeTagsManager::instance();

		// One tag at a time, each value as text: a contact's name or a ticket's
		// subject is the customer's own words, never markup in the reply. (The
		// tags a saved reply offers, contact, business and Helpdesk, are all
		// plain text; the ones that build HTML belong to other triggers.)
		return (string) preg_replace_callback(
			'/{{(.*?):(.*?)}}/',
			static function ( array $match ) use ( $tags, $context ): string {
				return esc_html( (string) $tags->process_merge_tags( $match[0], $context ) );
			},
			(string) $reply->content
		);
	}

	/**
	 * Count one more use.
	 *
	 * @param SavedReplyModel $reply Reply.
	 * @return void
	 */
	public static function used( SavedReplyModel $reply ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}doublescale_support_saved_replies SET use_count = use_count + 1 WHERE id = %d", (int) $reply->id ) );
	}

	/**
	 * A reply as the screens read it.
	 *
	 * @param SavedReplyModel $reply   Reply.
	 * @param int             $user_id Who reads.
	 * @return array<string, mixed>
	 */
	public static function shape( SavedReplyModel $reply, int $user_id ): array {
		$owner = $reply->user_id ? get_userdata( (int) $reply->user_id ) : false;
		return array(
			'id'         => (int) $reply->id,
			'title'      => (string) $reply->title,
			'content'    => (string) $reply->content,
			'shared'     => (bool) $reply->shared,
			'mine'       => (int) $reply->user_id === $user_id,
			'owner'      => $owner ? (string) $owner->display_name : '',
			'use_count'  => (int) $reply->use_count,
			'can_change' => self::can_change( $reply, $user_id ),
		);
	}
}
