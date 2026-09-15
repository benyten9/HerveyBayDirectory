<?php
/**
 * Normalize uploaded CSV bytes to UTF-8.
 *
 * Excel on Arabic Windows saves "CSV (Comma delimited)" as Windows-1256.
 * Reading those bytes as UTF-8 makes MySQL replace each letter with "?".
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Contacts\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * CSV charset helper.
 */
final class CsvEncoding {

	private const UTF8_BOM     = "\xEF\xBB\xBF";
	private const UTF16LE_BOM  = "\xFF\xFE";
	private const UTF16BE_BOM  = "\xFE\xFF";

	/**
	 * Convert CSV file bytes to UTF-8 and strip a leading BOM.
	 *
	 * @param string $bytes Raw file contents.
	 * @return string UTF-8 bytes.
	 */
	public static function to_utf8( $bytes ) {
		$bytes = (string) $bytes;
		if ( '' === $bytes ) {
			return '';
		}

		$encoding = self::detect( $bytes );
		$bytes    = self::strip_bom( $bytes, $encoding );

		if ( 'UTF-8' === $encoding ) {
			return $bytes;
		}

		$converted = self::convert( $bytes, $encoding );
		return false === $converted ? $bytes : $converted;
	}

	/**
	 * Rewrite a CSV on disk as UTF-8 when the original encoding differs.
	 *
	 * @param string $file_path Absolute path.
	 */
	public static function ensure_file_is_utf8( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- binary read; WP_Filesystem may not be binary-safe across transports.
		$bytes = file_get_contents( $file_path );
		if ( false === $bytes ) {
			return;
		}

		$utf8 = self::to_utf8( $bytes );
		if ( $utf8 === $bytes ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- matching Security.php; binary rewrite of the import working file.
		file_put_contents( $file_path, $utf8 );
	}

	/**
	 * @param string $bytes Raw file contents.
	 * @return string Encoding label understood by iconv/mbstring.
	 */
	private static function detect( $bytes ) {
		if ( 0 === strpos( $bytes, self::UTF8_BOM ) ) {
			return 'UTF-8';
		}
		if ( 0 === strpos( $bytes, self::UTF16LE_BOM ) ) {
			return 'UTF-16LE';
		}
		if ( 0 === strpos( $bytes, self::UTF16BE_BOM ) ) {
			return 'UTF-16BE';
		}
		if ( self::is_utf8( $bytes ) ) {
			return 'UTF-8';
		}
		if ( self::looks_like_utf16le( $bytes ) ) {
			return 'UTF-16LE';
		}
		if ( self::looks_like_utf16be( $bytes ) ) {
			return 'UTF-16BE';
		}

		$as_1256 = self::convert( $bytes, 'Windows-1256' );
		if ( is_string( $as_1256 ) && self::has_arabic_run( $as_1256 ) ) {
			return 'Windows-1256';
		}

		$as_iso = self::convert( $bytes, 'ISO-8859-6' );
		if ( is_string( $as_iso ) && self::has_arabic_run( $as_iso ) ) {
			return 'ISO-8859-6';
		}

		return 'Windows-1252';
	}

	/**
	 * @param string $bytes    Raw bytes.
	 * @param string $encoding Detected encoding.
	 * @return string
	 */
	private static function strip_bom( $bytes, $encoding ) {
		if ( 'UTF-8' === $encoding && 0 === strpos( $bytes, self::UTF8_BOM ) ) {
			return substr( $bytes, 3 );
		}
		if ( 'UTF-16LE' === $encoding && 0 === strpos( $bytes, self::UTF16LE_BOM ) ) {
			return substr( $bytes, 2 );
		}
		if ( 'UTF-16BE' === $encoding && 0 === strpos( $bytes, self::UTF16BE_BOM ) ) {
			return substr( $bytes, 2 );
		}
		return $bytes;
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @return bool
	 */
	private static function is_utf8( $bytes ) {
		if ( function_exists( 'mb_check_encoding' ) ) {
			return mb_check_encoding( $bytes, 'UTF-8' );
		}
		return 1 === preg_match( '/./us', $bytes );
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @return bool
	 */
	private static function looks_like_utf16le( $bytes ) {
		return self::nul_ratio( $bytes, 1 ) >= 0.4;
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @return bool
	 */
	private static function looks_like_utf16be( $bytes ) {
		return self::nul_ratio( $bytes, 0 ) >= 0.4;
	}

	/**
	 * @param string $bytes  Raw bytes.
	 * @param int    $offset 0 for high byte (BE), 1 for low byte (LE).
	 * @return float
	 */
	private static function nul_ratio( $bytes, $offset ) {
		$length = strlen( $bytes );
		if ( $length < 6 ) {
			return 0.0;
		}
		$sample = min( $length, 400 );
		if ( 0 !== $sample % 2 ) {
			--$sample;
		}
		$nuls  = 0;
		$pairs = 0;
		for ( $i = $offset; $i < $sample; $i += 2 ) {
			++$pairs;
			if ( "\0" === $bytes[ $i ] ) {
				++$nuls;
			}
		}
		return $pairs > 0 ? $nuls / $pairs : 0.0;
	}

	/**
	 * @param string $text UTF-8 text.
	 * @return bool
	 */
	private static function has_arabic_run( $text ) {
		return 1 === preg_match( '/\p{Arabic}{2,}/u', $text );
	}

	/**
	 * @param string $bytes Raw bytes.
	 * @param string $from  Source encoding.
	 * @return string|false
	 */
	private static function convert( $bytes, $from ) {
		if ( function_exists( 'iconv' ) ) {
			$converted = @iconv( $from, 'UTF-8', $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv warns on illegal sequences; a false return is handled by the caller.
			if ( false !== $converted ) {
				return $converted;
			}
		}

		if ( function_exists( 'mb_convert_encoding' ) && function_exists( 'mb_list_encodings' ) ) {
			$known = array_map( 'strtolower', mb_list_encodings() );
			if ( in_array( strtolower( $from ), $known, true ) ) {
				$converted = mb_convert_encoding( $bytes, 'UTF-8', $from );
				if ( is_string( $converted ) && '' !== $converted ) {
					return $converted;
				}
			}
		}

		return false;
	}
}
