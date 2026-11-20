<?php

namespace DirectoristAnalytics\App\Providers;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\Contracts\Provider;
use DirectoristAnalytics\WpMVC\View\View;

class MenuServiceProvider implements Provider
{
    public function boot() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ], 20 );
    }

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=at_biz_dir',
            __( 'Analytics', 'directorist-analytics' ),
            __( 'Analytics', 'directorist-analytics' ),
            'manage_options',
            'directorist-analytics',
            [ $this, 'render_page' ],
            15
        );
    }

    public function render_page() {
        View::render( 'admin/analytics', [
            'title' => __( 'Analytics Dashboard', 'directorist-analytics' ),
        ] );
    }
}