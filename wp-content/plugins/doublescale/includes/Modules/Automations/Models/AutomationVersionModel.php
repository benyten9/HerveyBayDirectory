<?php

/**
 * Class AutomationVersionModel
 * This class is responsible for handling the Automation Version model — a
 * full snapshot of an automation captured for undo / redo (rollback).
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * AutomationVersionModel class
 */
class AutomationVersionModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_automation_versions';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'automation_id',
		'version',
		'label',
		'snapshot',
		'created_by',
		'created_at',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'snapshot'      => 'array',
		'automation_id' => 'integer',
		'version'       => 'integer',
	);

	/**
	 * Timestamps
	 *
	 * The table only has created_at (snapshots are immutable), so disable the
	 * automatic updated_at handling.
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = false;

	/**
	 * Get the automation this version belongs to.
	 *
	 * @since 1.0.0
	 *
	 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
	 */
	public function automation() {
		return $this->belongsTo( AutomationModel::class, 'automation_id', 'id' );
	}
}
