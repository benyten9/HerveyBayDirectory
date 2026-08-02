<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\EditAction;

use AddonskitForElementor\Elements\Common\Button;
use AddonskitForElementor\Utils\DirectoristHelper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EditAction extends Widget_Base {

    use Button;

    public function get_name() {
        return 'directorist_single_listing_BackEdit';
    }

    public function get_title() {
        return __( 'Listing - Edit Actions', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return ['edit', 'back', 'continue', 'listing edit', 'go back'];
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
                'label' => esc_html__( 'Listing - Edit Actions', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will display <span style="color: green;">Back, Edit, Continue</span> actions for the listing author on a single listing.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->add_control(
            "actions",
            [
                'label'    => esc_html__( 'Select Action', 'addonskit-for-elementor' ),
                'type'     => Controls_Manager::SELECT2,
                'options'  => [
                    'back'     => 'Back',
                    'edit'     => 'Edit',
                    'continue' => 'Continue',
                ],
                'multiple' => true,
                'default'  => ['back', 'edit', 'continue'],
            ]
        );

        $this->add_responsive_control(
            'info_type_align',
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
        $this->register_button2_style( __( 'Style', 'addonskit-for-elementor' ), 'action_style', '.directorist-single-listing-top .directorist-btn' );
    }

    public function render(): void {

        $settings = $this->get_settings_for_display();
        DirectoristHelper::get_template(
            plugin_dir_path( __FILE__ ) . 'output',
            [
                'actions' => $settings['actions'],
                'id'      => get_the_ID(),
            ]
        );
    }
}