<?php
/**
 * Links between support tickets and projects (projects ship in DoubleScale Pro).
 *
 * The one rule: a link never gives anyone access. A ticket stays visible
 * under the ticket rules (managers all, agents their own; portal clients
 * their own or their company's), a project under the project rules. Free
 * stores the link (`project_id` on the ticket); Pro answers the project
 * questions through the filters below. Without Pro nothing can be linked.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Portal\Services\PortalAccess;
use DoubleScale\Modules\Portal\Services\PortalAccessGate;
use WP_Error;

final class TicketProject {

	/** @var array<int, array{id: int, title: string}|null> Per-request summaries for the current user. */
	private static $summaries = array();

	/**
	 * Whether tickets can be linked to projects on this site (Pro Projects on).
	 */
	public static function available(): bool {
		/**
		 * Whether support tickets can be linked to projects.
		 *
		 * @param bool $available Default false (projects ship in Pro).
		 */
		return (bool) apply_filters( 'doublescale_ticket_projects_available', false );
	}

	/**
	 * May the current user link a ticket of this client to this project?
	 *
	 * The caller has already checked that the user may edit the ticket.
	 *
	 * @param int      $project_id Project id.
	 * @param int      $contact_id The ticket's contact.
	 * @param int|null $company_id The ticket's company (null = none).
	 * @return true|WP_Error
	 */
	public static function check( int $project_id, int $contact_id, ?int $company_id ) {
		/**
		 * Decide whether the current user may link a ticket to a project.
		 *
		 * Pro returns true, or a WP_Error: the project does not exist, the user
		 * cannot open it, or it belongs to another client.
		 *
		 * @param true|WP_Error $result     Default: projects unavailable.
		 * @param int           $project_id Project id.
		 * @param int           $contact_id Ticket contact.
		 * @param int|null      $company_id Ticket company.
		 */
		$result = apply_filters(
			'doublescale_ticket_project_check',
			new WP_Error( 'projects_unavailable', __( 'Linking tickets to projects needs DoubleScale Pro with Projects turned on.', 'doublescale' ), array( 'status' => 400 ) ),
			$project_id,
			$contact_id,
			$company_id
		);
		return true === $result ? true : ( is_wp_error( $result ) ? $result : new WP_Error( 'project_not_allowed', __( 'This ticket cannot be linked to that project.', 'doublescale' ), array( 'status' => 403 ) ) );
	}

	/**
	 * Whether a project still belongs to a ticket's client (server-side only).
	 *
	 * Used when a ticket's contact or company changes, to drop a link that no
	 * longer fits. Ignores who is asking on purpose: an editor who cannot
	 * open the project is no reason to drop the link.
	 *
	 * @param int      $project_id Project id.
	 * @param int      $contact_id The ticket's contact.
	 * @param int|null $company_id The ticket's company (null = none).
	 */
	public static function matches_client( int $project_id, int $contact_id, ?int $company_id ): bool {
		/**
		 * Whether a project belongs to this contact or company.
		 *
		 * @param bool     $matches    Default true (nothing to compare without Pro).
		 * @param int      $project_id Project id.
		 * @param int      $contact_id Contact id.
		 * @param int|null $company_id Company id.
		 */
		return (bool) apply_filters( 'doublescale_ticket_project_matches_client', true, $project_id, $contact_id, $company_id );
	}

	/**
	 * What the current user may see of a linked project: id and title, or null.
	 *
	 * @param int|null $project_id Linked project id.
	 * @return array{id: int, title: string}|null
	 */
	public static function summary( ?int $project_id ): ?array {
		if ( ! $project_id ) {
			return null;
		}
		if ( ! array_key_exists( $project_id, self::$summaries ) ) {
			/**
			 * The linked project as the current user may see it (null = hide it).
			 *
			 * @param array|null $summary    Default null.
			 * @param int        $project_id Project id.
			 */
			$summary                          = apply_filters( 'doublescale_ticket_project_summary', null, $project_id );
			self::$summaries[ $project_id ] = is_array( $summary ) && isset( $summary['id'], $summary['title'] )
				? array(
					'id'    => (int) $summary['id'],
					'title' => (string) $summary['title'],
				)
				: null;
		}
		return self::$summaries[ $project_id ];
	}

	/**
	 * May this portal client see (and file tickets under) this project's tickets?
	 *
	 * True only when they can open the project in the portal, the project
	 * shows tickets to clients, and the client has the Support section.
	 *
	 * @param int          $project_id Project id.
	 * @param ContactModel $contact    Portal client.
	 */
	public static function portal_allowed( int $project_id, ContactModel $contact ): bool {
		if ( $project_id <= 0 || ! self::portal_has_tickets( $contact ) ) {
			return false;
		}
		/**
		 * Whether a portal client may see a project's tickets.
		 *
		 * @param bool $allowed    Default false (projects ship in Pro).
		 * @param int  $project_id Project id.
		 * @param int  $contact_id Contact id.
		 */
		return (bool) apply_filters( 'doublescale_portal_ticket_project_allowed', false, $project_id, (int) $contact->id );
	}

	/**
	 * The linked project as a portal client may see it, or null.
	 *
	 * @param int|null     $project_id Linked project id.
	 * @param ContactModel $contact    Portal client.
	 * @return array{id: int, title: string}|null
	 */
	public static function portal_summary( ?int $project_id, ContactModel $contact ): ?array {
		if ( ! $project_id ) {
			return null;
		}
		/**
		 * The linked project as a portal client may see it (null = hide it).
		 *
		 * @param array|null $summary    Default null.
		 * @param int        $project_id Project id.
		 * @param int        $contact_id Contact id.
		 */
		$summary = apply_filters( 'doublescale_portal_ticket_project_summary', null, $project_id, (int) $contact->id );
		return is_array( $summary ) && isset( $summary['id'], $summary['title'] )
			? array(
				'id'    => (int) $summary['id'],
				'title' => (string) $summary['title'],
			)
			: null;
	}

	/**
	 * Whether the client's portal shows the Support section.
	 *
	 * @param ContactModel $contact Portal client.
	 */
	public static function portal_has_tickets( ContactModel $contact ): bool {
		if ( ! class_exists( PortalAccess::class ) || ! class_exists( PortalAccessGate::class ) ) {
			return false;
		}
		return PortalAccessGate::visible( 'sections', 'tickets', PortalAccess::for_contact( $contact ) );
	}
}
