<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$is_elementor_theme_exist = function_exists( 'elementor_theme_do_location' );
$location = '';
$is_event_category_archive = is_tax( 'event_category' );

if ( is_singular() ) {
	$location = 'single';
} elseif ( is_archive() || $is_event_category_archive ) {
	$location = 'archive';
} elseif ( is_search() ) {
	$location = 'archive';
} elseif ( is_404() ) {
	$location = '404';
}

if ( $is_elementor_theme_exist && ! empty( $location ) && elementor_theme_do_location( $location ) ) {
} else {
	
	if ( is_singular() ) {
		get_template_part( 'template-parts/single' );
	} elseif ( is_search() ) {
		get_template_part( 'template-parts/search-results' );
	} elseif ( $is_event_category_archive ) {
		get_template_part( 'template-parts/event-category-archive' );
	} elseif ( is_404() ) {
		get_template_part( 'template-parts/404' );
	} elseif ( is_archive() || is_home() ) {
		get_template_part( 'template-parts/archive' );
	} else {
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

						while ( have_posts() ) :
							the_post();
							get_template_part( 'template-parts/content', get_post_type() );
						endwhile;

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
