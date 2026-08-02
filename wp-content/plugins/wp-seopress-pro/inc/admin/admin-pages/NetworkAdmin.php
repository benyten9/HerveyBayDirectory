<?php //phpcs:ignore
/**
 * SEOPress PRO Network Admin page.
 *
 * @package SEOPress PRO
 * @subpackage Admin_Pages
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( is_plugin_active( 'wp-seopress/seopress.php' ) ) {
	if ( function_exists( 'seopress_admin_header' ) ) {
		echo seopress_admin_header();
	}
	?>
	<div class="seopress-option seopress-php-header">
		<h1><?php esc_html_e( 'SEO Network settings', 'wp-seopress-pro' ); ?></h1>
	</div>
	<div id="seopress-admin-settings-root" class="seopress-option">
		<?php // React app renders here. ?>
	</div>
	<?php
}
