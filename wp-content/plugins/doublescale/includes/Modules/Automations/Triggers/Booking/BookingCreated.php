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
 * Booking created trigger stub.
 */
class BookingCreated extends TriggerPro {

	public $name = 'Booking created';

	public $slug = 'booking_created';

	public $description = 'Fires when a new booking is created.';

	public $attributes = array();

	public $source = 'booking';

	public $group = 'booking';
}

TriggersManager::instance()->register( new BookingCreated() );
