<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\BusinessHours;

use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BusinessHours extends Widget_Base {
    public function get_name() {
        return 'directorist_single_listing_business_hours';
    }

    public function get_title() {
        return __( 'Listing - Business Hours', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'available', 'time', 'open-close', 'on', 'hours', 'business',
        ];
    }

    public function show_in_panel() {
        return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
    }

    protected function register_controls(): void {
        $this->register_contents();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Listing - Business Hours', 'addonskit-for-elementor' ),
            ]
        );

        $this->end_controls_section();
    }

    public function render(): void {
        echo 'BusinessHours';
    }
}