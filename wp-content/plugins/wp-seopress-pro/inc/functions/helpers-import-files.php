<?php
/**
 * Private storage and cleanup for import working files.
 *
 * @package SEOPress PRO
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Name of the directory holding files being imported.
 *
 * @since 10.3.0
 *
 * @return string
 */
function seopress_import_files_dirname() {
	return 'seopress-imports';
}

/**
 * How long an import file is kept before the cleanup removes it.
 *
 * @since 10.3.0
 *
 * @return int Seconds.
 */
function seopress_import_files_lifetime() {
	/**
	 * Filter how long an abandoned import file stays on the server.
	 *
	 * A file is deleted as soon as the import it belongs to finishes, so this
	 * only applies to a wizard that was left part way through.
	 *
	 * @since 10.3.0
	 *
	 * @param int $lifetime Seconds. Default one day.
	 */
	$lifetime = (int) apply_filters( 'seopress_import_file_lifetime', DAY_IN_SECONDS );

	return $lifetime > 0 ? $lifetime : DAY_IN_SECONDS;
}

/**
 * The filesystem abstraction, or null when it cannot run unattended.
 *
 * Only the direct transport works here: every other one wants credentials this
 * code has no way of asking for, and there is no screen to ask them on during a
 * cron run.
 *
 * @since 10.3.0
 *
 * @return WP_Filesystem_Base|null
 */
function seopress_import_files_filesystem() {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( ! WP_Filesystem() ) {
		return null;
	}

	return $wp_filesystem instanceof WP_Filesystem_Direct ? $wp_filesystem : null;
}

/**
 * Whether a resolved path is inside a known public directory.
 *
 * Includes custom uploads and document roots, not only the WordPress root.
 * Server aliases outside these roots must be excluded by the host.
 *
 * @since 10.3.0
 * @param string     $path Existing absolute path.
 * @param array|null $upload Upload roots supplied by an active upload_dir filter.
 * @return bool
 */
