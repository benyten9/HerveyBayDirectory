<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class CalendarsMetaTable extends Migration {

	public $table_name = 'booking_calendars_meta';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		calendar_id int(11) NOT NULL,
		meta_key varchar(255) NOT NULL,
		meta_value text,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY calendar_id (calendar_id)';
	}
}
