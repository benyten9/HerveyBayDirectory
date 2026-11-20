<?php
/**
 * The client portal's ticket screens follow new replies live, through the
 * real-time relay ({@see \DoubleScale\Pro\Core\Realtime\Realtime}).
 *
 * - Each portal client listens on `portal.contact.<contact id>`, and on
 *   `portal.company.<company id>` for each company whose tickets they may see.
 *   Tokens for those channels only are in their portal config (`realtime`).
 * - A reply on a ticket (from the team, or from the customer by email or the
 *   portal) or a status change sends `support.ticket` {ticket_id, what} there.
 *   Only ids and a word: the portal reads the ticket through its own routes,
 *   which decide what this client may see. Internal notes fire no event.
 *
 * Without an active licence (or with the relay off) nothing is sent and the
 * portal's own polling carries on.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Core\Realtime\Realtime;

/**
 * PortalLive class.
 */
final class PortalLive {

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_support_reply_created', array( self::class, 'on_reply' ), 30, 2 );
		add_action( 'doublescale_support_ticket_updated', array( self::class, 'on_updated' ), 30, 3 );
		add_filter( 'doublescale_client_portal_config', array( self::class, 'portal_config' ), 30, 2 );
		// The portal's notification bell: shown at once, no polling.
		add_action( 'doublescale_portal_notification_added', array( self::class, 'on_notification' ), 10, 2 );
	}

	/**
	 * @param int $id         Notification.
	 * @param int $contact_id Client.
	 * @return void
	 */
	public static function on_notification( $id, $contact_id ): void {
		if ( (int) $contact_id <= 0 || ! class_exists( Realtime::class ) || ! Realtime::available() ) {
			return;
		}
		Realtime::publish( self::contact_channel( (int) $contact_id ), 'portal.notification', array( 'id' => (int) $id ) );
	}

	/**
	 * @param int $contact_id Contact.
	 * @return string
	 */
	public static function contact_channel( int $contact_id ): string {
		return 'portal.contact.' . $contact_id;
	}

	/**
	 * @param int $company_id Company.
	 * @return string
	 */
	public static function company_channel( int $company_id ): string {
		return 'portal.company.' . $company_id;
	}

	/**
	 * @param mixed $activity Reply.
	 * @param mixed $ticket   Ticket.
	 * @return void
	 */
	public static function on_reply( $activity, $ticket ): void {
		unset( $activity );
		self::send( $ticket, 'reply' );
	}

	/**
	 * @param mixed $ticket    Ticket after.
	 * @param mixed $effective Changed fields.
	 * @param mixed $before    Ticket before.
	 * @return void
	 */
	public static function on_updated( $ticket, $effective = null, $before = null ): void {
		unset( $effective );
		if ( is_object( $before ) && is_object( $ticket ) && (string) ( $before->status ?? '' ) === (string) ( $ticket->status ?? '' ) ) {
			return;
		}
		self::send( $ticket, 'status' );
	}

	/**
	 * @param mixed  $ticket Ticket.
	 * @param string $what   reply | status.
	 * @return void
	 */
	private static function send( $ticket, string $what ): void {
		if ( ! is_object( $ticket ) || ! class_exists( Realtime::class ) || ! Realtime::available() ) {
			return;
		}
		$channels = array();
		if ( ! empty( $ticket->contact_id ) ) {
			$channels[] = self::contact_channel( (int) $ticket->contact_id );
		}
		// A ticket shared with the client's company: the colleagues who see it.
		if ( ! empty( $ticket->company_id ) ) {
			$channels[] = self::company_channel( (int) $ticket->company_id );
		}
		if ( $channels ) {
			Realtime::publish(
				$channels,
				'support.ticket',
				array(
					'ticket_id' => (int) $ticket->id,
					'what'      => $what,
				)
			);
		}
	}

	/**
	 * The portal client's relay address and tokens for their own channels.
	 *
	 * @param array<string, mixed> $config Portal config.
	 * @param mixed                $user   Current user.
	 * @return array<string, mixed>
	 */
	public static function portal_config( $config, $user = null ) {
		unset( $user );
		$config = is_array( $config ) ? $config : array();
		if ( ! class_exists( Realtime::class ) || ! Realtime::available() || ! class_exists( '\\DoubleScale\\Modules\\Portal\\Services\\PortalIdentity' ) ) {
			return $config;
		}
		$contact = \DoubleScale\Modules\Portal\Services\PortalIdentity::current_contact();
		if ( null === $contact ) {
			return $config;
		}
		$channels = array( self::contact_channel( (int) $contact->id ) );
		if ( class_exists( '\\DoubleScale\\Modules\\Portal\\Services\\PortalScope' ) ) {
			foreach ( (array) \DoubleScale\Modules\Portal\Services\PortalScope::company_ids( (int) $contact->id, 'tickets' ) as $company_id ) {
				$channels[] = self::company_channel( (int) $company_id );
			}
		}
		$client = Realtime::client_config( $channels );
		if ( null !== $client ) {
			$config['realtime'] = $client;
		}
		return $config;
	}
}
