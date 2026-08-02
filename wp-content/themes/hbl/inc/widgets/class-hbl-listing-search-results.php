<?php
/**
 * HBL Listing Search Results Widget
 *
 * Display search results for listings
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

class HBL_Listing_Search_Results extends Widget_Base {

	public function get_name() {
		return 'hbl-listing-search-results';
	}

	public function get_title() {
		return esc_html__( 'HBL Listing Search Results', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-search-results';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'search', 'results', 'listings', 'directory', 'filter', 'hbl' );
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
			'show_results_count',
			array(
				'label'        => esc_html__( 'Show Results Count', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_active_filters',
			array(
				'label'        => esc_html__( 'Show Active Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_search',
			array(
				'label'        => esc_html__( 'Show Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'search_placeholder',
			array(
				'label'       => esc_html__( 'Search Placeholder', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => 'Search listings...',
				'label_block' => true,
				'condition'   => array(
					'show_search' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: LISTINGS ==========
		$this->start_controls_section(
			'section_listings',
			array(
				'label' => esc_html__( 'Listing Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'posts_per_page',
			array(
				'label'   => esc_html__( 'Listings Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 12,
				'min'     => 1,
				'max'     => 50,
			)
		);

		$this->add_control(
			'columns',
			array(
				'label'   => esc_html__( 'Columns', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => '3',
				'options' => array(
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
			)
		);

		$this->add_control(
			'default_orderby',
			array(
				'label'   => esc_html__( 'Default Order By', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'relevance',
				'options' => array(
					'relevance' => esc_html__( 'Relevance', 'hbl' ),
					'date'      => esc_html__( 'Date', 'hbl' ),
					'title'     => esc_html__( 'Title', 'hbl' ),
					'rand'      => esc_html__( 'Random', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'show_image',
			array(
				'label'        => esc_html__( 'Show Image', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_category',
			array(
				'label'        => esc_html__( 'Show Category', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_address',
			array(
				'label'        => esc_html__( 'Show Address', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_excerpt',
			array(
				'label'        => esc_html__( 'Show Excerpt', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_pagination',
			array(
				'label'        => esc_html__( 'Show Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: NO RESULTS ==========
		$this->start_controls_section(
			'section_no_results',
			array(
				'label' => esc_html__( 'No Results', 'hbl' ),
			)
		);

		$this->add_control(
			'no_results_title',
			array(
				'label'   => esc_html__( 'No Results Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'No listings found',
			)
		);

		$this->add_control(
			'no_results_message',
			array(
				'label'   => esc_html__( 'No Results Message', 'hbl' ),
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'Try adjusting your search criteria or browse our categories.',
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
			'primary_color',
			array(
				'label'     => esc_html__( 'Primary Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-lresults-link' => 'background: {{VALUE}};',
					'{{WRAPPER}} .hbl-lresults-link:hover' => 'background: {{VALUE}}cc;',
					'{{WRAPPER}} .hbl-lresults-title a:hover' => 'color: {{VALUE}};',
					'{{WRAPPER}} .hbl-lresults-pagination a:hover' => 'background: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-lresults-pagination .current' => 'background: {{VALUE}}; border-color: {{VALUE}};',
					'{{WRAPPER}} .hbl-lresults-filter-tag' => 'background: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Get search parameters
		$keyword = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$category_slug = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$location_slug = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		$paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;

		// Build query args
		$args = array(
			'post_type'      => ATBDP_POST_TYPE,
			'posts_per_page' => absint( $settings['posts_per_page'] ),
			'paged'          => $paged,
			'post_status'    => 'publish',
		);

		// Search by keyword
		if ( ! empty( $keyword ) ) {
			$args['s'] = $keyword;
			if ( 'relevance' === $settings['default_orderby'] ) {
				$args['orderby'] = 'relevance';
			}
		} else {
			$args['orderby'] = 'date' === $settings['default_orderby'] || 'relevance' === $settings['default_orderby'] ? 'date' : $settings['default_orderby'];
			$args['order'] = 'DESC';
		}

		// Filter by category
		$category_term = null;
		if ( ! empty( $category_slug ) && taxonomy_exists( 'at_biz_dir-category' ) ) {
			$category_term = get_term_by( 'slug', $category_slug, 'at_biz_dir-category' );
			if ( $category_term && ! is_wp_error( $category_term ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'at_biz_dir-category',
					'field'    => 'term_id',
					'terms'    => $category_term->term_id,
				);
			}
		}

		// Filter by location
		$location_term = null;
		if ( ! empty( $location_slug ) && taxonomy_exists( 'at_biz_dir-location' ) ) {
			$location_term = get_term_by( 'slug', $location_slug, 'at_biz_dir-location' );
			if ( $location_term && ! is_wp_error( $location_term ) ) {
				$args['tax_query'][] = array(
					'taxonomy' => 'at_biz_dir-location',
					'field'    => 'term_id',
					'terms'    => $location_term->term_id,
				);
			}
		}

		// Set tax_query relation if multiple taxonomies
		if ( isset( $args['tax_query'] ) && count( $args['tax_query'] ) > 1 ) {
			$args['tax_query']['relation'] = 'AND';
		}

		$listings = new \WP_Query( $args );

		// Check if search has been performed
		$has_search = ! empty( $keyword ) || ! empty( $category_slug ) || ! empty( $location_slug );
		$widget_id = $this->get_id();
		?>
		<div class="hbl-lresults-widget" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
			<?php if ( 'yes' === $settings['show_results_count'] || 'yes' === $settings['show_active_filters'] ) : ?>
				<div class="hbl-lresults-header">
					<?php if ( 'yes' === $settings['show_results_count'] ) : ?>
						<div class="hbl-lresults-count">
							<?php if ( $has_search ) : ?>
								<span class="hbl-lresults-count-number"><?php echo esc_html( $listings->found_posts ); ?></span>
								<?php echo esc_html( _n( 'listing found', 'listings found', $listings->found_posts, 'hbl' ) ); ?>
							<?php else : ?>
								<span class="hbl-lresults-count-number"><?php echo esc_html( $listings->found_posts ); ?></span>
								<?php esc_html_e( 'total listings', 'hbl' ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( 'yes' === $settings['show_active_filters'] && $has_search ) : ?>
						<div class="hbl-lresults-filters">
							<?php if ( ! empty( $keyword ) ) : ?>
								<span class="hbl-lresults-filter-tag">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
										<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									"<?php echo esc_html( $keyword ); ?>"
									<a href="<?php echo esc_url( remove_query_arg( 'q' ) ); ?>" class="hbl-lresults-filter-remove">&times;</a>
								</span>
							<?php endif; ?>

							<?php if ( $category_term ) : ?>
								<span class="hbl-lresults-filter-tag">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2"/>
									</svg>
									<?php echo esc_html( $category_term->name ); ?>
									<a href="<?php echo esc_url( remove_query_arg( 'category' ) ); ?>" class="hbl-lresults-filter-remove">&times;</a>
								</span>
							<?php endif; ?>

							<?php if ( $location_term ) : ?>
								<span class="hbl-lresults-filter-tag">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2"/>
										<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
									</svg>
									<?php echo esc_html( $location_term->name ); ?>
									<a href="<?php echo esc_url( remove_query_arg( 'location' ) ); ?>" class="hbl-lresults-filter-remove">&times;</a>
								</span>
							<?php endif; ?>

							<a href="<?php echo esc_url( strtok( $_SERVER['REQUEST_URI'], '?' ) ); ?>" class="hbl-lresults-clear-all">
								<?php esc_html_e( 'Clear all', 'hbl' ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( 'yes' === $settings['show_search'] ) : ?>
				<div class="hbl-lresults-search">
					<div class="hbl-lresults-search-wrapper">
						<svg class="hbl-lresults-search-icon" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
							<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<input type="text" class="hbl-lresults-search-input" placeholder="<?php echo esc_attr( $settings['search_placeholder'] ); ?>" data-target="<?php echo esc_attr( $widget_id ); ?>">
						<button type="button" class="hbl-lresults-search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'hbl' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							</svg>
						</button>
					</div>
					<p class="hbl-lresults-no-search-results" style="display: none;"><?php esc_html_e( 'No listings found matching your search.', 'hbl' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $listings->have_posts() ) : ?>
				<div class="hbl-lresults-grid hbl-lresults-cols-<?php echo esc_attr( $settings['columns'] ); ?>">
					<?php
					while ( $listings->have_posts() ) :
						$listings->the_post();
						$listing_id = get_the_ID();
						
						// Get featured image, fallback to Directorist preview image
						$image = get_the_post_thumbnail_url( $listing_id, 'medium_large' );
						if ( ! $image ) {
							// Try Directorist preview image
							$preview_img_id = get_post_meta( $listing_id, '_listing_prv_img', true );
							if ( $preview_img_id ) {
								$image = wp_get_attachment_image_url( $preview_img_id, 'medium_large' );
							}
						}
						if ( ! $image ) {
							// Try first gallery image
							$gallery_ids = get_post_meta( $listing_id, '_listing_img', true );
							if ( ! empty( $gallery_ids ) ) {
								$first_id = is_array( $gallery_ids ) ? reset( $gallery_ids ) : explode( ',', $gallery_ids )[0];
								if ( $first_id ) {
									$image = wp_get_attachment_image_url( absint( $first_id ), 'medium_large' );
								}
							}
						}
						
						$categories = get_the_terms( $listing_id, ATBDP_CATEGORY );
						$address = get_post_meta( $listing_id, '_address', true );
						$excerpt = has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 15 );
						$listing_title = get_the_title();
						$category_name = ( $categories && ! is_wp_error( $categories ) ) ? $categories[0]->name : '';
					?>
						<article class="hbl-lresults-card" data-listing-title="<?php echo esc_attr( strtolower( $listing_title ) ); ?>" data-listing-category="<?php echo esc_attr( strtolower( $category_name ) ); ?>" data-listing-address="<?php echo esc_attr( strtolower( $address ) ); ?>">
							<?php if ( 'yes' === $settings['show_image'] ) : ?>
								<a href="<?php the_permalink(); ?>" class="hbl-lresults-card-image-wrapper">
									<?php if ( $image ) : ?>
										<img src="<?php echo esc_url( $image ); ?>" alt="<?php the_title_attribute(); ?>" class="hbl-lresults-image">
									<?php else : ?>
										<div class="hbl-lresults-no-image">
											<svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
												<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
												<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2"/>
											</svg>
										</div>
									<?php endif; ?>
								</a>
							<?php endif; ?>

							<div class="hbl-lresults-content">
								<?php if ( 'yes' === $settings['show_category'] && $categories && ! is_wp_error( $categories ) ) : ?>
									<span class="hbl-lresults-category"><?php echo esc_html( $categories[0]->name ); ?></span>
								<?php endif; ?>

								<div class="hbl-listing-title-row">
									<h3 class="hbl-lresults-card-title">
										<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
									</h3>
									<?php 
									$is_claimed = get_post_meta( $listing_id, '_claimed_by_admin', true );
									if ( $is_claimed ) : ?>
										<span class="hbl-claimed-badge">
											<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<?php esc_html_e( 'Claimed', 'hbl' ); ?>
										</span>
									<?php endif; ?>
								</div>

								<?php if ( 'yes' === $settings['show_address'] && $address ) : ?>
									<p class="hbl-lresults-address">
										<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2"/>
											<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
										</svg>
										<?php echo esc_html( $address ); ?>
									</p>
								<?php endif; ?>

								<?php if ( 'yes' === $settings['show_excerpt'] && $excerpt ) : ?>
									<p class="hbl-lresults-excerpt"><?php echo esc_html( $excerpt ); ?></p>
								<?php endif; ?>

								<a href="<?php the_permalink(); ?>" class="hbl-lresults-link">
									<?php esc_html_e( 'View Details', 'hbl' ); ?>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</a>
							</div>
						</article>
					<?php endwhile; ?>
				</div>

				<?php if ( 'yes' === $settings['show_pagination'] && $listings->max_num_pages > 1 ) : ?>
					<div class="hbl-lresults-pagination">
						<?php
						echo paginate_links( array(
							'total'     => $listings->max_num_pages,
							'current'   => $paged,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
						) );
						?>
					</div>
				<?php endif; ?>

				<?php wp_reset_postdata(); ?>
			<?php else : ?>
				<div class="hbl-lresults-no-results">
					<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
						<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						<path d="M8 8L14 14M14 8L8 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<h3 class="hbl-lresults-no-results-title"><?php echo esc_html( $settings['no_results_title'] ); ?></h3>
					<p class="hbl-lresults-no-results-message"><?php echo esc_html( $settings['no_results_message'] ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( 'yes' === $settings['show_search'] ) : ?>
		<script>
		(function() {
			const widget = document.querySelector('.hbl-lresults-widget[data-widget-id="<?php echo esc_js( $widget_id ); ?>"]');
			if (!widget) return;

			const searchInput = widget.querySelector('.hbl-lresults-search-input');
			const clearBtn = widget.querySelector('.hbl-lresults-search-clear');
			const noResults = widget.querySelector('.hbl-lresults-no-search-results');
			const cards = widget.querySelectorAll('.hbl-lresults-card');
			const grid = widget.querySelector('.hbl-lresults-grid');

			if (!searchInput || !cards.length) return;

			function filterListings() {
				const searchTerm = searchInput.value.toLowerCase().trim();
				let visibleCount = 0;

				cards.forEach(function(card) {
					const title = card.getAttribute('data-listing-title') || '';
					const category = card.getAttribute('data-listing-category') || '';
					const address = card.getAttribute('data-listing-address') || '';
					const isMatch = !searchTerm || title.includes(searchTerm) || category.includes(searchTerm) || address.includes(searchTerm);
					
					card.style.display = isMatch ? '' : 'none';
					if (isMatch) visibleCount++;
				});

				// Toggle clear button visibility
				if (clearBtn) {
					clearBtn.style.display = searchTerm ? 'flex' : 'none';
				}

				// Show/hide no results message
				if (noResults) {
					noResults.style.display = visibleCount === 0 ? 'block' : 'none';
				}

				// Hide/show grid based on results
				if (grid) {
					grid.style.display = visibleCount === 0 ? 'none' : '';
				}
			}

			searchInput.addEventListener('input', filterListings);

			if (clearBtn) {
				clearBtn.addEventListener('click', function() {
					searchInput.value = '';
					filterListings();
					searchInput.focus();
				});
			}
		})();
		</script>
		<?php endif; ?>
		<?php
	}
}

