<?php
/**
 * Map a DoubleScale invoice onto the EN 16931 semantic model.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\EInvoicing\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Documents\Constants\DiscountType;

/**
 * XRechnungInvoiceMapper service.
 *
 * All money logic lives here so the builder only serialises. The output
 * amounts are already rounded to cents and internally consistent with the
 * EN 16931 calculation rules (BR-CO-10..17), and the payable amount is always
 * the stored invoice total minus what was paid, so the XML never disagrees
 * with the PDF the customer received.
 *
 * Input shapes (all plain arrays, so the mapping is testable without a DB):
 *
 * - invoice: invoice_number, invoice_date, due_date, service_date,
 *   service_date_end, currency, buyer_reference, discount_type,
 *   discount_value, line_items, total, amount_paid, note, terms
 * - seller: name, street, postcode, city, country, vat_id, registration_number,
 *   email, contact_name, contact_phone, iban, bic, account_name,
 *   zero_vat_category, exemption_reason
 * - buyer: name, street, additional_street, city, postcode, state, country,
 *   vat_id, registration_number, email
 *
 * Callers must run {@see XRechnungValidator::preflight()} first; this class
 * assumes a mappable invoice.
 */
final class XRechnungInvoiceMapper {

	/**
	 * Allowance reason code 95 = "Discount" (UNTDID 5189).
	 */
	private const DISCOUNT_REASON_CODE = '95';

