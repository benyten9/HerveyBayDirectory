<?php
/**
 * Compile JED JSON files that wp_set_script_translations() loads for React bundles.
 *
 * Loco Translate writes .po/.mo only. The admin SPA reads hashed JSON named
 * `{domain}-{locale}-{md5(script path)}.json`, so those files must be rebuilt
 * whenever a .po changes.
 *
 * @package DoubleScale
 */

namespace DoubleScale\I18n;

defined( 'ABSPATH' ) || exit;

final class JedJsonCompiler {

	public const DOMAIN = 'doublescale';

	/**
	 * Script paths registered with wp_set_script_translations() in the free plugin.
	 *
	 * @return list<string>
	 */
	public static function free_scripts(): array {
		return array(
			'build/client/index.js',
			'build/renderer/index.js',
			'build/renderer/support/index.js',
			'build/renderer/proposal/index.js',
			'build/renderer/invoice/index.js',
			'build/renderer/contract/index.js',
		);
	}

	/**
	 * Compile one locale into JED JSON files.
	 *
	 * Later PO files in $po_files win on msgid conflict (used by Pro to overlay
	 * the free catalog).
	 *
	 * @param list<string> $scripts  Relative script paths used for JSON md5 names.
	 * @param list<string> $po_files Absolute .po paths, merge order.
	 * @return int Number of translated strings written, or 0 on failure.
	 */
	public static function compile( string $locale, string $output_dir, array $scripts, array $po_files ): int {
		if ( '' === $locale || '' === $output_dir || array() === $scripts ) {
			return 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- also invoked from CLI without WP_Filesystem.
		if ( ! is_dir( $output_dir ) && ! mkdir( $output_dir, 0775, true ) && ! is_dir( $output_dir ) ) {
			return 0;
		}

		$entries = array();
		$header  = array();
		foreach ( $po_files as $po_file ) {
			if ( ! is_string( $po_file ) || ! is_readable( $po_file ) ) {
				continue;
			}
			$entries = array_merge( $entries, self::parse_po_file( $po_file ) );
			$parsed  = self::extract_po_header( $po_file );
			if ( array() !== $parsed ) {
				$header = $parsed;
			}
		}

		$messages = array();
		foreach ( $entries as $entry ) {
			if ( '' === $entry['msgid'] || '' === $entry['msgstr'] ) {
				continue;
			}
			$key = $entry['msgid'];
			if ( '' !== $entry['msgctxt'] ) {
				$key = $entry['msgctxt'] . "\x04" . $key;
			}

			if ( null !== $entry['msgid_plural'] ) {
				$messages[ $key ] = array_merge(
					array( $entry['msgid_plural'] ),
					$entry['msgstr_plural']
				);
			} else {
				$messages[ $key ] = array( $entry['msgstr'] );
			}
		}

		if ( array() === $messages ) {
			return 0;
		}

		$revision = $header['PO-Revision-Date'] ?? ( gmdate( 'Y-m-d H:i' ) . '+0000' );
		$lang     = str_replace( '_', '-', $locale );

		foreach ( $scripts as $script ) {
			if ( ! is_string( $script ) || '' === $script ) {
				continue;
			}
			$jed = array(
				'translation-revision-date' => $revision,
				'generator'                 => 'doublescale/jed-json-compiler',
				'source'                    => $script,
				'domain'                    => 'messages',
				'locale_data'               => array(
					'messages' => array_merge(
						array(
							'' => array(
								'domain' => 'messages',
								'lang'   => $lang,
							),
						),
						$messages
					),
				),
			);

			$out_file = rtrim( $output_dir, '/\\' ) . '/' . self::DOMAIN . '-' . $locale . '-' . md5( $script ) . '.json';
			$encoded  = json_encode( $jed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			if ( ! is_string( $encoded ) || false === file_put_contents( $out_file, $encoded ) ) {
				return 0;
			}
			self::make_web_writable( $out_file );
		}

		foreach ( $po_files as $po_file ) {
			if ( is_string( $po_file ) && is_file( $po_file ) ) {
				self::make_web_writable( $po_file );
				$mo_file = preg_replace( '/\.po$/', '.mo', $po_file );
				if ( is_string( $mo_file ) && is_file( $mo_file ) ) {
					self::make_web_writable( $mo_file );
				}
			}
		}

		return count( $messages );
	}

	/**
	 * Keep author catalogs writable by Apache (www-data) after CLI rewrites.
	 *
	 * Loco runs as the web user. Files rewritten here as the developer user
	 * otherwise become 0644 and Loco save fails with "Permission denied".
	 */
	public static function make_web_writable( string $path ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; failure must not abort compile.
		@chmod( $path, 0664 );
		if ( ! function_exists( 'posix_getgrnam' ) ) {
			return;
		}
		$group = posix_getgrnam( 'www-data' );
		if ( ! is_array( $group ) || ! isset( $group['gid'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; not all environments allow chgrp.
		@chgrp( $path, (int) $group['gid'] );
	}

	/**
	 * Compile the free plugin's JSON files for one locale from its author .po.
	 */
	public static function compile_free_locale( string $locale ): int {
		if ( ! defined( 'DOUBLESCALE_PLUGIN_DIR' ) ) {
			return 0;
		}
		$lang_dir = DOUBLESCALE_PLUGIN_DIR . 'languages';
		$po_file  = $lang_dir . '/' . self::DOMAIN . '-' . $locale . '.po';
		return self::compile( $locale, $lang_dir, self::free_scripts(), array( $po_file ) );
	}

	/**
	 * @return list<array{msgctxt:string,msgid:string,msgid_plural:?string,msgstr:string,msgstr_plural:array<int,string>}>
	 */
	public static function parse_po_file( string $path ): array {
		$content = file_get_contents( $path );
		if ( ! is_string( $content ) ) {
			return array();
		}
		$lines = explode( "\n", $content );

		$entries = array();
		$current = self::empty_entry();
		$state   = '';

		foreach ( $lines as $line ) {
			$line = rtrim( $line, "\r" );

			if ( '' === $line ) {
				if ( '' !== $current['msgid'] || '' !== $current['msgstr'] ) {
					$entries[] = $current;
				}
				$current = self::empty_entry();
				$state   = '';
				continue;
			}

			if ( isset( $line[0] ) && '#' === $line[0] ) {
				continue;
			}

			if ( preg_match( '/^msgctxt\s+"(.*)"$/', $line, $m ) ) {
				$current['msgctxt'] = stripcslashes( $m[1] );
				$state              = 'msgctxt';
			} elseif ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
				$current['msgid'] = stripcslashes( $m[1] );
				$state            = 'msgid';
			} elseif ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
				$current['msgid_plural'] = stripcslashes( $m[1] );
				$state                   = 'msgid_plural';
			} elseif ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
				$current['msgstr'] = stripcslashes( $m[1] );
				$state             = 'msgstr';
			} elseif ( preg_match( '/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m ) ) {
				$current['msgstr_plural'][ (int) $m[1] ] = stripcslashes( $m[2] );
				$state                                   = 'msgstr_plural_' . $m[1];
			} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$value = stripcslashes( $m[1] );
				if ( 'msgctxt' === $state ) {
					$current['msgctxt'] .= $value;
				} elseif ( 'msgid' === $state ) {
					$current['msgid'] .= $value;
				} elseif ( 'msgid_plural' === $state ) {
					$current['msgid_plural'] .= $value;
				} elseif ( 'msgstr' === $state ) {
					$current['msgstr'] .= $value;
				} elseif ( 0 === strpos( $state, 'msgstr_plural_' ) ) {
					$idx                                = (int) substr( $state, 14 );
					$current['msgstr_plural'][ $idx ] = ( $current['msgstr_plural'][ $idx ] ?? '' ) . $value;
				}
			}
		}

		if ( '' !== $current['msgid'] || '' !== $current['msgstr'] ) {
			$entries[] = $current;
		}

		return $entries;
	}

	/**
	 * @return array<string, string>
	 */
	public static function extract_po_header( string $path ): array {
		$content = file_get_contents( $path );
		if ( ! is_string( $content ) ) {
			return array();
		}
		$headers = array();

		if ( preg_match( '/^msgid ""\nmsgstr "(.*?)"\n\n/ms', $content, $m ) ) {
			$raw = stripcslashes( $m[1] );
			foreach ( explode( "\n", $raw ) as $line ) {
				if ( false !== strpos( $line, ':' ) ) {
					list( $key, $val ) = explode( ':', $line, 2 );
					$headers[ trim( $key ) ] = trim( str_replace( '\n', '', $val ) );
				}
			}
		}

		return $headers;
	}

	/**
	 * @return array{msgctxt:string,msgid:string,msgid_plural:?string,msgstr:string,msgstr_plural:array<int,string>}
	 */
	private static function empty_entry(): array {
		return array(
			'msgctxt'       => '',
			'msgid'         => '',
			'msgid_plural'  => null,
			'msgstr'        => '',
			'msgstr_plural' => array(),
		);
	}
}
