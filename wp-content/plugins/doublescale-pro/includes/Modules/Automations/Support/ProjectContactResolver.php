<?php
/**
 * Resolves a ContactModel from a ProjectModel for automation enrollment.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Support;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that maps a project to a contact for automation enrollment.
 *
 * Projects with a null contact_id cannot enroll — automations enroll a contact.
 */
final class ProjectContactResolver {

	/**
	 * Resolve the contact associated with a project.
	 *
	 * @param ProjectModel $project Project.
	 * @return ContactModel|null
	 */
	public static function resolve( ProjectModel $project ): ?ContactModel {
		if ( ! self::projects_storage_ready() ) {
			return null;
		}

		$contact = $project->contact;
		return $contact instanceof ContactModel ? $contact : null;
	}

	/**
	 * Look up a project by ID when projects storage is ready.
	 *
	 * @param int $project_id Project ID.
	 * @return ProjectModel|null
	 */
	public static function find_project( int $project_id ): ?ProjectModel {
		if ( $project_id <= 0 || ! self::projects_storage_ready() ) {
			return null;
		}

		$project = ProjectModel::find( $project_id );
		return $project instanceof ProjectModel ? $project : null;
	}

	/**
	 * Resolve a project from an automation contact's enrollment data.
	 *
	 * @param object $automation_contact Automation contact with `data['project_id']`.
	 * @return ProjectModel|null
	 */
	public static function resolve_from_automation_contact( $automation_contact ): ?ProjectModel {
		if ( ! is_object( $automation_contact ) || ! isset( $automation_contact->data ) ) {
			return null;
		}

		$data       = is_array( $automation_contact->data ) ? $automation_contact->data : array();
		$project_id = isset( $data['project_id'] ) ? (int) $data['project_id'] : 0;

		return self::find_project( $project_id );
	}

	/**
	 * Whether projects storage is safe to query.
	 */
	private static function projects_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'projects', ProjectModel::class );
	}
}
