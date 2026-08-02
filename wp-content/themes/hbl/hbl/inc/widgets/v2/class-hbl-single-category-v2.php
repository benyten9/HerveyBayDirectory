<?php
/**
 * HBL Single Category V2 Widget
 *
 * Displays listings from a category (auto-detected from URL) using V2 design.
 *
 * @package HBL
 * @since 2.0.0
 */

namespace HBL\Widgets\V2;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use HBL\Widgets\V2\Traits\Query_Handler;
use HBL\Widgets\V2\Traits\Card_Renderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Single_Category_V2 extends Widget_Base {

	use Query_Handler;
	use Card_Renderer;

	public function get_name() {
		return 'hbl-single-category-v2';
	}

	public function get_title() {
		return esc_html__( 'HBL Single Category V2', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-folder';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'category', 'archive', 'listings', 'directory', 'v2' );
	}

	/**
	 * Detect current category from URL / query vars.
	 */
	private function get_current_term() {
		$atbdp_cat = get_query_var( 'atbdp_category' );
		if ( $atbdp_cat ) {
			$term = get_term_by( 'slug', $atbdp_cat, 'at_biz_dir-category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		if ( is_tax( 'at_biz_dir-category' ) ) {
			$term = get_queried_object();
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		// URL path detection
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$url_path    = trim( wp_parse_url( $request_uri, PHP_URL_PATH ), '/' );
		$path_parts  = explode( '/', $url_path );

		foreach ( $path_parts as $index => $part ) {
			if ( in_array( $part, array( 'single-category', 'category', 'listing-category' ), true ) && isset( $path_parts[ $index + 1 ] ) ) {
				$term = get_term_by( 'slug', $path_parts[ $index + 1 ], 'at_biz_dir-category' );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term;
				}
			}
		}

		// GET param fallback
		if ( isset( $_GET['category'] ) ) {
			$term = get_term_by( 'slug', sanitize_text_field( $_GET['category'] ), 'at_biz_dir-category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}

		return null;
	}

	protected function register_controls() {

		// Display Options
		$this->start_controls_section(
			'section_display',
			array( 'label' => esc_html__( 'Display Options', 'hbl' ) )
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
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_term_header',
			array(
				'label'        => esc_html__( 'Show Category Header', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_alphabetical_filter',
			array(
				'label'        => esc_html__( 'Show A-Z Filter', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_keyword_search',
			array(
				'label'        => esc_html__( 'Show Keyword Search', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_sort_dropdown',
			array(
				'label'        => esc_html__( 'Show Sort Dropdown', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_view_toggle',
			array(
				'label'        => esc_html__( 'Show View Toggle', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
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
					'grid' => esc_html__( 'Grid', 'hbl' ),
					'list' => esc_html__( 'List', 'hbl' ),
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

		// Build tag options for repeaters
		$_tag_opts = array();
		$_raw_tags = get_terms( array(
			'taxonomy'   => 'at_biz_dir-tags',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
			'number'     => 200,
		) );
		if ( ! is_wp_error( $_raw_tags ) && ! empty( $_raw_tags ) ) {
			foreach ( $_raw_tags as $_t ) {
				$_tag_opts[ $_t->term_id ] = $_t->name;
			}
		}

		// Featured Tags
		$this->add_control(
			'show_popular_search',
			array(
				'label'        => esc_html__( 'Show Featured Tags', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		if ( ! empty( $_tag_opts ) ) {
			$popular_repeater = new \Elementor\Repeater();
			$popular_repeater->add_control(
				'search_text',
				array(
					'label'       => esc_html__( 'Label', 'hbl' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => esc_html__( 'Tag', 'hbl' ),
					'label_block' => true,
				)
			);
			$popular_repeater->add_control(
				'search_tag',
				array(
					'label'       => esc_html__( 'Tag', 'hbl' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $_tag_opts,
					'label_block' => true,
				)
			);
			$this->add_control(
				'popular_searches',
				array(
					'label'       => esc_html__( 'Featured Tags', 'hbl' ),
					'type'        => Controls_Manager::REPEATER,
					'fields'      => $popular_repeater->get_controls(),
					'default'     => array(),
					'title_field' => '{{{ search_text }}}',
					'condition'   => array( 'show_popular_search' => 'yes' ),
				)
			);
		}

		// More Filters
		$this->add_control(
			'show_more_filters',
			array(
				'label'        => esc_html__( 'Show More Filters', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'no',
			)
		);

		if ( ! empty( $_tag_opts ) ) {
			$more_filters_repeater = new \Elementor\Repeater();
			$more_filters_repeater->add_control(
				'filter_text',
				array(
					'label'       => esc_html__( 'Label', 'hbl' ),
					'type'        => Controls_Manager::TEXT,
					'default'     => esc_html__( 'Filter', 'hbl' ),
					'label_block' => true,
				)
			);
			$more_filters_repeater->add_control(
				'filter_tag',
				array(
					'label'       => esc_html__( 'Tag', 'hbl' ),
					'type'        => Controls_Manager::SELECT,
					'options'     => $_tag_opts,
					'label_block' => true,
				)
			);
			$this->add_control(
				'more_filters',
				array(
					'label'       => esc_html__( 'More Filters', 'hbl' ),
					'type'        => Controls_Manager::REPEATER,
					'fields'      => $more_filters_repeater->get_controls(),
					'default'     => array(),
					'title_field' => '{{{ filter_text }}}',
					'condition'   => array( 'show_more_filters' => 'yes' ),
				)
			);
		}

		$this->end_controls_section();

		// Plan Tier Mapping
		$this->start_controls_section(
			'section_plan_tiers',
			array( 'label' => esc_html__( 'Plan Tier Mapping', 'hbl' ) )
		);

		$pricing_plans = $this->get_pricing_plans();
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

		// Plan Badges
		$this->start_controls_section(
			'section_plan_badges',
			array( 'label' => esc_html__( 'Plan Badges', 'hbl' ) )
		);

		$this->add_control(
			'show_plan_badges',
			array(
				'label'        => esc_html__( 'Show Plan Badges', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$repeater = new \Elementor\Repeater();
		$repeater->add_control( 'plan_tier', array(
			'label'   => esc_html__( 'Plan Tier', 'hbl' ),
			'type'    => Controls_Manager::SELECT,
			'default' => 'gold',
			'options' => array(
				'gold'   => 'Gold',
				'silver' => 'Silver',
				'bronze' => 'Bronze',
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
					array( 'plan_tier' => 'silver',  'badge_text' => 'FEATURED', 'badge_bg_color' => '#008080', 'badge_text_color' => '#FFFFFF' ),
					array( 'plan_tier' => 'bronze',  'badge_text' => 'BASIC',    'badge_bg_color' => '#6c757d', 'badge_text_color' => '#FFFFFF' ),
				),
				'title_field' => '{{{ plan_tier.toUpperCase() }}} - {{{ badge_text }}}',
				'condition'   => array( 'show_plan_badges' => 'yes' ),
			)
		);

		$this->end_controls_section();

		// Fallback Logo
		$this->start_controls_section(
			'section_fallback_logo',
			array( 'label' => esc_html__( 'Fallback Logo', 'hbl' ) )
		);
		$this->add_control(
			'fallback_logo',
			array(
				'label' => esc_html__( 'Default Logo', 'hbl' ),
				'type'  => Controls_Manager::MEDIA,
			)
		);
		$this->end_controls_section();
	}

	protected function render() {
		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		$settings    = $this->get_settings_for_display();
		$widget_id   = $this->get_id();
		$current_term = $this->get_current_term();

		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$letter = isset( $_GET['letter'] ) ? strtoupper( sanitize_text_field( $_GET['letter'] ) ) : '';

		// Inject forced category into settings for initial query and AJAX
		$query_settings = $settings;
		if ( $current_term ) {
			$query_settings['category_filter_type'] = 'specific';
			$query_settings['category']             = array( $current_term->term_id );
			$query_settings['number_of_listings']   = -1;
		}

		$query        = $this->get_listings_query( $query_settings, $paged, $letter );
		$default_view        = isset( $settings['default_view'] ) ? $settings['default_view'] : 'grid';
		$show_sort           = isset( $settings['show_sort_dropdown'] ) && 'yes' === $settings['show_sort_dropdown'];
		$show_toggle         = isset( $settings['show_view_toggle'] ) && 'yes' === $settings['show_view_toggle'];
		$show_search         = isset( $settings['show_keyword_search'] ) && 'yes' === $settings['show_keyword_search'];
		$show_popular_search = isset( $settings['show_popular_search'] ) && 'yes' === $settings['show_popular_search'];
		$show_more_filters   = isset( $settings['show_more_filters'] ) && 'yes' === $settings['show_more_filters'];

		$popular_searches   = ( $show_popular_search && ! empty( $settings['popular_searches'] ) ) ? $settings['popular_searches'] : array();
		$more_filters_items = ( $show_more_filters && ! empty( $settings['more_filters'] ) ) ? $settings['more_filters'] : array();
		?>
		<div class="hbl-v2-widget hbl-v2-category-widget"
		     data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
		     data-default-view="<?php echo esc_attr( $default_view ); ?>"
		     data-widget-settings="<?php echo esc_attr( wp_json_encode( $query_settings ) ); ?>">

			<?php if ( $current_term && isset( $settings['show_term_header'] ) && 'yes' === $settings['show_term_header'] ) : ?>
				<div class="hbl-v2-term-header">
					<h1 class="hbl-v2-term-title"><?php echo esc_html( $current_term->name ); ?></h1>
					<?php if ( ! empty( $current_term->description ) ) : ?>
						<p class="hbl-v2-term-description"><?php echo esc_html( $current_term->description ); ?></p>
					<?php endif; ?>
					<span class="hbl-v2-term-count">
						<?php printf( esc_html( _n( '%d listing', '%d listings', $current_term->count, 'hbl' ) ), $current_term->count ); ?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( ! $current_term ) : ?>
				<div class="hbl-v2-notice">
					<p><?php esc_html_e( 'No category detected. Please navigate to a category page.', 'hbl' ); ?></p>
				</div>
			<?php else : ?>

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

			<?php if ( isset( $settings['show_alphabetical_filter'] ) && 'yes' === $settings['show_alphabetical_filter'] ) : ?>
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

			<?php if ( $show_search || $show_sort || $show_toggle || $show_more_filters ) : ?>
				<div class="hbl-v2-filters-bar">
					<div class="hbl-v2-filters-left">
						<span class="hbl-v2-filters-label"><?php esc_html_e( 'Filters', 'hbl' ); ?></span>
						<?php if ( $show_search ) : ?>
							<div class="hbl-v2-keyword-search">
								<i class="bi bi-search hbl-v2-keyword-search-icon"></i>
								<input type="text" class="hbl-v2-keyword-search-input" placeholder="<?php esc_attr_e( 'Search by Keyword...', 'hbl' ); ?>" autocomplete="off">
								<button type="button" class="hbl-v2-keyword-clear" style="display:none;"><i class="bi bi-x-lg"></i></button>
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

						<?php if ( $show_more_filters && ! empty( $more_filters_items ) ) : ?>
						<div class="hbl-v2-more-filters-wrapper">
							<div class="hbl-v2-more-filters-container">
								<button type="button" class="hbl-v2-more-filters-trigger" aria-expanded="false" aria-haspopup="listbox">
									<span class="hbl-v2-more-filters-label"><?php esc_html_e( 'More Filters', 'hbl' ); ?></span>
									<span class="hbl-v2-more-filters-chevron">
										<svg width="8" height="4" viewBox="0 0 8 4" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M1 0.5L4 3.5L7 0.5" stroke="#F9532A" stroke-width="2"/>
										</svg>
									</span>
								</button>
							</div>
							<div class="hbl-v2-more-filters-dropdown" role="listbox" aria-hidden="true">
								<div class="hbl-v2-more-filters-list">
									<?php foreach ( $more_filters_items as $item ) :
										$item_text = isset( $item['filter_text'] ) ? $item['filter_text'] : '';
										$item_tag  = isset( $item['filter_tag'] ) ? $item['filter_tag'] : 0;
									?>
										<button type="button" class="hbl-v2-more-filter-item" data-tag="<?php echo esc_attr( $item_tag ); ?>" role="option">
											<?php echo esc_html( $item_text ); ?>
										</button>
									<?php endforeach; ?>
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

			<div class="hbl-v2-active-filters" style="display:none;">
				<div class="hbl-v2-active-filters-container"></div>
			</div>

			<div class="hbl-v2-listings-grid <?php echo 'list' === $default_view ? 'list-view' : ''; ?>">
				<?php if ( $query->have_posts() ) :
					$listings     = array();
					while ( $query->have_posts() ) : $query->the_post();
						$listings[] = get_the_ID();
					endwhile;
					wp_reset_postdata();

					$half         = ceil( count( $listings ) / 2 );
					$left_column  = array_slice( $listings, 0, $half );
					$right_column = array_slice( $listings, $half );
					?>
					<div class="hbl-v2-left-column">
						<?php foreach ( $left_column as $listing_id ) :
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach; ?>
					</div>
					<div class="hbl-v2-right-column">
						<?php foreach ( $right_column as $listing_id ) :
							global $post;
							$post = get_post( $listing_id );
							setup_postdata( $post );
							$this->render_listing_card( $listing_id, $settings );
						endforeach; ?>
					</div>
					<?php wp_reset_postdata(); ?>
				<?php else : ?>
					<div class="hbl-v2-no-results">
						<i class="bi bi-search"></i>
						<p><?php esc_html_e( 'No listings found in this category.', 'hbl' ); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( isset( $settings['enable_pagination'] ) && 'yes' === $settings['enable_pagination'] && $query->max_num_pages > 1 ) :
				$listings_per_page = isset( $settings['listings_per_page'] ) ? absint( $settings['listings_per_page'] ) : 20;
				?>
				<div class="hbl-v2-pagination">
					<div class="hbl-v2-pagination-info">
						<?php
						$start_item = ( ( $paged - 1 ) * $listings_per_page ) + 1;
						$end_item   = min( $paged * $listings_per_page, $query->found_posts );
						printf( esc_html__( 'Showing %1$d - %2$d of %3$d listings', 'hbl' ), $start_item, $end_item, $query->found_posts );
						?>
					</div>
					<div class="hbl-v2-pagination-controls">
						<?php if ( $paged > 1 ) : ?>
							<a href="#" data-page="<?php echo esc_attr( $paged - 1 ); ?>" class="hbl-v2-page-btn hbl-v2-prev-btn hbl-v2-page-link">
								<i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-prev-btn disabled"><i class="bi bi-chevron-left"></i><span><?php esc_html_e( 'Previous', 'hbl' ); ?></span></span>
						<?php endif; ?>
						<div class="hbl-v2-page-numbers">
							<?php for ( $i = 1; $i <= $query->max_num_pages; $i++ ) :
								if ( $i === 1 || $i === $query->max_num_pages || ( $i >= $paged - 2 && $i <= $paged + 2 ) ) : ?>
									<a href="#" data-page="<?php echo esc_attr( $i ); ?>" class="hbl-v2-page-number hbl-v2-page-link <?php echo $i === $paged ? 'active' : ''; ?>"><?php echo esc_html( $i ); ?></a>
								<?php endif;
							endfor; ?>
						</div>
						<?php if ( $paged < $query->max_num_pages ) : ?>
							<a href="#" data-page="<?php echo esc_attr( $paged + 1 ); ?>" class="hbl-v2-page-btn hbl-v2-next-btn hbl-v2-page-link">
								<span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i>
							</a>
						<?php else : ?>
							<span class="hbl-v2-page-btn hbl-v2-next-btn disabled"><span><?php esc_html_e( 'Next', 'hbl' ); ?></span><i class="bi bi-chevron-right"></i></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
			<?php endif; // current_term check ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var widgetId = '<?php echo esc_js( $widget_id ); ?>';
			var $widget  = $('[data-widget-id="' + widgetId + '"]');
			var widgetSettingsJSON = $widget.attr('data-widget-settings') || '{}';
			var sortLabels = { 'recommended': '<?php esc_html_e( 'Sort By', 'hbl' ); ?>', 'a-z': 'A\u2013Z', 'z-a': 'Z\u2013A', 'newest': '<?php esc_html_e( 'Newest', 'hbl' ); ?>' };
			var currentFilters = { keyword: '', letter: '<?php echo esc_js( $letter ); ?>', sort: 'recommended', tag: 0, paged: 1 };

			function updateActiveFilters() {
				var tags = [];
				if (currentFilters.keyword) tags.push({ key: 'keyword', label: '<?php esc_html_e( 'Keyword', 'hbl' ); ?>: ' + currentFilters.keyword });
				if (currentFilters.letter)  tags.push({ key: 'letter',  label: '<?php esc_html_e( 'Letter', 'hbl' ); ?>: '  + currentFilters.letter });
				if (currentFilters.sort && currentFilters.sort !== 'recommended') tags.push({ key: 'sort', label: '<?php esc_html_e( 'Sort', 'hbl' ); ?>: ' + (sortLabels[currentFilters.sort] || currentFilters.sort) });
				var $bar = $widget.find('.hbl-v2-active-filters');
				var $container = $widget.find('.hbl-v2-active-filters-container');
				$container.empty();
				if (tags.length === 0) { $bar.slideUp(200); return; }
				$.each(tags, function(i, tag) {
					var $tag   = $('<span class="hbl-v2-active-filter-tag"></span>').text(tag.label);
					var $clear = $('<button type="button" class="hbl-v2-active-filter-tag-clear" aria-label="Remove filter">&times;</button>');
					$clear.on('click', function() { clearFilter(tag.key); });
					$tag.append($clear);
					$container.append($tag);
				});
				if (tags.length > 1) {
					var $clearAll = $('<button type="button" class="hbl-v2-active-filter-tag hbl-v2-clear-all-filters"><?php esc_html_e( 'Clear All', 'hbl' ); ?></button>');
					$clearAll.on('click', function() { clearFilter('all'); });
					$container.append($clearAll);
				}
				$bar.slideDown(200);
			}

			// Featured tag buttons
			$widget.find('.hbl-v2-popular-search-btn').on('click', function() {
				$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
				$(this).addClass('active');
				currentFilters.tag = parseInt($(this).data('tag')) || 0;
				currentFilters.paged = 1;
				updateActiveFilters();
				applyFilters();
			});

			// More Filters dropdown toggle
			$widget.find('.hbl-v2-more-filters-trigger').on('click', function() {
				var $dd = $widget.find('.hbl-v2-more-filters-dropdown');
				var open = $(this).attr('aria-expanded') === 'true';
				open ? $dd.slideUp(200) : $dd.slideDown(200);
				$(this).attr('aria-expanded', !open);
				$dd.attr('aria-hidden', open);
			});

			// More Filters item click
			$widget.find('.hbl-v2-more-filter-item').on('click', function() {
				currentFilters.tag = parseInt($(this).data('tag')) || 0;
				currentFilters.paged = 1;
				var selectedText = $(this).text().trim();
				$widget.find('.hbl-v2-more-filters-label').text(selectedText);
				$widget.find('.hbl-v2-more-filters-dropdown').slideUp(200);
				$widget.find('.hbl-v2-more-filters-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-more-filters-dropdown').attr('aria-hidden', 'true');
				updateActiveFilters();
				applyFilters();
			});

			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-more-filters-wrapper').length) {
					$widget.find('.hbl-v2-more-filters-dropdown').slideUp(200);
					$widget.find('.hbl-v2-more-filters-trigger').attr('aria-expanded', 'false');
					$widget.find('.hbl-v2-more-filters-dropdown').attr('aria-hidden', 'true');
				}
			});

			function clearFilter(key) {
				if (key === 'all') {
					currentFilters.keyword = ''; currentFilters.letter = ''; currentFilters.sort = 'recommended'; currentFilters.tag = 0;
					$widget.find('.hbl-v2-keyword-search-input').val('');
					$widget.find('.hbl-v2-keyword-clear').hide();
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
					$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
					$widget.find('.hbl-v2-sort-item').removeClass('selected');
					$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
					$widget.find('.hbl-v2-more-filters-label').text('<?php esc_html_e( 'More Filters', 'hbl' ); ?>');
				} else if (key === 'keyword') {
					currentFilters.keyword = '';
					$widget.find('.hbl-v2-keyword-search-input').val('');
					$widget.find('.hbl-v2-keyword-clear').hide();
				} else if (key === 'letter') {
					currentFilters.letter = '';
					$widget.find('.hbl-v2-letter-btn').removeClass('active');
				} else if (key === 'sort') {
					currentFilters.sort = 'recommended';
					$widget.find('.hbl-v2-sort-label').text('<?php esc_html_e( 'Sort By', 'hbl' ); ?>');
					$widget.find('.hbl-v2-sort-item').removeClass('selected');
				} else if (key === 'tag') {
					currentFilters.tag = 0;
					$widget.find('.hbl-v2-popular-search-btn').removeClass('active');
					$widget.find('.hbl-v2-more-filters-label').text('<?php esc_html_e( 'More Filters', 'hbl' ); ?>');
				}
				currentFilters.paged = 1;
				updateActiveFilters();
				applyFilters();
			}

			updateActiveFilters();

			// View toggle
			$widget.find('.hbl-v2-view-btn').on('click', function() {
				var view = $(this).data('view');
				$(this).addClass('active').siblings().removeClass('active');
				$widget.find('.hbl-v2-listings-grid').toggleClass('list-view', view === 'list');
			});

			// Keyword search
			var searchTimeout;
			$widget.find('.hbl-v2-keyword-search-input').on('input', function() {
				var keyword = $(this).val().trim();
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					currentFilters.keyword = keyword;
					currentFilters.paged = 1;
					$widget.find('.hbl-v2-keyword-clear').toggle(!!keyword);
					updateActiveFilters();
					applyFilters();
				}, 500);
			});
			$widget.find('.hbl-v2-keyword-clear').on('click', function() {
				$widget.find('.hbl-v2-keyword-search-input').val('');
				currentFilters.keyword = '';
				currentFilters.paged = 1;
				$(this).hide();
				updateActiveFilters();
				applyFilters();
			});

			// Letter filter
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
				updateActiveFilters();
				applyFilters();
			});

			// Sort dropdown
			$widget.find('.hbl-v2-sort-trigger').on('click', function() {
				var $dd = $widget.find('.hbl-v2-sort-dropdown');
				var open = $(this).attr('aria-expanded') === 'true';
				open ? $dd.slideUp(200) : $dd.slideDown(200);
				$(this).attr('aria-expanded', !open);
			});
			$widget.find('.hbl-v2-sort-item').on('click', function() {
				currentFilters.sort = $(this).data('value');
				currentFilters.paged = 1;
				var lbl = currentFilters.sort === 'recommended' ? '<?php esc_html_e( 'Sort By', 'hbl' ); ?>' : $(this).text().trim();
				$widget.find('.hbl-v2-sort-label').text(lbl);
				$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
				$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				$widget.find('.hbl-v2-sort-item').removeClass('selected');
				$(this).addClass('selected');
				updateActiveFilters();
				applyFilters();
			});
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-v2-sort-wrapper').length) {
					$widget.find('.hbl-v2-sort-dropdown').slideUp(200);
					$widget.find('.hbl-v2-sort-trigger').attr('aria-expanded', 'false');
				}
			});

			// Pagination
			$widget.on('click', '.hbl-v2-page-link', function(e) {
				e.preventDefault();
				currentFilters.paged = parseInt($(this).data('page')) || 1;
				applyFilters();
				$('html,body').animate({ scrollTop: $widget.offset().top - 100 }, 300);
			});

			function applyFilters() {
				$.ajax({
					url: hblV2Ajax.ajaxurl,
					type: 'POST',
					data: {
						action: 'hbl_v2_filter_listings',
						nonce: hblV2Ajax.nonce,
						widget_id: widgetId,
						widget_settings: widgetSettingsJSON,
						keyword: currentFilters.keyword,
						category: 0,
						tag: currentFilters.tag,
						letter: currentFilters.letter,
						sort: currentFilters.sort,
						paged: currentFilters.paged,
						per_page: <?php echo absint( $settings['listings_per_page'] ); ?>
					},
					beforeSend: function() { $widget.addClass('loading'); },
					success: function(response) {
						if (response.success) {
							if (response.html) {
								$widget.find('.hbl-v2-left-column').html(response.html.left);
								$widget.find('.hbl-v2-right-column').html(response.html.right);
							}
							if (response.pagination_html) {
								var $pg = $widget.find('.hbl-v2-pagination');
								if ($pg.length) $pg.replaceWith(response.pagination_html);
							}
						}
					},
					complete: function() { $widget.removeClass('loading'); }
				});
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
