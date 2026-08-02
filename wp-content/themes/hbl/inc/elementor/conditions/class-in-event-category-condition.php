<?php
/**
 * Elementor Theme Builder Condition: In Event Category
 * 
 * Adds a sub-condition to display templates on events in specific categories
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
 * In Event Category Condition Class
 */
class In_Event_Category_Condition extends Condition_Base {

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
		return 'in_event_category';
	}

	/**
	 * Get condition label
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'In Event Category', 'hbl' );
	}

	/**
	 * Get all label (for displaying in conditions list)
	 *
	 * @return string
	 */
	public function get_all_label() {
		return esc_html__( 'Events In Category', 'hbl' );
	}

	/**
	 * Get condition priority
	 *
	 * @return int
	 */
	public static function get_priority() {
		return 20; // Higher priority than single event
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

		// Check if the post is an event
		$is_event = get_post_meta( $post_id, '_piecal_is_event', true );
		if ( $is_event !== '1' ) {
			return false;
		}

		// Check if specific category ID is requested
		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$category_id = (int) $args['id'];
			if ( $category_id ) {
				return has_term( $category_id, 'event_category', $post_id );
			}
		}

		// If no specific category, just check if post has any event category
		$terms = get_the_terms( $post_id, 'event_category' );
		return ! empty( $terms ) && ! is_wp_error( $terms );
	}

	/**
	 * Register controls for the condition
	 */
	protected function register_controls() {
		$categories = get_terms( [
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
		] );

		$options = [];
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $category ) {
				$options[ $category->term_id ] = $category->name;
			}
		}

		$this->add_control(
			'event_category_id',
			[
				'section' => 'settings',
				'type' => 'select2',
				'select2options' => [
					'dropdownCssClass' => 'elementor-conditions-select2-dropdown',
					'placeholder' => esc_html__( 'Select Category', 'hbl' ),
					'allowClear' => true,
				],
				'options' => $options,
			]
		);
	}
}

