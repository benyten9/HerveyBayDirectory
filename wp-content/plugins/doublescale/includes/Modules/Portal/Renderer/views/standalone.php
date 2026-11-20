<?php
/**
 * A full-screen client portal page: the page's content on its own, without
 * the theme's header, footer or sidebars ({@see PortalPageLayout}).
 *
 * WordPress's head and footer still run, so the portal's scripts, the login
 * form's styles and any plugin that relies on them keep working.
 *
 * @package DoubleScale\Modules\Portal
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
		<title><?php echo esc_html( wp_get_document_title() ); ?></title>
	<?php endif; ?>
	<?php wp_head(); ?>
	<style id="doublescale-client-portal-standalone">
		html, body.doublescale-client-portal-standalone { margin: 0 !important; padding: 0 !important; min-height: 100%; background: #f7f8fa !important; }
		body.doublescale-client-portal-standalone .doublescale-client-portal-standalone-main { min-height: 100vh; box-sizing: border-box; }
		body.doublescale-client-portal-standalone #doublescale-client-portal { min-height: 100vh; }
		/* Signed out: the split login screen (brand panel + form), styled with the
		   login form's own stylesheet; the shell fills the window. */
		body.doublescale-client-portal-standalone .doublescale-client-portal-shell.doublescale-client-portal-shell--login { box-sizing: border-box !important; }
	</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="doublescale-client-portal-standalone-main">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</main>
<?php wp_footer(); ?>
</body>
</html>
