<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AddListing;

use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Utils\DirectoristTaxonomies;
use AddonskitForElementor\Utils\Helper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AddListing extends Widget_Base {

	use Styles;
	use Container;
	use TextControls;

	public function get_name() {
		return 'directorist_add_listing';
	}

	public function get_title() {
		return __( 'Add Listing Form', 'addonskit-for-elementor' );
	}

	public function get_icon() {
		return 'directorist-el-custom';
	}

	public function get_categories() {
		return ['directorist-widgets'];
	}

	public function get_keywords() {
		return [
			'add listing form', 'add-listing-form', 'form', 'add listing', 'submit listing',
		];
	}

	public function get_script_depends() {
		return ['directorist-select2-script', 'directorist-add-listing'];
	}

	protected function register_controls(): void {
		$this->register_contents();
		$this->register_styles();
	}

	protected function register_contents(): void {
		$this->start_controls_section(
			'sec_general',
			[
				'label' => __( 'General', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			'type',
			[
				'label'       => __( 'Directory Types', 'addonskit-for-elementor' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => DirectoristTaxonomies::directory_types(),
				'condition'   => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
				'description' => __( 'Leave it empty for showing all directory types', 'addonskit-for-elementor' ),
				'separator'   => 'before',
				'default'     => [],
			]
		);

		$this->end_controls_section();
	}

	protected function register_styles(): void {
		// Directory Type
		$this->register_container_style_controls( __( 'Directory Type: Container', 'addonskit-for-elementor' ), 'add_listing_directory_type_container', '.directorist-w-100', directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true], );
		$this->register_add_listing_directory_controls('.directorist-add-listing-types__single__link');

		//Listing Form
		$this->register_enable_multi_dir();
		
		$this->register_add_listing_sidebar_controls('.multistep-wizard__nav__btn');
		$this->register_add_listing_form_container_controls();
		$this->register_section_name_controls( __( 'Form: Title', 'addonskit-for-elementor' ), 'form_title', '.directorist-content-module__title h2' );
		$this->register_add_listing_form_fields( __( 'Form: Fields', 'addonskit-for-elementor' ), 'form_fields', '.directorist-form-label' );
		//$this->register_add_listing_form_progressbar_controls();
		$this->register_add_listing_form_buttons_controls();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$type     = $settings['type'] ?? [];
		$atts['directory_type'] = implode( ',', $type );

		$atts = apply_filters( 'directorist_add_listing_elementor_widget_atts', $atts, $settings );

		Helper::run_shortcode( 'directorist_add_listing', $atts );
	}
}