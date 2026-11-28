<?php
/**
 * Closed compound native pricing values; no currency or settings writes.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Validation\ContractException;

/** Keep the three native pricing controls together through review and readback. */
final class ListingPricingValues {

	/** Describe the actual native mode, currency and range choices.
	 *
	 * @param array $props Current native field settings.
	 * @return object
	 * @throws ContractException When the native mode is unavailable.
	 */
	public static function contract( array $props ): object {
		$mode = $props['pricing_type'] ?? '';
		if ( ! in_array( $mode, array( 'both', 'price_unit', 'price_range' ), true ) ) {
			throw new ContractException( 'unsupported_contract', 'The native pricing mode is unavailable.' );
		}
		return (object) array(
			'mode'          => $mode,
			'types'         => 'both' === $mode ? array( 'price', 'range' ) : array( 'price_unit' === $mode ? 'price' : 'range' ),
			'allow_decimal' => (bool) get_directorist_option( 'allow_decimal', 1 ),
			'currency'      => (string) directorist_get_currency(),
			'ranges'        => array(
				(object) array(
					'value' => 'skimming',
					'label' => __( 'Ultra High', 'directorist-ai' ),
				),
				(object) array(
					'value' => 'moderate',
					'label' => __( 'Moderate', 'directorist-ai' ),
				),
				(object) array(
					'value' => 'economy',
					'label' => __( 'Economy', 'directorist-ai' ),
				),
				(object) array(
					'value' => 'bellow_economy',
					'label' => __( 'Cheap', 'directorist-ai' ),
				),
			),
		);
	}

	/** Decode the existing inspector's exact type/price/range shape.
	 *
	 * @param string $wire Bound native transport value.
	 * @return object
	 * @throws ContractException When transport is not the closed native shape.
	 */
	public static function decode( string $wire ): object {
		$value = '' === $wire ? (object) array(
			'type'  => '',
			'price' => '',
			'range' => '',
		) : json_decode( $wire );
		if ( ! $value instanceof \stdClass || count( get_object_vars( $value ) ) !== 3 || array_diff( array( 'type', 'price', 'range' ), array_keys( get_object_vars( $value ) ) ) ) {
			throw new ContractException( 'invalid_input', 'Use a native pricing object with type, price and range.' );
		}
		foreach ( array( 'type', 'price', 'range' ) as $key ) {
			if ( ! is_string( $value->$key ) || strlen( $value->$key ) > 64 ) {
				throw new ContractException( 'invalid_input', 'Native pricing values must be bounded strings.' );
			}
		}
		return $value;
	}

	/** Encode all three siblings in one stable transport value.
	 *
	 * @param object $value Decoded native siblings.
	 * @return string
	 */
	public static function wire( object $value ): string {
		return wp_json_encode(
			(object) array(
				'type'  => (string) $value->type,
				'price' => (string) $value->price,
				'range' => (string) $value->range,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/** Build the real native Pricing_Field request shape.
	 *
	 * @param object $value Decoded native siblings.
	 * @return array
	 */
	public static function posted( object $value ): array {
		return array(
			'atbd_listing_pricing' => $value->type,
			'price'                => $value->price,
			'price_range'          => $value->range,
		);
	}

	/** Validate without silently converting, rounding, or clearing inactive values.
	 *
	 * @param array  $props Current native field settings.
	 * @param string $wire Proposed native transport value.
	 * @return string
	 * @throws ContractException When the native value is invalid.
	 */
	public static function sanitize( array $props, string $wire ): string {
		$value    = self::decode( $wire );
		$contract = self::contract( $props );
		if ( ! in_array( $value->type, $contract->types, true ) && ! ( '' === $value->type && 'both' === $contract->mode && empty( $props['required'] ) ) ) {
			throw new ContractException( 'invalid_input', 'Choose a price type supported by this native directory.' );
		}
		if ( '' !== $value->price && ( ! preg_match( $contract->allow_decimal ? '/^[0-9]+(?:\.[0-9]{1,2})?$/D' : '/^[0-9]+$/D', $value->price ) || ! is_finite( (float) $value->price ) ) ) {
			throw new ContractException( 'invalid_input', 'Use a non-negative price within the native decimal format.' );
		}
		if ( '' !== $value->range && ! in_array( $value->range, array_column( $contract->ranges, 'value' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Choose an exact native price range.' );
		}
		if ( ( 'price' === $value->type && '' === $value->price ) || ( 'range' === $value->type && '' === $value->range ) ) {
			throw new ContractException( 'invalid_input', 'The selected native price type needs its supplied value.' );
		}
		$field = \Directorist\Fields\Fields::create( $props );
		if ( ! $field->validate( self::posted( $value ) ) ) {
			throw new ContractException( 'invalid_input', 'The pricing value does not meet the native field contract.' );
		}
		return self::wire( $value );
	}

	/** An active price edit cannot silently erase the other native sibling.
	 *
	 * @param string $before Captured native preimage.
	 * @param string $after Validated proposal.
	 * @return void
	 * @throws ContractException When an inactive sibling changed.
	 */
	public static function preserve_inactive( string $before, string $after ): void {
		$old = self::decode( $before );
		$new = self::decode( $after );
		if ( ( 'price' !== $new->type && $old->price !== $new->price ) || ( 'range' !== $new->type && $old->range !== $new->range ) ) {
			throw new ContractException( 'scope_mismatch', 'Keep inactive native pricing values unchanged.' );
		}
	}

	/** Read all three native meta values, including the inactive sibling.
	 *
	 * @param int $id Authorized native listing identity.
	 * @return object
	 */
	public static function read( int $id ): object {
		return (object) array(
			'type'  => (string) get_post_meta( $id, '_atbd_listing_pricing', true ),
			'price' => (string) get_post_meta( $id, '_price', true ),
			'range' => (string) get_post_meta( $id, '_price_range', true ),
		);
	}

	/** Show labels grounded in the actual native currency and range choices.
	 *
	 * @param array  $props Current native field settings.
	 * @param string $wire Bound native transport value.
	 * @return string
	 */
	public static function display( array $props, string $wire ): string {
		$value    = self::decode( $wire );
		$contract = self::contract( $props );
		return 'price' === $value->type ? trim( $contract->currency . ' ' . $value->price ) : ( 'range' === $value->type ? ( array_column( $contract->ranges, 'label', 'value' )[ $value->range ] ?? $value->range ) : '' );
	}
}
