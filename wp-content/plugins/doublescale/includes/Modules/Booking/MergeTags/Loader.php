<?php
/**
 * Merge Tags Loader
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Loader {

	public static function register(): void {
		Booking\AdditionalGuests::instance();
		Booking\BookingCancelUrl::instance();
		Booking\BookingDetailsUrl::instance();
		Booking\BookingEndDate::instance();
		Booking\BookingHash::instance();
		Booking\BookingLocation::instance();
		Booking\BookingStartDate::instance();
		Booking\BookingName::instance();
		Booking\BookingTimezone::instance();
		Booking\ConfirmUrl::instance();
		Booking\RejectUrl::instance();
		Booking\EventName::instance();
		Booking\RescheduleUrl::instance();
		Booking\WaitingListPosition::instance();
		Booking\WaitingListClaimUrl::instance();

		Contact\ContactName::instance();
		Contact\ContactEmail::instance();
		Contact\ContactNote::instance();
		Contact\ContactTimezone::instance();

		Host\HostName::instance();
		Host\HostEmail::instance();
		Host\HostTimezone::instance();
	}
}
