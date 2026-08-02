<?php

/**
 * Class PageVisited
 *
 * This class is responsible for handling the page visited rule
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
use DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel;

/**
 * PageVisited class
 */
class PageVisited extends Rule
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
    public $name = 'Page Visited';

    /**
     * Slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $slug = 'activity_page_visited';

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
    public $type = 'page_visited';

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
     * Get the count of pages visited by this contact
     *
     * @since 1.0.0
     *
     * @param AutomationContactModel $automation_contact Contact Model.
     * @param array                    $timeframe_data Timeframe data.
     * @param string                   $page_guid Optional page GUID to filter by.
     *
     * @return int
     */
    public function get_value($automation_contact, $timeframe_data = array(), $page_guid = '')
    {
        $contact = $automation_contact->contact;

        if (! $contact || ! $contact->exists) {
            return 0;
        }

        // Start building the query
        $query = PageVisitModel::where('contact_id', $contact->id);

        // Filter by specific page if guid is provided
        if (! empty($page_guid)) {
            // Convert full URL to path for comparison
            $path = wp_parse_url($page_guid, PHP_URL_PATH);
            $query_string = wp_parse_url($page_guid, PHP_URL_QUERY);
            $query->where('path', $path);
            if (! empty($query_string)) {
                $query->where('query', $query_string);
            }
        }

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
        if ( ! isset( $GLOBALS['wp_rewrite'] ) ) {
            global $wp_rewrite;
            $GLOBALS['wp_rewrite'] = $wp_rewrite ?? new \WP_Rewrite();
        }

        $pages   = get_pages();
        $options = array();

        foreach ($pages as $page) {
            $permalink = get_permalink($page->ID);
            $options[$permalink] = empty($page->post_title) ? '(Page)' : $page->post_title;
        }

        return $options;
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
        // Extract the page guid if provided
        $page_guid = '';
        if (isset($rule['value']) && is_array($rule['value']) && isset($rule['value']['guid'])) {
            $page_guid = $rule['value']['guid'];
        }

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
        $actual_count = $this->get_value($automation_contact, $timeframe_data, $page_guid);

        // Extract event count condition if available
        $event_count_condition = $rule['value']['event_count_condition'] ?? array();


        return $this->check_event_count_condition($actual_count, $event_count_condition);
    }
}

RulesManager::instance()->register(new PageVisited());
