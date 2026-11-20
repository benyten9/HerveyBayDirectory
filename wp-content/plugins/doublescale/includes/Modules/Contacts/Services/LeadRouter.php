<?php
/**
 * Lead routing: who owns a new contact.
 *
 * @package DoubleScale\Modules\Contacts\Services
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;

/**
 * Decides the owner of a contact at creation time.
 *
 * Priority, first match wins:
 *  1. the acting user is a scoped agent → they own what they create or import;
 *  2. an explicit `owner_id` in the context (a manager's choice);
 *  3. the rule for the source: a form's owner, a booking calendar's host, an
 *     inbound mailbox's owner;
 *  4. round-robin over the configured agents, when enabled;
 *  5. the default owner from Settings → Team → Lead routing;
 *  6. nobody — the contact lands in the unassigned pool (managers only).
 *
 * Until a site sets any of this up, every answer is step 6 or step 1, which
 * keeps existing installs exactly as they are.
 */
final class LeadRouter {

	/**
	 * Routing rules live in their own option (not the shared settings array,
	 * which is saved whole and can be overwritten by a slow request), and the
	 * round-robin position in another, so assigning a lead writes only that.
	 */
	public const OPTION        = 'doublescale_lead_routing';
	public const CURSOR_OPTION = 'doublescale_lead_routing_cursor';

	public const PICK_DEFAULT     = 'default';
	public const PICK_ROUND_ROBIN = 'round_robin';
	public const PICK_HOST        = 'host';
	public const PICK_WORKFLOW_OWNER = 'workflow_owner';

	/**
	 * The stored rules, with every key present.
	 *
	 * @return array{default_owner_id:int, round_robin:array{enabled:bool, agent_ids:int[], cursor:int}, booking_calendars:array<string,int|string>, inbound:string, integrations:string}
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$round_robin = isset( $stored['round_robin'] ) && is_array( $stored['round_robin'] ) ? $stored['round_robin'] : array();

		return array(
			'default_owner_id'  => (int) ( $stored['default_owner_id'] ?? 0 ),
			'round_robin'       => array(
				'enabled'   => ! empty( $round_robin['enabled'] ),
				'agent_ids' => array_values( array_filter( array_map( 'intval', (array) ( $round_robin['agent_ids'] ?? array() ) ) ) ),
				'cursor'    => (int) get_option( self::CURSOR_OPTION, 0 ),
			),
			'booking_calendars' => is_array( $stored['booking_calendars'] ?? null ) ? $stored['booking_calendars'] : array(),
			'inbound'           => (string) ( $stored['inbound'] ?? 'mailbox_owner' ),
			'integrations'      => (string) ( $stored['integrations'] ?? self::PICK_DEFAULT ),
			// Contacts a workflow creates: the workflow's owner unless changed
			// to the default rules or round-robin. A workflow can still pick
			// its own owner.
			'automations'       => (string) ( $stored['automations'] ?? self::PICK_WORKFLOW_OWNER ),
		);
	}

	/**
	 * Resolve the owner for a contact being created.
	 *
	 * @param string               $source  The contact's `source` ('form', 'booking', 'support', 'woocommerce', 'import', …).
	 * @param array<string, mixed> $context Optional: 'owner_id' (manager's pick), 'form_id', 'calendar_id',
	 *                                      'host_user_id', 'mailbox_user_id', 'actor_id' (default: current user).
	 * @return int|null User id, or null for the unassigned pool.
	 */
	public static function resolve( string $source, array $context = array() ): ?int {
		$actor = isset( $context['actor_id'] ) ? (int) $context['actor_id'] : (int) get_current_user_id();

		// 1. An agent owns what they bring in (whatever the visibility mode,
		// so a site that switches to private later already has owners).
		if ( $actor > 0 && Permissions::is_agent_tier( $actor ) ) {
			return $actor;
		}

		// 2. A manager chose.
		$explicit = (int) ( $context['owner_id'] ?? 0 );
		if ( $explicit > 0 && self::is_valid_owner( $explicit ) ) {
			return $explicit;
		}

		$settings = self::settings();
		$pick     = null;

		// 3. The source's own rule.
		switch ( $source ) {
			case 'form':
				// Each form names its owner in its own settings (passed as the
				// explicit owner); without one, the default rule.
				break;

			case 'booking':
				$calendar_id = (string) ( $context['calendar_id'] ?? '' );
				$pick        = $settings['booking_calendars'][ $calendar_id ] ?? self::PICK_HOST;
				if ( self::PICK_HOST === $pick ) {
					$pick = (int) ( $context['host_user_id'] ?? 0 );
				}
				break;

			case 'support':
			case 'inbox':
			case 'email':
			case 'whatsapp':
			case 'sms':
				if ( 'mailbox_owner' === $settings['inbound'] ) {
					$pick = (int) ( $context['mailbox_user_id'] ?? 0 );
				}
				break;

			case 'automation':
				if ( self::PICK_WORKFLOW_OWNER === $settings['automations'] ) {
					$pick = (int) ( $context['workflow_owner_id'] ?? 0 );
				} elseif ( self::PICK_ROUND_ROBIN === $settings['automations'] ) {
					$pick = self::PICK_ROUND_ROBIN;
				}
				break;

			default:
				if ( self::PICK_ROUND_ROBIN === $settings['integrations'] ) {
					$pick = self::PICK_ROUND_ROBIN;
				}
		}

		$owner = self::pick_to_owner( $pick, $settings );

		// 4. Round-robin, when enabled site-wide and nothing more specific applied.
		if ( null === $owner && $settings['round_robin']['enabled'] ) {
			$owner = self::next_round_robin( $settings );
		}

		// 5. The default owner.
		if ( null === $owner && $settings['default_owner_id'] > 0 && self::is_valid_owner( $settings['default_owner_id'] ) ) {
			$owner = $settings['default_owner_id'];
		}

		/**
		 * Filters the owner chosen for a new contact.
		 *
		 * @param int|null $owner   Resolved owner (null = unassigned pool).
		 * @param string   $source  Contact source.
		 * @param array    $context Routing context.
		 */
		$owner = apply_filters( 'doublescale_lead_router_owner', $owner, $source, $context );

		return ( is_int( $owner ) && $owner > 0 ) ? $owner : null;
	}

