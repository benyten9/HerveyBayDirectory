<?php
/**
 * Class UserModel
 *
 * This class is responsible for handling the user model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Core\Models\UsermetaModel;

/**
 * UserModel class
 */
class UserModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 *
	 * NOTE: WordPress users table is shared across all sites in multisite.
	 * In single-site: 'users' becomes 'wp_users'
	 * In multisite: Always use base prefix 'wp_users', not 'wp_2_users', 'wp_3_users', etc.
	 */
	protected $table;

	/**
	 * Constructor - Initialize table name
	 *
	 * @param array $attributes Attributes.
	 */
	public function __construct( array $attributes = array() ) {
		// Set table name before parent constructor
		$this->set_table_name();
		parent::__construct( $attributes );
	}

	/**
	 * Set the correct table name based on multisite context
	 */
	protected function set_table_name() {
		global $wpdb;
		// In multisite, always use base prefix for shared users table
		// In single-site, base_prefix equals prefix
		$this->table = $wpdb->base_prefix . 'users';
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
	protected $primary_key = 'ID';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'user_login',
		'user_pass',
		'user_nicename',
		'user_email',
		'user_url',
		'user_registered',
		'user_activation_key',
		'user_status',
		'display_name',
	);

	/**
	 * Attributes that should be hidden for arrays and JSON.
	 *
	 * @var array
	 */
	protected $visible = array(
		'ID',
		'user_login',
		'user_email',
		'display_name',
	);

	/**
	 * User meta
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function meta() {
		return $this->hasMany( UsermetaModel::class, 'user_id', 'ID' );
	}

	/**
	 * Capability
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\HasMany
	 */
	public function capability() {
		return $this->hasMany( UsermetaModel::class, 'user_id', 'ID' )->where( 'meta_key', 'wp_capabilities' );
	}
}
