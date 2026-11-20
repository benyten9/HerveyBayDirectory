<?php
/**
 * Counters behind public rate limits, "once per visitor" checks and
 * concurrency slots ({@see \DoubleScale\Core\Security\RateLimiter}).
 *
 * `bucket` is a SHA-1 of the key (never an address in clear). `scope` is
 * empty for counters and names the pool for slot leases. Expired rows are
 * swept as the table is used.
 *
 * @package DoubleScale\Core\Database\Migrations
 */

namespace DoubleScale\Core\Database\Migrations;

use DoubleScale\Core\Database\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * RateLimitsTable migration.
 */
class RateLimitsTable extends Migration {

	/**
	 * Logical name → {prefix}doublescale_rate_limits.
	 *
	 * @var string
	 */
	public $table_name = 'rate_limits';

	/**
	 * Column definitions.
	 *
	 * @return string
	 */
	public function get_query() {
		return "bucket CHAR(40) NOT NULL,
			scope VARCHAR(64) NOT NULL DEFAULT '',
			hits INT(10) UNSIGNED NOT NULL DEFAULT 0,
			expires_at INT(10) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (bucket),
			KEY scope_expires (scope,expires_at),
			KEY expires_at (expires_at)";
	}
}
