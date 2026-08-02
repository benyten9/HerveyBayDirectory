<?php

/**
 * Class Deal Value
 *
 * This class is responsible for handling the Deal Value rule
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
 * Deal Value class
 */
class DealValue extends Rule
{
	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Deal Value';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'deal_value';

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
	public $type = 'number';

    /**
     * Get Operators
     * 
     * @since 1.0.0
     *
     * @return array
     */
    public function get_operators()
    {
        return array(
            'equal_to' => __( 'Equal to', 'doublescale'),
            'not_equal_to' => __( 'Not equal to', 'doublescale'),
            'greater_than' => __( 'Greater than', 'doublescale'),
            'less_than' => __( 'Less than', 'doublescale'),
            'greater_than_or_equal_to' => __( 'Greater than or equal to', 'doublescale'),
            'less_than_or_equal_to' => __( 'Less than or equal to', 'doublescale'),
        );
    }
	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Deal Model.
	 *
	 * @return mixed
	 */
	public function get_value($automation_contact)
	{
        $deal_id = $automation_contact->data['deal_id'] ?? null;
        if ( ! $deal_id ) {
            return 0;
        }
        $deal = DealModel::find($deal_id);
        if ( ! $deal ) {
            return 0;
        }
        return $deal->value ?? 0;
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
        $value = $this->get_value($automation_contact);
        $operator = $rule['operator'];
        $rule_value = $rule['value'];

        switch ($operator) {
            case 'equal_to':
                return $value == $rule_value;
            case 'not_equal_to':
                return $value != $rule_value;
            case 'greater_than':
                return $value > $rule_value;
            case 'less_than':
                return $value < $rule_value;
            case 'greater_than_or_equal_to':
                return $value >= $rule_value;
            case 'less_than_or_equal_to':
                return $value <= $rule_value;
            default:
                return false;   
        }
    }
}

DealRuleRegistration::register(new DealValue());
