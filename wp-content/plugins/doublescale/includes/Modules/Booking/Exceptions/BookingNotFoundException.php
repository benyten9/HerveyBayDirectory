<?php
/**
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Exceptions;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Thrown when a booking hash id is well-formed but no matching record exists.
 */
class BookingNotFoundException extends Exception {}
