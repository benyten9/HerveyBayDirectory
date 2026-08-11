<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
