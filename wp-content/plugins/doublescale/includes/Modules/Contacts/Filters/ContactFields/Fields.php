<?php
/**
 * Contact custom-field filters (one filter per contact-scoped custom field).
 *
 * @package DoubleScale\Modules\Contacts\Filters\ContactFields
 */

namespace DoubleScale\Modules\Contacts\Filters\ContactFields;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Filter;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fields class
 */
class Fields extends Filter {

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
	 * Group
	 *
	 * @var string
	 */
	public $group = 'contact_fields';

	/**
	 * Custom Field
	 *
	 * @var CustomFieldModel
	 */
	public $custom_field;

	/**
	 * Type
	 *
	 * @var string
	 */
	public $type = 'text';

	/**
	 * Constructor
	 *
	 * @param CustomFieldModel $custom_field Custom field definition.
	 */
	public function __construct( $custom_field ) {
		$this->custom_field = $custom_field;
		$this->name         = $custom_field->name;
		$this->slug         = 'contact_field_' . $custom_field->id;
		$this->type         = $this->map_field_type( $custom_field->type );
	}

	/**
	 * Map custom field type to filter UI type.
	 *
	 * @param string $field_type Custom field type slug.
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
	 * Get operators based on field type.
	 *
	 * @return array<string, string>
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
	 * Get options for select-type fields.
	 *
	 * @return array<string, string>
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

		if ( isset( $attributes['options'] ) && is_array( $attributes['options'] ) ) {
			$attributes = $attributes['options'];
		}

		$options = array();
		foreach ( $attributes as $attribute ) {
			if ( is_array( $attribute ) && isset( $attribute['value'] ) ) {
				$options[ $attribute['value'] ] = $attribute['label'] ?? $attribute['value'];
			} elseif ( is_scalar( $attribute ) ) {
				$options[ $attribute ] = $attribute;
			}
		}

		return $options;
	}

	/**
	 * Whether the operator requires a non-empty value.
	 *
	 * @param string $operator Operator slug.
	 * @return bool
	 */
	protected function operator_needs_value( $operator ) {
		return ! in_array( $operator, array( 'is_empty', 'is_not_empty' ), true );
	}

	/**
	 * Whether a submitted filter value is empty.
	 *
	 * @param mixed $value Filter value.
	 * @return bool
	 */
	protected function is_empty_filter_value( $value ) {
		if ( is_array( $value ) ) {
			return empty(
				array_filter(
					$value,
					static function ( $item ) {
						return '' !== trim( (string) $item );
					}
				)
			);
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Normalize a filter value to a string for SQL comparisons.
	 *
	 * @param mixed $value Filter value.
	 * @return string
	 */
	protected function normalize_value( $value ) {
		if ( is_array( $value ) ) {
			return implode( ',', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Apply the filter to a contact query.
	 *
	 * @param Builder              $query  Contact query.
	 * @param array<string, mixed> $filter Filter rule.
	 * @return Builder
	 */
	public function apply( Builder $query, $filter = array() ) {
		$operator = isset( $filter['operator'] ) ? $filter['operator'] : 'is';
		$value    = $filter['value'] ?? '';

		if ( $this->operator_needs_value( $operator ) && $this->is_empty_filter_value( $value ) ) {
			return $query;
		}

		$field_id       = $this->custom_field->id;
		$string_value   = $this->normalize_value( $value );
		$has_field_slug = 'custom_fields';

		switch ( $operator ) {
			case 'is':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', $string_value );
					}
				);
				break;

			case 'is_not':
				$query->where(
					function ( $outer ) use ( $has_field_slug, $field_id, $string_value ) {
						$outer->whereDoesntHave(
							$has_field_slug,
							function ( $sub ) use ( $field_id ) {
								$sub->where( 'custom_field_id', $field_id );
							}
						)->orWhereHas(
							$has_field_slug,
							function ( $sub ) use ( $field_id, $string_value ) {
								$sub->where( 'custom_field_id', $field_id )->where( 'value', '!=', $string_value );
							}
						);
					}
				);
				break;

			case 'contains':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', 'like', '%' . $string_value . '%' );
					}
				);
				break;

			case 'does_not_contain':
				$query->where(
					function ( $outer ) use ( $has_field_slug, $field_id, $string_value ) {
						$outer->whereDoesntHave(
							$has_field_slug,
							function ( $sub ) use ( $field_id ) {
								$sub->where( 'custom_field_id', $field_id );
							}
						)->orWhereHas(
							$has_field_slug,
							function ( $sub ) use ( $field_id, $string_value ) {
								$sub->where( 'custom_field_id', $field_id )->where( 'value', 'not like', '%' . $string_value . '%' );
							}
						);
					}
				);
				break;

			case 'starts_with':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', 'like', $string_value . '%' );
					}
				);
				break;

			case 'ends_with':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', 'like', '%' . $string_value );
					}
				);
				break;

			case 'greater_than':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', '>', $string_value );
					}
				);
				break;

			case 'lower_than':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id, $string_value ) {
						$sub->where( 'custom_field_id', $field_id )->where( 'value', '<', $string_value );
					}
				);
				break;

			case 'is_empty':
				$query->where(
					function ( $outer ) use ( $has_field_slug, $field_id ) {
						$outer->whereDoesntHave(
							$has_field_slug,
							function ( $sub ) use ( $field_id ) {
								$sub->where( 'custom_field_id', $field_id );
							}
						)->orWhereHas(
							$has_field_slug,
							function ( $sub ) use ( $field_id ) {
								$sub->where( 'custom_field_id', $field_id )
									->where(
										function ( $value_query ) {
											$value_query->whereNull( 'value' )
												->orWhere( 'value', '=', '' );
										}
									);
							}
						);
					}
				);
				break;

			case 'is_not_empty':
				$query->whereHas(
					$has_field_slug,
					function ( $sub ) use ( $field_id ) {
						$sub->where( 'custom_field_id', $field_id )
							->whereNotNull( 'value' )
							->where( 'value', '!=', '' );
					}
				);
				break;
		}

		return $query;
	}
}
