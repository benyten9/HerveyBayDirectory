<?php
namespace AddonskitForElementor\Elements\Common;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;

trait TextControls {
    protected function register_text_controls( string $section_label = '', string $prefix = '', string $selector = '', $condition = '' ): void {

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label'     => $section_label,
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => $condition,
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"       => 'color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$selector}:after" => 'background-color: {{VALUE}};',
                ],
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
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
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

        $this->end_controls_section();
    }

    protected function register_text_fields( string $section_label = '', string $prefix = '', string $selector = '' ): void {

        $this->add_control(
            "section_{$prefix}",
            [
                'label' => $section_label,
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"       => 'color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$selector}:after" => 'background-color: {{VALUE}};',
                ],
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
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
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
    }
}
