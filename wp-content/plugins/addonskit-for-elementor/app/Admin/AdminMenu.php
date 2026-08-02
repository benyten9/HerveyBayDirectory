<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Admin;

use AddonskitForElementor\Utils\Hookable;
use AddonskitForElementor\Utils\Singleton;

/**
 * Administration Menu Class
 */
class AdminMenu {
    use Hookable;
    use Singleton;

    public function setup() {
        $this->action( 'admin_menu', 'admin_menu' );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'AddonskitForElementor', 'addonskit-for-elementor' ),
            apply_filters( 'akef_title', 'AddonskitForElementor' ),
            'manage_options',
            'akef',
            [ $this, 'overview' ]
        );
        add_submenu_page(
            'akef',
            __( 'Dashboard', 'addonskit-for-elementor' ),
            __( 'Dashboard', 'addonskit-for-elementor' ),
            'manage_options',
            'akef',
            [ $this, 'overview' ],
        );
    }

    public function overview() {
        echo 1212;
    }
}
