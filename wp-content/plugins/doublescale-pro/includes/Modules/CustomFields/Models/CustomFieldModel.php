<?php
/**
 * Custom field definition model (contact-scoped fields and merge tags).
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Models
 */

namespace DoubleScale\Pro\Modules\CustomFields\Models;

use Illuminate\Support\Str;
use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * CustomFieldModel class
 */
class CustomFieldModel extends Model {

	/**
	 * Table name.
	 *
	 * @var string
	 */
	protected $table = 'doublescale_custom_fields';

	/**
	 * Primary key.
	 *
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns.
	 *
	 * @var array
	 */
	protected $fillable = array(
		'name',
		'slug',
		'type',
		'attributes',
		'group_id',
		'scope',
		'created_at',
		'updated_at',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected $casts = array(
		'attributes' => 'array',
		'group_id'   => 'integer',
		'scope'      => 'string',
	);

	/**
	 * Rules.
	 *
	 * @var array
	 */
	protected $rules = array(
		'name'     => 'required',
		'type'     => 'required',
		'group_id' => 'required',
		'scope'    => 'required',
	);

	/**
	 * Validation messages.
	 *
	 * @var array
	 */
	protected $messages = array(
		'name.required'     => 'Custom field name is required',
		'type.required'     => 'Custom field type is required',
		'group_id.required' => 'Custom field group ID is required',
		'scope.required'    => 'Custom field scope is required',
	);

	/**
	 * Timestamps.
	 *
	 * @var bool
	 */
	public $timestamps = true;

	/**
	 * Group relation.
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function group() {
		return $this->belongsTo( CustomFieldsGroupModel::class, 'group_id' );
	}

	/**
	 * Contacts using this field (pivot: entity_id + entity_type).
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
	 */
	public function contacts() {
		return $this->belongsToMany(
			ContactModel::class,
			'doublescale_custom_field_relationship',
			'custom_field_id',
			'entity_id'
		)->wherePivot( 'entity_type', 'contact' );
	}

	/**
	 * Normalize attributes into options + required metadata.
	 *
	 * @return array{options: array<int, string>, required: bool}
	 */
	public function get_attributes_meta() {
		// Use getAttribute() — $this->attributes is Eloquent's internal column bag, not this field's JSON column.
		$attributes = $this->getAttribute( 'attributes' );

		if ( is_array( $attributes ) && isset( $attributes['options'] ) && is_array( $attributes['options'] ) ) {
			return array(
				'options'  => array_values(
					array_filter(
						array_map(
							static function ( $option ) {
								if ( is_scalar( $option ) ) {
									return trim( (string) $option );
								}

								if ( is_array( $option ) ) {
									$value = $option['value'] ?? $option['label'] ?? '';
									return is_scalar( $value ) ? trim( (string) $value ) : '';
								}

								return '';
							},
							$attributes['options']
						)
					)
				),
				'required' => ! empty( $attributes['required'] ),
			);
		}

		if ( is_array( $attributes ) && array_is_list( $attributes ) ) {
			return array(
				'options'  => array_values(
					array_filter(
						array_map(
							static function ( $option ) {
								if ( is_scalar( $option ) ) {
									return trim( (string) $option );
								}

								if ( is_array( $option ) ) {
									$value = $option['value'] ?? $option['label'] ?? '';
									return is_scalar( $value ) ? trim( (string) $value ) : '';
								}

								return '';
							},
							$attributes
						)
					)
				),
				'required' => false,
			);
		}

		if ( is_array( $attributes ) ) {
			return array(
				'options'  => array(),
				'required' => ! empty( $attributes['required'] ),
			);
		}

		return array(
			'options'  => array(),
			'required' => false,
		);
	}

	/**
	 * Whether this field must have a value on save.
	 *
	 * @return bool
	 */
	public function is_required_field() {
		return $this->get_attributes_meta()['required'];
	}

	/**
	 * Select / multiselect option values.
	 *
	 * @return array<int, string>
	 */
	public function get_option_values() {
		return $this->get_attributes_meta()['options'];
	}

	/**
	 * Whether a submitted value should be treated as empty.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	public function is_empty_value( $value ) {
		if ( 'boolean' === $this->type ) {
			return ! in_array( $value, array( true, 'true', 1, '1' ), true );
		}

		if ( in_array( $this->type, array( 'multiselect', 'checkbox' ), true ) ) {
			if ( is_array( $value ) ) {
				return empty( array_filter( $value ) );
			}

			return '' === trim( (string) $value );
		}

		if ( null === $value ) {
			return true;
		}

		return '' === trim( (string) $value );
	}

	/**
	 * Normalize REST custom field payloads to id => value pairs.
	 *
	 * @param mixed $custom_fields Raw request payload.
	 * @return array<int, mixed>
	 */
	public static function normalize_submission( $custom_fields ) {
		if ( ! is_array( $custom_fields ) ) {
			return array();
		}

		$normalized = array();

		if ( array_is_list( $custom_fields ) ) {
			foreach ( $custom_fields as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$id = $item['custom_field_id'] ?? $item['id'] ?? null;
				if ( ! $id ) {
					continue;
				}

				$value = $item['value'] ?? ( $item['pivot']['value'] ?? null );
				$normalized[ (int) $id ] = $value;
			}

			return $normalized;
		}

		foreach ( $custom_fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$id = $value['custom_field_id'] ?? $value['id'] ?? null;
				if ( ! $id ) {
					continue;
				}

				$normalized[ (int) $id ] = $value['value'] ?? ( $value['pivot']['value'] ?? null );
				continue;
			}

			if ( is_numeric( $key ) ) {
				$normalized[ (int) $key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * Validate a submitted value, including required checks.
	 *
	 * @param mixed $value Value to validate.
	 * @return true|\WP_Error
	 */
	public function validate_submission_value( $value ) {
		if ( $this->is_required_field() && $this->is_empty_value( $value ) ) {
			return new \WP_Error(
				'custom_field_required',
				sprintf(
					/* translators: %s: custom field label */
					__( 'The field "%s" is required.', 'doublescale' ),
					$this->name
				),
				array(
					'status'   => 400,
					'field_id' => (int) $this->id,
				)
			);
		}

		if ( $this->is_empty_value( $value ) ) {
			return true;
		}

		if ( ! $this->validate_value( $value ) ) {
			return new \WP_Error(
				'custom_field_invalid',
				sprintf(
					/* translators: %s: custom field label */
					__( 'The field "%s" has an invalid value.', 'doublescale' ),
					$this->name
				),
				array(
					'status'   => 400,
					'field_id' => (int) $this->id,
				)
			);
		}

		return true;
	}

	/**
	 * Validate a value for this field type.
	 *
	 * @param mixed $value Value to validate.
	 * @return bool
	 */
	public function validate_value( $value ) {
		switch ( $this->type ) {
			case 'boolean':
				return ( $value === 'false' || $value === 'true' || $value === false || $value === true );
			case 'text':
			case 'textarea':
				return is_string( $value );
			case 'number':
				return is_numeric( $value );
			case 'date':
				return (bool) strtotime( (string) $value );
			case 'email':
				return (bool) is_email( (string) $value );
			case 'phone':
				return (bool) preg_match( '/^[0-9\-\(\)\/\+\s]*$/', (string) $value );
			case 'url':
				return (bool) filter_var( $value, FILTER_VALIDATE_URL );
			case 'select':
			case 'radio':
				return in_array( (string) $value, $this->get_option_values(), true );
			case 'multiselect':
			case 'checkbox':
				$values = is_array( $value ) ? $value : explode( ',', (string) $value );
				foreach ( $values as $val ) {
					if ( '' === trim( (string) $val ) ) {
						continue;
					}
					if ( ! in_array( (string) $val, $this->get_option_values(), true ) ) {
						return false;
					}
				}
				return true;
			default:
				return true;
		}
	}

	/**
	 * Resolve field id from slug (used by merge tags: contact_field:slug).
	 *
	 * @param string $key Field slug.
	 * @return int
	 */
	public static function get_id( $key ) {
		$field = static::where( 'slug', $key )->first();
		return $field ? (int) $field->id : 0;
	}

	/**
	 * Boot model events.
	 */
	public static function boot() {
		parent::boot();

		static::creating(
			function ( $field ) {
				if ( empty( $field->slug ) ) {
					$field->slug = Str::slug( $field->name );
				}

				if ( static::where( 'slug', $field->slug )->exists() ) {
					throw new \Exception( sprintf( __( '%s already exists.', 'doublescale' ), $field->name ) );
				}

				if ( ! CustomFieldsGroupModel::find( $field->group_id ) && (int) $field->group_id !== 0 ) {
					throw new \Exception( sprintf( __( 'Group with ID %s does not exist.', 'doublescale' ), $field->group_id ) );
				}
			}
		);

		static::deleting(
			function ( $field ) {
				\Illuminate\Support\Facades\DB::table( 'doublescale_custom_field_relationship' )
					->where( 'custom_field_id', $field->id )
					->delete();
			}
		);
	}
}
