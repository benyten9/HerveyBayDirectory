<?php

/**
 * Class Tags Added Goal
 *
 * This class is responsible for handling the tag added goal
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DoubleScale\Modules\Automations\Abstracts\Goal;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;

/**
 * Tags Added Goal class
 */
class TagsAdded extends Goal {

	/**
	 * Goal Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Tags Added';

	/**
	 * Goal Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'tag_added';

	/**
	 * Goal Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'This goal is achieved when a contact is added to a specific tag.';

	/**
	 * Source
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $source = 'automation';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'contact';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_contact_tag_apply', array( $this, 'tags_applied' ), 10, 2 );
	}

	/**
	 * Tags Applied
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact
	 * @param array        $tags
	 *
	 * @return void
	 */
	public function tags_applied( ContactModel $contact, $tags ) {
		$data = array(
			'tags' => $tags,
		);

		$this->process( $contact, $data );
	}

	/**
	 * Check if the goal is completed
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Automation Contact Model.
	 * @param array                  $data Data.
	 *
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		$tags = $data['tags'] ?? array();

		// Get the current step model
		$current_step = AutomationStepModel::find( $automation_contact->current_step );
		if ( ! $current_step ) {
			return false;
		}

		$goal_tags = $current_step->get_setting( 'tags', array() );

		return ! empty( array_intersect( $tags, $goal_tags ) );
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
			'tags' => array(
				'label'    => __( 'Tags', 'doublescale' ),
				'type'     => 'tags',
				'multiple' => true,
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
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

GoalsManager::instance()->register( new TagsAdded() );
