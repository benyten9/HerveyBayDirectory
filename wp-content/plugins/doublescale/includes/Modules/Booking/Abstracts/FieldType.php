<?php
/**
 * Abstract class FieldType
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Traits\EntityProperties;

/**
 * FieldType class
 */
abstract class FieldType {

	use \DoubleScale\Modules\Booking\Traits\EntityProperties;

	/**
	 * Is Valid
	 *
	 * @var bool
	 */
	public $is_valid = true;

	/**
	 * Validation Error message
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $validation_err = null;

	/**
	 * Is value array
	 *
	 * @var boolean
	 */
	protected $is_value_array = false;

	/**
	 * Is Required
	 *
	 * @var boolean
	 */
	public $is_required = false;

	/**
	 * Has options
	 *
	 * @var boolean
	 */
	public $has_options = false;

	/**
	 * Multiple
	 *
	 * @var boolean
	 */
	public $multiple = false;

	/**
	 * Attributes
	 *
	 * @var string
	 */
	protected $label;

	/**
	 * Constructor
	 */
	public function __construct( $args = array() ) {
		$this->is_required = isset( $args['is_required'] ) ? $args['is_required'] : false;
		$this->label       = isset( $args['label'] ) ? $args['label'] : $this->name;
	}

	/**
	 * Is Value Array
	 *
	 * @return bool
	 */
	public function is_value_array() {
		return $this->is_value_array;
	}

	/**
	 * Sanitize Value
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	abstract public function sanitize_field( $value );

	/**
	 * Validate Value
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	abstract public function validate_value( $value );

	/**
	 * Format Value
	 *
	 * @param mixed $value
	 */
	public function format_value( $value ) {
		return $value;
	}
}
