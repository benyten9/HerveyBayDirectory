<?php
/**
 * HBL Search Column Widget
 *
 * A vertical search form widget for searching directory listings
 * Matches Figma design specifications for Hervey Bay Directory
 *
 * @package HBL
 * @since 1.2.36
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * HBL Search Column Widget Class
 */
class HBL_Search_Column extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-search-column';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Search Column', 'hbl' );
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
		return array( 'search', 'directory', 'column', 'hbl', 'filter' );
	}

	/**
	 * Register widget controls
	 */
	protected function register_controls() {

		// ========== CONTENT SECTION ==========
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Search Form', 'hbl' ),
			)
		);

		$this->add_control(
			'category_label',
			array(
				'label'       => esc_html__( 'Category Label', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'What do you need...', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'category_placeholder',
			array(
				'label'       => esc_html__( 'Category Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Select an Option', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'location_label',
			array(
				'label'       => esc_html__( 'Location Label', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Where do you need it...', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'location_placeholder',
			array(
				'label'       => esc_html__( 'Location Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Hervey Bay 4655', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'search_button_text',
			array(
				'label'       => esc_html__( 'Search Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search Directory', 'hbl' ),
				'label_block' => true,
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
			'show_keyword',
			array(
				'label'        => esc_html__( 'Show Keyword Search', 'hbl' ),
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
				'label'       => esc_html__( 'Keyword Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search...', 'hbl' ),
				'label_block' => true,
				'condition'   => array(
					'show_keyword' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: LABELS ==========
		$this->start_controls_section(
			'section_style_labels',
			array(
				'label' => esc_html__( 'Labels', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => esc_html__( 'Label Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-label' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-column-label',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: SELECT FIELDS ==========
		$this->start_controls_section(
			'section_style_selects',
			array(
				'label' => esc_html__( 'Select Fields', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'select_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EEEEEE',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-select' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'select_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => 'rgba(0, 0, 0, 0.25)',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-select' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'select_icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-icon' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'select_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-column-select',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BUTTON ==========
		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => esc_html__( 'Button', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-button' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-column-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-column-button',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$search_results_url = ! empty( $settings['search_results_page'] ) ? home_url( $settings['search_results_page'] ) : home_url( '/search-result/' );
		
		$current_keyword = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$current_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$current_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		?>
		<div class="hbl-search-column-widget">
			<form class="hbl-search-column-form" id="hbl-search-column-form" action="<?php echo esc_url( $search_results_url ); ?>" method="get">
				
				<!-- Keyword Field -->
				<?php if ( 'yes' === $settings['show_keyword'] ) : ?>
					<div class="hbl-search-column-field">
						<label class="hbl-search-column-label"><?php esc_html_e( 'Search', 'hbl' ); ?></label>
						<div class="hbl-search-column-input-wrapper">
							<input type="text" name="q" class="hbl-search-column-input" placeholder="<?php echo esc_attr( $settings['keyword_placeholder'] ); ?>" value="<?php echo esc_attr( $current_keyword ); ?>">
						</div>
					</div>
				<?php endif; ?>

				<!-- Category Field -->
				<div class="hbl-search-column-field">
					<?php if ( ! empty( $settings['category_label'] ) ) : ?>
						<label class="hbl-search-column-label"><?php echo esc_html( $settings['category_label'] ); ?></label>
					<?php endif; ?>
					<div class="hbl-search-column-select-wrapper">
						<select class="hbl-search-column-select" name="category">
							<option value=""><?php echo esc_html( $settings['category_placeholder'] ); ?></option>
							<?php foreach ( $this->get_listing_categories() as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_category, $slug ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Location Field -->
				<div class="hbl-search-column-field">
					<?php if ( ! empty( $settings['location_label'] ) ) : ?>
						<label class="hbl-search-column-label"><?php echo esc_html( $settings['location_label'] ); ?></label>
					<?php endif; ?>
					<div class="hbl-search-column-select-wrapper hbl-has-icon">
						<i class="bi bi-geo-alt hbl-search-column-icon"></i>
						<select class="hbl-search-column-select hbl-with-icon" name="location">
							<option value=""><?php echo esc_html( $settings['location_placeholder'] ); ?></option>
							<?php foreach ( $this->get_listing_locations() as $slug => $name ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_location, $slug ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<!-- Search Button -->
				<button type="submit" class="hbl-search-column-button">
					<?php echo esc_html( $settings['search_button_text'] ); ?>
				</button>

			</form>
		</div>
		<?php
	}

	/**
	 * Get listing categories
	 */
	private function get_listing_categories() {
		$categories = array();
		
		// Get Directorist categories
		$terms = get_terms(
			array(
				'taxonomy'   => 'at_biz_dir-category',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[ $term->slug ] = $term->name;
			}
		}

		return $categories;
	}

	/**
	 * Get listing locations
	 */
	private function get_listing_locations() {
		$locations = array();
		
		// Get Directorist locations
		$terms = get_terms(
			array(
				'taxonomy'   => 'at_biz_dir-location',
				'hide_empty' => false,
				'parent'     => 0,
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$locations[ $term->slug ] = $term->name;
			}
		}

		return $locations;
	}
}

