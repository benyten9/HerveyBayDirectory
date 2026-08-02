<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;


use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

// Use global function via fully-qualified call when needed.

class UpdateStatusDeal extends BaseDealAction
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update a deal status';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_status_deal';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the status of a deal.';


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
		$status = $step->get_setting('status');
		$deals  = $this->build_target_deals_query(
			array(
				'affects'  => $step->get_setting('affects'),
				'pipeline' => $step->get_setting('pipeline'),
			),
			$automation_contact
		)->get();

		foreach ($deals as $deal) {
			$deal->status = $status;
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
			'status'   => array(
				'label'   => $this->t('Deal Status'),
				'type'    => 'select',
				'options' => array(
					'open' => $this->t('Open'),
					'won'  => $this->t('Won'),
					'lost' => $this->t('Lost'),
				),
			),
			'affects'  => array(
				'label'   => $this->t('Affects'),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t(
					'
When this automation is triggered, we will use this configuration to decide which deal(s) to update for
the given contact.'
				),
			),
			'pipeline' => array(
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
				'status'   => array(
					'type'     => 'string',
					'required' => true,
				),
				'affects'  => array(
					'type'     => 'string',
					'required' => true,
				),
				'pipeline' => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
