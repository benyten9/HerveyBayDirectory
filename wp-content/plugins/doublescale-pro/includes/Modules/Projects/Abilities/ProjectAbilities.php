<?php
/**
 * Read-only project abilities.
 *
 * @package DoubleScale\Pro\Modules\Projects
 */

namespace DoubleScale\Pro\Modules\Projects\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\Abilities\AbilityScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Pro\Modules\Projects\Services\ProjectManager;

/**
 * Projects tie contacts, deals, tasks, and invoices together, so they are the
 * natural answer to "where does this piece of work stand".
 *
 * Gate 3 keys on `owner_id`. Note the two-level permission model here differs
 * from Sales: `has_project_access()` decides whether you may use the module at
 * all, `can_manage_all_projects()` decides whether you see everyone's.
 */
final class ProjectAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( Permissions::class, 'has_project_access' );

		return array(
			'doublescale/list-projects'         => array(
				'module_slug'      => 'projects',
				'label'            => __( 'List projects', 'doublescale' ),
				'description'      => __( 'List projects with status, owner, linked contact, budget, progress, and due date. Unless you can manage all projects you see only projects you own.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'status_id'  => array(
							'type'        => 'integer',
							'description' => 'Only projects in this status. Use list-project-statuses to find ids.',
						),
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Only projects for this contact.',
						),
						'search'     => array(
							'type'        => 'string',
							'description' => 'Match on project title.',
						),
						'limit'      => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset'     => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_projects' ),
			),

			'doublescale/get-project'           => array(
				'module_slug'      => 'projects',
				'label'            => __( 'Get project', 'doublescale' ),
				'description'      => __( 'One project with its description, status, owner, contact, linked deal, budget, dates, and progress.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Project id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_project' ),
			),

			'doublescale/list-project-statuses' => array(
				'module_slug'      => 'projects',
				'label'            => __( 'List project statuses', 'doublescale' ),
				'description'      => __( 'The project statuses configured on this site, in board order, flagging which ones count as complete. Statuses are site-defined, not a fixed list.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'list_project_statuses' ),
			),

			'doublescale/create-project'        => array(
				'module_slug'      => 'projects',
				'label'            => __( 'Create a project', 'doublescale' ),
				'description'      => __( 'Create a project in one of the site\'s statuses. Call list-project-statuses first for valid status ids. Creating a project can start an automation.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'title'       => array(
							'type'        => 'string',
							'description' => 'Project name.',
						),
						'status_id'   => array(
							'type'        => 'integer',
							'description' => 'Status id from list-project-statuses.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'What the project covers.',
						),
						'contact_id'  => array(
							'type'        => 'integer',
							'description' => 'Client this project is for.',
						),
						'budget'      => array(
							'type'        => 'number',
							'description' => 'Project budget.',
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'Due date as YYYY-MM-DD.',
						),
					),
					'required'   => array( 'title', 'status_id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'create_project' ),
			),

			'doublescale/update-project'        => array(
				'module_slug'      => 'projects',
				'label'            => __( 'Update a project', 'doublescale' ),
				'description'      => __( 'Change a project\'s title, status, budget, or due date. Moving a project to a completed status can start an automation. You can only update projects you own unless you manage all projects.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Project id.',
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'New title.',
						),
						'status_id'   => array(
							'type'        => 'integer',
							'description' => 'New status id from list-project-statuses.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'New description.',
						),
						'budget'      => array(
							'type'        => 'number',
							'description' => 'New budget.',
						),
						'due_date'    => array(
							'type'        => 'string',
							'description' => 'New due date as YYYY-MM-DD.',
						),
					),
					'required'   => array( 'id' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => true,
					),
				),
				'execute_callback' => array( self::class, 'update_project' ),
			),

			'doublescale/get-project-summary'   => array(
				'module_slug'      => 'projects',
				'label'            => __( 'Get project summary', 'doublescale' ),
				'description'      => __( 'Project counts grouped by status, plus how many are overdue. Statuses are site-defined, so the groups come back with their names rather than a fixed list. Scoped the same way as list-projects: if your scope is "own", these are your projects only.', 'doublescale' ),
				'category'         => AbilityCategories::PROJECTS,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'execute_callback' => array( self::class, 'get_project_summary' ),
			),
		);
	}

	/**
	 * Project counts by status, plus overdue.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function get_project_summary( $input ) {
		unset( $input );

		$sees_all = self::sees_all();

		$by_status = array();
		foreach ( ProjectStatusModel::query()->get() as $status ) {
			$query = ProjectModel::query()->where( 'status_id', (int) $status->id );
			AbilityScope::apply( $query, 'owner_id', $sees_all );

			$by_status[] = array(
				'status_id' => (int) $status->id,
				'name'      => (string) $status->name,
				'count'     => (int) $query->count(),
			);
		}

		$total_query = ProjectModel::query();
		AbilityScope::apply( $total_query, 'owner_id', $sees_all );

		// Overdue means past its due date and not in a status that counts as
		// complete — a finished project with a past due date is not late.
		$complete_ids = ProjectStatusModel::query()
			->where( 'is_completed', 1 )
			->pluck( 'id' )
			->all();

		$overdue_query = ProjectModel::query()
			->whereNotNull( 'due_date' )
			->where( 'due_date', '<', gmdate( 'Y-m-d' ) );

		if ( ! empty( $complete_ids ) ) {
			$overdue_query->whereNotIn( 'status_id', $complete_ids );
		}

		AbilityScope::apply( $overdue_query, 'owner_id', $sees_all );

		return array(
			'total'     => (int) $total_query->count(),
			'overdue'   => (int) $overdue_query->count(),
			'by_status' => $by_status,
			'scope'     => AbilityScope::label( $sees_all ),
		);
	}

	/**
	 * Create a project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create_project( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'title', 'status_id' ) ),
				AbilityInput::id( $input['status_id'] ?? null, 'status_id' ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$missing = self::assert_status_exists( (int) $input['status_id'] );
		if ( $missing ) {
			return $missing;
		}

		if ( ! empty( $input['contact_id'] )
			&& ContactModel::query()->where( 'id', (int) $input['contact_id'] )->count() < 1 ) {
			return AbilityResult::not_found(
				sprintf(
					/* translators: %d: contact id */
					__( 'No contact exists with id %d.', 'doublescale' ),
					(int) $input['contact_id']
				)
			);
		}

		$data = array(
			'title'       => (string) $input['title'],
			'status_id'   => (int) $input['status_id'],
			'description' => isset( $input['description'] ) ? (string) $input['description'] : '',
			'owner_id'    => get_current_user_id(),
		);

		if ( ! empty( $input['contact_id'] ) ) {
			$data['contact_id'] = (int) $input['contact_id'];
		}
		if ( isset( $input['budget'] ) ) {
			$data['budget'] = (float) $input['budget'];
		}
		if ( ! empty( $input['due_date'] ) ) {
			$data['due_date'] = (string) $input['due_date'];
		}

		$project = ProjectManager::instance()->create_project( $data );

		if ( ! $project ) {
			return new \WP_Error(
				'doublescale_project_not_created',
				__( 'The project could not be created.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'created'    => true,
			'project_id' => (int) $project->id,
			'title'      => $project->title,
		);
	}

	/**
	 * Update a project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update_project( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'id' ) ),
				AbilityInput::id( $input['id'] ?? null, 'id' ),
				AbilityInput::id( $input['status_id'] ?? null, 'status_id' ),
				AbilityInput::date( $input['due_date'] ?? null, 'due_date' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$project = ProjectModel::query()->where( 'id', (int) $input['id'] )->first();
		if ( ! $project ) {
			return AbilityResult::not_found( __( 'No project found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$project,
			'owner_id',
			self::sees_all(),
			__( 'This project is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		if ( ! empty( $input['status_id'] ) ) {
			$missing = self::assert_status_exists( (int) $input['status_id'] );
			if ( $missing ) {
				return $missing;
			}
		}

		$data = array();
		foreach ( array( 'title', 'description', 'due_date' ) as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$data[ $field ] = (string) $input[ $field ];
			}
		}
		if ( isset( $input['status_id'] ) ) {
			$data['status_id'] = (int) $input['status_id'];
		}
		if ( isset( $input['budget'] ) ) {
			$data['budget'] = (float) $input['budget'];
		}

		if ( array() === $data ) {
			return array(
				'updated'    => false,
				'project_id' => (int) $project->id,
				'message'    => __( 'Nothing to change — supply at least one field to update.', 'doublescale' ),
			);
		}

		$updated = ProjectManager::instance()->update_project( (int) $project->id, $data );

		if ( ! $updated ) {
			return new \WP_Error(
				'doublescale_project_not_updated',
				__( 'The project could not be updated.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'updated'    => true,
			'project_id' => (int) $project->id,
			'changed'    => array_keys( $data ),
		);
	}

	/**
	 * Confirm a project status exists.
	 *
	 * Statuses are site-defined rows rather than a fixed enum, so the valid set
	 * differs per install and cannot be checked from a constant.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status_id Status id.
	 * @return \WP_Error|null Null when the status exists.
	 */
	private static function assert_status_exists( int $status_id ): ?\WP_Error {
		if ( ProjectStatusModel::query()->where( 'id', $status_id )->count() > 0 ) {
			return null;
		}

		return AbilityResult::not_found(
			sprintf(
				/* translators: %d: status id */
				__( 'No project status exists with id %d. Call list-project-statuses for valid ids.', 'doublescale' ),
				$status_id
			)
		);
	}

	/**
	 * Whether the caller sees every project or only their own.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function sees_all(): bool {
		return Permissions::can_manage_all_projects();
	}

	/**
	 * List projects.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_projects( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = ProjectModel::query()->with( array( 'status', 'contact' ) );

		if ( ! empty( $input['status_id'] ) ) {
			$query->where( 'status_id', (int) $input['status_id'] );
		}
		if ( ! empty( $input['contact_id'] ) ) {
			$query->where( 'contact_id', (int) $input['contact_id'] );
		}

		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$query->where( 'title', 'LIKE', '%' . $search . '%' );
		}

		// Applied last so no caller filter can widen it.
		$sees_all = self::sees_all();
		AbilityScope::apply( $query, 'owner_id', $sees_all );

		$total = (int) $query->count();

		$rows = $query->orderBy( 'created_at', 'desc' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_project( $row );
		}

		return AbilityResult::collection(
			$items,
			$total,
			$limit,
			$offset,
			array( 'scope' => AbilityScope::label( $sees_all ) )
		);
	}

	/**
	 * Get one project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_project( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid project id.', 'doublescale' ) );
		}

		$project = ProjectModel::query()
			->with( array( 'status', 'contact', 'owner' ) )
			->where( 'id', $id )
			->first();

		if ( ! $project ) {
			return AbilityResult::not_found( __( 'No project found with that id.', 'doublescale' ) );
		}

		$forbidden = AbilityScope::assert_owns(
			$project,
			'owner_id',
			self::sees_all(),
			__( 'This project is not assigned to you.', 'doublescale' )
		);
		if ( $forbidden ) {
			return $forbidden;
		}

		$data = self::shape_project( $project );

		$body                = AbilityResult::truncate( (string) ( $project->description ?? '' ) );
		$data['description'] = $body['text'];
		$data['truncated']   = $body['truncated'];

		$owner         = $project->owner ?? null;
		$data['owner'] = is_object( $owner )
			? array(
				'id'   => (int) $project->owner_id,
				'name' => $owner->display_name,
			)
			: null;

		$data['deal_id']    = $project->deal_id ? (int) $project->deal_id : null;
		$data['start_date'] = $project->start_date;

		return $data;
	}

	/**
	 * Site-defined project statuses.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_project_statuses( array $input ): array {
		unset( $input );

		$statuses = array();
		foreach ( ProjectStatusModel::query()->orderBy( 'position' )->get() as $status ) {
			$statuses[] = array(
				'id'           => (int) $status->id,
				'name'         => $status->name,
				'is_completed' => (bool) $status->is_completed,
			);
		}

		return array( 'statuses' => $statuses );
	}

	/**
	 * Shape a project row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $project Project.
	 * @return array<string, mixed>
	 */
	private static function shape_project( $project ): array {
		$contact = $project->contact ?? null;
		$status  = $project->status ?? null;

		$contact_name = '';
		if ( is_object( $contact ) ) {
			$contact_name = trim( (string) $contact->first_name . ' ' . (string) $contact->last_name );
			if ( '' === $contact_name ) {
				$contact_name = (string) $contact->email;
			}
		}

		return array(
			'id'       => (int) $project->id,
			'title'    => $project->title,
			'status'   => is_object( $status )
				? array(
					'id'           => (int) $project->status_id,
					'name'         => $status->name,
					'is_completed' => (bool) $status->is_completed,
				)
				: null,
			'contact'  => is_object( $contact )
				? array(
					'id'    => (int) $contact->id,
					'name'  => $contact_name,
					'email' => $contact->email,
				)
				: null,
			'budget'   => null !== $project->budget ? (float) $project->budget : null,
			'progress' => null !== $project->progress ? (int) $project->progress : null,
			'due_date' => $project->due_date,
		);
	}
}
