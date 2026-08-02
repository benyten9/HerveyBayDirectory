<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Robots.txt.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( seopress_pro_get_service( 'OptionPro' )->getRobotsTxtEnable() === '1' ) {
	/**
	 * Filter robots.txt.
	 *
	 * @param string $output The output.
	 * @param bool   $public The public.
	 *
	 * @return string $seopress_robots
	 */
	function seopress_filter_robots_txt( $output, $public ) {
		$seopress_robots = seopress_pro_get_service( 'OptionPro' )->getRobotsTxtFile();
		$seopress_robots = apply_filters( 'seopress_robots_txt_file', $seopress_robots );
		return $seopress_robots;
	}
	add_filter( 'robots_txt', 'seopress_filter_robots_txt', 10, 2 );
}
