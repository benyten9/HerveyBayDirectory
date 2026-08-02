<?php

/**
 * Lists Applied Trigger
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;


/**
 * Class Lists Applied Trigger
 */
class ListsApplied extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Lists Applied';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'lists_applied';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a list is applied to a contact.';

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
	public $group = 'contact';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('doublescale_contact_list_apply', array($this, 'lists_applied'), 10, 2);
	}

	/**
	 * Lists Applied
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact_id Contact.
	 * @param array         $lists List ID.
	 *
	 * @return void
	 */
	public function lists_applied($contact, $lists)
	{
		$data = array(
			'contact' => $contact,
			'data'    => array(
				'lists' => $lists,
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
		$lists            = $args['data']['lists'];
		$automation_lists = $automation->get_setting('lists', array());

		// Check if any of the lists match
		if (! array_intersect($lists, $automation_lists)) {
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
			'lists' => array(
				'label'    => __('Lists', 'doublescale'),
				'type'     => 'lists',
				'multiple' => true,
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
				'lists' => array(
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
