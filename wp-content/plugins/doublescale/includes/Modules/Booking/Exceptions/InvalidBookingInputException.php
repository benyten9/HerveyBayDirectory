<?php
/**
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Exceptions;

defined( 'ABSPATH' ) || exit;

use Exception;

/**
 * Thrown when the caller supplied invalid booking input.
 *
 * This separates "you sent bad data" from "the server failed". The REST
 * controllers map it to 400 so a client can tell the two apart; every other
 * exception keeps its existing 500 so genuine faults stay visible.
 *
 * It extends \Exception, so existing `catch ( Exception $e )` blocks continue
 * to catch it unchanged — only the places that deliberately re-map it behave
 * differently.
 */
class InvalidBookingInputException extends Exception {}
