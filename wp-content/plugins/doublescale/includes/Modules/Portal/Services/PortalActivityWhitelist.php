<?php
/**
 * Customer-safe activity whitelist + shaper for the portal timeline.
 *
 * `doublescale_activities` is one table mixing customer-safe rows with
 * internal-only rows (agent notes, call logs, deal internals, logins that carry
 * IPs). The portal timeline MUST whitelist by `activity_type` before exposing
 * anything.
 *
 * NOTE (audited 2026-06-14): the `booking_*` activity types defined in
 * {@see \DoubleScale\Core\Constants\ActivityTypes} are never written to this
 * table — booking lifecycle is projected directly from BookingModel by the
 * timeline controller. The only customer-safe row actually written here (with a
 * `contact_id`) is `support_reply`. This whitelist therefore exists to *drop*
 * the internal rows, not to surface booking data.
 *
 * The default whitelist is intentionally EMPTY (deny-by-default). Each owning
 * module opts its own customer-safe type in from its `boot()` via the
 * `doublescale_portal_timeline_activity_types` filter — Support adds
 * `support_reply` in {@see \DoubleScale\Modules\Support\Module::boot()}. This
 * keeps the timeline in lock-step with the section/summary-card seam: when a
 * module is disabled its `boot()` never runs, so its rows disappear from the
 * timeline too (instead of lingering as orphaned entries that link to a section
 * that no longer exists).
 *
 * @package DoubleScale\Modules\Portal
 */

namespace DoubleScale\Modules\Portal\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Constants\ActivityTypes;
use DoubleScale\Modules\Activities\Models\ActivityModel;

/**
 * PortalActivityWhitelist helper.
 */
final class PortalActivityWhitelist {

	/**
	 * Activity types from `doublescale_activities` that are safe to expose to
	 * the owning contact. Keep this list tight — everything else is internal.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_types(): array {
		/**
		 * Filter the customer-safe activity types shown in the portal timeline.
		 *
		 * Deny-by-default: the base list is EMPTY. Owning modules opt their own
		 * type in from `boot()` (Support adds `support_reply`), so a disabled
		 * module contributes nothing — keeping the timeline consistent with the
		 * section/summary-card gating. Operators can still opt-in extra types
		 * (e.g. `email_sent`) via this filter.
		 *
		 * @param array<int, string> $types Allowed activity_type strings.
		 */
		$types = (array) apply_filters( 'doublescale_portal_timeline_activity_types', array() );

		return array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );
	}

	/**
	 * Whether an activity type is safe to surface in the portal.
	 *
	 * @param string $type Activity type slug.
	 * @return bool
	 */
	public static function is_allowed( string $type ): bool {
		return in_array( $type, self::allowed_types(), true );
	}

	/**
	 * Whether an activity was authored by the contact themselves (vs a support agent).
	 *
	 * The author is the contact when EITHER:
	 *   - the row carries no WP `user_id` — portal/email replies are deliberately
	 *     written NULL ({@see \DoubleScale\Modules\Support\Rest\Controllers\RestPortalController::add_reply}); or
	 *   - the `user_id` is the contact's own WP account — which is what the *opening*
	 *     message of a web-created ticket carries, because TicketService credits the
	 *     logged-in customer (source `web`, no explicit author).
	 *
	 * An agent reply (or an agent filing on the customer's behalf) carries a
	 * different `user_id` and is therefore NOT self. Testing only `empty(user_id)`
	 * mislabels the customer's own opening message as "Support team".
	 *
	 * @param ActivityModel $activity        Activity row.
	 * @param int|null      $contact_user_id The contact's own WP account id, or null.
	 * @return bool
	 */
	public static function is_self_authored( ActivityModel $activity, ?int $contact_user_id ): bool {
		$uid = (int) $activity->user_id;
		if ( 0 === $uid ) {
			return true;
		}
		return null !== $contact_user_id && $uid === $contact_user_id;
	}

	/**
	 * Shape a whitelisted activity row into a safe timeline item.
	 *
	 * Emits only safe fields and a generic author label — never the agent's
	 * user id / name. Returns null for any row whose type is not whitelisted
	 * (defence-in-depth: the query already filters, this is a second gate).
	 *
	 * @param ActivityModel $activity        Activity row (already scoped to the contact).
	 * @param int|null      $contact_user_id The contact's own WP account id (matched by
	 *                                       email), so a message they authored while
	 *                                       logged in is recognised as "self". Null when
	 *                                       the contact has no WP account.
	 * @return array<string, mixed>|null
	 */
	public static function shape( ActivityModel $activity, ?int $contact_user_id = null ): ?array {
		$type = (string) $activity->activity_type;
		if ( ! self::is_allowed( $type ) ) {
			return null;
		}

		$data    = is_array( $activity->data ) ? $activity->data : array();
		$is_self = self::is_self_authored( $activity, $contact_user_id );

		$item = array(
			'id'      => (int) $activity->id,
			'kind'    => 'activity',
			'type'    => $type,
			'date'    => self::row_date( $activity ),
			'is_self' => $is_self,
			'author'  => $is_self ? __( 'You', 'doublescale' ) : __( 'Support team', 'doublescale' ),
		);

		if ( ActivityTypes::SUPPORT_REPLY === $type ) {
			$item['title']     = __( 'Support reply', 'doublescale' );
			$item['ticket_id'] = isset( $data['ticket_id'] ) ? (int) $data['ticket_id'] : 0;
		} else {
			$item['title'] = ucwords( str_replace( '_', ' ', $type ) );
		}

		return $item;
	}

	/**
	 * Best-effort timestamp for an activity row.
	 *
	 * @param ActivityModel $activity Activity row.
	 * @return string|null
	 */
	private static function row_date( ActivityModel $activity ): ?string {
		if ( ! empty( $activity->activity_date ) ) {
			return (string) $activity->activity_date;
		}
		if ( ! empty( $activity->created_at ) ) {
			return (string) $activity->created_at;
		}

		return null;
	}
}
