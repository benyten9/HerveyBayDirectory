<?php
/**
 * Enrolls a contact into an automation run (create/find contact + automation_contact row).
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Engine;

use DoubleScale\Core\Scope\OwnerScope;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\Settings\PhoneAsWhatsappSetting;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Contacts\Services\LeadRouter;
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

		// A workflow built by an agent who is limited to their own contacts
		// runs on contacts that agent owns and on unassigned ones (the team's,
		// which the agent can see) — never on a colleague's.
		$creator = (int) ( $this->automation->created_by ?? 0 );
		if ( $creator > 0
			&& Permissions::is_scoped_agent( $creator )
			&& (int) ( $contact->owner_id ?? 0 ) > 0
			&& ! OwnerScope::for_user( $creator )->owns_contact( $contact ) ) {
			return false;
		}

		$automation_contact = $this->automation->contacts()->where( 'contact_id', $contact->id )->first();

		if ( $automation_contact && ! $multiple_runs && ! $test_mode ) {
			return false;
		}

		$data = $this->args['data'] ?? array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$booking_id = 0;
		if ( isset( $this->args['booking'] ) && $this->args['booking'] instanceof \DoubleScale\Modules\Booking\Models\BookingModel ) {
			$booking_id = (int) $this->args['booking']->id;
		} elseif ( ! empty( $this->args['booking_id'] ) ) {
			$booking_id = (int) $this->args['booking_id'];
		}
		if ( $booking_id > 0 ) {
			$data['booking_id'] = $booking_id;
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
		$contact    = $this->find_contact( $lookup );
		$attributes = $this->without_numbers_held_by_others(
			$this->prepare_contact_attributes( $contact ),
			$contact
		);

		if ( ! $contact ) {
			$contact = self::creating_for(
				$this->automation,
				static function () use ( $attributes ) {
					return ContactModel::create( $attributes );
				}
			);
		} else {
			$contact->update( $attributes );
		}

		return $contact;
	}

	/**
	 * Who owns a contact this workflow creates, or null to follow lead routing.
	 *
	 * Setting `new_contact_owner`: 'routing', 'workflow_owner' or a user id.
	 * A workflow built by an agent always gives new contacts to that agent —
	 * agents own what they bring in, and in private mode their workflows only
	 * run on contacts they own. Otherwise the default is lead routing, so a
	 * site-wide "form submitted" workflow doesn't hand every lead to the admin.
	 *
	 * @return int|null
	 */
	public static function new_contact_owner( AutomationModel $automation ): ?int {
		$creator = (int) ( $automation->created_by ?? 0 );

		if ( $creator > 0 && Permissions::is_agent_tier( $creator ) ) {
			return $creator;
		}

		$choice = (string) $automation->get_setting( 'new_contact_owner', 'routing' );

		if ( 'workflow_owner' === $choice ) {
			return ( $creator > 0 && LeadRouter::is_valid_owner( $creator ) ) ? $creator : null;
		}

		$user_id = (int) $choice;

		return ( $user_id > 0 && LeadRouter::is_valid_owner( $user_id ) ) ? $user_id : null;
	}

	/**
	 * Run $create (which may create a contact) so a new contact gets this
	 * workflow's owner. Used here and by triggers that create the contact
	 * themselves (e.g. Webhook Received).
	 *
	 * @template T
	 * @param AutomationModel $automation Workflow.
	 * @param callable(): T   $create     Code that creates or updates the contact.
	 * @return T
	 */
	public static function creating_for( AutomationModel $automation, callable $create ) {
		$owner = self::new_contact_owner( $automation );
		if ( null === $owner ) {
			// "Lead routing rules": the Automations rule in Team settings decides.
			return LeadRouter::with_context(
				'automation',
				array( 'workflow_owner_id' => (int) ( $automation->created_by ?? 0 ) ),
				$create
			);
		}

		// actor_id 0: the workflow's choice wins over whoever is logged in.
		return LeadRouter::with_context(
			'automation',
			array(
				'actor_id' => 0,
				'owner_id' => $owner,
			),
			$create
		);
	}

	/**
	 * The existing contact these trigger args belong to.
	 *
	 * An email names one person. A phone or WhatsApp match only joins the args
	 * to a contact that has no email of its own; one with a different email is
	 * someone else sharing the number (a household, a company line), and taking
	 * it over would overwrite their email and name with the buyer's.
	 *
	 * @param array<string, mixed> $lookup Normalized trigger args.
	 *
	 * @return ContactModel|null
	 */
	private function find_contact( array $lookup ) {
		$match = ContactModel::find_identifier_conflict( $lookup );
		if ( ! $match ) {
			return null;
		}

		$has_email = null !== ( $lookup['email'] ?? null );
		if ( $has_email && 'email' !== $match['field'] && '' !== trim( (string) $match['contact']->email ) ) {
			return null;
		}

		return $match['contact'];
	}

	/**
	 * Drop a phone or WhatsApp number that another contact already holds.
	 *
	 * Both columns are unique, so saving it would throw and the contact would
	 * never enter the automation. The run matters more than the number, which
	 * stays with the contact who has it.
	 *
	 * @param array<string, mixed> $attributes Contact attributes about to be saved.
	 * @param ContactModel|null    $contact    Contact being updated, null when creating.
	 *
	 * @return array<string, mixed>
	 */
	private function without_numbers_held_by_others( array $attributes, $contact ): array {
		$exclude_id = $contact ? (int) $contact->id : null;

		foreach ( array( 'phone', 'whatsapp_phone' ) as $field ) {
			if ( empty( $attributes[ $field ] ) ) {
				continue;
			}

			if ( ContactModel::find_identifier_conflict( array( $field => $attributes[ $field ] ), $exclude_id ) ) {
				unset( $attributes[ $field ] );
			}
		}

		return $attributes;
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
		foreach ( TriggerPayload::internal_keys() as $key ) {
			unset( $attributes[ $key ] );
		}

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

		// Never overwrite an existing non-empty phone or name; only fill blanks on
		// update. A trigger's name is what someone typed next to an email (a
		// public booking, a guest checkout): it must not rename the person who
		// already owns that email in the CRM.
		if ( $existing ) {
			foreach ( array( 'phone', 'whatsapp_phone', 'first_name', 'last_name' ) as $field ) {
				if ( isset( $attributes[ $field ] ) && ! empty( $existing->{$field} ) ) {
					unset( $attributes[ $field ] );
				}
			}
		}

		return $attributes;
	}
}
