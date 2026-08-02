<?php
/**
 * Booking event-bus bootstrap.
 *
 * Wires the WP-Cron retry sweeper for {@see EventBus}. Separated from the
 * bus class itself so unit tests can exercise dispatch without scheduling
 * cron events.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

defined( 'ABSPATH' ) || exit;

class EventBusBootstrap {

	public static function init(): void {
		EventBus::register_cron();
	}
}
