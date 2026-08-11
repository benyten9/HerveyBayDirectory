<?php

namespace HBL\Elementor\Conditions;

use ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Single_Blog_Condition extends Condition_Base {

	public static function get_type() {
		return 'singular';
	}

	public function get_name() {
		return 'hbl_single_blog';
	}

	public function get_label() {
		return esc_html__( 'Single Blog Post', 'hbl' );
	}

	public function get_all_label() {
		return esc_html__( 'All Blog Posts', 'hbl' );
	}

	public static function get_priority() {
		return 25;
	}

	public function check( $args ) {
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
		
		return empty( $is_event ) || $is_event !== '1';
	}

	protected function register_controls() {
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
