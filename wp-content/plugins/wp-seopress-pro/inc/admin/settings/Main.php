<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Main section.
 *
 * @package SEOPress PRO
 * @subpackage Settings
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

register_setting(
	'seopress_pro_mu_option_group', // Option group.
	'seopress_pro_mu_option_name', // Option name.
	array( $this, 'sanitize' ) // Sanitize.
);
register_setting(
	'seopress_pro_option_group', // Option group.
	'seopress_pro_option_name', // Option name.
	array( $this, 'sanitize' ) // Sanitize.
);

register_setting(
	'seopress_pro_option_group', // Option group.
	'seopress_instant_indexing_option_name', // Option name.
	array( $this, 'sanitize' ) // Sanitize.
);

register_setting(
	'seopress_license', // Option group.
	'seopress_pro_license_key', // Option name.
	'seopress_sanitize_license' // Sanitize.
);
