<?php
/**
 * Elementor Theme Builder Condition: Single Event
 * 
 * Adds a condition to display templates on single event posts
 * (posts with _piecal_is_event meta = 1)
 *
 * @package HBL
 * @since 1.2.643
 */

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Single Event Condition Class
 */
class Single_Event_Condition extends Condition_Base {

	/**
	 * Get condition type
	 *
	 * @return string
	 */
	public static function get_type() {
		return 'singular';
	}

	/**
	 * Get condition name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'hbl_single_event';
	}

	/**
	 * Get condition label
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Single Event', 'hbl' );
	}

	/**
	 * Get all label (for displaying in conditions list)
	 *
	 * @return string
	 */
	public function get_all_label() {
		return esc_html__( 'All Events', 'hbl' );
	}

	/**
	 * Get condition priority
	 * Lower number = higher priority
	 *
	 * @return int
	 */
	public static function get_priority() {
		return 30; // Higher priority than regular posts (40)
	}

	/**
	 * Check if condition is met
	 *
	 * @param array $args Condition arguments
	 * @return bool
	 */
	public function check( $args ) {
		// Must be a singular post page
		if ( ! is_singular( 'post' ) ) {
			return false;
		}

		$post_id = get_queried_object_id();
		
		// Check if specific event ID is requested
		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$id = (int) $args['id'];
			if ( $id && $post_id !== $id ) {
				return false;
			}
		}

		// Check if the post is an event (has _piecal_is_event = 1)
		$is_event = get_post_meta( $post_id, '_piecal_is_event', true );
		
		return $is_event === '1';
	}

	/**
	 * Register sub-conditions (optional)
	 * Can add sub-conditions like "Event in Category X"
	 */
	public function register_sub_conditions() {
		// Register event category sub-condition if event_category taxonomy exists
		if ( taxonomy_exists( 'event_category' ) ) {
			require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-in-event-category-condition.php';
			$in_category = new In_Event_Category_Condition();
			$this->register_sub_condition( $in_category );
		}
	}

	/**
	 * Register controls for the condition (optional)
	 * Allows selecting specific events
	 */
	protected function register_controls() {
		// Add control to select specific event
		$this->add_control(
			'event_id',
			[
				'section' => 'settings',
				'type' => 'select2',
				'select2options' => [
					'dropdownCssClass' => 'elementor-conditions-select2-dropdown',
					'placeholder' => esc_html__( 'All Events', 'hbl' ),
					'allowClear' => true,
				],
				'options' => $this->get_events_options(),
			]
		);
	}

	/**
	 * Get events for the select dropdown
	 *
	 * @return array
	 */
	private function get_events_options() {
		$options = [];
		
		$events = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => [
				[
					'key'     => '_piecal_is_event',
					'value'   => '1',
					'compare' => '=',
				],
			],
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		foreach ( $events as $event ) {
			$options[ $event->ID ] = $event->post_title;
		}

		return $options;
	}
}

