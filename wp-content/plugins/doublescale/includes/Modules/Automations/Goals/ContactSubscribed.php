<?php
/**
 * Class Contact Subscribed Goal
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Goals;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Goal;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Services\GoalsManager;
use DoubleScale\Modules\Automations\Support\ContactSubscriptionSettings;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Contact Subscribed Goal class
 */
class ContactSubscribed extends Goal {

	/**
	 * Goal Name
	 *
	 * @var string
	 */
	public $name = 'Contact Subscribed';

	/**
	 * Goal Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_subscribed';

	/**
	 * Goal Description
	 *
	 * @var string
	 */
	public $description = 'This goal is achieved when a contact subscribes to Email, SMS, or WhatsApp.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'automation';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'contact';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_email_subscribed', array( $this, 'handle_email_subscribed' ) );
		add_action( 'doublescale_sms_subscribed', array( $this, 'handle_sms_subscribed' ) );
		add_action( 'doublescale_whatsapp_subscribed', array( $this, 'handle_whatsapp_subscribed' ) );
		add_action( 'doublescale_contact_subscribe', array( $this, 'handle_legacy_email_subscribed' ) );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_email_subscribed( $contact ) {
		$this->dispatch_goal_event( $contact, 'email' );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_sms_subscribed( $contact ) {
		$this->dispatch_goal_event( $contact, 'sms' );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_whatsapp_subscribed( $contact ) {
		$this->dispatch_goal_event( $contact, 'whatsapp' );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_legacy_email_subscribed( $contact ) {
		$this->dispatch_goal_event( $contact, 'email' );
	}

	/**
	 * @param ContactModel         $contact Contact.
	 * @param string               $type    Subscription type.
	 * @param array<string, mixed> $extra   Extra event data.
	 * @return void
	 */
	protected function dispatch_goal_event( ContactModel $contact, string $type, array $extra = array() ): void {
		$this->process(
			$contact,
			array_merge(
				array(
					'subscription_type' => $type,
				),
				$extra
			)
		);
	}

	/**
	 * @param AutomationContactModel $automation_contact Automation contact.
	 * @param array                  $data               Event data.
	 * @return bool
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		$current_step = AutomationStepModel::find( $automation_contact->current_step );
		if ( ! $current_step ) {
			return false;
		}

		return ContactSubscriptionSettings::matches(
			$current_step->get_setting( 'subscription_type', 'any' ),
			is_array( $data ) ? $data : array()
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields() {
		return ContactSubscriptionSettings::fields();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_attributes_schema() {
		return ContactSubscriptionSettings::schema();
	}
}

GoalsManager::instance()->register( new ContactSubscribed() );
