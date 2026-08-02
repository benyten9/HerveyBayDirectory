<?php
/**
 * HBL Directorist V2 AJAX Handlers
 * 
 * Handles dynamic filtering for the V2 widget
 *
 * @package HBL
 * @since 2.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Handler for V2 Widget Filtering
 */
function hbl_directorist_v2_filter_listings() {
	// Verify nonce
	check_ajax_referer( 'hbl_v2_filter_nonce', 'nonce' );
	
	// Get filter parameters
	$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( $_POST['keyword'] ) : '';
	$category = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
	$tag = isset( $_POST['tag'] ) ? absint( $_POST['tag'] ) : 0;
	$letter = isset( $_POST['letter'] ) ? strtoupper( sanitize_text_field( $_POST['letter'] ) ) : '';
	$sort = isset( $_POST['sort'] ) ? sanitize_text_field( $_POST['sort'] ) : 'recommended';
	$paged = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
	$widget_id = isset( $_POST['widget_id'] ) ? sanitize_text_field( $_POST['widget_id'] ) : '';
	$per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10;
	
	// Get widget settings early so we can apply Elementor-configured filters
	$widget_settings = isset( $_POST['widget_settings'] ) ? json_decode( stripslashes( $_POST['widget_settings'] ), true ) : array();
	
	// Build query args
	$args = array(
		'post_type' => 'at_biz_dir',
		'post_status' => 'publish',
		'posts_per_page' => $per_page,
		'paged' => $paged,
	);

	// Disable object cache for AJAX filter queries so posts_where changes are always
	// reflected — without this, WP caches results keyed only on $args, meaning
	// letter=A and letter='' share the same cache entry and clearing the filter
	// returns stale filtered results.
	$args['cache_results']          = false;
	$args['update_post_meta_cache'] = false;
	$args['update_post_term_cache'] = false;

	// Add keyword search
	if ( ! empty( $keyword ) ) {
		$args['s'] = $keyword;
	}
	
	// Initialize tax_query
	$args['tax_query'] = array();
	
	// Apply Elementor widget's configured category restriction (base filter)
	if ( isset( $widget_settings['category_filter_type'] ) && 'specific' === $widget_settings['category_filter_type'] ) {
		if ( ! empty( $widget_settings['category'] ) ) {
			$widget_categories = is_array( $widget_settings['category'] ) ? $widget_settings['category'] : array( $widget_settings['category'] );
			$widget_categories = array_map( 'absint', $widget_categories );
			$widget_categories = array_filter( $widget_categories );
			
			if ( ! empty( $widget_categories ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'at_biz_dir-category',
					'field'            => 'term_id',
					'terms'            => $widget_categories,
					'include_children' => true,
				);
			}
		}
	}
	
	// Apply Elementor widget's configured location restriction (base filter)
	if ( isset( $widget_settings['location_filter_type'] ) && 'specific' === $widget_settings['location_filter_type'] ) {
		if ( ! empty( $widget_settings['location'] ) ) {
			$widget_locations = is_array( $widget_settings['location'] ) ? $widget_settings['location'] : array( $widget_settings['location'] );
			$widget_locations = array_map( 'absint', $widget_locations );
			$widget_locations = array_filter( $widget_locations );
			
			if ( ! empty( $widget_locations ) ) {
				$args['tax_query'][] = array(
					'taxonomy'         => 'at_biz_dir-location',
					'field'            => 'term_id',
					'terms'            => $widget_locations,
					'include_children' => true,
				);
			}
		}
	}
	
	// Add user-selected category filter (from front-end dropdown)
	if ( $category > 0 ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'at_biz_dir-category',
			'field' => 'term_id',
			'terms' => $category,
		);
	}

	// Add tag filter
	if ( $tag > 0 ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'at_biz_dir-tags',
			'field' => 'term_id',
			'terms' => $tag,
		);
	}
	
	if ( count( $args['tax_query'] ) > 1 ) {
		$args['tax_query']['relation'] = 'AND';
	}
	
	// Add alphabetical filter
	if ( ! empty( $letter ) ) {
		$hbl_letter_filter_cb = function( $where ) use ( $letter ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $letter . '%' );
			return $where;
		};
		add_filter( 'posts_where', $hbl_letter_filter_cb );
	}
	
	// Add sorting
	switch ( $sort ) {
		case 'a-z':
			$args['orderby'] = 'title';
			$args['order'] = 'ASC';
			break;
		case 'z-a':
			$args['orderby'] = 'title';
			$args['order'] = 'DESC';
			break;
		case 'newest':
			$args['orderby'] = 'date';
			$args['order'] = 'DESC';
			break;
		default:
			// Recommended - featured first, then by date
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key' => '_featured',
					'compare' => 'EXISTS',
				),
				array(
					'key' => '_featured',
					'compare' => 'NOT EXISTS',
				),
			);
			$args['orderby'] = array(
				'meta_value' => 'DESC',
				'date' => 'DESC',
			);
			break;
	}
	
	// Execute query
	$query = new WP_Query( $args );

	// Remove the letter filter immediately after query so it cannot bleed into
	// any subsequent queries within the same request.
	if ( ! empty( $letter ) ) {
		remove_filter( 'posts_where', $hbl_letter_filter_cb );
	}
	
	// Prepare response
	$response = array(
		'success' => true,
		'listings' => array(),
		'pagination' => array(
			'current_page' => $paged,
			'total_pages' => $query->max_num_pages,
			'total_items' => $query->found_posts,
			'per_page' => $per_page,
		),
		'active_filters' => array(),
	);
	
	// Build active filters array
	if ( ! empty( $keyword ) ) {
		$response['active_filters'][] = array(
			'type' => 'keyword',
			'label' => $keyword,
			'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
		);
	}
	
	if ( $category > 0 ) {
		$term = get_term( $category, 'at_biz_dir-category' );
		if ( $term && ! is_wp_error( $term ) ) {
			$response['active_filters'][] = array(
				'type' => 'category',
				'label' => $term->name,
				'value' => $category,
				'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6H20M4 12H20M4 18H20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
			);
		}
	}
	
	if ( $tag > 0 ) {
		$term = get_term( $tag, 'at_biz_dir-tags' );
		if ( $term && ! is_wp_error( $term ) ) {
			$response['active_filters'][] = array(
				'type' => 'tag',
				'label' => $term->name,
				'value' => $tag,
				'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>',
			);
		}
	}
	
	if ( ! empty( $letter ) ) {
		$response['active_filters'][] = array(
			'type' => 'letter',
			'label' => $letter,
			'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 7V17M4 7L12 2L20 7V17L12 22L4 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		);
	}
	
	if ( $sort !== 'recommended' ) {
		$sort_labels = array(
			'a-z' => 'A–Z',
			'z-a' => 'Z–A',
			'newest' => 'Newest',
		);
		$response['active_filters'][] = array(
			'type' => 'sort',
			'label' => isset( $sort_labels[ $sort ] ) ? $sort_labels[ $sort ] : $sort,
			'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 6H21M6 12H18M9 18H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		);
	}

	
	// Get listings HTML
	if ( $query->have_posts() ) {
		// Separate into columns
		$listings = array();
		while ( $query->have_posts() ) {
			$query->the_post();
			$listings[] = get_the_ID();
		}
		wp_reset_postdata();
		
		$half = ceil( count( $listings ) / 2 );
		$left_column = array_slice( $listings, 0, $half );
		$right_column = array_slice( $listings, $half );
		
		
		
		// Render left column HTML
		ob_start();
		foreach ( $left_column as $listing_id ) {
			global $post;
			$post = get_post( $listing_id );
			setup_postdata( $post );
			
			// Render actual card HTML
			hbl_v2_render_listing_card( $listing_id, $widget_settings );
		}
		$left_html = ob_get_clean();
		
		// Render right column HTML
		ob_start();
		foreach ( $right_column as $listing_id ) {
			global $post;
			$post = get_post( $listing_id );
			setup_postdata( $post );
			
			// Render actual card HTML
			hbl_v2_render_listing_card( $listing_id, $widget_settings );
		}
		$right_html = ob_get_clean();
		
		wp_reset_postdata();
		
		$response['html'] = array(
			'left' => $left_html,
			'right' => $right_html,
		);
	} else {
		$response['html'] = array(
			'left' => '<div class="hbl-v2-no-results"><i class="bi bi-search"></i><p>' . esc_html__( 'No listings found.', 'hbl' ) . '</p></div>',
			'right' => '',
		);
	}
	
	// Always generate pagination HTML so the count updates after every filter.
	ob_start();
	if ( $query->found_posts > 0 ) {
		$start_item = ( ( $paged - 1 ) * $per_page ) + 1;
		$end_item   = min( $paged * $per_page, $query->found_posts );
		?>
		<div class="hbl-v2-pagination">
			<div class="hbl-v2-pagination-info">
				<?php
				printf(
					esc_html__( 'Showing %1$d - %2$d of %3$d listings', 'hbl' ),
					$start_item,
					$end_item,
					$query->found_posts
				);
				?>
			</div>
			<?php if ( $query->max_num_pages > 1 ) : ?>
			<div class="hbl-v2-pagination-controls">
				<?php if ( $paged > 1 ) : ?>
					<a href="#" data-page="<?php echo esc_attr( $paged - 1 ); ?>" class="hbl-v2-page-btn hbl-v2-prev-btn hbl-v2-page-link">
						<i class="bi bi-chevron-left"></i>
						<span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
					</a>
				<?php else : ?>
					<span class="hbl-v2-page-btn hbl-v2-prev-btn disabled">
						<i class="bi bi-chevron-left"></i>
						<span><?php esc_html_e( 'Previous', 'hbl' ); ?></span>
					</span>
				<?php endif; ?>

				<div class="hbl-v2-page-numbers">
					<?php
					$range     = 2;
					$show_dots = false;
					for ( $i = 1; $i <= $query->max_num_pages; $i++ ) :
						if ( $i === 1 || $i === $query->max_num_pages || ( $i >= $paged - $range && $i <= $paged + $range ) ) :
							$is_current = ( $i === $paged );
					?>
						<a href="#" data-page="<?php echo esc_attr( $i ); ?>"
						   class="hbl-v2-page-number hbl-v2-page-link <?php echo $is_current ? 'active' : ''; ?>">
							<?php echo esc_html( $i ); ?>
						</a>
					<?php
						$show_dots = true;
						elseif ( $show_dots ) :
					?>
						<span class="hbl-v2-page-dots">...</span>
					<?php
						$show_dots = false;
						endif;
					endfor;
					?>
				</div>

				<?php if ( $paged < $query->max_num_pages ) : ?>
					<a href="#" data-page="<?php echo esc_attr( $paged + 1 ); ?>" class="hbl-v2-page-btn hbl-v2-next-btn hbl-v2-page-link">
						<span><?php esc_html_e( 'Next', 'hbl' ); ?></span>
						<i class="bi bi-chevron-right"></i>
					</a>
				<?php else : ?>
					<span class="hbl-v2-page-btn hbl-v2-next-btn disabled">
						<span><?php esc_html_e( 'Next', 'hbl' ); ?></span>
						<i class="bi bi-chevron-right"></i>
					</span>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
	$response['pagination_html'] = ob_get_clean();
	
	wp_send_json( $response );
}
add_action( 'wp_ajax_hbl_v2_filter_listings', 'hbl_directorist_v2_filter_listings' );
add_action( 'wp_ajax_nopriv_hbl_v2_filter_listings', 'hbl_directorist_v2_filter_listings' );

/**
 * Helper function to render a listing card
 * This is a standalone version of the widget's render_listing_card method
 */
function hbl_v2_render_listing_card( $listing_id, $settings = array() ) {
	// Load the card renderer trait if not already loaded
	static $trait_loaded = false;
	if ( ! $trait_loaded ) {
		require_once get_template_directory() . '/inc/widgets/v2/traits/trait-card-renderer.php';
		require_once get_template_directory() . '/inc/widgets/v2/traits/trait-query-handler.php';
		$trait_loaded = true;
	}
	
	// Create a temporary class to use the trait
	static $renderer = null;
	if ( null === $renderer ) {
		$renderer = new class {
			use \HBL\Widgets\V2\Traits\Card_Renderer;
			use \HBL\Widgets\V2\Traits\Query_Handler;
			
			public function render_card( $listing_id, $settings ) {
				$this->render_listing_card( $listing_id, $settings );
			}
		};
	}
	
	$renderer->render_card( $listing_id, $settings );
}

/**
 * Enqueue V2 Widget Scripts
 */
function hbl_directorist_v2_enqueue_scripts() {
	// Only enqueue on pages with the widget
	if ( ! is_admin() ) {
		wp_localize_script( 'hbl-script', 'hblV2Ajax', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'hbl_v2_filter_nonce' ),
		) );
	}
}
add_action( 'wp_enqueue_scripts', 'hbl_directorist_v2_enqueue_scripts', 20 );
