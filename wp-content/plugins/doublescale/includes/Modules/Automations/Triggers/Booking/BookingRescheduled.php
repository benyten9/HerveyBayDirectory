<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * Booking rescheduled trigger stub.
 */
class BookingRescheduled extends TriggerPro {

	public $name = 'Booking rescheduled';

	public $slug = 'booking_rescheduled';

	public $description = 'Fires when a booking is rescheduled.';

	public $attributes = array();

	public $source = 'booking';

	public $group = 'booking';
}

TriggersManager::instance()->register( new BookingRescheduled() );
