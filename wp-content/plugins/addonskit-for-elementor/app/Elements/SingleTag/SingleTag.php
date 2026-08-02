<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleTag;

use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\DirectoryTypeStyles;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Elements\SearchListing\Styles as SearchStyles;
use AddonskitForElementor\Elements\UserDashboard\Styles as DashboardStyles;
use AddonskitForElementor\Elements\AllListings\Styles;
use AddonskitForElementor\Utils\DirectoristTaxonomies;
use AddonskitForElementor\Utils\Helper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SingleTag extends Widget_Base {
    // Traits
    use DirectoryTypeStyles;
    use TextControls;
    use Container;
    use Styles;
    use SearchStyles;
    use DashboardStyles;

    // Widget Configuration
    public function get_name(): string {
        return 'directorist_tag';
    }

    public function get_title(): string {
        return __('Single Tag', 'addonskit-for-elementor');
    }

    public function get_icon(): string {
        return 'directorist-el-custom';
    }

    public function get_categories(): array {
        return ['directorist-widgets'];
    }

    public function get_keywords(): array {
        return ['tag', 'taxonomy', 'single-tag'];
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {
        // General Section
        $this->start_controls_section(
            'section_general',
            [
                'label' => __('General Settings', 'addonskit-for-elementor'),
                'tab' => Controls_Manager::TAB_CONTENT,
            ]
        );

        // Directory Type Settings
        $this->add_directory_type_controls();

        // Listing Configuration
        $this->add_listing_configuration_controls();

        // Filter & Display Controls  
        $this->add_filter_display_controls();

        // Sorting & Pagination
        $this->add_sorting_pagination_controls();

        $this->end_controls_section();
    }

    protected function add_directory_type_controls(): void {
        $this->add_control(
			'multi_directory',
			[
				'label' => esc_html__( 'Directory Type Settings', 'addonskit-for-elementor' ),
				'type' => Controls_Manager::HEADING,
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);

		$this->add_control(
			'type',
			[
				'label'     => __( 'Select Types', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::directory_types(),
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
				'description'     => __( 'Leave empty to display all directory types', 'addonskit-for-elementor' ),
			]
		);

		$this->add_control(
			'default_type',
			[
				'label'     => __( 'Active Type', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::directory_types(),
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);

		$this->add_responsive_control(
			'type_align',
			[
				'label'     => esc_html__( 'Type Alignment', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'start'  => [
						'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-center',
					],
					'end'    => [
						'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'default'   => '',
				'selectors' => [
					'{{WRAPPER}} .directorist-type-nav .directorist-type-nav__list' => 'justify-content: {{VALUE}};',
				],
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);
		
		$this->add_responsive_control(
			'type_display',
			[
				'label'     => esc_html__( 'Icon Position', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'top' => [
						'title' => esc_html__( 'Default', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-arrow-up',
					],
					'column-reverse' => [
						'title' => esc_html__( 'Column Reverse', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-arrow-down',
					],
					'row'  => [
						'title' => esc_html__( 'Row', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-arrow-left',
					],
					'row-reverse' => [
						'title' => esc_html__( 'Row Reverse', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-arrow-right',
					],
				],
				'default'   => 'top',
				'selectors' => [
					'{{WRAPPER}} .directorist-type-nav .directorist-type-nav__link' => 'flex-direction: {{VALUE}};',
					'{{WRAPPER}} .directorist-type-nav .directorist-type-nav__list .directorist-icon-mask' => 'margin-bottom: 0px;',
				],
				'condition' => directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true],
			]
		);
    }

    protected function add_listing_configuration_controls(): void {
        $this->add_control(
			'listing_area',
			[
				'label' => esc_html__( 'Listing Configuration', 'addonskit-for-elementor' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'header',
			[
				'label'     => __( 'Display Header Section', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'yes',
			]
		);

		$this->add_control(
			'header_title',
			[
				'label'     => __( 'Listings Found Text', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Listings Found', 'addonskit-for-elementor' ),
				'condition' => ['header' => 'yes'],
			]
		);

		$this->add_control(
			'sidebar',
			[
				'label'     => __( 'Sidebar Options', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					''              => __( 'Default', 'addonskit-for-elementor' ),
					'left_sidebar'  => __( 'Left', 'addonskit-for-elementor' ),
					'right_sidebar' => __( 'Right', 'addonskit-for-elementor' ),
					'no_sidebar'    => __( 'No Sidebar', 'addonskit-for-elementor' ),
				],
				'default'   => '',
			]
		);

		$this->add_control(
			'filter',
			[
				'label'     => __( 'Enable Filter Button', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'no',
				'condition' => ['header' => 'yes', 'sidebar' => 'no_sidebar'],
			]
		);
    }

    protected function add_filter_display_controls(): void {
        $this->add_control(
			'preview',
			[
				'label'     => __( 'Display Preview Image', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'yes',
			]
		);

		$this->add_control(
			'view',
			[
				'label'     => __( 'View As', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'grid' => __( 'Grid', 'addonskit-for-elementor' ),
					'list' => __( 'List', 'addonskit-for-elementor' ),
					'map'  => __( 'Map', 'addonskit-for-elementor' ),
				],
				'default'   => 'grid',
			]
		);

		$this->add_control(
			'map_height',
			[
				'label'     => __( 'Map Height', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 300,
				'max'       => 1980,
				'default'   => 500,
				'condition' => ['view' => ['map']],
			]
		);

		$this->add_control(
			'columns',
			[
				'label'     => __( 'Grid Columns', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'label_on'  => esc_html__( 'Show', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Hide', 'addonskit-for-elementor' ),
				'options'   => [
					'6' => __( '6 Columns', 'addonskit-for-elementor' ),
					'4' => __( '4 Columns', 'addonskit-for-elementor' ),
					'3' => __( '3 Columns', 'addonskit-for-elementor' ),
					'2' => __( '2 Columns', 'addonskit-for-elementor' ),
				],
				'default'   => '3',
				'condition' => ['view' => 'grid'],
			]
		);

		$this->add_control(
			'featured',
			[
				'label'     => __( 'Show Featured Listings Only', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'no',
			]
		);

		$this->add_control(
			'popular',
			[
				'label'     => __( 'Show Popular Listings Only', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'no',
			]
		);

		$this->add_control(
			'order_by',
			[
				'label'     => __( 'Order by', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'title' => __( 'Title', 'addonskit-for-elementor' ),
					'date'  => __( 'Date', 'addonskit-for-elementor' ),
					'price' => __( 'Price', 'addonskit-for-elementor' ),
				],
				'default'   => 'date',
			]
		);

		$this->add_control(
			'order_list',
			[
				'label'     => __( 'Order', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT,
				'options'   => [
					'asc'  => __( ' ASC', 'addonskit-for-elementor' ),
					'desc' => __( ' DESC', 'addonskit-for-elementor' ),
				],
				'default'   => 'desc',
			]
		);

		// $this->add_control(
		// 	'order_type',
		// 	[
		// 		'label'     => __( 'Order Type', 'addonskit-for-elementor' ),
		// 		'type'      => Controls_Manager::SELECT,
		// 		'options'   => [
		// 			'regular'   => __( 'Regular', 'addonskit-for-elementor' ),
		// 			'selective' => __( 'Selective', 'addonskit-for-elementor' ),
		// 		],
		// 		'regular'   => 'date',
		// 	]
		// );

		// $this->add_control(
		// 	'cat',
		// 	[
		// 		'label'     => __( 'Specify Categories', 'addonskit-for-elementor' ),
		// 		'type'      => Controls_Manager::SELECT2,
		// 		'multiple'  => true,
		// 		'options'   => DirectoristTaxonomies::all_listings(),
		// 		'condition' => ['order_type' => 'regular'],
		// 	]
		// );

		$this->add_control(
			'cat',
			[
				'label'     => __( 'Specify Categories', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::listing_categories(),
				// 'condition' => ['order_type' => 'regular'],
			]
		);

		$this->add_control(
			'tag',
			[
				'label'     => __( 'Specify Tags', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::listing_tags(),
				// 'condition' => ['order_type' => 'regular'],
			]
		);

		$this->add_control(
			'location',
			[
				'label'     => __( 'Specify Locations', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SELECT2,
				'multiple'  => true,
				'options'   => DirectoristTaxonomies::listing_locations(),
				// 'condition' => ['order_type' => 'regular'],
			]
		);

		$this->add_control(
			'listing_number',
			[
				'label'     => __( 'Listings Per Page', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 100,
				'step'      => 1,
				'default'   => 6,
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
			]
		);
    }

    protected function add_sorting_pagination_controls(): void {
        $this->add_control(
			'pagination_area',
			[
				'label' => esc_html__( 'Pagination Area', 'addonskit-for-elementor' ),
				'type' => Controls_Manager::HEADING,
				'separator' => 'before',
			]
		);

		$this->add_control(
			'show_pagination',
			[
				'label'     => __( 'Enable Pagination', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::SWITCHER,
				'label_on'  => esc_html__( 'Enable', 'addonskit-for-elementor' ),
				'label_off' => esc_html__( 'Disable', 'addonskit-for-elementor' ),
				'default'   => 'no',
			]
		);

		$this->add_responsive_control(
			'pagination_align',
			[
				'label'     => esc_html__( 'Alignment', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'start'  => [
						'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-center',
					],
					'end'    => [
						'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'default'   => 'center',
				'selectors' => [
					'{{WRAPPER}} .directorist-pagination' => 'justify-content: {{VALUE}};',
				],
				'condition' => ['show_pagination' => 'yes'],
			]
		);
    }

    protected function register_styles(): void {
        // Directory Type Styles
        $this->register_directory_type_styles();

        // Search Form Styles
        $this->register_search_form_styles();

        // Listing Card Styles
        $this->register_listing_card_styles();

        // Pagination Styles
        $this->register_pagination_styles();
    }

    protected function register_directory_type_styles(): void {
		$this->register_container_style_controls( 
			__( 'Type: Container', 'addonskit-for-elementor' ), 
			'all_listing_directory_type_container', 
			'.directorist-type-nav__list', 
			directorist_is_multi_directory_enabled() ? '' : ['nocondition' => true] 
		);

		$this->register_directory_type_style_controls( '.directorist-type-nav__list .directorist-type-nav__link', [], '.directorist-type-nav__list__current .directorist-type-nav__link' );
    }

    protected function register_search_form_styles(): void {
        if ( ! get_directorist_option( 'listing_hide_top_search_bar', false ) ) {

			//Top search
			$this->register_form_container_style_controls( __( 'Search Form: Container', 'addonskit-for-elementor' ), 'top-search-form-container', '.directorist-basic-search .directorist-search-form__box', ['sidebar!' => 'no_sidebar' ] );

			$this->register_all_listings_form_fields_controls(  
				__( 'Search Form: Field Settings', 'addonskit-for-elementor' ), 
				'top-search-form-fields', 
				'.directorist-basic-search .directorist-search-field__label, .directorist-content-active .directorist-basic-search .select2-container--default .select2-selection--single .select2-selection__rendered .select2-selection__placeholder, .directorist-advanced-filter__advanced__element .directorist-search-field > label', 
				['sidebar!' => 'no_sidebar'] );
		}

		// Header
		$this->register_filters_button( ['header' => 'yes', 'filter' => 'yes', 'sidebar' => 'no_sidebar'] );

		$this->register_text_controls( __( 'Listing Found Text', 'addonskit-for-elementor' ), 'listings_found', '.directorist-listings-header__left .directorist-header-found-title', [ 'header' => 'yes' ] );

		$this->register_view_as_sort_by();

		//Sidebar
		$this->register_form_container_style_controls( __( 'Sidebar: Container', 'addonskit-for-elementor' ), 'sidebar-container', '.listing-with-sidebar__sidebar .directorist-search-form__box', ['sidebar!' => 'no_sidebar'] );

		$this->register_all_listings_form_fields_controls(  __( 'Sidebar: Title Settings', 'addonskit-for-elementor' ), 'sidebar-form-title', '.directorist-advanced-filter__title', ['sidebar!' => 'no_sidebar'] );
		
		$this->register_all_listings_form_fields_controls(  __( 'Sidebar: Field Settings', 'addonskit-for-elementor' ), 'sidebar-form-fields', '.listing-with-sidebar__sidebar .directorist-search-field__label', ['sidebar!' => 'no_sidebar'] );
    }

    protected function register_listing_card_styles(): void {
		$this->register_container_style_controls( 
			__( 'Listing: Card Container', 'addonskit-for-elementor' ), 
			'all_listing_card_container', 
			'.directorist-listing-card',
		);

		//Listing Car
		$this->register_listing_card_info();
		$this->register_listing_footer();
    }

    protected function register_pagination_styles(): void {
        //Pagination
		$this->register_container_style_controls( __( 'Pagination: Container', 'addonskit-for-elementor' ), 'all_listing_pagination_container', '.directorist-pagination', ['show_pagination' => 'yes'] );

		$this->register_all_listing_pagination( ['show_pagination' => 'yes'] );
    }

    protected function render(): void {
        $settings = $this->get_settings();
        $attributes = $this->prepare_attributes($settings);
        
        Helper::run_shortcode('directorist_tag', $attributes);
    }

    private function prepare_attributes(array $settings): array {
        $attributes = [
            'header'                => $settings['header'] ?? 'no',
            'header_title'          => $settings['header_title'],
            'view'                  => $settings['view'],
            'map_height'            => $settings['map_height'],
            'columns'               => $settings['columns'],
            'listings_per_page'     => $settings['listing_number'],
            'show_pagination'       => $settings['show_pagination'] ?? 'no',
            'category'              => $this->prepare_taxonomy_terms($settings['cat'] ?? ''),
            'tag'                   => $this->prepare_taxonomy_terms($settings['tag'] ?? ''),
            'location'              => $this->prepare_taxonomy_terms($settings['location'] ?? ''),
            'featured_only'         => $settings['featured'] ?? 'no',
            'popular_only'          => $settings['popular'] ?? 'no',
            'logged_in_user_only'   => $settings['user'] ?? 'no',
            'display_preview_image' => $settings['preview'] ?? 'no',
            'orderby'               => $settings['order_by'],
            'order'                 => $settings['order_list'],
        ];

        if (!empty($settings['sidebar'])) {
            $attributes['sidebar'] = $settings['sidebar'];
			if ( $settings['sidebar'] === 'no_sidebar' ) {
				$attributes['advanced_filter'] = $settings['filter'];
			}
        }

        if (directorist_is_multi_directory_enabled()) {
            $attributes = $this->add_directory_type_attributes($attributes, $settings);
        }

        return apply_filters('directorist_single_tag_elementor_widget_atts', $attributes, $settings);
    }

    private function prepare_taxonomy_terms($terms) {
        return is_array($terms) && !empty($terms) ? implode(',', $terms) : '';
    }

    private function add_directory_type_attributes(array $attributes, array $settings): array {
        $type = $settings['type'] ?? [];
        
        if (is_array($type)) {
            $attributes['directory_type'] = implode(',', $type);
        }
        
        if (!empty($settings['default_type'])) {
            $attributes['default_directory_type'] = $settings['default_type'];
        }

        return $attributes;
    }
}