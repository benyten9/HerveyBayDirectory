<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Pro section.
 *
 * @package SEOPress PRO
 * @subpackage Sections
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Print section info pro.
 *
 * @param string $key The key of the section.
 * @return void
 */
function seopress_print_pro_section( $key ) {
	seopress_pro_get_service( 'RenderSectionPro' )->render( $key );
}
