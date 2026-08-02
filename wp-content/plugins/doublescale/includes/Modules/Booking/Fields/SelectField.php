<?php
/**
 * Class Select_Field
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Fields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\FieldType;

/**
 * Select_Field class
 */
class SelectField extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Select Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'select';

	/**
	 * Is Value Array
	 *
	 * @var boolean
	 */
	protected $is_value_array = false;

	/**
	 * Has Options
	 *
	 * @var boolean
	 */
	public $has_options = true;

	/**
	 * Sanitize
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function sanitize_field( $value ) {
		return sanitize_text_field( $value );
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

		if ( ! in_array( $value, array_keys( $this->options ) ) ) {
			$this->is_valid = false;
			/* translators: %s: field label */
			$this->validation_err = sprintf( __( '%s is not a valid option', 'doublescale' ), $this->label );
		}
	}
}
