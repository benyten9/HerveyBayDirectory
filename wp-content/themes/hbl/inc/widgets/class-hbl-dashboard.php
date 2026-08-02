<?php
/**
 * HBL Dashboard Widget
 *
 * A clean, modern dashboard widget for Directorist
 * Provides user dashboard functionality with attractive UI
 *
 * @package HBL
 * @since 1.3.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Dashboard extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-dashboard';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Dashboard', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-dashboard';
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
		return array( 'dashboard', 'user', 'profile', 'listings', 'account', 'directorist' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {

		// ========== CONTENT: GENERAL ==========
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'show_welcome_message',
			array(
				'label'        => esc_html__( 'Show Welcome Message', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'welcome_text',
			array(
				'label'     => esc_html__( 'Welcome Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Welcome back,',
				'condition' => array(
					'show_welcome_message' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_stats',
			array(
				'label'        => esc_html__( 'Show Statistics Cards', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'listings_per_page',
			array(
				'label'   => esc_html__( 'Listings Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: TABS ==========
		$this->start_controls_section(
			'section_tabs',
			array(
				'label' => esc_html__( 'Tabs Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'show_my_listings',
			array(
				'label'        => esc_html__( 'Show My Listings Tab', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'my_listings_label',
			array(
				'label'     => esc_html__( 'My Listings Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'My Listings',
				'condition' => array(
					'show_my_listings' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_profile',
			array(
				'label'        => esc_html__( 'Show Profile Tab', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'profile_label',
			array(
				'label'     => esc_html__( 'Profile Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'My Profile',
				'condition' => array(
					'show_profile' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_favorites',
			array(
				'label'        => esc_html__( 'Show Favorites Tab', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'favorites_label',
			array(
				'label'     => esc_html__( 'Favorites Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Favorites',
				'condition' => array(
					'show_favorites' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_events',
			array(
				'label'        => esc_html__( 'Show Events Tab', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'events_label',
			array(
				'label'     => esc_html__( 'Events Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'My Events',
				'condition' => array(
					'show_events' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_claims',
			array(
				'label'        => esc_html__( 'Show Claims Tab', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'claims_label',
			array(
				'label'     => esc_html__( 'Claims Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'My Claims',
				'condition' => array(
					'show_claims' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: BUTTONS ==========
		$this->start_controls_section(
			'section_buttons',
			array(
				'label' => esc_html__( 'Buttons', 'hbl' ),
			)
		);

		$this->add_control(
			'show_add_listing_btn',
			array(
				'label'        => esc_html__( 'Show Add Listing Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'add_listing_text',
			array(
				'label'     => esc_html__( 'Add Listing Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Add New Listing',
				'condition' => array(
					'show_add_listing_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'add_listing_url',
			array(
				'label'       => esc_html__( 'Add Listing URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'Leave empty for default', 'hbl' ),
				'default'     => array(
					'url' => '',
				),
				'condition'   => array(
					'show_add_listing_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_add_event_btn',
			array(
				'label'        => esc_html__( 'Show Add Event Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'add_event_text',
			array(
				'label'     => esc_html__( 'Add Event Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Add New Event',
				'condition' => array(
					'show_add_event_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'add_event_url',
			array(
				'label'       => esc_html__( 'Add Event URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'Leave empty for default', 'hbl' ),
				'default'     => array(
					'url' => '',
				),
				'condition'   => array(
					'show_add_event_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_claim_listing_btn',
			array(
				'label'        => esc_html__( 'Show Claim Listing Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'claim_listing_text',
			array(
				'label'     => esc_html__( 'Claim Listing Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Claim Listing',
				'condition' => array(
					'show_claim_listing_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'claim_listing_url',
			array(
				'label'       => esc_html__( 'Claim Listing URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://example.com/claim-listing/', 'hbl' ),
				'default'     => array(
					'url' => '/claim-listing/',
				),
				'condition'   => array(
					'show_claim_listing_btn' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_logout_btn',
			array(
				'label'        => esc_html__( 'Show Logout Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'upgrade_url',
			array(
				'label'       => esc_html__( 'Upgrade / Pricing Plans URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'Leave empty for default', 'hbl' ),
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'Shown in the sidebar when the user has no active listing package. Leave empty to use /list-your-business/.', 'hbl' ),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: GENERAL ==========
		$this->start_controls_section(
			'section_style_general',
			array(
				'label' => esc_html__( 'General', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'     => esc_html__( 'Primary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-tab.active' => 'border-color: {{VALUE}}; color: {{VALUE}};',
					'{{WRAPPER}} .hbl-dashboard-stat-icon' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-dashboard-btn-primary' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-dashboard-listing-status.publish' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'secondary_color',
			array(
				'label'     => esc_html__( 'Secondary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-secondary' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-dashboard-btn-primary:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-widget' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'background_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-widget' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HEADER ==========
		$this->start_controls_section(
			'section_style_header',
			array(
				'label' => esc_html__( 'Header', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'welcome_typography',
				'label'    => esc_html__( 'Welcome Text Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-welcome-text',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'username_typography',
				'label'    => esc_html__( 'Username Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-username',
			)
		);

		$this->add_control(
			'avatar_size',
			array(
				'label'     => esc_html__( 'Avatar Size', 'hbl' ),
				'type'      => Controls_Manager::SLIDER,
				'range'     => array(
					'px' => array(
						'min' => 40,
						'max' => 150,
					),
				),
				'default'   => array(
					'size' => 80,
					'unit' => 'px',
				),
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-avatar' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: STATS ==========
		$this->start_controls_section(
			'section_style_stats',
			array(
				'label' => esc_html__( 'Statistics Cards', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'stat_card_bg',
			array(
				'label'     => esc_html__( 'Card Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F8F9FA',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-stat-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'stat_number_typography',
				'label'    => esc_html__( 'Number Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-stat-number',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'stat_label_typography',
				'label'    => esc_html__( 'Label Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-stat-label',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TABS ==========
		$this->start_controls_section(
			'section_style_tabs',
			array(
				'label' => esc_html__( 'Tabs', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'tab_bg_color',
			array(
				'label'     => esc_html__( 'Tab Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F8F9FA',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-tabs' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'tab_active_bg',
			array(
				'label'     => esc_html__( 'Active Tab Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-tab.active' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'tab_typography',
				'label'    => esc_html__( 'Tab Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-tab',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: LISTINGS ==========
		$this->start_controls_section(
			'section_style_listings',
			array(
				'label' => esc_html__( 'Listings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'listing_card_bg',
			array(
				'label'     => esc_html__( 'Card Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-listing-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'listing_card_border',
			array(
				'label'     => esc_html__( 'Card Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E9ECEF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-listing-card' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'listing_title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-listing-title',
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
				'name'     => 'btn_typography',
				'label'    => esc_html__( 'Button Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-dashboard-btn',
			)
		);

		// Primary Button (Add Listing)
		$this->add_control(
			'heading_btn_primary',
			array(
				'label'     => esc_html__( 'Add Listing Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_primary_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-primary' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_primary_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-primary:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_primary_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-primary' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_primary_hover_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-primary:hover' => 'color: {{VALUE}} !important;',
				),
			)
		);

		// Secondary Button (Add Event)
		$this->add_control(
			'heading_btn_secondary',
			array(
				'label'     => esc_html__( 'Add Event Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_secondary_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-secondary' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_secondary_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#006666',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-secondary:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_secondary_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-secondary' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_secondary_hover_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-secondary:hover' => 'color: {{VALUE}} !important;',
				),
			)
		);

		// Outline Button (Logout)
		$this->add_control(
			'heading_btn_outline',
			array(
				'label'     => esc_html__( 'Logout Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_outline_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'transparent',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-outline' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_outline_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(255,255,255,0.1)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-outline:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_outline_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-outline' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_outline_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(255,255,255,0.4)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-outline' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_outline_hover_border_color',
			array(
				'label'     => esc_html__( 'Hover Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-btn-outline:hover' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		// Action Buttons (View, Edit, Delete)
		$this->add_control(
			'heading_btn_action',
			array(
				'label'     => esc_html__( 'Action Buttons (View/Edit/Delete)', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_action_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F8F9FA',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-action-btn' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_action_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-action-btn:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_action_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6C757D',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-action-btn' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_action_hover_color',
			array(
				'label'     => esc_html__( 'Hover Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-action-btn:hover' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_action_delete_hover_bg',
			array(
				'label'     => esc_html__( 'Delete Button Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#DC3545',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-action-btn-danger:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		// Login Required Buttons
		$this->add_control(
			'heading_btn_login',
			array(
				'label'     => esc_html__( 'Login/Register Buttons', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_login_bg',
			array(
				'label'     => esc_html__( 'Login Button Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-login-buttons .hbl-dashboard-btn-primary' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_login_hover_bg',
			array(
				'label'     => esc_html__( 'Login Button Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-login-buttons .hbl-dashboard-btn-primary:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_register_border_color',
			array(
				'label'     => esc_html__( 'Register Button Border', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#DEE2E6',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-login-buttons .hbl-dashboard-btn-outline' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		// Profile Image Upload Button
		$this->add_control(
			'heading_btn_profile_upload',
			array(
				'label'     => esc_html__( 'Profile Photo Upload Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_profile_upload_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_upload_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#006666',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_upload_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_upload_hover_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image:hover' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_upload_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_upload_hover_border_color',
			array(
				'label'     => esc_html__( 'Hover Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#006666',
				'selectors' => array(
					'{{WRAPPER}} #hbl-upload-profile-image:hover' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		// Profile Image Save Button
		$this->add_control(
			'heading_btn_profile_save',
			array(
				'label'     => esc_html__( 'Profile Photo Save Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'btn_profile_save_bg',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} #hbl-save-profile-image' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_save_hover_bg',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#e04420',
				'selectors' => array(
					'{{WRAPPER}} #hbl-save-profile-image:hover' => 'background-color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_save_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} #hbl-save-profile-image' => 'color: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'btn_profile_save_border_color',
			array(
				'label'     => esc_html__( 'Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} #hbl-save-profile-image' => 'border-color: {{VALUE}} !important;',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HEADER COLORS ==========
		$this->start_controls_section(
			'section_style_header_colors',
			array(
				'label' => esc_html__( 'Header Colors', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'header_bg_color',
			array(
				'label'     => esc_html__( 'Header Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-header' => 'background: linear-gradient(135deg, {{VALUE}} 0%, {{VALUE}} 100%) !important;',
				),
			)
		);

		$this->add_control(
			'header_text_color',
			array(
				'label'     => esc_html__( 'Header Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-dashboard-header' => 'color: {{VALUE}} !important;',
					'{{WRAPPER}} .hbl-dashboard-welcome-text' => 'color: rgba(255,255,255,0.8) !important;',
					'{{WRAPPER}} .hbl-dashboard-username' => 'color: {{VALUE}} !important;',
					'{{WRAPPER}} .hbl-dashboard-email' => 'color: rgba(255,255,255,0.7) !important;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Shared icon set for sidebar nav, stat cards, quick access, etc.
	 */
	private function icon( $name ) {
		$icons = array(
			'dashboard' => '<path d="M19 3H5C3.89543 3 3 3.89543 3 5V19C3 20.1046 3.89543 21 5 21H19C20.1046 21 21 20.1046 21 19V5C21 3.89543 20.1046 3 19 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 9H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 21V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'listings'  => '<path d="M8 6H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 12H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 6H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 12H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 18H3.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'plus'      => '<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'event'     => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'profile'   => '<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'favorites' => '<path d="M20.84 4.61C20.3292 4.09924 19.7228 3.69397 19.0554 3.41708C18.3879 3.14019 17.6725 2.99756 16.95 2.99756C16.2275 2.99756 15.5121 3.14019 14.8446 3.41708C14.1772 3.69397 13.5708 4.09924 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99806 7.05 2.99806C5.59096 2.99806 4.19169 3.57831 3.16 4.61C2.1283 5.6417 1.54806 7.04097 1.54806 8.5C1.54806 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.3508 11.8792 21.756 11.2728 22.0329 10.6054C22.3098 9.93789 22.4524 9.2225 22.4524 8.5C22.4524 7.7775 22.3098 7.06211 22.0329 6.39464C21.756 5.72718 21.3508 5.12075 20.84 4.61Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'claims'    => '<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'logout'    => '<path d="M9 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H9M16 17L21 12M21 12L16 7M21 12H9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'arrow'     => '<path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'check'     => '<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'clock'     => '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'warning'   => '<path d="M10.29 3.86L1.82 18C1.64 18.3 1.55 18.64 1.55 19C1.55 19.36 1.64 19.7 1.82 20C2 20.3 2.26 20.56 2.56 20.74C2.86 20.92 3.2 21.01 3.55 21.01H20.49C20.84 21.01 21.18 20.92 21.48 20.74C21.78 20.56 22.04 20.3 22.22 20C22.4 19.7 22.49 19.36 22.49 19C22.49 18.64 22.4 18.3 22.22 18L13.75 3.86C13.57 3.56 13.31 3.32 13.01 3.14C12.71 2.96 12.37 2.87 12.02 2.87C11.67 2.87 11.33 2.96 11.03 3.14C10.73 3.32 10.47 3.56 10.29 3.86Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 9V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 17H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
		);

		if ( empty( $icons[ $name ] ) ) {
			return '';
		}

		return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' . $icons[ $name ] . '</svg>';
	}

	/**
	 * Get the user's current listing package usage from Directorist Pricing
	 * Plans, if that add-on is active and the user has an active package
	 * with a listing quota. Returns null when there's nothing meaningful to
	 * show (no pricing plans add-on, no active package, or a plan without a
	 * listing cap to report).
	 */
	private function get_listing_quota( $user_id ) {
		if ( ! function_exists( 'directorist_get_current_package' )
			|| ! function_exists( 'directorist_get_pricing_plan_by_id' )
			|| ! function_exists( 'directorist_package_usage' )
			|| ! function_exists( 'directorist_plan_has_listing_quota' )
			|| ! function_exists( 'default_directory_type' )
		) {
			return null;
		}

		$directory_type_id = (int) default_directory_type();
		$package = directorist_get_current_package( $directory_type_id, $user_id );

		if ( ! $package || empty( $package->plan_id ) ) {
			return null;
		}

		if ( function_exists( 'directorist_is_package_active' ) && ! directorist_is_package_active( $package ) ) {
			return null;
		}

		$plan = directorist_get_pricing_plan_by_id( (int) $package->plan_id );

		if ( ! $plan || ! directorist_plan_has_listing_quota( $plan ) ) {
			return null;
		}

		$uses = directorist_package_usage()->get_regular_uses( $user_id, $plan );

		return array(
			'allowed' => isset( $uses['allowed'] ) ? (int) $uses['allowed'] : -1,
			'used'    => isset( $uses['used'] ) ? (int) $uses['used'] : 0,
		);
	}

	/**
	 * Get the user's most recently added/updated listings and events,
	 * merged and sorted by recency, for the Overview "Recent Activity" feed.
	 */
	private function get_recent_activity( $user_id, $limit = 6 ) {
		$items = array();

		if ( defined( 'ATBDP_POST_TYPE' ) ) {
			$listings = get_posts( array(
				'author'         => $user_id,
				'post_type'      => ATBDP_POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'expired', 'private' ),
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			) );

			foreach ( $listings as $listing ) {
				$items[] = array(
					'type'   => 'listing',
					'title'  => $listing->post_title,
					'url'    => get_permalink( $listing ),
					'time'   => strtotime( $listing->post_modified ),
					'action' => $listing->post_date === $listing->post_modified ? __( 'Listing added', 'hbl' ) : __( 'Listing updated', 'hbl' ),
				);
			}
		}

		if ( function_exists( 'hbl_events_db' ) ) {
			global $wpdb;
			$table  = hbl_events_db()->get_table_name();
			$events = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d",
				$user_id,
				$limit
			) );

			foreach ( $events as $event ) {
				$items[] = array(
					'type'   => 'event',
					'title'  => $event->title,
					'url'    => hbl_events_db()->get_event_url( $event ),
					'time'   => strtotime( $event->updated_at ),
					'action' => $event->created_at === $event->updated_at ? __( 'Event created', 'hbl' ) : __( 'Event updated', 'hbl' ),
				);
			}
		}

		usort( $items, function ( $a, $b ) {
			return $b['time'] <=> $a['time'];
		} );

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Render widget output
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Enqueue WordPress media uploader for profile image upload
		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			$this->render_login_required();
			return;
		}

		// Check if Directorist is active
		if ( ! defined( 'ATBDP_VERSION' ) ) {
			echo '<div class="hbl-dashboard-widget"><div class="hbl-dashboard-notice">Directorist plugin is required for this widget.</div></div>';
			return;
		}

		$current_user = wp_get_current_user();
		$user_id      = $current_user->ID;

		// Get user stats
		$stats = $this->get_user_stats( $user_id );

		// Get custom profile image
		$profile_image_id   = get_user_meta( $user_id, 'hbl_profile_image', true );
		$profile_avatar_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';

		$nav_items = array(
			'overview'  => array(
				'label'   => esc_html__( 'Overview', 'hbl' ),
				'icon'    => 'dashboard',
				'enabled' => true,
				'count'   => null,
			),
			'listings'  => array(
				'label'   => $settings['my_listings_label'],
				'icon'    => 'listings',
				'enabled' => 'yes' === $settings['show_my_listings'],
				'count'   => $stats['total'],
			),
			'events'    => array(
				'label'   => $settings['events_label'],
				'icon'    => 'event',
				'enabled' => 'yes' === $settings['show_events'],
				'count'   => $stats['events'],
			),
			'profile'   => array(
				'label'   => $settings['profile_label'],
				'icon'    => 'profile',
				'enabled' => 'yes' === $settings['show_profile'],
				'count'   => null,
			),
			'favorites' => array(
				'label'   => $settings['favorites_label'],
				'icon'    => 'favorites',
				'enabled' => 'yes' === $settings['show_favorites'],
				'count'   => $stats['favorites'],
			),
			'claims'    => array(
				'label'   => $settings['claims_label'],
				'icon'    => 'claims',
				'enabled' => 'yes' === $settings['show_claims'],
				'count'   => $stats['claims'] ?? 0,
			),
		);

		// Determine which "page" is active based on the URL - each sidebar
		// link is a real, bookmarkable link (?view=...), not a JS tab.
		$requested_view = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'overview';
		$view           = ( isset( $nav_items[ $requested_view ] ) && $nav_items[ $requested_view ]['enabled'] ) ? $requested_view : 'overview';

		if ( isset( $_GET['add_event'] ) || isset( $_GET['edit_event'] ) ) {
			$view = 'events';
		}

		$base_url   = get_permalink();
		$logout_url = wp_logout_url( home_url() );
		$quota      = $this->get_listing_quota( $user_id );

		// Build the top-bar action buttons once, so the same markup can be
		// printed in the desktop top bar and (below 1024px) inside the mobile
		// drawer under the nav, without maintaining two copies.
		ob_start();
		if ( 'yes' === $settings['show_claim_listing_btn'] ) :
			$claim_listing_url = ! empty( $settings['claim_listing_url']['url'] )
				? $settings['claim_listing_url']['url']
				: home_url( '/claim-listing/' );
			?>
			<a href="<?php echo esc_url( $claim_listing_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-outline-solid" <?php echo ! empty( $settings['claim_listing_url']['is_external'] ) ? 'target="_blank"' : ''; ?>>
				<?php echo $this->icon( 'claims' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo esc_html( $settings['claim_listing_text'] ); ?>
			</a>
		<?php endif; ?>
		<?php if ( 'yes' === $settings['show_add_event_btn'] ) :
			$add_event_url = ! empty( $settings['add_event_url']['url'] )
				? $settings['add_event_url']['url']
				: home_url( '/add-event/' );
			?>
			<a href="<?php echo esc_url( $add_event_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-secondary" <?php echo ! empty( $settings['add_event_url']['is_external'] ) ? 'target="_blank"' : ''; ?>>
				<?php echo $this->icon( 'event' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo esc_html( $settings['add_event_text'] ); ?>
			</a>
		<?php endif; ?>
		<?php if ( 'yes' === $settings['show_add_listing_btn'] ) :
			$add_listing_url = ! empty( $settings['add_listing_url']['url'] )
				? $settings['add_listing_url']['url']
				: ( class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_add_listing_page_link() : '#' );
			?>
			<a href="<?php echo esc_url( $add_listing_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary" <?php echo ! empty( $settings['add_listing_url']['is_external'] ) ? 'target="_blank"' : ''; ?>>
				<?php echo $this->icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo esc_html( $settings['add_listing_text'] ); ?>
			</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hbl-dash-back-link">
			<?php echo $this->icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php esc_html_e( 'Back to site', 'hbl' ); ?></span>
		</a>
		<?php
		$actions_html = ob_get_clean();
		?>
		<div class="hbl-dashboard-widget hbl-dash" data-user-id="<?php echo esc_attr( $user_id ); ?>">

			<aside class="hbl-dash-sidebar">
				<div class="hbl-dash-brand">
					<div class="hbl-dash-brand-logo">
						<?php if ( has_custom_logo() ) : ?>
							<?php the_custom_logo(); ?>
						<?php else : ?>
							<span class="hbl-dash-brand-name"><?php bloginfo( 'name' ); ?></span>
						<?php endif; ?>
					</div>
					<button type="button" class="hbl-dash-menu-toggle" aria-label="<?php esc_attr_e( 'Toggle menu', 'hbl' ); ?>" aria-expanded="false" aria-controls="hbl-dash-sidebar-body">
						<svg class="hbl-dash-menu-open-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<svg class="hbl-dash-menu-close-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
				</div>

				<div class="hbl-dash-sidebar-body" id="hbl-dash-sidebar-body">
					<div class="hbl-dash-user">
						<div class="hbl-dash-user-avatar">
							<?php if ( $profile_avatar_url ) : ?>
								<img src="<?php echo esc_url( $profile_avatar_url ); ?>" alt="<?php echo esc_attr( $current_user->display_name ); ?>">
							<?php else : ?>
								<?php echo get_avatar( $user_id, 44 ); ?>
							<?php endif; ?>
						</div>
						<div class="hbl-dash-user-info">
							<span class="hbl-dash-user-name"><?php echo esc_html( $current_user->display_name ); ?></span>
							<span class="hbl-dash-user-email"><?php echo esc_html( $current_user->user_email ); ?></span>
						</div>
					</div>

					<nav class="hbl-dash-nav">
						<?php foreach ( $nav_items as $key => $item ) : ?>
							<?php if ( ! $item['enabled'] ) { continue; } ?>
							<a href="<?php echo esc_url( 'overview' === $key ? $base_url : add_query_arg( 'view', $key, $base_url ) ); ?>" class="hbl-dash-nav-link <?php echo $view === $key ? 'active' : ''; ?>" data-view="<?php echo esc_attr( $key ); ?>">
								<?php echo $this->icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><?php echo esc_html( $item['label'] ); ?></span>
								<?php if ( null !== $item['count'] ) : ?>
									<span class="hbl-dash-nav-count"><?php echo esc_html( $item['count'] ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<div class="hbl-dash-drawer-actions">
						<?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>

					<div class="hbl-dash-sidebar-footer">
						<?php if ( null === $quota ) :
							$upgrade_url = ! empty( $settings['upgrade_url']['url'] ) ? $settings['upgrade_url']['url'] : home_url( '/list-your-business/' );
						?>
						<div class="hbl-dash-upgrade-card">
							<strong><?php esc_html_e( 'Get more from your listing', 'hbl' ); ?></strong>
							<p><?php esc_html_e( 'Upgrade for more listings, featured placement and extra visibility.', 'hbl' ); ?></p>
							<a href="<?php echo esc_url( $upgrade_url ); ?>" class="hbl-dash-upgrade-btn"><?php esc_html_e( 'View Plans', 'hbl' ); ?></a>
						</div>
						<?php endif; ?>

						<?php if ( 'yes' === $settings['show_logout_btn'] ) : ?>
						<a href="<?php echo esc_url( $logout_url ); ?>" class="hbl-dash-logout">
							<?php echo $this->icon( 'logout' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span><?php esc_html_e( 'Logout', 'hbl' ); ?></span>
						</a>
						<?php endif; ?>
					</div>
				</div>
			</aside>

			<div class="hbl-dash-overlay" data-hbl-dash-close></div>

			<div class="hbl-dash-main">
				<header class="hbl-dash-topbar">
					<h1 class="hbl-dash-topbar-title"><?php echo esc_html( $nav_items[ $view ]['label'] ); ?></h1>
					<div class="hbl-dash-topbar-actions">
						<?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</header>

				<div class="hbl-dash-content">
					<?php
					switch ( $view ) {
						case 'listings':
							$this->render_listings_tab( $user_id, $settings );
							break;
						case 'events':
							$this->render_events_tab( $user_id, $settings );
							break;
						case 'profile':
							$this->render_profile_tab( $user_id );
							break;
						case 'favorites':
							$this->render_favorites_tab( $user_id );
							break;
						case 'claims':
							$this->render_claims_tab( $user_id );
							break;
						default:
							$this->render_overview( $user_id, $settings, $stats, $current_user, $base_url, $quota );
							break;
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Overview "page": welcome header, listing quota / upgrade
	 * nudge, stat cards, recent activity feed, and a quick-access grid of
	 * dashboard actions.
	 */
	private function render_overview( $user_id, $settings, $stats, $current_user, $base_url, $quota ) {
		$activity = $this->get_recent_activity( $user_id, 6 );
		?>
		<?php if ( 'yes' === $settings['show_welcome_message'] ) : ?>
		<div class="hbl-dash-welcome">
			<h2><?php echo esc_html( $settings['welcome_text'] ); ?> <?php echo esc_html( $current_user->display_name ); ?> 👋</h2>
			<p><?php esc_html_e( "Here's what's happening with your listings.", 'hbl' ); ?></p>
		</div>
		<?php endif; ?>

		<?php if ( null !== $quota ) :
			$is_unlimited = -1 === $quota['allowed'];
			$percent      = $is_unlimited ? 100 : ( $quota['allowed'] > 0 ? min( 100, round( ( $quota['used'] / $quota['allowed'] ) * 100 ) ) : 100 );
		?>
		<div class="hbl-dash-quota-bar">
			<div class="hbl-dash-quota-bar-label">
				<?php if ( $is_unlimited ) : ?>
					<span><?php esc_html_e( 'Unlimited listings on your current plan', 'hbl' ); ?></span>
				<?php else : ?>
					<span><?php printf( esc_html__( '%1$d of %2$d listings used', 'hbl' ), (int) $quota['used'], (int) $quota['allowed'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! $is_unlimited ) : ?>
			<div class="hbl-dash-quota-track">
				<div class="hbl-dash-quota-fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
			</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php if ( 'yes' === $settings['show_stats'] ) : ?>
		<div class="hbl-dash-stats">
			<?php
			$stat_cards = array(
				array( 'icon' => 'listings', 'value' => $stats['total'], 'label' => __( 'Total Listings', 'hbl' ), 'variant' => 'total' ),
				array( 'icon' => 'check', 'value' => $stats['published'], 'label' => __( 'Published', 'hbl' ), 'variant' => 'published' ),
				array( 'icon' => 'clock', 'value' => $stats['pending'], 'label' => __( 'Pending', 'hbl' ), 'variant' => 'pending' ),
				array( 'icon' => 'warning', 'value' => $stats['expired'], 'label' => __( 'Expired', 'hbl' ), 'variant' => 'expired' ),
				array( 'icon' => 'favorites', 'value' => $stats['favorites'], 'label' => __( 'Favorites', 'hbl' ), 'variant' => 'favorites' ),
				array( 'icon' => 'event', 'value' => $stats['events'], 'label' => __( 'Events', 'hbl' ), 'variant' => 'events' ),
			);
			foreach ( $stat_cards as $card ) :
			?>
			<div class="hbl-dash-stat-card hbl-dash-stat-card-<?php echo esc_attr( $card['variant'] ); ?>">
				<span class="hbl-dash-stat-icon"><?php echo $this->icon( $card['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<span class="hbl-dash-stat-value"><?php echo esc_html( $card['value'] ); ?></span>
				<span class="hbl-dash-stat-label"><?php echo esc_html( $card['label'] ); ?></span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="hbl-dash-panel">
			<h3 class="hbl-dash-panel-title"><?php esc_html_e( 'Recent Activity', 'hbl' ); ?></h3>
			<?php if ( empty( $activity ) ) : ?>
			<div class="hbl-dash-empty-activity">
				<h4><?php esc_html_e( 'No activity yet', 'hbl' ); ?></h4>
				<p><?php esc_html_e( 'Add a listing or event to see your recent activity here.', 'hbl' ); ?></p>
			</div>
			<?php else : ?>
			<ul class="hbl-dash-activity-list">
				<?php foreach ( $activity as $item ) : ?>
				<li class="hbl-dash-activity-item">
					<span class="hbl-dash-activity-icon hbl-dash-activity-icon-<?php echo esc_attr( $item['type'] ); ?>">
						<?php echo $this->icon( 'listing' === $item['type'] ? 'listings' : 'event' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</span>
					<div class="hbl-dash-activity-body">
						<a href="<?php echo esc_url( $item['url'] ); ?>" class="hbl-dash-activity-title"><?php echo esc_html( $item['title'] ); ?></a>
						<span class="hbl-dash-activity-meta"><?php echo esc_html( $item['action'] ); ?> &middot; <?php echo esc_html( human_time_diff( $item['time'], current_time( 'timestamp' ) ) ); ?> <?php esc_html_e( 'ago', 'hbl' ); ?></span>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>

		<div class="hbl-dash-panel">
			<h3 class="hbl-dash-panel-title"><?php esc_html_e( 'Quick Access', 'hbl' ); ?></h3>
			<div class="hbl-dash-quick-grid">
				<?php
				$quick_links = array();

				if ( 'yes' === $settings['show_add_listing_btn'] ) {
					$quick_links[] = array(
						'label' => $settings['add_listing_text'],
						'url'   => ! empty( $settings['add_listing_url']['url'] ) ? $settings['add_listing_url']['url'] : ( class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_add_listing_page_link() : '#' ),
					);
				}
				if ( 'yes' === $settings['show_add_event_btn'] ) {
					$quick_links[] = array(
						'label' => $settings['add_event_text'],
						'url'   => ! empty( $settings['add_event_url']['url'] ) ? $settings['add_event_url']['url'] : home_url( '/add-event/' ),
					);
				}
				if ( 'yes' === $settings['show_claim_listing_btn'] ) {
					$quick_links[] = array(
						'label' => $settings['claim_listing_text'],
						'url'   => ! empty( $settings['claim_listing_url']['url'] ) ? $settings['claim_listing_url']['url'] : home_url( '/claim-listing/' ),
					);
				}
				if ( 'yes' === $settings['show_my_listings'] ) {
					$quick_links[] = array( 'label' => $settings['my_listings_label'], 'url' => add_query_arg( 'view', 'listings', $base_url ) );
				}
				if ( 'yes' === $settings['show_events'] ) {
					$quick_links[] = array( 'label' => $settings['events_label'], 'url' => add_query_arg( 'view', 'events', $base_url ) );
				}
				if ( 'yes' === $settings['show_profile'] ) {
					$quick_links[] = array( 'label' => $settings['profile_label'], 'url' => add_query_arg( 'view', 'profile', $base_url ) );
				}
				if ( 'yes' === $settings['show_favorites'] ) {
					$quick_links[] = array( 'label' => $settings['favorites_label'], 'url' => add_query_arg( 'view', 'favorites', $base_url ) );
				}
				if ( 'yes' === $settings['show_claims'] ) {
					$quick_links[] = array( 'label' => $settings['claims_label'], 'url' => add_query_arg( 'view', 'claims', $base_url ) );
				}

				foreach ( $quick_links as $link ) :
				?>
				<a href="<?php echo esc_url( $link['url'] ); ?>" class="hbl-dash-quick-link">
					<span><?php echo esc_html( $link['label'] ); ?></span>
					<?php echo $this->icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render login required message
	 */
	private function render_login_required() {
		$login_url = function_exists( 'ATBDP_Permalink' ) && class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_login_page_link() : wp_login_url();
		$register_url = function_exists( 'ATBDP_Permalink' ) && class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
		?>
		<div class="hbl-dashboard-widget">
			<div class="hbl-dashboard-login-required">
			<div class="hbl-dashboard-login-icon">
				<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<h3 class="hbl-dashboard-login-title"><?php esc_html_e( 'Login Required', 'hbl' ); ?></h3>
			<p class="hbl-dashboard-login-text"><?php esc_html_e( 'Please login to access your dashboard.', 'hbl' ); ?></p>
			<div class="hbl-dashboard-login-buttons">
				<a href="<?php echo esc_url( $login_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary"><?php esc_html_e( 'Login', 'hbl' ); ?></a>
				<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-outline"><?php esc_html_e( 'Register', 'hbl' ); ?></a>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get user statistics
	 */
	private function get_user_stats( $user_id ) {
		$stats = array(
			'total'     => 0,
			'published' => 0,
			'pending'   => 0,
			'expired'   => 0,
			'favorites' => 0,
			'events'    => 0,
			'claims'    => 0,
		);

		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return $stats;
		}

		// Total listings
		$total_query = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'expired', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$stats['total'] = $total_query->found_posts;

		// Published
		$published_query = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$stats['published'] = $published_query->found_posts;

		// Pending
		$pending_query = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => 'pending',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$stats['pending'] = $pending_query->found_posts;

		// Expired
		$expired_query = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => 'expired',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$stats['expired'] = $expired_query->found_posts;

		// Favorites (listings + events) - count only those that actually exist and have valid status
		$listing_favorites = get_user_meta( $user_id, 'atbdp_favourites', true );
		$listing_favorite_ids = is_array( $listing_favorites ) ? $listing_favorites : array();
		$listing_count = 0;
		
		if ( ! empty( $listing_favorite_ids ) && defined( 'ATBDP_POST_TYPE' ) ) {
			$favorites_count_query = new \WP_Query( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post__in'       => $listing_favorite_ids,
				'post_status'    => array( 'publish', 'pending', 'expired', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
			$listing_count = $favorites_count_query->found_posts;
		}
		
		$event_favorites = get_user_meta( $user_id, 'hbl_favorite_events', true );
		$event_favorite_ids = is_array( $event_favorites ) ? $event_favorites : array();
		$event_count = 0;
		
		if ( ! empty( $event_favorite_ids ) && function_exists( 'hbl_events_db' ) ) {
			foreach ( $event_favorite_ids as $event_id ) {
				$event = hbl_events_db()->get( $event_id );
				if ( $event && in_array( $event->status, array( 'publish', 'pending', 'private', 'expired' ), true ) ) {
					$event_count++;
				}
			}
		}
		
		$stats['favorites'] = $listing_count + $event_count;

		// Events from custom hbl_events database
		if ( function_exists( 'hbl_events_db' ) ) {
			global $wpdb;
			$table = hbl_events_db()->get_table_name();
			$stats['events'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d",
				$user_id
			) );
		}

		// Claims (dcl_claim_listing post type where user is the claimer)
		if ( post_type_exists( 'dcl_claim_listing' ) ) {
			$claims_query = new \WP_Query( array(
				'post_type'      => 'dcl_claim_listing',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_listing_claimer',
						'value'   => $user_id,
						'compare' => '=',
					),
				),
			) );
			$stats['claims'] = $claims_query->found_posts;
		}

		return $stats;
	}

	/**
	 * Render listings tab
	 */
	private function render_listings_tab( $user_id, $settings ) {
		if ( ! defined( 'ATBDP_POST_TYPE' ) ) {
			return;
		}

		$per_page = isset( $settings['listings_per_page'] ) ? absint( $settings['listings_per_page'] ) : 10;
		$paged = isset( $_GET['dashboard_page'] ) ? absint( $_GET['dashboard_page'] ) : 1;

		$listings = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => ATBDP_POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'expired', 'private' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		?>
		<div class="hbl-dashboard-listings-header">
			<div class="hbl-dashboard-listings-search">
				<input type="text" class="hbl-dashboard-search-input" placeholder="<?php esc_attr_e( 'Search listings...', 'hbl' ); ?>">
				<svg class="hbl-dashboard-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
			</div>
			<div class="hbl-dashboard-listings-filter">
				<select class="hbl-dashboard-filter-select">
					<option value="all"><?php esc_html_e( 'All Status', 'hbl' ); ?></option>
					<option value="publish"><?php esc_html_e( 'Published', 'hbl' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'hbl' ); ?></option>
					<option value="expired"><?php esc_html_e( 'Expired', 'hbl' ); ?></option>
				</select>
			</div>
		</div>

		<?php if ( $listings->have_posts() ) : ?>
		<div class="hbl-dashboard-listings-grid">
			<?php while ( $listings->have_posts() ) : $listings->the_post(); ?>
				<?php $this->render_listing_card( get_the_ID() ); ?>
			<?php endwhile; ?>
		</div>

		<?php if ( $listings->max_num_pages > 1 ) : ?>
		<div class="hbl-dashboard-pagination">
			<?php
			echo paginate_links( array(
				'base'      => add_query_arg( 'dashboard_page', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $listings->max_num_pages,
				'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'hbl' ),
				'next_text' => esc_html__( 'Next', 'hbl' ) . ' &raquo;',
			) );
			?>
		</div>
		<?php endif; ?>

		<?php else : ?>
		<div class="hbl-dashboard-empty">
			<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M14 2V8H20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M12 18V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M9 15H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
			<h4><?php esc_html_e( 'No Listings Yet', 'hbl' ); ?></h4>
			<p><?php esc_html_e( 'You haven\'t created any listings yet. Create your first listing now!', 'hbl' ); ?></p>
			<?php if ( class_exists( 'ATBDP_Permalink' ) ) : ?>
			<a href="<?php echo esc_url( \ATBDP_Permalink::get_add_listing_page_link() ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
				<?php esc_html_e( 'Add New Listing', 'hbl' ); ?>
			</a>
			<?php endif; ?>
		</div>
		<?php endif;
		wp_reset_postdata();
	}

	/**
	 * Render single listing card
	 */
	private function render_listing_card( $listing_id ) {
		$thumbnail  = get_the_post_thumbnail_url( $listing_id, 'medium' );
		$status     = get_post_status( $listing_id );
		$edit_url   = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_edit_listing_page_link( $listing_id ) : get_edit_post_link( $listing_id );
		$view_url   = get_permalink( $listing_id );
		$categories = defined( 'ATBDP_CATEGORY' ) ? get_the_terms( $listing_id, ATBDP_CATEGORY ) : array();
		$category   = $categories && ! is_wp_error( $categories ) ? $categories[0]->name : '';

		$status_labels = array(
			'publish' => esc_html__( 'Published', 'hbl' ),
			'pending' => esc_html__( 'Pending', 'hbl' ),
			'expired' => esc_html__( 'Expired', 'hbl' ),
			'private' => esc_html__( 'Private', 'hbl' ),
			'draft'   => esc_html__( 'Draft', 'hbl' ),
		);
		$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : ucfirst( $status );
		?>
		<div class="hbl-dashboard-listing-card" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="hbl-dashboard-listing-image">
				<?php if ( $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php the_title_attribute(); ?>">
				<?php else : ?>
					<div class="hbl-dashboard-listing-no-image">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
				<?php endif; ?>
				<span class="hbl-dashboard-listing-status <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>
			<div class="hbl-dashboard-listing-content">
				<h4 class="hbl-dashboard-listing-title">
					<a href="<?php echo esc_url( $view_url ); ?>"><?php the_title(); ?></a>
				</h4>
				<?php if ( $category ) : ?>
				<span class="hbl-dashboard-listing-category"><?php echo esc_html( $category ); ?></span>
				<?php endif; ?>
				<div class="hbl-dashboard-listing-meta">
					<span class="hbl-dashboard-listing-date">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php echo get_the_date(); ?>
					</span>
				</div>
			</div>
			<div class="hbl-dashboard-listing-actions">
				<a href="<?php echo esc_url( $view_url ); ?>" class="hbl-dashboard-action-btn hbl-dashboard-action-view" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="hbl-dashboard-action-btn hbl-dashboard-action-edit" title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M11 4H4C3.46957 4 2.96086 4.21071 2.58579 4.58579C2.21071 4.96086 2 5.46957 2 6V20C2 20.5304 2.21071 21.0391 2.58579 21.4142C2.96086 21.7893 3.46957 22 4 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M18.5 2.50001C18.8978 2.10219 19.4374 1.87869 20 1.87869C20.5626 1.87869 21.1022 2.10219 21.5 2.50001C21.8978 2.89784 22.1213 3.4374 22.1213 4.00001C22.1213 4.56262 21.8978 5.10219 21.5 5.50001L12 15L8 16L9 12L18.5 2.50001Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
				<button class="hbl-dashboard-action-btn hbl-dashboard-action-delete" data-listing-id="<?php echo esc_attr( $listing_id ); ?>" title="<?php esc_attr_e( 'Delete', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 6H5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H14C14.5304 2 15.0391 2.21071 15.4142 2.58579C15.7893 2.96086 16 3.46957 16 4V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render profile tab
	 */
	private function render_profile_tab( $user_id ) {
		$user = get_userdata( $user_id );
		$phone = get_user_meta( $user_id, 'atbdp_phone', true );
		$website = $user->user_url;
		$address = get_user_meta( $user_id, 'address', true );
		$bio = get_user_meta( $user_id, 'description', true );
		$facebook = get_user_meta( $user_id, 'atbdp_facebook', true );
		$twitter = get_user_meta( $user_id, 'atbdp_twitter', true );
		$linkedin = get_user_meta( $user_id, 'atbdp_linkedin', true );
		
		// Get profile image
		$profile_image_id = get_user_meta( $user_id, 'hbl_profile_image', true );
		$profile_image_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';
		$avatar_url = $profile_image_url ? $profile_image_url : get_avatar_url( $user_id, array( 'size' => 150 ) );
		?>
		<div class="hbl-dashboard-profile">
			<form class="hbl-dashboard-profile-form" method="post">
				<?php wp_nonce_field( 'hbl_update_profile', 'hbl_profile_nonce' ); ?>
				
				<!-- Profile Image Section -->
				<div class="hbl-dashboard-form-section hbl-dashboard-profile-image-section">
					<h4 class="hbl-dashboard-form-title"><?php esc_html_e( 'Profile Photo', 'hbl' ); ?></h4>
					
					<div class="hbl-dashboard-profile-image-wrapper">
						<div class="hbl-dashboard-profile-image-preview" id="hbl-profile-image-preview">
							<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>" id="hbl-profile-image-img" data-gravatar="<?php echo esc_url( get_avatar_url( $user_id, array( 'size' => 150 ) ) ); ?>">
							<?php if ( $profile_image_id ) : ?>
							<button type="button" class="hbl-dashboard-profile-image-remove" id="hbl-remove-profile-image" title="<?php esc_attr_e( 'Remove Photo', 'hbl' ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
							<?php endif; ?>
						</div>
						<div class="hbl-dashboard-profile-image-actions">
							<input type="hidden" name="profile_image" id="hbl-profile-image-input" value="<?php echo esc_attr( $profile_image_id ); ?>">
							<div class="hbl-dashboard-profile-image-buttons">
								<button type="button" class="hbl-dashboard-btn hbl-dashboard-btn-sm" id="hbl-upload-profile-image">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M17 8L12 3L7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M12 3V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Upload Photo', 'hbl' ); ?>
								</button>
								<button type="button" class="hbl-dashboard-btn hbl-dashboard-btn-sm hbl-dashboard-btn-save-photo" id="hbl-save-profile-image" style="display: none;">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H16L21 8V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M17 21V13H7V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M7 3V8H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Save Photo', 'hbl' ); ?>
								</button>
							</div>
							<p class="hbl-dashboard-profile-image-hint"><?php esc_html_e( 'Recommended: Square image, at least 150x150 pixels', 'hbl' ); ?></p>
						</div>
					</div>
				</div>
				
				<div class="hbl-dashboard-form-section">
					<h4 class="hbl-dashboard-form-title"><?php esc_html_e( 'Basic Information', 'hbl' ); ?></h4>
					
					<div class="hbl-dashboard-form-row">
						<div class="hbl-dashboard-form-group">
							<label for="first_name"><?php esc_html_e( 'First Name', 'hbl' ); ?></label>
							<input type="text" id="first_name" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>">
						</div>
						<div class="hbl-dashboard-form-group">
							<label for="last_name"><?php esc_html_e( 'Last Name', 'hbl' ); ?></label>
							<input type="text" id="last_name" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>">
						</div>
					</div>

					<div class="hbl-dashboard-form-row">
						<div class="hbl-dashboard-form-group">
							<label for="email"><?php esc_html_e( 'Email Address', 'hbl' ); ?></label>
							<input type="email" id="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>">
						</div>
						<div class="hbl-dashboard-form-group">
							<label for="phone"><?php esc_html_e( 'Phone Number', 'hbl' ); ?></label>
							<input type="tel" id="phone" name="phone" value="<?php echo esc_attr( $phone ); ?>">
						</div>
					</div>

					<div class="hbl-dashboard-form-group">
						<label for="website"><?php esc_html_e( 'Website', 'hbl' ); ?></label>
						<input type="url" id="website" name="website" value="<?php echo esc_url( $website ); ?>">
					</div>

					<div class="hbl-dashboard-form-group">
						<label for="address"><?php esc_html_e( 'Address', 'hbl' ); ?></label>
						<input type="text" id="address" name="address" value="<?php echo esc_attr( $address ); ?>">
					</div>

					<div class="hbl-dashboard-form-group">
						<label for="bio"><?php esc_html_e( 'Bio', 'hbl' ); ?></label>
						<textarea id="bio" name="bio" rows="4"><?php echo esc_textarea( $bio ); ?></textarea>
					</div>
				</div>

				<div class="hbl-dashboard-form-section">
					<h4 class="hbl-dashboard-form-title"><?php esc_html_e( 'Social Links', 'hbl' ); ?></h4>
					
					<div class="hbl-dashboard-form-row">
						<div class="hbl-dashboard-form-group">
							<label for="facebook"><?php esc_html_e( 'Facebook', 'hbl' ); ?></label>
							<input type="url" id="facebook" name="facebook" value="<?php echo esc_url( $facebook ); ?>" placeholder="https://facebook.com/username">
						</div>
						<div class="hbl-dashboard-form-group">
							<label for="twitter"><?php esc_html_e( 'Twitter', 'hbl' ); ?></label>
							<input type="url" id="twitter" name="twitter" value="<?php echo esc_url( $twitter ); ?>" placeholder="https://twitter.com/username">
						</div>
					</div>

					<div class="hbl-dashboard-form-group">
						<label for="linkedin"><?php esc_html_e( 'LinkedIn', 'hbl' ); ?></label>
						<input type="url" id="linkedin" name="linkedin" value="<?php echo esc_url( $linkedin ); ?>" placeholder="https://linkedin.com/in/username">
					</div>
				</div>

				<div class="hbl-dashboard-form-section">
					<h4 class="hbl-dashboard-form-title"><?php esc_html_e( 'Change Password', 'hbl' ); ?></h4>
					
					<div class="hbl-dashboard-form-group">
						<label for="current_password"><?php esc_html_e( 'Current Password', 'hbl' ); ?></label>
						<input type="password" id="current_password" name="current_password">
					</div>

					<div class="hbl-dashboard-form-row">
						<div class="hbl-dashboard-form-group">
							<label for="new_password"><?php esc_html_e( 'New Password', 'hbl' ); ?></label>
							<input type="password" id="new_password" name="new_password">
						</div>
						<div class="hbl-dashboard-form-group">
							<label for="confirm_password"><?php esc_html_e( 'Confirm Password', 'hbl' ); ?></label>
							<input type="password" id="confirm_password" name="confirm_password">
						</div>
					</div>
				</div>

				<div class="hbl-dashboard-form-actions">
					<button type="submit" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
						<?php esc_html_e( 'Save Changes', 'hbl' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render favorites tab
	 */
	private function render_favorites_tab( $user_id ) {
		$listing_favorites = get_user_meta( $user_id, 'atbdp_favourites', true );
		$listing_favorite_ids = is_array( $listing_favorites ) ? array_filter( array_map( 'absint', $listing_favorites ) ) : array();

		$event_favorites = get_user_meta( $user_id, 'hbl_favorite_events', true );
		$event_favorite_ids = is_array( $event_favorites ) ? array_filter( array_map( 'absint', $event_favorites ) ) : array();

		// Resolve the saved favourite IDs to items that actually still exist
		// before deciding what to render. The stored meta can point at
		// listings/events that have since been deleted, so it is not on its
		// own a reliable signal that there is anything left to show.
		$listings_query = null;
		if ( ! empty( $listing_favorite_ids ) && defined( 'ATBDP_POST_TYPE' ) ) {
			$listings_query = new \WP_Query( array(
				'post_type'      => ATBDP_POST_TYPE,
				'post__in'       => $listing_favorite_ids,
				'post_status'    => array( 'publish', 'pending', 'expired', 'private' ),
				'posts_per_page' => -1,
			) );
		}
		$has_listings = $listings_query && $listings_query->have_posts();

		$favorite_events = array();
		if ( ! empty( $event_favorite_ids ) && function_exists( 'hbl_events_db' ) ) {
			foreach ( $event_favorite_ids as $event_id ) {
				$event = hbl_events_db()->get( $event_id );
				// Include all events with valid statuses (not just publish)
				if ( $event && in_array( $event->status, array( 'publish', 'pending', 'private', 'expired' ), true ) ) {
					$favorite_events[] = $event;
				}
			}
		}
		$has_events = ! empty( $favorite_events );

		if ( ! $has_listings && ! $has_events ) {
			wp_reset_postdata();
			?>
			<div class="hbl-dashboard-empty">
				<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M20.84 4.61C20.3292 4.09924 19.7228 3.69397 19.0554 3.41708C18.3879 3.14019 17.6725 2.99756 16.95 2.99756C16.2275 2.99756 15.5121 3.14019 14.8446 3.41708C14.1772 3.69397 13.5708 4.09924 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99806 7.05 2.99806C5.59096 2.99806 4.19169 3.57831 3.16 4.61C2.1283 5.6417 1.54806 7.04097 1.54806 8.5C1.54806 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.3508 11.8792 21.756 11.2728 22.0329 10.6054C22.3098 9.93789 22.4524 9.2225 22.4524 8.5C22.4524 7.7775 22.3098 7.06211 22.0329 6.39464C21.756 5.72718 21.3508 5.12075 20.84 4.61Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<h4><?php esc_html_e( 'No Favorites Yet', 'hbl' ); ?></h4>
				<p><?php esc_html_e( 'You haven\'t saved any listings or events to your favorites yet. Browse the directory and tap the heart icon to save them here.', 'hbl' ); ?></p>
				<a href="<?php echo esc_url( home_url( '/all-listings/' ) ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
					<?php esc_html_e( 'Browse Listings', 'hbl' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		// Favorite Listings Section
		if ( $has_listings ) :
			?>
			<div class="hbl-dashboard-favorites-section">
				<h3 class="hbl-dashboard-favorites-section-title">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Favorite Listings', 'hbl' ); ?>
					<span class="hbl-dashboard-favorites-count"><?php echo esc_html( $listings_query->found_posts ); ?></span>
				</h3>
				<div class="hbl-dashboard-favorites-grid">
					<?php while ( $listings_query->have_posts() ) : $listings_query->the_post(); ?>
						<?php $this->render_favorite_card( get_the_ID(), 'listing' ); ?>
					<?php endwhile; ?>
				</div>
			</div>
			<?php
			wp_reset_postdata();
		endif;

		// Favorite Events Section (from custom database)
		if ( $has_events ) :
			?>
			<div class="hbl-dashboard-favorites-section">
				<h3 class="hbl-dashboard-favorites-section-title">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
						<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
					</svg>
					<?php esc_html_e( 'Favorite Events', 'hbl' ); ?>
					<span class="hbl-dashboard-favorites-count"><?php echo esc_html( count( $favorite_events ) ); ?></span>
				</h3>
				<div class="hbl-dashboard-favorites-grid">
					<?php foreach ( $favorite_events as $event ) : ?>
						<?php $this->render_favorite_event_card( $event ); ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		endif;
	}

	/**
	 * Render single favorite card
	 */
	private function render_favorite_card( $item_id, $type = 'listing' ) {
		$thumbnail  = get_the_post_thumbnail_url( $item_id, 'medium' );
		$view_url   = get_permalink( $item_id );
		
		if ( $type === 'listing' && defined( 'ATBDP_CATEGORY' ) ) {
			$categories = get_the_terms( $item_id, ATBDP_CATEGORY );
		} elseif ( $type === 'event' ) {
			$categories = get_the_terms( $item_id, 'event_category' );
		} else {
			$categories = array();
		}
		
		$category = $categories && ! is_wp_error( $categories ) && ! empty( $categories ) ? $categories[0]->name : '';
		
		// Get date - event date for events, publish date for listings
		$display_date = '';
		if ( $type === 'event' ) {
			$start_date = get_post_meta( $item_id, '_piecal_start_date', true );
			if ( $start_date ) {
				$display_date = date_i18n( 'M j, Y', strtotime( $start_date ) );
			}
		} else {
			// For listings, show publish date
			$display_date = get_the_date( 'M j, Y', $item_id );
		}
		?>
		<div class="hbl-dashboard-favorite-card" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
			<div class="hbl-dashboard-favorite-image">
				<?php if ( $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php the_title_attribute(); ?>">
				<?php else : ?>
					<div class="hbl-dashboard-favorite-no-image">
						<?php if ( $type === 'event' ) : ?>
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
						</svg>
						<?php else : ?>
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/>
							<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2"/>
						</svg>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<button class="hbl-dashboard-favorite-remove" data-item-id="<?php echo esc_attr( $item_id ); ?>" data-type="<?php echo esc_attr( $type ); ?>" title="<?php esc_attr_e( 'Remove from favorites', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
						<path d="M20.84 4.61C20.3292 4.09924 19.7228 3.69397 19.0554 3.41708C18.3879 3.14019 17.6725 2.99756 16.95 2.99756C16.2275 2.99756 15.5121 3.14019 14.8446 3.41708C14.1772 3.69397 13.5708 4.09924 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99806 7.05 2.99806C5.59096 2.99806 4.19169 3.57831 3.16 4.61C2.1283 5.6417 1.54806 7.04097 1.54806 8.5C1.54806 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.3508 11.8792 21.756 11.2728 22.0329 10.6054C22.3098 9.93789 22.4524 9.2225 22.4524 8.5C22.4524 7.7775 22.3098 7.06211 22.0329 6.39464C21.756 5.72718 21.3508 5.12075 20.84 4.61Z"/>
					</svg>
				</button>
			</div>
			<div class="hbl-dashboard-favorite-content">
				<h4 class="hbl-dashboard-favorite-title">
					<a href="<?php echo esc_url( $view_url ); ?>"><?php the_title(); ?></a>
				</h4>
				<?php if ( $display_date ) : ?>
				<span class="hbl-dashboard-favorite-date"><?php echo esc_html( $display_date ); ?></span>
				<?php endif; ?>
				<?php if ( $category ) : ?>
				<span class="hbl-dashboard-favorite-category"><?php echo esc_html( $category ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render single favorite event card (from custom database)
	 */
	private function render_favorite_event_card( $event ) {
		$event_id = $event->id;
		$thumbnail = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium' ) : '';
		$event_url = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
		
		// Get category name
		$category_name = '';
		if ( $event->category_id ) {
			$category = get_term( $event->category_id, 'event_category' );
			if ( $category && ! is_wp_error( $category ) ) {
				$category_name = $category->name;
			}
		}
		
		// Format date
		$display_date = '';
		if ( $event->start_date ) {
			$display_date = date_i18n( 'M j, Y', strtotime( $event->start_date ) );
		}
		?>
		<div class="hbl-dashboard-favorite-card" data-item-id="<?php echo esc_attr( $event_id ); ?>" data-type="event">
			<div class="hbl-dashboard-favorite-image">
				<?php if ( $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( $event->title ); ?>">
				<?php else : ?>
					<div class="hbl-dashboard-favorite-no-image">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
						</svg>
					</div>
				<?php endif; ?>
				<button class="hbl-dashboard-favorite-remove" data-item-id="<?php echo esc_attr( $event_id ); ?>" data-type="event" title="<?php esc_attr_e( 'Remove from favorites', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
						<path d="M20.84 4.61C20.3292 4.09924 19.7228 3.69397 19.0554 3.41708C18.3879 3.14019 17.6725 2.99756 16.95 2.99756C16.2275 2.99756 15.5121 3.14019 14.8446 3.41708C14.1772 3.69397 13.5708 4.09924 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99806 7.05 2.99806C5.59096 2.99806 4.19169 3.57831 3.16 4.61C2.1283 5.6417 1.54806 7.04097 1.54806 8.5C1.54806 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.3508 11.8792 21.756 11.2728 22.0329 10.6054C22.3098 9.93789 22.4524 9.2225 22.4524 8.5C22.4524 7.7775 22.3098 7.06211 22.0329 6.39464C21.756 5.72718 21.3508 5.12075 20.84 4.61Z"/>
					</svg>
				</button>
			</div>
			<div class="hbl-dashboard-favorite-content">
				<h4 class="hbl-dashboard-favorite-title">
					<a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a>
				</h4>
				<?php if ( $display_date ) : ?>
				<span class="hbl-dashboard-favorite-date"><?php echo esc_html( $display_date ); ?></span>
				<?php endif; ?>
				<?php if ( $category_name ) : ?>
				<span class="hbl-dashboard-favorite-category"><?php echo esc_html( $category_name ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render claims tab
	 */
	private function render_claims_tab( $user_id ) {
		// Check if Directorist Claim Listing plugin is active
		if ( ! post_type_exists( 'dcl_claim_listing' ) ) {
			?>
			<div class="hbl-dashboard-empty">
				<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<h4><?php esc_html_e( 'Claims Not Available', 'hbl' ); ?></h4>
				<p><?php esc_html_e( 'The claim listing feature is not currently available.', 'hbl' ); ?></p>
			</div>
			<?php
			return;
		}

		// Get user's claim requests
		$claims_query = new \WP_Query( array(
			'post_type'      => 'dcl_claim_listing',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_listing_claimer',
					'value'   => $user_id,
					'compare' => '=',
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		// Get claimed listings (listings where user is owner and listing is claimed)
		$claimed_listings_query = new \WP_Query( array(
			'author'         => $user_id,
			'post_type'      => defined( 'ATBDP_POST_TYPE' ) ? ATBDP_POST_TYPE : 'at_biz_dir',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_claimed_by_admin',
					'value'   => '1',
					'compare' => '=',
				),
			),
		) );

		$has_claims = $claims_query->have_posts();
		$has_claimed_listings = $claimed_listings_query->have_posts();

		if ( ! $has_claims && ! $has_claimed_listings ) {
			?>
			<div class="hbl-dashboard-empty">
				<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<h4><?php esc_html_e( 'No Claims Yet', 'hbl' ); ?></h4>
				<p><?php esc_html_e( 'You haven\'t submitted any claim requests yet. Browse listings and claim your business!', 'hbl' ); ?></p>
				<a href="<?php echo esc_url( home_url( '/all-listings/' ) ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
					<?php esc_html_e( 'Browse Listings', 'hbl' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		// Claimed Businesses Section
		if ( $has_claimed_listings ) :
			?>
			<div class="hbl-dashboard-claims-section">
				<h3 class="hbl-dashboard-claims-section-title">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18455 2.99721 7.13631 4.39828 5.49706C5.79935 3.85781 7.69279 2.71537 9.79619 2.24013C11.8996 1.7649 14.1003 1.98232 16.07 2.85999" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'My Claimed Businesses', 'hbl' ); ?>
					<span class="hbl-dashboard-claims-count"><?php echo esc_html( $claimed_listings_query->found_posts ); ?></span>
				</h3>
				<div class="hbl-dashboard-claims-grid">
					<?php while ( $claimed_listings_query->have_posts() ) : $claimed_listings_query->the_post(); ?>
						<?php $this->render_claimed_listing_card( get_the_ID() ); ?>
					<?php endwhile; ?>
				</div>
			</div>
			<?php
			wp_reset_postdata();
		endif;

		// Claim Requests Section
		if ( $has_claims ) :
			?>
			<div class="hbl-dashboard-claims-section">
				<h3 class="hbl-dashboard-claims-section-title">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
						<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<?php esc_html_e( 'Claim Requests', 'hbl' ); ?>
					<span class="hbl-dashboard-claims-count"><?php echo esc_html( $claims_query->found_posts ); ?></span>
				</h3>
				<div class="hbl-dashboard-claims-list">
					<?php while ( $claims_query->have_posts() ) : $claims_query->the_post(); ?>
						<?php $this->render_claim_request_card( get_the_ID() ); ?>
					<?php endwhile; ?>
				</div>
			</div>
			<?php
			wp_reset_postdata();
		endif;
	}

	/**
	 * Render claimed listing card
	 */
	private function render_claimed_listing_card( $listing_id ) {
		$thumbnail  = get_the_post_thumbnail_url( $listing_id, 'medium' );
		$view_url   = get_permalink( $listing_id );
		$edit_url   = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_edit_listing_page_link( $listing_id ) : get_edit_post_link( $listing_id );
		$categories = defined( 'ATBDP_CATEGORY' ) ? get_the_terms( $listing_id, ATBDP_CATEGORY ) : array();
		$category   = $categories && ! is_wp_error( $categories ) ? $categories[0]->name : '';
		$claimed_date = get_post_meta( $listing_id, '_claim_fee', true ) === 'claim_approved' ? get_the_modified_date( '', $listing_id ) : '';
		?>
		<div class="hbl-dashboard-claim-card hbl-dashboard-claimed-listing-card">
			<div class="hbl-dashboard-claim-image">
				<?php if ( $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php the_title_attribute(); ?>">
				<?php else : ?>
					<div class="hbl-dashboard-claim-no-image">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/>
							<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2"/>
						</svg>
					</div>
				<?php endif; ?>
				<span class="hbl-dashboard-claim-badge hbl-dashboard-claim-badge-verified">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Verified Owner', 'hbl' ); ?>
				</span>
			</div>
			<div class="hbl-dashboard-claim-content">
				<h4 class="hbl-dashboard-claim-title">
					<a href="<?php echo esc_url( $view_url ); ?>"><?php the_title(); ?></a>
				</h4>
				<?php if ( $category ) : ?>
				<span class="hbl-dashboard-claim-category"><?php echo esc_html( $category ); ?></span>
				<?php endif; ?>
				<div class="hbl-dashboard-claim-meta">
					<span class="hbl-dashboard-claim-date">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
							<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php esc_html_e( 'Claimed', 'hbl' ); ?>
					</span>
				</div>
			</div>
			<div class="hbl-dashboard-claim-actions">
				<a href="<?php echo esc_url( $view_url ); ?>" class="hbl-dashboard-action-btn" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
					</svg>
				</a>
				<a href="<?php echo esc_url( $edit_url ); ?>" class="hbl-dashboard-action-btn" title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M11 4H4C3.46957 4 2.96086 4.21071 2.58579 4.58579C2.21071 4.96086 2 5.46957 2 6V20C2 20.5304 2.21071 21.0391 2.58579 21.4142C2.96086 21.7893 3.46957 22 4 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M18.5 2.50001C18.8978 2.10219 19.4374 1.87869 20 1.87869C20.5626 1.87869 21.1022 2.10219 21.5 2.50001C21.8978 2.89784 22.1213 3.4374 22.1213 4.00001C22.1213 4.56262 21.8978 5.10219 21.5 5.50001L12 15L8 16L9 12L18.5 2.50001Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render claim request card
	 */
	private function render_claim_request_card( $claim_id ) {
		$listing_id     = get_post_meta( $claim_id, '_claimed_listing', true );
		$claim_status   = get_post_meta( $claim_id, '_claim_status', true );
		$claimer_details = get_post_meta( $claim_id, '_claimer_details', true );
		$claim_date     = get_the_date( '', $claim_id );
		
		// Get listing info
		$listing_title  = $listing_id ? get_the_title( $listing_id ) : __( 'Unknown Listing', 'hbl' );
		$listing_url    = $listing_id ? get_permalink( $listing_id ) : '#';
		$listing_thumb  = $listing_id ? get_the_post_thumbnail_url( $listing_id, 'thumbnail' ) : '';

		// Status labels and colors
		$status_labels = array(
			'pending'  => __( 'Pending Review', 'hbl' ),
			'approved' => __( 'Approved', 'hbl' ),
			'declined' => __( 'Declined', 'hbl' ),
		);
		$status_label = isset( $status_labels[ $claim_status ] ) ? $status_labels[ $claim_status ] : ucfirst( $claim_status );
		?>
		<div class="hbl-dashboard-claim-request-card" data-claim-id="<?php echo esc_attr( $claim_id ); ?>" data-status="<?php echo esc_attr( $claim_status ); ?>">
			<div class="hbl-dashboard-claim-request-header">
				<div class="hbl-dashboard-claim-request-listing">
					<?php if ( $listing_thumb ) : ?>
						<img src="<?php echo esc_url( $listing_thumb ); ?>" alt="<?php echo esc_attr( $listing_title ); ?>" class="hbl-dashboard-claim-request-thumb">
					<?php else : ?>
						<div class="hbl-dashboard-claim-request-thumb-placeholder">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
								<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/>
								<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2"/>
							</svg>
						</div>
					<?php endif; ?>
					<div class="hbl-dashboard-claim-request-listing-info">
						<h4 class="hbl-dashboard-claim-request-title">
							<a href="<?php echo esc_url( $listing_url ); ?>"><?php echo esc_html( $listing_title ); ?></a>
						</h4>
						<span class="hbl-dashboard-claim-request-date">
							<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
								<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							</svg>
							<?php printf( esc_html__( 'Submitted: %s', 'hbl' ), esc_html( $claim_date ) ); ?>
						</span>
					</div>
				</div>
				<span class="hbl-dashboard-claim-status hbl-dashboard-claim-status-<?php echo esc_attr( $claim_status ); ?>">
					<?php if ( $claim_status === 'pending' ) : ?>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
					<?php elseif ( $claim_status === 'approved' ) : ?>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					<?php else : ?>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
					<?php endif; ?>
					<?php echo esc_html( $status_label ); ?>
				</span>
			</div>
			<?php if ( $claimer_details ) : ?>
			<div class="hbl-dashboard-claim-request-details">
				<strong><?php esc_html_e( 'Your Message:', 'hbl' ); ?></strong>
				<p><?php echo esc_html( wp_trim_words( $claimer_details, 30 ) ); ?></p>
			</div>
			<?php endif; ?>
			<?php if ( $claim_status === 'approved' ) : ?>
			<div class="hbl-dashboard-claim-request-success">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Congratulations! Your claim has been approved. You can now manage this listing.', 'hbl' ); ?>
			</div>
			<?php elseif ( $claim_status === 'declined' ) : ?>
			<div class="hbl-dashboard-claim-request-error">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
					<path d="M12 8V12M12 16H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>
				<?php esc_html_e( 'Your claim request was declined. Please contact support for more information.', 'hbl' ); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render events tab
	 */
	private function render_events_tab( $user_id, $settings ) {
		// Check if we're adding/editing an event
		$editing   = isset( $_GET['edit_event'] ) ? absint( $_GET['edit_event'] ) : 0;
		$show_form = isset( $_GET['add_event'] ) || $editing;

		// Get user's events from custom database
		$events = array();
		if ( function_exists( 'hbl_events_db' ) ) {
			global $wpdb;
			$table = hbl_events_db()->get_table_name();
			$events = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY start_date DESC",
				$user_id
			) );
		}

		// Get event categories from taxonomy
		$event_categories = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
		) );
		if ( is_wp_error( $event_categories ) ) {
			$event_categories = array();
		}

		// Get event data if editing
		$event_data = array();
		if ( $editing && function_exists( 'hbl_events_db' ) ) {
			$event = hbl_events_db()->get( $editing );
			if ( $event && (int) $event->user_id === $user_id ) {
				$event_data = array(
					'id'              => $event->id,
					'title'           => $event->title,
					'content'         => $event->description,
					'is_allday'       => $event->is_allday,
					'start_date'      => $event->start_date,
					'end_date'        => $event->end_date,
					'event_color'     => $event->event_color,
					'featured_image'  => $event->featured_image,
					'category_id'     => $event->category_id,
					'venue'           => $event->venue,
					'event_url'       => $event->event_url,
					'contact_email'   => $event->contact_email,
					'event_type'      => $event->event_type,
					'event_cost'      => $event->event_cost,
					'event_frequency' => $event->event_frequency,
					'organiser_type'  => $event->organiser_type,
				);
			} else {
				$editing = 0;
			}
		}
		?>
		<div class="hbl-dashboard-events">
			<?php if ( isset( $_GET['event_saved'] ) ) : ?>
				<div class="hbl-dashboard-notice hbl-dashboard-notice-success">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Event saved successfully!', 'hbl' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['event_deleted'] ) ) : ?>
				<div class="hbl-dashboard-notice hbl-dashboard-notice-success">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<?php esc_html_e( 'Event deleted successfully!', 'hbl' ); ?>
				</div>
			<?php endif; ?>

			<div class="hbl-dashboard-events-header">
				<h3 class="hbl-dashboard-events-title">
					<?php 
					if ( $show_form ) {
						echo $editing 
							? esc_html__( 'Edit Event', 'hbl' ) 
							: esc_html__( 'Add New Event', 'hbl' );
					} else {
						esc_html_e( 'My Events', 'hbl' );
					}
					?>
				</h3>
				
				<?php if ( $show_form ) : 
					$back_to_events_url = add_query_arg( 'view', 'events', remove_query_arg( array( 'add_event', 'edit_event' ) ) );
				?>
					<a href="<?php echo esc_url( $back_to_events_url ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-outline hbl-dashboard-btn-sm">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php esc_html_e( 'Back to Events', 'hbl' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( $show_form ) : ?>
				<?php $this->render_event_form( $event_data, $event_categories, $editing ); ?>
			<?php else : ?>
				<?php $this->render_events_list( $events ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render event form
	 */
	private function render_event_form( $event_data, $event_categories, $editing ) {
		?>
		<form id="hbl-event-form" class="hbl-dashboard-event-form" method="post">
			<?php wp_nonce_field( 'hbl_event_form', 'hbl_event_nonce' ); ?>
			<input type="hidden" name="event_id" value="<?php echo esc_attr( $editing ); ?>">
			<input type="hidden" name="action" value="hbl_save_event">

			<div class="hbl-dashboard-form-group">
				<label for="event_title"><?php esc_html_e( 'Event Title', 'hbl' ); ?> <span class="required">*</span></label>
				<input type="text" id="event_title" name="event_title" value="<?php echo esc_attr( $event_data['title'] ?? '' ); ?>" required>
			</div>

			<div class="hbl-dashboard-form-group">
				<label for="event_content"><?php esc_html_e( 'Description', 'hbl' ); ?></label>
				<textarea id="event_content" name="event_content" rows="4"><?php echo esc_textarea( $event_data['content'] ?? '' ); ?></textarea>
			</div>

			<?php if ( ! empty( $event_categories ) && ! is_wp_error( $event_categories ) ) : ?>
			<div class="hbl-dashboard-form-group">
				<label for="event_category"><?php esc_html_e( 'Category', 'hbl' ); ?></label>
				<select id="event_category" name="event_category" class="hbl-dashboard-select">
					<option value=""><?php esc_html_e( '— Select Category —', 'hbl' ); ?></option>
					<?php foreach ( $event_categories as $category ) : ?>
						<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $event_data['category_id'] ?? 0, $category->term_id ); ?>>
							<?php echo esc_html( $category->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<div class="hbl-dashboard-form-row">
				<div class="hbl-dashboard-form-group">
					<label for="start_date"><?php esc_html_e( 'Start Date & Time', 'hbl' ); ?> <span class="required">*</span></label>
					<input type="datetime-local" id="start_date" name="start_date" value="<?php echo esc_attr( $event_data['start_date'] ?? '' ); ?>" required>
				</div>
				<div class="hbl-dashboard-form-group">
					<label for="end_date"><?php esc_html_e( 'End Date & Time', 'hbl' ); ?></label>
					<input type="datetime-local" id="end_date" name="end_date" value="<?php echo esc_attr( $event_data['end_date'] ?? '' ); ?>">
				</div>
			</div>

			<div class="hbl-dashboard-form-group hbl-dashboard-checkbox-group">
				<label class="hbl-dashboard-checkbox-label">
					<input type="checkbox" name="is_allday" value="1" <?php checked( $event_data['is_allday'] ?? 0, 1 ); ?>>
					<span><?php esc_html_e( 'All Day Event', 'hbl' ); ?></span>
				</label>
			</div>

			<div class="hbl-dashboard-form-group">
				<label for="event_color"><?php esc_html_e( 'Event Color', 'hbl' ); ?></label>
				<div class="hbl-dashboard-color-picker">
					<input type="color" id="event_color" name="event_color" value="<?php echo esc_attr( $event_data['event_color'] ?? '#008080' ); ?>">
					<span class="hbl-dashboard-color-preview" style="background-color: <?php echo esc_attr( $event_data['event_color'] ?? '#008080' ); ?>;"></span>
				</div>
			</div>

			<div class="hbl-dashboard-form-actions">
				<button type="submit" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
					<?php echo $editing ? esc_html__( 'Update Event', 'hbl' ) : esc_html__( 'Create Event', 'hbl' ); ?>
				</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render events list
	 */
	private function render_events_list( $events ) {
		if ( empty( $events ) ) :
			?>
			<div class="hbl-dashboard-empty">
				<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<h4><?php esc_html_e( 'No Events Yet', 'hbl' ); ?></h4>
				<p><?php esc_html_e( 'You haven\'t created any events yet. Create your first event now!', 'hbl' ); ?></p>
				<a href="<?php echo esc_url( home_url( '/add-event/' ) ); ?>" class="hbl-dashboard-btn hbl-dashboard-btn-primary">
					<?php esc_html_e( 'Add New Event', 'hbl' ); ?>
				</a>
			</div>
			<?php
			return;
		endif;
		?>
		<div class="hbl-dashboard-events-grid">
			<?php foreach ( $events as $event ) : 
				$event_id    = $event->id;
				$start_date  = $event->start_date;
				$end_date    = $event->end_date;
				$is_allday   = $event->is_allday;
				$event_color = $event->event_color ?: '#008080';
				$status      = $event->status;
				$thumbnail   = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium' ) : '';
				
				// Get category name
				$category_name = '';
				if ( $event->category_id ) {
					$category = get_term( $event->category_id, 'event_category' );
					if ( $category && ! is_wp_error( $category ) ) {
						$category_name = $category->name;
					}
				}
				
				// Get event URL
				$event_url = function_exists( 'hbl_events_db' ) ? hbl_events_db()->get_event_url( $event ) : '#';
				
				$status_labels = array(
					'publish' => __( 'Published', 'hbl' ),
					'pending' => __( 'Pending', 'hbl' ),
					'draft'   => __( 'Draft', 'hbl' ),
				);
			?>
			<div class="hbl-dashboard-event-card" data-event-id="<?php echo esc_attr( $event_id ); ?>">
				<div class="hbl-dashboard-event-image" style="border-left-color: <?php echo esc_attr( $event_color ); ?>;">
					<?php if ( $thumbnail ) : ?>
						<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( $event->title ); ?>">
					<?php else : ?>
						<div class="hbl-dashboard-event-placeholder" style="background-color: <?php echo esc_attr( $event_color ); ?>;">
							<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
					<?php endif; ?>
					<span class="hbl-dashboard-event-status hbl-dashboard-event-status-<?php echo esc_attr( $status ); ?>">
						<?php echo esc_html( $status_labels[ $status ] ?? ucfirst( $status ) ); ?>
					</span>
				</div>
				<div class="hbl-dashboard-event-content">
					<h4 class="hbl-dashboard-event-title">
						<a href="<?php echo esc_url( $event_url ); ?>"><?php echo esc_html( $event->title ); ?></a>
					</h4>
					
					<?php if ( ! empty( $category_name ) ) : ?>
						<span class="hbl-dashboard-event-category"><?php echo esc_html( $category_name ); ?></span>
					<?php endif; ?>
					
					<?php if ( $start_date ) : ?>
					<div class="hbl-dashboard-event-date">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M16 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M8 2V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php 
						$date_format = $is_allday ? get_option( 'date_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
						echo esc_html( date_i18n( $date_format, strtotime( $start_date ) ) );
						if ( $is_allday ) {
							echo ' <span class="hbl-dashboard-event-allday">' . esc_html__( 'All Day', 'hbl' ) . '</span>';
						}
						?>
					</div>
					<?php endif; ?>

					<div class="hbl-dashboard-event-actions">
						<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-dashboard-action-btn" title="<?php esc_attr_e( 'View', 'hbl' ); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</a>
						<a href="<?php echo esc_url( home_url( '/add-event/?event_id=' . $event_id ) ); ?>" class="hbl-dashboard-action-btn" title="<?php esc_attr_e( 'Edit', 'hbl' ); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M11 4H4C3.46957 4 2.96086 4.21071 2.58579 4.58579C2.21071 4.96086 2 5.46957 2 6V20C2 20.5304 2.21071 21.0391 2.58579 21.4142C2.96086 21.7893 3.46957 22 4 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M18.5 2.50001C18.8978 2.10219 19.4374 1.87869 20 1.87869C20.5626 1.87869 21.1022 2.10219 21.5 2.50001C21.8978 2.89784 22.1213 3.4374 22.1213 4.00001C22.1213 4.56262 21.8978 5.10219 21.5 5.50001L12 15L8 16L9 12L18.5 2.50001Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</a>
						<button type="button" class="hbl-dashboard-action-btn hbl-dashboard-action-btn-danger hbl-delete-event" data-event-id="<?php echo esc_attr( $event_id ); ?>" title="<?php esc_attr_e( 'Delete', 'hbl' ); ?>">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M3 6H5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H14C14.5304 2 15.0391 2.21071 15.4142 2.58579C15.7893 2.96086 16 3.46957 16 4V6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

