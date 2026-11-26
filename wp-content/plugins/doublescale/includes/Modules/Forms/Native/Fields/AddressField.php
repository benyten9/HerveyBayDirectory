<?php
/**
 * Address.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * Street, second line, city, state, postal code and country.
 */
class AddressField extends PartsField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'address';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Address', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function parts(): array {
		return array( 'address_1', 'address_2', 'city', 'state', 'zip', 'country' );
	}

	/** {@inheritDoc} */
	public function contact_parts( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$data = array();
		foreach ( $this->parts() as $part ) {
			if ( ! empty( $value[ $part ] ) ) {
				$data[ $part ] = 'country' === $part && function_exists( 'doublescale_get_country_code' ) ? doublescale_get_country_code( $value[ $part ] ) : $value[ $part ];
			}
		}
		return $data;
	}
}
