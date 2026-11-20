<?php
/**
 * Resolve seller and buyer data for XRechnung from settings and contacts.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Services\DocumentTaxNote;
use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * XRechnungProfile service.
 */
final class XRechnungProfile {

	/**
	 * Sales-settings keys that hold the structured seller data EN 16931 needs
	 * and the free-text PDF address cannot provide.
	 */
	public const SETTING_KEYS = array(
		'einvoice_seller_street',
		'einvoice_seller_postcode',
		'einvoice_seller_city',
		'einvoice_seller_country',
		'einvoice_seller_email',
		'einvoice_contact_name',
		'einvoice_contact_phone',
		'einvoice_iban',
		'einvoice_bic',
		'einvoice_account_name',
		'einvoice_attach_to_email',
		'einvoice_enabled',
	);

	/**
	 * @return array<string, string>
	 */
	public static function setting_defaults(): array {
		$defaults = array_fill_keys( self::SETTING_KEYS, '' );

		// Attach the XML to invoice emails unless switched off.
		$defaults['einvoice_attach_to_email'] = '1';
		// XRechnung as a whole; on, so a site that relies on it keeps it.
		$defaults['einvoice_enabled'] = '1';

		return $defaults;
	}

	/**
	 * Sanitise the e-invoicing keys of a sales-settings payload.
	 *
	 * @param array<string, mixed> $merged Merged settings payload.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( array $merged ): array {
		$clean = array();
		foreach ( self::SETTING_KEYS as $key ) {
			if ( ! array_key_exists( $key, $merged ) ) {
				continue;
			}
			$value = is_scalar( $merged[ $key ] ) ? (string) $merged[ $key ] : '';

			switch ( $key ) {
				case 'einvoice_seller_country':
					$clean[ $key ] = (string) CountryCode::to_alpha2( $value );
					break;
				case 'einvoice_seller_email':
					$clean[ $key ] = sanitize_email( $value );
					break;
				case 'einvoice_attach_to_email':
				case 'einvoice_enabled':
					$clean[ $key ] = in_array( $merged[ $key ], array( false, 0, '0', '', 'false', 'off', 'no' ), true ) ? '0' : '1';
					break;
				case 'einvoice_iban':
				case 'einvoice_bic':
					$clean[ $key ] = (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( $value ) );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		return $clean;
	}

	/**
	 * Seller profile. Name and legal IDs come from the document's company
	 * block (the issuer snapshot once sent), so the XML names the same issuer
	 * as the PDF; the structured address and bank data come from settings.
	 *
	 * @param array<string, mixed> $company Company block from DocumentIssuerSnapshot.
	 * @return array<string, mixed>
	 */
	public static function seller( array $company ): array {
		$setting = static function ( string $key ): string {
			return trim( (string) SalesSettings::get( $key, '' ) );
		};

		return array(
			'name'                => trim( (string) ( $company['name'] ?? '' ) ),
			'street'              => $setting( 'einvoice_seller_street' ),
			'postcode'            => $setting( 'einvoice_seller_postcode' ),
			'city'                => $setting( 'einvoice_seller_city' ),
			'country'             => $setting( 'einvoice_seller_country' ),
			'vat_id'              => trim( (string) ( $company['tax_vat_number'] ?? '' ) ),
			'registration_number' => trim( (string) ( $company['registration_number'] ?? '' ) ),
			'email'               => $setting( 'einvoice_seller_email' ),
			'contact_name'        => $setting( 'einvoice_contact_name' ),
			'contact_phone'       => $setting( 'einvoice_contact_phone' ),
			'iban'                => $setting( 'einvoice_iban' ),
			'bic'                 => $setting( 'einvoice_bic' ),
			'account_name'        => $setting( 'einvoice_account_name' ),
			// Untaxed lines are a general sales setting (it also prints on the
			// PDF). Not specified still exports as exempt (E), which then asks
			// for a reason instead of guessing one.
			'zero_vat_category'   => DocumentTaxNote::category() ?: 'E',
			'exemption_reason'    => DocumentTaxNote::note(),
		);
	}

	/**
	 * Buyer data from the invoice's contact.
	 *
	 * @param object|null $contact Contact model (or null when deleted).
	 * @return array<string, string>
	 */
	public static function buyer( $contact ): array {
		$get = static function ( string $key ) use ( $contact ): string {
			return $contact && isset( $contact->{$key} ) && is_scalar( $contact->{$key} ) ? trim( (string) $contact->{$key} ) : '';
		};

		$name = $get( 'company_name' );
		if ( '' === $name ) {
			$name = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
		}

		return array(
			'name'                => $name,
			'street'              => $get( 'address_1' ),
			'additional_street'   => $get( 'address_2' ),
			'city'                => $get( 'city' ),
			'postcode'            => $get( 'zip' ),
			'state'               => $get( 'state' ),
			'country'             => $get( 'country' ),
			'vat_id'              => $get( 'tax_vat_number' ),
			'registration_number' => $get( 'company_registration_number' ),
			'email'               => $get( 'email' ),
		);
	}
}
