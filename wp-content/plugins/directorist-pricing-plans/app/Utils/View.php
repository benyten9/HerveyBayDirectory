<?php

namespace DirectoristPricingPlan\App\Utils;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\View\View as MVCView;

class View extends MVCView {
    public static function get_path( string $file ): string {
        $theme_file = $file;

        if ( empty( \pathinfo( $theme_file )['extension'] ) ) {
            $theme_file .= '.php';
        }

        $theme_file_path = null;
        $theme_template  = '/directorist-pricing-plans/' . \ltrim( $theme_file, '/' );

        if ( file_exists( get_stylesheet_directory() . $theme_template ) ) {
            $theme_file_path = get_stylesheet_directory() . $theme_template;
        } elseif ( file_exists( get_template_directory() . $theme_template ) ) {
            $theme_file_path = get_template_directory() . $theme_template;
        }

        if ( $theme_file_path && file_exists( $theme_file_path ) ) {
            return $theme_file_path;
        }

        return parent::get_path( $file );
    }
}
