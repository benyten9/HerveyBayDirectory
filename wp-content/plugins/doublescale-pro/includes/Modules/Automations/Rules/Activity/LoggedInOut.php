<?php

/**
 * Class LoggedInOut
 *
 * This class is responsible for handling the logged in out rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Activity;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Automations\Traits\TimeframeFilter;
use DoubleScale\Modules\Automations\Traits\EventCountConditionFilter;
use DoubleScale\Modules\Activities\Models\ActivityModel;

/**
 * LoggedInOut class
 */
class LoggedInOut extends Rule
{
    use TimeframeFilter;
    use EventCountConditionFilter;

    /**
     * Name
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $name = '';


    /*
    * Type
    *
    * @var string
    *
    * @since 1.0.0
    */
    public $activity_type = '';

    /**
     * Slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $slug = '';

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
    public $type = 'logged_in_out';

    /*
	* Is automation rule
	*
	* @var bool
	*
	* @since 1.0.0
	*/
    public $is_automation = false;


    /**
     * Constructor
     *
     * @since 1.0.0
     */
    public function __construct($name, $activity_type, $slug)
    {
        $this->name = $name;
        $this->activity_type = $activity_type;
        $this->slug = $slug;
    }


    /**
     * Get value
     *
     * Get the count of logged in by this contact
     *
     * @since 1.0.0
     *
     * @param AutomationContactModel $automation_contact Contact Model.
     * @param array                    $timeframe_data Timeframe data.
     * @param string                   $page_guid Optional page GUID to filter by.
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
        $query = ActivityModel::query()
            ->whereHas(
                'associations',
                function ( $q ) use ( $contact ) {
                    $q->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
                        ->where( 'entity_id', $contact->id );
                }
            )
            ->where('activity_type', $this->activity_type);

        // Apply timeframe filter if provided
        if (! empty($timeframe_data) && isset($timeframe_data['type'])) {
            $query = $this->apply_timeframe_filter($query, $timeframe_data, 'created_at');
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
        return array();
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
        return array();
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
        // Extract timeframe data if available
        $timeframe_data = array();
        if (isset($rule['value']) && is_array($rule['value']) && isset($rule['value']['timeframe'])) {
            $timeframe_data = $rule['value']['timeframe'];
        } else {
            $timeframe_data = array(
                'type' => 'at_any_time',
            );
        }

        // Get the actual count with timeframe filter applied
        $actual_count = $this->get_value($automation_contact, $timeframe_data);

        // Extract event count condition if available
        $event_count_condition = $rule['value']['event_count_condition'] ?? array();

        return $this->check_event_count_condition($actual_count, $event_count_condition);
    }
}

RulesManager::instance()->register(new LoggedInOut('Logged In', 'logged_in', 'activity_logged_in'));
RulesManager::instance()->register(new LoggedInOut('Logged Out', 'logged_out', 'activity_logged_out'));
