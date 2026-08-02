<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class AvailabilityTable extends Migration {

	public $table_name = 'booking_availability';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		user_id int(11) NOT NULL,
		name varchar(255) NOT NULL,
		value longtext NOT NULL,
		timezone varchar(255) NOT NULL,
		is_default tinyint(1) NOT NULL DEFAULT 0,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY user_id (user_id)';
	}
}
