<?php

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class In_Event_Category_Condition extends Condition_Base {

	public static function get_type() {
		return 'singular';
	}

	public function get_name() {
		return 'in_event_category';
	}

	public function get_label() {
		return esc_html__( 'In Event Category', 'hbl' );
	}

	public function get_all_label() {
		return esc_html__( 'Events In Category', 'hbl' );
	}

	public static function get_priority() {
		return 20;
	}

	public function check( $args ) {
		if ( ! is_singular( 'post' ) ) {
			return false;
		}

		$post_id = get_queried_object_id();

		$is_event = get_post_meta( $post_id, '_piecal_is_event', true );
		if ( $is_event !== '1' ) {
			return false;
		}

		if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
			$category_id = (int) $args['id'];
			if ( $category_id ) {
				return has_term( $category_id, 'event_category', $post_id );
			}
		}

		$terms = get_the_terms( $post_id, 'event_category' );
		return ! empty( $terms ) && ! is_wp_error( $terms );
	}

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

