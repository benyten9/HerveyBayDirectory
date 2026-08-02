<?php
/**
 * Template Name: HBL Account Dashboard
 * Template Post Type: page
 *
 * Full-screen, bookmarkable version of the HBL Dashboard widget. Renders the
 * exact same widget markup as the Elementor "HBL Dashboard" widget (same
 * classes, same CSS, same JS behaviour in theme.js) so the two never drift
 * apart visually, but as a real page instead of an embedded widget - so
 * browser back/forward and direct links work like any other page.
 *
 * Deliberately skips get_header()/get_footer() (the site's Elementor header
 * and footer) so the dashboard takes over the whole screen, the same way
 * Elementor's own "Canvas" page template works.
 *
 * @package HBL
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// This page is per-user and highly dynamic (listings, events, favorites,
// claims) - it must never be served from a page cache (e.g. LiteSpeed
// Cache), or deleted/updated data can appear to "come back" after a
// refresh because a stale cached copy of the HTML is served instead of a
// fresh render. DONOTCACHEPAGE is the standard constant honored by
// LiteSpeed Cache and virtually every other WP caching plugin.
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}
nocache_headers();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'hbl-dashboard-fullscreen' ); ?>>
<?php wp_body_open(); ?>

<main id="hbl-dashboard-page" class="hbl-dashboard-page">
	<div class="hbl-dashboard-page-container">
		<?php hbl_render_dashboard_widget(); ?>
	</div>
</main>

<?php wp_footer(); ?>
</body>
</html>
