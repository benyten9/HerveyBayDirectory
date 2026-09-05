<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO CSV helpers.
 *
 * Shared by the redirections importer and the metadata importer.
 *
 * @package SEOPress PRO
 * @subpackage Functions
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

if ( ! function_exists( 'seopress_get_csv_sample_lines' ) ) {
	/**
	 * Read the first non-empty lines of a CSV file.
	 *
	 * Streams the file instead of loading it whole: the metadata importer is
	 * batched precisely because those files can be hundreds of megabytes.
	 *
	 * @param string $file_path path to the CSV file
	 * @param int    $limit     maximum number of lines to return
	 *
	 * @return array list of raw lines, without their trailing line break
	 */
	function seopress_get_csv_sample_lines( $file_path, $limit = 5 ) {
		if ( empty( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}

		$handle = fopen( $file_path, 'r' );

		if ( false === $handle ) {
			return array();
		}

		$lines = array();

		while ( count( $lines ) < $limit ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			$line = rtrim( $line, "\r\n" );

			if ( '' === trim( $line ) ) {
				continue;
			}

			$lines[] = $line;
		}

		fclose( $handle );

		return $lines;
	}
}

if ( ! function_exists( 'seopress_detect_csv_separator' ) ) {
	/**
	 * Auto-detect the CSV separator by analyzing the first lines of the file.
	 *
	 * @param string $file_path path to the CSV file
	 * @param string $escape    escape character, so the detection parses the
	 *                          sample exactly the way the caller parses the file
	 *
	 * @return string detected separator character (',' or ';')
	 */
	function seopress_detect_csv_separator( $file_path, $escape = '\\' ) {
		$candidates = array(
			',' => 0,
			';' => 0,
		);

		$lines = seopress_get_csv_sample_lines( $file_path );

		if ( empty( $lines ) ) {
			return ',';
		}

		foreach ( $lines as $line ) {
			foreach ( $candidates as $sep => &$score ) {
				$cols = str_getcsv( $line, $sep, '"', $escape );
				if ( count( $cols ) >= 3 ) {
					++$score;
				}
			}
			unset( $score );
		}

		// A two column file never reaches the three column threshold, and a
		// metadata export of just an ID and a title is a legitimate case.
		// Fall back to whichever candidate actually shows up in the sample.
		if ( 0 === $candidates[';'] && 0 === $candidates[','] ) {
			$occurrences = array(
				',' => 0,
				';' => 0,
			);

			foreach ( $lines as $line ) {
				$occurrences[','] += substr_count( $line, ',' );
				$occurrences[';'] += substr_count( $line, ';' );
			}

			if ( $occurrences[','] !== $occurrences[';'] ) {
				return $occurrences[';'] > $occurrences[','] ? ';' : ',';
			}
		}

		return $candidates[';'] >= $candidates[','] ? ';' : ',';
	}
}
