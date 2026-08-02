<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Info;

use AddonskitForElementor\Utils\DirectoristHelper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Info extends Widget_Base {

	use Styles;

	public function get_name() {
		return 'directorist_single_listing_quickInfo';
	}

	public function get_title() {
		return __( 'Listing - Info', 'addonskit-for-elementor' );
	}

	public function get_icon() {
		return 'directorist-el-custom';
	}

	public function get_categories() {
		return ['theme-elements-single'];
	}

	public function get_keywords() {
		return [
			'quickinfo', 'info', 'badges', 'price', 'reviews', 'ratings_count', 'category', 'location',
		];
	}

	public function show_in_panel() {
		return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
	}

	protected function register_controls(): void {
		$this->register_contents();
		$this->register_info_style();
	}

	protected function register_contents(): void {

		$this->start_controls_section(
			'section_content',
			[
				'label' => esc_html__( 'Info', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			"widget_name",
			[
				'label'    => esc_html__( 'Select Info', 'addonskit-for-elementor' ),
				'type'     => Controls_Manager::SELECT2,
				'options'  => DirectoristHelper::get_header_quick_info_fields( 'more-widgets-placeholder' ),
				'multiple' => true,
				'default'  => ['ratings_count'],
			]
		);

		$this->add_responsive_control(
			'action_type_align',
			[
				'label'     => esc_html__( 'Alignment', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .directorist-listing-single__info' => 'justify-content: {{VALUE}};',
				],
				'default'   => '',
			]
		);

		$this->end_controls_section();
	}

	public function render(): void {
		$settings = $this->get_settings_for_display();

		echo '<div class="directorist-listing-single directorist-listing-single-quickinfo"><div class="directorist-listing-single__info" style="padding:0;">';

		foreach ( $settings['widget_name'] as $widget_name ) {
			DirectoristHelper::get_single_listing_info( $widget_name );
		}

		echo '</div></div>';
	}
}