<?php

/**
 * Class TextField
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
 * TextField class
 */
class TextField extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Text Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'text';

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

		if ( ! is_string( $value ) ) {
			$this->is_valid       = false;
			$this->validation_err = 'This field must be a string';
		}
	}
}

if ( class_exists( CustomFieldsManager::class ) ) {
	CustomFieldsManager::instance()->register( new TextField() );
}
