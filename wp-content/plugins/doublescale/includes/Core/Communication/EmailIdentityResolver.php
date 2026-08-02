<?php
/**
 * Shared resolver that picks the From / Reply-To identity for outgoing email.
 *
 * Both the Inbox sender and the Booking notifier delegate here so the
 * personal -> shared -> admin fallback chain stays in one place.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core\Communication;

use DoubleScale\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class EmailIdentityResolver {

	/**
	 * Resolve the From / Reply-To identity for an outgoing email.
	 *
	 * Resolution order:
	 *   1. Per-user personal mailbox (when `$host_user_id` has an enabled
	 *      `doublescale_user_email_account`).
	 *   2. Shared/team mailbox (`email_inbound.from_email`).
	 *   3. WordPress admin email.
	 *
	 * @param int|null $host_user_id WP user ID of the calendar host / message
	 *                               sender, or null for system-level sends.
	 *
	 * @return array{from_address:string, from_name:string, reply_to:string}
	 */
	public static function resolve( ?int $host_user_id = null ): array {
		$email_inbound = Settings::get( 'email_inbound', array() );

		if ( $host_user_id ) {
			$account = get_user_meta( $host_user_id, 'doublescale_user_email_account', true );
			if ( is_array( $account ) && ! empty( $account['enabled'] ) ) {
				$user = get_user_by( 'id', $host_user_id );
				$from = ! empty( $account['from_email'] )
					? $account['from_email']
					: ( $user ? $user->user_email : '' );

				if ( $from ) {
					$name = ! empty( $account['from_name'] )
						? $account['from_name']
						: ( $user ? ( $user->display_name ?: $user->user_login ) : '' );

					return array(
						'from_address' => $from,
						'from_name'    => $name,
						'reply_to'     => ! empty( $account['reply_to'] ) ? $account['reply_to'] : $from,
					);
				}
			}
		}

		if ( ! empty( $email_inbound['from_email'] ) ) {
			return array(
				'from_address' => $email_inbound['from_email'],
				'from_name'    => ! empty( $email_inbound['from_name'] )
					? $email_inbound['from_name']
					: get_bloginfo( 'name' ),
				'reply_to'     => ! empty( $email_inbound['reply_to'] )
					? $email_inbound['reply_to']
					: $email_inbound['from_email'],
			);
		}

		$admin = get_option( 'admin_email' );
		return array(
			'from_address' => $admin,
			'from_name'    => get_bloginfo( 'name' ),
			'reply_to'     => $admin,
		);
	}
}
