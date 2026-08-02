<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\Checkout;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {
    protected function register_section_title_controls(): void {
        $prefix   = 'checkout_section_title';
        $selector = '.directorist-card__header--title';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => __( 'Section Title', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_typography",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_stroke",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_shadow",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'      => "{$prefix}_border",
                'selector'  => "{{WRAPPER}} .directorist-card__header",
                'separator' => 'before',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_section_content_controls(): void {
        $prefix_title     = 'section_title';
        $prefix_content   = 'section_content';
        $selector_title   = '{{WRAPPER}} .directorist-summery-label, {{WRAPPER}} .directorist-radio__label';
        $selector_content = '{{WRAPPER}} .directorist-payment-text, {{WRAPPER}} .directorist-summery-label-description';

        $this->start_controls_section(
            "section_section_content_style",
            [
                'label' => __( 'Section Content', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "{$prefix_title}_title",
            [
                'label'     => __( 'Title', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix_title}_typography",
                'selector' => "$selector_title",
            ]
        );

        $this->add_control(
            "{$prefix_title}_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "$selector_title" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix_content}_content",
            [
                'label'     => __( 'Content', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix_content}_typography",
                'selector' => "$selector_content",
            ]
        );

        $this->add_control(
            "{$prefix_content}_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "$selector_content" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix_title}_price",
            [
                'label'     => __( 'Prices', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            "{$prefix_content}_pricing_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-summery-amount" => 'color: {{VALUE}} !important;',
                ],
                'separator' => 'before',
            ]
        );

        $this->add_control(
            "{$prefix_content}_border_color",
            [
                'label'     => __( 'Border Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-checkout-card tr td" => 'border-color: {{VALUE}} !important;',
                ],
                'separator' => 'before',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_button_controls(): void {

        $this->start_controls_section(
            'section_buttons_style',
            [
                'label' => __( 'Action Buttons', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->start_controls_tabs( 'button_tabs' );

        // Hover State Tab
        $this->start_controls_tab( 'button_primary', ['label' => __( 'Pay Now', 'addonskit-for-elementor' )] );
        $this->add_control(
            'button_color',
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .directorist-btn.directorist-btn-primary' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'button_bg_color',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .directorist-btn.directorist-btn-primary' => 'background-color: {{VALUE}};',
                    '{{WRAPPER}} #atbdp_checkout_submit_btn'               => 'border-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->end_controls_tab();

        // Hover State Tab
        $this->start_controls_tab( 'button_secondary', ['label' => __( 'Not Now', 'addonskit-for-elementor' )] );
        $this->add_control(
            'button_color_secondary',
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .directorist-btn.directorist-btn-light' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'button_color_bg_secondary',
            [
                'label'     => __( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .directorist-btn.directorist-btn-light' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_tabs();

        $this->end_controls_section();
    }
}