<?php

/**
 * Class WasActiveInactive
 *
 * This class is responsible for handling the was active inactive rule
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
use DoubleScale\Modules\Activities\Models\ActivityModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;
use DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel;

/**
 * WasActiveInactive class
 */
class WasActiveInactive extends Rule
{
    use TimeframeFilter;

    /**
     * Name
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $name = '';

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
    public $type = 'was_active_inactive';

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
    public function __construct($name, $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
    }


    /**
     * Get value
     *
     * Get the count of all activities by this contact across multiple tables
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

        $total_count = 0;

        // 1. Check Activity Model (logins, logouts, notes, emails, calls, etc.)
        if ( ! function_exists( 'doublescale_is_module_storage_ready' )
            || doublescale_is_module_storage_ready( 'activities', ActivityModel::class ) ) {
            $activity_query = ActivityModel::query()
                ->whereHas(
                    'associations',
                    function ( $q ) use ( $contact ) {
                        $q->where( 'entity_type', \DoubleScale\Modules\Activities\Models\ActivityAssociationModel::ENTITY_TYPE_CONTACT )
                            ->where( 'entity_id', $contact->id );
                    }
                );

            // Apply timeframe filter if provided
            if (! empty($timeframe_data) && isset($timeframe_data['type'])) {
                $activity_query = $this->apply_timeframe_filter($activity_query, $timeframe_data, 'created_at');
            }

            $total_count += $activity_query->count();
        }

        // 2. Check Form Submission Model (form submissions)
        if ( ! function_exists( 'doublescale_is_module_storage_ready' )
            || doublescale_is_module_storage_ready( 'forms', FormModel::class ) ) {
            $form_submission_query = FormSubmissionModel::where('contact_id', $contact->id);

            // Apply timeframe filter if provided
            if (! empty($timeframe_data) && isset($timeframe_data['type'])) {
                $form_submission_query = $this->apply_timeframe_filter($form_submission_query, $timeframe_data, 'created_at');
            }

            $total_count += $form_submission_query->count();
        }

        // 3. Check Page Visit Model (website page visits)
        if ( ! function_exists( 'doublescale_is_module_storage_ready' )
            || doublescale_is_module_storage_ready( 'websitetracking', PageVisitModel::class ) ) {
            $page_visit_query = PageVisitModel::where('contact_id', $contact->id);

            // Apply timeframe filter if provided
            if (! empty($timeframe_data) && isset($timeframe_data['type'])) {
                $page_visit_query = $this->apply_timeframe_filter($page_visit_query, $timeframe_data, 'created_at');
            }

            $total_count += $page_visit_query->count();
        }

        return $total_count;
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

        if ($this->slug === 'activity_was_not_active') {
            return $actual_count <= 0;
        } else {
            return $actual_count > 0;
        }
    }
}

RulesManager::instance()->register(new WasActiveInactive('Was Active', 'activity_was_active'));
RulesManager::instance()->register(new WasActiveInactive('Was Not Active', 'activity_was_not_active'));
