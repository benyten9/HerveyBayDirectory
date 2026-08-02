<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\UserLogin;

use Elementor\Group_Control_Background;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {
    protected function register_login_form_label_controls(): void {

        $prefix   = 'login_form_label';
        $selector = '.directorist-form-group label';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => __( 'Form Labels', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}label_typography",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_control(
            "{$prefix}label_color",
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
                'name'     => "{$prefix}label_stroke",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}label_shadow",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_control(
            "{$prefix}_remember_me",
            [
                'label'     => __( 'Remember Me', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_remember_me_typography",
                'selector' => "{{WRAPPER}} .directorist-checkbox__label",
            ]
        );

        $this->add_control(
            "{$prefix}_remember_me_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-checkbox__label" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_recover",
            [
                'label'     => __( 'Recover Password', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_recover_typography",
                'selector' => "{{WRAPPER}} .atbdp_recovery_pass",
            ]
        );

        $this->add_control(
            "{$prefix}_recover_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .atbdp_recovery_pass" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_account",
            [
                'label'     => __( "Don't have an account", 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_account_typography",
                'selector' => "{{WRAPPER}} .directorist-author__form__toggle-area",
            ]
        );

        $this->add_control(
            "{$prefix}_account_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-author__form__toggle-area" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_signup",
            [
                'label'     => __( "Sign Up", 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_signup_typography",
                'selector' => "{{WRAPPER}} .directorist-author__form__toggle-area a",
            ]
        );

        $this->add_control(
            "{$prefix}_signup_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-author__form__toggle-area a" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function register_login_form_fields_controls(): void {

        $prefix   = 'login_form_fields';
        $selector = '.directorist-form-element';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => __( 'Form Fields', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "{$prefix}_placeholder",
            [
                'label'     => __( 'Placeholder', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_control(
            "{$prefix}_placeholder_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}::placeholder" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_placeholder_typography",
                'selector' => "{{WRAPPER}} {$selector}::placeholder",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_placeholder_stroke",
                'selector' => "{{WRAPPER}} {$selector}::placeholder",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_placeholder_shadow",
                'selector' => "{{WRAPPER}} {$selector}::placeholder",
            ]
        );

        $this->add_control(
            "{$prefix}_separator",
            [
                'label'     => __( 'Field Separator', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border",
            [
                'label'      => __( 'Width', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range'      => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 10,
                        'step' => 1,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'border-bottom: {{SIZE}}{{UNIT}} solid;',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_border_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}" => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function register_button_2_style_controls( $section_label = '', $prefix = '', $selector = '' ) {


        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => $section_label,
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

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->start_controls_tabs( "tabs_{$prefix}_style" );

        $this->start_controls_tab(
            "{$prefix}_normal",
            [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}_text_color_normal",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                               => 'fill: {{VALUE}}; color: {{VALUE}};',
                    "{{WRAPPER}} {$selector}"                               => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => "{$prefix}_background_normal",
                'selector' => "{{WRAPPER}} {$selector}",
                'exclude'  => ['image']
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_normal",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_normal",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_normal",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_button_hover",
            [
                'label' => esc_html__( 'Hover', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}hover_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}:hover, {{WRAPPER}} {$selector}:focus"         => 'color: {{VALUE}}; border-color: {{VALUE}}!important',
                    "{{WRAPPER}} {$selector}:hover svg, {{WRAPPER}} {$selector}:focus svg" => 'fill: {{VALUE}};',
                    "{{WRAPPER}} {$selector}:hover .directorist-icon-mask::after"          => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => "{$prefix}_background_hover",
                'selector' => "{{WRAPPER}} {$selector}:hover, {{WRAPPER}} {$selector}:focus",
            ]
        );
        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_hover",
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_hover",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}:hover" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_hover",
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control(
            "{$prefix}_text_padding",
            [
                'label'      => esc_html__( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator'  => 'before',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_account_text_controls( string $section_label = '', string $prefix = '' ): void {

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label'     => $section_label,
                'tab'       => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} .directorist-radio__label"                      => 'color: {{VALUE}}',
                    "{{WRAPPER}} .directorist-checkbox__label"                   => 'color: {{VALUE}}',
                    "{{WRAPPER}} .directorist-authentication__form p"            => 'color: {{VALUE}}',
                    "{{WRAPPER}} .directorist-authentication__form__toggle-area" => 'color: {{VALUE}}',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_typography",
                'selector' => "{{WRAPPER}} .directorist-radio__label,
                    {{WRAPPER}} .directorist-checkbox__label,
                    {{WRAPPER}} .directorist-authentication__form p,
                    {{WRAPPER}} .directorist-authentication__form p button,
                    {{WRAPPER}} .directorist-authentication__form__toggle-area",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
                'selector' => "{{WRAPPER}} .directorist-radio__label,
                    {{WRAPPER}} .directorist-checkbox__label,
                    {{WRAPPER}} .directorist-authentication__form p,
                    {{WRAPPER}} .directorist-authentication__form p button,
                    {{WRAPPER}} .directorist-authentication__form__toggle-area",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} .directorist-radio__label,
                    {{WRAPPER}} .directorist-checkbox__label,
                    {{WRAPPER}} .directorist-authentication__form p,
                    {{WRAPPER}} .directorist-authentication__form p button,
                    {{WRAPPER}} .directorist-authentication__form__toggle-area",
            ]
        );

        $this->end_controls_section();
    }

    protected function register_form_fields_separator_controls( $section_label = '', $prefix = '', $selector = '' ): void {

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => $section_label,
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border",
            [
                'label'      => __( 'Width', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range'      => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 10,
                        'step' => 1,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'border-bottom: {{SIZE}}{{UNIT}} solid;',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_border_color",
            [
                'label'     => __( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}" => 'border-color: {{VALUE}};',
                ],
            ]
        );

        $this->end_controls_section();
    }
}