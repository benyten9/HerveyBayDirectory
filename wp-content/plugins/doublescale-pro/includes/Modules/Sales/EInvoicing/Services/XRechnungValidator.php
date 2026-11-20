<?php
/**
 * Pre-flight checks that block an XRechnung export before any XML is built.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DiscountType;

/**
 * XRechnungValidator service.
 *
 * Every blocker here is a rule the official XRechnung validator would reject
 * (a BR-* / BR-DE-* rule) or a DoubleScale shape EN 16931 cannot express
 * without disagreeing with the PDF. Refusing with a readable list is the whole
 * point: a file that fails at the recipient's portal is worse than no file.
 *
 * Inputs use the neutral shapes documented on {@see XRechnungInvoiceMapper}.
 */
final class XRechnungValidator {

	/**
	 * @param array<string, mixed> $invoice Invoice data.
	 * @param array<string, mixed> $seller  Seller profile.
	 * @param array<string, mixed> $buyer   Buyer data.
	 * @return string[] Human-readable blockers; empty when the export can proceed.
	 */
	public static function preflight( array $invoice, array $seller, array $buyer ): array {
		$blockers = array();

		if ( '' === self::str( $invoice['invoice_number'] ?? '' ) ) {
			$blockers[] = __( 'The invoice has no invoice number.', 'doublescale' );
		}
		if ( null === self::date( $invoice['invoice_date'] ?? '' ) ) {
			$blockers[] = __( 'The invoice has no issue date.', 'doublescale' );
		}

		$reference = self::str( $invoice['buyer_reference'] ?? '' );
		if ( self::looks_like_leitweg_id( $reference ) && ! self::is_valid_leitweg_id( $reference ) ) {
			$blockers[] = sprintf(
				/* translators: %s: Leitweg-ID as entered on the invoice */
				__( 'The Leitweg-ID "%s" has a wrong check digit — the authority\'s portal would reject it. Check it against the Leitweg-ID the authority gave you.', 'doublescale' ),
				$reference
			);
		}

		$discount_type  = self::str( $invoice['discount_type'] ?? DiscountType::NONE );
		$discount_value = (float) ( $invoice['discount_value'] ?? 0 );
		if ( $discount_value > 0 && in_array( $discount_type, array( DiscountType::PERCENT, DiscountType::FIXED ), true ) ) {
			$blockers[] = __( 'Discount type: XRechnung does not allow a discount that is left out of the taxable amount. Edit the invoice and change the discount type to "Before Tax" or "After Tax".', 'doublescale' );
		}

		$categories = array();
		$billable   = 0;
		$items      = is_array( $invoice['line_items'] ?? null ) ? $invoice['line_items'] : array();
		$position   = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['optional'] ) ) {
				continue;
			}
			++$position;
			++$billable;

			// § 14 (4) Nr. 5 UStG: every line must say what was supplied.
			$text = trim( wp_strip_all_tags( self::str( $item['description'] ?? '' ) . ' ' . self::str( $item['long_description'] ?? '' ) ) );
			if ( '' === $text ) {
				$blockers[] = sprintf(
					/* translators: %d: line position */
					__( 'Line %d has no description. An invoice must say what was supplied — edit the invoice and describe the line.', 'doublescale' ),
					$position
				);
			}

			$taxes = XRechnungInvoiceMapper::line_tax_rates( $item );
			if ( count( $taxes ) > 1 ) {
				$blockers[] = sprintf(
					/* translators: 1: line position, 2: line description */
					__( 'Line %1$d ("%2$s") has more than one tax. XRechnung allows one VAT rate per line — edit the invoice and keep a single tax on this line.', 'doublescale' ),
					$position,
					self::str( $item['description'] ?? '' )
				);
				continue;
			}

			$rate                                      = $taxes[0] ?? 0.0;
			$categories[ self::category( $rate, $seller ) ] = true;
		}

		if ( 0 === $billable ) {
			$blockers[] = __( 'The invoice has no billable line items — add at least one line that is not optional.', 'doublescale' );
		}

		// Seller (BR-06, BR-DE-2..7, BR-DE-23, BR-S/Z/E/AE-02).
		$missing_seller = array();
		$seller_fields  = array(
			'name'          => __( 'company name', 'doublescale' ),
			'street'        => __( 'street', 'doublescale' ),
			'postcode'      => __( 'postcode', 'doublescale' ),
			'city'          => __( 'city', 'doublescale' ),
			'email'         => __( 'e-invoice email address', 'doublescale' ),
			'contact_name'  => __( 'contact person', 'doublescale' ),
			'contact_phone' => __( 'contact phone', 'doublescale' ),
		);
		foreach ( $seller_fields as $key => $label ) {
			if ( '' === self::str( $seller[ $key ] ?? '' ) ) {
				$missing_seller[] = $label;
			}
		}
		if ( null === CountryCode::to_alpha2( self::str( $seller['country'] ?? '' ) ) ) {
			$missing_seller[] = __( 'country', 'doublescale' );
		}
		if ( '' === self::str( $seller['vat_id'] ?? '' ) ) {
			$missing_seller[] = __( 'VAT ID or tax number', 'doublescale' );
		}
		if ( ! empty( $missing_seller ) ) {
			$blockers[] = sprintf(
				/* translators: %s: comma-separated list of missing seller settings */
				__( 'Your company details are incomplete — fill in %s in Sales Settings → E-invoicing.', 'doublescale' ),
				implode( ', ', $missing_seller )
			);
		}

		$iban = self::normalize_account( self::str( $seller['iban'] ?? '' ) );
		if ( '' === $iban ) {
			$blockers[] = __( 'No IBAN is set — add the bank account customers pay into in Sales Settings → E-invoicing.', 'doublescale' );
		} elseif ( ! self::is_valid_iban( $iban ) ) {
			$blockers[] = sprintf(
				/* translators: %s: the IBAN as saved in settings */
				__( 'The IBAN "%s" is not a valid IBAN — correct it in Sales Settings → E-invoicing. An IBAN starts with a country code and two check digits, e.g. DE89 3704 0044 0532 0130 00.', 'doublescale' ),
				self::str( $seller['iban'] ?? '' )
			);
		}

		// Buyer (BR-07, BR-11, BR-DE-8/9, BT-49) — and a street: § 14 (4)
		// Nr. 1 UStG wants the recipient's full address, not just the city.
		$missing_buyer = array();
		$buyer_fields  = array(
			'name'     => __( 'name or company name', 'doublescale' ),
			'street'   => __( 'street', 'doublescale' ),
			'city'     => __( 'city', 'doublescale' ),
			'postcode' => __( 'postcode', 'doublescale' ),
			'email'    => __( 'email', 'doublescale' ),
		);
		foreach ( $buyer_fields as $key => $label ) {
			if ( '' === self::str( $buyer[ $key ] ?? '' ) ) {
				$missing_buyer[] = $label;
			}
		}
		$buyer_country = self::str( $buyer['country'] ?? '' );
		if ( null === CountryCode::to_alpha2( $buyer_country ) ) {
			$missing_buyer[] = '' === $buyer_country
				? __( 'country', 'doublescale' )
				: sprintf(
					/* translators: %s: country as typed on the contact */
					__( 'a recognisable country ("%s" is not one — use a name like Germany or a code like DE)', 'doublescale' ),
					$buyer_country
				);
		}
		if ( ! empty( $missing_buyer ) ) {
			$buyer_label = self::str( $buyer['name'] ?? '' );
			if ( '' === $buyer_label ) {
				$buyer_label = self::str( $buyer['email'] ?? '' );
			}
			$blockers[] = '' === $buyer_label
				? sprintf(
					/* translators: %s: comma-separated list of missing customer fields */
					__( 'The customer\'s details are incomplete — add %s on the customer\'s contact page.', 'doublescale' ),
					implode( ', ', $missing_buyer )
				)
				: sprintf(
					/* translators: 1: customer name, 2: comma-separated list of missing customer fields */
					__( 'The customer "%1$s" is missing %2$s — add them on the customer\'s contact page.', 'doublescale' ),
					$buyer_label,
					implode( ', ', $missing_buyer )
				);
		}

		// Zero-rated categories.
		if ( isset( $categories['E'] ) && '' === self::str( $seller['exemption_reason'] ?? '' ) ) {
			$blockers[] = __( 'This invoice has lines without tax, which are exported as VAT-exempt — enter the note for untaxed lines in Sales Settings → General → Documents & Receipts.', 'doublescale' );
		}
		if ( isset( $categories['AE'] ) ) {
			if ( null === XRechnungInvoiceMapper::vat_id( self::str( $seller['vat_id'] ?? '' ) ) ) {
				$blockers[] = __( 'Reverse charge needs your VAT ID with a country prefix (e.g. DE123456789) — set it in Sales Settings → General → Documents & Receipts.', 'doublescale' );
			}
			if ( null === XRechnungInvoiceMapper::vat_id( self::str( $buyer['vat_id'] ?? '' ) ) ) {
				$blockers[] = __( 'Reverse charge needs the customer\'s VAT ID with a country prefix (e.g. ATU12345678) — add it on the customer\'s contact page.', 'doublescale' );
			}
		}

		return $blockers;
	}

	/**
	 * VAT category for a line tax rate.
	 *
	 * @param float                $rate   Tax rate in percent.
	 * @param array<string, mixed> $seller Seller profile.
	 * @return string
	 */
	public static function category( float $rate, array $seller ): string {
		if ( $rate > 0 ) {
			return 'S';
		}

		$zero = strtoupper( self::str( $seller['zero_vat_category'] ?? 'E' ) );

		return in_array( $zero, array( 'Z', 'E', 'AE' ), true ) ? $zero : 'E';
	}

	/**
	 * Whether a buyer reference is shaped like a Leitweg-ID: coarse address
	 * starting with a Land code (01–16) or 99 (Bund), optional fine address,
	 * two-digit check. Anything else (B2B order numbers) is left alone.
	 *
	 * @param string $reference Buyer reference.
	 * @return bool
	 */
	public static function looks_like_leitweg_id( string $reference ): bool {
		return (bool) preg_match( '/^(0[1-9]|1[0-6]|99)\d{0,10}(-[A-Za-z0-9]{1,30})?-\d{2}$/', $reference );
	}

	/**
	 * Leitweg-ID check digit: ISO 7064 Mod 97-10 over the coarse and fine
	 * address, letters counted as in an IBAN (A = 10 … Z = 35).
	 *
	 * @param string $reference Leitweg-ID.
	 * @return bool
	 */
	public static function is_valid_leitweg_id( string $reference ): bool {
		$parts = explode( '-', strtoupper( $reference ) );
		$check = array_pop( $parts );
		$body  = implode( '', $parts );

		$digits = '';
		foreach ( str_split( $body ) as $char ) {
			$digits .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}

		$remainder = 0;
		foreach ( str_split( $digits . '00', 7 ) as $chunk ) {
			$remainder = (int) ( $remainder . $chunk ) % 97;
		}

		return sprintf( '%02d', 98 - $remainder ) === $check;
	}

	/**
	 * ISO 13616 mod-97 check.
	 *
	 * @param string $iban Normalized IBAN.
	 * @return bool
	 */
	public static function is_valid_iban( string $iban ): bool {
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}

		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$digits     = '';
		foreach ( str_split( $rearranged ) as $char ) {
			$digits .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}

		$remainder = 0;
		foreach ( str_split( $digits, 7 ) as $chunk ) {
			$remainder = (int) ( ( $remainder . $chunk ) ) % 97;
		}

		return 1 === $remainder;
	}

	/**
	 * Upper-case and strip spaces from an IBAN/BIC.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_account( string $value ): string {
		return strtoupper( (string) preg_replace( '/\s+/', '', $value ) );
	}

	/**
	 * @param string $value Raw date.
	 * @return string|null Y-m-d or null.
	 */
	public static function date( $value ): ?string {
		$value = substr( self::str( $value ), 0, 10 );

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && '0000-00-00' !== $value ? $value : null;
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
