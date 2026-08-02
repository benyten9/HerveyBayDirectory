<?php
/**
 * Class Location
 *
 * This class is responsible for handling the location
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Abstracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Managers\LocationsManager;
use DoubleScale\Modules\Booking\Managers\FieldsManager;
use DoubleScale\Modules\Booking\Traits\EntityProperties;
use WP_Error;

/**
 * Location class
 */
abstract class Location {

	/**
	 * Title
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Is integration
	 *
	 * @var bool
	 */
	public $is_integration = false;

	/**
	 * Subclasses instances.
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	private static $instances = array();

	/**
	 * Location Instances.
	 *
	 * Instantiates or reuses an instances of Location.
	 *
	 * @since 1.0.0
	 * @static
	 *
	 * @return static - Single instance
	 */
	final public static function instance() {
		if ( ! isset( self::$instances[ static::class ] ) ) {
			$instance = new static();
			$instance->register();
			self::$instances[ static::class ] = $instance;
		}

		return self::$instances[ static::class ];
	}

	/**
	 * Constructor
	 */
	protected function __construct() {}

	/**
	 * Register
	 *
	 * @return bool
	 */
	protected function register() {
		try {
			LocationsManager::instance()->register_location( $this );
		} catch ( \Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		return array();
	}

	/**
	 * Admin fields
	 *
	 * @return array
	 */
	public function get_admin_fields() {
		return array();
	}

	/**
	 * Validate location
	 *
	 * @param array $data
	 *
	 * @return array|\WP_Error
	 */
	public function validate_fields( $data ) {
		if ( empty( $this->get_admin_fields() ) ) {
			return $data;
		}

		$location_fields = $data['fields'] ?? array();
		foreach ( $this->get_admin_fields() as $slug => $field ) {
			if ( $field['required'] && ! isset( $location_fields[ $slug ] ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'field_required', sprintf( __( '%s is required', 'doublescale' ), $field['label'] ) );
			}

			$value = $location_fields[ $slug ];

			try {
				$field_type_obj = FieldsManager::instance()->get_item( $field['type'] );

				if ( null === $field_type_obj ) {
					/* translators: %s: field type slug */
					return new \WP_Error( 'invalid_field_type', sprintf( __( 'Field type "%s" does not exist', 'doublescale' ), $field['type'] ) );
				}

				$field_type_class = get_class( $field_type_obj );
				$field_type       = new $field_type_class(
					array(
						'is_required' => $field['required'] ?? false,
						'label'       => $field['label'],
					)
				);

				$value = $field_type->sanitize_field( $value );
				$field_type->validate_value( $value );
				if ( ! $field_type->is_valid ) {
					return new \WP_Error( 'field_invalid', $field_type->validation_err );
				}

				$data['fields'][ $slug ] = $value;
			} catch ( \Exception $e ) {
				return new \WP_Error( 'field_invalid', $e->getMessage() );
			}
		}

		return $data;
	}
}
