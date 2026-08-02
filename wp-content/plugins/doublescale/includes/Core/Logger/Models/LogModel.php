<?php
/**
 * Class LogModel
 *
 * This class is responsible for handling the Logs model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Logger\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * LogModel class
 */
class LogModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_logs';

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
		'timestamp',
		'level',
		'source',
		'message',
		'context',
	);

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'timestamp' => 'datetime',
		'context'   => 'array',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 */
	public $timestamps = false;
}
