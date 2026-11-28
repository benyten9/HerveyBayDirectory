<?php
/**
 * A runtime connection or SQL operation failed with an unresolved write outcome.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/** Callers reconcile durable state rather than treating it as missing. */
final class StorageUnavailableException extends StorageException {}
