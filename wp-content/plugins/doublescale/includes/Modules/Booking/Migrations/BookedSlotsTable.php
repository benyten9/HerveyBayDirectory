<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class BookedSlotsTable extends Migration {

	public $table_name = 'booking_booked_slots';

	public function get_query() {
		return "id bigint(20) NOT NULL AUTO_INCREMENT,
		calendar_id int(11) NOT NULL,
		event_id int(11) NULL,
		booking_id int(11) NOT NULL,
		slot_start datetime NOT NULL,
		slot_end datetime NOT NULL,
		status varchar(30) NOT NULL DEFAULT 'booked',
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uq_calendar_slot_booking (calendar_id, slot_start, booking_id),
		KEY idx_booking (booking_id),
		KEY idx_calendar_range (calendar_id, slot_start, slot_end),
		KEY idx_status (status)";
	}
}
