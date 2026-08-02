<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\Common;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;
use AddonskitForElementor\Utils\DirectoristHelper;

trait DirectoryTypeStyles {
    protected function register_directory_type_style_controls( string $selector = '', $args = [], string $active = '' ) {

        $default_args = [
            'section_condition' => [],
        ];

        $args = wp_parse_args( $args, $default_args );

        $this->start_controls_section(
            'section_directory_type_style',
            [
                'label'     => esc_html__( 'Type: Settings', 'addonskit-for-elementor' ),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
            ]
        );

        $this->add_responsive_control(
            'directory_type_icon_size',
            [
                'label'      => __( 'Icon Size', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em'],
                'range'      => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 50,
                        'step' => 1,
                    ],
                    'em' => [
                        'min'  => 0,
                        'max'  => 5,
                        'step' => .5,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
                ],
            ]
        );

        $this->add_responsive_control(
            'directory_type_icon_gap',
            [
                'label'      => __( 'Icon Margin', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'selectors'  => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask" => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'      => 'directory_type_typography',
                'selector'  => "{{WRAPPER}} {$selector}",
                'condition' => $args['section_condition'],
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'       => 'directory_type_text_shadow',
                'selector'   => "{{WRAPPER}} {$selector}",
                'condition'  => $args['section_condition'],
                'separation' => 'after',
            ]
        );
        
        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'directory_types_area_border',
                'selector' => "{{WRAPPER}} .directorist-listing-type-selection, {{WRAPPER}} .directorist-type-nav__list",
            ]
        );

        $this->start_controls_tabs( 'tabs_button_style' );

        $this->start_controls_tab(
            'tab_directory_type_button_normal',
            [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'directory_type_button_text_color_normal',
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'default'   => '',
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                               => 'fill: {{VALUE}}; color: {{VALUE}};',
                    "{{WRAPPER}} {$selector}"                               => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$selector}::after"                        => 'background-color: {{VALUE}}',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'     => 'directory_type_background_normal',
                'types'    => ['classic', 'gradient'],
                'exclude'  => ['image'],
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'directory_type_border_normal',
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            'directory_type_border_radius_normal',
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
                'name'     => 'directory_type_button_box_shadow_normal',
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_directory_type_button_active',
            [
                'label'     => esc_html__( 'Active', 'addonskit-for-elementor' ),
                'condition' => $args['section_condition'],
            ]
        );

        $this->add_control(
            'directory_type_active_color',
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'default'   => '',
                'selectors' => [
                    "{{WRAPPER}} {$active}"                               => 'fill: {{VALUE}}; color: {{VALUE}};',
                    "{{WRAPPER}} {$active}"                               => 'color: {{VALUE}}; border-color: {{VALUE}}!important',
                    "{{WRAPPER}} {$active} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
                ],
                'condition' => $args['section_condition'],
            ]
        );

        $this->add_group_control(
            Group_Control_Background::get_type(),
            [
                'name'           => 'directory_type_background_active',
                'types'          => ['classic', 'gradient'],
                'exclude'        => ['image'],
                'selector'       => "{{WRAPPER}} {$active}",
                'fields_options' => [
                    'background' => [
                        'default' => 'classic',
                    ],
                    'color'      => [
                        'global' => [
                        ],
                    ],
                ],
                'condition'      => $args['section_condition'],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'directory_type_border',
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->add_responsive_control(
            'directory_type_border_radius',
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$active}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} .directorist-listing-type-selection__item a:after" => 'background-color: transparent;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'directory_type_button_box_shadow',
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'tab_directory_type_button_hover',
            [
                'label' => esc_html__( 'Hover', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'directory_type_hover_color',
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
                'name'     => 'directory_type_button_background_hover',
                'types'    => ['classic', 'gradient'],
                'exclude'  => ['image'],
                'selector' => "{{WRAPPER}} {$selector}:hover, {{WRAPPER}} {$selector}:focus",
            ]
        );
        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'directory_type_border_hover',
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->add_responsive_control(
            'directory_type_border_radius_hover',
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
                'name'     => 'directory_type_button_box_shadow_hover',
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control(
            'directory_type_text_padding',
            [
                'label'      => esc_html__( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'condition'  => $args['section_condition'],
            ]
        );

        $this->end_controls_section();
    }
}