<?php
/**
 * HBL Static Grid V2 Widget
 *
 * Displays latest events from the HBL Events DB in the same static grid card design
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HBL Static Grid V2 Widget Class
 */
class HBL_Static_Grid_V2 extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-static-grid-v2';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'Grid of Events', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-posts-grid';
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
		return array( 'hbl', 'events', 'grid', 'listing', 'cards', 'dynamic' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {

		// ========== CONTENT: Query ==========
		$this->start_controls_section(
			'content_query',
			array(
				'label' => esc_html__( 'Query', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'   => esc_html__( 'Number of Events', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 6,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->add_control(
			'show_upcoming_only',
			array(
				'label'        => esc_html__( 'Upcoming Events Only', 'hbl' ),
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
				'default' => 'start_date',
				'options' => array(
					'start_date' => esc_html__( 'Event Date', 'hbl' ),
					'title'      => esc_html__( 'Title', 'hbl' ),
					'created_at' => esc_html__( 'Date Created', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'order',
			array(
				'label'   => esc_html__( 'Order', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'ASC',
				'options' => array(
					'ASC'  => esc_html__( 'Ascending', 'hbl' ),
					'DESC' => esc_html__( 'Descending', 'hbl' ),
				),
			)
		);

		// Category filter
		$category_options = array( '' => esc_html__( 'All Categories', 'hbl' ) );
		$categories       = get_terms(
			array(
				'taxonomy'   => 'event_category',
				'hide_empty' => false,
			)
		);
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $cat ) {
				$category_options[ $cat->term_id ] = $cat->name;
			}
		}

		$this->add_control(
			'category_filter',
			array(
				'label'   => esc_html__( 'Category', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => $category_options,
			)
		);

		$this->add_control(
			'event_type_filter',
			array(
				'label'   => esc_html__( 'Event Type', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '',
				'options' => array(
					''          => esc_html__( 'All Types', 'hbl' ),
					'in_person' => esc_html__( 'In Person', 'hbl' ),
					'virtual'   => esc_html__( 'Virtual', 'hbl' ),
					'hybrid'    => esc_html__( 'Hybrid', 'hbl' ),
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: Card Display ==========
		$this->start_controls_section(
			'content_display',
			array(
				'label' => esc_html__( 'Card Display', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => esc_html__( 'Show Description', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'description_length',
			array(
				'label'     => esc_html__( 'Description Length (words)', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 15,
				'min'       => 5,
				'max'       => 100,
				'condition' => array(
					'show_description' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_date_badge',
			array(
				'label'        => esc_html__( 'Show Date Badge on Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_icon',
			array(
				'label'        => esc_html__( 'Show Arrow Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'fallback_color',
			array(
				'label'   => esc_html__( 'Fallback Image Color', 'hbl' ),
				'type'    => Controls_Manager::COLOR,
				'default' => '#008080',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Grid Layout ==========
		$this->start_controls_section(
			'style_grid',
			array(
				'label' => esc_html__( 'Grid Layout', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'columns',
			array(
				'label'          => esc_html__( 'Columns', 'hbl' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'selectors'      => array(
					'{{WRAPPER}} .static-grid-container' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => esc_html__( 'Column Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 30,
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-container' => 'column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => esc_html__( 'Row Gap', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 50,
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-container' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Image ==========
		$this->start_controls_section(
			'style_image',
			array(
				'label' => esc_html__( 'Featured Image', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'image_height',
			array(
				'label'      => esc_html__( 'Image Height', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh' ),
				'range'      => array(
					'px' => array(
						'min' => 100,
						'max' => 600,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 297,
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-image' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Date Badge ==========
		$this->start_controls_section(
			'style_date_badge',
			array(
				'label'     => esc_html__( 'Date Badge', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_date_badge' => 'yes',
				),
			)
		);

		$this->add_control(
			'date_badge_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-date-badge' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'date_badge_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1a1a1a',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-date-badge' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Title ==========
		$this->start_controls_section(
			'style_title',
			array(
				'label' => esc_html__( 'Title', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'selector' => '{{WRAPPER}} .static-grid-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-title'   => 'color: {{VALUE}};',
					'{{WRAPPER}} .static-grid-title a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_hover_color',
			array(
				'label'     => esc_html__( 'Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-title a:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'title_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Description ==========
		$this->start_controls_section(
			'style_description',
			array(
				'label'     => esc_html__( 'Description', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_description' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .static-grid-description',
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'description_spacing',
			array(
				'label'      => esc_html__( 'Spacing', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: Arrow Icon ==========
		$this->start_controls_section(
			'style_icon',
			array(
				'label'     => esc_html__( 'Arrow Icon', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_icon' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 20,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 40,
				),
				'selectors'  => array(
					'{{WRAPPER}} .static-grid-icon'     => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .static-grid-icon svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.4); height: calc({{SIZE}}{{UNIT}} * 0.4);',
				),
			)
		);

		$this->add_control(
			'icon_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_arrow_color',
			array(
				'label'     => esc_html__( 'Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_arrow_color',
			array(
				'label'     => esc_html__( 'Hover Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .static-grid-icon:hover svg' => 'stroke: {{VALUE}};',
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

		$this->add_responsive_control(
			'hide_images_resp',
			array(
				'label'        => esc_html__( 'Hide Images', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .static-grid-image-wrapper' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_date_badge_resp',
			array(
				'label'        => esc_html__( 'Hide Date Badge', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .static-grid-date-badge' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_titles_resp',
			array(
				'label'        => esc_html__( 'Hide Titles', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .static-grid-title' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_descriptions_resp',
			array(
				'label'        => esc_html__( 'Hide Descriptions', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .static-grid-description' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->add_responsive_control(
			'hide_icons_resp',
			array(
				'label'        => esc_html__( 'Hide Arrow Icons', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'none',
				'selectors'    => array(
					'{{WRAPPER}} .static-grid-icon' => 'display: {{VALUE}} !important;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Get events from the HBL Events DB
	 */
	private function get_events( $settings ) {
		if ( ! function_exists( 'hbl_events_db' ) ) {
			return array();
		}

		$db         = hbl_events_db();
		$query_args = array(
			'status'  => 'publish',
			'orderby' => $settings['orderby'],
			'order'   => $settings['order'],
			'limit'   => -1,
		);

		if ( ! empty( $settings['category_filter'] ) ) {
			$query_args['category_id'] = intval( $settings['category_filter'] );
		}

		if ( ! empty( $settings['event_type_filter'] ) ) {
			$query_args['event_type'] = $settings['event_type_filter'];
		}

		$raw_events = $db->get_events( $query_args );

		if ( empty( $raw_events ) ) {
			return array();
		}

		$now         = current_time( 'timestamp' );
		$today_start = strtotime( 'today', $now );
		$processed   = array();

		foreach ( $raw_events as $event ) {
			$next_date = $this->get_next_occurrence( $event );
			$next_ts   = strtotime( $next_date );

			// Filter upcoming only
			if ( 'yes' === $settings['show_upcoming_only'] && $next_ts < $today_start ) {
				continue;
			}

			$event->computed_start_date = $next_date;
			$processed[]                = $event;
		}

		// Sort by computed date
		$order = $settings['order'];
		if ( $settings['orderby'] === 'start_date' ) {
			usort(
				$processed,
				function ( $a, $b ) use ( $order ) {
					$t1     = strtotime( $a->computed_start_date );
					$t2     = strtotime( $b->computed_start_date );
					$result = $t1 - $t2;
					return ( $order === 'DESC' ) ? -$result : $result;
				}
			);
		} elseif ( $settings['orderby'] === 'title' ) {
			usort(
				$processed,
				function ( $a, $b ) use ( $order ) {
					$result = strcasecmp( $a->title, $b->title );
					return ( $order === 'DESC' ) ? -$result : $result;
				}
			);
		}

		$limit = intval( $settings['posts_per_page'] );
		return array_slice( $processed, 0, $limit );
	}

	/**
	 * Calculate next occurrence for recurring events
	 */
	private function get_next_occurrence( $event ) {
		$frequency  = $event->event_frequency ?? 'once';
		$start_date = $event->start_date;
		$now        = current_time( 'timestamp' );
		$today_start = strtotime( 'today', $now );
		$event_start = strtotime( $start_date );
		$event_time  = date( 'H:i:s', $event_start );

		if ( $event_start >= $now ) {
			return $start_date;
		}

		if ( ! in_array( $frequency, array( 'weekly', 'monthly', 'recurring', 'multi_day' ), true ) ) {
			return $start_date;
		}

		$recurrence_days     = $event->recurrence_days ?? '';
		$recurrence_week     = $event->recurrence_week ?? '';
		$recurrence_interval = isset( $event->recurrence_interval ) ? max( 1, intval( $event->recurrence_interval ) ) : 1;

		$normalize_day = function ( $d ) {
			$d   = strtolower( trim( $d ) );
			$map = array(
				'monday'    => 'mon',
				'tuesday'   => 'tue',
				'wednesday' => 'wed',
				'thursday'  => 'thu',
				'friday'    => 'fri',
				'saturday'  => 'sat',
				'sunday'    => 'sun',
			);
			return isset( $map[ $d ] ) ? $map[ $d ] : substr( $d, 0, 3 );
		};

		if ( $frequency === 'weekly' && ! empty( $recurrence_days ) ) {
			$days = array_map( $normalize_day, explode( ',', $recurrence_days ) );
			for ( $i = 0; $i < 60; $i++ ) {
				$check_ts    = strtotime( "+$i days", $today_start );
				$day_of_week = strtolower( date( 'D', $check_ts ) );
				if ( in_array( $day_of_week, $days, true ) ) {
					$orig_week_start = strtotime( 'last monday', $event_start + 86400 );
					$curr_week_start = strtotime( 'last monday', $check_ts + 86400 );
					$weeks_diff      = round( ( $curr_week_start - $orig_week_start ) / ( 7 * 24 * 60 * 60 ) );
					if ( $weeks_diff % $recurrence_interval === 0 ) {
						return date( 'Y-m-d', $check_ts ) . ' ' . $event_time;
					}
				}
			}
		} elseif ( $frequency === 'monthly' && ! empty( $recurrence_days ) ) {
			$day_map        = array( 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 );
			$day_name       = $normalize_day( $recurrence_days );
			if ( isset( $day_map[ $day_name ] ) ) {
				$target_day_num = $day_map[ $day_name ];
				$weeks          = ! empty( $recurrence_week ) ? array_map( 'trim', explode( ',', $recurrence_week ) ) : array();
				for ( $i = 0; $i < 12; $i++ ) {
					$check_month_ts = strtotime( "+$i month", $today_start );
					$year           = date( 'Y', $check_month_ts );
					$month          = date( 'n', $check_month_ts );
					$days_in_month  = date( 't', mktime( 0, 0, 0, $month, 1, $year ) );
					$occurrence     = 0;
					for ( $day = 1; $day <= $days_in_month; $day++ ) {
						$date_ts = mktime( 0, 0, 0, $month, $day, $year );
						if ( date( 'w', $date_ts ) == $target_day_num ) {
							$occurrence++;
							if ( in_array( $occurrence, $weeks ) ) {
								if ( $date_ts >= $today_start ) {
									return date( 'Y-m-d', $date_ts ) . ' ' . $event_time;
								}
							}
						}
					}
				}
			}
		} elseif ( $frequency === 'multi_day' ) {
			$end_date_ts = ! empty( $event->end_date ) ? strtotime( $event->end_date ) : $event_start;
			if ( $end_date_ts >= $today_start ) {
				$check_ts = $today_start;
				for ( $i = 0; $i < 60; $i++ ) {
					$curr_ts = strtotime( "+$i days", $check_ts );
					if ( $curr_ts > $end_date_ts ) {
						break;
					}
					$days = ! empty( $recurrence_days ) ? array_map( $normalize_day, explode( ',', $recurrence_days ) ) : array();
					if ( empty( $days ) ) {
						return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
					}
					$day_of_week = strtolower( date( 'D', $curr_ts ) );
					if ( in_array( $day_of_week, $days, true ) ) {
						return date( 'Y-m-d', $curr_ts ) . ' ' . $event_time;
					}
				}
			}
		}

		return $start_date;
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$events   = $this->get_events( $settings );

		if ( empty( $events ) ) {
			echo '<div class="static-grid-no-items">';
			echo '<p>' . esc_html__( 'No events found.', 'hbl' ) . '</p>';
			echo '</div>';
			return;
		}

		$db               = hbl_events_db();
		$show_description = 'yes' === $settings['show_description'];
		$show_date_badge  = 'yes' === $settings['show_date_badge'];
		$show_icon        = 'yes' === $settings['show_icon'];
		$desc_length      = intval( $settings['description_length'] );
		$fallback_color   = $settings['fallback_color'];

		?>
		<div class="static-grid-wrapper">
			<div class="static-grid-container">
				<?php foreach ( $events as $event ) :
					$event_url     = $db->get_event_url( $event );
					$thumbnail_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'medium_large' ) : '';
					$start_date    = $event->computed_start_date;
					$description   = wp_strip_all_tags( $event->description ?? '' );
					if ( $desc_length > 0 ) {
						$description = wp_trim_words( $description, $desc_length, '...' );
					}
				?>
					<div class="static-grid-card">
						<div class="static-grid-image-wrapper">
							<a href="<?php echo esc_url( $event_url ); ?>" class="static-grid-image-link">
								<?php if ( $thumbnail_url ) : ?>
									<div class="static-grid-image" style="background-image: url('<?php echo esc_url( $thumbnail_url ); ?>');">
								<?php else : ?>
									<div class="static-grid-image" style="background-color: <?php echo esc_attr( $event->event_color ?: $fallback_color ); ?>;">
								<?php endif; ?>
									<?php if ( $show_date_badge && $start_date ) : ?>
										<div class="static-grid-date-badge">
											<span class="static-grid-date-day"><?php echo esc_html( date( 'd', strtotime( $start_date ) ) ); ?></span>
											<span class="static-grid-date-month"><?php echo esc_html( date( 'M', strtotime( $start_date ) ) ); ?></span>
										</div>
									<?php endif; ?>
									</div>
							</a>
						</div>

						<div class="static-grid-content">
							<?php if ( ! empty( $event->title ) ) : ?>
								<h3 class="static-grid-title">
									<a href="<?php echo esc_url( $event_url ); ?>">
										<?php echo esc_html( $event->title ); ?>
									</a>
								</h3>
							<?php endif; ?>

							<?php if ( $show_description && ! empty( $description ) ) : ?>
								<div class="static-grid-description">
									<?php echo esc_html( $description ); ?>
								</div>
							<?php endif; ?>

							<?php if ( $show_icon ) : ?>
								<a href="<?php echo esc_url( $event_url ); ?>" class="static-grid-icon" title="<?php echo esc_attr( $event->title ); ?>">
									<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M1 9L9 1M9 1H1M9 1V9" stroke="white" stroke-width="2.43" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}