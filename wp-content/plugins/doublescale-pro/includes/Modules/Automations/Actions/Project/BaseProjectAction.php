<?php
/**
 * Shared base for project automation actions.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Project;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Automations\Support\ProjectContactResolver;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectStatusModel;
use DoubleScale\Core\UserRoles\UserRoles;

defined( 'ABSPATH' ) || exit;

/**
 * BaseProjectAction
 */
abstract class BaseProjectAction extends Action {

	/**
	 * Source.
	 *
	 * @var string
	 */
	public $source = 'projects';

	/**
	 * Group.
	 *
	 * @var string
	 */
	public $group = 'project';

	/**
	 * Triggers that put a project in scope for mutation actions.
	 *
	 * @var array
	 */
	public static $project_trigger_slugs = array(
		'project_created',
		'project_status_changed',
		'project_completed',
		'project_owner_changed',
		'project_due_soon',
		'project_overdue',
		'project_comment_posted',
		'project_converted_from_deal',
	);

	/**
	 * Translate helper.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected function t( $text ) {
		if ( function_exists( '\\__' ) ) {
			return call_user_func( '\\__', $text, 'doublescale' );
		}
		return $text;
	}

	/**
	 * Whether projects storage is safe to query.
	 */
	protected function projects_storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'projects', ProjectModel::class );
	}

	/**
	 * Resolve merge tags within free text.
	 *
	 * @param string                 $text               Raw text.
	 * @param AutomationContactModel $automation_contact Contact.
	 * @return string
	 */
	protected function parse_text( $text, AutomationContactModel $automation_contact ) {
		if ( empty( $text ) ) {
			return '';
		}
		if ( preg_match( '/{{.*?:.*?}}/', $text ) ) {
			return \DoubleScale\Core\MergeTags\MergeTagsManager::instance()->process_merge_tags( $text, $automation_contact );
		}
		return $text;
	}

	/**
	 * Resolve the triggering project from enrollment data.
	 *
	 * @param AutomationContactModel $automation_contact Contact.
	 * @return ProjectModel|null
	 */
	protected function resolve_project( AutomationContactModel $automation_contact ): ?ProjectModel {
		return ProjectContactResolver::resolve_from_automation_contact( $automation_contact );
	}

	/**
	 * CRM user select options.
	 *
	 * @return array
	 */
	protected function get_users_options(): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role__in' => array(
					UserRoles::CRM_MANAGER,
					UserRoles::SALES_REP,
					UserRoles::ADMINISTRATOR,
					UserRoles::PROJECT_MANAGER,
					UserRoles::PROJECT_MEMBER,
				),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			)
		);

		$options = array();
		foreach ( $users as $user ) {
			$options[ $user->ID ] = $user->display_name;
		}
		return $options;
	}

	/**
	 * Project status options.
	 *
	 * @return array
	 */
	protected function get_status_options(): array {
		if ( ! $this->projects_storage_ready() ) {
			return array();
		}

		$options = array();
		foreach ( ProjectStatusModel::orderBy( 'position', 'asc' )->get() as $status ) {
			$options[ $status->id ] = $status->name;
		}
		return $options;
	}

	/**
	 * Shared infinite-scroll owner field config.
	 *
	 * @param bool $required Whether the field is required.
	 * @return array
	 */
	protected function get_owner_field( bool $required = false ): array {
		$field = array(
			'label'       => $this->t( 'Owner' ),
			'type'        => 'infinite_scroll_select',
			'endpoint'    => '/doublescale/v1/user-management/users/frontend',
			'placeholder' => $this->t( 'Search and select owner…' ),
			'settings'    => array(
				'apiParams'       => array(
					'filter_crm_users' => 'true',
				),
				'dataPath'        => 'users',
				'totalPath'       => 'pagination.total',
				'searchParamName' => 'search',
				'perPage'         => 20,
			),
		);

		if ( $required ) {
			$field['required'] = true;
		}

		return $field;
	}
}