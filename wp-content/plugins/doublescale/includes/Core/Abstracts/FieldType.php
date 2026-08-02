<?php

/**
 * Abstract class FieldType
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Abstracts;

defined( 'ABSPATH' ) || exit;

/**
 * FieldType class
 */
abstract class FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

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
	 * Attributes
	 *
	 * @var array
	 */
	protected $attributes = array();

	/**
	 * Is Value Array
	 *
	 * @return bool
	 */
	public function is_value_array() {
		return $this->is_value_array;
	}

	/**
	 * Constructor
	 *
	 * @param array $args Args.
	 */
	public function __construct( $args = array() ) {
		$this->is_required = isset( $args['is_required'] ) ? $args['is_required'] : false;
		$this->attributes  = isset( $args['attributes'] ) ? $args['attributes'] : array();
	}

	/**
	 * Sanitize Value
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	abstract public function sanitize_field( $value );

	/**
	 * Validate Value
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	abstract public function validate_value( $value );

	/**
	 * Format Value
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	public function format_value( $value ) {
		return $value;
	}
}
