<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Booking;

use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Booking extends Widget_Base {
    public function get_name() {
        return 'directorist_single_listing_booking';
    }

    public function get_title() {
        return __( 'Listing - Booking', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'booking', 'reservation', 'book', 'reserve', 'book now',
        ];
    }

    protected function register_controls(): void {
        $this->register_contents();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Listing - Booking', 'addonskit-for-elementor' ),
            ]
        );

        $this->end_controls_section();
    }

    public function render(): void {
        echo 'Booking';
    }
}