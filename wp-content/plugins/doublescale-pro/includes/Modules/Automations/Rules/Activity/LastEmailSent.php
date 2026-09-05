<?php

/**
 * Class LastEmailSent
 *
 * This class is responsible for handling the last email sent rule
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

/**
 * LastEmailSent class
 */
class LastEmailSent extends Rule {

	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Last Email Sent';

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'activity_last_email_sent';

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
	public $type = 'date';

	/**
	 * Campaign / advanced contact filters use non-automation rule sets.
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$contact        = $automation_contact->contact;
		$campaign_email = CommunicationTrackingModel::emails()->where( 'contact_id', $contact->id )
			->whereNotNull( 'sent_at' )
			->orderBy( 'sent_at', 'desc' )
			->first();

		if ( $campaign_email ) {
			return $campaign_email->sent_at;
		}

		return null;
	}

	/**
	 * Get operators
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		return array(
			'before'  => __( 'Before', 'doublescale'),
			'after'   => __( 'After', 'doublescale'),
			'on'      => __( 'On', 'doublescale'),
			'between' => __( 'Between', 'doublescale'),
			'within'  => __( 'Within', 'doublescale'),
		);
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
	public function is_met( AutomationContactModel $automation_contact, $rule = array() ) {
		return $this->is_date_condition_met(
			$this->get_value( $automation_contact ),
			$rule['operator'] ?? '',
			$rule['value'] ?? ''
		);
	}
}

RulesManager::instance()->register( new LastEmailSent() );
