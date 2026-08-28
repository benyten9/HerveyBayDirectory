<?php
/**
 * Pro overlay for JED JSON used by the Pro admin/renderer bundles.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\I18n;

use DoubleScale\I18n\JedJsonCompiler as FreeJedJsonCompiler;

defined( 'ABSPATH' ) || exit;

final class JedJsonCompiler {

	/**
	 * Script paths registered with wp_set_script_translations() in Pro.
	 *
	 * @return list<string>
	 */
	public static function scripts(): array {
		return array(
			'build/client/index.js',
			'build/renderer/index.js',
			'build/renderer/credit-note/index.js',
			'build/renderer/project/index.js',
		);
	}

	/**
	 * Compile Pro JSON as the union of free + Pro .po files (Pro wins).
	 */
	public static function compile_locale( string $locale ): int {
		if ( ! defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			return 0;
		}

		$lang_dir = DOUBLESCALE_PRO_PLUGIN_DIR . 'languages';
		$po_files = array();

		$free_po = self::free_po_path( $locale );
		if ( is_string( $free_po ) && is_readable( $free_po ) ) {
			$po_files[] = $free_po;
		}

		$pro_po     = $lang_dir . '/' . FreeJedJsonCompiler::DOMAIN . '-' . $locale . '.po';
		$po_files[] = $pro_po;

		return FreeJedJsonCompiler::compile(
			$locale,
			$lang_dir,
			self::scripts(),
			$po_files
		);
	}

	private static function free_po_path( string $locale ): ?string {
		$basename = FreeJedJsonCompiler::DOMAIN . '-' . $locale . '.po';
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$candidate = DOUBLESCALE_PLUGIN_DIR . 'languages/' . $basename;
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		$sibling = dirname( DOUBLESCALE_PRO_PLUGIN_DIR ) . '/doublescale/languages/' . $basename;
		return is_readable( $sibling ) ? $sibling : null;
	}
}
