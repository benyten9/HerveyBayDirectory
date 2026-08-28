<?php
/**
 * Automation trigger: project converted from deal.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectConvertedFromDeal trigger.
 */
class ProjectConvertedFromDeal extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project converted from deal';

	/**
	 * @var string
	 */
	public $slug = 'project_converted_from_deal';

	/**
	 * @var string
	 */
	public $description = 'Fires when a project is created by converting a deal. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_project_converted_from_deal', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * @param mixed $project Project model.
	 * @param mixed $deal    Deal model.
	 */
	public function handle( $project, $deal = null ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}

		$extra = array();
		if ( is_object( $deal ) && isset( $deal->id ) ) {
			$extra['deal_id'] = (int) $deal->id;
		}

		$this->enroll( $project, $extra );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		return isset( $args['project'] ) && $args['project'] instanceof ProjectModel;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}
}