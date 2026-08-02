<?php

/**
 * Class CheckboxField
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
 * CheckboxField class
 */
class CheckboxField extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Checkbox Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'checkbox';

	/**
	 * Is Value Array
	 *
	 * @var boolean
	 */
	protected $is_value_array = true;

	/**
	 * Sanitize
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public function sanitize_field( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'sanitize_text_field', $value );
		}
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
			$this->is_valid       = false;
			$this->validation_err = 'This field is required';
			return;
		}

		// For checkbox fields, validation of options should be done against the field's attributes
		// This is handled in the CustomFieldModel validation
	}
}

if ( class_exists( CustomFieldsManager::class ) ) {
	CustomFieldsManager::instance()->register( new CheckboxField() );
}
