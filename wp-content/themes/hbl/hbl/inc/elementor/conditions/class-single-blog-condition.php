<?php
/**
 * Elementor Theme Builder Condition: Single Blog Post
 * 
 * Adds a condition to display templates on single blog posts
 * (posts that are NOT events - without _piecal_is_event meta or = 0)
 *
 * @package HBL
 * @since 1.2.964
 */

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Single Blog Post Condition Class
 */
class Single_Blog_Condition extends Condition_Base {

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
		return 'hbl_single_blog';
	}

	/**
	 * Get condition label
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'Single Blog Post', 'hbl' );
	}

	/**
	 * Get all label (for displaying in conditions list)
	 *
	 * @return string
	 */
	public function get_all_label() {
		return esc_html__( 'All Blog Posts', 'hbl' );
	}

	/**
	 * Get condition priority
	 * Lower number = higher priority
	 *
	 * @return int
	 */
	public static function get_priority() {
		return 25; // Higher priority than regular posts and events
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
		
		// Check if specific blog post ID is requested
		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$id = (int) $args['id'];
			if ( $id && $post_id !== $id ) {
				return false;
			}
		}

		// Check if the post is NOT an event (no _piecal_is_event meta or = 0)
		$is_event = get_post_meta( $post_id, '_piecal_is_event', true );
		
		// Return true if it's not an event (regular blog post)
		return empty( $is_event ) || $is_event !== '1';
	}

	/**
	 * Register controls for the condition (optional)
	 * Allows selecting specific blog posts
	 */
	protected function register_controls() {
		// Add control to select specific blog post
		$this->add_control(
			'blog_id',
			[
				'section' => 'settings',
				'type' => 'select2',
				'select2options' => [
					'dropdownCssClass' => 'elementor-conditions-select2-dropdown',
					'placeholder' => esc_html__( 'All Blog Posts', 'hbl' ),
					'allowClear' => true,
				],
				'options' => $this->get_blog_posts_options(),
			]
		);
	}

	/**
	 * Get blog posts for the select dropdown
	 *
	 * @return array
	 */
	private function get_blog_posts_options() {
		$options = [];
		
		$blog_posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_piecal_is_event',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => '_piecal_is_event',
					'value'   => '1',
					'compare' => '!=',
				],
			],
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		foreach ( $blog_posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}
}
