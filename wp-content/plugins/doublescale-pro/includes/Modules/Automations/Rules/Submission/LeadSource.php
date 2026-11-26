<?php
/**
 * Automation and lead-scoring rule "Lead source": the contact's first or latest touch
 * (UTM source, medium or campaign) matches. The matching is Free's contact filter.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Submission;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Services\RulesManager;
use DoubleScale\Modules\Contacts\Models\ContactModel;

defined( 'ABSPATH' ) || exit;

/**
 * Lead source rule.
 */
class LeadSource extends Rule {

	public $name = 'Lead Source';

	public $slug = 'lead_source';

	public $group = 'submission';

	public $type = 'lead_source';

	public $is_automation = false;

	/**
	 * Known values (same shape as the contact filter).
	 *
	 * @return array
	 */
	public function get_options() {
		return self::filter_class_ready() ? ( new \DoubleScale\Modules\Contacts\Filters\Submission\LeadSource() )->get_options() : array();
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
	 * Whether the contact's source matches.
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
		$filter = new \DoubleScale\Modules\Contacts\Filters\Submission\LeadSource();
		return $filter->apply( ContactModel::where( 'id', (int) $contact->id ), array( 'value' => $rule['value'] ?? array() ) )->exists();
	}

	/**
	 * Free ships the filter from 1.10.2 on.
	 *
	 * @return bool
	 */
	private static function filter_class_ready(): bool {
		return class_exists( '\DoubleScale\Modules\Contacts\Filters\Submission\LeadSource' );
	}
}

RulesManager::instance()->register( new LeadSource() );
