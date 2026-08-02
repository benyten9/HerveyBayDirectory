<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;


use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

// Use global function via fully-qualified call when needed.

class UpdateValueDeal extends BaseDealAction
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Update a deal value';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'update_value_deal';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will update the value of a deal.';


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
		$value_setting = $step->get_setting('value_setting');
		$value         = $this->parse_deal_value($step->get_setting('value'), $automation_contact);
		$deals         = $this->build_target_deals_query(
			array(
				'affects'  => $step->get_setting('affects'),
				'pipeline' => $step->get_setting('pipeline'),
			),
			$automation_contact
		)->get();

		foreach ($deals as $deal) {
			$currentValue = (float) $deal->value;
			$newValue     = 0;
			if ($value_setting === 'set-value-deal') {
				$newValue = $value;
			} elseif ($value_setting === 'add-value-deal') {
				$newValue = $currentValue + $value;
			} elseif ($value_setting === 'subtract-value-deal') {
				$newValue = $currentValue - $value;
				if ($newValue < 0) {
					$newValue = 0;
				}
			}
			$deal->value = $newValue;
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
			'value_setting' => array(
				'label'   => $this->t('Deal value setting'),
				'type'    => 'select',
				'options' => array(
					'set-value-deal'      => $this->t('Set the deal value to'),
					'add-value-deal'      => $this->t('Increase the deal value by'),
					'subtract-value-deal' => $this->t('Decrease the deal value by'),
				),
			),
			'value'         => array(
				'label' => $this->t('Deal Value'),
				'type'  => 'text',
			),
			'affects'       => array(
				'label'   => $this->t('Affects'),
				'type'    => 'select',
				'options' => $this->get_effects_options(),
				'tooltip' => $this->t(
					'
When this automation is triggered, we will use this configuration to decide which deal(s) to update for
the given contact.'
				),
			),
			'pipeline'      => array(
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
				'value_setting' => array(
					'type'     => 'string',
					'required' => true,
				),
				'value'         => array(
					'type'     => 'text',
					'required' => true,
				),
				'affects'       => array(
					'type'     => 'string',
					'required' => true,
				),
				'pipeline'      => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);
	}
}
