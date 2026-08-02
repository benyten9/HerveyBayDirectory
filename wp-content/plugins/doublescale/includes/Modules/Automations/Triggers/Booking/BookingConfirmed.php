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
 * Booking confirmed trigger stub.
 */
class BookingConfirmed extends TriggerPro {

	public $name = 'Booking confirmed';

	public $slug = 'booking_confirmed';

	public $description = 'Fires when a booking is confirmed.';

	public $attributes = array();

	public $source = 'booking';

	public $group = 'booking';
}

TriggersManager::instance()->register( new BookingConfirmed() );
