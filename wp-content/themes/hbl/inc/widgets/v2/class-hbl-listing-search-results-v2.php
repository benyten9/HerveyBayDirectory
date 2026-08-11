<?php

namespace HBL\Widgets\V2;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use HBL\Widgets\V2\Traits\Query_Handler;
use HBL\Widgets\V2\Traits\Card_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Listing_Search_Results_V2 extends Widget_Base {

	use Query_Handler;
	use Card_Renderer;

	public function get_name() {
		return 'hbl-listing-search-results-v2';
	}

	public function get_title() {
		return esc_html__( 'HBL Listing Search Results V2', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-search-results';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'search', 'results', 'listings', 'directory', 'v2' );
	}

	protected function register_controls() {

		$categories = $this->get_listing_categories();
		$tags       = $this->get_listing_tags();

		$this->start_controls_section(
			'section_query',
			array( 'label' => esc_html__( 'Query Settings', 'hbl' ) )
		);

		$this->add_control(
			'listings_per_page',
			array(
				'label'   => esc_html__( 'Listings Per Page', 'hbl' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 20,
				'min'     => 1,
				'max'     => 100,
			)
		);

		$this->add_control(
			'enable_pagination',
			array(
				'label'        => esc_html__( 'Enable Pagination', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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

		$pricing_plans = $this->get_pricing_plans();

		$this->start_controls_section(
			'section_plan_tiers',
			array( 'label' => esc_html__( 'Plan Tier Mapping', 'hbl' ) )
		);

		if ( ! empty( $pricing_plans ) ) {
			$this->add_control(
				'gold_plan_ids',
				array(
					'label'       => esc_html__( 'Gold Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);
			$this->add_control(
				'silver_plan_ids',
				array(
					'label'       => esc_html__( 'Silver Tier Plans', 'hbl' ),
					'type'        => Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $pricing_plans,
					'label_block' => true,
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_plan_badges',
			array( 'label' => esc_html__( 'Plan Badges', 'hbl' ) )
		);

		$this->add_control(
			'show_plan_badges',
			array(
				'label'        => esc_html__( 'Show Plan Badges', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		if ( ! empty( $pricing_plans ) ) {
			$repeater = new \Elementor\Repeater();
			$repeater->add_control( 'plan_tier', array(
				'label'   => esc_html__( 'Plan Tier', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'gold',
				'options' => array(
					'gold'   => esc_html__( 'Gold', 'hbl' ),
					'silver' => esc_html__( 'Silver', 'hbl' ),
					'bronze' => esc_html__( 'Bronze', 'hbl' ),
				),
			) );
			$repeater->add_control( 'badge_text', array(
				'label'   => esc_html__( 'Badge Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'PREMIUM',
			) );
			$repeater->add_control( 'badge_bg_color', array(
				'label'   => esc_html__( 'Background Color', 'hbl' ),
				'type'    => Controls_Manager::COLOR,
				'default' => '#F9532A',
			) );
			$repeater->add_control( 'badge_text_color', array(
				'label'   => esc_html__( 'Text Color', 'hbl' ),
				'type'    => Controls_Manager::COLOR,
				'default' => '#FFFFFF',
			) );

			$this->add_control(
				'plan_badges',
				array(
					'label'       => esc_html__( 'Plan Badges', 'hbl' ),
					'type'        => Controls_Manager::REPEATER,
					'fields'      => $repeater->get_controls(),
					'default'     => array(
						array( 'plan_tier' => 'gold',   'badge_text' => 'PREMIUM',  'badge_bg_color' => '#F9532A', 'badge_text_color' => '#FFFFFF' ),
						array( 'plan_tier' => 'silver', 'badge_text' => 'FEATURED', 'badge_bg_color' => '#008080', 'badge_text_color' => '#FFFFFF' ),
						array( 'plan_tier' => 'bronze', 'badge_text' => 'BASIC',    'badge_bg_color' => '#6c757d', 'badge_text_color' => '#FFFFFF' ),
					),
					'title_field' => '{{{ plan_tier.toUpperCase() }}} - {{{ badge_text }}}',
					'condition'   => array( 'show_plan_badges' => 'yes' ),
				)
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'section_display',
			array( 'label' => esc_html__( 'Display Options', 'hbl' ) )
		);

		$this->add_control(
			'show_alphabetical_filter',
			array(
				'label'        => esc_html__( 'Show A-Z Alphabetical Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_keyword_search',
			array(
				'label'        => esc_html__( 'Show Search Business', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_popular_search',
			array(
				'label'        => esc_html__( 'Show Featured Tags', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$popular_searches_repeater = new \Elementor\Repeater();
		$popular_searches_repeater->add_control( 'search_text', array(
			'label'       => esc_html__( 'Tag Text', 'hbl' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => esc_html__( 'Tag Item', 'hbl' ),
			'placeholder' => esc_html__( 'Enter tag text', 'hbl' ),
		) );
		if ( ! empty( $tags ) ) {
			$popular_searches_repeater->add_control( 'search_tag', array(
				'label'       => esc_html__( 'Tag', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $tags,
				'label_block' => true,
			) );
		}
		$this->add_control(
			'popular_searches',
			array(
				'label'       => esc_html__( 'Featured Tags', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $popular_searches_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ search_text }}}',
				'condition'   => array( 'show_popular_search' => 'yes' ),
			)
		);

		$this->add_control(
			'show_popular_categories',
			array(
				'label'        => esc_html__( 'Show Search Categories', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$popular_categories_repeater = new \Elementor\Repeater();
		$popular_categories_repeater->add_control( 'category_text', array(
			'label'       => esc_html__( 'Category Text', 'hbl' ),
			'type'        => Controls_Manager::TEXT,
			'default'     => esc_html__( 'Category Item', 'hbl' ),
			'placeholder' => esc_html__( 'Enter category text', 'hbl' ),
		) );
		if ( ! empty( $categories ) ) {
			$popular_categories_repeater->add_control( 'category_id', array(
				'label'       => esc_html__( 'Category', 'hbl' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $categories,
				'label_block' => true,
			) );
		}
		$this->add_control(
			'popular_categories',
			array(
				'label'       => esc_html__( 'Search Categories', 'hbl' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $popular_categories_repeater->get_controls(),
				'default'     => array(),
				'title_field' => '{{{ category_text }}}',
				'condition'   => array( 'show_popular_categories' => 'yes' ),
			)
		);

		$this->add_control(
			'browse_more_categories_link',
			array(
				'label'       => esc_html__( 'Browse More Categories Link', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'https://your-link.com', 'hbl' ),
				'default'     => array( 'url' => '', 'is_external' => false, 'nofollow' => false ),
				'condition'   => array( 'show_popular_categories' => 'yes' ),
			)
		);

		$this->add_control(
			'show_sort_dropdown',
			array(
				'label'        => esc_html__( 'Show Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle (Grid/List)', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'default_view',
			array(
				'label'   => esc_html__( 'Default View', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grid',
				'options' => array(
					'grid' => esc_html__( 'Grid View', 'hbl' ),
					'list' => esc_html__( 'List View', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'grid_view_icon',
			array(
				'label'     => esc_html__( 'Grid View Icon', 'hbl' ),
				'type'      => Controls_Manager::MEDIA,
				'default'   => array( 'url' => get_template_directory_uri() . '/assets/images/grid-view-icon.svg' ),
				'condition' => array( 'show_view_toggle' => 'yes' ),
			)
		);

		$this->add_control(
			'list_view_icon',
			array(
				'label'     => esc_html__( 'List View Icon', 'hbl' ),
				'type'      => Controls_Manager::MEDIA,
				'default'   => array( 'url' => get_template_directory_uri() . '/assets/images/list-view-icon.svg' ),
				'condition' => array( 'show_view_toggle' => 'yes' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_fallback_logo',
			array( 'label' => esc_html__( 'Fallback Logo', 'hbl' ) )
		);

		$this->add_control(
			'fallback_logo',
			array(
				'label'       => esc_html__( 'Default Logo', 'hbl' ),
				'type'        => Controls_Manager::MEDIA,
				'default'     => array( 'url' => '' ),
				'description' => esc_html__( 'Displayed for listings without a logo', 'hbl' ),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		$settings  = $this->get_settings_for_display();
		$widget_id = $this->get_id();

		$keyword       = isset( $_GET['q'] )        ? sanitize_text_field( wp_unslash( $_GET['q'] ) )        : '';
		$category_slug = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$location_slug = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		$paged         = max( 1, get_query_var( 'paged', 1 ) );
		$letter        = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['letter'] ) ) ) : '';

		$per_page = absint( $settings['listings_per_page'] );

		$args = array(
			'post_type'      => ATBDP_POST_TYPE,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'post_status'    => 'publish',
		);

		if ( ! empty( $keyword ) ) {
			$args['s']       = $keyword;
			$args['orderby'] = 'relevance';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

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

		if ( isset( $args['tax_query'] ) && count( $args['tax_query'] ) > 1 ) {
			$args['tax_query']['relation'] = 'AND';
		}

		$query = new \WP_Query( $args );

		$has_search              = ! empty( $keyword ) || ! empty( $category_slug ) || ! empty( $location_slug );
		$default_view            = ! empty( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$show_alpha              = isset( $settings['show_alphabetical_filter'] ) && 'yes' === $settings['show_alphabetical_filter'];
		$show_keyword            = isset( $settings['show_keyword_search'] ) && 'yes' === $settings['show_keyword_search'];
		$show_popular_search     = isset( $settings['show_popular_search'] ) && 'yes' === $settings['show_popular_search'];
		$show_popular_categories = isset( $settings['show_popular_categories'] ) && 'yes' === $settings['show_popular_categories'];
		$show_sort               = isset( $settings['show_sort_dropdown'] ) && 'yes' === $settings['show_sort_dropdown'];
		$show_toggle             = isset( $settings['show_view_toggle'] ) && 'yes' === $settings['show_view_toggle'];

		$popular_searches = array();
		if ( $show_popular_search ) {
			if ( ! empty( $settings['popular_searches'] ) && is_array( $settings['popular_searches'] ) ) {
				$popular_searches = $settings['popular_searches'];
			} else {
				$popular_tags = get_terms( array(
					'taxonomy'   => 'at_biz_dir-tags',
					'hide_empty' => true,
					'orderby'    => 'count',
					'order'      => 'DESC',
					'number'     => 6,
				) );
				if ( ! is_wp_error( $popular_tags ) ) {
					foreach ( $popular_tags as $tag ) {
						$popular_searches[] = array( 'search_text' => $tag->name, 'search_tag' => $tag->term_id );
					}
				}
			}
		}

		$popular_categories = array();
		if ( $show_popular_categories && ! empty( $settings['popular_categories'] ) && is_array( $settings['popular_categories'] ) ) {
			$popular_categories = $settings['popular_categories'];
		}

		$query_settings = array(
			'listings_per_page' => $per_page,
			'enable_pagination' => $settings['enable_pagination'] ?? 'yes',
		);
		?>
		<div class="hbl-v2-widget hbl-directorist-widget hbl-v2-lresults-widget"
		     data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
		     data-default-view="<?php echo esc_attr( $default_view ); ?>"
		     data-widget-settings="<?php echo esc_attr( wp_json_encode( $settings ) ); ?>">

			<?php ?>
			<?php if ( $has_search && isset( $settings['show_results_count'] ) && 'yes' === $settings['show_results_count'] ) : ?>
				<div class="hbl-v2-lresults-header">
					<div class="hbl-v2-lresults-count">
						<span class="hbl-v2-lresults-count-number"><?php echo esc_html( $query->found_posts ); ?></span>
						<?php echo esc_html( _n( 'listing found', 'listings found', $query->found_posts, 'hbl' ) ); ?>
					</div>
					<div class="hbl-v2-lresults-active-filters">
						<?php if ( ! empty( $keyword ) ) : ?>
							<span class="hbl-v2-lresults-filter-tag">
								<i class="bi bi-search"></i>
								"<?php echo esc_html( $keyword ); ?>"
								<a href="<?php echo esc_url( remove_query_arg( 'q' ) ); ?>" class="hbl-v2-lresults-filter-remove">&times;</a>
							</span>
						<?php endif; ?>
						<?php if ( $category_term ) : ?>
							<span class="hbl-v2-lresults-filter-tag">
								<i class="bi bi-folder"></i>
								<?php echo esc_html( $category_term->name ); ?>
								<a href="<?php echo esc_url( remove_query_arg( 'category' ) ); ?>" class="hbl-v2-lresults-filter-remove">&times;</a>
							</span>
						<?php endif; ?>
						<?php if ( $location_term ) : ?>
							<span class="hbl-v2-lresults-filter-tag">
								<i class="bi bi-geo-alt"></i>
								<?php echo esc_html( $location_term->name ); ?>
								<a href="<?php echo esc_url( remove_query_arg( 'location' ) ); ?>" class="hbl-v2-lresults-filter-remove">&times;</a>
							</span>
						<?php endif; ?>
						<a href="<?php echo esc_url( strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), '?' ) ); ?>" class="hbl-v2-lresults-clear-all">
							<?php esc_html_e( 'Clear all', 'hbl' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<?php ?>
			<?php if ( $show_popular_search && ! empty( $popular_searches ) ) : ?>
				<div class="hbl-v2-popular-searches-section">
					<div class="hbl-v2-popular-searches-buttons">
						<?php foreach ( $popular_searches as $item ) :
							$item_text = isset( $item['search_text'] ) ? $item['search_text'] : '';
							$item_tag  = isset( $item['search_tag'] ) ? $item['search_tag'] : 0;
						?>
							<button type="button" class="hbl-v2-popular-search-btn" data-tag="<?php echo esc_attr( $item_tag ); ?>">
								<?php echo esc_html( $item_text ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php ?>
			<?php if ( $show_alpha ) : ?>
				<div class="hbl-v2-alphabetical-filter">
					<div class="hbl-v2-filter-buttons">
						<?php foreach ( range( 'A', 'Z' ) as $alpha_letter ) :
							$is_active  = ( $letter === $alpha_letter );
							$letter_url = $is_active ? remove_query_arg( 'letter' ) : add_query_arg( 'letter', $alpha_letter );
						?>
							<a href="<?php echo esc_url( $letter_url ); ?>"
							   class="hbl-v2-letter-btn <?php echo $is_active ? 'active' : ''; ?>"
							   data-letter="<?php echo esc_attr( $alpha_letter ); ?>">
								<?php echo esc_html( $alpha_letter ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php ?>
			<?php if ( $show_keyword || $show_popular_categories || $show_sort || $show_toggle ) : ?>
				<div class="hbl-v2-filters-bar">

					<div class="hbl-v2-filters-left">
						<span class="hbl-v2-filters-label"><?php esc_html_e( 'Filters', 'hbl' ); ?></span>

						<?php if ( $show_keyword ) : ?>
							<div class="hbl-v2-keyword-search">
								<i class="bi bi-search hbl-v2-keyword-search-icon"></i>
								<input type="text" class="hbl-v2-keyword-search-input" placeholder="<?php esc_attr_e( 'Search by Keyword...', 'hbl' ); ?>" autocomplete="off">
								<button type="button" class="hbl-v2-keyword-clear" style="display:none;"><i class="bi bi-x-lg"></i></button>
							</div>
						<?php endif; ?>

						<?php if ( $show_popular_categories && ! empty( $popular_categories ) ) : ?>
							<div class="hbl-v2-popular-categories-wrapper">
								<div class="hbl-v2-popular-categories-container">
									<button type="button" class="hbl-v2-popular-categories-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-popular-categories-label"><?php esc_html_e( 'Search Categories', 'hbl' ); ?></span>
										<span class="hbl-v2-popular-categories-chevron">
											<svg width="8" height="4" viewBox="0 0 8 4" fill="none"><path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/></svg>
										</span>
									</button>
								</div>
								<div class="hbl-v2-popular-categories-dropdown" role="listbox" aria-hidden="true">
									<div class="hbl-v2-popular-categories-list">
										<?php foreach ( $popular_categories as $item ) :
											$item_text     = isset( $item['category_text'] ) ? $item['category_text'] : '';
											$item_category = isset( $item['category_id'] ) ? $item['category_id'] : 0;
										?>
											<button type="button" class="hbl-v2-popular-category-item" data-category="<?php echo esc_attr( $item_category ); ?>" role="option">
												<?php echo esc_html( $item_text ); ?>
											</button>
										<?php endforeach; ?>
									</div>
									<?php if ( ! empty( $settings['browse_more_categories_link']['url'] ) ) : ?>
										<div class="hbl-v2-popular-categories-footer">
											<a href="<?php echo esc_url( $settings['browse_more_categories_link']['url'] ); ?>"
											   class="hbl-v2-browse-more"
											   <?php echo $settings['browse_more_categories_link']['is_external'] ? 'target="_blank"' : ''; ?>
											   <?php echo $settings['browse_more_categories_link']['nofollow'] ? 'rel="nofollow"' : ''; ?>>
												<span class="hbl-v2-browse-more-text"><?php esc_html_e( 'Browse More Categories', 'hbl' ); ?></span>
												<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8z"/></svg>
											</a>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endif; ?>

						<?php if ( $show_sort ) : ?>
							<div class="hbl-v2-sort-wrapper hbl-v2-filter-dropdown">
								<div class="hbl-v2-sort-container">
									<button type="button" class="hbl-v2-sort-trigger" aria-expanded="false" aria-haspopup="listbox">
										<span class="hbl-v2-sort-label"><?php esc_html_e( 'Sort By', 'hbl' ); ?></span>
										<span class="hbl-v2-sort-chevron"><svg width="8" height="4" viewBox="0 0 8 4" fill="none"><path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/></svg></span>
									</button>
								</div>
								<div class="hbl-v2-sort-dropdown" role="listbox" aria-hidden="true" style="display:none;">
									<div class="hbl-v2-sort-list">
										<button type="button" class="hbl-v2-sort-item" data-value="recommended" role="option"><?php esc_html_e( 'Recommended', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="a-z" role="option"><?php esc_html_e( 'A–Z', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="z-a" role="option"><?php esc_html_e( 'Z–A', 'hbl' ); ?></button>
										<button type="button" class="hbl-v2-sort-item" data-value="newest" role="option"><?php esc_html_e( 'Newest', 'hbl' ); ?></button>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( $show_toggle ) : ?>
						<div class="hbl-v2-filters-right">
							<div class="hbl-v2-view-toggle">
								<span class="hbl-v2-view-label"><?php esc_html_e( 'View as:', 'hbl' ); ?></span>
								<div class="hbl-v2-view-buttons">
									<button type="button" class="hbl-v2-view-btn <?php echo 'grid' === $default_view ? 'active' : ''; ?>" data-view="grid">
										<?php if ( ! empty( $settings['grid_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['grid_view_icon']['url'] ); ?>" alt="Grid" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-grid-3x3-gap"></i>
										<?php endif; ?>
									</button>
									<button type="button" class="hbl-v2-view-btn <?php echo 'list' === $default_view ? 'active' : ''; ?>" data-view="list">
										<?php if ( ! empty( $settings['list_view_icon']['url'] ) ) : ?>
											<img src="<?php echo esc_url( $settings['list_view_icon']['url'] ); ?>" alt="List" class="hbl-v2-view-icon">
										<?php else : ?>
											<i class="bi bi-list-ul"></i>
										<?php endif; ?>
									</button>
								</div>
							</div>
						</div>
					<?php endif; ?>

				</div>
			<?php endif; ?>

			<?php ?>
			<div class="hbl-v2-active-filters" style="display:none;">
				<div class="hbl-v2-active-filters-container"></div>
			</div>

			<?php ?>
			<?php if ( $query->have_posts() ) : ?>

				<?php
				$listings = array();
				while ( $query->have_posts() ) {
					$query->the_post();
					$listings[] = get_the_ID();
				}
				wp_reset_postdata();

				$half         = (int) ceil( count( $listings ) / 2 );
				$left_column  = array_slice( $listings, 0, $half );
				$right_column = array_slice( $listings, $half );
				?>

				<div class="hbl-v2-listings-grid <?php echo 'list' === $default_view ? 'list-view' : ''; ?>">
					<div class="hbl-v2-left-column">
						<?php foreach ( $left_column as $listing_id ) :
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach;
						wp_reset_postdata(); ?>
					</div>
					<div class="hbl-v2-right-column">
						<?php foreach ( $right_column as $listing_id ) :
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach;
						wp_reset_postdata(); ?>
					</div>
				</div>

				<?php if ( isset( $settings['enable_pagination'] ) && 'yes' === $settings['enable_pagination'] && $query->max_num_pages > 1 ) : ?>
					<div class="hbl-v2-pagination">
						<div class="hbl-v2-pagination-info">
							<?php
							$start_item = ( ( $paged - 1 ) * $per_page ) + 1;
							$end_item   = min( $paged * $per_page, $query->found_posts );
							printf( esc_html__( 'Showing %1$d - %2$d of %3$d listings', 'hbl' ), $start_item, $end_item, $query->found_posts );
							?>
						</div>
						<div class="hbl-v2-pagination-controls">
							<?php if ( $paged > 1 ) : ?>
								<a href="#" data-page="<?php echo esc_attr( $paged - 1 ); ?>" class="hbl-v2-page-btn hbl-v2-prev-btn hbl-v2-page-link">
									<i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
								</a>
							<?php else : ?>
								<span class="hbl-v2-page-btn hbl-v2-prev-btn disabled">
									<i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
								</span>
							<?php endif; ?>

							<div class="hbl-v2-page-numbers">
								<?php
								$range     = 2;
								$show_dots = false;
								for ( $i = 1; $i <= $query->max_num_pages; $i++ ) :
									if ( $i === 1 || $i === $query->max_num_pages || ( $i >= $paged - $range && $i <= $paged + $range ) ) :
										$show_dots = true;
										?>
										<a href="#" data-page="<?php echo esc_attr( $i ); ?>" class="hbl-v2-page-number hbl-v2-page-link <?php echo $i === $paged ? 'active' : ''; ?>">
											<?php echo esc_html( $i ); ?>
										</a>
										<?php
									elseif ( $show_dots ) :
										echo '<span class="hbl-v2-page-dots">...</span>';
										$show_dots = false;
									endif;
								endfor;
								?>
							</div>

							<?php if ( $paged < $query->max_num_pages ) : ?>
								<a href="#" data-page="<?php echo esc_attr( $paged + 1 ); ?>" class="hbl-v2-page-btn hbl-v2-next-btn hbl-v2-page-link">
									<span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i>
								</a>
							<?php else : ?>
								<span class="hbl-v2-page-btn hbl-v2-next-btn disabled">
									<span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i>
								</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<div class="hbl-v2-no-results">
					<i class="bi bi-search" style="font-size:3rem;color:var(--hbl-gray-300);display:block;margin-bottom:16px;"></i>
					<h3><?php echo esc_html( $settings['no_results_title'] ); ?></h3>
					<p><?php echo esc_html( $settings['no_results_message'] ); ?></p>
				</div>
			<?php endif; ?>

		</div>

		<script>
		jQuery(document).ready(function($) {
			var widgetId         = '<?php echo esc_js( $widget_id ); ?>';
			var $widget          = $('[data-widget-id="' + widgetId + '"]');
			var widgetSettingsJSON = $widget.attr('data-widget-settings') || '{}';
			var currentFilters   = {
				keyword : '',
				category: 0,
				tag     : 0,
				letter  : '<?php echo esc_js( $letter ); ?>',
				sort    : 'recommended',
				paged   : 1
			};

			$widget.find('.hbl-v2-view-btn').on('click', function() {
				var view   = $(this).data('view');
				var $grid  = $widget.find('.hbl-v2-listings-grid');
				$(this).addClass('active').siblings().removeClass('active');
				$grid.toggleClass('list-view', view === 'list');
			});

			var searchTimeout;
			$widget.find('.hbl-v2-keyword-search-input').on('input', function() {
				var keyword = $(this).val().trim();
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					currentFilters.keyword = keyword;
					currentFilters.paged   = 1;
					$widget.find('.hbl-v2-keyword-clear').toggle(!! keyword);
					applyFilters();
				}, 500);
			});

			$widget.find('.hbl-v2-keyword-clear').on('click', function() {
				$widget.find('.hbl-v2-keyword-search-input').val('');
				currentFilters.keyword = '';
				currentFilters.paged   = 1;
				$(this).hide();
				applyFilters();
			});

			$widget.find('.hbl-v2-popular-search-btn').on('click', function() {
				$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
				$(this).addClass('active');
				currentFilters.tag   = parseInt($(this).data('tag')) || 0;
				currentFilters.paged = 1;
				applyFilters();
			});

			$widget.find('.hbl-v2-popular-categories-trigger').on('click', function() {
				var $dropdown = $widget.find('.hbl-v2-popular-categories-dropdown');
				var isOpen    = $(this).attr('aria-expanded') === 'true';
				if (isOpen) {
					$dropdown.slideUp(200);
					$(this).attr('aria-expanded', 'false');
					$dropdown.attr('aria-hidden', 'true');
				} else {
					$dropdown.slideDown(200);
					$(this).attr('aria-expanded', 'true');
					$dropdown.attr('aria-hidden', 'false');
				}
			});

			$widget.find('.hbl-v2-popular-category-item').on('click', function() {
				currentFilters.category = parseInt($(this).data('category')) || 0;
				currentFilters.paged    = 1;
				$widget.find('.hbl-v2-popular-categories-dropdown').slideUp(200);
				$widget.find('.hbl-v2-popular-categories-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-popular-categories-dropdown').attr('aria-hidden', 'true');
				$widget.find('.hbl-v2-popular-categories-label').text($(this).text().trim());
				applyFilters();
			});

			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-popular-categories-wrapper').length) {
					$widget.find('.hbl-v2-popular-categories-dropdown').slideUp(200);
					$widget.find('.hbl-v2-popular-categories-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-popular-categories-dropdown').attr('aria-hidden', 'true');
				}
			});

			$widget.find('.hbl-v2-sort-trigger').on('click', function() {
				var $dropdown = $widget.find('.hbl-v2-sort-dropdown');
				var isOpen    = $(this).attr('aria-expanded') === 'true';
				if (isOpen) {
					$dropdown.slideUp(200);
					$(this).attr('aria-expanded', 'false');
					$dropdown.attr('aria-hidden', 'true');
				} else {
					$dropdown.slideDown(200);
					$(this).attr('aria-expanded', 'true');
					$dropdown.attr('aria-hidden', 'false');
				}
			});

			$widget.find('.hbl-v2-sort-item').on('click', function() {
				currentFilters.sort  = $(this).data('value');
				currentFilters.paged = 1;
				var label = currentFilters.sort === 'recommended' ? '<?php esc_html_e( 'Sort By', 'hbl' ); ?>' : $(this).text().trim();
				$widget.find('.hbl-v2-sort-label').text(label);
				$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
				$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-sort-dropdown').attr('aria-hidden', 'true');
				$widget.find('.hbl-v2-sort-item').removeClass('selected');
				$(this).addClass('selected');
				applyFilters();
			});

			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-sort-wrapper').length) {
					$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
					$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-sort-dropdown').attr('aria-hidden', 'true');
				}
			});

			$widget.find('.hbl-v2-letter-btn').on('click', function(e) {
				e.preventDefault();
				var ltr = $(this).data('letter');
				if ($(this).hasClass('active')) {
					currentFilters.letter = '';
					$(this).removeClass('active');
				} else {
					currentFilters.letter = ltr;
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					$(this).addClass('active');
				}
				currentFilters.paged = 1;
				applyFilters();
			});

			$widget.on('click', '.hbl-v2-page-link', function(e) {
				e.preventDefault();
				currentFilters.paged = parseInt($(this).data('page')) || 1;
				applyFilters();
				$('html, body').animate({ scrollTop: $widget.offset().top - 100 }, 300);
			});

			function applyFilters() {
				$.ajax({
					url : hblV2Ajax.ajaxurl,
					type: 'POST',
					data: {
						action         : 'hbl_v2_filter_listings',
						nonce          : hblV2Ajax.nonce,
						widget_id      : widgetId,
						widget_settings: widgetSettingsJSON,
						keyword        : currentFilters.keyword,
						category       : currentFilters.category,
						tag            : currentFilters.tag,
						letter         : currentFilters.letter,
						sort           : currentFilters.sort,
						paged          : currentFilters.paged,
						per_page       : <?php echo absint( $per_page ); ?>
					},
					beforeSend: function() { $widget.addClass('loading'); },
					success: function(response) {
						if (response.success) {
							updateActiveFilters(response.active_filters);
							if (response.html) {
								$widget.find('.hbl-v2-left-column').html(response.html.left);
								$widget.find('.hbl-v2-right-column').html(response.html.right);
							}
							if (response.pagination_html) {
								var $pag = $widget.find('.hbl-v2-pagination');
								$pag.length ? $pag.replaceWith(response.pagination_html) : $widget.find('.hbl-v2-listings-grid').after(response.pagination_html);
							}
						}
					},
					error   : function(xhr, status, error) { console.error('Filter error:', error); },
					complete: function() { $widget.removeClass('loading'); }
				});
			}

			function updateActiveFilters(filters) {
				var $container = $widget.find('.hbl-v2-active-filters-container');
				$container.empty();
				if (filters && filters.length > 0) {
					filters.forEach(function(filter) {
						var $tag = $('<div class="hbl-v2-active-filter-tag" data-filter-type="' + filter.type + '"></div>');
						$tag.append('<span class="hbl-v2-active-filter-tag-icon">' + filter.icon + '</span>');
						$tag.append('<span class="hbl-v2-active-filter-tag-text">' + filter.label + '</span>');
						var $clearBtn = $('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Clear filter"><svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M9 3L3 9M3 3L9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button>');
						$clearBtn.on('click', function() { clearFilter(filter.type); });
						$tag.append($clearBtn);
						$container.append($tag);
					});
					$widget.find('.hbl-v2-active-filters').show();
				} else {
					$widget.find('.hbl-v2-active-filters').hide();
				}
			}

			function clearFilter(type) {
				switch(type) {
					case 'keyword':
						$widget.find('.hbl-v2-keyword-search-input').val('');
						$widget.find('.hbl-v2-keyword-clear').hide();
						currentFilters.keyword = '';
						break;
					case 'category':
						$widget.find('.hbl-v2-popular-categories-label').text('<?php esc_html_e( 'Search Categories', 'hbl' ); ?>');
						currentFilters.category = 0;
						break;
					case 'tag':
						$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
						currentFilters.tag = 0;
						break;
					case 'letter':
						$widget.find('.hbl-v2-letter-btn').removeClass('active');
						currentFilters.letter = '';
						break;
					case 'sort':
						$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
						$widget.find('.hbl-v2-sort-item').removeClass('selected');
						currentFilters.sort = 'recommended';
						break;
				}
				currentFilters.paged = 1;
				applyFilters();
			}

			if (!window.hblV2MapListenerAdded) {
			window.hblV2MapListenerAdded = true;

			function hblInitMap(mapContainer) {
				if (!mapContainer || mapContainer.dataset.initialized) return;
				var lat = mapContainer.dataset.lat;
				var lng = mapContainer.dataset.lng;
				var title = mapContainer.dataset.title;
				if (lat && lng && typeof L !== 'undefined') {
					try {
						mapContainer.innerHTML = '';
						var map = L.map(mapContainer, { zoomControl: true, scrollWheelZoom: true, dragging: true, attributionControl: false }).setView([parseFloat(lat), parseFloat(lng)], 15);
						L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
						L.marker([parseFloat(lat), parseFloat(lng)]).addTo(map).bindPopup('<strong>' + title + '</strong>');
						mapContainer.dataset.initialized = 'true';
						mapContainer._hblMap = map;
					} catch (err) {}
				}
			}

			document.addEventListener('mouseenter', function(e) {
				var card = e.target.closest('.hbl-v2-listing-card');
				if (!card) return;
				hblInitMap(card.querySelector('.hbl-v2-quick-map'));
			}, true);

			document.addEventListener('click', function(e) {
				var card = e.target.closest('.hbl-v2-listing-card');
				if (!card) return;
				if (e.target.closest('a, button, input, select, textarea, .hbl-v2-favorite-btn, .hbl-v2-expand-trigger, .leaflet-container')) return;
				var isMapClick = !!e.target.closest('.hbl-v2-quick-map');
				if (!isMapClick && card.classList.contains('hbl-expandable')) { card.classList.toggle('expanded'); }
				var mapContainer = card.querySelector('.hbl-v2-quick-map');
				hblInitMap(mapContainer);
				if (mapContainer && mapContainer._hblMap) {
					setTimeout(function() { mapContainer._hblMap.invalidateSize(); }, 50);
					setTimeout(function() { mapContainer._hblMap.invalidateSize(); }, 300);
				}
			}, true);
		}
		});
		</script>
		<?php
	}
}