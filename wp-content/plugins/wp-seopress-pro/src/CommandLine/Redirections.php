<?php
/**
 * SEOPress PRO redirections WP-CLI commands.
 *
 * @package SEOPress PRO
 * @subpackage CommandLine
 */

namespace SEOPressPro\CommandLine;

use WP_CLI;
use WP_CLI_Command;

/**
 * Import redirections in bulk.
 *
 * The admin screen is limited by what a browser will upload, and a migration
 * often arrives as a file far larger than that. This is the same importer,
 * driven from the shell against a file already on the server.
 */
class Redirections extends WP_CLI_Command {

	/**
	 * Import redirections from a file.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to the file to import. Omit it with --resume.
	 *
	 * [--resume]
	 * : Continue the import that was interrupted, instead of starting a new one.
	 *
	 * [--source=<source>]
	 * : Which export the file comes from. Defaults to seopress for a .csv and
	 * fails for a .json, where the format cannot be guessed.
	 * ---
	 * options:
	 *   - csv
	 *   - redirections_plugin
	 *   - yoast
	 *   - rankmath
	 *   - rankmath_csv
	 *   - slimseo
	 *   - aioseo
	 *   - smartcrawl
	 * ---
	 *
	 * [--separator=<separator>]
	 * : CSV separator. Detected from the file when omitted.
	 * ---
	 * default: auto
	 * options:
	 *   - auto
	 *   - comma
	 *   - semicolon
	 *   - tab
	 *   - pipe
	 * ---
	 *
	 * [--batch=<size>]
	 * : Redirections written per slice. Lower it on a database under load.
	 *
	 * [--dry-run]
	 * : Parse the file and report what would be imported, without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro redirections import redirects.csv
	 *     wp seopress-pro redirections import rankmath.json --source=rankmath
	 *     wp seopress-pro redirections import big.csv --separator=semicolon --batch=1000
	 *     wp seopress-pro redirections import redirects.csv --dry-run
	 *     wp seopress-pro redirections import --resume
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$importer = seopress_pro_get_service( 'RedirectionsImport' );

		if ( ! $importer ) {
			WP_CLI::error( 'The redirections importer is unavailable. Check that SEOPress PRO is fully installed.' );
		}

		if ( ! empty( $assoc_args['resume'] ) ) {
			$job = $importer->get_job();

			if ( ! $job ) {
				WP_CLI::error( 'There is no interrupted import to resume.' );
			}

			WP_CLI::log(
				sprintf(
					'Resuming: %1$d of %2$d redirection(s) already written.',
					$job['imported'],
					$job['total']
				)
			);

			$this->drain( $importer, $job );

			return;
		}

		$file = isset( $args[0] ) ? $args[0] : '';

		if ( ! $file || ! is_readable( $file ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		$source  = isset( $assoc_args['source'] ) ? $assoc_args['source'] : $this->guess_source( $file );
		$sources = $importer->get_sources();

		if ( ! isset( $sources[ $source ] ) ) {
			WP_CLI::error( sprintf( 'Unknown source "%1$s". Known sources: %2$s.', $source, implode( ', ', array_keys( $sources ) ) ) );
		}

		if ( ! empty( $assoc_args['batch'] ) ) {
			$batch = max( 1, (int) $assoc_args['batch'] );
			add_filter(
				'seopress_redirections_import_batch_size',
				function () use ( $batch ) {
					return $batch;
				}
			);
		}

		$options = array( 'separator' => isset( $assoc_args['separator'] ) ? $assoc_args['separator'] : 'auto' );

		WP_CLI::log( sprintf( 'Reading %1$s as %2$s...', $file, $source ) );

		$job = $importer->prepare( $file, $source, $options );

		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}

		WP_CLI::log(
			sprintf(
				'%1$d redirection(s) ready, %2$d row(s) skipped, %3$d duplicate(s) dropped.',
				$job['total'],
				$job['skipped'],
				$job['duplicate']
			)
		);

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$result = $importer->cancel();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success( sprintf( 'Dry run: %d redirection(s) would be imported.', $job['total'] ) );

			return;
		}

		$this->drain( $importer, $job );
	}

	/**
	 * Run a prepared job to completion, slice by slice.
	 *
	 * A slice that fails leaves the job intact - the offset has not moved and
	 * nothing was half-written - so the command says how to pick it back up
	 * rather than throwing away what has already landed.
	 *
	 * @param \SEOPressPro\Services\RedirectionsImport $importer The importer.
	 * @param array                                    $job      Job descriptor.
	 *
	 * @return void
	 */
	protected function drain( $importer, $job ) {
		$bar = \WP_CLI\Utils\make_progress_bar( 'Importing', (int) $job['total'] );

		$done  = (int) $job['processed'];
		$state = null;

		$bar->tick( $done );

		do {
			$state = $importer->run( $job['id'] );

			if ( is_wp_error( $state ) ) {
				$bar->finish();
				WP_CLI::warning( $state->get_error_message() );
				WP_CLI::error( 'Import interrupted. Run it again with --resume to continue from where it stopped.' );
			}

			$bar->tick( $state['processed'] - $done );
			$done = $state['processed'];
		} while ( empty( $state['done'] ) );

		$bar->finish();

		if ( ! empty( $state['degraded'] ) ) {
			WP_CLI::warning(
				sprintf(
					'%d redirection(s) used a matching rule that is not supported and were imported as exact matches.',
					$state['degraded']
				)
			);
		}

		if ( ! empty( $state['resumed'] ) ) {
			WP_CLI::log( sprintf( '%d redirection(s) were already written by an earlier attempt.', $state['resumed'] ) );
		}

		WP_CLI::success(
			sprintf(
				'%1$d redirection(s) imported, %2$d skipped, %3$d duplicate(s) dropped.',
				$state['imported'],
				$state['skipped'],
				$state['duplicate']
			)
		);
	}

	/**
	 * List the export formats the importer understands.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress-pro redirections sources
	 *
	 * @return void
	 */
	public function sources() {
		$importer = seopress_pro_get_service( 'RedirectionsImport' );

		if ( ! $importer ) {
			WP_CLI::error( 'The redirections importer is unavailable. Check that SEOPress PRO is fully installed.' );
		}

		$rows = array();

		foreach ( $importer->get_sources() as $key => $source ) {
			$rows[] = array(
				'source'     => $key,
				'label'      => $source['label'],
				'extensions' => implode( ', ', $source['extensions'] ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'label', 'extensions' ) );
	}

	/**
	 * Guess the source from the file extension.
	 *
	 * Only a CSV can be guessed: it is SEOPress's own format unless told
	 * otherwise. Four different plugins export JSON, and reading one as another
	 * produces a silent, wrong import rather than an error.
	 *
	 * @param string $file Path to the file.
	 *
	 * @return string
	 */
	protected function guess_source( $file ) {
		$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			return 'csv';
		}

		WP_CLI::error( 'Pass --source: a JSON export cannot be identified on its own. Run "wp seopress-pro redirections sources" for the list.' );
	}
}
