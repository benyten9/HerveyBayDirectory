<?php
/**
 * Detect the field delimiter of an uploaded CSV.
 *
 * Excel "Unicode Text" / "Tab delimited" exports use tabs, and Excel on
 * European locales writes semicolons. Parsing those with a comma turns the
 * whole header into a single column, so nothing can be mapped.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Contacts\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * CSV delimiter helper.
 */
final class CsvDelimiter {

	/**
	 * Default delimiter, and the one that wins every tie.
	 */
	public const DEFAULT = ',';

	/**
	 * Candidates in priority order: comma is listed first so that a file
	 * parsed equally well with two delimiters keeps today's behaviour.
	 */
	private const CANDIDATES = array( ',', ';', "\t", '|' );

	/**
	 * Pick the delimiter that occurs most often in the header, outside quotes.
	 *
	 * Only the header is inspected: it is the line least likely to contain
	 * a stray delimiter inside a value. Characters inside double quotes are
	 * ignored, so a quoted cell such as "Tags; more; tabs" cannot promote
	 * the semicolon. A candidate other than the comma is chosen only when
	 * it occurs strictly more often, so every CSV that imported correctly
	 * before keeps parsing exactly as it did.
	 *
	 * @param string $header_line First line of the UTF-8 CSV (with or without a trailing newline).
	 * @return string One-character delimiter.
	 */
	public static function detect( $header_line ) {
		$header_line = rtrim( (string) $header_line, "\r\n" );
		if ( '' === $header_line ) {
			return self::DEFAULT;
		}

		$counts = self::count_outside_quotes( $header_line );

		$best       = self::DEFAULT;
		$best_count = 0;

		foreach ( self::CANDIDATES as $delimiter ) {
			$count = $counts[ $delimiter ];
			if ( $count > $best_count ) {
				$best       = $delimiter;
				$best_count = $count;
			}
		}

		return $best;
	}

	/**
	 * Detect the delimiter from the first line of a UTF-8 CSV on disk.
	 *
	 * @param string $file_path Absolute path to a file already normalised by {@see CsvEncoding::ensure_file_is_utf8()}.
	 * @return string One-character delimiter; comma when the file cannot be read.
	 */
	public static function detect_from_file( $file_path ) {
		if ( ! is_readable( $file_path ) ) {
			return self::DEFAULT;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read a single line without loading a large upload into memory.
		$handle = fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			return self::DEFAULT;
		}

		$line = fgets( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		if ( false === $line ) {
			return self::DEFAULT;
		}

		return self::detect( $line );
	}

	/**
	 * Count each candidate delimiter outside double-quoted sections.
	 *
	 * A doubled quote ("") inside a quoted cell is the RFC 4180 escape and
	 * keeps the quoted state. If the quotes turn out to be unbalanced the
	 * line is malformed for any delimiter; fall back to raw counts so the
	 * most frequent separator still wins.
	 *
	 * @param string $line Single CSV line.
	 * @return array<string, int> Delimiter => occurrences.
	 */
	private static function count_outside_quotes( $line ) {
		$counts = array_fill_keys( self::CANDIDATES, 0 );
		$raw    = array_fill_keys( self::CANDIDATES, 0 );
		$quoted = false;
		$length = strlen( $line );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $line[ $i ];

			if ( '"' === $char ) {
				if ( $quoted && $i + 1 < $length && '"' === $line[ $i + 1 ] ) {
					$i++; // Escaped quote inside a quoted cell.
					continue;
				}
				$quoted = ! $quoted;
				continue;
			}

			if ( isset( $raw[ $char ] ) ) {
				$raw[ $char ]++;
				if ( ! $quoted ) {
					$counts[ $char ]++;
				}
			}
		}

		return $quoted ? $raw : $counts;
	}
}
