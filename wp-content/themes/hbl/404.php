<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$elementor_handled = false;

if ( function_exists( 'elementor_theme_do_location' ) ) {
	$elementor_handled = elementor_theme_do_location( 'single' );
}

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

