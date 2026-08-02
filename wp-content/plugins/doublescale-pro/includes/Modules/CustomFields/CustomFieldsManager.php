<?php

/**
 * Class Custom_Fields Manager
 * This class is responsible for handling the custom_fields
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\CustomFields
 */

namespace DoubleScale\Pro\Modules\CustomFields;

use Exception;
use DoubleScale\Core\Abstracts\FieldType as Custom_Field;

/**
 * Custom_Fields class
 */
final class CustomFieldsManager
{
	/**
	 * Registed custom_fields
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $custom_fields = array();

	/**
	 * Options
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * @deprecated Retained for backward compatibility; prefer container resolution.
	 * @var CustomFieldsManager|null
	 */
	private static $instance;

	/**
	 * Get the singleton instance.
	 *
	 * The DI container is registered to call this method. Do not resolve the
	 * same FQCN from within here or the container will recurse until the
	 * process runs out of memory.
	 *
	 * @since 1.0.0
	 *
	 * @return CustomFieldsManager
	 */
	public static function instance()
	{
		if (is_null(self::$instance)) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register Custom_Field
	 *
	 * @since 1.0.0
	 *
	 * @param Custom_Field $custom_field
	 * @return void
	 */
	public function register(Custom_Field $custom_field)
	{
		if (! $custom_field instanceof Custom_Field) {
			throw new Exception(__('Invalid custom_field', 'doublescale'));
		}

		if (isset($this->custom_fields[$custom_field->slug])) {
			throw new Exception(sprintf(__('Custom_Field %s already registered', 'doublescale'), $custom_field->name));
		}

		$this->custom_fields[$custom_field->slug] = $custom_field;
		$this->options[$custom_field->slug]       = array(
			'name' => $custom_field->name,
		);
	}

	/**
	 * Get Custom_Field
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug
	 * @return Custom_Field
	 */
	public function get_custom_field($slug)
	{
		if (isset($this->custom_fields[$slug])) {
			return $this->custom_fields[$slug];
		}

		throw new Exception(sprintf(__('Custom_Field %s not found', 'doublescale'), $slug));
	}

	/**
	 * Get Custom_Fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_custom_fields()
	{
		return $this->custom_fields;
	}

	/**
	 * Get Options
	 *
	 * @return array
	 */
	public function get_options()
	{
		return $this->options;
	}
}
