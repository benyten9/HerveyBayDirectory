<?php

/**
 * Class TagsRemoved
 *
 * This class is responsible for handling the tags removed trigger
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
 * Class Tags Removed Trigger
 */
class TagsRemoved extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Tags Removed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'tags_removed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a tag is removed to a contact.';

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
		add_action('doublescale_contact_tag_remove', array($this, 'tags_removed'), 10, 2);
	}

	/**
	 * Tags Removed
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact
	 * @param array         $tags
	 *
	 * @return void
	 */
	public function tags_removed(ContactModel $contact, $tags)
	{
		$data = array(
			'contact' => $contact,
			'data'    => array(
				'tags' => $tags,
			),
		);

		$this->process($data);
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation
	 * @param array            $args
	 *
	 * @return bool
	 */
	public function is_processable(AutomationModel $automation, $args)
	{
		$tags            = $args['data']['tags'];
		$automation_tags = $automation->get_setting('tags', array());

		if (! array_intersect($tags, $automation_tags)) {
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
			'tags' => array(
				'label'    => __('Tags', 'doublescale'),
				'type'     => 'tags',
				'multiple' => true,
			),
		);
	}

	/**
	 * Get attributes schema
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
				'tags' => array(
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
