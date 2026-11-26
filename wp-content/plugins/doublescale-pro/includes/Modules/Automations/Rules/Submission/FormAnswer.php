<?php
/**
 * Automation and lead-scoring rule "Form answer": the contact answered a DoubleScale form's
 * question a certain way. The matching itself is Free's contact filter (same SQL as segments).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Submission;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Forms\Models\FormModel;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Form answer rule.
 */
class FormAnswer extends Rule {

	public $name = 'Form Answer';

	public $slug = 'form_answer';

	public $group = 'submission';

	public $type = 'form_answer';

	public $is_automation = false;

	/**
	 * Forms and their questions (same shape as the contact filter).
	 *
	 * @return array
	 */
	public function get_options() {
		if ( ! self::filter_class_ready() || ! AutomationModuleStorage::is_ready( 'forms', FormModel::class ) ) {
			return array();
		}
		return ( new \DoubleScale\Modules\Contacts\Filters\Submission\FormAnswer() )->get_options();
	}

	/**
	 * Operators (the UI shows its own).
	 *
	 * @return array
	 */
	public function get_operators() {
		return array();
	}

	/**
	 * Value for the rule (1 when it matches, 0 when not).
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule.
	 * @return int
	 */
	public function get_value( $automation_contact, $rule = array() ) {
		return $this->is_met( $automation_contact, $rule ) ? 1 : 0;
	}

	/**
	 * Whether the contact's answer matches.
	 *
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $rule               Rule.
	 * @return bool
	 */
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		$contact = $automation_contact->contact;
		if ( ! $contact || ! $contact->exists || ! self::filter_class_ready() ) {
			return false;
		}
		$filter = new \DoubleScale\Modules\Contacts\Filters\Submission\FormAnswer();
		return $filter->apply( ContactModel::where( 'id', (int) $contact->id ), array( 'value' => $rule['value'] ?? array() ) )->exists();
	}

	/**
	 * Free ships the filter from 1.10.2 on.
	 *
	 * @return bool
	 */
	private static function filter_class_ready(): bool {
		return class_exists( '\DoubleScale\Modules\Contacts\Filters\Submission\FormAnswer' );
	}
}

AutomationModuleStorage::register_rule( new FormAnswer(), 'forms', FormModel::class );