	/**
	 * Turn a stored pick (user id, 'round_robin', 'default') into a user id.
	 *
	 * @param mixed $pick     Stored value.
	 * @param array $settings Settings.
	 * @return int|null
	 */
	private static function pick_to_owner( $pick, array $settings ): ?int {
		if ( null === $pick || '' === $pick ) {
			return null;
		}

		if ( self::PICK_ROUND_ROBIN === $pick ) {
			return self::next_round_robin( $settings );
		}

		if ( self::PICK_DEFAULT === $pick ) {
			return null; // falls through to the default owner below.
		}

		$user_id = (int) $pick;

		return ( $user_id > 0 && self::is_valid_owner( $user_id ) ) ? $user_id : null;
	}

	/**
	 * The next agent in the round-robin rotation, advancing the cursor.
	 *
	 * The cursor is bumped with one read-modify-write on the option. Two leads
	 * in the same instant may land on the same agent; that is acceptable for a
	 * rotation, and far cheaper than locking.
	 *
	 * @param array|null $settings Settings (loaded when null).
	 * @return int|null
	 */
	public static function next_round_robin( ?array $settings = null ): ?int {
		$settings = $settings ?? self::settings();
		$agents   = array_values( array_filter( $settings['round_robin']['agent_ids'], array( self::class, 'is_valid_owner' ) ) );

		if ( empty( $agents ) ) {
			return null;
		}

		$cursor = (int) $settings['round_robin']['cursor'] % count( $agents );
		$owner  = $agents[ $cursor ];

		update_option( self::CURSOR_OPTION, ( $cursor + 1 ) % count( $agents ), false );

		return $owner;
	}

	/**
	 * A valid owner is a user who still holds a CRM role that can own
	 * contacts: a CRM Agent, or any manager-tier CRM role.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	/**
	 * Routing context for contacts created inside $callback (source + its
	 * details: form_id, calendar_id, host_user_id, mailbox_user_id).
	 *
	 * Contacts are created in many places, most through generic helpers
	 * (createOrUpdate, firstOrCreate) that know nothing about routing. The
	 * caller that DOES know wraps the creation, and the ContactModel
	 * `creating` observer picks the context up — one choke point, no
	 * routing arguments threaded through every helper.
	 *
	 * @template T
	 * @param string            $source   Lead source (form, booking, email, …).
	 * @param array             $context  Source details.
	 * @param callable(): T     $callback Code that may create contacts.
	 * @return T
	 */
	public static function with_context( string $source, array $context, callable $callback ) {
		self::$stack[] = array( $source, $context );
		try {
			return $callback();
		} finally {
			array_pop( self::$stack );
		}
	}

	/**
	 * The owner for a contact being created right now (the model observer):
	 * the innermost with_context() wins over the contact's own `source`.
	 *
	 * @param string $source The contact's `source` column.
	 * @return int|null
	 */
	public static function resolve_for_new_contact( string $source ): ?int {
		if ( ! empty( self::$stack ) ) {
			list( $ctx_source, $context ) = self::$stack[ count( self::$stack ) - 1 ];
			return self::resolve( $ctx_source, $context );
		}

		return self::resolve( $source, array() );
	}

	/**
	 * Active with_context() frames.
	 *
	 * @var array<int, array{0:string,1:array}>
	 */
	private static $stack = array();

	public static function is_valid_owner( int $user_id ): bool {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return false;
		}

		// Anyone on the team: administrators, and every DoubleScale role.
		if ( Permissions::is_crm_manager( $user_id ) ) {
			return true;
		}

		$user = get_userdata( $user_id );

		return (bool) array_intersect( UserRoles::get_known_role_slugs(), (array) $user->roles );
	}
}
