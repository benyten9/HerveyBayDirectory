<?php
/**
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Exceptions;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Thrown when a booking hash id is missing or syntactically empty in the URL.
 */
class InvalidBookingHashException extends Exception {}
