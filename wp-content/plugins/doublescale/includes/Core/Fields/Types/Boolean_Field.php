<?php

/**
 * Boolean field type (distinct from checkbox in schema / UI).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Core\Fields\Types;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\FieldType;
use DoubleScale\Pro\Modules\CustomFields\CustomFieldsManager;

/**
 * Boolean_Field class
 */
class Boolean_Field extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Boolean';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'boolean';

	/**
	 * Is Value Array
	 *
	 * @var boolean
	 */
	protected $is_value_array = false;

	/**
	 * Sanitize
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function sanitize_field( $value ) {
		return $value === 'true' || $value === true ? 'true' : 'false';
	}

	/**
	 * Validate
	 *
	 * @param mixed $value Value.
	 * @return void
	 */
	public function validate_value( $value ) {
		if ( $value !== 'true' && $value !== 'false' && $value !== true && $value !== false ) {
			$this->is_valid       = false;
			$this->validation_err = 'This field must be true or false';
		}
	}
}

if ( class_exists( CustomFieldsManager::class ) ) {
	CustomFieldsManager::instance()->register( new Boolean_Field() );
}
