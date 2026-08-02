<?php

namespace DoubleScale\Modules\Booking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class UsermetaModel extends Model {

	protected $primary_key = 'umeta_id';

	protected $fillable = array(
		'user_id',
		'meta_key',
		'meta_value',
	);

	public function __construct( array $attributes = array() ) {
		global $wpdb;
		$this->table = $wpdb->usermeta;
		parent::__construct( $attributes );
	}

	public function getTable() {
		global $wpdb;
		return $wpdb->usermeta;
	}

	public function user() {
		return $this->belongsTo( UserModel::class, 'user_id', 'ID' );
	}
}
