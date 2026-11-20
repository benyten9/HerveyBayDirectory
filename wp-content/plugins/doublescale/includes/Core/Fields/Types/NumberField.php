<?php

/**
 * Class NumberField
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Core\Fields\Types;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\FieldType;
use DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager;

/**
 * NumberField class
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
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		// Prices, budgets and balances keep their decimals and sign (absint() turned 19.99 into 19
		// and -5 into 5). Anything that is not a number is left as it is, so validation skips it
		// instead of saving a made-up 0.
		return is_numeric( $value ) ? $value + 0 : $value;
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
			$this->is_valid       = false;
			$this->validation_err = 'This field is required';
			return;
		}

		if ( ! is_numeric( $value ) ) {
			$this->is_valid       = false;
			$this->validation_err = 'This field must be a number';
		}
	}
}

if ( class_exists( CustomFieldsManager::class ) ) {
	CustomFieldsManager::instance()->register( new NumberField() );
}
