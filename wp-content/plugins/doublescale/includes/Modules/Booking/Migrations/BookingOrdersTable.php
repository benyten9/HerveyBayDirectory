<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class BookingOrdersTable extends Migration {

	public $table_name = 'booking_orders';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		booking_id int(11) NOT NULL,
		items text,
		discount decimal(10,2) NOT NULL DEFAULT 0.00,
		total decimal(10,2) NOT NULL,
		currency varchar(255) NOT NULL,
		payment_method varchar(255) NOT NULL,
		status varchar(255) NOT NULL,
		transaction_id varchar(255) DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY booking_id (booking_id)';
	}
}
