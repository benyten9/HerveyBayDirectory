<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class UserModel extends Model {

	protected $primary_key = 'ID';

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

	protected $visible = array(
		'ID',
		'user_login',
		'user_email',
		'display_name',
	);

	protected $casts = array(
		'ID' => 'integer',
	);

	public function __construct( array $attributes = array() ) {
		global $wpdb;
		$this->table = $wpdb->users;
		parent::__construct( $attributes );
	}

	public function getTable() {
		global $wpdb;
		return $wpdb->users;
	}

	public function meta() {
		return $this->hasMany( UsermetaModel::class, 'user_id', 'ID' );
	}

	public function availability() {
		return $this->hasMany( AvailabilityModel::class, 'user_id', 'ID' );
	}
}
