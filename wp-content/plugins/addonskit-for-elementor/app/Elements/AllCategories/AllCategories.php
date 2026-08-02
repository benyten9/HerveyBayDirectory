<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AllCategories;

use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\DirectoryTypeStyles;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Utils\DirectoristHelper;
use AddonskitForElementor\Utils\DirectoristTaxonomies;
use AddonskitForElementor\Utils\Helper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AllCategories extends Widget_Base {

	use DirectoryTypeStyles;
	use TextControls;
	use Container;
	use Styles;

	public function get_name() {
		return 'directorist_all_categories';
	}

	public function get_title() {
		return __( 'All Categories', 'addonskit-for-elementor' );
	}

	public function get_icon() {
		return 'directorist-el-custom';
	}

	public function get_categories() {
		return ['directorist-widgets'];
	}

	public function get_keywords() {
		return [
			'all-categories', 'category', 'categories', 'directorist',
		];
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
				'label'     => __( 'Directory Types', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::directory_types(),
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
				'separator' => 'before',
			]
		);

		$this->add_control(
			'default_type',
			[
				'label'     => __( 'Active Directory', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::directory_types(),
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);

		$this->add_control(
			'view',
			[
				'label'     => __( 'View As', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'grid' => __( 'Grid View', 'addonskit-for-elementor' ),
					'list' => __( 'List View', 'addonskit-for-elementor' ),
				],
				'default'   => 'grid',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'columns',
			[
				'label'     => __( 'Categories Per Row', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'label_on'  => esc_html__( 'Show', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Hide', 'addonskit-for-elementor' ),
				'options'   => [
					'6' => __( '6 Items / Row', 'addonskit-for-elementor' ),
					'5' => __( '5 Items / Row', 'addonskit-for-elementor' ),
					'4' => __( '4 Items / Row', 'addonskit-for-elementor' ),
					'3' => __( '3 Items / Row', 'addonskit-for-elementor' ),
					'2' => __( '2 Items / Row', 'addonskit-for-elementor' ),
				],
				'default'   => '3',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'order_by',
			[
				'label'     => __( 'Order by', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'id'    => __( 'ID', 'addonskit-for-elementor' ),
					'count' => __( 'Count', 'addonskit-for-elementor' ),
					'name'  => __( 'Name', 'addonskit-for-elementor' ),
					'slug'  => __( 'Slug', 'addonskit-for-elementor' ),
				],
				'default'   => 'id',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'slug',
			[
				'label'     => __( 'Specify Categories', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::listing_categories(),
				'condition' => ['order_by' => ['slug']],
				'separator' => 'before',
			]
		);

		$this->add_control(
			'order_list',
			[
				'label'     => __( 'Categories Order', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'asc'  => __( ' ASC', 'addonskit-for-elementor' ),
					'desc' => __( ' DESC', 'addonskit-for-elementor' ),
				],
				'default'   => 'desc',
				'separator' => 'before',
			]
		);

		$this->add_control(
			'number_cat',
			[
				'label'       => __( 'Number of Categories to Show', 'addonskit-for-elementor' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => -1,
				'max'         => 100,
				'step'        => 1,
				'default'     => 6,
				'separator'   => 'before',
				'description' => __( 'Set -1 to display all categories', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			'user',
			[
				'label'     => __( 'Only For Logged In User?', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Yes', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'No', 'addonskit-for-elementor' ),
				'default'   => 'no',
				'separator' => 'before',
			]
		);

		$this->end_controls_section();
	}

	protected function register_styles(): void {

		if ( directorist_is_multi_directory_enabled() ) {

			$this->register_container_style_controls(
				__( 'Type: Container', 'addonskit-for-elementor' ),
				'directory_type_area',
				'.directorist-type-nav__list',
			);

			$this->register_directory_type_style_controls(
				'.directorist-type-nav__list .directorist-type-nav__link',
				[],
				'.directorist-type-nav__list .current .directorist-type-nav__link'
			);
		}

		$this->register_category_card_style_controls();

		$this->register_category_title_controls();

		$this->register_category_icon_styles();

		$this->register_text_controls(
			__( 'Listing Count', 'addonskit-for-elementor' ),
			'category_listing_found',
			'.directorist-categories__single__total',
			['view' => 'grid']
		);
	}

	protected function render(): void {
		$settings = $this->get_settings();
		$type     = empty( $settings['type'] ) ? [] : $settings['type'];
		$slug     = empty( $settings['slug'] ) ? [] : $settings['slug'];
		$atts     = [
			'view'                => $settings['view'],
			'columns'             => $settings['columns'],
			'cat_per_page'        => empty($settings['number_cat']) ? 3 : $settings['number_cat'],
			'orderby'             => $settings['order_by'],
			'order'               => $settings['order_list'],
			'logged_in_user_only' => $settings['user'] ?? 'no',
			'slug'                => $slug ? implode( ',', $slug ) : '',
		];

		if ( directorist_is_multi_directory_enabled() ) {
			if ( is_array( $type ) ) {
				$atts['directory_type'] = implode( ',', $type );
			}

			$atts['default_directory_type'] = $settings['default_type'] ?: 'all';
		}

		/**
		 * Filters the Elementor All Listing atts to modify or extend it
		 *
		 * @since 1.0.0
		 *
		 * @param array     $atts       Available atts in the widgets
		 * @param array     $settings   All the settings of the widget
		 */
		$atts = apply_filters( 'directorist_all_categories_elementor_widget_atts', $atts, $settings );

		Helper::run_shortcode( 'directorist_all_categories', $atts );
	}
}