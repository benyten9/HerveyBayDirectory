<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class CalendarsTable extends Migration {

	public $table_name = 'booking_calendars';

	public function get_query() {
		return "id int(11) NOT NULL AUTO_INCREMENT,
		hash_id varchar(255) NOT NULL,
		user_id int(11) NOT NULL,
		name varchar(255) NOT NULL,
		description text,
		slug varchar(255) NOT NULL,
		status varchar(255) NOT NULL DEFAULT 'active',
		type varchar(255) NOT NULL DEFAULT 'host',
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY hash_id (hash_id(191)),
		KEY slug (slug(191)),
		KEY user_id (user_id)";
	}
}
