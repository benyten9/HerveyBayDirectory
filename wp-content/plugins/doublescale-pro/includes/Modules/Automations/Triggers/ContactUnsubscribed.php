<?php
/**
 * Contact Unsubscribed Trigger
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Automations\Traits\ContactSubscriptionTriggerTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Unsubscribed Trigger
 */
class ContactUnsubscribed extends Trigger {

	use ContactSubscriptionTriggerTrait;

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Contact Unsubscribed';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'contact_unsubscribed';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'Fires when a contact unsubscribes from Email, SMS, or WhatsApp.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'crm';

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
		add_action( 'doublescale_email_unsubscribed', array( $this, 'handle_email_unsubscribed' ) );
		add_action( 'doublescale_sms_unsubscribed', array( $this, 'handle_sms_unsubscribed' ) );
		add_action( 'doublescale_whatsapp_unsubscribed', array( $this, 'handle_whatsapp_unsubscribed' ) );
		add_action( 'doublescale_contact_unsubscribe', array( $this, 'handle_legacy_email_unsubscribed' ) );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_email_unsubscribed( $contact ) {
		$this->dispatch_subscription_event( $contact, 'email' );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_sms_unsubscribed( $contact ) {
		$this->dispatch_subscription_event( $contact, 'sms' );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_whatsapp_unsubscribed( $contact ) {
		$this->dispatch_subscription_event( $contact, 'whatsapp' );
	}

	/**
	 * Legacy hook when email_status changes to unsubscribed.
	 *
	 * @param ContactModel $contact Contact.
	 * @return void
	 */
	public function handle_legacy_email_unsubscribed( $contact ) {
		$this->dispatch_subscription_event( $contact, 'email' );
	}

	/**
	 * @param AutomationModel $automation Automation.
	 * @param array           $args       Event args.
	 * @return bool
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		$event = $args['data'] ?? array();
		if ( ! is_array( $event ) ) {
			return false;
		}

		if ( ! $this->matches_subscription_filter( $automation, $event ) ) {
			return false;
		}

		return parent::is_processable( $automation, $args );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields() {
		return $this->get_subscription_fields();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_attributes_schema() {
		return $this->get_subscription_schema();
	}
}
