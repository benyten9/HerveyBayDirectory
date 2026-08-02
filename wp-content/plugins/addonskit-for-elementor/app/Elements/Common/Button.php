<?php
namespace AddonskitForElementor\Elements\Common;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Typography;

trait Button {

	protected function register_button1_style( string $section_label = '', string $prefix = '', string $selector = '', string $active = '' ) {

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
				'exclude'  => ['image'],
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
			"{$prefix}_button_active",
			[
				'label' => esc_html__( 'Active', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			"{$prefix}active_color",
			[
				'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => [
					"{{WRAPPER}} {$active}"                               => 'fill: {{VALUE}}; color: {{VALUE}};',
					"{{WRAPPER}} {$active}"                               => 'color: {{VALUE}}; border-color: {{VALUE}}!important',
					"{{WRAPPER}} {$active} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => "{$prefix}_background_active",
				'selector' => "{{WRAPPER}} {$active}",
				'exclude'  => ['image'],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'     => "{$prefix}_border",
				'selector' => "{{WRAPPER}} {$active}",
			]
		);

		$this->add_responsive_control(
			"{$prefix}_border_radius",
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
				'name'     => "{$prefix}_box_shadow",
				'selector' => "{{WRAPPER}} {$active}",
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
				'exclude'  => ['image'],
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
		
		$this->add_responsive_control(
			"{$prefix}_text_margin",
			[
				'label'      => esc_html__( 'Margin', 'addonskit-for-elementor' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
				'selectors'  => [
					"{{WRAPPER}} {$selector}" => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'separator'  => 'before',
			]
		);

		$this->end_controls_section();
	}

	protected function register_button2_style( string $section_label = '', string $prefix = '', string $selector = '' ) {

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
				'exclude'  => ['image'],
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
			"{$prefix}_hover_color",
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
				'exclude'  => ['image'],
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
}