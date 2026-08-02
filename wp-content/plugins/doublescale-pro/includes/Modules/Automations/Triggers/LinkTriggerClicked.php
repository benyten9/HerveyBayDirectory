<?php

/**
 * Class Link Trigger Clicked
 *
 * This trigger will be fired when a link trigger is clicked.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\LinkTriggers\Models\LinkTriggerModel;

/**
 * Link Trigger Clicked
 */
class LinkTriggerClicked extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Link Trigger Clicked';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'link_trigger_clicked';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a link trigger is clicked.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'link_triggers';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('doublescale_link_trigger_clicked', array($this, 'link_trigger_clicked'), 10, 2);
	}

	/**
	 * Link Trigger Clicked
	 *
	 * @param LinkTriggerModel $link_trigger Link Trigger.
	 * @param ContactModel      $contact Contact.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function link_trigger_clicked($link_trigger, $contact)
	{
		$data = array(
			'contact' => $contact,
			'data'    => array(
				'link_trigger_id' => $link_trigger->id,
			),
		);

		$this->process($data);
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model.
	 * @param array            $args Arguments.
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$link_id          = $args['data']['link_trigger_id'];
		$automation_links = $automation->get_setting('links', array());

		if (! in_array($link_id, $automation_links)) {
			return false;
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
			'links' => array(
				'type'        => 'api_select',
				'label'       => __('Links', 'doublescale'),
				'endpoint'    => 'doublescale/v1/link-triggers',
				'placeholder' => __('Select Links', 'doublescale'),
				'multiple'    => true,
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
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'links' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'integer',
					),
					'required' => true,
				),
			),
		);
	}
}
