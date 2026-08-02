<?php

namespace DoubleScale\Pro\Modules\Automations\Triggers\Deal;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;


/**
 * Deal Stage Change Trigger
 */
class DealStageChange extends Trigger {

	/**
	 * Default Value Pipeline
	 *
	 * @var string
	 */
	public $Default_Value_Pipeline = 'any-pipeline';

	/**
	 * Default Value Stage
	 *
	 * @var string
	 */
	public $Default_Value_Stage = 'any-stage';

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Deal Stage changes';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_stage_change';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a deal stage is changed.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Trigger Source
	 *
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'deal';

	public function __construct() {
		 parent::__construct();
	}

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		\add_action( 'doublescale_automation_deal_stage_changed', array( $this, 'deal_stage_changed' ), 10, 4 );
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array           $args Arguments.
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$deal         = $args['deal'];
		$old_stage_id = $args['data']['old_stage_id'];
		$new_stage_id = $args['data']['new_stage_id'];

		// Get automation settings with default values
		$automation_pipeline_id  = $automation->get_setting( 'pipeline', $this->Default_Value_Pipeline );
		$automation_old_stage_id = $automation->get_setting( 'old_stage', $this->Default_Value_Stage );
		$automation_new_stage_id = $automation->get_setting( 'new_stage', $this->Default_Value_Stage );

		if ( $old_stage_id === $new_stage_id ) {
			return false;
		}

		// Case 1: {"pipeline":"any-pipeline","old_stage":"any-stage","new_stage":"any-stage"}
		// All conditions are "any" - trigger for all deals regardless of pipeline or stages
		if (
			$automation_pipeline_id === $this->Default_Value_Pipeline &&
			$automation_old_stage_id === $this->Default_Value_Stage &&
			$automation_new_stage_id === $this->Default_Value_Stage
		) {
			return true;
		}

		// Case 2: {"pipeline":"value","old_stage":"any-stage","new_stage":"any-stage"}
		// Specific pipeline, any old stage, any new stage
		if (
			$automation_pipeline_id !== $this->Default_Value_Pipeline &&
			$automation_old_stage_id === $this->Default_Value_Stage &&
			$automation_new_stage_id === $this->Default_Value_Stage
		) {
			return $deal->pipeline_id == $automation_pipeline_id;
		}

		// Case 3: {"pipeline":"value","old_stage":"value","new_stage":"any-stage"}
		// Specific pipeline, specific old stage, any new stage
		if (
			$automation_pipeline_id !== $this->Default_Value_Pipeline &&
			$automation_old_stage_id !== $this->Default_Value_Stage &&
			$automation_new_stage_id === $this->Default_Value_Stage
		) {
			return $deal->pipeline_id == $automation_pipeline_id &&
				$old_stage_id == $automation_old_stage_id;
		}

		// Case 4: {"pipeline":"value","old_stage":"any-stage","new_stage":"value"}
		// Specific pipeline, any old stage, specific new stage
		if (
			$automation_pipeline_id !== $this->Default_Value_Pipeline &&
			$automation_old_stage_id === $this->Default_Value_Stage &&
			$automation_new_stage_id !== $this->Default_Value_Stage
		) {
			return $deal->pipeline_id == $automation_pipeline_id &&
				$new_stage_id == $automation_new_stage_id;
		}

		// Case 5: {"pipeline":"value","old_stage":"value","new_stage":"value"}
		// All conditions are specific values
		if (
			$automation_pipeline_id !== $this->Default_Value_Pipeline &&
			$automation_old_stage_id !== $this->Default_Value_Stage &&
			$automation_new_stage_id !== $this->Default_Value_Stage
		) {
			return $deal->pipeline_id == $automation_pipeline_id &&
				$old_stage_id == $automation_old_stage_id &&
				$new_stage_id == $automation_new_stage_id;
		}
		return false;
	}

	/**
	 * Deal Stage Changed
	 *
	 * @since 1.0.0
	 *
	 * @param DealModel    $deal Deal Model
	 * @param ContactModel $contact Contact Model
	 * @param int          $old_stage_id Old Stage ID
	 * @param int          $new_stage_id New Stage ID
	 *
	 * @return void
	 */
	public function deal_stage_changed( $contact, $deal, $old_stage_id, $new_stage_id ) {
		$data = array(
			'contact' => $contact,
			'deal'    => $deal,
			'data'    => array(
				'old_stage_id' => $old_stage_id,
				'new_stage_id' => $new_stage_id,
				'deal_id'      => $deal->id,
			),
		);

		$this->process( $data );
	}


	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'pipeline'  => array(
				'label'         => \__( 'Pipeline', 'doublescale' ),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipelines',
				'multiple'      => false,
				'default-value' => $this->Default_Value_Pipeline,
			),
			'old_stage' => array(
				'label'         => \__( 'Old Stage', 'doublescale' ),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipeline-stages',
				'multiple'      => false,
				'default-value' => $this->Default_Value_Stage,
			),
			'new_stage' => array(
				'label'         => \__( 'New Stage', 'doublescale' ),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipeline-stages',
				'multiple'      => false,
				'default-value' => $this->Default_Value_Stage,
			),
		);
	}

	/**
	 * Get Attributes Schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'pipeline_id'  => array(
					'type'     => 'integer',
					'required' => true,
				),
				'old_stage_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
				'new_stage_id' => array(
					'type'     => 'integer',
					'required' => true,
				),
			),
		);
	}
}
