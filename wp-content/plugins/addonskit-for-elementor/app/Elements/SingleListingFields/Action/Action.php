<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Action;

use AddonskitForElementor\Elements\Common\Button;
use AddonskitForElementor\Utils\DirectoristHelper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Action extends Widget_Base {

    use Button;

    public function get_name() {
        return 'directorist_single_listing_quickaction';
    }

    public function get_title() {
        return __( 'Listing - Action', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'quickaction', 'bookmark', 'report', 'share', 'compare', 'single listing',
        ];
    }

    public function show_in_panel() {
        return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Listing - Action', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "widget_name",
            [
                'label'    => esc_html__( 'Select Action', 'addonskit-for-elementor' ),
                'type'     => Controls_Manager::SELECT2,
                'options'  => DirectoristHelper::get_header_quick_action_fields( 'quick-widgets-placeholder' ),
                'multiple' => true,
                'default'  => ['bookmark', 'share', 'report'],
            ]
        );

        $this->add_responsive_control(
            'action_type_align',
            [
                'label'     => esc_html__( 'Alignment', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::CHOOSE,
                'options'   => [
                    'left'   => [
                        'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'right'  => [
                        'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
                'default'   => '',
                'selectors' => [
                    '{{WRAPPER}} .directorist-justify-content-between' => 'justify-content: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles(): void {
        $this->register_button2_style( __( 'Style', 'addonskit-for-elementor' ), 'action_style', '.directorist-single-listing-quick-action .directorist-btn-light' );
    }

    public function render(): void {
        $settings = $this->get_settings();

        echo '<div class="directorist-flex directorist-align-center directorist-justify-content-between"><div class="directorist-single-listing-quick-action directorist-flex directorist-align-center directorist-justify-content-between">';

        foreach ( $settings['widget_name'] as $widget_name ) {
            DirectoristHelper::get_single_listing_info( $widget_name );
        }

        echo '</div></div>';
    }
}