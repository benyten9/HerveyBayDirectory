<?php

namespace DoubleScale\Pro\Modules\Automations\Triggers\Deal;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Deal Owner Change Trigger
 */
class DealOwnerChange extends Trigger {

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Deal Owner changes';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'deal_owner_change';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when a deal owner is changed.';

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

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_automation_deal_owner_changed', array( $this, 'deal_owner_changed' ), 10, 4 );
	}

	/**
	 * Deal Owner Changed
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact Model
	 * @param DealModel    $deal Deal Model
	 * @param int|string   $old_owner Old Owner ID
	 * @param int|string   $new_owner New Owner ID
	 */
	public function deal_owner_changed( $contact, $deal, $old_owner_id, $new_owner_id ) {

		$data = array(
			'contact' => $contact,
			'deal'    => $deal,
			'data'    => array(
				'old_owner_id' => $old_owner_id,
				'new_owner_id' => $new_owner_id,
				'deal_id'      => $deal->id,
			),
		);
		$this->process( $data );
	}

	/**
	 * Is Processable
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel $automation Automation Model
	 * @param array           $args Arguments
	 *
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$automation_from_owner = $automation->get_setting( 'from' ) ?? array();
		$automation_to_owner   = $automation->get_setting( 'to' ) ?? array();
		$old_owner             = (string) ( $args['data']['old_owner_id'] ?? '' );
		$new_owner             = (string) ( $args['data']['new_owner_id'] ?? '' );

		// If owner didn't actually change, don't process
		if ( $old_owner === $new_owner ) {
			return false;
		}

		// Check 'from' condition
		if ( ! empty( $automation_from_owner ) && ! $this->check_owner_condition( $automation_from_owner, $old_owner ) ) {
			return false;
		}

		// Check 'to' condition
		if ( ! empty( $automation_to_owner ) && ! $this->check_owner_condition( $automation_to_owner, $new_owner ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if an owner meets the specified condition
	 *
	 * @since 1.0.0
	 *
	 * @param array  $condition_config The condition configuration
	 * @param string $owner_id The owner ID to check
	 *
	 * @return bool
	 */
	private function check_owner_condition( $condition_config, $owner_id ) {
		$condition       = $condition_config['condition'] ?? '';
		$target_owner_id = (string) ( $condition_config['owner_id'] ?? '' );

		// If condition is 'any-value', always return true
		if ( 'any-value' === $condition ) {
			return true;
		}

		// Check specific conditions
		switch ( $condition ) {
			case 'equal_to':
				return $owner_id === $target_owner_id;
			case 'not_equal_to':
				return $owner_id !== $target_owner_id;
			default:
				return true; // Default to true for unknown conditions
		}
	}


	public function get_fields() {
		return array(
			'from' => array(
				'type'  => 'deal_owner_change',
				'label' => __( 'From', 'doublescale' ),
			),
			'to'   => array(
				'type'  => 'deal_owner_change',
				'label' => __( 'To', 'doublescale' ),
			),
		);
	}

	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'from' => array(
					'type'       => 'object',
					'properties' => array(
						'condition' => array(
							'type'     => 'string',
							'required' => true,
						),
						'owner_id'  => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				'to'   => array(
					'type'       => 'object',
					'properties' => array(
						'condition' => array(
							'type'     => 'string',
							'required' => true,
						),
						'owner_id'  => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			),
		);
	}
}
