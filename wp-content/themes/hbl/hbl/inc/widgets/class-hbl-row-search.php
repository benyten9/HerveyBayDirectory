<?php
/**
 * HBL Row Search Widget
 *
 * Displays a horizontal search bar with keyword, category, and location filters
 *
 * @package HBL
 * @since 1.0.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL Row Search Widget Class
 */
class HBL_Row_Search extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-row-search';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Row Search', 'hbl' );
	}

	/**
	 * Get widget icon
	 */
	public function get_icon() {
		return 'eicon-search';
	}

	/**
	 * Get widget categories
	 */
	public function get_categories() {
		return array( 'hbl' );
	}

	/**
	 * Get widget keywords
	 */
	public function get_keywords() {
		return array( 'hbl', 'search', 'row', 'filter', 'category', 'location' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// ========== CONTENT SECTION ==========
		$this->start_controls_section(
			'content_section',
			array(
				'label' => esc_html__( 'Search Settings', 'hbl' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'search_placeholder',
			array(
				'label'       => esc_html__( 'Search Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search...', 'hbl' ),
				'placeholder' => esc_html__( 'Enter placeholder text', 'hbl' ),
			)
		);

		$this->add_control(
			'category_label',
			array(
				'label'   => esc_html__( 'Category Label', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Category', 'hbl' ),
			)
		);

		$this->add_control(
			'location_label',
			array(
				'label'   => esc_html__( 'Location Label', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Location', 'hbl' ),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => esc_html__( 'Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Search', 'hbl' ),
			)
		);

		$this->add_control(
			'search_results_page',
			array(
				'label'       => esc_html__( 'Search Results Page URL', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '/search-result/',
				'placeholder' => '/search-result/',
				'description' => esc_html__( 'URL of the page with search results widget', 'hbl' ),
			)
		);

		$this->add_control(
			'search_in_listings',
			array(
				'label'        => esc_html__( 'Search in Listings', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'search_in_posts',
			array(
				'label'        => esc_html__( 'Search in Posts', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: CONTAINER ==========
		$this->start_controls_section(
			'style_container',
			array(
				'label' => esc_html__( 'Container', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'container_gap',
			array(
				'label'      => esc_html__( 'Gap Between Fields', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 50,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 15,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-row-search-container' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'container_align',
			array(
				'label'     => esc_html__( 'Alignment', 'hbl' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => esc_html__( 'Left', 'hbl' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center'     => array(
						'title' => esc_html__( 'Center', 'hbl' ),
						'icon'  => 'eicon-text-align-center',
					),
					'flex-end'   => array(
						'title' => esc_html__( 'Right', 'hbl' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'flex-start',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-wrapper' => 'justify-content: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: SEARCH INPUT ==========
		$this->start_controls_section(
			'style_search_input',
			array(
				'label' => esc_html__( 'Search Input', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'search_width',
			array(
				'label'      => esc_html__( 'Width', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 100,
						'max' => 600,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 347,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-row-search-field' => 'width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'search_typography',
				'selector' => '{{WRAPPER}} .hbl-row-search-input',
			)
		);

		$this->add_control(
			'search_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-input' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'search_placeholder_color',
			array(
				'label'     => esc_html__( 'Placeholder Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.25)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-input::placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'search_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-field' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: DROPDOWNS ==========
		$this->start_controls_section(
			'style_dropdowns',
			array(
				'label' => esc_html__( 'Dropdowns', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'dropdown_width',
			array(
				'label'      => esc_html__( 'Width', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array(
					'px' => array(
						'min' => 100,
						'max' => 400,
					),
					'%'  => array(
						'min' => 10,
						'max' => 100,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 190,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-row-search-dropdown' => 'width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'dropdown_typography',
				'selector' => '{{WRAPPER}} .hbl-row-search-dropdown-label',
			)
		);

		$this->add_control(
			'dropdown_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.25)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-dropdown-label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropdown_icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-dropdown-icon' => 'stroke: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'dropdown_icon_size',
			array(
				'label'      => esc_html__( 'Icon Size', 'hbl' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 4,
						'max' => 24,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .hbl-row-search-dropdown-icon' => 'width: {{SIZE}}{{UNIT}}; height: calc({{SIZE}}{{UNIT}} / 2);',
				),
			)
		);

		$this->add_control(
			'dropdown_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-dropdown' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BUTTON ==========
		$this->start_controls_section(
			'style_button',
			array(
				'label' => esc_html__( 'Search Button', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => '{{WRAPPER}} .hbl-row-search-button',
			)
		);

		$this->start_controls_tabs( 'button_style_tabs' );

		// Normal State
		$this->start_controls_tab(
			'button_normal',
			array(
				'label' => esc_html__( 'Normal', 'hbl' ),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		// Hover State
		$this->start_controls_tab(
			'button_hover',
			array(
				'label' => esc_html__( 'Hover', 'hbl' ),
			)
		);

		$this->add_control(
			'button_hover_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-button:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-row-search-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Get categories for dropdown
		$categories = array();
		if ( taxonomy_exists( 'at_biz_dir-category' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'at_biz_dir-category',
				'hide_empty' => false,
				'number'     => 0,
			) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$categories = $terms;
			}
		}

		// Get locations for dropdown
		$locations = array();
		if ( taxonomy_exists( 'at_biz_dir-location' ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'at_biz_dir-location',
				'hide_empty' => false,
				'number'     => 0,
			) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$locations = $terms;
			}
		}

		// Build search URL
		$search_url = ! empty( $settings['search_results_page'] ) ? home_url( $settings['search_results_page'] ) : home_url( '/search-result/' );
		
		$current_keyword = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$current_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$current_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		?>

		<div class="hbl-row-search-wrapper">
			<form class="hbl-row-search-container" action="<?php echo esc_url( $search_url ); ?>" method="get">
				
				<!-- Search Input -->
				<div class="hbl-row-search-field">
					<input 
						type="text" 
						name="q" 
						class="hbl-row-search-input" 
						placeholder="<?php echo esc_attr( $settings['search_placeholder'] ); ?>"
						value="<?php echo esc_attr( $current_keyword ); ?>"
					/>
				</div>

				<!-- Category Dropdown -->
				<div class="hbl-row-search-dropdown" data-dropdown="category">
					<span class="hbl-row-search-dropdown-label" data-default="<?php echo esc_attr( $settings['category_label'] ); ?>">
						<?php 
						if ( $current_category ) {
							foreach ( $categories as $category ) {
								if ( $category->slug === $current_category ) {
									echo esc_html( $category->name );
									break;
								}
							}
						} else {
							echo esc_html( $settings['category_label'] );
						}
						?>
					</span>
					<svg class="hbl-row-search-dropdown-icon" viewBox="0 0 10 5" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M1 1L5 4L9 1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					
					<!-- Hidden dropdown menu -->
					<div class="hbl-row-search-dropdown-menu">
						<div class="hbl-row-search-dropdown-option" data-value="">
							<?php esc_html_e( 'All Categories', 'hbl' ); ?>
						</div>
						<?php foreach ( $categories as $category ) : ?>
							<div class="hbl-row-search-dropdown-option <?php echo $current_category === $category->slug ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $category->slug ); ?>">
								<?php echo esc_html( $category->name ); ?>
							</div>
						<?php endforeach; ?>
					</div>
					
					<input type="hidden" name="category" class="hbl-row-search-dropdown-value" value="<?php echo esc_attr( $current_category ); ?>" />
				</div>

				<!-- Location Dropdown -->
				<div class="hbl-row-search-dropdown" data-dropdown="location">
					<span class="hbl-row-search-dropdown-label" data-default="<?php echo esc_attr( $settings['location_label'] ); ?>">
						<?php 
						if ( $current_location ) {
							foreach ( $locations as $location ) {
								if ( $location->slug === $current_location ) {
									echo esc_html( $location->name );
									break;
								}
							}
						} else {
							echo esc_html( $settings['location_label'] );
						}
						?>
					</span>
					<svg class="hbl-row-search-dropdown-icon" viewBox="0 0 10 5" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M1 1L5 4L9 1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					
					<!-- Hidden dropdown menu -->
					<div class="hbl-row-search-dropdown-menu">
						<div class="hbl-row-search-dropdown-option" data-value="">
							<?php esc_html_e( 'All Locations', 'hbl' ); ?>
						</div>
						<?php foreach ( $locations as $location ) : ?>
							<div class="hbl-row-search-dropdown-option <?php echo $current_location === $location->slug ? 'selected' : ''; ?>" data-value="<?php echo esc_attr( $location->slug ); ?>">
								<?php echo esc_html( $location->name ); ?>
							</div>
						<?php endforeach; ?>
					</div>
					
					<input type="hidden" name="location" class="hbl-row-search-dropdown-value" value="<?php echo esc_attr( $current_location ); ?>" />
				</div>

				<!-- Search Button -->
				<button type="submit" class="hbl-row-search-button">
					<?php echo esc_html( $settings['button_text'] ); ?>
				</button>
			</form>
		</div>

		<?php
	}
}

