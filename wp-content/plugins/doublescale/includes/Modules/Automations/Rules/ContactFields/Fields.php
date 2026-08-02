<?php

/**
 * Class Fields
 *
 * This class is responsible for handling the contact custom fields rule.
 * Each custom field with scope 'contact' is registered as a separate rule
 * with type-appropriate operators and options.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Rules\ContactFields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use DoubleScale\Modules\Automations\Services\RulesManager;

/**
 * Fields class
 */
class Fields extends Rule {


	/**
	 * Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name;

	/**
	 * Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug;

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'contact_fields';

	/**
	 * Custom Field
	 *
	 * @var CustomFieldModel
	 *
	 * @since 1.0.0
	 */
	public $custom_field;

	/**
	 * Type
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $type = 'text';

	/**
	 * Available in contacts list advanced filters (not automation-only).
	 *
	 * @var bool
	 */
	public $is_automation = false;

	/**
	 * Constructor
	 */
	public function __construct( $custom_field ) {
		$this->custom_field = $custom_field;
		$this->name         = $custom_field->name;
		$this->slug         = 'contact_field_' . $custom_field->id;
		$this->type         = $this->map_field_type( $custom_field->type );
	}

	/**
	 * Map custom field type to rule type
	 *
	 * @param string $field_type The custom field type.
	 *
	 * @return string
	 */
	protected function map_field_type( $field_type ) {
		$type_map = array(
			'text'        => 'text',
			'textarea'    => 'text',
			'email'       => 'text',
			'phone'       => 'text',
			'url'         => 'text',
			'number'      => 'number',
			'date'        => 'date',
			'select'      => 'select',
			'multiselect' => 'multiselect',
			'radio'       => 'select',
			'checkbox'    => 'multiselect',
			'boolean'     => 'select',
		);

		return $type_map[ $field_type ] ?? 'text';
	}

	/**
	 * Get operators based on field type
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_operators() {
		switch ( $this->custom_field->type ) {
			case 'number':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'greater_than' => __( 'Greater than', 'doublescale' ),
					'lower_than'   => __( 'Less than', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'date':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'greater_than' => __( 'Is after', 'doublescale' ),
					'lower_than'   => __( 'Is before', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'select':
			case 'radio':
				return array(
					'is'           => __( 'Is', 'doublescale' ),
					'is_not'       => __( 'Is not', 'doublescale' ),
					'is_empty'     => __( 'Is empty', 'doublescale' ),
					'is_not_empty' => __( 'Is not empty', 'doublescale' ),
				);

			case 'multiselect':
			case 'checkbox':
				return array(
					'contains'         => __( 'Contains', 'doublescale' ),
					'does_not_contain' => __( 'Does not contain', 'doublescale' ),
					'is_empty'         => __( 'Is empty', 'doublescale' ),
					'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
				);

			case 'boolean':
				return array(
					'is' => __( 'Is', 'doublescale' ),
				);

			default:
				return array(
					'is'               => __( 'Is', 'doublescale' ),
					'is_not'           => __( 'Is not', 'doublescale' ),
					'contains'         => __( 'Contains', 'doublescale' ),
					'does_not_contain' => __( 'Does not contain', 'doublescale' ),
					'starts_with'      => __( 'Starts with', 'doublescale' ),
					'ends_with'        => __( 'Ends with', 'doublescale' ),
					'is_empty'         => __( 'Is empty', 'doublescale' ),
					'is_not_empty'     => __( 'Is not empty', 'doublescale' ),
				);
		}
	}

	/**
	 * Has options
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function has_options() {
		return in_array( $this->custom_field->type, array( 'select', 'multiselect', 'radio', 'checkbox', 'boolean' ), true );
	}

	/**
	 * Get options for select-type fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_options() {
		if ( 'boolean' === $this->custom_field->type ) {
			return array(
				'true'  => __( 'Checked', 'doublescale' ),
				'false' => __( 'Unchecked', 'doublescale' ),
			);
		}

		$attributes = $this->custom_field->attributes;
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return array();
		}

		// Some custom-field sources store options under an "options" key:
		// { "options": [ ... ] }. Unwrap it so we iterate the actual list.
		if ( isset( $attributes['options'] ) && is_array( $attributes['options'] ) ) {
			$attributes = $attributes['options'];
		}

		$options = array();
		foreach ( $attributes as $attribute ) {
			if ( is_array( $attribute ) && isset( $attribute['value'] ) ) {
				$options[ $attribute['value'] ] = $attribute['label'] ?? $attribute['value'];
			} elseif ( is_scalar( $attribute ) ) {
				// Plain scalar option (e.g. "free") — use it as both key and label.
				$options[ $attribute ] = $attribute;
			}
			// Any other shape (nested array without a 'value') is skipped rather
			// than used as an array key, which would be an illegal offset.
		}

		return $options;
	}

	/**
	 * Get value
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact Contact Model.
	 *
	 * @return mixed
	 */
	public function get_value( $automation_contact ) {
		$contact = $automation_contact->contact;
		if ( ! $contact ) {
			return '';
		}

		$value = $contact->get_custom_field( $this->custom_field->id );

		if ( in_array( $this->custom_field->type, array( 'multiselect', 'checkbox' ), true ) && is_string( $value ) ) {
			return array_map( 'trim', explode( ',', $value ) );
		}

		return $value ?? '';
	}
}

if ( class_exists( CustomFieldModel::class ) ) {
	( static function () {
		if ( function_exists( 'doublescale_is_module_storage_ready' )
			&& ! doublescale_is_module_storage_ready( 'custom-fields', CustomFieldModel::class ) ) {
			return;
		}

		$custom_fields = CustomFieldModel::where( 'scope', 'contact' )->get();

		if ( ! empty( $custom_fields ) ) {
			foreach ( $custom_fields as $custom_field ) {
				$rule = new Fields( $custom_field );
				RulesManager::instance()->register( $rule );
			}
		}
	} )();
}
