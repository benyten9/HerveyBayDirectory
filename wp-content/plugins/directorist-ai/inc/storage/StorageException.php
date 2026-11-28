<?php
/**
 * Storage failures without SQL or credential disclosure.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/** Expected storage failure requiring explicit caller recovery. */
class StorageException extends \RuntimeException {}
