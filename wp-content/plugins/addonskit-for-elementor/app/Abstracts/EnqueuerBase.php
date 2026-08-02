<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Abstracts;

use AddonskitForElementor\Utils\Hookable;
use AddonskitForElementor\Utils\Singleton;

abstract class EnqueuerBase {
    use Hookable;
    use Singleton;
}
