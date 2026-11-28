<?php
/**
 * Exact native template source authority for private builder drafts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

/** Keeps source identity distinct from the current theme's private draft target. */
final class GutenbergTemplateSource {

	/**
	 * Read an editable current-theme source or an integration-proven plugin source.
	 *
	 * @param string $ref Exact native template reference.
	 * @return \WP_Block_Template|null Authorized native source.
	 */
	public static function read( string $ref ): ?\WP_Block_Template {
		if ( ! current_user_can( 'edit_theme_options' ) || ! function_exists( 'get_block_template' ) || ! wp_is_block_theme() || ! preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $ref, $parts ) ) {
			return null;
		}
		$template = get_block_template( $parts[2] . '//' . $parts[3], $parts[1] );
		if ( ! $template instanceof \WP_Block_Template || $template->id !== $parts[2] . '//' . $parts[3] || $template->theme !== $parts[2] || $template->slug !== $parts[3] || $template->type !== $parts[1] ) {
			return null;
		}
		if ( get_stylesheet() === $parts[2] ) {
			return $template;
		}
		// Only the installed integration can prove its own native fallback source.
		// This grants source reads/private copies, never assignment or publication.
		return true === apply_filters( 'directorist_ai_pro_gutenberg_plugin_template_source', false, $template, $ref ) ? $template : null;
	}
}
