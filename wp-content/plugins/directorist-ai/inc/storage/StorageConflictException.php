<?php
/**
 * A durable idempotency or version identity conflicts with existing state.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/** Safe for a transport to classify as a conflict without exposing SQL. */
final class StorageConflictException extends StorageException {}
