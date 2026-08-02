<?php
/**
 * HBL Listing Search Widget
 *
 * A beautiful search form for listings
 *
 * @package HBL
 * @since 1.2.697
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Listing_Search extends Widget_Base {

	public function get_name() {
		return 'hbl-listing-search';
	}

	public function get_title() {
		return esc_html__( 'HBL Listing Search', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-search';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'search', 'form', 'listings', 'directory', 'filter', 'hbl' );
	}

	protected function register_controls() {
		// ========== CONTENT: GENERAL ==========
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
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
				'label_block' => true,
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'   => esc_html__( 'Layout', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'horizontal',
				'options' => array(
					'horizontal' => esc_html__( 'Horizontal', 'hbl' ),
					'vertical'   => esc_html__( 'Vertical', 'hbl' ),
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: FIELDS ==========
		$this->start_controls_section(
			'section_fields',
			array(
				'label' => esc_html__( 'Search Fields', 'hbl' ),
			)
		);

		$this->add_control(
			'show_keyword',
			array(
				'label'        => esc_html__( 'Show Keyword Field', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'keyword_placeholder',
			array(
				'label'     => esc_html__( 'Keyword Placeholder', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'What are you looking for?',
				'condition' => array(
					'show_keyword' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_category',
			array(
				'label'        => esc_html__( 'Show Category Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'category_placeholder',
			array(
				'label'     => esc_html__( 'Category Placeholder', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'All Categories',
				'condition' => array(
					'show_category' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_location',
			array(
				'label'        => esc_html__( 'Show Location Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'location_placeholder',
			array(
				'label'     => esc_html__( 'Location Placeholder', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'All Locations',
				'condition' => array(
					'show_location' => 'yes',
				),
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => esc_html__( 'Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Search',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: COLORS ==========
		$this->start_controls_section(
			'section_style_colors',
			array(
				'label' => esc_html__( 'Colors', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Button Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-btn' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_color',
			array(
				'label'     => esc_html__( 'Button Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#006666',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-btn:hover' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Button Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-btn' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_bg_color',
			array(
				'label'     => esc_html__( 'Input Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-input' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-lsearch-select' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_border_color',
			array(
				'label'     => esc_html__( 'Input Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#e2e8f0',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-input' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-lsearch-select' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'form_bg_color',
			array(
				'label'     => esc_html__( 'Form Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#f8fafc',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lsearch-form' => 'background: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$action_url = ! empty( $settings['search_results_page'] ) ? home_url( $settings['search_results_page'] ) : home_url( '/search-result/' );

		// Get categories
		$categories = array();
		if ( taxonomy_exists( 'at_biz_dir-category' ) ) {
			$categories = get_terms( array(
				'taxonomy'   => 'at_biz_dir-category',
				'hide_empty' => false,
				'parent'     => 0,
			) );
		}

		// Get locations
		$locations = array();
		if ( taxonomy_exists( 'at_biz_dir-location' ) ) {
			$locations = get_terms( array(
				'taxonomy'   => 'at_biz_dir-location',
				'hide_empty' => false,
				'parent'     => 0,
			) );
		}

		// Get current values from URL
		$current_keyword = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$current_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$current_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		?>
		<div class="hbl-lsearch-widget">
			<form class="hbl-lsearch-form hbl-lsearch-<?php echo esc_attr( $settings['layout'] ); ?>" action="<?php echo esc_url( $action_url ); ?>" method="get">
				<div class="hbl-lsearch-fields">
					<?php if ( 'yes' === $settings['show_keyword'] ) : ?>
						<div class="hbl-lsearch-field hbl-lsearch-field-keyword">
							<div class="hbl-lsearch-input-wrapper">
								<svg class="hbl-lsearch-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
									<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<input type="text" name="q" class="hbl-lsearch-input" placeholder="<?php echo esc_attr( $settings['keyword_placeholder'] ); ?>" value="<?php echo esc_attr( $current_keyword ); ?>">
							</div>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $settings['show_category'] ) : ?>
						<div class="hbl-lsearch-field hbl-lsearch-field-category">
							<div class="hbl-lsearch-select-wrapper">
								<svg class="hbl-lsearch-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<select name="category" class="hbl-lsearch-select">
									<option value=""><?php echo esc_html( $settings['category_placeholder'] ); ?></option>
									<?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
										<?php foreach ( $categories as $category ) : ?>
											<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $current_category, $category->slug ); ?>>
												<?php echo esc_html( $category->name ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $settings['show_location'] ) : ?>
						<div class="hbl-lsearch-field hbl-lsearch-field-location">
							<div class="hbl-lsearch-select-wrapper">
								<svg class="hbl-lsearch-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
								</svg>
								<select name="location" class="hbl-lsearch-select">
									<option value=""><?php echo esc_html( $settings['location_placeholder'] ); ?></option>
									<?php if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) : ?>
										<?php foreach ( $locations as $location ) : ?>
											<option value="<?php echo esc_attr( $location->slug ); ?>" <?php selected( $current_location, $location->slug ); ?>>
												<?php echo esc_html( $location->name ); ?>
											</option>
										<?php endforeach; ?>
									<?php endif; ?>
								</select>
							</div>
						</div>
					<?php endif; ?>
				</div>

				<button type="submit" class="hbl-lsearch-btn">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
						<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<?php echo esc_html( $settings['button_text'] ); ?>
				</button>
			</form>
		</div>
		<?php
	}
}

