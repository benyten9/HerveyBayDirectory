<?php
/**
 * SEOPress PRO Breadcrumbs block.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Breadcrumbs Block display callback
 *
 * @param   array    $attributes  Block attributes.
 * @param   string   $content     Inner block content.
 * @param   WP_Block $block       Actual block.
 * @return  string    $html
 */
function seopress_pro_breadcrumb_block( $attributes, $content, $block ) {
	$html = '';
	if ( '1' == seopress_get_toggle_option( 'breadcrumbs' ) ) {
		if ( '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsEnable() || '1' === seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsJsonEnable() ) {
			$attr = get_block_wrapper_attributes();
			$html = sprintf( '<div %s>%s</div>', $attr, seopress_display_breadcrumbs( false ) );
		}
	}
	return apply_filters( 'seopress_pro_breadcrumb_block_html', $html, $attributes, $content, $block );
}
