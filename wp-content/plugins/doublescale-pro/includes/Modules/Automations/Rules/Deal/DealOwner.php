<?php

/**
 * Class Deal Owner
 *
 * This class is responsible for handling the Deal Owner rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Deal;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Pro\Modules\Deals\Models\DealModel;

/**
 * Deal Owner class
 */
class DealOwner extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Deal Owner';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'deal_owner';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'deal';

	/**
	 * Is automation rule
	 *
	 * @var boolean
	 *
	 * @since 1.0.0
	 */
	public $is_automation = true;

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
	public function get_operators()
	{
		return array(
			'is'         => __('Is', 'doublescale'),
			'is_not'     => __('Is not', 'doublescale'),
			'is_not_set' => __('Is not set', 'doublescale'),
		);
	}

	/**
	 * Get options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options()
	{
		$users   = get_users(array(
			'fields' => array('ID', 'user_email')
		));

		$options = array();

		foreach ($users as $user) {
			$options[$user->ID] = $user->user_email;
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
	public function get_value($automation_contact)
	{
		$deal_id = $automation_contact->data['deal_id'] ?? null;
		if (! $deal_id) {
			return '';
		}
		$deal = DealModel::find($deal_id);
		if (! $deal) {
			return '';
		}
		return $deal->owner_id ?? '';
	}

	/**
	 * Is met
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 * @param array                    $rule Rule.
	 *
	 * @return bool
	 */
	public function is_met(AutomationContactModel $automation_contact, $rule = array())
	{
		$value      = $this->get_value($automation_contact);
		$operator   = $rule['operator'];
		$rule_value = $rule['value'] ?? '';

		switch ($operator) {
			case 'is':
				return $value == $rule_value;
			case 'is_not':
				return $value != $rule_value;
			case 'is_not_set':
				return empty($value);
			default:
				return false;
		}
	}
}

DealRuleRegistration::register(new DealOwner());
