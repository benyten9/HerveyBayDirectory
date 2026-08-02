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
 * Booking cancelled trigger stub.
 */
class BookingCancelled extends TriggerPro {

	public $name = 'Booking cancelled';

	public $slug = 'booking_cancelled';

	public $description = 'Fires when a booking is cancelled.';

	public $attributes = array();

	public $source = 'booking';

	public $group = 'booking';
}

TriggersManager::instance()->register( new BookingCancelled() );
