<?php

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Single_Event_Condition extends Condition_Base {

	public static function get_type() {
		return 'singular';
	}

	public function get_name() {
		return 'hbl_single_event';
	}

	public function get_label() {
		return esc_html__( 'Single Event', 'hbl' );
	}

	public function get_all_label() {
		return esc_html__( 'All Events', 'hbl' );
	}

	public static function get_priority() {
		return 30;
	}

	public function check( $args ) {
		if ( get_query_var( 'hbl_event_slug' ) ) {
			return true;
		}

		if ( ! is_singular( 'post' ) ) {
			return false;
		}

		$post_id = get_queried_object_id();

		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$id = (int) $args['id'];
			if ( $id && $post_id !== $id ) {
				return false;
			}
		}

		$is_event = get_post_meta( $post_id, '_piecal_is_event', true );

		return $is_event === '1';
	}

	public function register_sub_conditions() {
		if ( taxonomy_exists( 'event_category' ) ) {
			require_once HBL_THEME_DIR . '/inc/elementor/conditions/class-in-event-category-condition.php';
			$in_category = new In_Event_Category_Condition();
			$this->register_sub_condition( $in_category );
		}
	}

	protected function register_controls() {
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

