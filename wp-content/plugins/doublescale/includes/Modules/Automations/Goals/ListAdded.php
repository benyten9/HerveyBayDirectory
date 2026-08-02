<?php

/**
 * Class List Added Goal
 *
 * This class is responsible for handling the list added goal
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
 * List Added Goal class
 */
class ListAdded extends Goal {

	/**
	 * Goal Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'List Added';

	/**
	 * Goal Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'list_added';

	/**
	 * Goal Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'This goal is achieved when a contact is added to a specific list.';

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
		add_action( 'doublescale_contact_list_apply', array( $this, 'lists_applied' ), 10, 2 );
	}

	/**
	 * Lists Applied
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact_id Contact.
	 * @param array        $lists List ID.
	 *
	 * @return void
	 */
	public function lists_applied( $contact, $lists ) {
		$data = array(
			'lists' => $lists,
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
		$lists = $data['lists'] ?? array();

		// Get the current step model
		$current_step = AutomationStepModel::find( $automation_contact->current_step );
		if ( ! $current_step ) {
			return false;
		}

		$goal_lists = $current_step->get_setting( 'lists', array() );

		return ! empty( array_intersect( $lists, $goal_lists ) );
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
			'lists' => array(
				'label'    => __( 'Lists', 'doublescale' ),
				'type'     => 'lists',
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

GoalsManager::instance()->register( new ListAdded() );
