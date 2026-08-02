<?php

namespace DoubleScale\Pro\Modules\Automations\Actions\Deal;

use DoubleScale\Modules\Activities\Services\ActivityManager;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

class AddNoteDeal extends BaseDealAction
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Add a deal note';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'add_note_deal';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will add a note to a deal.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'sales';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'deal';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		// Resolve merge tags in the note content
		$note  = $this->parse_deal_title($step->get_setting('note'), $automation_contact);
		$deals = $this->build_target_deals_query(
			array(
				'affects'  => $step->get_setting('affects'),
				'pipeline' => $step->get_setting('pipeline'),
			),
			$automation_contact
		)->get();
		foreach ($deals as $deal) {
			ActivityManager::instance()->add_note(
				array(
					'entity_id'   => $deal->id,
					'entity_type' => \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_DEAL,
					'contact_id' => $deal->contact_id,
					'content'    => $note,
				)
			);
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
			'note'     => array(
				'label' => $this->t('Deal Note'),
				'type'  => 'textarea',
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
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'note'     => array(
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
