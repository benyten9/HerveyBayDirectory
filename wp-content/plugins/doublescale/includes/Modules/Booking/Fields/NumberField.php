<?php
/**
 * Class Number_Field
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Fields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\FieldType;

/**
 * Number_Field class
 */
class NumberField extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Number Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'number';

	/**
	 * Is Value Array
	 *
	 * @var boolean
	 */
	protected $is_value_array = false;

	/**
	 * Sanitize
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function sanitize_field( $value ) {
		return absint( $value );
	}

	/**
	 * Validate
	 *
	 * @param mixed $value
	 *
	 * @return boolean
	 */
	public function validate_value( $value ) {
		if ( empty( $value ) && $this->is_required ) {
			$this->is_valid = false;
			/* translators: %s: field label */
			$this->validation_err = sprintf( __( '%s is required', 'doublescale' ), $this->label );
			return;
		}

		if ( ! is_numeric( $value ) ) {
			$this->is_valid = false;
			/* translators: %s: field label */
			$this->validation_err = sprintf( __( '%s is not a valid number', 'doublescale' ), $this->label );
		}
	}
}
