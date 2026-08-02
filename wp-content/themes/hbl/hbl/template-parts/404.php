<?php
/**
 * The template for displaying 404 pages (not found).
 *
 * @package HBL
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<main id="content" class="site-main">

	<?php if ( apply_filters( 'hbl_page_title', hbl_get_setting( 'hbl_page_title' ) ) ) : ?>
		<div class="page-header">
			<h1 class="entry-title"><?php echo esc_html__( 'The page can&rsquo;t be found.', 'hbl' ); ?></h1>
		</div>
	<?php endif; ?>

	<div class="page-content">
		<p><?php echo esc_html__( 'It looks like nothing was found at this location.', 'hbl' ); ?></p>
	</div>

</main>

