<?php
/**
 * Daily form counters: views, starts and completions per form, day and variant.
 *
 * One row per form a day, not one per view, so a busy form costs a few hundred rows a year.
 *
 * @package DoubleScale\Modules\Forms
 */

namespace DoubleScale\Modules\Forms\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

/**
 * Form stats table.
 */
class FormStatsDailyTable extends Migration {

	/**
	 * Table name (prefixed with doublescale_ by the base class).
	 *
	 * @var string
	 */
	public $table_name = 'form_stats_daily';

	/**
	 * Columns.
	 *
	 * @return string
	 */
	public function get_query() {
		return 'id BIGINT(20) NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) NOT NULL,
			day DATE NOT NULL,
			variant VARCHAR(40) NOT NULL DEFAULT "",
			views INT(11) NOT NULL DEFAULT 0,
			starts INT(11) NOT NULL DEFAULT 0,
			completions INT(11) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY form_day_variant (form_id, day, variant)';
	}
}
