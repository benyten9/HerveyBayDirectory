<?php
/**
 * Name.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * First and last name.
 */
class NameField extends PartsField {

	/** {@inheritDoc} */
	public function type(): string {
		return 'name';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Name', 'doublescale' );
	}

	/** {@inheritDoc} */
	public function parts(): array {
		return array( 'first', 'last' );
	}

	/** {@inheritDoc} */
	public function contact_fallback(): string {
		return 'name';
	}

	/** {@inheritDoc} */
	public function contact_parts( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		$data = array();
		if ( ! empty( $value['first'] ) ) {
			$data['first_name'] = $value['first'];
		}
		if ( ! empty( $value['last'] ) ) {
			$data['last_name'] = $value['last'];
		}
		return $data;
	}

	/** {@inheritDoc} */
	protected function glue(): string {
		return ' ';
	}
}
