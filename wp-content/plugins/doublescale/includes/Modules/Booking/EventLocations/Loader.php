<?php
/**
 * Event Locations Loader
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventLocations;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

PersonAddress::instance();
AttendeeAddress::instance();
AttendeePhone::instance();
PersonPhone::instance();
Custom::instance();
Online::instance();
GoogleMeet::instance();
Zoom::instance();
MsTeams::instance();
