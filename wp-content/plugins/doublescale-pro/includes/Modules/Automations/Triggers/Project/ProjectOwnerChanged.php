<?php
/**
 * Automation trigger: project owner changed.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectOwnerChanged trigger.
 */
class ProjectOwnerChanged extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project owner changed';

	/**
	 * @var string
	 */
	public $slug = 'project_owner_changed';

	/**
	 * @var string
	 */
	public $description = 'Fires when a project owner is assigned or changed. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_project_owner_changed', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * @param mixed $project      Project model.
	 * @param mixed $old_owner_id Previous owner.
	 * @param mixed $new_owner_id New owner.
	 */
	public function handle( $project, $old_owner_id = null, $new_owner_id = null ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}
		$this->enroll(
			$project,
			array(
				'old_owner_id' => $old_owner_id,
				'new_owner_id' => $new_owner_id,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$project = $args['project'] ?? null;
		if ( ! $project instanceof ProjectModel ) {
			return false;
		}

		$owner  = $automation->get_setting( 'owner', '' );
		$actual = $args['data']['new_owner_id'] ?? $project->owner_id;

		return $this->matches_any_or_value( $owner, $actual, 'any-user' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array(
			'owner' => array(
				'label'       => $this->t( 'New owner' ),
				'type'        => 'infinite_scroll_select',
				'endpoint'    => '/doublescale/v1/user-management/users/frontend',
				'placeholder' => $this->t( 'Search and select owner…' ),
				'helperText'  => $this->t( 'Leave empty to match any owner.' ),
				'settings'    => array(
					'apiParams'       => array(
						'filter_crm_users' => 'true',
					),
					'dataPath'        => 'users',
					'totalPath'       => 'pagination.total',
					'searchParamName' => 'search',
					'perPage'         => 20,
				),
			),
		);
	}
}