<?php
/**
 * Elementor Theme Builder Condition: Event Category Archive
 * 
 * Adds a condition to display templates on event category archive pages
 * URL pattern: /whats-on/category/{term-slug}/
 *
 * @package HBL
 * @since 1.3.0
 */

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Event Category Archive Condition Class
 */
class Event_Category_Archive_Condition extends Condition_Base {

	/**
	 * Get condition type
	 *
	 * @return string
	 */
	public static function get_type() {
		return 'archive';
	}

	/**
	 * Get condition name
	 *
	 * @return string
	 */
	public function get_name() {
		return 'event_category_archive';
	}

	/**
	 * Get condition label
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Event Category', 'hbl' );
	}

	/**
	 * Get all label (for displaying in conditions list)
	 *
	 * @return string
	 */
	public function get_all_label() {
		return esc_html__( 'All Event Categories', 'hbl' );
	}

	/**
	 * Get condition priority
	 * Lower number = higher priority
	 *
	 * @return int
	 */
	public static function get_priority() {
		return 40;
	}

	/**
	 * Check if condition is met
	 *
	 * @param array $args Condition arguments
	 * @return bool
	 */
	public function check( $args ) {
		// Check if we're on an event_category taxonomy archive
		if ( ! is_tax( 'event_category' ) ) {
			return false;
		}

		// If specific category ID is requested
		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$term_id = (int) $args['id'];
			if ( $term_id ) {
				$queried_object = get_queried_object();
				if ( $queried_object && isset( $queried_object->term_id ) ) {
					return $queried_object->term_id === $term_id;
				}
				return false;
			}
		}

		// All event category archives
		return true;
	}

	/**
	 * Register controls for the condition
	 * Allows selecting specific event categories
	 */
	protected function register_controls() {
		$categories = get_terms( [
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
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
					'placeholder' => esc_html__( 'All Event Categories', 'hbl' ),
					'allowClear' => true,
				],
				'options' => $options,
			]
		);
	}
}
