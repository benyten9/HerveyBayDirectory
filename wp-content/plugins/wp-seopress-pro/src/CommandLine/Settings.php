<?php

namespace SEOPressPro\CommandLine;

use WP_CLI_Command;
use WP_CLI;

class Settings extends WP_CLI_Command {

	/**
	 * Name the export is written under when only a directory is given.
	 */
	const FILENAME = 'seopress-settings.json';

	/**
	 * This command import the SEOPress settings from a JSON file.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<value>]
	 * : Path to the file, or to the directory holding it. Defaults to the
	 * current directory, falling back to the uploads directory where earlier
	 * versions of this command wrote their exports.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress settings import
	 *     wp seopress settings import --from=/home/user
	 *     wp seopress settings import --from=/home/user/staging-settings.json
	 */
	public function import( $args, $assoc_args ) {
		if ( is_null( seopress_get_service( 'ImportSettings' ) ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$from = isset( $assoc_args['from'] ) ? $assoc_args['from'] : '';

		if ( '' !== $from ) {
			$from = $this->resolve_path( $from );

			if ( ! file_exists( $from ) ) {
				WP_CLI::error( sprintf( 'The file %s does not exist.', $from ) );
			}
		} else {
			$from = $this->locate_export();

			if ( '' === $from ) {
				WP_CLI::error( sprintf( 'No %s found in the current directory. Pass --from to say where the file is.', self::FILENAME ) );
			}

			WP_CLI::line( sprintf( 'Reading %s', $from ) );
		}

		WP_CLI::line( 'Importing SEOPress settings...' );

		$contents = $this->filesystem()->get_contents( $from );

		if ( false === $contents ) {
			WP_CLI::error( sprintf( 'The file %s could not be read.', $from ) );
		}

		$data = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			WP_CLI::error( sprintf( 'The file %s is not a valid JSON file.', $from ) );
		}

		seopress_get_service( 'ImportSettings' )->handle( $data );

		WP_CLI::success( 'SEOPress settings imported.' );
	}

	/**
	 * This command export the SEOPress settings to a JSON file.
	 *
	 * The file holds your license key and every API key SEOPress stores. Write
	 * it somewhere your web server does not serve, and use --exclude to leave
	 * the secrets out of a configuration meant to be reused across sites.
	 *
	 * ## OPTIONS
	 *
	 * [--destination=<value>]
	 * : Path to the file, or to the directory to write it in. Defaults to the
	 * current directory. Public destinations are refused before writing.
	 *
	 * [--exclude=<categories>]
	 * : Comma-separated list of site-specific categories to leave out. Run
	 * without a value to list what is available.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seopress settings export
	 *     wp seopress settings export --destination=/home/user/
	 *     wp seopress settings export --exclude=license,api_keys
	 */
	public function export( $args, $assoc_args ) {
		$service = seopress_get_service( 'ExportSettings' );

		if ( is_null( $service ) ) {
			WP_CLI::line( 'SEOPress is not up to date. Please update SEOPress to the latest version.' );
			return;
		}

		$exclude     = $this->parse_exclude( $service, $assoc_args );
		$settings    = $service->handle( $exclude );
		$destination = $this->resolve_destination( $assoc_args );
		if ( seopress_import_path_is_public( dirname( $destination ) ) || is_link( $destination ) ) {
			WP_CLI::error( 'Choose an export destination outside the web root and uploads directory. Symbolic links are not accepted.' );
		}

		/*
		 * 0600 rather than the usual file mode: on shared hosting every other
		 * account on the machine can read a world-readable file, and this one
		 * carries the license key and the API keys.
		 */
		$filesystem = $this->filesystem();
		if ( file_exists( $destination ) && ! $filesystem->chmod( $destination, 0600 ) ) {
			WP_CLI::error( 'The export file permissions could not be secured.' );
		}
		$mask = umask( 0077 );
		try {
			$written = $filesystem->put_contents( $destination, wp_json_encode( $settings ), 0600 );
		} finally {
			umask( $mask );
		}
		if ( ! $written ) {
			WP_CLI::error( sprintf( 'The file %s could not be written.', $destination ) );
		}

		WP_CLI::line( sprintf( 'The file has been created: %s', $destination ) );

		$this->warn_about_secrets( $exclude );
		$this->warn_if_served( $destination );
	}

	/**
	 * The filesystem abstraction, or a fatal error when it cannot run.
	 *
	 * @return \WP_Filesystem_Base
	 */
	private function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem instanceof \WP_Filesystem_Direct ) {
			WP_CLI::error( 'WordPress cannot access the filesystem directly on this install.' );
		}

