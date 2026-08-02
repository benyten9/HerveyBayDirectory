<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class BookingsTable extends Migration {

	public $table_name = 'bookings';

	public function get_query() {
		return 'id int(11) NOT NULL AUTO_INCREMENT,
		hash_id varchar(255) NOT NULL,
		event_id int(11) NULL,
		calendar_id int(11) NOT NULL,
		contact_id bigint(20) unsigned NOT NULL,
		start_time datetime NOT NULL,
		end_time datetime NOT NULL,
		slot_time int(11) NOT NULL,
		source varchar(255) NOT NULL,
		status varchar(255) NOT NULL,
		cancelled_by varchar(255) NOT NULL,
		event_url varchar(255) NOT NULL,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY event_id (event_id),
		KEY contact_id (contact_id)';
	}
}
