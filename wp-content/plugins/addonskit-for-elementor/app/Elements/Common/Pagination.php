<?php
namespace AddonskitForElementor\Elements\Common;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

trait Pagination {
    protected function pagination_style_controls( string $section_label = '', string $prefix = '', string $selector = '' ): void {

        $normal = "{$selector} a.page-numbers";
        $arrow  = "{$selector} a.page-numbers .directorist-icon-mask:after";
        $active = "{$selector} span.page-numbers.current";
        $hover  = "{$selector} a.page-numbers:hover";


        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label'     => $section_label,
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => ['show_pagination' => 'yes'],
            ]
        );

        $this->start_controls_tabs( "{$prefix}_tabs_normal" );

        $this->start_controls_tab(
            "{$prefix}_tab_normal", [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$normal}" => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$arrow}"  => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$normal}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border",
                'selector' => "{{WRAPPER}} {$normal}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$normal}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow",
                'selector' => "{{WRAPPER}} {$normal}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_tab_active", [
                'label' => esc_html__( 'Active', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}_color_active",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$active}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color_active",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$active}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_active",
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_active",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$active}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_active",
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_tab_hover", [
                'label' => esc_html__( 'Hover', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}_color_hover",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$hover}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color_hover",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$hover}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_hover",
                'selector' => "{{WRAPPER}} {$hover}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_hover",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$hover}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_hover",
                'selector' => "{{WRAPPER}} {$hover}",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }
}