	/**
	 * @param array<string, mixed> $invoice Invoice data.
	 * @param array<string, mixed> $seller  Seller profile.
	 * @param array<string, mixed> $buyer   Buyer data.
	 * @return array<string, mixed> Mapped document for {@see XRechnungBuilder}.
	 */
	public static function map( array $invoice, array $seller, array $buyer ): array {
		$number = self::str( $invoice['invoice_number'] ?? '' );
		$lines  = array();
		$groups = array();

		$items    = is_array( $invoice['line_items'] ?? null ) ? $invoice['line_items'] : array();
		$position = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['optional'] ) ) {
				continue;
			}
			++$position;

			$line    = self::map_line( $item, $position, $seller );
			$lines[] = $line;

			$key = $line['category'] . '|' . self::fmt_rate( $line['rate'] );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'category' => $line['category'],
					'rate'     => $line['rate'],
					'net'      => 0.0,
				);
			}
			$groups[ $key ]['net'] = self::money( $groups[ $key ]['net'] + $line['line_extension'] );
		}

		// Before-tax and after-tax discounts are the same percentage off each
		// VAT group's net (after-tax also takes it off the VAT, which the
		// per-group recalculation below does implicitly). EN 16931 needs a
		// document-level allowance per VAT category (BR-32), so split it.
		$discount_type  = self::str( $invoice['discount_type'] ?? DiscountType::NONE );
		$discount_value = (float) ( $invoice['discount_value'] ?? 0 );
		$percent        = in_array( $discount_type, array( DiscountType::BEFORE_TAX, DiscountType::AFTER_TAX ), true ) && $discount_value > 0
			? min( 100.0, $discount_value )
			: 0.0;

		$allowances    = array();
		$tax_subtotals = array();
		foreach ( $groups as $group ) {
			$allowance = 0.0;
			if ( $percent > 0 ) {
				$allowance = self::money( $group['net'] * $percent / 100 );
				if ( 0.0 !== $allowance ) {
					$allowances[] = array(
						'amount'      => $allowance,
						'base_amount' => $group['net'],
						'percent'     => $percent,
						'category'    => $group['category'],
						'rate'        => $group['rate'],
						'reason'      => 'Discount',
						'reason_code' => self::DISCOUNT_REASON_CODE,
					);
				}
			}

			$taxable         = self::money( $group['net'] - $allowance );
			$tax_subtotals[] = array_merge(
				array(
					'category' => $group['category'],
					'rate'     => $group['rate'],
					'taxable'  => $taxable,
					'tax'      => self::money( $taxable * $group['rate'] / 100 ),
				),
				self::exemption( $group['category'], $seller )
			);
		}

		$line_extension  = self::money( array_sum( array_column( $lines, 'line_extension' ) ) );
		$allowance_total = self::money( array_sum( array_column( $allowances, 'amount' ) ) );
		$tax_exclusive   = self::money( $line_extension - $allowance_total );
		$tax_total       = self::money( array_sum( array_column( $tax_subtotals, 'tax' ) ) );
		$tax_inclusive   = self::money( $tax_exclusive + $tax_total );
		$prepaid         = self::money( (float) ( $invoice['amount_paid'] ?? 0 ) );
		// The stored total already carries the untaxed adjustment and was
		// computed from unrounded lines; the difference is the payable rounding
		// amount (BT-114), which keeps BR-CO-16 true and the payable amount
		// identical to the PDF.
		$rounding = self::money( (float) ( $invoice['total'] ?? 0 ) - $tax_inclusive );
		$payable  = self::money( $tax_inclusive - $prepaid + $rounding );

		$notes = array();
		$note  = self::plain( $invoice['note'] ?? '' );
		if ( '' !== $note ) {
			$notes[] = $note;
		}

		$buyer_reference = self::str( $invoice['buyer_reference'] ?? '' );

		// § 14 UStG: the date of supply. A period is BG-14, a single date
		// BT-72; nothing entered means the service date is the invoice date.
		$issue_date    = XRechnungValidator::date( $invoice['invoice_date'] ?? '' );
		$service_start = XRechnungValidator::date( $invoice['service_date'] ?? '' );
		$service_end   = XRechnungValidator::date( $invoice['service_date_end'] ?? '' );
		$invoice_period = null;
		$delivery_date  = $service_start ?? $issue_date;
		if ( null !== $service_start && null !== $service_end && $service_end >= $service_start ) {
			$invoice_period = array(
				'start' => $service_start,
				'end'   => $service_end,
			);
			$delivery_date  = null;
		}

		$seller_vat = self::vat_id( self::str( $seller['vat_id'] ?? '' ) );
		$buyer_vat  = self::vat_id( self::str( $buyer['vat_id'] ?? '' ) );

		return array(
			'number'          => $number,
			'issue_date'      => $issue_date,
			'delivery_date'   => $delivery_date,
			'invoice_period'  => $invoice_period,
			'due_date'        => XRechnungValidator::date( $invoice['due_date'] ?? '' ),
			'currency'        => strtoupper( self::str( $invoice['currency'] ?? 'EUR' ) ),
			'buyer_reference' => '' !== $buyer_reference ? $buyer_reference : $number,
			'notes'           => $notes,
			'payment_terms'   => self::plain( $invoice['terms'] ?? '' ),
			'seller'          => array(
				'name'                => self::str( $seller['name'] ?? '' ),
				'street'              => self::str( $seller['street'] ?? '' ),
				'postcode'            => self::str( $seller['postcode'] ?? '' ),
				'city'                => self::str( $seller['city'] ?? '' ),
				'country'             => CountryCode::to_alpha2( self::str( $seller['country'] ?? '' ) ),
				'vat_id'              => $seller_vat,
				// A value that is not a prefixed VAT ID is a national tax
				// number (Steuernummer) — BT-32 rather than BT-31 (BR-CO-09).
				'tax_number'          => null === $seller_vat && '' !== self::str( $seller['vat_id'] ?? '' ) ? self::str( $seller['vat_id'] ) : null,
				'registration_number' => self::str( $seller['registration_number'] ?? '' ),
				'email'               => self::str( $seller['email'] ?? '' ),
				'contact_name'        => self::str( $seller['contact_name'] ?? '' ),
				'contact_phone'       => self::str( $seller['contact_phone'] ?? '' ),
			),
			'buyer'           => array(
				'name'                => self::str( $buyer['name'] ?? '' ),
				'street'              => self::str( $buyer['street'] ?? '' ),
				'additional_street'   => self::str( $buyer['additional_street'] ?? '' ),
				'postcode'            => self::str( $buyer['postcode'] ?? '' ),
				'city'                => self::str( $buyer['city'] ?? '' ),
				'state'               => self::str( $buyer['state'] ?? '' ),
				'country'             => CountryCode::to_alpha2( self::str( $buyer['country'] ?? '' ) ),
				'vat_id'              => $buyer_vat,
				'registration_number' => self::str( $buyer['registration_number'] ?? '' ),
				'email'               => self::str( $buyer['email'] ?? '' ),
			),
			'payment'         => array(
				// 58 = SEPA credit transfer.
				'means_code'   => '58',
				'payment_id'   => $number,
				'iban'         => XRechnungValidator::normalize_account( self::str( $seller['iban'] ?? '' ) ),
				'bic'          => XRechnungValidator::normalize_account( self::str( $seller['bic'] ?? '' ) ),
				'account_name' => self::str( $seller['account_name'] ?? '' ),
			),
			'allowances'      => $allowances,
			'tax_subtotals'   => $tax_subtotals,
			'totals'          => array(
				'line_extension'  => $line_extension,
				'allowance_total' => $allowance_total,
				'tax_exclusive'   => $tax_exclusive,
				'tax_total'       => $tax_total,
				'tax_inclusive'   => $tax_inclusive,
				'prepaid'         => $prepaid,
				'rounding'        => $rounding,
				'payable'         => $payable,
			),
			'lines'           => $lines,
		);
	}

	/**
	 * Tax rates on a line item, in percent.
	 *
	 * @param array<string, mixed> $item Line item.
	 * @return float[]
	 */
	public static function line_tax_rates( array $item ): array {
		$rates = array();
		$taxes = isset( $item['tax'] ) && is_array( $item['tax'] ) ? $item['tax'] : array();
		foreach ( $taxes as $tax ) {
			if ( is_array( $tax ) ) {
				$rates[] = isset( $tax['rate'] ) ? (float) $tax['rate'] : 0.0;
			}
		}

		return $rates;
	}

	/**
	 * Prefixes that actually issue VAT identifiers: the EU member states
	 * (Greece as EL), Northern Ireland (XI), the UK, Switzerland and Norway.
	 * Checking only "two letters" is not enough — "VAT-98765" starts with
	 * VA, the Vatican's ISO code, and was exported as a VAT ID.
	 */
	private const VAT_PREFIXES = array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'XI', 'GB', 'CH', 'NO' );

	/**
	 * A VAT identifier with its country prefix, or null (BR-CO-09).
	 *
	 * @param string $value Raw value.
	 * @return string|null
	 */
	public static function vat_id( string $value ): ?string {
		$value = strtoupper( (string) preg_replace( '/[\s.\-]/', '', $value ) );

		if ( ! preg_match( '/^([A-Z]{2})([0-9A-Z+*]{2,13})$/', $value, $parts ) ) {
			return null;
		}

		// A real VAT number carries digits after the prefix.
		return in_array( $parts[1], self::VAT_PREFIXES, true ) && preg_match( '/\d/', $parts[2] ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $item     Line item.
	 * @param int                  $position 1-based line position.
	 * @param array<string, mixed> $seller   Seller profile.
	 * @return array<string, mixed>
	 */
	private static function map_line( array $item, int $position, array $seller ): array {
		// Same resolution as TotalsCalculator: legacy `quantity`, then one unit.
		$qty    = isset( $item['qty'] ) ? (float) $item['qty'] : ( isset( $item['quantity'] ) ? (float) $item['quantity'] : 1.0 );
		$price  = isset( $item['rate'] ) ? (float) $item['rate'] : 0.0;
		$amount = isset( $item['amount'] ) ? (float) $item['amount'] : ( $qty * $price );

		// BR-27: the item net price must not be negative — carry the sign on
		// the quantity instead (a credit line).
		if ( $price < 0 ) {
			$price = -$price;
			$qty   = -$qty;
		}

		$net   = self::money( $amount );
		$gross = self::money( $qty * $price );
		$diff  = self::money( $gross - $net );

		// A per-line discount is baked into `amount` by the editor; express the
		// gap as a line allowance (or charge) so qty × price reconciles.
		$adjustment = null;
		if ( 0.0 !== $diff ) {
			$adjustment = array(
				'charge'      => $diff < 0,
				'amount'      => abs( $diff ),
				'base_amount' => abs( $gross ),
				'reason'      => $diff > 0 ? 'Discount' : 'Surcharge',
				'reason_code' => $diff > 0 ? self::DISCOUNT_REASON_CODE : 'ZZZ',
			);
		}

		$rates    = self::line_tax_rates( $item );
		$rate     = (float) ( $rates[0] ?? 0.0 );
		$category = XRechnungValidator::category( $rate, $seller );
		if ( 'S' !== $category ) {
			$rate = 0.0;
		}

		$name        = self::plain( $item['description'] ?? '' );
		$description = self::plain( $item['long_description'] ?? '' );
		// The editor lets a line carry only a long description; use it as the
		// item name rather than a placeholder, and do not repeat it.
		if ( '' === $name && '' !== $description ) {
			$name        = $description;
			$description = '';
		}
		if ( '' === $name ) {
			/* translators: %d: line position */
			$name = sprintf( __( 'Item %d', 'doublescale' ), $position );
		}

		return array(
			'id'             => (string) $position,
			'name'           => $name,
			'description'    => $description,
			'quantity'       => $qty,
			'unit'           => UnitCode::from( isset( $item['unit'] ) ? (string) $item['unit'] : '' ),
			'price'          => $price,
			'line_extension' => $net,
			'category'       => $category,
			'rate'           => $rate,
			'adjustment'     => $adjustment,
		);
	}

	/**
	 * Exemption reason/code per category (BR-E-10, BR-AE-10, BR-Z-10).
	 *
	 * @param string               $category VAT category.
	 * @param array<string, mixed> $seller   Seller profile.
	 * @return array{exemption_reason: string|null, exemption_code: string|null}
	 */
	private static function exemption( string $category, array $seller ): array {
		if ( 'E' === $category ) {
			return array(
				'exemption_reason' => self::str( $seller['exemption_reason'] ?? '' ),
				'exemption_code'   => null,
			);
		}
		if ( 'AE' === $category ) {
			return array(
				'exemption_reason' => 'Reverse charge',
				'exemption_code'   => 'VATEX-EU-AE',
			);
		}

		return array(
			'exemption_reason' => null,
			'exemption_code'   => null,
		);
	}

	/**
	 * @param float $value Amount.
	 * @return float
	 */
	private static function money( float $value ): float {
		$rounded = round( $value, 2 );

		// Normalise -0.0 so comparisons and output stay clean.
		return 0.0 === $rounded ? 0.0 : $rounded;
	}

	/**
	 * @param float $rate Rate.
	 * @return string
	 */
	private static function fmt_rate( float $rate ): string {
		return rtrim( rtrim( number_format( $rate, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Strip HTML (notes and terms are rich text) to plain text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function plain( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		$text = (string) preg_replace( '#<(br|/p|/div|/li)\b[^>]*>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+/", ' ', $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function str( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
