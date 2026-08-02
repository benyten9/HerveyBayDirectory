<?php
/**
 * Eloquent model for the `doublescale_link_triggers` table.
 *
 * @package DoubleScale\Pro\Modules\LinkTriggers\Models
 */

namespace DoubleScale\Pro\Modules\LinkTriggers\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

class LinkTriggerModel extends Model {

	protected $table = 'doublescale_link_triggers';

	protected $primary_key = 'id';

	protected $fillable = array(
		'name',
		'hash',
		'status',
		'settings',
		'click_count',
		'created_at',
		'updated_at',
	);

	protected $casts = array(
		'settings' => 'array',
	);

	protected $rules = array(
		'name' => 'required',
	);

	protected $messages = array(
		'name.required' => 'Link trigger name is required',
	);

	public $timestamps = true;

	public function get_setting( $key, $default = null ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $default;
	}

	public static function boot() {
		parent::boot();

		static::retrieved(
			function ( $link_trigger ) {
				$link_trigger->full_url = home_url( '?doublescale-link-trigger=' . rawurlencode( $link_trigger->hash ) );
			}
		);

		static::saving(
			function ( $link_trigger ) {
				unset( $link_trigger->full_url );
			}
		);
	}
}
