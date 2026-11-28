<?php
/**
 * Safe background-dispatch failure.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

/** The committed run remains recoverable when queue dispatch fails. */
final class DispatchException extends \RuntimeException {}
