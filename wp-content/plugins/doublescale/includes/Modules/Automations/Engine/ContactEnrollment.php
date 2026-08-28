<?php
/**
 * Enrolls a contact into an automation run (create/find contact + automation_contact row).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Engine;

use DoubleScale\Core\Settings\PhoneAsWhatsappSetting;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Core\Validators\PhoneValidator;

defined( 'ABSPATH' ) || exit;

final class ContactEnrollment {

	private AutomationModel $automation;

	/** @var array<string, mixed> */
	private array $args;

	/**
	 * @param array<string, mixed> $args Same shape as {@see ProcessAutomation::$args}.
	 */
	public function __construct( AutomationModel $automation, array $args ) {
		$this->automation = $automation;
		$this->args       = $args;
	}

	/**
	 * @return AutomationContactModel|false
	 */
	public function add_contact() {
		$multiple_runs = $this->automation->get_setting( 'multiple_runs', false );
		$test_mode     = ! empty( $this->args['test_mode'] );
		$contact       = $this->args['contact'] ?? null;

		if ( ! $contact ) {
			$contact = $this->maybe_create_contact();
		}

		$automation_contact = $this->automation->contacts()->where( 'contact_id', $contact->id )->first();

		if ( $automation_contact && ! $multiple_runs && ! $test_mode ) {
			return false;
		}

		$data = $this->args['data'] ?? array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( isset( $this->args['booking'] ) && $this->args['booking'] instanceof \DoubleScale\Modules\Booking\Models\BookingModel ) {
			$data['booking_id'] = (int) $this->args['booking']->id;
			if ( isset( $this->args['context'] ) && is_array( $this->args['context'] ) ) {
				$data['booking_context'] = $this->args['context'];
			}
		}

		$automation_contact = AutomationContactModel::create(
			array(
				'automation_id' => $this->automation->id,
				'contact_id'    => $contact->id,
				'status'        => 'active',
				'data'          => $data,
			)
		);

		/**
		 * Fires when a contact enters an automation.
		 *
		 * @since 1.0.0
		 *
		 * @param \DoubleScale\Modules\Automations\Models\AutomationModel         $automation         The automation.
		 * @param \DoubleScale\Modules\Automations\Models\AutomationContactModel $automation_contact The automation contact record.
		 */
		do_action( 'doublescale_automation_contact_enter', $this->automation, $automation_contact );

		return $automation_contact;
	}

	public function maybe_create_contact(): ContactModel {
		$lookup     = ContactModel::normalize_contact_data( $this->args );
		$contact    = ContactModel::find_by_identifiers( $lookup );
		$attributes = $this->prepare_contact_attributes( $contact );

		if ( ! $contact ) {
			$contact = ContactModel::create( $attributes );
		} else {
			$contact->update( $attributes );
		}

		return $contact;
	}

	/**
	 * Build the contact attribute set from the trigger args.
	 *
	 * Normalizes the phone fields so WooCommerce billing/shipping numbers land in
	 * both `phone` (loose) and `whatsapp_phone` (strict E.164). Empty or
	 * unverifiable phone values are dropped so they never overwrite a good value
	 * already on an existing contact.
	 *
	 * @param ContactModel|null $existing Existing contact (when updating).
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_contact_attributes( $existing ): array {
		$attributes = $this->args;

		// Internal-only keys that are not contact columns.
		unset( $attributes['data'], $attributes['contact'], $attributes['booking'], $attributes['context'] );

		$country_hint = isset( $attributes['country'] ) ? (string) $attributes['country'] : '';

		// Loose phone column.
		if ( array_key_exists( 'phone', $attributes ) ) {
			$phone = PhoneValidator::normalize_loose( $attributes['phone'] );
			if ( '' === $phone ) {
				unset( $attributes['phone'] );
			} else {
				$attributes['phone'] = $phone;
			}
		}

		// Strict E.164 WhatsApp column. Derive it from the loose phone when an
		// explicit whatsapp_phone was not supplied and the automation allows it.
		$phone_is_whatsapp = PhoneAsWhatsappSetting::is_enabled( $this->automation );
		if ( $phone_is_whatsapp ) {
			$whatsapp_source = $attributes['whatsapp_phone'] ?? ( $attributes['phone'] ?? ( $this->args['phone'] ?? '' ) );
			$whatsapp        = PhoneValidator::to_e164( $whatsapp_source, $country_hint );
			if ( null === $whatsapp ) {
				unset( $attributes['whatsapp_phone'] );
			} else {
				$attributes['whatsapp_phone'] = $whatsapp;
			}
		} else {
			unset( $attributes['whatsapp_phone'] );
		}

		// `country` is only a hint for E.164 resolution; keep it only if it maps
		// to a real contact column value (ISO code) and is non-empty.
		if ( isset( $attributes['country'] ) && '' === trim( (string) $attributes['country'] ) ) {
			unset( $attributes['country'] );
		}

		// Never overwrite an existing non-empty phone field with the same-or-empty
		// incoming value churn; only fill blanks on update.
		if ( $existing ) {
			foreach ( array( 'phone', 'whatsapp_phone' ) as $field ) {
				if ( isset( $attributes[ $field ] ) && ! empty( $existing->{$field} ) ) {
					unset( $attributes[ $field ] );
				}
			}
		}

		return $attributes;
	}
}
