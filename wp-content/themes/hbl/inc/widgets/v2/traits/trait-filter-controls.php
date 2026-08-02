<?php
/**
 * Filter Controls Trait for HBL Directorist V2
 * 
 * Handles Elementor control registration for filters and display options
 *
 * @package HBL
 * @since 2.0.0
 */

namespace HBL\Widgets\V2\Traits;

use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Filter_Controls {

	/**
	 * Register filter-related controls
	 */
	protected function register_filter_controls() {
		// Display Options Section
		$this->start_controls_section(
			'section_v2_display',
			array(
				'label' => esc_html__( 'Display Options', 'hbl' ),
			)
		);

		$this->add_control(
			'show_alphabetical_filter',
			array(
				'label'        => esc_html__( 'Show A-Z Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show alphabetical A-Z filter buttons', 'hbl' ),
			)
		);

		$this->add_control(
			'show_keyword_search',
			array(
				'label'        => esc_html__( 'Show Search Business', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show keyword search input field', 'hbl' ),
			)
		);

		$this->add_control(
			'show_popular_search',
			array(
				'label'        => esc_html__( 'Show Popular Searches', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show popular categories dropdown', 'hbl' ),
			)
		);

		$this->add_control(
			'show_sort_dropdown',
			array(
				'label'        => esc_html__( 'Show Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show sort by dropdown (A-Z, Z-A, Newest)', 'hbl' ),
			)
		);

		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show grid/list view toggle buttons', 'hbl' ),
			)
		);

		$this->add_control(
			'default_view',
			array(
				'label'   => esc_html__( 'Default View', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => array(
					'grid' => esc_html__( 'Grid View', 'hbl' ),
					'list' => esc_html__( 'List View', 'hbl' ),
				),
				'description' => esc_html__( 'Select the default view mode on page load', 'hbl' ),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->add_control(
			'grid_view_icon',
			array(
				'label' => esc_html__( 'Grid View Icon', 'hbl' ),
				'type' => Controls_Manager::MEDIA,
				'description' => esc_html__( 'Upload custom grid view icon (optional)', 'hbl' ),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->add_control(
			'list_view_icon',
			array(
				'label' => esc_html__( 'List View Icon', 'hbl' ),
				'type' => Controls_Manager::MEDIA,
				'description' => esc_html__( 'Upload custom list view icon (optional)', 'hbl' ),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->add_control(
			'fallback_logo',
			array(
				'label' => esc_html__( 'Fallback Logo', 'hbl' ),
				'type' => Controls_Manager::MEDIA,
				'description' => esc_html__( 'Default logo for listings without images', 'hbl' ),
			)
		);

		$this->add_control(
			'show_search_bar',
			array(
				'label'        => esc_html__( 'Show Search Bar', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_category_filter',
			array(
				'label'        => esc_html__( 'Show Category Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_location_filter',
			array(
				'label'        => esc_html__( 'Show Location Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_rating_filter',
			array(
				'label'        => esc_html__( 'Show Rating Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'cards_per_row',
			array(
				'label'   => esc_html__( 'Cards Per Row', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'2' => esc_html__( '2 Columns', 'hbl' ),
					'3' => esc_html__( '3 Columns', 'hbl' ),
					'4' => esc_html__( '4 Columns', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'card_style',
			array(
				'label'   => esc_html__( 'Card Style', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'modern',
				'options' => array(
					'modern'  => esc_html__( 'Modern (Default)', 'hbl' ),
					'minimal' => esc_html__( 'Minimal', 'hbl' ),
					'classic' => esc_html__( 'Classic', 'hbl' ),
				),
			)
		);

		$this->end_controls_section();

		// Style Section
		$this->start_controls_section(
			'section_v2_style',
			array(
				'label' => esc_html__( 'Card Styling', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'card_bg_color',
			array(
				'label'     => esc_html__( 'Card Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-v2-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'card_border_radius',
			array(
				'label'      => esc_html__( 'Border Radius', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 16,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-v2-card' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'card_shadow',
			array(
				'label'        => esc_html__( 'Card Shadow', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'premium_badge_color',
			array(
				'label'     => esc_html__( 'Premium Badge Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFD700',
				'selectors' => array(
					'{{WRAPPER}} .hbl-v2-premium-badge' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}
}
