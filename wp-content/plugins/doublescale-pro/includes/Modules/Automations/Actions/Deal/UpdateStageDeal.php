<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;


use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;


// Use global function via fully-qualified call when needed.

class UpdateStageDeal extends BaseDealAction
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update a deal stage';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_stage_deal';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the stage of a deal.';


	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();


	/**
	 * Action Source
	 *
	 * @var string
	 */
	public $source = 'sales';


	/**
	 * Action Group
	 *
	 * @var string
	 */
	public $group = 'deal';



	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		$old_pipeline = $step->get_setting('old_pipeline');
		$new_stage    = $step->get_setting('new_stage');
		$new_pipeline = $step->get_setting('pipeline');

		$deals = $this->build_target_deals_query(
			array(
				'affects'      => $step->get_setting('affects'),
				// For the filtering of "old" pipeline we must use 'old_pipeline' key
				// instead of the default 'pipeline'.
				'old_pipeline' => $old_pipeline,
			),
			$automation_contact,
			'old_pipeline'
		)->get();

		foreach ($deals as $deal) {
			$deal->pipeline_id = $new_pipeline;
			$deal->stage_id    = $new_stage;
			$deal->save();
		}

		return true;
	}



	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'pipeline'     => array(
				'label'         => $this->t('New pipeline'),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipelines',
				'multiple'      => false,
				'default-value' => '',
			),
			'new_stage'    => array(
				'label'         => $this->t('New stage'),
				'type'          => 'pipeline_stage_change',
				'endpoint'      => 'pipeline-stages',
				'multiple'      => false,
				'default-value' => '',
			),
			'affects'      => array(
				'label'   => $this->t('Affects'),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t(
					'
When this automation is triggered, we will use this configuration to decide which deal(s) to update for
the given contact.'
				),
			),
			'old_pipeline' => array(
				'label'   => $this->t('Pipeline'),
				'type'    => 'select',
				'options' => $this->get_pipelines_options(),
			),
		);
	}

	/**
	 * Get users options
	 *
	 * @return array
	 */
	public function get_pipelines_options()
	{
		return parent::get_pipelines_options();
	}

	/**
	 * Get effects options
	 *
	 * @return array
	 */
	public function get_effects_options()
	{
		return parent::get_effects_options();
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'pipeline'     => array(
					'type'     => 'integer',
					'required' => true,
				),
				'new_stage'    => array(
					'type'     => 'integer',
					'required' => true,
				),
				'affects'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'old_pipeline' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
