<?php
/**
 * Rebuild Pro React JSON translations when Loco Translate saves a .po file.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\I18n;

use DoubleScale\I18n\LocoJsonSync as FreeLocoJsonSync;

defined( 'ABSPATH' ) || exit;

final class LocoJsonSync {

	/**
	 * Handle Loco's loco_file_written action.
	 *
	 * Pro's admin bundle JSON is the union of free + Pro catalogs, so a save in
	 * either plugin's languages/ directory must rebuild Pro JSON.
	 *
	 * @param mixed $path Absolute path Loco just wrote.
	 */
	public static function on_file_written( $path ): void {
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		$locale = FreeLocoJsonSync::locale_for_written_file( $path, self::watched_directories() );
		if ( null === $locale ) {
			return;
		}

		if ( function_exists( 'apply_filters' )
			&& ! apply_filters( 'doublescale_pro_compile_js_translations_on_loco_save', true, $locale, $path ) ) {
			return;
		}

		try {
			JedJsonCompiler::compile_locale( $locale );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- save must not fail because JSON compile did.
			error_log( 'DoubleScale Pro JS translation compile failed: ' . $e->getMessage() );
		}
	}

	/**
	 * @return list<string>
	 */
	public static function watched_directories(): array {
		$dirs = array();
		if ( defined( 'DOUBLESCALE_PRO_PLUGIN_DIR' ) ) {
			$dirs[] = DOUBLESCALE_PRO_PLUGIN_DIR . 'languages';
		}
		if ( defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			$dirs[] = DOUBLESCALE_PLUGIN_DIR . 'languages';
		}
		return $dirs;
	}
}
