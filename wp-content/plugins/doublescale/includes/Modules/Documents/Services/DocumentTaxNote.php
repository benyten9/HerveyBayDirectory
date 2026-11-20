<?php
/**
 * Legally required wording for invoices with untaxed lines.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\Services\SalesSettings;

/**
 * DocumentTaxNote service.
 *
 * An invoice that charges no VAT on a line has to say why: § 19 UStG small
 * business ("Kein Ausweis von Umsatzsteuer …"), reverse charge
 * ("Steuerschuldnerschaft des Leistungsempfängers", § 14a UStG), or a tax
 * exemption. The seller picks what untaxed lines are once in Sales Settings;
 * the PDF, the preview and Pro's XRechnung all read it from here.
 *
 * Category codes follow EN 16931 (UNCL 5305): E exempt, Z zero-rated,
 * AE reverse charge. An empty category prints nothing, so shops outside
 * Germany with 0 % lines never get German tax wording.
 */
final class DocumentTaxNote {

	public const CATEGORIES = array( '', 'E', 'Z', 'AE' );

	/**
	 * Configured category for untaxed lines ('' = not specified).
	 *
	 * @return string
	 */
	public static function category(): string {
		$category = strtoupper( trim( (string) SalesSettings::get( 'zero_tax_category', '' ) ) );

		return in_array( $category, self::CATEGORIES, true ) ? $category : '';
	}

	/**
	 * Configured wording (exemption reason) for untaxed lines.
	 *
	 * @return string
	 */
	public static function note(): string {
		return trim( (string) SalesSettings::get( 'zero_tax_note', '' ) );
	}

	/**
	 * The note to print for these line items, or null when none applies.
	 *
	 * @param mixed       $line_items Line items.
	 * @param string|null $category   Category (null = configured one).
	 * @param string|null $note       Wording (null = configured one).
	 * @return string|null
	 */
	public static function for_line_items( $line_items, ?string $category = null, ?string $note = null ): ?string {
		if ( ! self::has_untaxed_line( $line_items ) ) {
			return null;
		}

		$category = null === $category ? self::category() : strtoupper( trim( $category ) );
		$note     = null === $note ? self::note() : trim( $note );

		if ( 'AE' === $category ) {
			return '' !== $note
				? $note
				: __( 'Reverse charge: VAT to be accounted for by the recipient (Steuerschuldnerschaft des Leistungsempfängers).', 'doublescale' );
		}
		if ( in_array( $category, array( 'E', 'Z' ), true ) && '' !== $note ) {
			return $note;
		}

		return null;
	}

	/**
	 * Whether any billed line carries no VAT.
	 *
	 * @param mixed $line_items Line items.
	 * @return bool
	 */
	public static function has_untaxed_line( $line_items ): bool {
		foreach ( is_array( $line_items ) ? $line_items : array() as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['optional'] ) ) {
				continue;
			}
			$rate  = 0.0;
			$taxes = isset( $item['tax'] ) && is_array( $item['tax'] ) ? $item['tax'] : array();
			foreach ( $taxes as $tax ) {
				$rate += is_array( $tax ) && isset( $tax['rate'] ) ? (float) $tax['rate'] : 0.0;
			}
			if ( $rate <= 0.0 ) {
				return true;
			}
		}

		return false;
	}
}
