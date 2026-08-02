<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class BookingLogTable extends Migration {

	public $table_name = 'booking_log';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		booking_id int(11) NOT NULL,
		type varchar(255) NOT NULL,
		source varchar(255) NOT NULL,
		message varchar(255) NOT NULL,
		details text,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY booking_id (booking_id)';
	}
}
