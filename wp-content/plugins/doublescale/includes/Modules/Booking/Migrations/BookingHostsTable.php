<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class BookingHostsTable extends Migration {

	public $table_name = 'booking_hosts';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		booking_id int(11) NOT NULL,
		user_id int(11) NOT NULL,
		status varchar(255) NOT NULL,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY booking_id (booking_id),
		KEY user_id (user_id)';
	}
}
