<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Review;

use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {
    protected function register_review_button_style(): void {

        $class = '.directorist-card__header .directorist-btn';
        $icon  = "{$class} .directorist-icon-mask:after";

        $this->start_controls_section(
            'section_review_buttons_style',
            [
                'label' => __( 'Add Review: Button Style', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->start_controls_tabs( 'review_button_tabs' );

        // Hover State Tab
        $this->start_controls_tab( 'review_button_normal', ['label' => __( 'Normal', 'addonskit-for-elementor' )] );
        $this->add_control(
            'review_button_color',
            [
                'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}" => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$icon}"  => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'review_button_bg_color',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-review-container {$class}" => 'border-color: {{VALUE}};',
                    "{{WRAPPER}} {$class}"                               => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        // Hover State Tab
        $this->start_controls_tab( 'review_button_hover', ['label' => __( 'Hover', 'addonskit-for-elementor' )] );
        $this->add_control(
            'review_button_color_hover',
            [
                'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}:hover"          => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$class}:hover i::after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'review_button_color_bg_hover',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}:hover"                               => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} .directorist-review-container {$class}:hover" => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function register_submit_button_style(): void {

        $class = '.directorist-review-submit button.directorist-btn';

        $this->start_controls_section(
            'section_review_form_buttons_style',
            [
                'label' => __( 'Submit Review: Button Style', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->start_controls_tabs( 'submit_button_tabs' );

        // Hover State Tab
        $this->start_controls_tab( 'submit_button_normal', ['label' => __( 'Normal', 'addonskit-for-elementor' )] );
        $this->add_control(
            'submit_button_color',
            [
                'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'submit_button_bg_color',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}"                               => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} .directorist-review-container {$class}" => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tab();

        // Hover State Tab
        $this->start_controls_tab( 'submit_button_hover', ['label' => __( 'Hover', 'addonskit-for-elementor' )] );
        $this->add_control(
            'submit_button_color_hover',
            [
                'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}:hover" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'submit_button_color_bg_hover',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$class}:hover"                               => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} .directorist-review-container {$class}:hover" => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tabs();

        $this->end_controls_section();
    }
}