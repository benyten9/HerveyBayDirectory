<?php

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Is Customer class
 */
class IsCustomer extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Is customer ?';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'is_customer';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'woocommerce';

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'select';

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'equal' => __( 'equal', 'doublescale' ),
		);
	}


	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		return array(
			'yes' => __( 'Yes', 'doublescale' ),
			'no'  => __( 'No', 'doublescale' ),
		);
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return bool
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;

		if ( ! $contact || empty( $contact->email ) ) {
			return false;
		}

		$user = get_user_by( 'email', $contact->email );

		return $user;
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
		$is_customer = $this->get_value( $automation_contact );
		$operator    = $rule['operator'];
		$rule_value  = $rule['value'] ?? '';

		switch ( $operator ) {
			case 'equal':
				if ( $rule_value === 'yes' ) {
					return $is_customer === true;
				} elseif ( $rule_value === 'no' ) {
					return $is_customer === false;
				}
				return false;
			default:
				return false;
		}
	}
}

add_action(
	'init',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			RulesManager::instance()->register( new IsCustomer() );
		} else {
			add_action(
				'woocommerce_loaded',
				function () {
					RulesManager::instance()->register( new IsCustomer() );
				}
			);
		}
	},
	99
);