		return $wp_filesystem;
	}

	/**
	 * Turn a path given on the command line into an absolute file path.
	 *
	 * A path ending in .json names the file. Anything else names a directory,
	 * which is how this command has always been documented.
	 *
	 * @param string $path Path as it was typed.
	 *
	 * @return string
	 */
	private function resolve_path( $path ) {
		$path = wp_normalize_path( (string) $path );

		if ( '.json' === strtolower( substr( $path, -5 ) ) ) {
			return $path;
		}

		return trailingslashit( $path ) . self::FILENAME;
	}

	/**
	 * Where an export goes when --destination is not given.
	 *
	 * The uploads directory used to be the default, which left a file holding
	 * the license key and every API key at
	 * `https://example.com/wp-content/uploads/seopress-settings.json`: a fixed,
	 * guessable URL, served to anybody who asked for it, and never cleaned up.
	 * The current directory is what the rest of WP-CLI does with an export.
	 *
	 * @param array $assoc_args Command arguments.
	 *
	 * @return string
	 */
	private function resolve_destination( $assoc_args ) {
		$destination = isset( $assoc_args['destination'] ) ? $assoc_args['destination'] : '';

		if ( '' === $destination ) {
			$destination = getcwd();

			if ( false === $destination ) {
				$destination = ABSPATH;
			}
		}

		return $this->resolve_path( $destination );
	}

	/**
	 * Find the file an import should read when --from is not given.
	 *
	 * The uploads directory is still looked at, second, so a site that scripted
	 * the old default keeps working while it is being moved over.
	 *
	 * @return string Empty when there is nothing to read.
	 */
	private function locate_export() {
		$candidates = array();

		$cwd = getcwd();

		if ( false !== $cwd ) {
			$candidates[] = trailingslashit( wp_normalize_path( $cwd ) ) . self::FILENAME;
		}

		$upload = wp_upload_dir();

		if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
			$candidates[] = trailingslashit( wp_normalize_path( $upload['basedir'] ) ) . self::FILENAME;
		}

		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Read --exclude and check it against what the free plugin knows about.
	 *
	 * @param object $service    The ExportSettings service.
	 * @param array  $assoc_args Command arguments.
	 *
	 * @return array Category keys to strip from the export.
	 */
	private function parse_exclude( $service, $assoc_args ) {
		if ( ! method_exists( $service, 'getSiteSpecificCategories' ) ) {
			if ( ! empty( $assoc_args['exclude'] ) ) {
				WP_CLI::error( 'This version of SEOPress does not support --exclude. Please update SEOPress to the latest version.' );
			}

			return array();
		}

		$available = array_keys( $service->getSiteSpecificCategories() );

		if ( ! isset( $assoc_args['exclude'] ) ) {
			return array();
		}

		// `--exclude` on its own arrives as true, and is the shortest way to
		// ask what the categories are called.
		if ( true === $assoc_args['exclude'] || '' === $assoc_args['exclude'] ) {
			WP_CLI::error( sprintf( '--exclude needs a value. Available categories: %s.', implode( ', ', $available ) ) );
		}

		$requested = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['exclude'] ) ) );
		$unknown   = array_diff( $requested, $available );

		if ( ! empty( $unknown ) ) {
			WP_CLI::error(
				sprintf(
					'Unknown category: %1$s. Available categories: %2$s.',
					implode( ', ', $unknown ),
					implode( ', ', $available )
				)
			);
		}

		return array_values( $requested );
	}

	/**
	 * Say what secrets went into the file, and how to leave them out.
	 *
	 * @param array $exclude Categories that were stripped.
	 *
	 * @return void
	 */
	private function warn_about_secrets( $exclude ) {
		$secrets = array(
			'license'  => 'your license key',
			'api_keys' => 'your API keys',
		);

		$included = array_diff_key( $secrets, array_flip( $exclude ) );

		if ( empty( $included ) ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				'This file contains %1$s. Pass --exclude=%2$s to leave them out.',
				implode( ' and ', $included ),
				implode( ',', array_keys( $included ) )
			)
		);
	}

	/**
	 * Warn when the file landed somewhere the web server will hand out.
	 *
	 * @param string $destination Absolute path to the file.
	 *
	 * @return void
	 */
	private function warn_if_served( $destination ) {
		$root = realpath( ABSPATH );
		$dir  = realpath( dirname( $destination ) );

		if ( false === $root || false === $dir ) {
			return;
		}

		$root = trailingslashit( wp_normalize_path( $root ) );
		$dir  = trailingslashit( wp_normalize_path( $dir ) );

		if ( 0 !== strpos( $dir, $root ) ) {
			return;
		}

		$relative = substr( $dir, strlen( $root ) ) . basename( $destination );

		WP_CLI::warning(
			sprintf(
				'This file is inside your site, so your web server will hand it to anyone who asks for %s. Move it outside your web root, or delete it once you are done.',
				site_url( '/' . $relative )
			)
		);
	}
}
