<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Admin;

use AddonskitForElementor\Admin\AdminMenu;
use AddonskitForElementor\Utils\Singleton;

class Admin {
    use Singleton;

    public function setup() {
        AdminMenu::initialize();
    }
}