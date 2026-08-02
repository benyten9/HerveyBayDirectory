<?php

/**
 * Class Form Submission
 *
 * This class is responsible for handling the form submission rule
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Submission;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Forms\Models\FormSubmissionModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Modules\Automations\Traits\TimeframeFilter;

/**
 * Form Submission class
 */
class FormSubmission extends Rule
{
    use TimeframeFilter;

    /**
     * Name
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $name = 'Form Submission';

    /**
     * Slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $slug = 'form_submission';

    /**
     * Group
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $group = 'submission';

    /**
     * Type
     *
     * @var string
     *
     * @since 1.0.0
     */
    public $type = 'form_submission';

    /**
     * Is automation rule
     *
     * @var bool
     *
     * @since 1.0.0
     */
    public $is_automation = false;

    /**
     * Get options
     *
     * @since 1.0.0
     *
     * @return array
     */
    public function get_options()
    {
        $options = array();

        if ( ! AutomationModuleStorage::is_ready( 'forms', FormModel::class ) ) {
            return $options;
        }

        $forms = FormModel::all();

        foreach ($forms as $form) {
            $options[$form->id] = $form->name;
        }

        return $options;
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
     * Get value
     *
     * @since 1.0.0
     *
     * @param AutomationContactModel $automation_contact Contact Model.
     * @param array                    $rule Rule data.
     *
     * @return int
     */
    public function get_value($automation_contact, $rule = array())
    {
        $contact = $automation_contact->contact;

        if (! $contact || ! $contact->exists) {
            return 0;
        }

        // Extract form IDs and timeframe data from rule
        $form_ids       = array();
        $timeframe_data = array();

        if (isset($rule['value']) && is_array($rule['value'])) {
            $form_ids       = $rule['value']['form_ids'] ?? array();
            $timeframe_data = $rule['value']['timeframe'] ?? array();
        }

        // Build the query for form submissions
        $query = FormSubmissionModel::where('contact_id', $contact->id);

        // Filter by form IDs if provided
        if (! empty($form_ids)) {
            $query->whereIn('form_id', $form_ids);
        }

        // Apply timeframe filter if provided
        if (! empty($timeframe_data) && isset($timeframe_data['type'])) {
            $query = $this->apply_timeframe_filter($query, $timeframe_data, 'created_at');
        }

        return $query->count();
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
        $contact = $automation_contact->contact;

        if (! $contact || ! $contact->exists) {
            return false;
        }

        $value = $this->get_value($automation_contact, $rule);
        return $value > 0;
    }
}

AutomationModuleStorage::register_rule( new FormSubmission(), 'forms', FormModel::class );
