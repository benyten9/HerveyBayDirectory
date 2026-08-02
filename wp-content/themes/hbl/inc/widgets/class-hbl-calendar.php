<?php
/**
 * HBL Calendar Widget
 *
 * Calendar widget that displays events from custom HBL Events database
 * Matches Figma design specifications for Hervey Bay Directory
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Background;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class HBL_Calendar extends Widget_Base {

	/**
	 * Day labels for recurrence display
	 */
	private $day_labels = array(
		'mon' => 'Monday',
		'tue' => 'Tuesday',
		'wed' => 'Wednesday',
		'thu' => 'Thursday',
		'fri' => 'Friday',
		'sat' => 'Saturday',
		'sun' => 'Sunday',
	);

	/**
	 * Week ordinal labels for recurrence display
	 */
	private $week_labels = array(
		'1' => '1st',
		'2' => '2nd',
		'3' => '3rd',
		'4' => '4th',
	);

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-calendar';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'Calendar', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-calendar';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories() {
		return array( 'hbl' );
	}

	/**
	 * Check if HBL Events DB is available
	 */
	private function is_events_db_active() {
		return function_exists( 'hbl_events_db' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {

		// Build event tag options (slug => name) for SELECT2 / SELECT controls
		$_tag_opts = array();
		$_raw_tags = get_terms( array(
			'taxonomy'   => 'event_tag',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => 200,
		) );
		if ( ! is_wp_error( $_raw_tags ) && ! empty( $_raw_tags ) ) {
			foreach ( $_raw_tags as $_t ) {
				$_tag_opts[ $_t->slug ] = $_t->name;
			}
		}

		// ========== CONTENT SECTION ==========
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Content', 'hbl' ),
			)
		);

		$this->add_control(
			'main_title',
			array(
				'label'       => esc_html__( 'Main Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( "What's On", 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'section_title',
			array(
				'label'       => esc_html__( 'Section Title', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( "What's On for Today", 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'events_per_date',
			array(
				'label'       => esc_html__( 'Events per Date', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 12,
				'min'         => 1,
				'max'         => 50,
				'description' => esc_html__( 'Number of events to display per selected date', 'hbl' ),
			)
		);

		$this->add_control(
			'month_title_prefix',
			array(
				'label'       => esc_html__( 'Month Title Prefix', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Events for', 'hbl' ),
				'label_block' => true,
				'description' => esc_html__( 'Text that appears before the month/year (e.g., "Events for")', 'hbl' ),
			)
		);

		$this->add_control(
			'month_description',
			array(
				'label'       => esc_html__( 'Description Below Month Title', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => esc_html__( "Here's what's on this week across the Fraser Coast. Filter by day to plan your visit.", 'hbl' ),
				'label_block' => true,
				'rows'        => 3,
				'description' => esc_html__( 'Description text that appears below the month title', 'hbl' ),
			)
		);

		$this->add_control(
			'no_events_message',
			array(
				'label'       => esc_html__( 'No Events Message', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => esc_html__( 'No events listed for this date. Please check back soon or explore our full calendar.', 'hbl' ),
				'label_block' => true,
				'rows'        => 3,
				'description' => esc_html__( 'Message shown when no events are found for selected date', 'hbl' ),
			)
		);

		$this->add_control(
			'event_card_read_more',
			array(
				'label'       => esc_html__( 'Event Card "Read More" Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Read More', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'event_excerpt_length',
			array(
				'label'       => esc_html__( 'Event Card Excerpt Length (words)', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 30,
				'min'         => 5,
				'max'         => 100,
				'description' => esc_html__( 'Number of words to display in event card excerpts', 'hbl' ),
			)
		);

		$this->add_control(
			'featured_event_description_length',
			array(
				'label'       => esc_html__( 'Featured Event Description Length (words)', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 25,
				'min'         => 5,
				'max'         => 100,
				'description' => esc_html__( 'Number of words to display in featured event descriptions', 'hbl' ),
			)
		);

		$this->add_control(
			'placeholder_image',
			array(
				'label'       => esc_html__( 'Placeholder Image', 'hbl' ),
				'type'        => Controls_Manager::MEDIA,
				'default'     => array(
					'url' => get_template_directory_uri() . '/assets/images/placeholder-event.jpg',
				),
				'description' => esc_html__( 'Image shown when events don\'t have a featured image', 'hbl' ),
			)
		);

		$this->add_control(
			'single_event_page',
			array(
				'label'       => esc_html__( 'Single Event Page URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'default'     => array(
					'url' => '/events/',
				),
				'label_block' => true,
				'description' => esc_html__( 'Base URL for events. Event slug will be appended as /events/event-slug/', 'hbl' ),
			)
		);

		$this->add_control(
			'featured_events_heading',
			array(
				'label'     => esc_html__( 'Featured Events', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'featured_event_1',
			array(
				'label'       => esc_html__( 'Featured Event 1 ID', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'label_block' => true,
				'description' => esc_html__( 'Enter the Event ID to feature', 'hbl' ),
			)
		);

		$this->add_control(
			'featured_event_1_cta_text',
			array(
				'label'       => esc_html__( 'Featured Event 1 CTA Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Learn More', 'hbl' ),
				'label_block' => true,
				'condition'   => array(
					'featured_event_1!' => '',
				),
			)
		);

		$this->add_control(
			'featured_event_2',
			array(
				'label'       => esc_html__( 'Featured Event 2 ID', 'hbl' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => '',
				'label_block' => true,
				'description' => esc_html__( 'Enter the Event ID to feature', 'hbl' ),
			)
		);

		$this->add_control(
			'featured_event_2_cta_text',
			array(
				'label'       => esc_html__( 'Featured Event 2 CTA Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Learn More', 'hbl' ),
				'label_block' => true,
				'condition'   => array(
					'featured_event_2!' => '',
				),
			)
		);

		$this->end_controls_section();

		// ========== FILTERS SECTION ==========
		$this->start_controls_section(
			'section_filters',
			array(
				'label' => esc_html__( 'Filters Bar', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_featured_tags',
			array(
				'label'        => esc_html__( 'Show Featured Tags', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);
		
		$featured_tags_repeater = new \Elementor\Repeater();
		$featured_tags_repeater->add_control(
			'featured_tag',
			array(
				'label'       => esc_html__( 'Tag', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $_tag_opts,
				'label_block' => true,
			)
		);
		$featured_tags_repeater->add_control(
			'featured_tag_label',
			array(
				'label'       => esc_html__( 'Display Label (optional)', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'Leave empty to use tag name', 'hbl' ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'featured_tags_items',
			array(
				'label'       => esc_html__( 'Tags to Display', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $featured_tags_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '<# print( featured_tag_label ? featured_tag_label : featured_tag ) #>',
				'condition'   => array(
					'show_featured_tags' => 'yes',
				),
			)
		);
		
		$this->add_control(
			'show_az_filters',
			array(
				'label'        => esc_html__( 'Show A-Z Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_keyword_search',
			array(
				'label'        => esc_html__( 'Show Keyword Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_category_dropdown',
			array(
				'label'        => esc_html__( 'Show Category Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sort_filter',
			array(
				'label'        => esc_html__( 'Show Sort Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
			)
		);

		$more_filters_repeater = new \Elementor\Repeater();
		$more_filters_repeater->add_control(
			'filter_tag',
			array(
				'label'       => esc_html__( 'Tag', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $_tag_opts,
				'label_block' => true,
			)
		);
		$more_filters_repeater->add_control(
			'filter_label',
			array(
				'label'       => esc_html__( 'Display Label (optional)', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => esc_html__( 'Leave empty to use tag name', 'hbl' ),
				'label_block' => true,
			)
		);
		$this->add_control(
			'more_filters',
			array(
				'label'       => esc_html__( 'More Filters', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $more_filters_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '<# print( filter_label ? filter_label : filter_tag ) #>',
				'condition'   => array(
					'show_more_filters' => 'yes',
				),
			)
		);

		$this->add_control(
			'list_event_url',
			array(
				'label'       => esc_html__( 'List Your Event CTA URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://...', 'hbl' ),
				'default'     => array(
					'url' => home_url( '/add-event/' ),
				),
			)
		);
		
		$this->add_control(
			'list_event_text',
			array(
				'label'       => esc_html__( 'CTA Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'List Your Event', 'hbl' ),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CONTAINER ==========
		$this->start_controls_section(
			'section_container_style',
			array(
				'label' => esc_html__( 'Container', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HEADINGS ==========
		$this->start_controls_section(
			'section_headings_style',
			array(
				'label' => esc_html__( 'Headings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'main_title_heading',
			array(
				'label'     => esc_html__( 'Main Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'main_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-main-title',
			)
		);

		$this->add_control(
			'main_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-main-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'section_title_heading',
			array(
				'label'     => esc_html__( 'Section Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'section_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-section-title',
			)
		);

		$this->add_control(
			'section_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-section-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'month_title_heading',
			array(
				'label'     => esc_html__( 'Month Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'month_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-month-title',
			)
		);

		$this->add_control(
			'month_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-month-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'month_description_heading',
			array(
				'label'     => esc_html__( 'Month Description', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'month_description_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-month-description',
			)
		);

		$this->add_control(
			'month_description_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-month-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'month_description_margin',
			array(
				'label'      => esc_html__( 'Margin', 'hbl' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-calendar-month-description' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'selected_date_heading',
			array(
				'label'     => esc_html__( 'Selected Date', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'selected_date_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-selected-date',
			)
		);

		$this->add_control(
			'selected_date_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#444444',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-selected-date' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_count_heading',
			array(
				'label'     => esc_html__( 'Event Count', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'event_count_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-event-count',
			)
		);

		$this->add_control(
			'event_count_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-count' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CALENDAR ==========
		$this->start_controls_section(
			'section_calendar_style',
			array(
				'label' => esc_html__( 'Calendar', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'calendar_background',
				'label'    => esc_html__( 'Calendar Grid Background', 'hbl' ),
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-grid',
			)
		);

		$this->add_control(
			'calendar_background_opacity',
			array(
				'label'      => esc_html__( 'Background Overlay Opacity', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min'  => 0,
						'max'  => 1,
						'step' => 0.1,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 0.5,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-calendar-grid::before' => 'opacity: {{SIZE}};',
				),
			)
		);

		$this->add_control(
			'weekday_background',
			array(
				'label'     => esc_html__( 'Weekday Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-weekday' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'weekday_color',
			array(
				'label'     => esc_html__( 'Weekday Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-weekday' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'weekday_typography',
				'label'    => esc_html__( 'Weekday Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-weekday',
			)
		);

		$this->add_control(
			'date_color',
			array(
				'label'     => esc_html__( 'Date Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-date' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'date_typography',
				'label'    => esc_html__( 'Date Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-date',
			)
		);

		$this->add_control(
			'date_hover_color',
			array(
				'label'     => esc_html__( 'Date Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-date:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'date_active_color',
			array(
				'label'     => esc_html__( 'Active Date Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-date.active' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'date_border_color',
			array(
				'label'     => esc_html__( 'Date Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-date' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'navigation_heading',
			array(
				'label'     => esc_html__( 'Navigation Buttons', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'navigation_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-nav-btn',
			)
		);

		$this->add_control(
			'navigation_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-nav-btn' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-calendar-nav-btn svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'navigation_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-nav-btn:hover' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-calendar-nav-btn:hover svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: EVENTS ==========
		$this->start_controls_section(
			'section_events_style',
			array(
				'label' => esc_html__( 'Events', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'event_card_background',
			array(
				'label'     => esc_html__( 'Event Card Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-card' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_card_border_color',
			array(
				'label'     => esc_html__( 'Event Card Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-card' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_title_heading',
			array(
				'label'     => esc_html__( 'Event Card Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'event_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-event-title',
			)
		);

		$this->add_control(
			'event_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_excerpt_heading',
			array(
				'label'     => esc_html__( 'Event Card Excerpt', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'event_excerpt_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-event-excerpt',
			)
		);

		$this->add_control(
			'event_excerpt_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-excerpt' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_link_heading',
			array(
				'label'     => esc_html__( 'Event Card Link', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'event_link_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-event-link',
			)
		);

		$this->add_control(
			'event_link_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-link' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-calendar-event-link svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'event_link_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-event-link:hover' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-calendar-event-link:hover svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: DIVIDER ==========
		$this->start_controls_section(
			'section_divider_style',
			array(
				'label' => esc_html__( 'Divider', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'divider_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.5)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-divider' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'divider_height',
			array(
				'label'      => esc_html__( 'Height', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 10,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-calendar-divider' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: FEATURED EVENTS ==========
		$this->start_controls_section(
			'section_featured_events_style',
			array(
				'label' => esc_html__( 'Featured Events', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'featured_event_title_heading',
			array(
				'label'     => esc_html__( 'Featured Event Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'featured_event_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-featured-event-title',
			)
		);

		$this->add_control(
			'featured_event_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_description_heading',
			array(
				'label'     => esc_html__( 'Featured Event Description', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'featured_event_description_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-featured-event-description',
			)
		);

		$this->add_control(
			'featured_event_description_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_cta_heading',
			array(
				'label'     => esc_html__( 'Featured Event CTA Button', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'featured_event_cta_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-calendar-featured-event-cta',
			)
		);

		$this->add_control(
			'featured_event_cta_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-cta' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_cta_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-cta' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_cta_hover_color',
			array(
				'label'     => esc_html__( 'Hover Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-cta:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_cta_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-cta:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'featured_event_overlay_heading',
			array(
				'label'     => esc_html__( 'Background Overlay', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'featured_event_overlay_color',
			array(
				'label'     => esc_html__( 'Overlay Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(25, 25, 25, 0.63)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-calendar-featured-event-overlay' => 'background-color: {{VALUE}};',
				),
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
			'resp_vis_heading_top',
			array(
				'label' => esc_html__( 'Top Section', 'hbl' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_responsive_control(
			'hide_main_title',
			array(
				'label'        => esc_html__( 'Hide Main Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-main-title' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_month_title',
			array(
				'label'        => esc_html__( 'Hide Month Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-month-title' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_month_description_resp',
			array(
				'label'        => esc_html__( 'Hide Month Description', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-month-description' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'resp_vis_heading_calendar',
			array(
				'label'     => esc_html__( 'Calendar', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'hide_calendar_grid',
			array(
				'label'        => esc_html__( 'Hide Calendar Grid', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-left-section' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_calendar_navigation',
			array(
				'label'        => esc_html__( 'Hide Calendar Navigation', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-navigation' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_prev_month',
			array(
				'label'        => esc_html__( 'Hide See Previous Month', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-month-helper.hbl-calendar-prev' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_next_month',
			array(
				'label'        => esc_html__( 'Hide See Next Month', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-month-helper.hbl-calendar-next' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_featured_events',
			array(
				'label'        => esc_html__( 'Hide Featured Events', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-featured-events' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_divider',
			array(
				'label'        => esc_html__( 'Hide Divider', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-divider' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'resp_vis_heading_filters',
			array(
				'label'     => esc_html__( 'Filters', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'hide_featured_tags_resp',
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
			'hide_filters_bar',
			array(
				'label'        => esc_html__( 'Hide Entire Filters Bar', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-filters-section' => 'display: {{VALUE}} !important;',
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
					'{{WRAPPER}} .hbl-calendar-keyword-search' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_category_dropdown_resp',
			array(
				'label'        => esc_html__( 'Hide Category Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-category-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_sort_filter_resp',
			array(
				'label'        => esc_html__( 'Hide Sort Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-sort-wrapper' => 'display: {{VALUE}} !important;',
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
					'{{WRAPPER}} .hbl-calendar-more-filters-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_list_event_cta',
			array(
				'label'        => esc_html__( 'Hide List Event CTA', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-v2-filters-right' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_control(
			'resp_vis_heading_events',
			array(
				'label'     => esc_html__( 'Events Section', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'hide_results_header',
			array(
				'label'        => esc_html__( 'Hide Results Header', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-results-header' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_section_title_resp',
			array(
				'label'        => esc_html__( 'Hide Section Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-section-title' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_selected_date',
			array(
				'label'        => esc_html__( 'Hide Selected Date', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-selected-date' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_event_count',
			array(
				'label'        => esc_html__( 'Hide Event Count', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-event-count' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_events_section',
			array(
				'label'        => esc_html__( 'Hide Events Section', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .hbl-calendar-events-section' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_active_filters',
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
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		
		// Check if HBL Events DB is available
		if ( ! $this->is_events_db_active() ) {
			echo '<div class="hbl-calendar-widget"><p>' . esc_html__( 'HBL Events system is required for this widget to work.', 'hbl' ) . '</p></div>';
			return;
		}

		// Get current date (today)
		$today = current_time( 'Y-m-d' );
		$selected_date = $today;
		
		// Use today's month/year for the calendar display
		$display_month = absint( date( 'n', strtotime( $today ) ) );
		$display_year = absint( date( 'Y', strtotime( $today ) ) );

		// Get events for the display month
		$events = $this->get_month_events( $display_year, $display_month );

		// Get calendar data for the display month
		$calendar_data = $this->get_calendar_data( $display_year, $display_month, $events );

		// Get events for today
		$events_result = $this->get_events_for_date( $today, $settings['events_per_date'] );
		$events_for_date = $events_result['events'];
		$total_events = $events_result['total'];
		$total_pages = ceil( $total_events / $settings['events_per_date'] );

		// Keep current_date for "today" checks in templates
		$current_date = $today;
		$current_month = $display_month;
		$current_year = $display_year;

		// Event page URL - not needed anymore as we use hbl_events_db()->get_event_url()
		// Keeping for backwards compatibility but it won't be used
		$event_page_url = home_url( '/events/' );

		?>
		<div class="hbl-calendar-widget" data-year="<?php echo esc_attr( $display_year ); ?>" data-month="<?php echo esc_attr( $display_month ); ?>" data-read-more="<?php echo esc_attr( $settings['event_card_read_more'] ); ?>" data-no-events-message="<?php echo esc_attr( $settings['no_events_message'] ); ?>" data-events-per-date="<?php echo esc_attr( $settings['events_per_date'] ); ?>">
			<div class="hbl-calendar-top-section">
				<?php if ( ! empty( $settings['main_title'] ) ) : ?>
					<h2 class="hbl-calendar-main-title"><?php echo esc_html( $settings['main_title'] ); ?></h2>
				<?php endif; ?>
				
				<h3 class="hbl-calendar-month-title"><?php echo esc_html( $settings['month_title_prefix'] ); ?> <?php echo esc_html( date( 'F Y', strtotime( $current_year . '-' . $current_month . '-01' ) ) ); ?></h3>
				
				<?php if ( ! empty( $settings['month_description'] ) ) : ?>
					<p class="hbl-calendar-month-description"><?php echo esc_html( $settings['month_description'] ); ?></p>
				<?php endif; ?>
				
				<div class="hbl-calendar-wrapper">
					<div class="hbl-calendar-left-section">
						<div class="hbl-calendar-grid">
							<div class="hbl-calendar-weekdays">
								<div class="hbl-calendar-weekday">MONDAY</div>
								<div class="hbl-calendar-weekday">TUESDAY</div>
								<div class="hbl-calendar-weekday">WEDNESDAY</div>
								<div class="hbl-calendar-weekday">THURSDAY</div>
								<div class="hbl-calendar-weekday">FRIDAY</div>
								<div class="hbl-calendar-weekday">SATURDAY</div>
								<div class="hbl-calendar-weekday">SUNDAY</div>
							</div>
							
							<div class="hbl-calendar-dates">
								<?php foreach ( $calendar_data['weeks'] as $week ) : ?>
									<div class="hbl-calendar-week">
										<?php foreach ( $week as $day ) : ?>
											<?php
											$day_num = $day['day'];
											$is_current_month = $day['is_current_month'];
											$is_today = $day['is_today'];
											$has_events = $day['has_events'];
											$date_string = $day['date_string'];
											$is_selected = ( $date_string === $selected_date );
											?>
											<div class="hbl-calendar-date-wrapper <?php echo ! $is_current_month ? 'other-month' : ''; ?>">
												<?php if ( $is_current_month && $day_num ) : ?>
													<button 
														type="button" 
														class="hbl-calendar-date <?php echo $is_today ? 'today' : ''; ?> <?php echo $is_selected ? 'active' : ''; ?> <?php echo $has_events ? 'has-events' : ''; ?>"
														data-date="<?php echo esc_attr( $date_string ); ?>"
														data-year="<?php echo esc_attr( $day['year'] ); ?>"
														data-month="<?php echo esc_attr( $day['month'] ); ?>"
														data-day="<?php echo esc_attr( $day_num ); ?>"
													>
														<?php echo esc_html( $day_num ); ?>
													</button>
												<?php else : ?>
													<div class="hbl-calendar-date empty"></div>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
						
						<div class="hbl-calendar-navigation">
							<!-- Row 1: Prev month | Current month | Next month -->
							<div class="hbl-calendar-month-nav-row">
								<button type="button" class="hbl-calendar-nav-btn hbl-calendar-prev" data-direction="prev">
									<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M4 2L2 4L4 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php echo esc_html( date( 'F', strtotime( '-1 month', strtotime( $current_year . '-' . $current_month . '-01' ) ) ) ); ?></span>
								</button>

								<span class="hbl-calendar-current-month-label">
									<?php echo esc_html( date( 'F', strtotime( $current_year . '-' . $current_month . '-01' ) ) ); ?>
								</span>

								<button type="button" class="hbl-calendar-nav-btn hbl-calendar-next" data-direction="next">
									<span><?php echo esc_html( date( 'F', strtotime( '+1 month', strtotime( $current_year . '-' . $current_month . '-01' ) ) ) ); ?></span>
									<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M4 2L6 4L4 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</button>
							</div>

							<!-- Row 2: Helper label | < Today > | Helper label -->
							<?php
							// Calculate previous and next day dates for day navigation
							$prev_day = date( 'Y-m-d', strtotime( $selected_date . ' -1 day' ) );
							$next_day = date( 'Y-m-d', strtotime( $selected_date . ' +1 day' ) );
							?>
							<div class="hbl-calendar-day-nav-row">
								<button type="button" class="hbl-calendar-nav-btn hbl-calendar-month-helper hbl-calendar-prev" data-direction="prev">
									<?php esc_html_e( 'SEE PREVIOUS MONTH', 'hbl' ); ?>
								</button>

								<div class="hbl-calendar-day-navigation">
									<button type="button" class="hbl-calendar-day-nav-btn hbl-calendar-day-prev" data-date="<?php echo esc_attr( $prev_day ); ?>" data-year="<?php echo esc_attr( date( 'Y', strtotime( $prev_day ) ) ); ?>" data-month="<?php echo esc_attr( date( 'n', strtotime( $prev_day ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous day', 'hbl' ); ?>">
										<svg width="10" height="16" viewBox="0 0 10 16" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M8 2L2 8L8 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</button>

									<button type="button" class="hbl-calendar-today-btn" data-today-year="<?php echo esc_attr( date( 'Y' ) ); ?>" data-today-month="<?php echo esc_attr( date( 'n' ) ); ?>" data-today-date="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
											<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
											<circle cx="12" cy="16" r="2" fill="currentColor"/>
										</svg>
										<span><?php esc_html_e( 'Today', 'hbl' ); ?></span>
									</button>

									<button type="button" class="hbl-calendar-day-nav-btn hbl-calendar-day-next" data-date="<?php echo esc_attr( $next_day ); ?>" data-year="<?php echo esc_attr( date( 'Y', strtotime( $next_day ) ) ); ?>" data-month="<?php echo esc_attr( date( 'n', strtotime( $next_day ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next day', 'hbl' ); ?>">
										<svg width="10" height="16" viewBox="0 0 10 16" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M2 2L8 8L2 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</button>
								</div>

								<button type="button" class="hbl-calendar-nav-btn hbl-calendar-month-helper hbl-calendar-next" data-direction="next">
									<?php esc_html_e( 'SEE NEXT MONTH', 'hbl' ); ?>
								</button>
							</div>
						</div>
					</div>
					
					<div class="hbl-calendar-featured-events">
						<?php
						// Display Featured Event 1
						if ( ! empty( $settings['featured_event_1'] ) ) {
							$featured_event_1 = hbl_events_db()->get( absint( $settings['featured_event_1'] ) );
							if ( $featured_event_1 ) {
								$this->render_featured_event( $featured_event_1, $settings['featured_event_1_cta_text'], $settings['featured_event_description_length'], $settings['placeholder_image'], $event_page_url );
							}
						}
						
						// Display Featured Event 2
						if ( ! empty( $settings['featured_event_2'] ) ) {
							$featured_event_2 = hbl_events_db()->get( absint( $settings['featured_event_2'] ) );
							if ( $featured_event_2 ) {
								$this->render_featured_event( $featured_event_2, $settings['featured_event_2_cta_text'], $settings['featured_event_description_length'], $settings['placeholder_image'], $event_page_url );
							}
						}
						?>
					</div>
				</div>
			</div>
			
			<div class="hbl-calendar-divider"></div>

			<?php if ( isset( $settings['show_featured_tags'] ) && 'yes' === $settings['show_featured_tags'] && ! empty( $settings['featured_tags_items'] ) ) : ?>
				<div class="hbl-v2-popular-searches-section">
					<div class="hbl-v2-popular-searches-buttons">
						<?php foreach ( $settings['featured_tags_items'] as $_ft_item ) :
							$_ft_slug  = ! empty( $_ft_item['featured_tag'] ) ? $_ft_item['featured_tag'] : '';
							if ( empty( $_ft_slug ) ) { continue; }
							$_ft_label = ! empty( $_ft_item['featured_tag_label'] ) ? $_ft_item['featured_tag_label'] : '';
							if ( empty( $_ft_label ) ) {
								$_ft_obj   = get_term_by( 'slug', $_ft_slug, 'event_tag' );
								$_ft_label = $_ft_obj ? $_ft_obj->name : $_ft_slug;
							}
							?>
							<button type="button" class="hbl-v2-popular-search-btn" data-tag="<?php echo esc_attr( $_ft_slug ); ?>">
								<?php echo esc_html( $_ft_label ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="hbl-calendar-filters-section hbl-v2-widget">

				<?php if ( isset( $settings['show_az_filters'] ) && 'yes' === $settings['show_az_filters'] ) : ?>
					<div class="hbl-v2-alphabetical-filter">
						<div class="hbl-v2-filter-buttons">
							<?php foreach ( range( 'A', 'Z' ) as $alpha_letter ) : ?>
								<a href="#" class="hbl-v2-letter-btn" data-letter="<?php echo esc_attr( $alpha_letter ); ?>">
									<?php echo esc_html( $alpha_letter ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="hbl-calendar-filters-bar">
					<div class="hbl-calendar-filters-left">
						<span class="hbl-v2-filters-label"><?php esc_html_e( 'Filters', 'hbl' ); ?></span>
						
						<?php if ( isset( $settings['show_keyword_search'] ) && 'yes' === $settings['show_keyword_search'] ) : ?>
							<div class="hbl-v2-keyword-search hbl-calendar-keyword-search">
								<i class="bi bi-search hbl-v2-keyword-search-icon"></i>
								<input type="text" class="hbl-v2-keyword-search-input hbl-calendar-search-input" placeholder="<?php esc_attr_e( 'Search by Keyword...', 'hbl' ); ?>">
							</div>
						<?php endif; ?>

						<?php if ( isset( $settings['show_category_dropdown'] ) && 'yes' === $settings['show_category_dropdown'] ) : ?>
							<div class="hbl-v2-popular-categories-wrapper hbl-calendar-category-wrapper">
								<div class="hbl-v2-popular-categories-container">
									<button type="button" class="hbl-v2-popular-categories-trigger hbl-calendar-filter-trigger" aria-expanded="false">
										<span class="hbl-v2-popular-categories-label hbl-calendar-filter-label"><?php esc_html_e( 'Search Categories', 'hbl' ); ?></span>
										<span class="hbl-v2-popular-categories-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-popular-categories-dropdown hbl-calendar-filter-dropdown" style="display: none;">
									<div class="hbl-v2-popular-categories-list">
										<button type="button" class="hbl-v2-popular-category-item hbl-calendar-filter-item" data-category=""><?php esc_html_e( 'All Categories', 'hbl' ); ?></button>
										<?php
										$categories = get_terms( array(
											'taxonomy'   => 'event_category',
											'hide_empty' => false,
										) );
										if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
											foreach ( $categories as $category ) {
												echo '<button type="button" class="hbl-v2-popular-category-item hbl-calendar-filter-item" data-category="' . esc_attr( $category->slug ) . '">' . esc_html( $category->name ) . '</button>';
											}
										}
										?>
									</div>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( isset( $settings['show_sort_filter'] ) && 'yes' === $settings['show_sort_filter'] ) : ?>
							<div class="hbl-v2-sort-wrapper hbl-v2-filter-dropdown hbl-calendar-sort-wrapper">
								<div class="hbl-v2-sort-container">
									<button type="button" class="hbl-v2-sort-trigger hbl-calendar-filter-trigger" aria-expanded="false">
										<span class="hbl-v2-sort-label hbl-calendar-filter-label"><?php esc_html_e( 'Sort By', 'hbl' ); ?></span>
										<span class="hbl-v2-sort-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-sort-dropdown hbl-calendar-filter-dropdown" style="display: none;">
									<div class="hbl-v2-sort-list">
										<button type="button" class="hbl-v2-sort-item hbl-calendar-filter-item" data-sort="recommended" role="option"><?php esc_html_e( 'Recommended', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item hbl-calendar-filter-item" data-sort="a-z" role="option"><?php esc_html_e( 'A–Z', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item hbl-calendar-filter-item" data-sort="z-a" role="option"><?php esc_html_e( 'Z–A', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item hbl-calendar-filter-item" data-sort="newest" role="option"><?php esc_html_e( 'Newest', 'hbl' ); ?></button>
									</div>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( isset( $settings['show_more_filters'] ) && 'yes' === $settings['show_more_filters'] && ! empty( $settings['more_filters'] ) ) : ?>
							<div class="hbl-v2-more-filters-wrapper hbl-calendar-more-filters-wrapper">
								<div class="hbl-v2-more-filters-container">
									<button type="button" class="hbl-v2-more-filters-trigger hbl-calendar-filter-trigger" aria-expanded="false">
										<span class="hbl-v2-more-filters-label hbl-calendar-filter-label"><?php esc_html_e( 'More Filters', 'hbl' ); ?></span>
										<span class="hbl-v2-more-filters-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
											</svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-more-filters-dropdown hbl-calendar-filter-dropdown" style="display: none;">
									<div class="hbl-v2-more-filters-list">
									<?php foreach ( $settings['more_filters'] as $filter_item ) :
										$_slug    = ! empty( $filter_item['filter_tag'] ) ? $filter_item['filter_tag'] : '';
										if ( empty( $_slug ) ) { continue; }
										$_display = ! empty( $filter_item['filter_label'] ) ? $filter_item['filter_label'] : '';
										if ( empty( $_display ) ) {
											$_tag_obj = get_term_by( 'slug', $_slug, 'event_tag' );
											$_display = $_tag_obj ? $_tag_obj->name : $_slug;
										}
										?>
										<button type="button" class="hbl-v2-more-filter-item hbl-calendar-filter-item" data-filter="<?php echo esc_attr( $_slug ); ?>">
											<?php echo esc_html( $_display ); ?>
										</button>
									<?php endforeach; ?>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
					
					<div class="hbl-v2-filters-right">
						<?php if ( ! empty( $settings['list_event_text'] ) ) : ?>
							<a href="<?php echo esc_url( $settings['list_event_url']['url'] ?? '#' ); ?>" class="hbl-calendar-featured-event-cta hbl-calendar-featured-event-cta-btn">
								<?php echo esc_html( $settings['list_event_text'] ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			
			<!-- Active Filters Display -->
			<div class="hbl-v2-active-filters" style="display: none;">
				<div class="hbl-v2-active-filters-container"></div>
			</div>
			
			<div class="hbl-calendar-events-section" id="events-list">
				<div class="hbl-calendar-results-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
					<div class="hbl-calendar-header-left" style="flex: 1; text-align: left;">
						<?php if ( ! empty( $settings['section_title'] ) ) : ?>
							<h3 class="hbl-calendar-section-title" style="margin: 0;"><?php echo esc_html( $settings['section_title'] ); ?></h3>
						<?php endif; ?>
					</div>
					<div class="hbl-calendar-selected-date" style="flex: 1; text-align: center;">
						<?php echo date( 'l, F j, Y', current_time('timestamp') ); ?>
					</div>
					<div class="hbl-calendar-event-count" style="flex: 1; text-align: right;">
						<?php printf( _n( '%s Event for today', '%s Events for today', count( $events_for_date ), 'hbl' ), count( $events_for_date ) ); ?>
					</div>
				</div>
			
				<div class="hbl-calendar-events-container">
					<?php if ( ! empty( $events_for_date ) ) : ?>
						<?php foreach ( $events_for_date as $event ) : ?>
							<?php
							$event_id = $event->id;
						$event_title = $event->title;
						$event_excerpt = wp_trim_words( $event->description, $settings['event_excerpt_length'] );
						// Get event URL
				$event_url = hbl_events_db()->get_event_url( $event );
							$event_image = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
							if ( ! $event_image ) {
								$placeholder_url = ! empty( $settings['placeholder_image']['url'] ) ? $settings['placeholder_image']['url'] : get_template_directory_uri() . '/assets/images/placeholder-event.jpg';
								$event_image = $placeholder_url;
							}
							
							?>
							<div class="hbl-calendar-event-card">
								<?php if ( $event_image ) : ?>
									<div class="hbl-calendar-event-image" style="background-image: url('<?php echo esc_url( $event_image ); ?>');">
									</div>
								<?php endif; ?>
								<div class="hbl-calendar-event-content">
									<h4 class="hbl-calendar-event-title"><?php echo esc_html( $event_title ); ?></h4>
									<?php if ( ! empty( $event->venue ) ) : ?>
										<p class="hbl-calendar-event-venue">
											<svg width="12" height="12" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 5.03 7.03 1 12 1S21 5.03 21 10Z" stroke="currentColor" stroke-width="2"/>
												<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
											</svg>
											<?php echo esc_html( $event->venue ); ?>
										</p>
									<?php endif; ?>
									<?php
									// Show event category
									$event_category = '';
									$event_category_link = '';
									if ( ! empty( $event->category_id ) ) {
										$term = get_term( $event->category_id );
										if ( ! is_wp_error( $term ) && $term ) {
											$event_category = $term->name;
											$event_category_link = get_term_link( $term );
										}
									}
									if ( ! empty( $event_category ) ) :
									?>
										<p class="hbl-calendar-event-category">
											<a href="<?php echo esc_url( $event_category_link ); ?>"><?php echo esc_html( $event_category ); ?></a>
										</p>
									<?php endif; ?>
									<?php if ( ! empty( $event_excerpt ) ) : ?>
										<p class="hbl-calendar-event-excerpt"><?php echo esc_html( $event_excerpt ); ?></p>
									<?php endif; ?>
									<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-calendar-event-link" data-selected-date="<?php echo esc_attr( $today ); ?>">
										<?php echo esc_html( $settings['event_card_read_more'] ); ?>
										<svg width="8" height="11" viewBox="0 0 8 11" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M4 2L6 4L4 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</a>
								</div>
							</div>
						<?php endforeach; ?>

						<?php if ( $total_pages > 1 ) : ?>
							<div class="hbl-calendar-pagination">
								<button type="button" class="hbl-calendar-page-btn prev disabled" disabled><?php esc_html_e( 'Previous', 'hbl' ); ?></button>
								<span class="hbl-calendar-page-info"><?php printf( esc_html__( 'Page %s of %s', 'hbl' ), '1', $total_pages ); ?></span>
								<button type="button" class="hbl-calendar-page-btn next" data-page="2"><?php esc_html_e( 'Next', 'hbl' ); ?></button>
							</div>
						<?php endif; ?>
					<?php else : ?>
						<p class="hbl-calendar-no-events"><?php echo esc_html( $settings['no_events_message'] ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( ! empty( $settings['list_event_text'] ) ) : ?>
				<div class="hbl-calendar-mobile-cta">
					<p class="hbl-calendar-mobile-cta-text"><?php esc_html_e( 'Running an event in Hervey Bay? Get it in front of locals.', 'hbl' ); ?></p>
					<a href="<?php echo esc_url( $settings['list_event_url']['url'] ?? '#' ); ?>" class="hbl-calendar-featured-event-cta hbl-calendar-featured-event-cta-btn hbl-calendar-mobile-cta-btn">
						<?php echo esc_html( $settings['list_event_text'] ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get formatted event time display
	 */
	private function get_event_time_display( $event ) {
		if ( $event->is_allday ) {
			return esc_html__( 'All Day', 'hbl' );
		}

		// Check for multi-day events with specific daily hours
		$scheduling_type = isset( $event->scheduling_type ) ? $event->scheduling_type : 'single';
		$event_frequency = isset( $event->event_frequency ) ? $event->event_frequency : '';
		
		if ( ( $scheduling_type === 'multi' || $event_frequency === 'multi_day' ) 
			&& ! empty( $event->daily_start_time ) ) {
			// Use the daily opening hours for multi-day events
			$start_time = date( get_option( 'time_format' ), strtotime( $event->daily_start_time ) );
			
			if ( ! empty( $event->daily_end_time ) ) {
				$end_time = date( get_option( 'time_format' ), strtotime( $event->daily_end_time ) );
				return $start_time . ' - ' . $end_time;
			}
			
			return $start_time;
		}

		// Standard time display for single/recurring events
		$start_date = $event->start_date;
		$end_date = $event->end_date;

		if ( empty( $start_date ) ) {
			return '';
		}

		$start_time = date( get_option( 'time_format' ), strtotime( $start_date ) );
		
		if ( ! empty( $end_date ) ) {
			$end_time = date( get_option( 'time_format' ), strtotime( $end_date ) );
			return $start_time . ' - ' . $end_time;
		}

		return $start_time;
	}

	/**
	 * Generate human-readable recurrence description
	 *
	 * @param object $event Event object
	 * @return string Human-readable description
	 */
	private function get_recurrence_description( $event ) {
		$frequency = $event->event_frequency ?? '';
		$interval = $event->recurrence_interval ?? 1;
		$days = $event->recurrence_days ?? '';
		$weeks = $event->recurrence_week ?? '';

		if ( empty( $frequency ) || $frequency === 'once' ) {
			return __( 'One-off event', 'hbl' );
		}

		if ( $frequency === 'recurring' ) {
			return __( 'Ongoing / recurring', 'hbl' );
		}

		// Multi-day event with open days
		if ( $frequency === 'multi_day' ) {
			if ( ! empty( $days ) ) {
				$day_list = explode( ',', $days );
				$day_names = array();
				foreach ( $day_list as $day ) {
					$day = strtolower( trim( $day ) );
					if ( isset( $this->day_labels[ $day ] ) ) {
						$day_names[] = $this->day_labels[ $day ];
					}
				}
				if ( count( $day_names ) === 1 ) {
					return sprintf( __( 'Multi-day event, open %s', 'hbl' ), $day_names[0] );
				} elseif ( count( $day_names ) > 1 ) {
					$last = array_pop( $day_names );
					return sprintf( __( 'Multi-day event, open %s and %s', 'hbl' ), implode( ', ', $day_names ), $last );
				}
			}
			return __( 'Multi-day event', 'hbl' );
		}

		if ( $frequency === 'weekly' ) {
			// Weekly recurrence
			if ( $interval == 2 ) {
				$prefix = __( 'Every second', 'hbl' );
			} else {
				$prefix = __( 'Every', 'hbl' );
			}

			if ( ! empty( $days ) ) {
				$day_list = explode( ',', $days );
				$day_names = array();
				foreach ( $day_list as $day ) {
					$day = strtolower( trim( $day ) );
					if ( isset( $this->day_labels[ $day ] ) ) {
						$day_names[] = $this->day_labels[ $day ];
					}
				}
				if ( count( $day_names ) === 1 ) {
					return $prefix . ' ' . $day_names[0];
				} elseif ( count( $day_names ) > 1 ) {
					$last = array_pop( $day_names );
					return $prefix . ' ' . implode( ', ', $day_names ) . ' ' . __( 'and', 'hbl' ) . ' ' . $last;
				}
			}
			return __( 'Weekly', 'hbl' );

		} elseif ( $frequency === 'monthly' ) {
			// Monthly recurrence
			$week_parts = array();
			$day_name = '';

			// Get weeks
			if ( ! empty( $weeks ) ) {
				$week_list = explode( ',', $weeks );
				foreach ( $week_list as $week ) {
					$week = trim( $week );
					if ( isset( $this->week_labels[ $week ] ) ) {
						$week_parts[] = $this->week_labels[ $week ];
					}
				}
			}

			// Get day
			if ( ! empty( $days ) ) {
				$day = strtolower( trim( $days ) );
				if ( isset( $this->day_labels[ $day ] ) ) {
					$day_name = $this->day_labels[ $day ];
				}
			}

			if ( ! empty( $week_parts ) && ! empty( $day_name ) ) {
				if ( count( $week_parts ) === 1 ) {
					return sprintf( __( 'Every %s %s', 'hbl' ), $week_parts[0], $day_name );
				} else {
					$last_week = array_pop( $week_parts );
					return sprintf( 
						__( 'Every %s and %s %s', 'hbl' ), 
						implode( ', ', $week_parts ), 
						$last_week, 
						$day_name 
					);
				}
			} elseif ( ! empty( $day_name ) ) {
				return sprintf( __( 'Monthly on %s', 'hbl' ), $day_name );
			}
			return __( 'Monthly', 'hbl' );
		}

		return '';
	}

	/**
	 * Add recurring event dates to the events_by_date array
	 *
	 * @param array  $events_by_date Reference to events by date array
	 * @param object $event Event object
	 * @param int    $year Year
	 * @param int    $month Month
	 * @param array  $day_to_num Day name to number mapping
	 */
	private function add_recurring_event_dates( &$events_by_date, $event, $year, $month, $day_to_num ) {
		$event_frequency = $event->event_frequency ?? 'once';
		$recurrence_days = $event->recurrence_days ?? '';
		$recurrence_week = $event->recurrence_week ?? '';
		$recurrence_interval = $event->recurrence_interval ?? 1;
		$event_start = strtotime( $event->start_date );

		$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
		$days_in_month = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );

		if ( $event_frequency === 'weekly' && ! empty( $recurrence_days ) ) {
			// Weekly recurrence - event occurs on specific days each week
			$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
			
			for ( $day = 1; $day <= $days_in_month; $day++ ) {
				$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
				$date_timestamp = strtotime( $date_string );
				
				// Skip if date is before event start
				if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
					continue;
				}

				$day_of_week = strtolower( date( 'D', $date_timestamp ) ); // mon, tue, wed, etc.
				
				// Check if this day matches recurrence days
				if ( in_array( $day_of_week, $days, true ) ) {
					// For bi-weekly, check if this is the right week
					if ( $recurrence_interval == 2 ) {
						$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
						if ( $weeks_since_start % 2 !== 0 ) {
							continue; // Skip odd weeks for bi-weekly
						}
					}

					if ( ! isset( $events_by_date[ $date_string ] ) ) {
						$events_by_date[ $date_string ] = array();
					}
					$events_by_date[ $date_string ][] = $event;
				}
			}
		} elseif ( $event_frequency === 'monthly' && ! empty( $recurrence_days ) ) {
			// Monthly recurrence - event occurs on specific week(s) and day
			$day_name = strtolower( trim( $recurrence_days ) );
			$weeks = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();
			
			if ( isset( $day_to_num[ $day_name ] ) && ! empty( $weeks ) ) {
				$target_day_num = $day_to_num[ $day_name ];
				
				foreach ( $weeks as $week_num ) {
					$week_num = intval( $week_num );
					if ( $week_num < 1 || $week_num > 4 ) {
						continue;
					}
					
					// Find the nth occurrence of the day in this month
					$occurrence = 0;
					for ( $day = 1; $day <= $days_in_month; $day++ ) {
						$date_string = "{$year}-{$month_padded}-" . str_pad( $day, 2, '0', STR_PAD_LEFT );
						$date_timestamp = strtotime( $date_string );
						
						if ( date( 'w', $date_timestamp ) == $target_day_num ) {
							$occurrence++;
							if ( $occurrence == $week_num ) {
								// Skip if date is before event start
								if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
									break;
								}

								if ( ! isset( $events_by_date[ $date_string ] ) ) {
									$events_by_date[ $date_string ] = array();
								}
								$events_by_date[ $date_string ][] = $event;
								break;
							}
						}
					}
				}
			}
		} elseif ( $event_frequency === 'recurring' ) {
			// Ongoing/recurring - show on the original start date only (within month)
			$start_date_string = date( 'Y-m-d', $event_start );
			$start_month = date( 'n', $event_start );
			$start_year = date( 'Y', $event_start );
			
			if ( $start_month == $month && $start_year == $year ) {
				if ( ! isset( $events_by_date[ $start_date_string ] ) ) {
					$events_by_date[ $start_date_string ] = array();
				}
				$events_by_date[ $start_date_string ][] = $event;
			}
		}
	}

	/**
	 * Get events for a specific month from custom database
	 * Includes one-off events and recurring events
	 */
	private function get_month_events( $year, $month ) {
		if ( ! $this->is_events_db_active() ) {
			return array();
		}

		global $wpdb;
		$table = hbl_events_db()->get_table_name();
		
		$month_padded = str_pad( $month, 2, '0', STR_PAD_LEFT );
		$first_day = "{$year}-{$month_padded}-01";
		$last_day_num = date( 't', strtotime( "{$year}-{$month_padded}-01" ) );
		$last_day = "{$year}-{$month_padded}-{$last_day_num}";
		$start_date = "{$first_day} 00:00:00";
		$end_date = "{$last_day} 23:59:59";

		// Query events that fall within this month:
		// 1. Events that START within this month
		// 2. OR multi-day events that span into this month
		// 3. OR recurring events (weekly/monthly) that started before or during this month
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM `{$table}` 
			WHERE status = %s
			AND (
				(DATE(start_date) >= %s AND DATE(start_date) <= %s)
				OR (start_date < %s AND (end_date >= %s OR end_date IS NULL))
				OR (event_frequency IN ('weekly', 'monthly', 'recurring', 'multi_day') AND DATE(start_date) <= %s)
			)
			ORDER BY start_date ASC",
			'publish',
			$first_day,
			$last_day,
			$start_date,
			$start_date,
			$last_day
		);

		$results = $wpdb->get_results( $sql );
		
		return $results ? $results : array();
	}

	/**
	 * Get calendar data for a month
	 */
	private function get_calendar_data( $year, $month, $events = array() ) {
		$first_day = mktime( 0, 0, 0, $month, 1, $year );
		$first_day_of_week = date( 'w', $first_day );
		$first_day_of_week = $first_day_of_week == 0 ? 7 : $first_day_of_week; // Convert Sunday from 0 to 7
		$days_in_month = date( 't', $first_day );
		$current_date = current_time( 'Y-m-d' );

		// Day name to number mapping (for recurrence)
		$day_to_num = array(
			'sun' => 0,
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
		);

		// Create events lookup by date
		$events_by_date = array();
		foreach ( $events as $event ) {
			$event_start = $event->start_date;
			$event_end = $event->end_date;
			$event_frequency = $event->event_frequency ?? 'once';
			
			if ( empty( $event_start ) ) {
				continue;
			}

			// Handle recurring events
			if ( in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
				$this->add_recurring_event_dates( $events_by_date, $event, $year, $month, $day_to_num );
				continue;
			}

			// Handle Multi-day events with specific open days
			// Check both scheduling_type AND event_frequency for legacy/migration robustness
			if ( ( isset( $event->scheduling_type ) && $event->scheduling_type === 'multi' ) || ( isset( $event->event_frequency ) && $event->event_frequency === 'multi_day' ) ) {
				$start = strtotime( date( 'Y-m-d', strtotime( $event_start ) ) );
				$end = ! empty( $event_end ) ? strtotime( date( 'Y-m-d', strtotime( $event_end ) ) ) : $start;
				$recurrence_days = isset($event->recurrence_days) ? $event->recurrence_days : ''; 
				$open_days_array = ! empty( $recurrence_days ) ? array_map('strtolower', array_map('trim', explode( ',', $recurrence_days ))) : array();

				// Get all dates this event spans
				for ( $date = $start; $date <= $end; $date = strtotime( '+1 day', $date ) ) {
					// Check if valid day of week
					// We always filter if we are in this block, assuming default is 'all open' only if input was missing/malformed,
					// but here we trust the array. If array is empty, it means closed all days.
					// Use date() instead of date_i18n() for consistent English abbreviations (mon, tue, etc.)
					$day_name = strtolower( date( 'D', $date ) ); // mon, tue...
					
					// DEBUG
					error_log( sprintf(
						'🔴 Calendar Filter - Event #%d: Date %s (day: %s) vs open_days [%s] - %s',
						$event->id,
						date('Y-m-d', $date),
						$day_name,
						implode(',', $open_days_array),
						in_array( $day_name, $open_days_array, true ) ? 'SHOW' : 'SKIP'
					) );
					
					// If we have explicit open days, and this day isn't one of them, skip.
					// If open_days_array is empty, we skip everything (closed).
					if ( ! in_array( $day_name, $open_days_array, true ) ) {
						continue; // Skip closed days
					}

					$date_key = date( 'Y-m-d', $date );
					if ( ! isset( $events_by_date[ $date_key ] ) ) {
						$events_by_date[ $date_key ] = array();
					}
					$events_by_date[ $date_key ][] = $event;
				}
				continue;
			}

			// Handle legacy one-off and standard multi-day events
			$start = strtotime( date( 'Y-m-d', strtotime( $event_start ) ) );
			$end = ! empty( $event_end ) ? strtotime( date( 'Y-m-d', strtotime( $event_end ) ) ) : $start;
			
			// Get all dates this event spans
			for ( $date = $start; $date <= $end; $date = strtotime( '+1 day', $date ) ) {
				$date_key = date( 'Y-m-d', $date );
				if ( ! isset( $events_by_date[ $date_key ] ) ) {
					$events_by_date[ $date_key ] = array();
				}
				$events_by_date[ $date_key ][] = $event;
			}
		}

		$weeks = array();
		$week = array();
		
		// Add empty cells for days before the first day of the month
		for ( $i = 1; $i < $first_day_of_week; $i++ ) {
			$prev_month = $month - 1;
			$prev_year = $year;
			if ( $prev_month < 1 ) {
				$prev_month = 12;
				$prev_year--;
			}
			$days_in_prev_month = date( 't', mktime( 0, 0, 0, $prev_month, 1, $prev_year ) );
			$day_num = $days_in_prev_month - ( $first_day_of_week - $i - 1 );
			$date_string = $prev_year . '-' . str_pad( $prev_month, 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $day_num, 2, '0', STR_PAD_LEFT );
			
			$week[] = array(
				'day'            => $day_num,
				'is_current_month' => false,
				'is_today'       => false,
				'has_events'     => isset( $events_by_date[ $date_string ] ),
				'date_string'    => $date_string,
				'year'          => $prev_year,
				'month'         => $prev_month,
			);
		}
		
		// Add days of the current month
		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date_string = $year . '-' . str_pad( $month, 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $day, 2, '0', STR_PAD_LEFT );
			$is_today = ( $date_string === $current_date );
			
			$week[] = array(
				'day'            => $day,
				'is_current_month' => true,
				'is_today'       => $is_today,
				'has_events'     => isset( $events_by_date[ $date_string ] ),
				'date_string'    => $date_string,
				'year'          => $year,
				'month'         => $month,
			);
			
			// Start a new week if we've reached 7 days
			if ( count( $week ) == 7 ) {
				$weeks[] = $week;
				$week = array();
			}
		}
		
		// Add empty cells for days after the last day of the month
		$next_month = $month + 1;
		$next_year = $year;
		if ( $next_month > 12 ) {
			$next_month = 1;
			$next_year++;
		}
		
		$day_num = 1;
		while ( count( $week ) < 7 ) {
			$date_string = $next_year . '-' . str_pad( $next_month, 2, '0', STR_PAD_LEFT ) . '-' . str_pad( $day_num, 2, '0', STR_PAD_LEFT );
			
			$week[] = array(
				'day'            => $day_num,
				'is_current_month' => false,
				'is_today'       => false,
				'has_events'     => isset( $events_by_date[ $date_string ] ),
				'date_string'    => $date_string,
				'year'          => $next_year,
				'month'         => $next_month,
			);
			$day_num++;
		}
		
		if ( ! empty( $week ) ) {
			$weeks[] = $week;
		}
		
		return array(
			'weeks' => $weeks,
		);
	}

	/**
	 * Get events for a specific date from custom database
	 * Includes one-off events and recurring events that match this date
	 */
	private function get_events_for_date( $date, $limit = 12 ) {
		if ( ! $this->is_events_db_active() ) {
			return array();
		}

		global $wpdb;
		$table = hbl_events_db()->get_table_name();
		
		$date_start = $date . ' 00:00:00';
		$date_timestamp = strtotime( $date );
		$day_of_week = strtolower( date( 'D', $date_timestamp ) ); // mon, tue, wed, etc.

		// Query events that fall on this date:
		// 1. Events that START on this date
		// 2. OR multi-day events where the date falls between start and end
		// 3. OR recurring events that started before or on this date
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM `{$table}` 
			WHERE status = %s
			AND (
				(DATE(start_date) = %s)
				OR (start_date < %s AND (end_date >= %s OR end_date IS NULL))
				OR (event_frequency IN ('weekly', 'monthly', 'recurring', 'multi_day') AND DATE(start_date) <= %s)
			)
			ORDER BY start_date ASC",
			'publish',
			$date,
			$date_start,
			$date_start,
			$date
		);

		$results = $wpdb->get_results( $sql );
		
		if ( empty( $results ) ) {
			return array();
		}

		// Filter recurring events to only include those that match this specific date
		$filtered_events = array();
		
		// Day name to number mapping
		$day_to_num = array(
			'sun' => 0,
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
		);

		foreach ( $results as $event ) {
			$event_frequency = $event->event_frequency ?? 'once';
			$event_start = strtotime( $event->start_date );
			$scheduling_type = $event->scheduling_type ?? 'single';
			
			// Handle Multi-day events with specific open days
			if ( $scheduling_type === 'multi' || $event_frequency === 'multi_day' ) {
				$recurrence_days = isset($event->recurrence_days) ? $event->recurrence_days : '';
				$open_days_array = ! empty( $recurrence_days ) ? array_map('strtolower', array_map('trim', explode( ',', $recurrence_days ))) : array();
				
				// If open_days_array is empty, this day is closed
				if ( empty( $open_days_array ) || ! in_array( $day_of_week, $open_days_array, true ) ) {
					continue; // Skip - this day is not open
				}
				
				$filtered_events[] = $event;
				continue;
			}
			
			// One-off and legacy multi-day events - include if they match the date
			if ( ! in_array( $event_frequency, array( 'weekly', 'monthly', 'recurring' ), true ) ) {
				$filtered_events[] = $event;
				continue;
			}

			// Skip if date is before event start
			if ( $date_timestamp < strtotime( date( 'Y-m-d', $event_start ) ) ) {
				continue;
			}

			// Weekly recurring events
			if ( $event_frequency === 'weekly' ) {
				$recurrence_days = $event->recurrence_days ?? '';
				$recurrence_interval = $event->recurrence_interval ?? 1;
				
				if ( ! empty( $recurrence_days ) ) {
					$days = array_map( function( $d ) { return strtolower( trim( $d ) ); }, explode( ',', $recurrence_days ) );
					
					if ( in_array( $day_of_week, $days, true ) ) {
						// For bi-weekly, check if this is the right week
						if ( $recurrence_interval == 2 ) {
							$weeks_since_start = floor( ( $date_timestamp - $event_start ) / ( 7 * 24 * 60 * 60 ) );
							if ( $weeks_since_start % 2 !== 0 ) {
								continue; // Skip odd weeks for bi-weekly
							}
						}
						$filtered_events[] = $event;
					}
				}
				continue;
			}

			// Monthly recurring events
			if ( $event_frequency === 'monthly' ) {
				$recurrence_days = $event->recurrence_days ?? '';
				$recurrence_week = $event->recurrence_week ?? '';
				
				if ( ! empty( $recurrence_days ) && ! empty( $recurrence_week ) ) {
					$day_name = strtolower( trim( $recurrence_days ) );
					$weeks = array_map( 'trim', explode( ',', $recurrence_week ) );
					
					if ( isset( $day_to_num[ $day_name ] ) ) {
						$target_day_num = $day_to_num[ $day_name ];
						
						// Check if today's day of week matches
						if ( date( 'w', $date_timestamp ) == $target_day_num ) {
							// Calculate which occurrence of this day in the month
							// by counting how many times this day has occurred so far
							$day_of_month = (int) date( 'j', $date_timestamp );
							$current_month = date( 'n', $date_timestamp );
							$current_year = date( 'Y', $date_timestamp );
							
							// Count occurrences of this day up to and including today
							$occurrence = 0;
							for ( $d = 1; $d <= $day_of_month; $d++ ) {
								$check_date = strtotime( "{$current_year}-{$current_month}-{$d}" );
								if ( date( 'w', $check_date ) == $target_day_num ) {
									$occurrence++;
								}
							}
							
							if ( in_array( (string) $occurrence, $weeks, true ) ) {
								$filtered_events[] = $event;
							}
						}
					}
				}
				continue;
			}

			// Ongoing/recurring - only show on original start date
			if ( $event_frequency === 'recurring' ) {
				if ( date( 'Y-m-d', $event_start ) === $date ) {
					$filtered_events[] = $event;
				}
			}
		}

		// Apply limit
		$total_events = count( $filtered_events );
		$events_slice = array_slice( $filtered_events, 0, $limit );
		
		return array(
			'events' => $events_slice,
			'total'  => $total_events
		);
	}

	/**
	 * Render featured event card
	 */
	private function render_featured_event( $event, $cta_text = 'Learn More', $description_length = 25, $placeholder_image = array(), $event_page_url = '' ) {
		$event_id = $event->id;
		$event_title = $event->title;
		$event_excerpt = wp_trim_words( $event->description, $description_length );
		$event_url = hbl_events_db()->get_event_url( $event );
		$event_image = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
		
		// Don't render if no image - no placeholder for featured events
		if ( ! $event_image ) {
			$event_image = ! empty( $placeholder_image['url'] ) ? $placeholder_image['url'] : '';
		}

		if ( ! $event_image ) {
			return;
		}

		// Event cost badge
		$cost_badge = $event->event_cost === 'paid' ? __( 'Paid', 'hbl' ) : __( 'Free', 'hbl' );
		
		?>
		<div class="hbl-calendar-featured-event">
			<div class="hbl-calendar-featured-event-bg" style="background-image: url('<?php echo esc_url( $event_image ); ?>');">
				<div class="hbl-calendar-featured-event-overlay"></div>
				<div class="hbl-calendar-featured-event-content">
					<span class="hbl-calendar-ticket-badge"><?php echo esc_html( $cost_badge ); ?></span>
					<h4 class="hbl-calendar-featured-event-title"><?php echo esc_html( $event_title ); ?></h4>
					<?php 
					// Show event time
					$event_time = $this->get_event_time_display( $event );
					if ( ! empty( $event_time ) ) : 
					?>
						<p class="hbl-calendar-featured-event-time"><?php echo esc_html( $event_time ); ?></p>
					<?php endif; ?>
					<?php
					// Show recurrence info
					$recurrence_desc = $this->get_recurrence_description( $event );
					if ( ! empty( $recurrence_desc ) ) :
					?>
						<p class="hbl-calendar-featured-event-recurrence">
							<i class="fa-solid fa-repeat"></i>
							<?php echo esc_html( $recurrence_desc ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $event_excerpt ) ) : ?>
						<p class="hbl-calendar-featured-event-description"><?php echo esc_html( $event_excerpt ); ?></p>
					<?php endif; ?>
					<a href="<?php echo esc_url( $event_url ); ?>" class="hbl-calendar-featured-event-cta">
						<?php echo esc_html( $cta_text ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
