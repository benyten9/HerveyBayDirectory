<?php
/**
 * Eloquent model for bundled SMTP email send log.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Models;

defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;

/**
 * Maps to {$wpdb->prefix}doublescale_smtp_email_log.
 */
class SmtpEmailLogModel extends Model {

	/**
	 * Table name (without WordPress table prefix).
	 *
	 * @var string
	 */
	protected $table = 'doublescale_smtp_email_log';

	/**
	 * Primary key column.
	 *
	 * @var string
	 */
	protected $primary_key = 'log_id';

	/**
	 * Allow flexible mass-assignment (serialized columns from legacy callers).
	 *
	 * @var array<int, string>
	 */
	protected $guarded = array();

	/**
	 * Whether to maintain created_at / updated_at.
	 *
	 * @var bool
	 */
	public $timestamps = false;
}
