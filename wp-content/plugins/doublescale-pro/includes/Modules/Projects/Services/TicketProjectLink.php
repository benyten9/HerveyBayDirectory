<?php
/**
 * Answers free's ticket ↔ project questions (see free's TicketProject).
 *
 * A link never gives anyone access: linking needs the right to open the
 * project, the project must belong to the ticket's client (contact or
 * company), and a linked project's name is shown only to people who can
 * open it. In the portal a client sees a project's tickets only when they
 * can open the project there and the team turned on "Show tickets".
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\Projects\Capabilities;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use WP_Error;

final class TicketProjectLink {

	public static function register(): void {
		add_filter( 'doublescale_ticket_projects_available', '__return_true' );
		add_filter( 'doublescale_ticket_project_check', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'doublescale_ticket_project_matches_client', array( __CLASS__, 'matches_client' ), 10, 4 );
		add_filter( 'doublescale_ticket_project_summary', array( __CLASS__, 'summary' ), 10, 2 );
		add_filter( 'doublescale_portal_ticket_project_allowed', array( __CLASS__, 'portal_allowed' ), 10, 3 );
		add_filter( 'doublescale_portal_ticket_project_summary', array( __CLASS__, 'portal_summary' ), 10, 3 );
	}

	/**
	 * May the current user link a ticket of this client to this project?
	 *
	 * Order matters: "can you open it" comes before "is it this client's", so
	 * nobody learns which client a project they cannot open belongs to.
	 *
	 * @param mixed    $result     Previous result (ignored).
	 * @param int      $project_id Project id.
	 * @param int      $contact_id Ticket contact.
	 * @param int|null $company_id Ticket company.
	 * @return true|WP_Error
	 */
	public static function check( $result, $project_id, $contact_id, $company_id ) {
		unset( $result );
		$project = ProjectModel::find( (int) $project_id );
		if ( ! $project instanceof ProjectModel || ! Capabilities::can_read_project( $project ) ) {
			return new WP_Error(
				'project_not_found',
				__( 'Project not found, or you cannot open it.', 'doublescale' ),
				array( 'status' => 404 )
			);
		}
		if ( ! self::belongs_to( $project, (int) $contact_id, $company_id ? (int) $company_id : null ) ) {
			return new WP_Error(
				'project_other_client',
				__( 'This project belongs to a different client than the ticket.', 'doublescale' ),
				array( 'status' => 422 )
			);
		}
		return true;
	}

	/**
	 * @param bool     $matches    Previous result.
	 * @param int      $project_id Project id.
	 * @param int      $contact_id Contact id.
	 * @param int|null $company_id Company id.
	 */
	public static function matches_client( $matches, $project_id, $contact_id, $company_id ): bool {
		unset( $matches );
		$project = ProjectModel::find( (int) $project_id );
		return $project instanceof ProjectModel && self::belongs_to( $project, (int) $contact_id, $company_id ? (int) $company_id : null );
	}

	/**
	 * The linked project as the current user may see it.
	 *
	 * @param mixed $summary    Previous result.
	 * @param int   $project_id Project id.
	 * @return array{id: int, title: string}|null
	 */
	public static function summary( $summary, $project_id ): ?array {
		unset( $summary );
		$project = ProjectModel::find( (int) $project_id );
		if ( ! $project instanceof ProjectModel || ! Capabilities::can_read_project( $project ) ) {
			return null;
		}
		return array(
			'id'    => (int) $project->id,
			'title' => (string) $project->title,
		);
	}

	/**
	 * May this portal client see this project's tickets?
	 *
	 * @param bool $allowed    Previous result.
	 * @param int  $project_id Project id.
	 * @param int  $contact_id Contact id.
	 */
	public static function portal_allowed( $allowed, $project_id, $contact_id ): bool {
		unset( $allowed );
		$project = ProjectModel::find( (int) $project_id );
		return $project instanceof ProjectModel
			&& (bool) $project->portal_show_tickets
			&& ProjectPortalScope::owns( $project, (int) $contact_id );
	}

	/**
	 * The linked project as a portal client may see it.
	 *
	 * @param mixed $summary    Previous result.
	 * @param int   $project_id Project id.
	 * @param int   $contact_id Contact id.
	 * @return array{id: int, title: string}|null
	 */
	public static function portal_summary( $summary, $project_id, $contact_id ): ?array {
		unset( $summary );
		$project = ProjectModel::find( (int) $project_id );
		if ( ! $project instanceof ProjectModel || ! ProjectPortalScope::owns( $project, (int) $contact_id ) ) {
			return null;
		}
		return array(
			'id'    => (int) $project->id,
			'title' => (string) $project->title,
		);
	}

	/**
	 * Whether the project is this client's: the ticket's contact, the ticket's
	 * company, or a company the ticket's contact belongs to (a member's
	 * private ticket about their company's project).
	 *
	 * @param ProjectModel $project    Project.
	 * @param int          $contact_id Ticket contact.
	 * @param int|null     $company_id Ticket company.
	 */
	private static function belongs_to( ProjectModel $project, int $contact_id, ?int $company_id ): bool {
		if ( $contact_id > 0 && (int) $project->contact_id === $contact_id ) {
			return true;
		}
		$project_company = (int) $project->company_id;
		if ( $project_company <= 0 ) {
			return false;
		}
		if ( $company_id && $project_company === $company_id ) {
			return true;
		}
		return $contact_id > 0
			&& function_exists( 'doublescale_record_company_id' )
			&& doublescale_record_company_id( $contact_id, $project_company ) === $project_company;
	}
}
