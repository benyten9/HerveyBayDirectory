<?php
/**
 * Rebuild React JSON translations when Loco Translate saves a .po file.
 *
 * @package DoubleScale
 */

namespace DoubleScale\I18n;

defined( 'ABSPATH' ) || exit;

final class LocoJsonSync {

	/**
	 * Handle Loco's loco_file_written action.
	 *
	 * @param mixed $path Absolute path Loco just wrote.
	 */
	public static function on_file_written( $path ): void {
		if ( ! is_string( $path ) || '' === $path ) {
			return;
		}

		$locale = self::locale_for_written_file( $path, self::watched_directories() );
		if ( null === $locale ) {
			return;
		}

		if ( function_exists( 'apply_filters' )
			&& ! apply_filters( 'doublescale_compile_js_translations_on_loco_save', true, $locale, $path ) ) {
			return;
		}

		try {
			JedJsonCompiler::compile_free_locale( $locale );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- save must not fail because JSON compile did.
			error_log( 'DoubleScale JS translation compile failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Directories whose doublescale-*.po saves should rebuild free JSON.
	 *
	 * @return list<string>
	 */
	public static function watched_directories(): array {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return array();
		}
		return array( DOUBLESCALE_PLUGIN_DIR . 'languages' );
	}

	/**
	 * @param list<string> $watch_dirs
	 */
	public static function locale_for_written_file( string $path, array $watch_dirs ): ?string {
		$normalized = self::normalize_path( $path );
		if ( ! preg_match( '/\/' . preg_quote( JedJsonCompiler::DOMAIN, '/' ) . '-([A-Za-z0-9_]+)\.po$/', $normalized, $m ) ) {
			return null;
		}

		foreach ( $watch_dirs as $dir ) {
			if ( ! is_string( $dir ) || '' === $dir ) {
				continue;
			}
			if ( self::is_under_directory( $normalized, $dir ) ) {
				return $m[1];
			}
		}

		return null;
	}

	private static function is_under_directory( string $normalized_file, string $directory ): bool {
		$real_file = realpath( $normalized_file );
		$real_dir  = realpath( $directory );
		$file      = $real_file ? self::normalize_path( $real_file ) : $normalized_file;
		$dir       = $real_dir ? self::normalize_path( $real_dir ) : self::normalize_path( $directory );
		$dir       = rtrim( $dir, '/' );
		if ( '' === $dir ) {
			return false;
		}
		return 0 === strpos( $file, $dir . '/' );
	}

	private static function normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}
