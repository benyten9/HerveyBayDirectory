<?php
/**
 * Minimal read-only model for campaign rows referenced by communication tracking.
 * Full campaigns UI lives in the Pro plugin.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * Maps to `doublescale_campaigns` for FK resolution when the Campaigns module is not bundled.
 */
class TrackingCampaignModel extends Model {

	/**
	 * @var string
	 */
	protected $table = 'doublescale_campaigns';

	/**
	 * @var string
	 */
	protected $primary_key = 'id';

	/**
	 * @var array
	 */
	protected $fillable = array(
		'name',
		'description',
		'status',
		'type',
		'settings',
		'parent_id',
		'count',
		'execute_at',
		'processing_started_at',
		'created_by',
		'created_at',
		'updated_at',
	);

	/**
	 * @var bool
	 */
	public $timestamps = true;
}
