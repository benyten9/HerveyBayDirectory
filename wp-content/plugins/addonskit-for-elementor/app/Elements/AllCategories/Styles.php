<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AllCategories;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {
    protected function register_category_card_style_controls(): void {

        $section_label = __( 'Category Card', 'addonskit-for-elementor' );
        $prefix        = 'category_area';
        $selector      = '.directorist-categories__single';
        $selector_list = '.directorist-taxonomy-list__card   ';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => $section_label,
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_padding",
            [
                'label'      => __( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} "      => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} {$selector_list} " => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator'  => 'before',
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_margin",
            [
                'label'      => __( 'Margin', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} {$selector_list}" => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_bg_color",
            [
                'label'       => __( 'Background Color', 'addonskit-for-elementor' ),
                'description' => __( 'For empty background image.', 'addonskit-for-elementor' ),
                'type'        => Controls_Manager::COLOR,
                'selectors'   => [
                    "{{WRAPPER}} {$selector} "      => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$selector_list} " => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'      => "{$prefix}_border",
                'selector'  => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$selector_list}",
                'separator' => 'before',
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius",
            [
                'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} "      => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} {$selector_list} " => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_border_box_shadow",
                'selector' => "{{WRAPPER}} {$selector} ",
            ]
        );

        $this->end_controls_section();
    }

    protected function register_category_title_controls(): void {

        $section_label  = __( 'Category Title', 'addonskit-for-elementor' );
        $prefix         = 'category_title';
        $selector       = '.directorist-categories__single__name';
        $selector_list  = '.directorist-taxonomy-list-one';
        $selector_name  = '.directorist-taxonomy-list__name';
        $selector_count = '.directorist-taxonomy-list__count';

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
                'name'     => "{$prefix}_color_typography",
                'selector' => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$selector_list} {$selector_name}, {{WRAPPER}} {$selector_list} {$selector_count}",
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                        => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector_list} {$selector_name}"  => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector_list} {$selector_count}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
                'selector' => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$selector_list} {$selector_name}, {{WRAPPER}} {$selector_list} {$selector_count}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$selector_list} {$selector_name}, {{WRAPPER}} {$selector_list} {$selector_count}",
            ]
        );

        $this->end_controls_section();
    }

    protected function register_category_icon_styles() {
        $selector      = '.directorist-categories__single__icon';
        $selector_list = '.directorist-taxonomy-list-one .directorist-taxonomy-list__card';

        $this->start_controls_section(
            'section_category_icon_style',
            [
                'label' => esc_html__( 'Icon', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'category_icon_size',
            [
                'label'      => __( 'Size', 'addonskit-for-elementor' ),
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
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after"      => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important',
                    "{{WRAPPER}} {$selector_list} .directorist-icon-mask::after" => 'width: {{SIZE}}{{UNIT}} !important; height: {{SIZE}}{{UNIT}} !important',
                ],
            ]
        );

        $this->add_control(
            'category_icon_color',
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after"      => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$selector_list} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'category_icon_background_color',
            [
                'label'       => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'description' => __( 'For empty background image.', 'addonskit-for-elementor' ),
                'type'        => Controls_Manager::COLOR,
                'selectors'   => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask" => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$selector_list} .directorist-icon-mask" => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} .directorist-categories__single--image .directorist-categories__single__content {$selector} .directorist-icon-mask" => 'background-color: transparent !important;',
                ],
            ]
        );

        $this->add_control(
            "category_padding",
            [
                'label'      => __( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', 'em', '%'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} "      => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} {$selector_list} " => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
                'separator'  => 'before',
            ]
        );

        $this->end_controls_section();
    }
}
