<?php
/**
 * Document discount type constants and validation.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents\Constants;

use DoubleScale\Modules\Documents\Services\TotalsCalculator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * DiscountType constants and validation helpers.
 */
class DiscountType {

	const NONE       = 'none';
	const PERCENT    = 'percent';
	const FIXED      = 'fixed';
	const BEFORE_TAX = 'before_tax';
	const AFTER_TAX  = 'after_tax';

	/**
	 * @return string[]
	 */
	public static function valid_types(): array {
		return array(
			self::NONE,
			self::PERCENT,
			self::FIXED,
			self::BEFORE_TAX,
			self::AFTER_TAX,
		);
	}

	/**
	 * @param string $type Discount type.
	 */
	public static function is_valid( string $type ): bool {
		return in_array( $type, self::valid_types(), true );
	}

	/**
	 * @param string $type Discount type.
	 */
	public static function is_percent_based( string $type ): bool {
		return in_array( $type, array( self::PERCENT, self::BEFORE_TAX, self::AFTER_TAX ), true );
	}

	/**
	 * Validate discount value for the given type.
	 *
	 * @param string     $type     Discount type.
	 * @param float      $value    Discount value.
	 * @param float|null $subtotal Document subtotal for fixed discount checks.
	 * @return true|WP_Error
	 */
	public static function validate_value( string $type, float $value, ?float $subtotal = null ) {
		if ( self::NONE === $type || $value <= 0 ) {
			return true;
		}

		if ( $value < 0 ) {
			return new WP_Error(
				'invalid_discount',
				__( 'Discount cannot be negative.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( self::is_percent_based( $type ) && $value > 100 ) {
			return new WP_Error(
				'invalid_discount',
				__( 'Discount percentage cannot exceed 100%.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		if ( self::FIXED === $type && null !== $subtotal && $value > $subtotal ) {
			return new WP_Error(
				'invalid_discount',
				__( 'Fixed discount cannot exceed the document subtotal.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Validate discount fields from a REST payload (optionally merged with an existing document).
	 *
	 * @param array<string, mixed> $payload  Sanitized request payload.
	 * @param object|null          $existing Existing document model, if updating.
	 * @return true|WP_Error
	 */
	public static function validate_payload( array $payload, $existing = null ) {
		$type = isset( $payload['discount_type'] )
			? (string) $payload['discount_type']
			: ( $existing ? (string) ( $existing->discount_type ?? self::NONE ) : self::NONE );

		if ( isset( $payload['discount_type'] ) && ! self::is_valid( $type ) ) {
			return new WP_Error(
				'invalid_discount_type',
				__( 'Invalid discount type.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$value = array_key_exists( 'discount_value', $payload )
			? (float) $payload['discount_value']
			: (float) ( $existing->discount_value ?? 0 );

		$line_items = $payload['line_items'] ?? ( $existing->line_items ?? null );
		$subtotal   = null;

		if ( is_array( $line_items ) && array() !== $line_items ) {
			$computed = TotalsCalculator::compute( $line_items, self::NONE, 0.0 );
			$subtotal = (float) $computed['subtotal'];
		}

		return self::validate_value( $type, $value, $subtotal );
	}
}
