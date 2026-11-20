<?php
/**
 * Who wrote a ticket message, as the customer reads it.
 *
 * A reply with no team author used to count as the customer's own. That holds
 * for what they post from the portal or mail from their own address, not for
 * an email from someone else on the thread (a CC'd colleague, a teammate
 * answering from their own mailbox) or a message the system posted (the
 * auto-close note). One rule for every page that shows the customer a thread:
 * the portal ticket, the guest ticket page and the portal's recent activity.
 *
 * @package DoubleScale\Modules\Support
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Constants\ActivityTypes;

final class ReplyAuthor {

	/**
	 * Whether a reply with no team author is the customer's own.
	 *
	 * Replies saved before the sender was kept carry no `from_email` and stay
	 * the customer's, as they always were.
	 *
	 * @param array<string, mixed> $data           The reply's activity data.
	 * @param string               $customer_email The ticket's contact email.
	 * @return bool
	 */
	public static function authorless_is_customer( array $data, string $customer_email ): bool {
		if ( ! empty( $data['system'] ) ) {
			return false;
		}
		$from = isset( $data['from_email'] ) ? strtolower( trim( (string) $data['from_email'] ) ) : '';
		if ( '' === $from ) {
			return true;
		}

		return $from === strtolower( trim( $customer_email ) );
	}

	/**
	 * The name shown on a reply with no team author that is not the customer's,
	 * or null for "the support team" (a system message, an unknown sender).
	 *
	 * A teammate who answered from their mailbox shows as on their portal
	 * replies, by their display name, never their address. Anyone else goes by
	 * the name on their email, else the address the customer's thread saw.
	 *
	 * @param array<string, mixed> $data The reply's activity data.
	 * @return string|null
	 */
	public static function sender_name( array $data ): ?string {
		if ( ! empty( $data['system'] ) ) {
			return null;
		}
		$email = isset( $data['from_email'] ) ? trim( (string) $data['from_email'] ) : '';
		if ( '' !== $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user && Permissions::has_support_access( (int) $user->ID ) ) {
				return (string) $user->display_name;
			}
		}
		$name = isset( $data['from_name'] ) ? trim( (string) $data['from_name'] ) : '';
		if ( '' !== $name ) {
			return $name;
		}

		return '' !== $email ? $email : null;
	}

	/**
	 * What the customer may read of a message's data: a reply's words, an
	 * event's kind and the before/after values the thread prints (the agent
	 * who took the ticket by name). Never the email headers, the CC list,
	 * the sender's address or a teammate's id.
	 *
	 * @param string               $activity_type Activity type.
	 * @param array<string, mixed> $data          Stored activity data.
	 * @return array<string, mixed>
	 */
	public static function public_data( string $activity_type, array $data ): array {
		if ( ActivityTypes::SUPPORT_REPLY === $activity_type ) {
			return array_intersect_key( $data, array_flip( array( 'content', 'source' ) ) );
		}

		$key = isset( $data['event_key'] ) ? (string) $data['event_key'] : '';
		$out = '' !== $key ? array( 'event_key' => $key ) : array();
		if ( in_array( $key, array( 'status_changed', 'priority_changed' ), true ) ) {
			$out['from'] = $data['from'] ?? null;
			$out['to']   = $data['to'] ?? null;
		} elseif ( 'agent_changed' === $key ) {
			// Who took the ticket, by the name on their replies (never an id);
			// true when that account is gone, false when unassigned.
			$agent     = ! empty( $data['to'] ) ? get_userdata( (int) $data['to'] ) : false;
			$out['to'] = $agent ? (string) $agent->display_name : ! empty( $data['to'] );
		}

		return $out;
	}
}
