<?php
/**
 * HBL Search Widget
 *
 * Universal search widget for WordPress posts and Directorist listings
 * Matches Figma design specifications for Hervey Bay Directory
 *
 * @package HBL
 * @since 1.2.30
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class HBL_Search extends Widget_Base {

	/**
	 * Get widget name
	 */
	public function get_name() {
		return 'hbl-search';
	}

	/**
	 * Get widget title
	 */
	public function get_title() {
		return esc_html__( 'HBL Search', 'hbl' );
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
	 * Register widget controls
	 */
	protected function register_controls() {
		
		// ========== CONTENT SECTION ==========
		$this->start_controls_section(
			'section_content',
			array(
				'label' => esc_html__( 'Search Settings', 'hbl' ),
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
			'search_button_text',
			array(
				'label'       => esc_html__( 'Search Button Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search', 'hbl' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'helper_text',
			array(
				'label'       => esc_html__( 'Search Placeholder Text', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => esc_html__( 'Search Like a Local', 'hbl' ),
				'label_block' => true,
				'description' => esc_html__( 'This text appears as the placeholder in the search input field', 'hbl' ),
			)
		);

		$this->add_control(
			'show_category_filter',
			array(
				'label'        => esc_html__( 'Show Category Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_location_filter',
			array(
				'label'        => esc_html__( 'Show Location Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'search_in_listings',
			array(
				'label'        => esc_html__( 'Search in Directorist Listings', 'hbl' ),
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
				'label'        => esc_html__( 'Search in WordPress Posts', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'results_per_page',
			array(
				'label'   => esc_html__( 'Results Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->end_controls_section();

		// ========== STYLE: SEARCH INPUT ==========
		$this->start_controls_section(
			'section_input_style',
			array(
				'label' => esc_html__( 'Search Input', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-input',
			)
		);

		$this->add_control(
			'input_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-input' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'input_placeholder_color',
			array(
				'label'     => esc_html__( 'Placeholder Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-input::placeholder' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-search-input::-webkit-input-placeholder' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-search-input::-moz-placeholder' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-search-input:-ms-input-placeholder' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: SEARCH BUTTON ==========
		$this->start_controls_section(
			'section_button_style',
			array(
				'label' => esc_html__( 'Search Button', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-button',
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-button' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-search-button' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-button:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: HELPER TEXT ==========
		$this->start_controls_section(
			'section_helper_style',
			array(
				'label' => esc_html__( 'Helper Text', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'helper_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-helper',
			)
		);

		$this->add_control(
			'helper_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-helper' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: FILTERS ==========
		$this->start_controls_section(
			'section_filters_style',
			array(
				'label' => esc_html__( 'Filters (Category/Location)', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'filter_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-search-filter',
			)
		);

		$this->add_control(
			'filter_text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-search-filter' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: RESULTS ==========
		$this->start_controls_section(
			'section_results_style',
			array(
				'label' => esc_html__( 'Search Results', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'results_title_heading',
			array(
				'label'     => esc_html__( 'Results Title', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'results_title_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-results-title',
			)
		);

		$this->add_control(
			'results_title_color',
			array(
				'label'     => esc_html__( 'Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-results-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'result_item_heading',
			array(
				'label'     => esc_html__( 'Result Items', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'result_title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-result-title',
			)
		);

		$this->add_control(
			'result_title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-title a' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'result_title_hover_color',
			array(
				'label'     => esc_html__( 'Title Hover Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-title a:hover' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'result_excerpt_typography',
				'label'    => esc_html__( 'Excerpt Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-result-excerpt',
			)
		);

		$this->add_control(
			'result_excerpt_color',
			array(
				'label'     => esc_html__( 'Excerpt Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-excerpt' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'result_meta_typography',
				'label'    => esc_html__( 'Meta Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-result-date, {{WRAPPER}} .hbl-result-address',
			)
		);

		$this->add_control(
			'result_meta_color',
			array(
				'label'     => esc_html__( 'Meta Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-date, {{WRAPPER}} .hbl-result-address' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'result_badge_heading',
			array(
				'label'     => esc_html__( 'Type Badges', 'hbl' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'badge_typography',
				'label'    => esc_html__( 'Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-result-type-badge',
			)
		);

		$this->add_control(
			'badge_post_bg',
			array(
				'label'     => esc_html__( 'Blog Post Badge Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-type-post .hbl-result-type-badge' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'badge_listing_bg',
			array(
				'label'     => esc_html__( 'Listing Badge Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-type-listing .hbl-result-type-badge' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'badge_text_color',
			array(
				'label'     => esc_html__( 'Badge Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .hbl-result-type-badge' => 'color: {{VALUE}};',
				),
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
		?>
		<div class="hbl-search-widget">
			<form class="hbl-search-form" id="hbl-search-form" method="get" action="<?php echo esc_url( $search_results_url ); ?>">
				
				<!-- First Row: Search Button + Input Field -->
				<div class="hbl-search-form-row-1">
					<!-- Search Button (Left Side) -->
					<div class="hbl-search-button-wrapper">
						<button type="submit" class="hbl-search-button">
							<?php echo esc_html( $settings['search_button_text'] ); ?>
						</button>
					</div>

					<!-- Search Input Field (Right Side - expands to fill) -->
					<!-- Helper text is the placeholder for the input field -->
					<div class="hbl-search-input-container">
						<input 
							type="text" 
							name="q" 
							class="hbl-search-input" 
							placeholder="<?php echo esc_attr( $settings['helper_text'] ); ?>"
							value="<?php echo esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ); ?>"
							autocomplete="off"
						>
					</div>
				</div>

				<!-- Second Row: Category and Location Filters (if enabled) -->
				<?php if ( 'yes' === $settings['show_category_filter'] || 'yes' === $settings['show_location_filter'] ) : ?>
					<div class="hbl-search-form-row-2">
						
						<!-- Category Filter -->
						<?php if ( 'yes' === $settings['show_category_filter'] ) : ?>
							<?php
							$current_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
							?>
							<div class="hbl-search-filter-wrapper">
								<select class="hbl-search-filter hbl-category-filter" name="category">
									<option value=""><?php esc_html_e( 'Category', 'hbl' ); ?></option>
									<?php
									// Get Directorist categories
									if ( taxonomy_exists( 'at_biz_dir-category' ) ) {
										$listing_categories = get_terms( array(
											'taxonomy'   => 'at_biz_dir-category',
											'hide_empty' => false,
											'parent'     => 0,
										) );
										if ( ! empty( $listing_categories ) && ! is_wp_error( $listing_categories ) ) {
											foreach ( $listing_categories as $cat ) {
												$selected = ( $current_category === $cat->slug ) ? ' selected' : '';
												echo '<option value="' . esc_attr( $cat->slug ) . '"' . $selected . '>' . esc_html( $cat->name ) . '</option>';
											}
										}
									}
									?>
								</select>
								<i class="bi bi-chevron-down hbl-filter-icon"></i>
							</div>
						<?php endif; ?>

						<!-- Location Filter -->
						<?php if ( 'yes' === $settings['show_location_filter'] && taxonomy_exists( 'at_biz_dir-location' ) ) : ?>
							<?php
							$current_location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
							?>
							<div class="hbl-search-filter-wrapper">
								<select class="hbl-search-filter hbl-location-filter" name="location">
									<option value=""><?php esc_html_e( 'Location', 'hbl' ); ?></option>
									<?php
									$locations = get_terms( array(
										'taxonomy'   => 'at_biz_dir-location',
										'hide_empty' => false,
										'parent'     => 0,
									) );
									if ( ! empty( $locations ) && ! is_wp_error( $locations ) ) {
										foreach ( $locations as $location ) {
											$selected = ( $current_location === $location->slug ) ? ' selected' : '';
											echo '<option value="' . esc_attr( $location->slug ) . '"' . $selected . '>' . esc_html( $location->name ) . '</option>';
										}
									}
									?>
								</select>
								<i class="bi bi-chevron-down hbl-filter-icon"></i>
							</div>
						<?php endif; ?>

					</div>
				<?php endif; ?>

				</form>
		</div>
		<?php
	}

	/**
	 * Display search results
	 */
	private function display_search_results( $settings ) {
		$search_query = get_search_query();
		$category = isset( $_GET['search_category'] ) ? sanitize_text_field( $_GET['search_category'] ) : '';
		$location = isset( $_GET['search_location'] ) ? sanitize_text_field( $_GET['search_location'] ) : '';
		
		$results = array();

		// Search in WordPress posts
		if ( 'yes' === $settings['search_in_posts'] && isset( $_GET['search_posts'] ) ) {
			$post_args = array(
				'post_type'      => 'post',
				'posts_per_page' => $settings['results_per_page'],
				's'              => $search_query,
				'post_status'    => 'publish',
			);

			// Add category filter for posts
			if ( ! empty( $category ) && strpos( $category, 'post_cat_' ) === 0 ) {
				$cat_id = str_replace( 'post_cat_', '', $category );
				$post_args['cat'] = intval( $cat_id );
			}

			$post_query = new \WP_Query( $post_args );
			
			if ( $post_query->have_posts() ) {
				while ( $post_query->have_posts() ) {
					$post_query->the_post();
					$results[] = array(
						'type'    => 'post',
						'id'      => get_the_ID(),
						'title'   => get_the_title(),
						'excerpt' => get_the_excerpt(),
						'url'     => get_permalink(),
						'date'    => get_the_date(),
					);
				}
				wp_reset_postdata();
			}
		}

		// Search in Directorist listings
		if ( 'yes' === $settings['search_in_listings'] && isset( $_GET['search_listings'] ) ) {
			$listing_args = array(
				'post_type'      => 'at_biz_dir',
				'posts_per_page' => $settings['results_per_page'],
				's'              => $search_query,
				'post_status'    => 'publish',
			);

			// Add category filter for listings
			if ( ! empty( $category ) && strpos( $category, 'listing_cat_' ) === 0 ) {
				$cat_id = str_replace( 'listing_cat_', '', $category );
				$listing_args['tax_query'] = array(
					array(
						'taxonomy' => 'at_biz_dir-category',
						'field'    => 'term_id',
						'terms'    => intval( $cat_id ),
					),
				);
			}

			// Add location filter
			if ( ! empty( $location ) ) {
				if ( ! isset( $listing_args['tax_query'] ) ) {
					$listing_args['tax_query'] = array();
				}
				$listing_args['tax_query'][] = array(
					'taxonomy' => 'at_biz_dir-location',
					'field'    => 'term_id',
					'terms'    => intval( $location ),
				);
			}

			$listing_query = new \WP_Query( $listing_args );
			
			if ( $listing_query->have_posts() ) {
				while ( $listing_query->have_posts() ) {
					$listing_query->the_post();
					$results[] = array(
						'type'    => 'listing',
						'id'      => get_the_ID(),
						'title'   => get_the_title(),
						'excerpt' => get_the_excerpt(),
						'url'     => get_permalink(),
						'address' => get_post_meta( get_the_ID(), '_address', true ),
					);
				}
				wp_reset_postdata();
			}
		}

		// Display results
		if ( ! empty( $results ) ) {
			echo '<div class="hbl-search-results-list">';
			echo '<h3 class="hbl-results-title">' . sprintf( esc_html__( 'Search Results for "%s"', 'hbl' ), esc_html( $search_query ) ) . '</h3>';
			
			foreach ( $results as $result ) {
				$this->render_result_item( $result );
			}
			
			echo '</div>';
		} else {
			echo '<div class="hbl-no-results">';
			echo '<p>' . sprintf( esc_html__( 'No results found for "%s"', 'hbl' ), esc_html( $search_query ) ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Render individual search result item
	 */
	private function render_result_item( $result ) {
		?>
		<div class="hbl-result-item hbl-result-type-<?php echo esc_attr( $result['type'] ); ?>">
			<div class="hbl-result-content">
				<h4 class="hbl-result-title">
					<a href="<?php echo esc_url( $result['url'] ); ?>">
						<?php echo esc_html( $result['title'] ); ?>
					</a>
				</h4>
				
				<?php if ( $result['type'] === 'post' && ! empty( $result['date'] ) ) : ?>
					<div class="hbl-result-meta">
						<span class="hbl-result-type-badge"><?php esc_html_e( 'Blog Post', 'hbl' ); ?></span>
						<span class="hbl-result-date"><?php echo esc_html( $result['date'] ); ?></span>
					</div>
				<?php elseif ( $result['type'] === 'listing' ) : ?>
					<div class="hbl-result-meta">
						<span class="hbl-result-type-badge"><?php esc_html_e( 'Business Listing', 'hbl' ); ?></span>
						<?php if ( ! empty( $result['address'] ) ) : ?>
							<span class="hbl-result-address">
								<i class="bi bi-geo-alt"></i>
								<?php echo esc_html( $result['address'] ); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $result['excerpt'] ) ) : ?>
					<div class="hbl-result-excerpt">
						<?php echo esc_html( $result['excerpt'] ); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

}

