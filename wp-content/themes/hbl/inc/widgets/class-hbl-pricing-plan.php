<?php
/**
 * HBL Pricing Plan Widget
 *
 * Feature comparison table widget for pricing plans
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL Pricing Plan Widget Class
 */
class HBL_Pricing_Plan extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-pricing-plan';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'Pricing Plan', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-price-table';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories() {
		return array( 'hbl' );
	}

	/**
	 * Get widget keywords
	 */
	public function get_keywords() {
		return array( 'hbl', 'pricing', 'plan', 'comparison', 'table', 'features' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// ========== CONTENT SECTION: TITLE ==========
		$this->start_controls_section(
			'section_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => esc_html__( 'Section Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Feature Comparison Table', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT SECTION: PLANS ==========
		$this->start_controls_section(
			'section_plans',
			array(
				'label' => esc_html__( 'Plans', 'hbl' ),
			)
		);

		$repeater_plans = new Repeater();

		$repeater_plans->add_control(
			'plan_name',
			array(
				'label'       => esc_html__( 'Plan Name', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Essential', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$repeater_plans->add_control(
			'plan_subtitle',
			array(
				'label'       => esc_html__( 'Plan Subtitle', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( '(Free)', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		$repeater_plans->add_control(
			'button_text',
			array(
				'label'       => esc_html__( 'Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Join for Free', 'hbl' ),
				'label_block' => true,
			)
		);

		$repeater_plans->add_control(
			'button_link',
			array(
				'label'       => esc_html__( 'Button Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url' => '#',
				),
			)
		);

		$repeater_plans->add_control(
			'highlight',
			array(
				'label'        => esc_html__( 'Highlight Plan', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$repeater_plans->add_control(
			'coming_soon',
			array(
				'label'        => esc_html__( 'Coming Soon Mode', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		$repeater_plans->add_control(
			'coming_soon_text',
			array(
				'label'       => esc_html__( 'Coming Soon Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Coming Soon', 'hbl' ),
				'condition'   => array(
					'coming_soon' => 'yes',
				),
			)
		);

		$this->add_control(
			'plans',
			array(
				'label'       => esc_html__( 'Pricing Plans', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater_plans->get_controls(),
				'default'     => array(
					array(
						'plan_name'    => esc_html__( 'Essential', 'hbl' ),
						'plan_subtitle' => esc_html__( '(Free)', 'hbl' ),
						'button_text'  => esc_html__( 'Join for Free', 'hbl' ),
						'button_link'  => array( 'url' => '#' ),
						'highlight'    => 'no',
					),
					array(
						'plan_name'    => esc_html__( 'Elite', 'hbl' ),
						'plan_subtitle' => esc_html__( '($19/mo or $190/yr)', 'hbl' ),
						'button_text'  => esc_html__( 'Upgrade to Elite', 'hbl' ),
						'button_link'  => array( 'url' => '#' ),
						'highlight'    => 'no',
					),
					array(
						'plan_name'    => esc_html__( 'VIP', 'hbl' ),
						'plan_subtitle' => esc_html__( '($49/mo or $490/yr)', 'hbl' ),
						'button_text'  => esc_html__( 'Become VIP', 'hbl' ),
						'button_link'  => array( 'url' => '#' ),
						'highlight'    => 'yes',
					),
				),
				'title_field' => '{{{ plan_name }}}',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT SECTION: FEATURES ==========
		$this->start_controls_section(
			'section_features',
			array(
				'label' => esc_html__( 'Features', 'hbl' ),
			)
		);

		$repeater_features = new Repeater();

		$repeater_features->add_control(
			'feature_name',
			array(
				'label'       => esc_html__( 'Feature Name', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Business Name, Address, Phone', 'hbl' ),
				'label_block' => true,
				'dynamic'     => array(
					'active' => true,
				),
			)
		);

		// Add nested repeater for plan values
		$repeater_plan_values = new Repeater();
		
		$repeater_plan_values->add_control(
			'icon_type',
			array(
				'label'       => esc_html__( 'Icon Type', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'tick',
				'options'     => array(
					'tick'    => esc_html__( 'Tick / Checkmark', 'hbl' ),
					'cross'   => esc_html__( 'Cross / X', 'hbl' ),
					'linebar' => esc_html__( 'Linebar / Dash', 'hbl' ),
					'custom'  => esc_html__( 'Custom Icon', 'hbl' ),
					'image'   => esc_html__( 'Custom Image', 'hbl' ),
					'none'    => esc_html__( 'None (Text Only)', 'hbl' ),
				),
				'description' => esc_html__( 'Choose which icon to display for this feature value', 'hbl' ),
			)
		);

		$repeater_plan_values->add_control(
			'custom_icon',
			array(
				'label'     => esc_html__( 'Custom Icon', 'hbl' ),
				'type'      => Controls_Manager::ICONS,
				'default'   => array(
					'value'   => 'fas fa-check',
					'library' => 'fa-solid',
				),
				'condition' => array(
					'icon_type' => 'custom',
				),
			)
		);

		$repeater_plan_values->add_control(
			'custom_image',
			array(
				'label'     => esc_html__( 'Custom Image', 'hbl' ),
				'type'      => Controls_Manager::MEDIA,
				'default'   => array(
					'url' => '',
				),
				'condition' => array(
					'icon_type' => 'image',
				),
			)
		);

		$repeater_plan_values->add_control(
			'plan_value',
			array(
				'label'       => esc_html__( 'Value Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
				'description' => esc_html__( 'Leave empty to show only icon, or enter custom text', 'hbl' ),
			)
		);

		$repeater_features->add_control(
			'plan_values',
			array(
				'label'       => esc_html__( 'Values per Plan', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater_plan_values->get_controls(),
				'default'     => array(
					array( 'icon_type' => 'tick', 'plan_value' => '' ),
					array( 'icon_type' => 'tick', 'plan_value' => '' ),
					array( 'icon_type' => 'tick', 'plan_value' => '' ),
				),
				'title_field' => 'Value: {{{ plan_value }}}',
			)
		);

		$this->add_control(
			'features',
			array(
				'label'       => esc_html__( 'Features', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater_features->get_controls(),
				'default'     => array(
					array(
						'feature_name' => esc_html__( 'Business Name, Address, Phone', 'hbl' ),
						'plan_values'   => array(
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
						),
					),
					array(
						'feature_name' => esc_html__( 'Logo / Main Image', 'hbl' ),
						'plan_values'   => array(
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
							array( 'icon_type' => 'tick', 'plan_value' => '' ),
						),
					),
					array(
						'feature_name' => esc_html__( 'Short Business Description', 'hbl' ),
						'plan_values'   => array(
							array( 'icon_type' => 'tick', 'plan_value' => '50–100 words' ),
							array( 'icon_type' => 'tick', 'plan_value' => '250–500 words' ),
							array( 'icon_type' => 'tick', 'plan_value' => 'Extended' ),
						),
					),
				),
				'title_field' => '{{{ feature_name }}}',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TITLE ==========
		$this->start_controls_section(
			'section_style_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => esc_html__( 'Bottom Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CONTAINER ==========
		$this->start_controls_section(
			'section_style_container',
			array(
				'label' => esc_html__( 'Container', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'container_gap',
			array(
				'label'      => esc_html__( 'Gap Between Sections', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 44,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-wrapper' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TABLE ==========
		$this->start_controls_section(
			'section_style_table',
			array(
				'label' => esc_html__( 'Table', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HEADER ROW ==========
		$this->start_controls_section(
			'section_style_header',
			array(
				'label' => esc_html__( 'Header Row', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'header_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-header-row',
			)
		);

		$this->add_responsive_control(
			'header_padding',
			array(
				'label'      => esc_html__( 'Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-header-cell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'header_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-header-row' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-header-cell' => 'border-right-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'header_feature_text_heading',
			array(
				'label'     => esc_html__( 'Feature Column Text', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'header_feature_text_typography',
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-header-text',
			)
		);

		$this->add_control(
			'header_feature_text_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-header-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'header_feature_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-header-cell.hbl-pricing-plan-feature-column',
			)
		);

		$this->add_control(
			'header_plan_text_heading',
			array(
				'label'     => esc_html__( 'Plan Column Text', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'header_plan_name_typography',
				'label'    => esc_html__( 'Plan Name Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-name',
			)
		);

		$this->add_control(
			'header_plan_name_color',
			array(
				'label'     => esc_html__( 'Plan Name Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-name' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'header_plan_subtitle_typography',
				'label'    => esc_html__( 'Plan Subtitle Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-subtitle',
			)
		);

		$this->add_control(
			'header_plan_subtitle_color',
			array(
				'label'     => esc_html__( 'Plan Subtitle Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-subtitle' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'header_plan_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-header-cell.hbl-pricing-plan-column',
			)
		);

		$this->add_control(
			'header_highlight_heading',
			array(
				'label'     => esc_html__( 'Highlighted Plan', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'header_highlight_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-header-cell.highlight',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: FEATURE ROW ==========
		$this->start_controls_section(
			'section_style_feature_row',
			array(
				'label' => esc_html__( 'Feature Rows', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'feature_row_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-row',
			)
		);

		$this->add_responsive_control(
			'feature_row_padding',
			array(
				'label'      => esc_html__( 'Cell Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'feature_row_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-row' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell' => 'border-right-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'feature_column_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-feature-column',
			)
		);

		$this->add_control(
			'feature_name_heading',
			array(
				'label'     => esc_html__( 'Feature Name', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'feature_name_typography',
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-feature-name',
			)
		);

		$this->add_control(
			'feature_name_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-feature-name' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'feature_value_heading',
			array(
				'label'     => esc_html__( 'Feature Value', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'feature_value_typography',
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-feature-value',
			)
		);

		$this->add_control(
			'feature_value_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-feature-value' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'feature_cell_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-column',
			)
		);

		$this->add_control(
			'feature_highlight_heading',
			array(
				'label'     => esc_html__( 'Highlighted Plan Column', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'feature_highlight_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-column.highlight',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: ICONS ==========
		$this->start_controls_section(
			'section_style_icons',
			array(
				'label' => esc_html__( 'Icons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->start_controls_tabs( 'icon_style_tabs' );

		// Normal State
		$this->start_controls_tab(
			'icon_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'icon_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-icon i' => 'color: {{VALUE}};',
					// Target SVG directly (it has the class hbl-pricing-icon)
					'{{WRAPPER}} svg.hbl-pricing-icon' => 'color: {{VALUE}}; stroke: {{VALUE}};',
					// Target inner elements of the SVG
					'{{WRAPPER}} .hbl-pricing-icon path' => 'stroke: {{VALUE}}; fill: currentColor;',
					'{{WRAPPER}} .hbl-pricing-icon circle' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-icon line' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-icon rect' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-icon polyline' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-icon-wrap' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		// Hover State
		$this->start_controls_tab(
			'icon_hover',
			array(
				'label' => esc_html__( 'Hover', 'hbl' ),
			)
		);

		$this->add_control(
			'icon_hover_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon i' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover svg.hbl-pricing-icon' => 'color: {{VALUE}}; stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon path' => 'stroke: {{VALUE}}; fill: currentColor;',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon circle' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon line' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon rect' => 'stroke: {{VALUE}};',
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-icon polyline' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-feature-cell:hover .hbl-pricing-plan-icon-wrap' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}; font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-pricing-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-pricing-icon i' => 'font-size: {{SIZE}}{{UNIT}};',
				),
				'separator'  => 'before',
			)
		);

		$this->add_responsive_control(
			'icon_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-icon-wrap' => 'margin-right: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'icon_padding',
			array(
				'label'      => esc_html__( 'Padding', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-icon-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'icon_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-pricing-plan-icon-wrap' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BUTTONS ==========
		$this->start_controls_section(
			'section_style_buttons',
			array(
				'label' => esc_html__( 'Buttons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-button',
			)
		);

		$this->start_controls_tabs( 'button_style_tabs' );

		// Normal State
		$this->start_controls_tab(
			'button_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'button_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-button',
			)
		);

		$this->end_controls_tab();

		// Hover State
		$this->start_controls_tab(
			'button_hover',
			array(
				'label' => esc_html__( 'Hover', 'hbl' ),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_hover_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-button:hover',
			)
		);

		$this->add_control(
			'button_hover_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-pricing-plan-button:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_control(
			'button_row_bg_heading',
			array(
				'label'     => esc_html__( 'Button Row Background', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_row_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-buttons-wrapper',
			)
		);

		$this->add_control(
			'button_cell_bg_heading',
			array(
				'label'     => esc_html__( 'Button Column Background', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_cell_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-button-column',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'button_cell_highlight_bg',
				'label'    => esc_html__( 'Highlighted Button Column Background', 'hbl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-pricing-plan-button-column.highlight',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( empty( $settings['plans'] ) || empty( $settings['features'] ) ) {
			return;
		}

		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		$plans = $settings['plans'];
		$features = $settings['features'];
		$num_plans = count( $plans );
		?>

		<div class="hbl-pricing-plan-wrapper">
			
			<?php if ( ! empty( $title ) ) : ?>
			<div class="hbl-pricing-plan-header">
				<div class="hbl-pricing-plan-header-icon">
					<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M21 16V8C20.9996 7.6493 20.9071 7.3048 20.7315 7.00017C20.556 6.69555 20.3037 6.44158 20 6.26L13 2.26C12.696 2.08805 12.3511 1.99804 12 1.99804C11.6489 1.99804 11.304 2.08805 11 2.26L4 6.26C3.69626 6.44158 3.44398 6.69555 3.26846 7.00017C3.09294 7.3048 3.00036 7.6493 3 8V16C3.00036 16.3507 3.09294 16.6952 3.26846 16.9998C3.44398 17.3045 3.69626 17.5584 4 17.74L11 21.74C11.304 21.912 11.6489 22.002 12 22.002C12.3511 22.002 12.696 21.912 13 21.74L20 17.74C20.3037 17.5584 20.556 17.3045 20.7315 16.9998C20.9071 16.6952 20.9996 16.3507 21 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M3.27 6.96L12 12.01L20.73 6.96" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M12 22.08V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</div>
				<div class="hbl-pricing-plan-header-text-wrap">
					<h2 class="hbl-pricing-plan-title"><?php echo esc_html( $title ); ?></h2>
					<p class="hbl-pricing-plan-subtitle-text"><?php esc_html_e( 'Compare our listing packages and choose the best fit for your business', 'hbl' ); ?></p>
				</div>
			</div>
			<?php endif; ?>

			<div class="hbl-pricing-plan-table" data-plans="<?php echo esc_attr( $num_plans ); ?>">
				
				<!-- Header Row with Plan Cards -->
				<div class="hbl-pricing-plan-cards-row">
					<div class="hbl-pricing-plan-feature-label">
						<span class="hbl-pricing-plan-feature-label-text"><?php echo esc_html__( 'Feature / Benefit', 'hbl' ); ?></span>
					</div>
					<?php foreach ( $plans as $plan_index => $plan ) : 
						$highlight_class = isset( $plan['highlight'] ) && 'yes' === $plan['highlight'] ? 'hbl-plan-highlighted' : '';
						$is_coming_soon = isset( $plan['coming_soon'] ) && 'yes' === $plan['coming_soon'];
						$coming_soon_text = isset( $plan['coming_soon_text'] ) ? $plan['coming_soon_text'] : esc_html__( 'Coming Soon', 'hbl' );
						
						if ( $is_coming_soon ) {
							$highlight_class .= ' hbl-plan-coming-soon';
						}
						
						$button_link = isset( $plan['button_link'] ) ? $plan['button_link'] : array();
						$button_url = isset( $button_link['url'] ) ? $button_link['url'] : '#';
						if ( $is_coming_soon ) {
							$button_url = '#';
						}
						
						$button_text = isset( $plan['button_text'] ) ? $plan['button_text'] : esc_html__( 'Get Started', 'hbl' );
						$target = isset( $button_link['is_external'] ) && $button_link['is_external'] ? ' target="_blank"' : '';
						$nofollow = isset( $button_link['nofollow'] ) && $button_link['nofollow'] ? ' rel="nofollow"' : '';
						?>
						<div class="hbl-pricing-plan-card <?php echo esc_attr( $highlight_class ); ?>">
							<?php if ( $is_coming_soon ) : ?>
								<div class="hbl-plan-coming-soon-overlay">
									<div class="hbl-rocket-icon-small">🚀</div>
									<span class="hbl-plan-coming-soon-text"><?php echo esc_html( $coming_soon_text ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $highlight_class ) ) : ?>
								<span class="hbl-pricing-plan-badge"><?php esc_html_e( 'Recommended', 'hbl' ); ?></span>
							<?php endif; ?>
							<div class="hbl-pricing-plan-card-content">
								<span class="hbl-pricing-plan-name"><?php echo esc_html( $plan['plan_name'] ); ?></span>
								<?php if ( ! empty( $plan['plan_subtitle'] ) ) : ?>
									<span class="hbl-pricing-plan-price"><?php echo esc_html( $plan['plan_subtitle'] ); ?></span>
								<?php endif; ?>
							</div>
							<a href="<?php echo esc_url( $button_url ); ?>" class="hbl-pricing-plan-button"<?php echo $target . $nofollow; ?>>
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php echo esc_html( $button_text ); ?>
							</a>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Feature Rows -->
				<div class="hbl-pricing-plan-features">
					<?php foreach ( $features as $feature_index => $feature ) : 
						$plan_values = isset( $feature['plan_values'] ) ? $feature['plan_values'] : array();
						$row_class = $feature_index % 2 === 0 ? 'hbl-row-even' : 'hbl-row-odd';
						?>
						<div class="hbl-pricing-plan-row <?php echo esc_attr( $row_class ); ?>">
							<div class="hbl-pricing-plan-feature-cell hbl-pricing-plan-feature-name-cell">
								<span class="hbl-pricing-plan-feature-name"><?php echo esc_html( $feature['feature_name'] ); ?></span>
							</div>
							<?php foreach ( $plans as $plan_index => $plan ) : 
								$highlight_class = isset( $plan['highlight'] ) && 'yes' === $plan['highlight'] ? 'hbl-cell-highlighted' : '';
								if ( isset( $plan['coming_soon'] ) && 'yes' === $plan['coming_soon'] ) {
									$highlight_class .= ' hbl-cell-coming-soon';
								}
								$plan_value_item = isset( $plan_values[ $plan_index ] ) ? $plan_values[ $plan_index ] : array();
								$feature_value = isset( $plan_value_item['plan_value'] ) ? $plan_value_item['plan_value'] : '';
								$icon_type = isset( $plan_value_item['icon_type'] ) ? $plan_value_item['icon_type'] : 'tick';
								$custom_icon = isset( $plan_value_item['custom_icon'] ) ? $plan_value_item['custom_icon'] : array();
								$custom_image = isset( $plan_value_item['custom_image'] ) ? $plan_value_item['custom_image'] : array();
								?>
								<div class="hbl-pricing-plan-feature-cell hbl-pricing-plan-value-cell <?php echo esc_attr( $highlight_class ); ?>">
									<?php 
									$has_text = ! empty( trim( $feature_value ) );
									$has_icon = 'none' !== $icon_type;
									
									if ( $has_icon || $has_text ) : ?>
										<div class="hbl-pricing-plan-value-content">
											<?php if ( $has_icon ) : ?>
												<span class="hbl-pricing-plan-icon-wrap <?php echo esc_attr( 'hbl-icon-' . $icon_type ); ?>">
													<?php echo $this->render_icon( $icon_type, $custom_icon, $custom_image ); ?>
												</span>
											<?php endif; ?>
											<?php if ( $has_text ) : ?>
												<span class="hbl-pricing-plan-feature-value"><?php echo esc_html( $feature_value ); ?></span>
											<?php endif; ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endforeach; ?>
				</div>

			</div>

		</div>

		<?php
	}

	/**
	 * Render icon based on type
	 *
	 * @param string $icon_type The type of icon to render.
	 * @param array  $custom_icon Custom icon data from Elementor Icons control.
	 * @param array  $custom_image Custom image data from Elementor Media control.
	 * @return string The rendered icon HTML.
	 */
	private function render_icon( $icon_type, $custom_icon = array(), $custom_image = array() ) {
		switch ( $icon_type ) {
			case 'tick':
				return '<svg class="hbl-pricing-icon hbl-pricing-icon-tick" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
				
			case 'cross':
				return '<svg class="hbl-pricing-icon hbl-pricing-icon-cross" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
					<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>';
				
			case 'linebar':
				return '<svg class="hbl-pricing-icon hbl-pricing-icon-dash" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<line x1="6" y1="12" x2="18" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>';
			
			case 'custom':
				// Render custom icon from Elementor Icons control
				if ( ! empty( $custom_icon ) && ! empty( $custom_icon['value'] ) ) {
					ob_start();
					\Elementor\Icons_Manager::render_icon( $custom_icon, array( 
						'aria-hidden' => 'true',
						'class'       => 'hbl-pricing-icon hbl-pricing-icon-custom',
					) );
					return ob_get_clean();
				}
				// Fallback to tick if no custom icon set
				return $this->render_icon( 'tick' );
			
			case 'image':
				// Render custom image from Elementor Media control
				if ( ! empty( $custom_image ) && ! empty( $custom_image['url'] ) ) {
					$image_id = ! empty( $custom_image['id'] ) ? $custom_image['id'] : 0;
					$image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
					return sprintf(
						'<img src="%s" alt="%s" class="hbl-pricing-icon hbl-pricing-icon-image" />',
						esc_url( $custom_image['url'] ),
						esc_attr( $image_alt )
					);
				}
				// Fallback to tick if no image set
				return $this->render_icon( 'tick' );
				
			case 'none':
			default:
				return '';
		}
	}
}

