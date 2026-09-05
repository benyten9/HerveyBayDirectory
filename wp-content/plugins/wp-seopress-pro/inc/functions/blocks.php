<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Blocks.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * This file contains block registration as well as dynamic callbacks for custom editor blocks.
 */

add_action( 'init', 'seopress_pro_register_blocks', 100 );
/**
 * Register editor blocks.
 */
function seopress_pro_register_blocks() {
	// Register Local Business block.
	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/inc/functions/blocks/local-business/block.php';
	register_block_type( SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/local-business/' );
	register_block_type(
		SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/local-business-field/',
		array(
			'render_callback' => 'seopress_pro_local_business_field_block',
		)
	);
	wp_set_script_translations( 'wpseopress/local-business', 'wp-seopress-pro' );
	wp_set_script_translations( 'wpseopress/local-business-field', 'wp-seopress-pro' );

	// Register Breadcrumbs block.
	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/inc/functions/blocks/breadcrumbs/block.php';
	register_block_type(
		SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/breadcrumbs/',
		array(
			'render_callback' => 'seopress_pro_breadcrumb_block',
			'attributes'      => array(
				'inlineStyles' => array(
					'type'    => 'string',
					'default' => function_exists( 'seopress_breadcrumbs_inline_css' ) ? seopress_breadcrumbs_inline_css( '', false ) : '',
				),
				'homeOption'   => array(
					'type'    => 'string',
					'default' => ! empty( seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHome() ) ? seopress_pro_get_service( 'OptionPro' )->getBreadcrumbsI18nHome() : __( 'Home', 'wp-seopress-pro' ),
				),
			),
		)
	);
	wp_set_script_translations( 'wpseopress/breadcrumbs', 'wp-seopress-pro' );

	// Register How-to block.
	register_block_type( SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/how-to/' );
	register_block_type( SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/how-to-step/' );
	wp_set_script_translations( 'wpseopress/how-to', 'wp-seopress-pro' );
	wp_set_script_translations( 'wpseopress/how-to-step', 'wp-seopress-pro' );

	// Register Table of Contents block.
	require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . '/inc/functions/blocks/table-of-contents/block.php';
	$toc_block = new SEOPRESS_PRO_Table_of_Contents_Block();
	$toc_block->register_hooks();
	register_block_type(
		SEOPRESS_PRO_PUBLIC_PATH . '/editor/blocks/table-of-contents/',
		array(
			'render_callback' => array( $toc_block, 'render' ),
		)
	);
	wp_set_script_translations( 'wpseopress/table-of-contents', 'wp-seopress-pro' );
}


add_action( 'current_screen', 'seopress_pro_unregister_blocks', 100 );
/**
 * Unregister blocks depending on context.
 */
function seopress_pro_unregister_blocks() {
	$screen = get_current_screen();

	if ( is_admin() && isset( $screen->base ) && 'widgets' === $screen->base ) {
		unregister_block_type( 'wpseopress/how-to' );
		unregister_block_type( 'wpseopress/how-to-step' );
	}
}

add_filter( 'render_block', 'seopress_pro_how_to_block_render_schema', 10, 2 );
/**
 * Re-encode the How-to block JSON-LD at render time.
 *
 * The block writes its schema into the saved markup as a string child of the
 * script element, and the block serializer escapes a string child as HTML: an
 * angle bracket is stored as `&lt;`, a bare ampersand as `&amp;`. The step
 * titles and texts are RichText values on top of that, so they arrive carrying
 * entities of their own.
 *
 * Google unescapes the body of a JSON-LD script element exactly once and asks
 * publishers to move to standard JSON escapes, so the stored payload is
 * replaced by one built from the block attributes, where every ampersand,
 * angle bracket and quote is a JSON unicode escape.
 *
 * Rebuilding at render rather than changing save.js is what fixes the posts
 * already in the database: nothing is rewritten until an author happens to edit
 * one, and the stored markup keeps matching what the editor expects.
 *
 * @since 10.2.0
 *
 * @param string $block_content The rendered block markup.
 * @param array  $block         The parsed block.
 *
 * @return string HTML.
 */
function seopress_pro_how_to_block_render_schema( $block_content, $block ) {
	if ( ! isset( $block['blockName'] ) || 'wpseopress/how-to' !== $block['blockName'] ) {
		return $block_content;
	}

	if ( empty( $block['attrs']['schema'] ) || ! is_array( $block['attrs']['schema'] ) ) {
		return $block_content;
	}

	$json = seopress_pro_json_ld_encode( $block['attrs']['schema'] );

	if ( false === $json ) {
		return $block_content;
	}

	// preg_replace_callback rather than preg_replace: the JSON is full of
	// backslashes, which the replacement string of preg_replace would read as
	// escapes of its own.
	$rendered = preg_replace_callback(
		'#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>.*?</script\s*>#is',
		function () use ( $json ) {
			return '<script type="application/ld+json">' . $json . '</script>';
		},
		$block_content,
		1
	);

	return null === $rendered ? $block_content : $rendered;
}