function seopress_import_path_is_public( $path, $upload = null ) {
	$real = realpath( $path );
	if ( false === $real ) {
		return true;
	}
	$upload = null === $upload ? wp_upload_dir() : $upload;
	$roots  = array( ABSPATH, WP_CONTENT_DIR );
	if ( ! empty( $upload['basedir'] ) ) {
		$roots[] = $upload['basedir'];
	}
	if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Server-supplied filesystem path, resolved with realpath below; sanitizing can change the directory.
		$roots[] = $_SERVER['DOCUMENT_ROOT'];
	}
	$real = trailingslashit( wp_normalize_path( $real ) );
	foreach ( $roots as $root ) {
		$root = realpath( $root );
		if ( false !== $root && 0 === strpos( $real, trailingslashit( wp_normalize_path( $root ) ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Private per-site storage shared by metadata and redirection imports.
 *
 * Refuse public or inaccessible storage before any upload is moved. A random
 * filename and server-specific deny files cannot protect a known public URL.
 *
 * Defaults to the system temporary directory. Define SEOPRESS_IMPORT_DIR in
 * wp-config.php when that does not fit the host (open_basedir, short-lived
 * temporary storage, several application nodes): an existing, writable
 * directory outside the web root, shared by every PHP worker that processes an
 * import, and never exposed through a server alias or a CDN, which cannot be
 * detected from here. There is no fallback to the public uploads directory.
 *
 * @since 10.3.0
 * @param string     $name Subdirectory name.
 * @param bool       $create Whether to create the directory.
 * @param array|null $upload Upload roots supplied by an active upload_dir filter.
 * @return string|WP_Error
 */
function seopress_private_import_dir( $name, $create = true, $upload = null ) {
	$base = defined( 'SEOPRESS_IMPORT_DIR' ) ? SEOPRESS_IMPORT_DIR : sys_get_temp_dir();
	$base = realpath( $base );
	if ( false === $base || seopress_import_path_is_public( $base, $upload ) ) {
		return new WP_Error( 'seopress_import_private_storage', __( 'Configure SEOPRESS_IMPORT_DIR to a writable directory outside the web root.', 'wp-seopress-pro' ) );
	}
	$site = hash( 'sha256', ABSPATH . '|' . get_current_blog_id() );
	$path = trailingslashit( wp_normalize_path( $base ) ) . 'seopress-' . $site . '-' . $name;
	if ( is_link( $path ) ) {
		return new WP_Error( 'seopress_import_private_storage', __( 'The private import directory must not be a symbolic link.', 'wp-seopress-pro' ) );
	}
	if ( ! $create ) {
		return $path;
	}
	$filesystem = seopress_import_files_filesystem();
	if ( null === $filesystem || ( ! is_dir( $path ) && ! $filesystem->mkdir( $path, 0700 ) ) || ! $filesystem->chmod( $path, 0700 ) || ! $filesystem->is_writable( $path ) || seopress_import_path_is_public( $path, $upload ) ) {
		return new WP_Error( 'seopress_import_private_storage', __( 'The private import directory could not be secured.', 'wp-seopress-pro' ) );
	}
	return $path;
}

/**
 * Private metadata import directory. There is deliberately no public URL.
 *
 * @since 10.3.0
 * @param bool       $create Whether to create the directory.
 * @param array|null $upload Upload roots supplied by an active upload_dir filter.
 * @return array|WP_Error
 */
function seopress_import_files_dir( $create = true, $upload = null ) {
	$path = seopress_private_import_dir( seopress_import_files_dirname(), $create, $upload );
	return is_wp_error( $path ) ? $path : array(
		'path' => $path,
		'url'  => '',
	);
}

/**
 * Name to store an uploaded import under.
 *
 * Random names prevent collisions between concurrent imports. Confidentiality
 * comes from private storage, including when the exact filename is known.
 *
 * wp_unique_filename() returns whatever a `unique_filename_callback` hands it,
 * without sanitizing it, so what comes out of here has to be a safe file name
 * on its own. The extension is the only part that comes from the request, and
 * it is rejected unless it is plain alphanumerics. It has already been through
 * wp_check_filetype_and_ext() by this point; deciding which extensions may be
 * uploaded stays that check's job, not this one's.
 *
 * @since 10.3.0
 *
 * @param string $dir  Directory the file is going into. Unused, part of the
 *                     `unique_filename_callback` signature.
 * @param string $name File name as it was uploaded. Unused, deliberately: the
 *                     name people give their exports is exactly what made the
 *                     old URL guessable.
 * @param string $ext  Extension, leading dot included.
 *
 * @return string
 */
function seopress_import_file_name( $dir = '', $name = '', $ext = '.csv' ) {
	$ext = ltrim( (string) $ext, '.' );

	if ( ! preg_match( '/^[A-Za-z0-9]{1,10}$/', $ext ) ) {
		$ext = 'csv';
	}

	try {
		$random = bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		// random_bytes() only throws when the platform has no source of
		// randomness at all. Falling back keeps the import working on a host
		// that broken, with a name that is still not derived from the upload.
		$random = md5( wp_generate_password( 32, false, false ) . microtime( true ) );
	}

	return 'seopress-import-' . $random . '.' . $ext;
}

/**
 * Whether a path is a file this plugin put in the import directory.
 *
 * Resolved with realpath() before being compared, so neither a symlink nor a
 * `..` segment can point the deletion at something else. The name is checked
 * too: a file somebody else put in the directory is none of our business.
 *
 * @since 10.3.0
 *
 * @param string $file Path to check.
 *
 * @return bool
 */
function seopress_is_import_file( $file ) {
	if ( ! is_string( $file ) || '' === $file ) {
		return false;
	}

	$real = realpath( $file );

	if ( false === $real || ! is_file( $real ) ) {
		return false;
	}

	$real = wp_normalize_path( $real );

	if ( 0 !== strpos( basename( $real ), 'seopress-import-' ) ) {
		return false;
	}

	$dir = seopress_import_files_dir( false );

	if ( is_wp_error( $dir ) ) {
		return false;
	}

	$upload = wp_upload_dir();
	$bases  = array( $dir['path'] );
	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$bases[] = trailingslashit( $upload['basedir'] ) . seopress_import_files_dirname();
	}
	foreach ( $bases as $base ) {
		$base = realpath( $base );
		if ( false !== $base && wp_normalize_path( dirname( $real ) ) === wp_normalize_path( $base ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Delete a file the importer uploaded.
 *
 * Anything that is not ours is left alone, which is what keeps the wizard's
 * other entry point safe: somebody importing a file they uploaded to the media
 * library themselves gets to keep it.
 *
 * @since 10.3.0
 *
 * @param string $file Path to the file.
 *
 * @return bool Whether the file was deleted.
 */
function seopress_delete_import_file( $file ) {
	if ( ! seopress_is_import_file( $file ) ) {
		return false;
	}

	$filesystem = seopress_import_files_filesystem();

	if ( null === $filesystem ) {
		return false;
	}

	return (bool) $filesystem->delete( wp_normalize_path( realpath( $file ) ) );
}

/**
 * Remove import files left behind by a wizard nobody finished.
 *
 * Runs daily, and again whenever an upload starts, so a site whose WP-Cron
 * never fires still clears the previous file before adding another one.
 *
 * @since 10.3.0
 *
 * @param int|null $lifetime Override the age a file has to reach, in seconds.
 *                           0 removes every file, whatever its age.
 *
 * @return int Number of files deleted.
 */
function seopress_cleanup_import_files( $lifetime = null ) {
	$dir = seopress_import_files_dir( false );

	if ( is_wp_error( $dir ) ) {
		return 0;
	}

	$files  = (array) glob( trailingslashit( $dir['path'] ) . 'seopress-import-*' );
	$upload = wp_upload_dir();
	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$files = array_merge( $files, (array) glob( trailingslashit( $upload['basedir'] ) . seopress_import_files_dirname() . '/seopress-import-*' ) );
	}

	if ( empty( $files ) ) {
		return 0;
	}

	$expired = time() - ( null === $lifetime ? seopress_import_files_lifetime() : max( 0, (int) $lifetime ) );
	$deleted = 0;

	foreach ( $files as $file ) {
		if ( ! is_file( $file ) ) {
			continue;
		}

		$modified = filemtime( $file );

		if ( false === $modified || $modified > $expired ) {
			continue;
		}

		if ( seopress_delete_import_file( $file ) ) {
			++$deleted;
		}
	}

	return $deleted;
}
