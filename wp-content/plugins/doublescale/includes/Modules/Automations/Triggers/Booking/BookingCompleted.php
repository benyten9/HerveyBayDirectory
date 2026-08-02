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
 * Booking completed trigger stub.
 */
class BookingCompleted extends TriggerPro {

	public $name = 'Booking completed';

	public $slug = 'booking_completed';

	public $description = 'Fires when a booking is marked completed.';

	public $attributes = array();

	public $source = 'booking';

	public $group = 'booking';
}

TriggersManager::instance()->register( new BookingCompleted() );
