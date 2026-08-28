<?php

namespace DoubleScale\Modules\Booking\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Database\Migration;

class EventsTable extends Migration {

	public $table_name = 'booking_events';

	public function get_query() {
		return "id int(11) NOT NULL AUTO_INCREMENT,
		hash_id varchar(255) NOT NULL,
		calendar_id int(11) NOT NULL,
		user_id int(11) NOT NULL,
		name varchar(255) NOT NULL,
		description text,
		slug varchar(255) NOT NULL,
		status varchar(255) NOT NULL DEFAULT 'active',
		type varchar(255) NOT NULL DEFAULT 'one-to-one',
		is_disabled boolean NOT NULL DEFAULT 0,
		duration int(11) NOT NULL DEFAULT 30,
		color varchar(255) NOT NULL DEFAULT '#0099ff',
		visibility varchar(255) NOT NULL DEFAULT 'public',
		availability_type varchar(255) NOT NULL DEFAULT 'existing',
		availability_meta longtext,
		availability_id int(11) NULL,
		created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY calendar_id (calendar_id),
		KEY availability_id (availability_id),
		KEY user_id (user_id),
		KEY hash_id (hash_id(191)),
		KEY slug (slug(191))";
	}
}
