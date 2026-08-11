<?php

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Event_Category_Archive_Condition extends Condition_Base {

	public static function get_type() {
		return 'archive';
	}

	public function get_name() {
		return 'event_category_archive';
	}

	public function get_label() {
		return esc_html__( 'Event Category', 'hbl' );
	}

	public function get_all_label() {
		return esc_html__( 'All Event Categories', 'hbl' );
	}

	public static function get_priority() {
		return 40;
	}

	public function check( $args ) {
		if ( ! is_tax( 'event_category' ) ) {
			return false;
		}

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

		return true;
	}

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
