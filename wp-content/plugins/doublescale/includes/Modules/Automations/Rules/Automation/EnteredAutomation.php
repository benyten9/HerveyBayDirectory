<?php

/**
 * Class EnteredAutomation
 *
 * This class is responsible for handling the entered automation rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\Automation;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * EnteredAutomation class
 */
class EnteredAutomation extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Entered Automation';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'automation_entered';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'automation';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'select';

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @param string $automation_name The automation name
	 *
	 * @return array
	 */
	public function get_options( $automation_name = '' ) {
		$options = array();

		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'automations', AutomationModel::class ) ) {
			return $options;
		}

		if ( '' === $automation_name ) {
			$automations = AutomationModel::paginate( 10, array( '*' ), 'page', 1 );
		} else {
			$automations = AutomationModel::where( 'name', 'LIKE', '%' . $automation_name . '%' )->paginate( 10, array( '*' ), 'page', 1 );
		}

		foreach ( $automations as $automation ) {
			$options[ $automation->id ] = $automation->name;
		}

		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		return $contact->id;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'any'  => __( 'Match any', 'doublescale' ),
			'all'  => __( 'Match all', 'doublescale' ),
			'none' => __( 'Match none', 'doublescale' ),
		);
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                  $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$value      = $this->get_value( $automation_contact );
		$operator   = $rule['operator'];
		$rule_value = $rule['value'];

		switch ( $operator ) {
			case 'any':
				return $this->is_any_met( $value, $rule_value );
			case 'all':
				return $this->is_all_met( $value, $rule_value );
			case 'none':
				return $this->is_none_met( $value, $rule_value );
		}
	}

	/**
	 * Check if any of the rules are met
	 *
	 * @since 1.0.0
	 *
	 * @param int   $contact_id The contact id
	 * @param array $automation_ids The automation ids
	 *
	 * @return bool
	 */
	private function is_any_met( $contact_id, $automation_ids ) {
		$automations = AutomationContactModel::where( 'contact_id', $contact_id )
			->whereIn( 'automation_id', $automation_ids )
			->get();

		return $automations->count() > 0;
	}

	/**
	 * Check if all of the rules are met
	 *
	 * @since 1.0.0
	 *
	 * @param int   $contact_id The contact id
	 * @param array $automation_ids The automation ids
	 *
	 * @return bool
	 */
	private function is_all_met( $contact_id, $automation_ids ) {
		$automations = AutomationContactModel::where( 'contact_id', $contact_id )
			->whereIn( 'automation_id', $automation_ids )
			->get();

		return $automations->count() === count( $automation_ids );
	}

	/**
	 * Check if none of the rules are met
	 *
	 * @since 1.0.0
	 *
	 * @param int   $contact_id The contact id
	 * @param array $automation_ids The automation ids
	 *
	 * @return bool
	 */
	private function is_none_met( $contact_id, $automation_ids ) {
		$automations = AutomationContactModel::where( 'contact_id', $contact_id )
			->whereIn( 'automation_id', $automation_ids )
			->get();

		return $automations->count() === 0;
	}
}

RulesManager::instance()->register( new EnteredAutomation() );
