<?php

/**
 * Class Deal Expected Close Date
 *
 * This class is responsible for handling the Deal Expected Close Date rule
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
 * Deal Expected Close Date class
 */
class DealExpectedCloseDate extends Rule
{

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Deal Expected Close Date';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'deal_expected_close_date';

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
	public $type = 'date';

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
			'before'     => __('Before', 'doublescale'),
			'after'      => __('After', 'doublescale'),
			'on'         => __('On', 'doublescale'),
			'is_not_set' => __('Is not set', 'doublescale'),
		);
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
		return $deal->expected_close_date ?? '';
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

		if ('is_not_set' === $operator) {
			return empty($value);
		}

		if (empty($value)) {
			return false;
		}

		try {
			$deal_date = new \DateTime($value);
			$rule_date = new \DateTime($rule_value);

			switch ($operator) {
				case 'before':
					return $deal_date < $rule_date;
				case 'after':
					return $deal_date > $rule_date;
				case 'on':
					return $deal_date->format('Y-m-d') === $rule_date->format('Y-m-d');
				default:
					return false;
			}
		} catch (\Exception $e) {
			return false;
		}
	}
}

DealRuleRegistration::register(new DealExpectedCloseDate());
