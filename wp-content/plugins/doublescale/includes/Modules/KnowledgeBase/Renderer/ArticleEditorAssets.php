<?php
/**
 * WordPress's block editor on the Help Center screens of the DoubleScale
 * admin, where an article's text is written.
 *
 * The block editor's scripts are large, so they load on these screens only
 * (`admin.php?page=doublescale&path=help-center…`), never as a
 * dependency of the admin bundle: every other DoubleScale screen would pay for
 * them. The article screen reads them from `window.wp` and, reached from
 * another screen without a page load, loads the page once.
 *
 * Only staff who may write articles get them. The settings are WordPress's
 * own for the block editor (theme styles, colours, upload limits), narrowed
 * to the blocks an article needs.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;

/**
 * ArticleEditorAssets class.
 */
final class ArticleEditorAssets {

	/**
	 * Blocks an article may hold: text, media and layout, no site-building
	 * blocks (navigation, query loops, template parts, post fields).
	 */
	public const BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/pullquote',
		'core/image',
		'core/gallery',
		'core/video',
		'core/audio',
		'core/file',
		'core/embed',
		'core/media-text',
		'core/table',
		'core/code',
		'core/preformatted',
		'core/details',
		'core/separator',
		'core/spacer',
		'core/buttons',
		'core/button',
		'core/columns',
		'core/column',
		'core/group',
		'core/html',
	);

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 10, 0 );
	}

	/**
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::on_help_center_screen() || ! KbAccess::can_write() ) {
			return;
		}

		foreach ( array( 'wp-blocks', 'wp-block-editor', 'wp-block-library', 'wp-format-library', 'wp-compose', 'wp-media-utils' ) as $handle ) {
			wp_enqueue_script( $handle );
		}
		foreach ( array( 'wp-components', 'wp-block-editor', 'wp-block-library', 'wp-edit-blocks', 'wp-format-library' ) as $handle ) {
			wp_enqueue_style( $handle );
		}
		wp_enqueue_media();

		$settings = function_exists( 'get_block_editor_settings' ) && class_exists( '\WP_Block_Editor_Context' )
			? get_block_editor_settings( array(), new \WP_Block_Editor_Context( array( 'name' => 'doublescale/kb-article' ) ) )
			: array();

		$settings['allowedBlockTypes'] = self::BLOCKS;
		$settings['bodyPlaceholder']   = __( 'Write the answer step by step. Type / to add a heading, image or list.', 'doublescale' );
		$settings['titlePlaceholder']  = '';
		// Room around the text inside the writing area: the theme's own page
		// padding belongs to its layout, which the article screen does not use.
		// More above, so the toolbar of the first block stays inside the area.
		$settings['styles']   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
		$settings['styles'][] = array(
			'css'            => '.editor-styles-wrapper { padding: 56px 24px 24px; }',
			'__unstableType' => 'theme',
		);
		// Patterns are for building pages, not for answers.
		$settings['__experimentalBlockPatterns']          = array();
		$settings['__experimentalBlockPatternCategories'] = array();

		wp_add_inline_script(
			'wp-block-library',
			'window.doublescaleKbEditor = ' . wp_json_encode(
				array(
					'settings' => $settings,
				), JSON_HEX_TAG | JSON_HEX_AMP
			) . ';'
		);
	}

	/**
	 * Whether this request is a Help Center screen of the DoubleScale admin.
	 *
	 * @return bool
	 */
	private static function on_help_center_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading which admin screen is open.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$path = isset( $_GET['path'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ), '/' ) : '';
		// phpcs:enable

		$menu_slug = (string) apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		return ( 'doublescale' === $page || $menu_slug === $page )
			&& 1 === preg_match( '#^help-center(/|$)#', $path );
	}
}
