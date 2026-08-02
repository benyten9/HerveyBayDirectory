<?php
/**
 * The template for displaying 404 pages (not found).
 *
 * This file allows Elementor Theme Builder's 404 template to work properly.
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

get_header();

// Check if Elementor Theme Builder handles this 404 page
$elementor_handled = false;

if ( function_exists( 'elementor_theme_do_location' ) ) {
	// Elementor Pro uses 'single' location with 404 condition for error pages
	$elementor_handled = elementor_theme_do_location( 'single' );
}

// If Elementor didn't handle it, show the default theme 404 content
if ( ! $elementor_handled ) {
	?>
	<main id="content" class="site-main">
		<div class="page-content">
			<?php get_template_part( 'template-parts/404' ); ?>
		</div>
	</main>
	<?php
}

get_footer();

