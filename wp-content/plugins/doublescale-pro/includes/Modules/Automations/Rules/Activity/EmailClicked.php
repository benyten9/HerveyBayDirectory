<?php

/**
 * Class EmailOpened
 *
 * This class is responsible for handling the email opened rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Activity;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Automations\Traits\TimeframeFilter;

/**
 * EmailClicked class
 */
class EmailClicked extends Rule
{
    use TimeframeFilter;

    /**
     * Name
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $name = 'Email Clicked';

    /**
     * Slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $slug = 'activity_email_clicked';

    /**
     * Group
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $group = 'activity';

    /**
     * Type
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $type = 'email_clicked';

    /*
    * Is automation rule
    *
    * @var bool
    *
    * @since 1.0.0
    */
    public $is_automation = false;


    /**
     * Get value
     *
     * Get the count of emails clicked by this contact
     *
     * @since 1.0.0
     *
     * @param AutomationContactModel $automation_contact Contact Model.
     * @param array                    $timeframe_data Timeframe data.
     *
     * @return int
     */
    public function get_value($automation_contact, $timeframe_data = array())
    {
        $contact = $automation_contact->contact;

        if (! $contact || ! $contact->exists) {
            return 0;
        }

        // Start building the query
        $query = CommunicationTrackingModel::where('contact_id', $contact->id)
            ->where('mode', CommunicationTrackingModel::MODE_EMAIL)
            ->where('clicked', 1);

        // Apply timeframe filter if provided
        if (!empty($timeframe_data) && isset($timeframe_data['type'])) {
            $query = $this->apply_timeframe_filter($query, $timeframe_data, 'clicked_at');
        }

        $count = $query->count();

        return $count;
    }

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
            'is' => 'Is',
            'is_not' => 'Is not',
            'greater_than' => 'Greater than',
            'lower_than' => 'Lower than',
            'lower_than_or_equal_to' => 'Lower than or equal to',
            'greater_than_or_equal_to' => 'Greater than or equal to',
        );
    }

    /**
     * Is met
     *
     * Check if the rule condition is met
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
        // Extract the count value to compare
        $rule_value = 0;
        if (isset($rule['value'])) {
            if (is_array($rule['value']) && isset($rule['value']['count'])) {
                $rule_value = intval($rule['value']['count']);
            } else {
                $rule_value = intval($rule['value']);
            }
        }

        // Extract timeframe data if available
        $timeframe_data = array();
        if (isset($rule['value']) && is_array($rule['value']) && isset($rule['value']['timeframe'])) {
            $timeframe_data = $rule['value']['timeframe'];
        }

        // Get the actual count with timeframe filter applied
        $value = $this->get_value($automation_contact, $timeframe_data);

        $operator = isset($rule['operator']) ? $rule['operator'] : 'is';

        switch ($operator) {
            case 'is':
                return $value === $rule_value;
            case 'is_not':
                return $value !== $rule_value;
            case 'greater_than':
                return $value > $rule_value;
            case 'lower_than':
                return $value < $rule_value; 
            case 'lower_than_or_equal_to':
                    return $value <= $rule_value;
            case 'greater_than_or_equal_to':
                    return $value >= $rule_value;
            default:
                return false;
        }
    }
}

RulesManager::instance()->register(new EmailClicked());
