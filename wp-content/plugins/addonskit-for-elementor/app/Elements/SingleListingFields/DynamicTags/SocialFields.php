<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\DynamicTags;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag as Tags;
use Elementor\Modules\DynamicTags\Module;

class SocialFields extends Tags {
	public function get_name() {
		return 'social_field';
	}

	public function get_title() {
		return esc_html__( 'Listing Social Fields', 'addonskit-for-elementor' );
	}

	public function get_group() {
		return 'directorist';
	}

	public function get_categories() {
		return [
			Module::URL_CATEGORY,
		];
	}

	protected function register_controls(): void {
		$this->add_control(
			"fields",
			[
				'label'   => esc_html__( 'Select Social', 'addonskit-for-elementor' ),
				'type'    => Controls_Manager::SELECT,
				'options' => ATBDP()->helper->social_links(),
			]
		);
	}

	public function render(): void {
		$settings = $this->get_settings();
		$socials  = get_post_meta( get_the_ID(), '_social', true );

		foreach ( $socials as $social ) {
			if ( $social['id'] === $settings['fields'] ) {
				echo esc_url( $social['id'] );

				return;
			}
		}
	}
}