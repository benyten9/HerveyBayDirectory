<?php
/**
 * HBL Directorist V2 Widget
 *
 * Modern redesign with same functionality as V1 but cleaner code
 * Uses trait-based architecture for better maintainability
 *
 * @package HBL
 * @since 2.0.0
 */

namespace HBL\Widgets\V2;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use HBL\Widgets\V2\Traits\Query_Handler;
use HBL\Widgets\V2\Traits\Filter_Controls;
use HBL\Widgets\V2\Traits\Card_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Directorist_V2 extends Widget_Base {
	
	use Query_Handler;
	use Filter_Controls;
	use Card_Renderer;

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-directorist-v2';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Directorist V2 (Modern)', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-gallery-grid';
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
		return array( 'directory', 'listing', 'business', 'directorist', 'v2', 'modern' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// Query Settings Section
		$this->start_controls_section(
			'section_query',
			array(
				'label' => esc_html__( 'Query Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'number_of_listings',
			array(
				'label'       => esc_html__( 'Total Listings', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => -1,
				'min'         => -1,
				'description' => esc_html__( 'Enter -1 to show all listings', 'hbl' ),
			)
		);

		$this->add_control(
			'listings_per_page',
			array(
				'label'   => esc_html__( 'Listings Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 20,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'enable_pagination',
			array(
				'label'        => esc_html__( 'Enable Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'orderby',
			array(
				'label'   => esc_html__( 'Order By', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'       => esc_html__( 'Date', 'hbl' ),
					'latest'     => esc_html__( 'Latest Listings', 'hbl' ),
					'last'       => esc_html__( 'Last Listings', 'hbl' ),
					'title'      => esc_html__( 'Title', 'hbl' ),
					'rand'       => esc_html__( 'Random', 'hbl' ),
					'menu_order' => esc_html__( 'Menu Order', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => esc_html__( 'Order', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'DESC',
				'options' => array(
					'ASC'  => esc_html__( 'Ascending', 'hbl' ),
					'DESC' => esc_html__( 'Descending', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'featured_only',
			array(
				'label'        => esc_html__( 'Featured Only', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		// Category Filter
		$this->add_control(
			'category_filter_type',
			array(
				'label'   => esc_html__( 'Category Filter', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'all'      => esc_html__( 'All Categories', 'hbl' ),
					'specific' => esc_html__( 'Specific Categories', 'hbl' ),
				),
			)
		);

		$categories = $this->get_listing_categories();
		$tags = $this->get_listing_tags();
		if ( ! empty( $categories ) ) {
			$this->add_control(
				'category',
				array(
					'label'       => esc_html__( 'Select Categories', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $categories,
					'label_block' => true,
					'condition'   => array(
						'category_filter_type' => 'specific',
					),
				)
			);
		}

		// Location Filter
		$this->add_control(
			'location_filter_type',
			array(
				'label'   => esc_html__( 'Location Filter', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'all'      => esc_html__( 'All Locations', 'hbl' ),
					'specific' => esc_html__( 'Specific Locations', 'hbl' ),
				),
			)
		);

		$locations = $this->get_listing_locations();
		if ( ! empty( $locations ) ) {
			$this->add_control(
				'location',
				array(
					'label'       => esc_html__( 'Select Locations', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $locations,
					'label_block' => true,
					'condition'   => array(
						'location_filter_type' => 'specific',
					),
				)
			);
		}

		// Pricing Plans Filter
		$this->add_control(
			'pricing_plans_filter_type',
			array(
				'label'   => esc_html__( 'Pricing Plans Filter', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'all',
				'options' => array(
					'all'      => esc_html__( 'All Plans', 'hbl' ),
					'specific' => esc_html__( 'Specific Plans', 'hbl' ),
				),
			)
		);

		$pricing_plans = $this->get_pricing_plans();
		if ( ! empty( $pricing_plans ) ) {
			$this->add_control(
				'pricing_plans',
				array(
					'label'       => esc_html__( 'Select Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
					'condition'   => array(
						'pricing_plans_filter_type' => 'specific',
					),
				)
			);
		}

		$this->end_controls_section();

		// Plan Tier Mapping
		$this->start_controls_section(
			'section_plan_tiers',
			array(
				'label' => esc_html__( 'Plan Tier Mapping', 'hbl' ),
			)
		);

		if ( ! empty( $pricing_plans ) ) {
			$this->add_control(
				'gold_plan_ids',
				array(
					'label'       => esc_html__( 'Gold Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);

			$this->add_control(
				'silver_plan_ids',
				array(
					'label'       => esc_html__( 'Silver Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		// Plan Badges Section
		$this->start_controls_section(
			'section_plan_badges',
			array(
				'label' => esc_html__( 'Plan Badges', 'hbl' ),
			)
		);

		$this->add_control(
			'show_plan_badges',
			array(
				'label'        => esc_html__( 'Show Plan Badges', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show badges on listing cards based on pricing plan', 'hbl' ),
			)
		);

		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'plan_tier',
			array(
				'label'   => esc_html__( 'Plan Tier', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'gold',
				'options' => array(
					'gold'   => esc_html__( 'Gold', 'hbl' ),
					'silver' => esc_html__( 'Silver', 'hbl' ),
					'bronze' => esc_html__( 'Bronze', 'hbl' ),
				),
			)
		);

		$repeater->add_control(
			'badge_text',
			array(
				'label'       => esc_html__( 'Badge Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'PREMIUM', 'hbl' ),
				'placeholder' => esc_html__( 'Enter badge text', 'hbl' ),
			)
		);

		$repeater->add_control(
			'badge_bg_color',
			array(
				'label'   => esc_html__( 'Background Color', 'hbl' ),
				'type'    => Controls_Manager::COLOR,
				'default' => '#F9532A',
			)
		);

		$repeater->add_control(
			'badge_text_color',
			array(
				'label'   => esc_html__( 'Text Color', 'hbl' ),
				'type'    => Controls_Manager::COLOR,
				'default' => '#FFFFFF',
			)
		);

		$this->add_control(
			'plan_badges',
			array(
				'label'       => esc_html__( 'Plan Badges', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'plan_tier'        => 'gold',
						'badge_text'       => 'PREMIUM',
						'badge_bg_color'   => '#F9532A',
						'badge_text_color' => '#FFFFFF',
					),
					array(
						'plan_tier'        => 'silver',
						'badge_text'       => 'FEATURED',
						'badge_bg_color'   => '#008080',
						'badge_text_color' => '#FFFFFF',
					),
					array(
						'plan_tier'        => 'bronze',
						'badge_text'       => 'BASIC',
						'badge_bg_color'   => '#6c757d',
						'badge_text_color' => '#FFFFFF',
					),
				),
				'title_field' => '{{{ plan_tier.toUpperCase() }}} - {{{ badge_text }}}',
				'condition'   => array(
					'show_plan_badges' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// Display Options
		$this->start_controls_section(
			'section_display',
			array(
				'label' => esc_html__( 'Display Options', 'hbl' ),
			)
		);

		$this->add_control(
			'show_alphabetical_filter',
			array(
				'label'        => esc_html__( 'Show A-Z Alphabetical Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show alphabetical A-Z filter buttons above the listings', 'hbl' ),
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
				'description'  => esc_html__( 'Show keyword search input', 'hbl' ),
			)
		);

		$this->add_control(
			'show_popular_search',
			array(
				'label'        => esc_html__( 'Show Featured Tags', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show featured tags', 'hbl' ),
			)
		);
		
		// Popular Searches Repeater
		$popular_searches_repeater = new \Elementor\Repeater();
		
		$popular_searches_repeater->add_control(
			'search_text',
			array(
				'label'       => esc_html__( 'Search Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search Item', 'hbl' ),
				'placeholder' => esc_html__( 'Enter search text', 'hbl' ),
			)
		);
		
		
		if ( ! empty( $tags ) ) {
			$popular_searches_repeater->add_control(
				'search_tag',
				array(
					'label'       => esc_html__( 'Tag', 'hbl' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $tags,
					'label_block' => true,
				)
			);
		}
		
		$this->add_control(
			'popular_searches',
			array(
				'label'       => esc_html__( 'Featured Tags', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $popular_searches_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ search_text }}}',
				'condition'   => array(
					'show_popular_search' => 'yes',
				),
			)
		);

		// Popular Categories Control
		$this->add_control(
			'show_popular_categories',
			array(
				'label'        => esc_html__( 'Show Search Categories', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show Search Categories', 'hbl' ),
			)
		);
		
		// Popular Categories Repeater
		$popular_categories_repeater = new \Elementor\Repeater();
		
		$popular_categories_repeater->add_control(
			'category_text',
			array(
				'label'       => esc_html__( 'Category Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Category Item', 'hbl' ),
				'placeholder' => esc_html__( 'Enter category text', 'hbl' ),
			)
		);
		
		if ( ! empty( $categories ) ) {
			$popular_categories_repeater->add_control(
				'category_id',
				array(
					'label'       => esc_html__( 'Category', 'hbl' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $categories,
					'label_block' => true,
				)
			);
		}
		
		$this->add_control(
			'popular_categories',
			array(
				'label'       => esc_html__( 'Search Categories', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $popular_categories_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ category_text }}}',
				'condition'   => array(
					'show_popular_categories' => 'yes',
				),
			)
		);

		$this->add_control(
			'browse_more_categories_link',
			array(
				'label'       => esc_html__( 'Browse More Categories Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array(
					'url'         => '',
					'is_external' => false,
					'nofollow'    => false,
				),
				'condition'   => array(
					'show_popular_categories' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_more_filters',
			array(
				'label'        => esc_html__( 'Show More Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Show more filters dropdown', 'hbl' ),
			)
		);

		// More Filters Repeater
		$more_filters_repeater = new \Elementor\Repeater();
		
		$more_filters_repeater->add_control(
			'filter_text',
			array(
				'label'       => esc_html__( 'Filter Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Filter Item', 'hbl' ),
				'placeholder' => esc_html__( 'Enter filter text', 'hbl' ),
			)
		);
		
		if ( ! empty( $tags ) ) {
			$more_filters_repeater->add_control(
				'filter_tag',
				array(
					'label'       => esc_html__( 'Tag', 'hbl' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $tags,
					'label_block' => true,
				)
			);
		}
		
		$this->add_control(
			'more_filters',
			array(
				'label'       => esc_html__( 'More Filters', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $more_filters_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ filter_text }}}',
				'condition'   => array(
					'show_more_filters' => 'yes',
				),
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
			)
		);


		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle (Grid/List)', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
				'description' => esc_html__( 'Select the default view mode for listings', 'hbl' ),
			)
		);

		$this->add_control(
			'grid_view_icon',
			array(
				'label'   => esc_html__( 'Grid View Icon', 'hbl' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => array(
					'url' => get_template_directory_uri() . '/assets/images/grid-view-icon.svg',
				),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->add_control(
			'list_view_icon',
			array(
				'label'   => esc_html__( 'List View Icon', 'hbl' ),
				'type'    => Controls_Manager::MEDIA,
				'default' => array(
					'url' => get_template_directory_uri() . '/assets/images/list-view-icon.svg',
				),
				'condition' => array(
					'show_view_toggle' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// Fallback Logo Section
		$this->start_controls_section(
			'section_fallback_logo',
			array(
				'label' => esc_html__( 'Fallback Logo', 'hbl' ),
			)
		);

		$this->add_control(
			'fallback_logo',
			array(
				'label'       => esc_html__( 'Default Logo', 'hbl' ),
				'type'        => Controls_Manager::MEDIA,
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'This logo will be displayed for listings without a logo', 'hbl' ),
			)
		);

		$this->end_controls_section();

		// ========== RESPONSIVE VISIBILITY ==========
		$this->start_controls_section(
			'section_responsive_visibility',
			array(
				'label' => esc_html__( 'Responsive Visibility', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'resp_vis_heading_filters',
			array(
				'label' => esc_html__( 'Filters', 'hbl' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_responsive_control(
			'hide_popular_searches_resp',
			array(
				'label'        => esc_html__( 'Hide Featured Tags', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-popular-searches-section' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_az_filter_resp',
			array(
				'label'        => esc_html__( 'Hide A-Z Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-alphabetical-filter' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_filters_bar_resp',
			array(
				'label'        => esc_html__( 'Hide Entire Filters Bar', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-filters-bar' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_keyword_search_resp',
			array(
				'label'        => esc_html__( 'Hide Keyword Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-keyword-search' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_popular_categories_resp',
			array(
				'label'        => esc_html__( 'Hide Search Categories', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-popular-categories-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_more_filters_resp',
			array(
				'label'        => esc_html__( 'Hide More Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-more-filters-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_sort_dropdown_resp',
			array(
				'label'        => esc_html__( 'Hide Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-sort-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'resp_vis_heading_display',
			array(
				'label'     => esc_html__( 'Display', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'hide_view_toggle_resp',
			array(
				'label'        => esc_html__( 'Hide View Toggle', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-view-toggle' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_listings_grid_resp',
			array(
				'label'        => esc_html__( 'Hide Listings Grid', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-listings-grid' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_pagination_resp',
			array(
				'label'        => esc_html__( 'Hide Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-pagination' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_active_filters_resp',
			array(
				'label'        => esc_html__( 'Hide Active Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-active-filters' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on frontend
	 */
	protected function render() {
		// Enqueue Leaflet for map
		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		$settings = $this->get_settings_for_display();
		$widget_id = $this->get_id();
		
		// Get current page and letter filter
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( $_GET['letter'] ) ) : '';
		
		// Get listings
		$query = $this->get_listings_query( $settings, $paged, $letter );
		
		// Get display settings
		$show_keyword_search = isset( $settings['show_keyword_search'] ) && 'yes' === $settings['show_keyword_search'];
		$show_popular_search = isset( $settings['show_popular_search'] ) && 'yes' === $settings['show_popular_search'];
		$show_more_filters = isset( $settings['show_more_filters'] ) && 'yes' === $settings['show_more_filters'];
		$show_sort = isset( $settings['show_sort_dropdown'] ) && 'yes' === $settings['show_sort_dropdown'];
		$show_view_toggle = isset( $settings['show_view_toggle'] ) && 'yes' === $settings['show_view_toggle'];
		$default_view = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		
		// Get popular tags for popular search
		$popular_searches = array();
		if ( $show_popular_search ) {
			// Check if custom popular searches are defined
			if ( ! empty( $settings['popular_searches'] ) && is_array( $settings['popular_searches'] ) ) {
				// Use custom popular searches from Elementor
				$popular_searches = $settings['popular_searches'];
			} else {
				// Fall back to automatic popular tags
				$popular_tags = get_terms( array(
					'taxonomy'   => 'at_biz_dir-tags',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 6,
				) );
				if ( ! is_wp_error( $popular_tags ) ) {
					// Convert to format compatible with custom searches
					foreach ( $popular_tags as $tag ) {
						$popular_searches[] = array(
							'search_text' => $tag->name,
							'search_tag'  => $tag->term_id,
						);
						}
				}
			}
		}

		// Get popular categories
		$show_popular_categories = isset( $settings['show_popular_categories'] ) && 'yes' === $settings['show_popular_categories'];
		$popular_categories = array();
		if ( $show_popular_categories ) {
			// Check if custom popular categories are defined
			if ( ! empty( $settings['popular_categories'] ) && is_array( $settings['popular_categories'] ) ) {
				// Use custom popular categories from Elementor
				$popular_categories = $settings['popular_categories'];
			}
		}

		// Get more filters items
		$more_filters_items = array();
		if ( $show_more_filters && ! empty( $settings['more_filters'] ) ) {
			$more_filters_items = $settings['more_filters'];
		}

		
		?>
		<div class="hbl-v2-widget hbl-directorist-widget" 
		     data-widget-id="<?php echo esc_attr( $widget_id ); ?>" 
		     data-default-view="<?php echo esc_attr( $default_view ); ?>"
		     data-widget-settings="<?php echo esc_attr( json_encode( $settings ) ); ?>">
			
			<?php if ( $show_popular_search && ! empty( $popular_searches ) ) : ?>
				<!-- Popular Searches as Buttons (Above A-Z Filter) -->
				<div class="hbl-v2-popular-searches-section">
					<div class="hbl-v2-popular-searches-buttons">
						<?php foreach ( $popular_searches as $item ) : 
							$item_text = isset( $item['search_text'] ) ? $item['search_text'] : '';
							$item_tag = isset( $item['search_tag'] ) ? $item['search_tag'] : 0;
						?>
							<button type="button" class="hbl-v2-popular-search-btn" data-tag="<?php echo esc_attr( $item_tag ); ?>">
								<?php echo esc_html( $item_text ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			

			
			<?php if ( isset( $settings['show_alphabetical_filter'] ) && 'yes' === $settings['show_alphabetical_filter'] ) : ?>
				<!-- A-Z Alphabetical Filter -->
				<div class="hbl-v2-alphabetical-filter">
					<div class="hbl-v2-filter-buttons">
						<?php foreach ( range( 'A', 'Z' ) as $alpha_letter ) : 
							$is_active = ( $letter === $alpha_letter );
							$letter_url = $is_active ? remove_query_arg( 'letter' ) : add_query_arg( 'letter', $alpha_letter );
						?>
							<a href="<?php echo esc_url( $letter_url ); ?>" 
							   class="hbl-v2-letter-btn <?php echo $is_active ? 'active' : ''; ?>" 
							   data-letter="<?php echo esc_attr( $alpha_letter ); ?>">
								<?php echo esc_html( $alpha_letter ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			
			<?php if ( $show_keyword_search || $show_popular_search || $show_popular_categories || $show_more_filters || $show_sort || $show_view_toggle ) : ?>
				<!-- Filters Bar -->
				<div class="hbl-v2-filters-bar">
					
					<div class="hbl-v2-filters-left">
						<span class="hbl-v2-filters-label"><?php esc_html_e( 'Filters', 'hbl' ); ?></span>
						
						<?php if ( $show_keyword_search ) : ?>
							<!-- Search Business -->
							<div class="hbl-v2-keyword-search">
								<i class="bi bi-search hbl-v2-keyword-search-icon"></i>
								<input type="text" class="hbl-v2-keyword-search-input" placeholder="<?php esc_attr_e( 'Search by Keyword...', 'hbl' ); ?>" autocomplete="off">
								<button type="button" class="hbl-v2-keyword-clear" style="display: none;">
									<i class="bi bi-x-lg"></i>
								</button>
							</div>
						<?php endif; ?>
						
						<?php if ( $show_popular_categories && ! empty( $popular_categories ) ) : ?>
							<!-- Popular Categories Dropdown -->
							<div class="hbl-v2-popular-categories-wrapper">
								<div class="hbl-v2-popular-categories-container">
									<button type="button" class="hbl-v2-popular-categories-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-popular-categories-label"><?php esc_html_e( 'Search Categories', 'hbl' ); ?></span>
										<span class="hbl-v2-popular-categories-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-popular-categories-dropdown" role="listbox" aria-hidden="true">
									<div class="hbl-v2-popular-categories-list">
										<?php foreach ( $popular_categories as $item ) : 
											$item_text = isset( $item['category_text'] ) ? $item['category_text'] : '';
											$item_category = isset( $item['category_id'] ) ? $item['category_id'] : 0;
										?>
											<button type="button" class="hbl-v2-popular-category-item" data-category="<?php echo esc_attr( $item_category ); ?>" role="option">
												<?php echo esc_html( $item_text ); ?>
											</button>
										<?php endforeach; ?>
										

									</div>
									
									<?php if ( ! empty( $settings['browse_more_categories_link']['url'] ) ) : ?>
										<div class="hbl-v2-popular-categories-footer">
											<a href="<?php echo esc_url( $settings['browse_more_categories_link']['url'] ); ?>" 
											   class="hbl-v2-browse-more"
											   <?php echo $settings['browse_more_categories_link']['is_external'] ? 'target="_blank"' : ''; ?>
											   <?php echo $settings['browse_more_categories_link']['nofollow'] ? 'rel="nofollow"' : ''; ?>>
												<span class="hbl-v2-browse-more-text"><?php esc_html_e( 'Browse More Categories', 'hbl' ); ?></span>
												<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
													<path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/>
												</svg>
											</a>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>
						
						<?php if ( $show_more_filters && ! empty( $more_filters_items ) ) : ?>
							<!-- More Filters Dropdown (stays in filters bar) -->
							<div class="hbl-v2-more-filters-wrapper">
								<div class="hbl-v2-more-filters-container">
									<button type="button" class="hbl-v2-more-filters-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-more-filters-label"><?php esc_html_e( 'More Filters', 'hbl' ); ?></span>
										<span class="hbl-v2-more-filters-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-more-filters-dropdown" role="listbox" aria-hidden="true">
									<div class="hbl-v2-more-filters-list">
										<?php foreach ( $more_filters_items as $item ) :
											$item_text = isset( $item['filter_text'] ) ? $item['filter_text'] : '';
											$item_tag = isset( $item['filter_tag'] ) ? $item['filter_tag'] : 0;
										?>
											<button type="button" class="hbl-v2-more-filter-item" data-tag="<?php echo esc_attr( $item_tag ); ?>" role="option">
												<?php echo esc_html( $item_text ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endif; ?>
						
						<?php if ( $show_sort ) : ?>
							<!-- Sort By -->
							<!-- Sort By -->
							<div class="hbl-v2-sort-wrapper hbl-v2-filter-dropdown">
								<div class="hbl-v2-sort-container">
									<button type="button" class="hbl-v2-sort-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-sort-label"><?php esc_html_e( 'Sort By', 'hbl' ); ?></span>
										<span class="hbl-v2-sort-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-sort-dropdown" role="listbox" aria-hidden="true" style="display: none;">
									<div class="hbl-v2-sort-list">
										<button type="button" class="hbl-v2-sort-item" data-value="recommended" role="option"><?php esc_html_e( 'Recommended', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="a-z" role="option"><?php esc_html_e( 'A–Z', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="z-a" role="option"><?php esc_html_e( 'Z–A', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="newest" role="option"><?php esc_html_e( 'Newest', 'hbl' ); ?></button>
									</div>
								</div>
							</div>
						<?php endif; ?>

					</div>
					
					<?php if ( $show_view_toggle ) : ?>
						<div class="hbl-v2-filters-right">
							<!-- View Toggle -->
							<div class="hbl-v2-view-toggle">
								<span class="hbl-v2-view-label"><?php esc_html_e( 'View as:', 'hbl' ); ?></span>
								<div class="hbl-v2-view-buttons">
									<button type="button" class="hbl-v2-view-btn <?php echo 'grid' === $default_view ? 'active' : ''; ?>" data-view="grid">
										<?php if ( ! empty( $settings['grid_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['grid_view_icon']['url'] ); ?>" alt="Grid" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-grid-3x3-gap"></i>
										<?php endif; ?>
									</button>
									<button type="button" class="hbl-v2-view-btn <?php echo 'list' === $default_view ? 'active' : ''; ?>" data-view="list">
										<?php if ( ! empty( $settings['list_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['list_view_icon']['url'] ); ?>" alt="List" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-list-ul"></i>
										<?php endif; ?>
									</button>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			
			<!-- Active Filters Display -->
			<div class="hbl-v2-active-filters" style="display: none;">
				<div class="hbl-v2-active-filters-container"></div>
			</div>
			
			<!-- Listings Grid -->
			<div class="hbl-v2-listings-grid <?php echo 'list' === $default_view ? 'list-view' : ''; ?>">
				<?php
				if ( $query->have_posts() ) :
					// Separate listings into left and right columns
					$listings = array();
					while ( $query->have_posts() ) :
						$query->the_post();
						$listings[] = get_the_ID();
					endwhile;
					wp_reset_postdata();
					
					$half = ceil( count( $listings ) / 2 );
					$left_column = array_slice( $listings, 0, $half );
					$right_column = array_slice( $listings, $half );
					?>
					
					<!-- Left Column -->
					<div class="hbl-v2-left-column">
						<?php foreach ( $left_column as $listing_id ) : 
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach; ?>
					</div>
					
					<!-- Right Column -->
					<div class="hbl-v2-right-column">
						<?php foreach ( $right_column as $listing_id ) : 
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach; ?>
					</div>
					
					<?php wp_reset_postdata(); ?>
				<?php else : ?>
					<div class="hbl-v2-no-results">
						<i class="bi bi-search"></i>
						<p><?php esc_html_e( 'No listings found.', 'hbl' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
			
			<?php if ( isset( $settings['enable_pagination'] ) && 'yes' === $settings['enable_pagination'] && $query->max_num_pages > 1 ) : ?>
				<!-- Pagination -->
				<div class="hbl-v2-pagination">
					<div class="hbl-v2-pagination-info">
						<?php
						$start_item = ( ( $paged - 1 ) * $settings['listings_per_page'] ) + 1;
						$end_item = min( $paged * $settings['listings_per_page'], $query->found_posts );
						printf(
							esc_html__( 'Showing %1$d - %2$d of %3$d listings', 'hbl' ),
							$start_item,
							$end_item,
							$query->found_posts
						);
						?>
					</div>
					<div class="hbl-v2-pagination-controls">
						<?php
						// Previous button
						if ( $paged > 1 ) :
						?>
							<a href="#" data-page="<?php echo esc_attr( $paged - 1 ); ?>" class="hbl-v2-page-btn hbl-v2-prev-btn hbl-v2-page-link">
								<i class="bi bi-chevron-left"></i>
								<span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-prev-btn disabled">
								<i class="bi bi-chevron-left"></i>
								<span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
							</span>
						<?php endif; ?>
						
						<!-- Page Numbers -->
						<div class="hbl-v2-page-numbers">
							<?php
							$range = 2;
							$show_dots = false;
							
							for ( $i = 1; $i <= $query->max_num_pages; $i++ ) :
								if ( $i === 1 || $i === $query->max_num_pages || ( $i >= $paged - $range && $i <= $paged + $range ) ) :
									$is_current = ( $i === $paged );
							?>
								<a href="#" data-page="<?php echo esc_attr( $i ); ?>" 
								   class="hbl-v2-page-number hbl-v2-page-link <?php echo $is_current ? 'active' : ''; ?>">
									<?php echo esc_html( $i ); ?>
								</a>
							<?php
									$show_dots = true;
								elseif ( $show_dots ) :
							?>
								<span class="hbl-v2-page-dots">...</span>
							<?php
									$show_dots = false;
								endif;
							endfor;
							?>
						</div>
						
						<?php
						// Next button
						if ( $paged < $query->max_num_pages ) :
						?>
							<a href="#" data-page="<?php echo esc_attr( $paged + 1 ); ?>" class="hbl-v2-page-btn hbl-v2-next-btn hbl-v2-page-link">
								<span><?php esc_html_e( 'Next', 'hbl' ); ?></span>
								<i class="bi bi-chevron-right"></i>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-next-btn disabled">
								<span><?php esc_html_e( 'Next', 'hbl' ); ?></span>
								<i class="bi bi-chevron-right"></i>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		
		<script>
		jQuery(document).ready(function($) {
			var widgetId = '<?php echo esc_js( $widget_id ); ?>';
			var $widget = $('[data-widget-id="' + widgetId + '"]');
			var widgetSettingsJSON = $widget.attr('data-widget-settings') || '{}';
			var currentFilters = {
				keyword: '',
				category: 0,
				tag: 0,
				letter: '<?php echo esc_js( $letter ); ?>',
				sort: 'recommended',
				paged: 1
			};
			
			// If page loaded with a letter filter in URL, show the active tag immediately
			if (currentFilters.letter !== '') {
				var $container = $widget.find('.hbl-v2-active-filters-container');
				var $tag = $('<div class="hbl-v2-active-filter-tag" data-filter-type="letter"></div>');
				$tag.append('<span class="hbl-v2-active-filter-tag-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7V17M4 7L12 2L20 7V17L12 22L4 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>');
				$tag.append('<span class="hbl-v2-active-filter-tag-text">' + currentFilters.letter + '</span>');
				$tag.append('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Clear filter"><svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 3L3 9M3 3L9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>');
				$container.append($tag);
				$widget.find('.hbl-v2-active-filters').show();
			}

			// A-Z Letter filter click
			$widget.find('.hbl-v2-alphabetical-filter').on('click', '.hbl-v2-letter-btn', function(e) {
				e.preventDefault();
				var letter = $(this).data('letter');
				// Toggle: clicking active letter deselects it
				if ($(this).hasClass('active')) {
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					currentFilters.letter = '';
				} else {
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					$(this).addClass('active');
					currentFilters.letter = letter;
				}
				currentFilters.paged = 1;
				applyFilters();
			});

			// View toggle functionality
			$widget.find('.hbl-v2-view-btn').on('click', function() {
				var view = $(this).data('view');
				var $grid = $widget.find('.hbl-v2-listings-grid');
				
				$(this).addClass('active').siblings().removeClass('active');
				
				if (view === 'list') {
					$grid.addClass('list-view');
				} else {
					$grid.removeClass('list-view');
				}
			});
			
			// Keyword search
			var searchTimeout;
			$widget.find('.hbl-v2-keyword-search-input').on('input', function() {
				var $input = $(this);
				var keyword = $input.val().trim();
				
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					currentFilters.keyword = keyword;
					currentFilters.paged = 1;
					
					if (keyword) {
						$widget.find('.hbl-v2-keyword-clear').show();
					} else {
						$widget.find('.hbl-v2-keyword-clear').hide();
					}
					
					applyFilters();
				}, 500);
			});
			
			// Clear keyword search
			$widget.find('.hbl-v2-keyword-clear').on('click', function() {
				$widget.find('.hbl-v2-keyword-search-input').val('');
				currentFilters.keyword = '';
				currentFilters.paged = 1;
				$(this).hide();
				applyFilters();
			});
			
			// Popular search buttons click (visible ones)
			$widget.find('.hbl-v2-popular-search-btn').on('click', function() {
				// Remove active from all buttons
				$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
				// Add active to clicked button
				$(this).addClass('active');
				
				// Reset category when tag is selected to avoid conflict if needed, or allow both
				// For this requirement, popular searches are tags
				currentFilters.tag = parseInt($(this).data('tag')) || 0;
				// currentFilters.category = 0; // Uncomment if tags and categories are mutually exclusive main filters
				currentFilters.paged = 1;
				applyFilters();
			});
			
			// More Filters dropdown toggle
			$widget.find('.hbl-v2-more-filters-trigger').on('click', function() {
				var $dropdown = $widget.find('.hbl-v2-more-filters-dropdown');
				var isOpen = $(this).attr('aria-expanded') === 'true';
				
				if (isOpen) {
					$dropdown.slideUp(200);
					$(this).attr('aria-expanded', 'false');
					$dropdown.attr('aria-hidden', 'true');
				} else {
					$dropdown.slideDown(200);
					$(this).attr('aria-expanded', 'true');
					$dropdown.attr('aria-hidden', 'false');
				}
			});
			
			// More Filters item click (hidden searches - now tags)
			$widget.find('.hbl-v2-more-filter-item').on('click', function() {
				// We don't remove active from tag buttons since they are separate filters now
				// $widget.find('.hbl-v2-popular-search-btn').removeClass('active');
				
				currentFilters.tag = parseInt($(this).data('tag')) || 0;
				currentFilters.paged = 1;
				
				// Close dropdown
				$widget.find('.hbl-v2-more-filters-dropdown').slideUp(200);
				$widget.find('.hbl-v2-more-filters-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-more-filters-dropdown').attr('aria-hidden', 'true');
				
				// Update trigger label to show selected filter
				var selectedText = $(this).text().trim();
				$widget.find('.hbl-v2-more-filters-label').text(selectedText);
				
				applyFilters();
			});
			
			// Close More Filters dropdown when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-more-filters-wrapper').length) {
					$widget.find('.hbl-v2-more-filters-dropdown').slideUp(200);
					$widget.find('.hbl-v2-more-filters-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-more-filters-dropdown').attr('aria-hidden', 'true');
				}
			});
			
			// Popular Categories dropdown toggle
			$widget.find('.hbl-v2-popular-categories-trigger').on('click', function() {
				var $dropdown = $widget.find('.hbl-v2-popular-categories-dropdown');
				var isOpen = $(this).attr('aria-expanded') === 'true';
				
				if (isOpen) {
					$dropdown.slideUp(200);
					$(this).attr('aria-expanded', 'false');
					$dropdown.attr('aria-hidden', 'true');
				} else {
					$dropdown.slideDown(200);
					$(this).attr('aria-expanded', 'true');
					$dropdown.attr('aria-hidden', 'false');
				}
			});
			
			// Popular Categories item click
			$widget.find('.hbl-v2-popular-category-item').on('click', function() {
				currentFilters.category = parseInt($(this).data('category')) || 0;
				currentFilters.paged = 1;
				
				// Close dropdown
				$widget.find('.hbl-v2-popular-categories-dropdown').slideUp(200);
				$widget.find('.hbl-v2-popular-categories-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-popular-categories-dropdown').attr('aria-hidden', 'true');
				
				// Update trigger label to show selected filter
				var selectedText = $(this).text().trim();
				$widget.find('.hbl-v2-popular-categories-label').text(selectedText);
				
				applyFilters();
			});
			
			// Close Popular Categories dropdown when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-popular-categories-wrapper').length) {
					$widget.find('.hbl-v2-popular-categories-dropdown').slideUp(200);
					$widget.find('.hbl-v2-popular-categories-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-popular-categories-dropdown').attr('aria-hidden', 'true');
				}
			});
			
			// Sort dropdown toggle
			$widget.find('.hbl-v2-sort-trigger').on('click', function() {
				var $dropdown = $widget.find('.hbl-v2-sort-dropdown');
				var isOpen = $(this).attr('aria-expanded') === 'true';
				
				if (isOpen) {
					$dropdown.slideUp(200);
					$(this).attr('aria-expanded', 'false');
					$dropdown.attr('aria-hidden', 'true');
				} else {
					$dropdown.slideDown(200);
					$(this).attr('aria-expanded', 'true');
					$dropdown.attr('aria-hidden', 'false');
				}
			});
			
			// Sort item click
			$widget.find('.hbl-v2-sort-item').on('click', function() {
				currentFilters.sort = $(this).data('value');
				currentFilters.paged = 1;
				
				// Update label
				var selectedText = $(this).text().trim();
				if (currentFilters.sort === 'recommended') {
					selectedText = '<?php esc_html_e( 'Sort By', 'hbl' ); ?>';
				}
				$widget.find('.hbl-v2-sort-label').text(selectedText);
				
				// Close dropdown
				$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
				$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-sort-dropdown').attr('aria-hidden', 'true');
				
				// Update active state
				$widget.find('.hbl-v2-sort-item').removeClass('selected');
				$(this).addClass('selected');
				
				applyFilters();
			});
			
			// Close Sort dropdown when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-sort-wrapper').length) {
					$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
					$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-sort-dropdown').attr('aria-hidden', 'true');
				}
			});

			// Pagination click handler
			$widget.on('click', '.hbl-v2-page-link', function(e) {
				e.preventDefault();
				var page = parseInt($(this).data('page')) || 1;
				currentFilters.paged = page;
				applyFilters();
				
				// Scroll to top of widget
				$('html, body').animate({
					scrollTop: $widget.offset().top - 100
				}, 300);
			});
			
			// Apply filters function
			function applyFilters() {
				$.ajax({
					url: hblV2Ajax.ajaxurl,
					type: 'POST',
					data: {
						action: 'hbl_v2_filter_listings',
						nonce: hblV2Ajax.nonce,
						widget_id: widgetId,
						widget_settings: widgetSettingsJSON,
						keyword: currentFilters.keyword,
						category: currentFilters.category,
						tag: currentFilters.tag,
						letter: currentFilters.letter,
						sort: currentFilters.sort,
						paged: currentFilters.paged,
						per_page: <?php echo absint( $settings['listings_per_page'] ); ?>
					},
					beforeSend: function() {
						$widget.addClass('loading');
					},
					success: function(response) {
						if (response.success) {
							// Update active filters display
							updateActiveFilters(response.active_filters);
							
							// Update listings HTML
							if (response.html) {
								$widget.find('.hbl-v2-left-column').html(response.html.left);
								$widget.find('.hbl-v2-right-column').html(response.html.right);
							}
							
							// Always sync pagination so the count reflects the current filter
							if ( typeof response.pagination_html !== 'undefined' ) {
								var $paginationContainer = $widget.find('.hbl-v2-pagination');
								if ( response.pagination_html ) {
									if ( $paginationContainer.length ) {
										$paginationContainer.replaceWith( response.pagination_html );
									} else {
										$widget.find('.hbl-v2-listings-grid').after( response.pagination_html );
									}
								} else {
									$paginationContainer.remove();
								}
							}
							
						}
					},
					error: function(xhr, status, error) {
						console.error('Filter error:', error);
					},
					complete: function() {
						$widget.removeClass('loading');
					}
				});
			}
			
			// Update active filters display
			function updateActiveFilters(filters) {
				var $container = $widget.find('.hbl-v2-active-filters-container');
				$container.empty();
				
				if (filters && filters.length > 0) {
					filters.forEach(function(filter) {
						var $tag = $('<div class="hbl-v2-active-filter-tag" data-filter-type="' + filter.type + '"></div>');
						$tag.append('<span class="hbl-v2-active-filter-tag-icon">' + filter.icon + '</span>');
						$tag.append('<span class="hbl-v2-active-filter-tag-text">' + filter.label + '</span>');
						$tag.append('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Clear filter"><svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 3L3 9M3 3L9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>');
						$container.append($tag);
					});
					
					$widget.find('.hbl-v2-active-filters').show();
				} else {
					$widget.find('.hbl-v2-active-filters').hide();
				}
			}

			// Event delegation for active filter tag X buttons — survives container rebuilds
			$widget.on('click', '.hbl-v2-active-filter-tag-clear', function(e) {
				e.preventDefault();
				var type = $(this).closest('.hbl-v2-active-filter-tag').data('filter-type');
				clearFilter(type);
			});
			
			// Clear individual filter
			function clearFilter(type) {
				switch(type) {
					case 'keyword':
						$widget.find('.hbl-v2-keyword-search-input').val('');
						$widget.find('.hbl-v2-keyword-clear').hide();
						currentFilters.keyword = '';
						break;
					case 'category':
						$widget.find('.hbl-v2-popular-categories-label').text('<?php esc_html_e( 'Search Categories', 'hbl' ); ?>');
						currentFilters.category = 0;
						break;
					case 'tag':
						$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
						currentFilters.tag = 0;
						break;
					case 'letter':
						$widget.find('.hbl-v2-letter-btn').removeClass('active');
						currentFilters.letter = '';
						break;
					case 'sort':
						$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
						$widget.find('.hbl-v2-sort-item').removeClass('selected');
						currentFilters.sort = 'recommended';
						break;
				}
				currentFilters.paged = 1;
				applyFilters();
			}
			
			// Star Rating Click Handler for Review Forms
			$(document).on('click', '.hbl-v2-star-rating i', function() {
				var $starRating = $(this).closest('.hbl-v2-star-rating');
				var rating = parseInt($(this).data('value')) || 0;
				
				// Update hidden input value
				$starRating.find('input[name="review_rating"]').val(rating);
				$starRating.attr('data-rating', rating);
				
				// Update star visuals
				$starRating.find('i').each(function() {
					var starValue = parseInt($(this).data('value')) || 0;
					if (starValue <= rating) {
						$(this).removeClass('bi-star').addClass('bi-star-fill active');
					} else {
						$(this).removeClass('bi-star-fill active').addClass('bi-star');
					}
				});
			});
			
			// Star Rating Hover Effect
			$(document).on('mouseenter', '.hbl-v2-star-rating i', function() {
				var $starRating = $(this).closest('.hbl-v2-star-rating');
				var hoverValue = parseInt($(this).data('value')) || 0;
				
				$starRating.find('i').each(function() {
					var starValue = parseInt($(this).data('value')) || 0;
					if (starValue <= hoverValue) {
						$(this).removeClass('bi-star').addClass('bi-star-fill');
					} else {
						$(this).removeClass('bi-star-fill').addClass('bi-star');
					}
				});
			});
			
			// Star Rating Mouse Leave - Restore to selected rating
			$(document).on('mouseleave', '.hbl-v2-star-rating', function() {
				var $starRating = $(this);
				var currentRating = parseInt($starRating.attr('data-rating')) || 0;
				
				$starRating.find('i').each(function() {
					var starValue = parseInt($(this).data('value')) || 0;
					if (starValue <= currentRating) {
						$(this).removeClass('bi-star').addClass('bi-star-fill active');
					} else {
						$(this).removeClass('bi-star-fill active').addClass('bi-star');
					}
				});
			});
			
			// Robust Native Event Listener for Card Interaction (Capture Phase)
			function hblInitMap(mapContainer) {
			if (!mapContainer || mapContainer.dataset.initialized) return;
			var lat = mapContainer.dataset.lat;
			var lng = mapContainer.dataset.lng;
			var title = mapContainer.dataset.title;
			if (lat && lng && typeof L !== 'undefined') {
				try {
					mapContainer.innerHTML = '';
					var map = L.map(mapContainer, { zoomControl: true, scrollWheelZoom: true, dragging: true, attributionControl: false }).setView([parseFloat(lat), parseFloat(lng)], 15);
					L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
					L.marker([parseFloat(lat), parseFloat(lng)]).addTo(map).bindPopup('<strong>' + title + '</strong>');
					mapContainer.dataset.initialized = 'true';
					mapContainer._hblMap = map;
				} catch (err) {}
			}
		}

		document.addEventListener('mouseenter', function(e) {
			var card = e.target.closest('.hbl-v2-listing-card');
			if (!card) return;
			hblInitMap(card.querySelector('.hbl-v2-quick-map'));
		}, true);

		document.addEventListener('click', function(e) {
			var card = e.target.closest('.hbl-v2-listing-card');
			if (!card) return;
			if (e.target.closest('a, button, input, select, textarea, .hbl-v2-favorite-btn, .hbl-v2-expand-trigger, .leaflet-container')) return;
			var isMapClick = !!e.target.closest('.hbl-v2-quick-map');
			if (!isMapClick && card.classList.contains('hbl-expandable')) { card.classList.toggle('expanded'); }
			var mapContainer = card.querySelector('.hbl-v2-quick-map');
			hblInitMap(mapContainer);
			if (mapContainer && mapContainer._hblMap) {
				setTimeout(function() { mapContainer._hblMap.invalidateSize(); }, 50);
				setTimeout(function() { mapContainer._hblMap.invalidateSize(); }, 300);
			}
		}, true);
		});
		</script>
		<?php
	}
}
