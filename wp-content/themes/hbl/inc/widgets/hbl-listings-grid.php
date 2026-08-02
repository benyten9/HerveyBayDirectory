<?php
/**
 * HBL Listings Grid Widget
 *
 * Displays Directorist listings in a responsive grid layout
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL Listings Grid Widget Class
 */
class HBL_Listings_Grid extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-listings-grid';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Listings Grid', 'hbl' );
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
		return array( 'hbl', 'listings', 'grid', 'directorist', 'directory', 'business' );
	}

	/**
	 * Get script dependencies
	 */
	public function get_script_depends() {
		return array( 'swiper' );
	}

	/**
	 * Get style dependencies
	 */
	public function get_style_depends() {
		return array( 'swiper' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Content Settings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		// Number of listings
		$this->add_control(
			'number_of_listings',
			array(
				'label'   => esc_html__( 'Number of Listings to Show', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 9,
				'min'     => 1,
				'max'     => 12,
			)
		);

		// Get available categories for dynamic selection with event counts
		$categories = array();
		if ( taxonomy_exists( 'event_category' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'event_category',
				'hide_empty' => false,
			) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					// Count published events in this category
					$event_count = 0;
					if ( function_exists( 'hbl_events_db' ) ) {
						$event_count = hbl_events_db()->count_events( array(
							'category_id' => $term->term_id,
							'status'      => 'publish',
						) );
					} else {
						$event_count = $term->count;
					}
					
					// Add category with count
					$categories[ $term->term_id ] = $term->name . ' (' . $event_count . ' events)';
				}
			}
		}

		if ( empty( $categories ) ) {
			$categories[''] = esc_html__( 'No categories found', 'hbl' );
		}

		// Repeater for individual listing categories
		$repeater = new \Elementor\Repeater();

		$repeater->add_control(
			'listing_category',
			array(
				'label'   => esc_html__( 'Select Category', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'options' => $categories,
				'default' => '',
			)
		);

		$this->add_control(
			'listing_categories',
			array(
				'label'       => esc_html__( 'Listing Categories', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => array(
					array(
						'listing_category' => '',
					),
				),
				'title_field' => '{{{ listing_category }}}',
			)
		);


		$this->add_control(
			'show_image',
			array(
				'label'        => esc_html__( 'Show Featured Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_category_icon',
			array(
				'label'        => esc_html__( 'Show Category Icon', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_excerpt',
			array(
				'label'        => esc_html__( 'Show Excerpt', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'excerpt_length',
			array(
				'label'     => esc_html__( 'Excerpt Length (words)', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 20,
				'min'       => 5,
				'max'       => 100,
				'condition' => array(
					'show_excerpt' => 'yes',
				),
			)
		);

		$this->add_control(
			'order_by',
			array(
				'label'   => esc_html__( 'Order By', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'date',
				'options' => array(
					'date'          => esc_html__( 'Date', 'hbl' ),
					'title'         => esc_html__( 'Title', 'hbl' ),
					'modified'      => esc_html__( 'Modified Date', 'hbl' ),
					'rand'          => esc_html__( 'Random', 'hbl' ),
					'menu_order'    => esc_html__( 'Menu Order', 'hbl' ),
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

		$this->end_controls_section();

		// Style Section - Grid
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
					'{{WRAPPER}} .hbl-grid-container' => 'grid-template-columns: repeat({{VALUE}}, 1fr);',
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
					'{{WRAPPER}} .hbl-grid-container' => 'column-gap: {{SIZE}}{{UNIT}};',
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
					'{{WRAPPER}} .hbl-grid-container' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// Slider Settings
		$this->start_controls_section(
			'slider_section',
			array(
				'label' => esc_html__( 'Slider Settings (Tablet & Mobile)', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'slider_autoplay',
			array(
				'label'        => esc_html__( 'Autoplay', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'slider_autoplay_speed',
			array(
				'label'     => esc_html__( 'Autoplay Speed (ms)', 'hbl' ),
				'type'      => Controls_Manager::NUMBER,
				'default'   => 3000,
				'min'       => 1000,
				'max'       => 10000,
				'step'      => 500,
				'condition' => array(
					'slider_autoplay' => 'yes',
				),
			)
		);

		$this->add_control(
			'slider_speed',
			array(
				'label'   => esc_html__( 'Transition Speed (ms)', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 500,
				'min'     => 100,
				'max'     => 3000,
				'step'    => 100,
			)
		);

		$this->add_control(
			'slider_loop',
			array(
				'label'        => esc_html__( 'Loop', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_navigation',
			array(
				'label'        => esc_html__( 'Show Navigation Arrows', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);


		$this->end_controls_section();

		// Style Section - Card
		$this->start_controls_section(
			'style_card',
			array(
				'label' => esc_html__( 'Card', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->end_controls_section();

		// Style Section - Image
		$this->start_controls_section(
			'style_image',
			array(
				'label'     => esc_html__( 'Featured Image', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_image' => 'yes',
				),
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
					'{{WRAPPER}} .hbl-grid-image' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Section - Title
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
				'selector' => '{{WRAPPER}} .hbl-grid-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-grid-title' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-grid-title a' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-grid-title a:hover' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-grid-title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Section - Excerpt
		$this->start_controls_section(
			'style_excerpt',
			array(
				'label'     => esc_html__( 'Excerpt', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_excerpt' => 'yes',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'excerpt_typography',
				'selector' => '{{WRAPPER}} .hbl-grid-excerpt',
			)
		);

		$this->add_control(
			'excerpt_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-grid-excerpt' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'excerpt_spacing',
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
					'{{WRAPPER}} .hbl-grid-excerpt' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Section - Category Icon
		$this->start_controls_section(
			'style_category_icon',
			array(
				'label'     => esc_html__( 'Category Icon', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_category_icon' => 'yes',
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
					'{{WRAPPER}} .hbl-grid-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .hbl-grid-icon svg' => 'width: calc({{SIZE}}{{UNIT}} * 0.4); height: calc({{SIZE}}{{UNIT}} * 0.4);',
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
					'{{WRAPPER}} .hbl-grid-icon' => 'background-color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-grid-icon svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-grid-icon:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_hover_arrow_color',
			array(
				'label'     => esc_html__( 'Hover Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-grid-icon:hover svg' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Section - Slider Navigation
		$this->start_controls_section(
			'style_slider_navigation',
			array(
				'label'     => esc_html__( 'Slider Navigation', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_navigation' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'navigation_button_size',
			array(
				'label'      => esc_html__( 'Button Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 20,
						'max' => 80,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 40,
				),
				'selectors'  => array(
					'{{WRAPPER}} .swiper-button-next' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .swiper-button-prev' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'navigation_arrow_size',
			array(
				'label'      => esc_html__( 'Arrow Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 10,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 20,
				),
				'selectors'  => array(
					'{{WRAPPER}} .swiper-button-next:after' => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .swiper-button-prev:after' => 'font-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'navigation_color',
			array(
				'label'     => esc_html__( 'Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .swiper-button-next' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-next:after' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev:after' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'navigation_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .swiper-button-next' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'navigation_hover_color',
			array(
				'label'     => esc_html__( 'Hover Arrow Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .swiper-button-next:hover' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev:hover' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-next:hover:after' => 'color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev:hover:after' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'navigation_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .swiper-button-next:hover' => 'background-color: {{VALUE}};',
					'{{WRAPPER}} .swiper-button-prev:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// Style Section - Pagination Colors
		$this->start_controls_section(
			'style_pagination_colors',
			array(
				'label'     => esc_html__( 'Pagination Colors', 'hbl' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_pagination' => 'yes',
				),
			)
		);

		$this->add_control(
			'pagination_bullet_color',
			array(
				'label'     => esc_html__( 'Bullet Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#CCCCCC',
				'selectors' => array(
					'{{WRAPPER}} .swiper-pagination-bullet' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'pagination_bullet_active_color',
			array(
				'label'     => esc_html__( 'Active Bullet Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .swiper-pagination-bullet-active' => 'background-color: {{VALUE}};',
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

		// Collect all events from different categories (1 per category)
		$all_events = array();
		$listing_categories = $settings['listing_categories'];
		$max_events = isset( $settings['number_of_listings'] ) ? intval( $settings['number_of_listings'] ) : 9;

		$used_categories = array(); // Track how many events we've taken from each category

		if ( ! empty( $listing_categories ) && function_exists( 'hbl_events_db' ) ) {
			foreach ( $listing_categories as $index => $cat_item ) {
				// Stop if we've reached the maximum number of events
				if ( count( $all_events ) >= $max_events ) {
					break;
				}

				// Skip if category is not set or empty
				if ( ! isset( $cat_item['listing_category'] ) || empty( $cat_item['listing_category'] ) ) {
					continue;
				}

				$category_id = intval( $cat_item['listing_category'] );
				
				// Skip invalid category IDs
				if ( $category_id <= 0 ) {
					continue;
				}

				// Track offset for this category (to get next event if category repeats)
				if ( ! isset( $used_categories[ $category_id ] ) ) {
					$used_categories[ $category_id ] = 0;
				}
				$offset = $used_categories[ $category_id ];

				// Map orderby
				$orderby = 'start_date';
				if ( $settings['order_by'] === 'title' ) {
					$orderby = 'title';
				} elseif ( $settings['order_by'] === 'modified' ) {
					$orderby = 'updated_at';
				}

				// Get 1 event from this category (with offset for duplicates)
				$args = array(
					'category_id' => $category_id,
					'status'      => 'publish',
					'orderby'     => $orderby,
					'order'       => $settings['order'],
					'limit'       => 1,
					'offset'      => $offset,
					'upcoming'    => true,
				);

				$events = hbl_events_db()->get_events( $args );

				if ( ! empty( $events ) ) {
					$all_events[] = $events[0];
					$used_categories[ $category_id ]++;
				}
			}
		}

		if ( empty( $all_events ) ) {
			echo '<div class="hbl-grid-no-listings">';
			echo '<p>' . esc_html__( 'No upcoming events found in the selected categories.', 'hbl' ) . '</p>';
			echo '</div>';
			return;
		}

		// Prepare slider settings
		$slider_settings = array(
			'autoplay'      => $settings['slider_autoplay'] === 'yes',
			'autoplaySpeed' => isset( $settings['slider_autoplay_speed'] ) ? intval( $settings['slider_autoplay_speed'] ) : 3000,
			'speed'         => isset( $settings['slider_speed'] ) ? intval( $settings['slider_speed'] ) : 500,
			'loop'          => $settings['slider_loop'] === 'yes',
			'navigation'    => $settings['show_navigation'] === 'yes',
		);

		?>
		<div class="hbl-grid-wrapper" data-slider-settings='<?php echo esc_attr( wp_json_encode( $slider_settings ) ); ?>'>
			<!-- Desktop Grid Container -->
			<div class="hbl-grid-container hbl-desktop-grid">
				<?php
				foreach ( $all_events as $event ) :
					$event_id = $event->id;
					$title = $event->title;
					$url = hbl_events_db()->get_event_url( $event );
					$image_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
					
					// Description/Excerpt
					$excerpt = $event->description;
					$excerpt = wp_trim_words( $excerpt, $settings['excerpt_length'], '...' );
					?>
					<div class="hbl-grid-card">
						<?php if ( 'yes' === $settings['show_image'] ) : ?>
							<div class="hbl-grid-image-wrapper">
								<a href="<?php echo esc_url( $url ); ?>" class="hbl-grid-image-link">
									<?php if ( $image_url ) : ?>
										<div class="hbl-grid-image" style="background-image: url('<?php echo esc_url( $image_url ); ?>');">
										</div>
									<?php else : ?>
										<div class="hbl-grid-image hbl-grid-no-image">
											<span class="hbl-grid-no-image-text"><?php esc_html_e( 'No Image', 'hbl' ); ?></span>
										</div>
									<?php endif; ?>
								</a>
							</div>
						<?php endif; ?>

						<div class="hbl-grid-content">
							<h3 class="hbl-grid-title">
								<a href="<?php echo esc_url( $url ); ?>">
									<?php echo esc_html( $title ); ?>
								</a>
							</h3>

							<?php if ( 'yes' === $settings['show_excerpt'] ) : ?>
								<div class="hbl-grid-excerpt">
									<?php echo esc_html( $excerpt ); ?>
								</div>
							<?php endif; ?>

							<?php if ( 'yes' === $settings['show_category_icon'] ) : ?>
								<?php
								if ( ! empty( $event->category_id ) ) {
									$term = get_term( $event->category_id );
									if ( ! is_wp_error( $term ) && $term ) {
										$category_link = get_term_link( $term );
										?>
										<a href="<?php echo esc_url( $category_link ); ?>" class="hbl-grid-icon" title="<?php echo esc_attr( $term->name ); ?>">
											<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M1 9L9 1M9 1H1M9 1V9" stroke="white" stroke-width="2.43" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</a>
										<?php
									}
								}
								?>
							<?php endif; ?>
						</div>
					</div>
					<?php
				endforeach;
				?>
			</div>

			<!-- Mobile/Tablet Slider Container -->
			<div class="hbl-mobile-slider swiper">
				<div class="swiper-wrapper">
					<?php
					foreach ( $all_events as $event ) :
						$title = $event->title;
						$url = hbl_events_db()->get_event_url( $event );
						$image_url = $event->featured_image ? wp_get_attachment_image_url( $event->featured_image, 'large' ) : '';
						
						// Description/Excerpt
						$excerpt = $event->description;
						$excerpt = wp_trim_words( $excerpt, $settings['excerpt_length'], '...' );
						?>
						<div class="swiper-slide">
							<div class="hbl-grid-card">
								<?php if ( 'yes' === $settings['show_image'] ) : ?>
									<div class="hbl-grid-image-wrapper">
										<a href="<?php echo esc_url( $url ); ?>" class="hbl-grid-image-link">
											<?php if ( $image_url ) : ?>
												<div class="hbl-grid-image" style="background-image: url('<?php echo esc_url( $image_url ); ?>');">
												</div>
											<?php else : ?>
												<div class="hbl-grid-image hbl-grid-no-image">
													<span class="hbl-grid-no-image-text"><?php esc_html_e( 'No Image', 'hbl' ); ?></span>
												</div>
											<?php endif; ?>
										</a>
									</div>
								<?php endif; ?>

								<div class="hbl-grid-content">
									<h3 class="hbl-grid-title">
										<a href="<?php echo esc_url( $url ); ?>">
											<?php echo esc_html( $title ); ?>
										</a>
									</h3>

									<?php if ( 'yes' === $settings['show_excerpt'] ) : ?>
										<div class="hbl-grid-excerpt">
											<?php echo esc_html( $excerpt ); ?>
										</div>
									<?php endif; ?>

									<?php if ( 'yes' === $settings['show_category_icon'] ) : ?>
										<?php
										if ( ! empty( $event->category_id ) ) {
											$term = get_term( $event->category_id );
											if ( ! is_wp_error( $term ) && $term ) {
												$category_link = get_term_link( $term );
												?>
												<a href="<?php echo esc_url( $category_link ); ?>" class="hbl-grid-icon" title="<?php echo esc_attr( $term->name ); ?>">
													<svg width="10" height="10" viewBox="0 0 10 10" fill="none" xmlns="http://www.w3.org/2000/svg">
														<path d="M1 9L9 1M9 1H1M9 1V9" stroke="white" stroke-width="2.43" stroke-linecap="round" stroke-linejoin="round"/>
													</svg>
												</a>
												<?php
											}
										}
										?>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php
					endforeach;
					?>
				</div>

				<!-- Navigation Arrows -->
				<?php if ( 'yes' === $settings['show_navigation'] ) : ?>
					<div class="swiper-button-next"></div>
					<div class="swiper-button-prev"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}