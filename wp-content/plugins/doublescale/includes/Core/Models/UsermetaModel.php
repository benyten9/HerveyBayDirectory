<?php
/**
 * Class User_Meta_Model
 *
 * This class is responsible for handling the user meta model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * User_Meta_Model class
 */
class UsermetaModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 *
	 * NOTE: WordPress usermeta table is shared across all sites in multisite.
	 */
	protected $table;

	/**
	 * Constructor - Initialize table name
	 *
	 * @param array $attributes Attributes.
	 */
	public function __construct( array $attributes = array() ) {
		$this->set_table_name();
		parent::__construct( $attributes );
	}

	/**
	 * Set the correct table name based on multisite context
	 */
	protected function set_table_name() {
		global $wpdb;
		$this->table = $wpdb->base_prefix . 'usermeta';
	}

	/**
	 * Get the table associated with the model.
	 *
	 * Override to ensure table name is always correct.
	 *
	 * @return string
	 */
	public function getTable() {
		if ( ! $this->table ) {
			$this->set_table_name();
		}
		// Return as-is since we already have the full table name with prefix
		return $this->table;
	}

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'umeta_id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'user_id',
		'meta_key',
		'meta_value',
	);
}
