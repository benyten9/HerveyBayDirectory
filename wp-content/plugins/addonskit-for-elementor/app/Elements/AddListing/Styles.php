<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AddListing;

use AddonskitForElementor\Utils\DirectoristHelper;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Styles {
	protected function register_add_listing_directory_controls( $selector = '' ): void {

		$this->start_controls_section(
			'section_directory_style',
			[
				'label'     => __( 'Directory Type: Settings', 'addonskit-for-elementor' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);

		$this->add_responsive_control(
			'directory_icon_size',
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
					"{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
				],
				'separator'  => 'before',
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'directory_title_typography',
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->add_responsive_control(
			'directory_margin',
			[
				'label'      => __( 'Margin', 'addonskit-for-elementor' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => ['px', 'em', '%'],
				'selectors'  => [
					"{{WRAPPER}} {$selector}" => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'separator'  => 'before',
			]
		);

		$this->add_responsive_control(
			'directory_padding',
			[
				'label'      => __( 'Padding', 'addonskit-for-elementor' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => ['px', 'em', '%'],
				'selectors'  => [
					"{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->start_controls_tabs( 'directory_header_tabs' );

		// Normal State Tab
		$this->start_controls_tab( 'directory_header_normal', ['label' => __( 'Normal', 'addonskit-for-elementor' )] );

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

		$this->add_control(
			'directory_text_color',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}" => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_bg_color',
			[
				'label'     => __( 'Background Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_icon_color',
			[
				'label'     => __( 'Icon Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_icon_bg_color',
			[
				'label'     => __( 'Icon Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector} .directorist-icon-mask" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		// Hover State Tab
		$this->start_controls_tab( 'directory_header_hover', ['label' => __( 'Hover', 'addonskit-for-elementor' )] );

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

		$this->add_control(
			'directory_text_color_hover',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}:hover" => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_bg_color_hover',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}:hover" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_icon_color_hover',
			[
				'label'     => __( 'Icon', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}:hover .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'directory_icon_bg_color_hover',
			[
				'label'     => __( 'Icon Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}:hover .directorist-icon-mask" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	protected function register_enable_multi_dir(): void {

		$this->start_controls_section(
			'section_enable_multi_dir',
			[
				'label' => __( 'Listing Form: Settings', 'addonskit-for-elementor' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'disable_multi_directory',
			[
				'label'     => '<b>' . __( 'Enable Settings', 'addonskit-for-elementor' ) . '</b>',
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'no',
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);

		$this->end_controls_section();
	}

	protected function register_add_listing_form_container_controls(): void {

		$prefix   = 'form';
		$selector = '.directorist-add-listing-form .directorist-content-module';

		$this->start_controls_section(
			'section_form_style',
			[
				'label'     => __( 'Form: Container', 'addonskit-for-elementor' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'disable_multi_directory' => 'yes',
				],

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
				],
			]
		);

		$this->add_responsive_control(
			"{$prefix}_padding",
			[
				'label'      => __( 'Padding', 'addonskit-for-elementor' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => ['px', 'em', '%'],
				'selectors'  => [
					"{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
				'separator'  => 'before',
			]
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			[
				'name'     => "{$prefix}_bg_color",
				'types'    => ['classic', 'gradient'],
				'exclude'  => ['image'],
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "{$prefix}_border",
				'selector'  => "{{WRAPPER}} {$selector}",
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
					"{{WRAPPER}} {$selector}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			[
				'name'     => "{$prefix}_border_box_shadow",
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->end_controls_section();
	}

	protected function register_add_listing_sidebar_controls( $selector = '' ): void {

		$this->start_controls_section(
			'section_steps_style',
			[
				'label' => __( 'Form: Sidebar', 'addonskit-for-elementor' ),
				'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
					'disable_multi_directory' => 'yes',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => "steps_typography",
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->start_controls_tabs( 'menu_tabs' );

		// Hover State Tab
		$this->start_controls_tab( 'menu_normal', ['label' => __( 'Normal', 'addonskit-for-elementor' )] );
		$this->add_control(
			'steps_menu_color',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}"         => 'color: {{VALUE}};',
					"{{WRAPPER}} {$selector} i:after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'steps_menu_bg_color',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$selector}" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "steps_menu_button_border",
				'selector'  => "{{WRAPPER}} {$selector}",
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"steps_menu_button_border_radius",
			[
				'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
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
				'name'     => "steps_menu_button_border_box_shadow",
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->end_controls_tab();

		// Active State Tab
		$this->start_controls_tab( 'menu_active', ['label' => __( 'Active', 'addonskit-for-elementor' )] );
		$this->add_control(
			'steps_menu_color_active',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover"          => 'color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover:before"   => 'background-color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover i:after"  => 'background-color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}.active"         => 'color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}.active:before"  => 'background-color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}.active i:after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'steps_menu_color_bg_active',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover"  => 'background-color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}.active" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "steps_menu_button_border_active",
				'selector'  => "{{WRAPPER}} {$selector}",
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"steps_menu_button_border_active_radius",
			[
				'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
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
				'name'     => "steps_menu_button_border_active_box_shadow",
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->end_controls_tab();

		// Hover State Tab
		$this->start_controls_tab( 'menu_hover', ['label' => __( 'Hover', 'addonskit-for-elementor' )] );
		$this->add_control(
			'steps_menu_color_hover',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover"         => 'color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover:before"  => 'background-color: {{VALUE}};',
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover i:after" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'steps_menu_color_bg_hover',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} .multistep-wizard .multistep-wizard__nav {$selector}:hover" => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "steps_menu_button_border_hover",
				'selector'  => "{{WRAPPER}} {$selector}:hover",
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"steps_menu_button_border_hover_radius",
			[
				'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
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
				'name'     => "steps_menu_button_border_hover_box_shadow",
				'selector' => "{{WRAPPER}} {$selector}:hover",
			]
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	protected function register_section_name_controls( string $section_label = '', string $prefix = '', string $selector = '' ): void {

		$separator_selector = '.directorist-content-module__contents';

		$this->start_controls_section(
			"section_{$prefix}_style",
			[
				'label' => $section_label,
				'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
					'disable_multi_directory' => 'yes',
				],
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

		$this->add_control(
			"{$prefix}_separator",
			[
				'label'     => __( 'Separator', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"{$prefix}_border",
			[
				'label'      => __( 'Height', 'addonskit-for-elementor' ),
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
					"{{WRAPPER}} {$separator_selector}" => 'border-top: {{SIZE}}{{UNIT}} solid;',
				],
			]
		);

		$this->add_control(
			"{$prefix}_border_color",
			[
				'label'     => __( 'Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} {$separator_selector}" => 'border-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function register_add_listing_form_fields( string $section_label = '', string $prefix = '', string $selector = '' ): void {

		$this->start_controls_section(
			"section_{$prefix}_style",
			[
				'label' => $section_label,
				'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
					'disable_multi_directory' => 'yes',
				],
			]
		);

		$this->add_control(
			"{$prefix}_label",
			[
				'label' => __( 'Label', 'addonskit-for-elementor' ),
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

		$this->add_control(
			"{$prefix}_separator",
			[
				'label'     => __( 'Separator', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			"{$prefix}_separator_height",
			[
				'label'      => esc_html__( 'Height', 'addonskit-for-elementor' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
				'range'      => [
					'px' => [
						'max' => 20,
					],
					'em' => [
						'max' => 2,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .directorist-form-group .directorist-form-element'                                                => 'border-width: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .directorist-form-group .select2.select2-container.select2-container--default .select2-selection' => 'border-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			"{$prefix}_separator_color",
			[
				'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .directorist-form-group .directorist-form-element'                                                => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .directorist-form-group .select2.select2-container.select2-container--default .select2-selection' => 'border-color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function register_add_listing_form_progressbar_controls(): void {

		$this->start_controls_section(
			'section_progressbar_style',
			[
				'label' => __( 'Form: Progressbar', 'addonskit-for-elementor' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'progressbar_color',
			[
				'label'     => esc_html__( 'Progressbar Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .multistep-wizard__progressbar__width:after' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'progressbar_bg_color',
			[
				'label'     => esc_html__( 'Progressbar Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .multistep-wizard__progressbar:before' => 'background-color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'progressbar_height',
			[
				'label'     => esc_html__( 'Height', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SLIDER,
				'selectors' => [
					'{{WRAPPER}} .multistep-wizard__progressbar:before'       => 'height: {{SIZE}}{{UNIT}}; line-height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .multistep-wizard__progressbar__width:after' => 'height: {{SIZE}}{{UNIT}}; line-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	protected function register_add_listing_form_buttons_controls(): void {

		$selector = '.default-add-listing-bottom .directorist-btn.directorist-btn-primary';

		$this->start_controls_section(
			'section_buttons_style',
			[
				'label' => __( 'Form: Save & Preview Button', 'addonskit-for-elementor' ),
				'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => [
					'disable_multi_directory' => 'yes',
				],
			]
		);

		$this->start_controls_tabs( 'button_tabs' );

		// Hover State Tab
		$this->start_controls_tab( 'button_normal', ['label' => __( 'Normal', 'addonskit-for-elementor' )] );
		$this->add_control(
			'button_color',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} $selector" => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_bg_color',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
                    "{{WRAPPER}} $selector" => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "button_border",
				'selector'  => "{{WRAPPER}} {$selector}",
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"button_border_radius",
			[
				'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
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
				'name'     => "button_border_box_shadow",
				'selector' => "{{WRAPPER}} {$selector}",
			]
		);

		$this->end_controls_tab();

		// Hover State Tab
		$this->start_controls_tab( 'button_hover', ['label' => __( 'Hover', 'addonskit-for-elementor' )] );
		$this->add_control(
			'button_color_hover',
			[
				'label'     => __( 'Text Color', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} $selector:hover" => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'button_color_bg_hover',
			[
				'label'     => __( 'Background', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					"{{WRAPPER}} $selector:hover" => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			[
				'name'      => "button_border_hover",
				'selector'  => "{{WRAPPER}} {$selector}:hover",
				'separator' => 'before',
			]
		);

		$this->add_responsive_control(
			"button_border_radius_hover",
			[
				'label'      => __( 'Border Radius', 'addonskit-for-elementor' ),
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
				'name'     => "button_border_box_shadow_hover",
				'selector' => "{{WRAPPER}} {$selector}:hover",
			]
		);

		$this->end_controls_tabs();

		$this->end_controls_section();
	}
}
