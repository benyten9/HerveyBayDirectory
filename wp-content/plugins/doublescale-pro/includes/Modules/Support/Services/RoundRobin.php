<?php
/**
 * New tickets go to the team in turn (Intercom round robin).
 *
 * A ticket created with nobody on it goes to the next person after the one
 * who got the last ticket (members in id order), skipping who lost helpdesk
 * access and, when the owner asks, who is not online. Nobody fits: it stays
 * unassigned, for the managers.
 *
 * Runs after the ticket is created (priority 20, after the "created" history
 * row and notices), through TicketService::update_ticket(): the agent gets
 * "Ticket assigned to you" and the history says "Assigned to …", as when a
 * person assigns it. Done as the system, never in the name of whoever
 * happened to create the ticket.
 *
 * The turn is one option row, read and moved under a database lock
 * (GET_LOCK): two tickets at the same moment take turns, never the same one,
 * and none is lost to a retry. No lock within 3 seconds: left unassigned.
 *
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Support\Models\TicketModel;
use DoubleScale\Modules\Support\Services\ContactResolver;
use DoubleScale\Modules\Support\Services\TicketService;

/**
 * RoundRobin class.
 */
final class RoundRobin {

	/**
	 * Who got the last ticket.
	 */
	public const POINTER = 'doublescale_support_auto_assign_last';

	/**
	 * Seconds to wait for the turn before leaving the ticket unassigned.
	 */
	private const WAIT = 3;

	/**
	 * The turn, later: when the owner's rules may assign new tickets.
	 */
	public const LATER_HOOK = 'doublescale_support_auto_assign_later';

	/**
	 * Seconds the turn waits for the owner's rules (queued at once).
	 */
	private const AFTER_RULES = MINUTE_IN_SECONDS;

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'doublescale_support_ticket_created', array( self::class, 'on_ticket_created' ), 20, 1 );
		add_action( self::LATER_HOOK, array( self::class, 'assign_later' ), 10, 1 );
	}

	/**
	 * @param mixed $ticket New ticket.
	 * @return void
	 */
	public static function on_ticket_created( $ticket ): void {
		if ( ! $ticket instanceof TicketModel || (int) $ticket->agent_user_id > 0 ) {
			return;
		}
		$settings = AutoAssignSettings::get();
		if ( ! $settings['enabled'] || array() === $settings['members'] ) {
			return;
		}

		// The owner's rules go first (Intercom): a workflow that assigns new
		// tickets runs from the queue a moment later. Taking the turn now gave
		// the ticket to someone who was told, made a collaborator on the
		// customer for good and lost their turn, just before the rule moved it.
		if ( self::rules_assign_new_tickets() && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::AFTER_RULES, self::LATER_HOOK, array( (int) $ticket->id ), 'doublescale' );
			return;
		}

		self::assign( $ticket, $settings );
	}

	/**
	 * The turn, after the owner's rules: only if the ticket is still open and
	 * nobody is on it.
	 *
	 * @param mixed $ticket_id Ticket id.
	 * @return void
	 */
	public static function assign_later( $ticket_id ): void {
		$ticket = TicketModel::find( (int) $ticket_id );
		if ( ! $ticket || (int) $ticket->agent_user_id > 0 || in_array( (string) $ticket->status, array( 'resolved', 'closed' ), true ) ) {
			return;
		}
		$settings = AutoAssignSettings::get();
		if ( ! $settings['enabled'] || array() === $settings['members'] ) {
			return;
		}
		self::assign( $ticket, $settings );
	}

	/**
	 * An active "Ticket created" workflow has an active "Assign agent" step.
	 *
	 * @return bool
	 */
	private static function rules_assign_new_tickets(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}doublescale_automations a
				INNER JOIN {$wpdb->prefix}doublescale_automation_steps s ON s.automation_id = a.id
				WHERE a.status = 'active' AND a.`trigger` = %s AND s.action = %s AND s.status = 'active' LIMIT 1",
				'ticket_created',
				'assign_agent_ticket'
			)
		);
	}

	/**
	 * Give the ticket to whoever's turn it is.
	 *
	 * @param TicketModel                                              $ticket   Ticket nobody is on.
	 * @param array{enabled: bool, members: int[], skip_offline: bool} $settings Settings.
	 * @return void
	 */
	private static function assign( TicketModel $ticket, array $settings ): void {
		try {
			$skip_offline = $settings['skip_offline'];
			$agent        = self::advance(
				$settings['members'],
				static function ( int $user_id ) use ( $skip_offline ): bool {
					return self::eligible( $user_id, $skip_offline );
				}
			);
			if ( 0 === $agent ) {
				return;
			}

			// The system assigns it: the history must not name whoever is signed in.
			$signed_in = get_current_user_id();
			wp_set_current_user( 0 );
			try {
				( new TicketService( new ContactResolver() ) )->update_ticket( $ticket, array( 'agent_user_id' => $agent ) );
			} finally {
				wp_set_current_user( $signed_in );
			}
		} catch ( \Throwable $e ) {
			// A ticket must never be lost to its assignment: it stays unassigned.
			if ( function_exists( 'doublescale_get_logger' ) ) {
				doublescale_get_logger()->error( 'Support auto assign failed', array( 'ticket_id' => (int) $ticket->id, 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * May take a ticket now.
	 *
	 * @param int  $user_id      Member.
	 * @param bool $skip_offline Only who is online.
	 * @return bool
	 */
	public static function eligible( int $user_id, bool $skip_offline ): bool {
		if ( false === get_userdata( $user_id ) || ! Permissions::has_support_access( $user_id ) ) {
			return false;
		}

		return ! $skip_offline || TeamPresence::is_online( $user_id );
	}

	/**
	 * Take the next turn: the first eligible member after the last one served.
	 *
	 * @param int[]    $members  Members.
	 * @param callable $eligible fn( int $user_id ): bool.
	 * @param string   $pointer  Option holding who got the last ticket.
	 * @return int The member, or 0 when nobody fits (or the turn stayed locked).
	 */
	public static function advance( array $members, callable $eligible, string $pointer = self::POINTER ): int {
		$members = array_values( array_unique( array_filter( array_map( 'intval', $members ) ) ) );
		sort( $members );
		if ( array() === $members ) {
			return 0;
		}
		global $wpdb;

		// One turn at a time: the database queues the others for a moment.
		// Lock names are shared by every database on the server: this one
		// names this site's options table, so another site never holds it up.
		$lock = 'ds_rr_' . md5( DB_NAME . '|' . $wpdb->options . '|' . $pointer );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::WAIT ) ) ) {
			return 0;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$last = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $pointer ) );
			$next = self::pick( $members, null === $last ? 0 : (int) $last, $eligible );
			if ( 0 === $next ) {
				return 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $pointer, (string) $next ) );
			wp_cache_delete( $pointer, 'options' );

			return $next;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * The first eligible member after $last, wrapping around.
	 *
	 * @param int[]    $members  Sorted members.
	 * @param int      $last     Who got the last ticket (0: nobody yet).
	 * @param callable $eligible fn( int $user_id ): bool.
	 * @return int
	 */
	public static function pick( array $members, int $last, callable $eligible ): int {
		$count = count( $members );
		$start = 0;
		foreach ( $members as $i => $member ) {
			if ( $member > $last ) {
				$start = $i;
				break;
			}
			$start = ( $i + 1 ) % $count;
		}
		for ( $i = 0; $i < $count; $i++ ) {
			$member = $members[ ( $start + $i ) % $count ];
			if ( $eligible( $member ) ) {
				return $member;
			}
		}

		return 0;
	}
}
