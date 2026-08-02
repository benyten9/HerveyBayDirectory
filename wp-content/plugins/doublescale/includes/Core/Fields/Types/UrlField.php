<?php

/**
 * Class UrlField
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
 * UrlField class
 */
class UrlField extends FieldType {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'URL Field';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'url';

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
		return esc_url_raw( $value );
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

		if ( ! empty( $value ) && ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
			$this->is_valid       = false;
			$this->validation_err = 'Please enter a valid URL';
		}
	}
}

if ( class_exists( CustomFieldsManager::class ) ) {
	CustomFieldsManager::instance()->register( new UrlField() );
}
