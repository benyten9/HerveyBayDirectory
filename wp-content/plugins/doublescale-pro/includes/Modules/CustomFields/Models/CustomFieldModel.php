<?php
/**
 * Custom field definition model (contact-scoped fields and merge tags).
 *
 * @package DoubleScale\Pro\Modules\CustomFields\Models
 */

namespace DoubleScale\Pro\Modules\CustomFields\Models;

use DoubleScale\Vendor\Illuminate\Support\Str;
use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\CustomFields\Migrations\CustomFieldsTablePositionColumn;

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
		'position'   => 'integer',
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
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function group() {
		return $this->belongsTo( CustomFieldsGroupModel::class, 'group_id' );
	}

	/**
	 * Contacts using this field (pivot: entity_id + entity_type).
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Relations\BelongsToMany
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

	/** The client never sees the field in the portal (the default). */
	const PORTAL_HIDDEN = 'hidden';
	/** The client sees the value on their portal profile. */
	const PORTAL_VISIBLE = 'visible';
	/** The client sees and changes the value on their portal profile. */
	const PORTAL_EDITABLE = 'editable';

	/**
	 * What the client may do with this field in the client portal
	 * (attributes.portal). Contact fields show on the client's profile and may
	 * be editable; project fields show on the project and are read-only. File
	 * fields, anything unknown and every other scope are hidden.
	 *
	 * @return string One of the PORTAL_* constants.
	 */
	public function get_portal_access() {
		$scope = (string) $this->scope;
		if ( ! in_array( $scope, array( 'contact', 'project' ), true ) || ( 'project' === $scope && 'file' === (string) $this->type ) ) {
			return self::PORTAL_HIDDEN;
		}

		$attributes = $this->getAttribute( 'attributes' );
		$access     = is_array( $attributes ) && isset( $attributes['portal'] ) ? (string) $attributes['portal'] : '';
		if ( 'project' === $scope && self::PORTAL_EDITABLE === $access ) {
			$access = self::PORTAL_VISIBLE;
		}

		return in_array( $access, array( self::PORTAL_VISIBLE, self::PORTAL_EDITABLE ), true ) ? $access : self::PORTAL_HIDDEN;
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
	 * Every custom field, read once per request.
	 *
	 * Modules register a merge tag, filter and automation rule per field while
	 * they boot; each used to run its own query (five per request). Saving or
	 * deleting a field clears this.
	 *
	 * @var \DoubleScale\Vendor\Illuminate\Database\Eloquent\Collection|null
	 */
	private static $request_cache = null;

	/**
	 * All custom fields, from the per-request cache, in their set order.
	 *
	 * Sorted here rather than in SQL: modules read this while they boot,
	 * possibly before an upgrade has added the `position` column.
	 *
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Collection
	 */
	public static function cached_all() {
		if ( null === self::$request_cache ) {
			self::$request_cache = static::query()->get()->sort( array( static::class, 'compare_order' ) )->values();
		}
		return self::$request_cache;
	}

	/**
	 * Order of two fields: their position, then id (the order before
	 * positions existed, and the tie-break for fields never dragged).
	 *
	 * @param CustomFieldModel $a Field.
	 * @param CustomFieldModel $b Field.
	 * @return int
	 */
	public static function compare_order( $a, $b ) {
		return array( (int) $a->position, (int) $a->id ) <=> array( (int) $b->position, (int) $b->id );
	}

	/**
	 * Whether the `position` column is on disk.
	 *
	 * Known from an option once the upgrade added it; until then checked once
	 * per request, so a site mid-upgrade keeps working in id order instead of
	 * failing on an unknown column.
	 *
	 * @var bool|null
	 */
	private static $has_position = null;

	/**
	 * @return bool
	 */
	public static function has_position_column() {
		if ( null !== self::$has_position ) {
			return self::$has_position;
		}

		if ( 'yes' === get_option( CustomFieldsTablePositionColumn::READY_OPTION ) ) {
			self::$has_position = true;
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_custom_fields';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		self::$has_position = $exists && CustomFieldsTablePositionColumn::column_on_disk( $table );
		if ( self::$has_position ) {
			update_option( CustomFieldsTablePositionColumn::READY_OPTION, 'yes', true );
		}
		return self::$has_position;
	}

	/**
	 * Forget what {@see has_position_column()} found (tests, schema changes).
	 */
	public static function forget_position_column() {
		self::$has_position = null;
	}

	/**
	 * Fields in the order the admin set (position, then id). Columns are
	 * qualified so the scope also works on joined relations (pivot rows
	 * have their own `id`).
	 *
	 * @param \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder $query Query.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Builder
	 */
	public function scopeOrdered( $query ) {
		if ( self::has_position_column() ) {
			$query->orderBy( $this->qualifyColumn( 'position' ) );
		}
		return $query->orderBy( $this->qualifyColumn( 'id' ) );
	}

	/**
	 * The position a new field of this scope gets: after every other one.
	 *
	 * @param string $scope Scope.
	 * @return int
	 */
	public static function next_position( $scope ) {
		return (int) static::query()->where( 'scope', (string) $scope )->max( 'position' ) + 1;
	}

	/**
	 * Save a scope's field order.
	 *
	 * The listed fields come first, in that order; any field of the scope
	 * left out (added in another tab, say) keeps its order after them.
	 *
	 * @param string $scope Scope.
	 * @param int[]  $ids   Field ids in their new order.
	 * @return true|\WP_Error
	 */
	public static function reorder( $scope, array $ids ) {
		$scope = (string) $scope;
		$ids   = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( '' === $scope || ! $ids ) {
			return new \WP_Error( 'custom_field_order_invalid', __( 'Send the scope and the field ids in their new order.', 'doublescale' ), array( 'status' => 400 ) );
		}
		if ( ! self::has_position_column() ) {
			return new \WP_Error( 'custom_field_order_unavailable', __( 'The field order cannot be saved until the plugin update finishes. Reload the page and try again.', 'doublescale' ), array( 'status' => 409 ) );
		}

		$current = array_map( 'intval', static::query()->where( 'scope', $scope )->ordered()->pluck( 'id' )->all() );
		$foreign = array_diff( $ids, $current );
		if ( $foreign ) {
			return new \WP_Error(
				'custom_field_order_scope',
				__( 'Some of these fields do not belong to this record type.', 'doublescale' ),
				array(
					'status' => 400,
					'ids'    => array_values( $foreign ),
				)
			);
		}

		$order = array_merge( $ids, array_values( array_diff( $current, $ids ) ) );

		// An order change is not an edit of the field and must not bump
		// updated_at. The column updates itself on any change (ON UPDATE
		// CURRENT_TIMESTAMP) unless the row is written with its own value.
		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_custom_fields';
		foreach ( $order as $index => $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET position = %d, updated_at = updated_at WHERE id = %d", $index + 1, (int) $id ) );
		}

		self::flush_request_cache();
		return true;
	}

	/**
	 * Custom fields of one scope ('contact', 'project', 'task'…), from the per-request cache.
	 *
	 * @param string $scope Scope.
	 * @return \DoubleScale\Vendor\Illuminate\Database\Eloquent\Collection
	 */
	public static function cached_for_scope( $scope ) {
		return self::cached_all()->where( 'scope', (string) $scope )->values();
	}

	/**
	 * Forget the per-request cache (after a field changes).
	 */
	public static function flush_request_cache() {
		self::$request_cache = null;
	}

	/**
	 * Boot model events.
	 */
	public static function boot() {
		parent::boot();

		static::saved(
			function () {
				self::flush_request_cache();
			}
		);
		static::deleted(
			function () {
				self::flush_request_cache();
			}
		);

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

				// New fields go last. Always set here (position is not fillable),
				// so a duplicated group's copies do not reuse the originals' places.
				if ( self::has_position_column() ) {
					$field->position = self::next_position( $field->scope );
				}
			}
		);

		static::deleting(
			function ( $field ) {
				// Not the DB facade: wp-eloquent never boots Laravel's container,
				// so Facades\DB::table() throws "A facade root has not been set"
				// and deleting a custom field fatals. It also skipped the WP table
				// prefix. Go through $wpdb, which is what the rest of the plugin
				// uses for raw pivot writes.
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete(
					$wpdb->prefix . 'doublescale_custom_field_relationship',
					array( 'custom_field_id' => (int) $field->id ),
					array( '%d' )
				);
			}
		);
	}
}
