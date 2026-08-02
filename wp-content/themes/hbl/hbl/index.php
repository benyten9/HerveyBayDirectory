<?php
/**
 * The main template file
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

// Check if Elementor has a custom template
$is_elementor_theme_exist = function_exists( 'elementor_theme_do_location' );
$location = '';
$is_event_category_archive = is_tax( 'event_category' );

// Determine which Elementor location to check
if ( is_singular() ) {
	$location = 'single';
} elseif ( is_archive() || $is_event_category_archive ) {
	$location = 'archive';
} elseif ( is_search() ) {
	$location = 'archive'; // Elementor uses 'archive' for search results
} elseif ( is_404() ) {
	$location = '404';
}

// Check if Elementor is handling this template
if ( $is_elementor_theme_exist && ! empty( $location ) && elementor_theme_do_location( $location ) ) {
	// Elementor is handling the template - do nothing, Elementor outputs everything
} else {
	// No Elementor template found, use default theme templates
	
	if ( is_singular() ) {
		// Single post/page
		get_template_part( 'template-parts/single' );
	} elseif ( is_search() ) {
		// Search results
		get_template_part( 'template-parts/search-results' );
	} elseif ( $is_event_category_archive ) {
		// Event Category Archive - special handling for custom event categories
		get_template_part( 'template-parts/event-category-archive' );
	} elseif ( is_404() ) {
		// 404 page
		get_template_part( 'template-parts/404' );
	} elseif ( is_archive() || is_home() ) {
		// Archive or blog page
		get_template_part( 'template-parts/archive' );
	} else {
		// Fallback - show default blog layout
		?>
		<main id="primary" class="site-main container py-5">
			<div class="row">
				<div class="col-lg-8">
					<?php
					if ( have_posts() ) :

						if ( is_home() && ! is_front_page() ) :
							?>
							<header class="page-header mb-4">
								<h1 class="page-title"><?php single_post_title(); ?></h1>
							</header>
							<?php
						endif;

						// Start the Loop
						while ( have_posts() ) :
							the_post();
							get_template_part( 'template-parts/content', get_post_type() );
						endwhile;

						// Pagination
						the_posts_pagination( array(
							'mid_size'  => 2,
							'prev_text' => sprintf(
								'<i class="bi bi-arrow-left"></i> <span class="screen-reader-text">%s</span>',
								__( 'Previous', 'hbl' )
							),
							'next_text' => sprintf(
								'<span class="screen-reader-text">%s</span> <i class="bi bi-arrow-right"></i>',
								__( 'Next', 'hbl' )
							),
							'class'     => 'pagination justify-content-center mt-5',
						) );

					else :

						get_template_part( 'template-parts/content', 'none' );

					endif;
					?>
				</div>

				<?php get_sidebar(); ?>
			</div>
		</main>
		<?php
	}
}

get_footer();
