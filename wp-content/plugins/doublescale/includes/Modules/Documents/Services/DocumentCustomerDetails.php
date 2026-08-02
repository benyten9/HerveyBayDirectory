<?php
/**
 * Compose customer party details for sales documents from contacts.
 *
 * @package DoubleScale\Modules\Documents\Services
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Documents\Models\ProposalModel;

/**
 * DocumentCustomerDetails service.
 */
final class DocumentCustomerDetails {

	/**
	 * @param string $number Registration number.
	 * @return string
	 */
	public static function registration_line( string $number ): string {
		return sprintf(
			/* translators: %s: company registration number */
			__( 'Registration: %s', 'doublescale' ),
			$number
		);
	}

	/**
	 * @param string $number Tax or VAT number.
	 * @return string
	 */
	public static function tax_vat_line( string $number ): string {
		return sprintf(
			/* translators: %s: tax or VAT number */
			__( 'Tax/VAT: %s', 'doublescale' ),
			$number
		);
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return string Multiline billing / bill-to block.
	 */
	public static function compose_billing_address( ContactModel $contact ): string {
		$lines = self::compose_party_lines( $contact );

		return implode( "\n", $lines );
	}

	/**
	 * @param ContactModel $contact Contact.
	 * @return array<int, string>
	 */
	public static function compose_party_lines( ContactModel $contact ): array {
		$lines = array();

		$company_name = trim( (string) ( $contact->company_name ?? '' ) );
		if ( '' !== $company_name ) {
			$lines[] = $company_name;
		}

		$person_name = trim( trim( (string) $contact->first_name ) . ' ' . trim( (string) $contact->last_name ) );
		if ( '' !== $person_name && ( '' === $company_name || $person_name !== $company_name ) ) {
			$lines[] = $person_name;
		}

		foreach ( array( 'address_1', 'address_2' ) as $field ) {
			$value = trim( (string) ( $contact->{$field} ?? '' ) );
			if ( '' !== $value ) {
				$lines[] = $value;
			}
		}

		$city_line = trim(
			implode(
				', ',
				array_filter(
					array(
						$contact->city ? (string) $contact->city : '',
						$contact->state ? (string) $contact->state : '',
					)
				)
			)
		);
		if ( $city_line && $contact->zip ) {
			$city_line .= ' ' . (string) $contact->zip;
		} elseif ( $contact->zip ) {
			$city_line = (string) $contact->zip;
		}
		if ( '' !== $city_line ) {
			$lines[] = $city_line;
		}

		if ( $contact->country ) {
			$lines[] = (string) $contact->country;
		}
		if ( $contact->email ) {
			$lines[] = (string) $contact->email;
		}
		if ( $contact->phone ) {
			$lines[] = (string) $contact->phone;
		}

		$registration = trim( (string) ( $contact->company_registration_number ?? '' ) );
		if ( '' !== $registration ) {
			$lines[] = self::registration_line( $registration );
		}

		$tax_vat = trim( (string) ( $contact->tax_vat_number ?? '' ) );
		if ( '' !== $tax_vat ) {
			$lines[] = self::tax_vat_line( $tax_vat );
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $line ) {
						return trim( (string) $line );
					},
					$lines
				)
			)
		);
	}

	/**
	 * Snapshot proposal party fields from the linked contact at send time.
	 *
	 * @param ProposalModel $proposal Proposal.
	 * @return void
	 */
	public static function snapshot_proposal_party_from_contact( ProposalModel $proposal ): void {
		$contact = $proposal->relationLoaded( 'contact' ) ? $proposal->contact : null;
		if ( ! $contact && $proposal->contact_id ) {
			$contact = ContactModel::find( (int) $proposal->contact_id );
		}
		if ( ! $contact ) {
			return;
		}

		$company_name = trim( (string) ( $contact->company_name ?? '' ) );
		$person_name  = trim( trim( (string) $contact->first_name ) . ' ' . trim( (string) $contact->last_name ) );

		$proposal->to_name = '' !== $company_name ? $company_name : ( '' !== $person_name ? $person_name : $proposal->to_name );
		$proposal->address = $contact->address_1 ? (string) $contact->address_1 : $proposal->address;
		$proposal->city    = $contact->city ? (string) $contact->city : $proposal->city;
		$proposal->state   = $contact->state ? (string) $contact->state : $proposal->state;
		$proposal->country = $contact->country ? (string) $contact->country : $proposal->country;
		$proposal->zip     = $contact->zip ? (string) $contact->zip : $proposal->zip;
		$proposal->email   = $contact->email ? (string) $contact->email : $proposal->email;
		$proposal->phone   = $contact->phone ? (string) $contact->phone : $proposal->phone;

		$legal_lines = array();
		$registration = trim( (string) ( $contact->company_registration_number ?? '' ) );
		if ( '' !== $registration ) {
			$legal_lines[] = self::registration_line( $registration );
		}
		$tax_vat = trim( (string) ( $contact->tax_vat_number ?? '' ) );
		if ( '' !== $tax_vat ) {
			$legal_lines[] = self::tax_vat_line( $tax_vat );
		}
		if ( ! empty( $legal_lines ) ) {
			$address = trim( (string) ( $proposal->address ?? '' ) );
			$proposal->address = '' !== $address
				? $address . "\n" . implode( "\n", $legal_lines )
				: implode( "\n", $legal_lines );
		}
	}

	/**
	 * Snapshot billing address from contact at send time.
	 *
	 * @param object $document Invoice or credit note model with contact_id + billing_address.
	 * @return void
	 */
	public static function snapshot_billing_from_contact( $document ): void {
		$contact = null;
		if ( isset( $document->contact ) && $document->contact instanceof ContactModel ) {
			$contact = $document->contact;
		} elseif ( ! empty( $document->contact_id ) ) {
			$contact = ContactModel::find( (int) $document->contact_id );
		}

		if ( ! $contact ) {
			return;
		}

		$document->billing_address = self::compose_billing_address( $contact );
	}
}
