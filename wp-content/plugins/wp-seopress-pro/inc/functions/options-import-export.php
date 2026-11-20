<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Import / Export.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

// seopress_detect_csv_separator(), shared with the metadata CSV importer.
require_once __DIR__ . '/helpers-csv.php';

/**
 * The eight redirection importers, and what they share.
 *
 * Every one of them used to carry its own copy of the same routine: read the
 * upload whole, loop over it, call wp_insert_post() per row, redirect. That is
 * what made an 18,000 line file impossible to import - the request ran out of
 * time or memory somewhere in the middle and left a partial import behind -
 * and it meant every fix to the parsing had to be made eight times.
 *
 * The parsing now lives in \SEOPressPro\Services\RedirectionsImport, one
 * method per export format, and the writing is batched. What is left here is
 * the form plumbing: check the nonce, check the capability, hand the file to
 * the importer, report the result.
 *
 * The Tools screen drives the same importer over REST instead, one slice per
 * request, which is what makes the size of the file stop mattering. These
 * handlers stay for anything still posting these actions directly, and run the
 * whole file in a single request - which the batched writes make viable for a
 * file that used to take the request down.
 *
 * @param string $action Value of the seopress_action field.
 * @param string $nonce  Name of the nonce field, also its action.
 * @param string $source Import source key.
 *
 * @return void
 */
function seopress_handle_redirections_import( $action, $nonce, $source ) {
	if ( empty( $_POST['seopress_action'] ) || $action !== $_POST['seopress_action'] ) {
		return;
	}

	if ( ! isset( $_POST[ $nonce ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce ] ) ), $nonce ) ) {
		return;
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$importer   = seopress_pro_get_service( 'RedirectionsImport' );
	$sources    = $importer->get_sources();
	$extensions = $sources[ $source ]['extensions'];

	$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
	$extension = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );
	// Not sanitized, and it must not be: this is the path PHP wrote the upload
	// to, and is_uploaded_file() below is what validates it. Running it through
	// a string sanitizer would only turn a valid path into an unreadable one.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? $_FILES['import_file']['tmp_name'] : '';

	if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	if ( ! in_array( $extension, $extensions, true ) ) {
		wp_die(
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of accepted file extensions, e.g. ".csv, .txt". */
					__( 'Please upload one of these file types: %s', 'wp-seopress-pro' ),
					seopress_pro_list_extensions( $extensions )
				)
			)
		);
	}

	$separator = isset( $_POST['import_sep'] ) ? sanitize_key( wp_unslash( $_POST['import_sep'] ) ) : 'auto';

	$result = $importer->import_all( $tmp_name, $source, array( 'separator' => $separator ) );

	if ( is_wp_error( $result ) ) {
		$result = array(
			'imported'  => 0,
			'skipped'   => 0,
			'degraded'  => 0,
			'duplicate' => 0,
			'disabled'  => 0,
			'error'     => $result->get_error_message(),
		);
	}

	set_transient(
		'seopress_csv_import_result_' . get_current_user_id(),
		array(
			'imported'  => (int) $result['imported'],
			'skipped'   => (int) $result['skipped'],
			'degraded'  => (int) $result['degraded'],
			'duplicate' => (int) $result['duplicate'],
			'disabled'  => isset( $result['disabled'] ) ? (int) $result['disabled'] : 0,
			'disabled_imported' => isset( $result['disabled_imported'] ) ? (int) $result['disabled_imported'] : null,
			'error'     => isset( $result['error'] ) ? $result['error'] : '',
		),
		MINUTE_IN_SECONDS
	);

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) );
	exit;
}

/**
 * Import redirections from SEOPress's own CSV export.
 *
 * @return void
 */
function seopress_import_redirections_settings() {
	seopress_handle_redirections_import( 'import_redirections_settings', 'seopress_import_redirections_nonce', 'csv' );
}
add_action( 'admin_init', 'seopress_import_redirections_settings' );

/**
 * Import Redirections from Yoast Premium (CSV).
 *
 * @return void
 */
function seopress_import_yoast_redirections() {
	seopress_handle_redirections_import( 'import_yoast_redirections', 'seopress_import_yoast_redirections_nonce', 'yoast' );
}
add_action( 'admin_init', 'seopress_import_yoast_redirections' );

/**
 * Export Redirections to CSV file.
 *
 * @return void
 */
function seopress_export_redirections_settings() {
	if ( empty( $_POST['seopress_action'] ) || 'export_redirections' != $_POST['seopress_action'] ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['seopress_export_redirections_nonce'], 'seopress_export_redirections_nonce' ) ) {
		return;
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}

	// Initialize.
	$args = array(
		'post_type'      => 'seopress_404',
		'posts_per_page' => '-1',
		'meta_query'     => array(
			array(
				'key'     => '_seopress_redirections_type',
				'value'   => array( '301', '302', '307', '410', '451' ),
				'compare' => 'IN',
			),
		),
	);

	$args = apply_filters( 'seopress_export_redirections_query', $args );

	$seopress_redirects_query = new WP_Query( $args );

	// Open output buffer to cleanly handle CSV output.
	ob_start();
	$output = fopen( 'php://output', 'w' );

	// CSV headers.
	$headers = array(
		'Title',
		'Redirection URL',
		'Redirection Type',
		'Enabled',
		'Parameter',
		'404 Count',
		'Categories',
		'Regex Enabled',
		'Logged Status',
		'Date of Last Request',
		'User Agent',
		'Full Origin',
	);
	fputcsv( $output, $headers, ',', '"', '\\' );

	if ( $seopress_redirects_query->have_posts() ) {
		while ( $seopress_redirects_query->have_posts() ) {
			$seopress_redirects_query->the_post();

			$redirect_categories = get_the_terms( get_the_ID(), 'seopress_404_cat' );
			if ( ! empty( $redirect_categories ) ) {
				$redirect_categories = join( ', ', wp_list_pluck( $redirect_categories, 'term_id' ) );
			} else {
				$redirect_categories = '';
			}

			// Collect row data.
			$row = array(
				html_entity_decode( urldecode( esc_attr( wp_filter_nohtml_kses( get_the_title() ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				html_entity_decode( urldecode( esc_attr( get_post_meta( get_the_ID(), '_seopress_redirections_value', true ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				get_post_meta( get_the_ID(), '_seopress_redirections_type', true ),
				get_post_meta( get_the_ID(), '_seopress_redirections_enabled', true ),
				get_post_meta( get_the_ID(), '_seopress_redirections_param', true ),
				get_post_meta( get_the_ID(), 'seopress_404_count', true ),
				$redirect_categories,
				get_post_meta( get_the_ID(), '_seopress_redirections_enabled_regex', true ),
				get_post_meta( get_the_ID(), '_seopress_redirections_logged_status', true ),
				get_post_meta( get_the_ID(), '_seopress_404_redirect_date_request', true ),
				get_post_meta( get_the_ID(), 'seopress_redirections_ua', true ),
				get_post_meta( get_the_ID(), 'seopress_redirections_referer', true ),
			);

			// Write row to CSV. The title and the referer come from visitor
			// requests, so a cell must never be evaluated as a formula.
			fputcsv( $output, array_map( 'seopress_pro_escape_csv_value', $row ), ',', '"', '\\' );
		}
		wp_reset_postdata();
	}

	// Close output and force download.
	fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV export streams through a handle, WP_Filesystem has no streaming API.
	header( 'Content-Type: application/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-redirections-export-' . gmdate( 'Y-m-d' ) . '.csv' );
	header( 'Expires: 0' );
	header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
	header( 'Content-Transfer-Encoding: binary' );
	ob_end_flush();
	exit;
}
add_action( 'admin_init', 'seopress_export_redirections_settings' );

/**
 * Export Slug Changes to CSV file.
 *
 * @return void
 */
function seopress_export_slug_changes() {
	if ( empty( $_POST['seopress_action'] ) || 'export_slug_changes' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_export_slug_changes_nonce'], 'seopress_export_slug_changes_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}

	// Initialize.
	$slug_changes_csv = '';

	$slug_changes = get_option( 'seopress_can_post_redirect' ) ?? null;

	if ( ! empty( $slug_changes ) ) {
		// php://temp is an in-memory stream, not a file on disk, so WP_Filesystem does not apply.
		$slug_output = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		foreach ( $slug_changes as $slug ) {
			$row = array(
				html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( $slug['before_url'] ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( $slug['new_url'] ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				$slug['type'],
			);

			fputcsv( $slug_output, array_map( 'seopress_pro_escape_csv_value', $row ), ';', '"', '\\' );
		}

		rewind( $slug_output );
		$slug_changes_csv = stream_get_contents( $slug_output );
		fclose( $slug_output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	ignore_user_abort( true );
	nocache_headers();
	header( 'Content-Type: application/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-slug-changes-export-' . gmdate( 'm-d-Y' ) . '.csv' );
	header( 'Expires: 0' );
	echo $slug_changes_csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV / htaccess export payload, printed verbatim as a download body.
	exit;
}
add_action( 'admin_init', 'seopress_export_slug_changes' );

/**
 * Export Redirections to txt file for .htaccess.
 *
 * @return void
 */
function seopress_export_redirections_htaccess_settings() {
	if ( empty( $_POST['seopress_action'] ) || 'export_redirections_htaccess' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_export_redirections_htaccess_nonce'], 'seopress_export_redirections_htaccess_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}

	// Initialize.
	$redirects_html = '';

	$args                     = array(
		'post_type'      => 'seopress_404',
		'posts_per_page' => '-1',
		'meta_query'     => array(
			array(
				'key'     => '_seopress_redirections_type',
				'value'   => array( '301', '302', '307', '410', '451' ),
				'compare' => 'IN',
			),
			array(
				'key'   => '_seopress_redirections_enabled',
				'value' => 'yes',
			),
		),
	);
	$seopress_redirects_query = new WP_Query( $args );

	if ( $seopress_redirects_query->have_posts() ) {
		while ( $seopress_redirects_query->have_posts() ) {
			$seopress_redirects_query->the_post();

			switch ( get_post_meta( get_the_ID(), '_seopress_redirections_type', true ) ) {
				case '301':
					$type = 'redirect 301 ';
					break;
				case '302':
					$type = 'redirect 302 ';
					break;
				case '307':
					$type = 'redirect 307 ';
					break;
				case '410':
					$type = 'redirect 410 ';
					break;
				case '451':
					$type = 'redirect 451 ';
					break;
			}

			$redirects_html .= $type . ' /' . untrailingslashit( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( get_the_title() ) ) ) ) ) . ' ';
			$redirects_html .= urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( get_post_meta( get_the_ID(), '_seopress_redirections_value', true ) ) ) ) );
			$redirects_html .= "\n";
		}
		wp_reset_postdata();
	}

	ignore_user_abort( true );
	echo $redirects_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV / htaccess export payload, printed verbatim as a download body.
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-redirections-htaccess-export-' . gmdate( 'm-d-Y' ) . '.txt' );
	header( 'Expires: 0' );
	exit;
}
add_action( 'admin_init', 'seopress_export_redirections_htaccess_settings' );

/**
 * Import Redirections from the Redirection plugin (JSON).
 *
 * @return void
 */
function seopress_import_redirections_plugin_settings() {
	seopress_handle_redirections_import( 'import_redirections_plugin_settings', 'seopress_import_redirections_plugin_nonce', 'redirections_plugin' );
}
add_action( 'admin_init', 'seopress_import_redirections_plugin_settings' );

/**
 * Import Redirections from Rank Math (JSON).
 *
 * @return void
 */
function seopress_import_rk_redirections() {
	seopress_handle_redirections_import( 'import_rk_redirections', 'seopress_import_rk_redirections_nonce', 'rankmath' );
}
add_action( 'admin_init', 'seopress_import_rk_redirections' );

/**
 * Import Redirections from Rank Math (CSV export).
 *
 * @return void
 */
function seopress_import_rk_csv_redirections() {
	seopress_handle_redirections_import( 'import_rk_csv_redirections', 'seopress_import_rk_csv_redirections_nonce', 'rankmath_csv' );
}
add_action( 'admin_init', 'seopress_import_rk_csv_redirections' );

/**
 * Import Redirections from Slim SEO (CSV).
 *
 * @return void
 */
function seopress_import_slimseo_redirections() {
	seopress_handle_redirections_import( 'import_slimseo_redirections', 'seopress_import_slimseo_redirections_nonce', 'slimseo' );
}
add_action( 'admin_init', 'seopress_import_slimseo_redirections' );

/**
 * Import Redirections from All in One SEO (JSON).
 *
 * @return void
 */
function seopress_import_aioseo_redirections() {
	seopress_handle_redirections_import( 'import_aioseo_redirections', 'seopress_import_aioseo_redirections_nonce', 'aioseo' );
}
add_action( 'admin_init', 'seopress_import_aioseo_redirections' );

/**
 * Import Redirections from SmartCrawl (JSON).
 *
 * @return void
 */
function seopress_import_smartcrawl_redirections() {
	seopress_handle_redirections_import( 'import_smartcrawl_redirections', 'seopress_import_smartcrawl_redirections_nonce', 'smartcrawl' );
}
add_action( 'admin_init', 'seopress_import_smartcrawl_redirections' );

/**
 * Export 404 errors to CSV file.
 *
 * @return void
 */
function seopress_export_404_settings() {
	if ( empty( $_POST['seopress_action'] ) || 'export_404' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_export_404_nonce'], 'seopress_export_404_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}

	// Initialize.
	$errors_404_html = '';

	$args = array(
		'post_type'      => 'seopress_404',
		'posts_per_page' => '-1',
		'meta_query'     => array(
			array(
				'key'     => '_seopress_redirections_type',
				'compare' => 'NOT EXISTS',
			),
		),
	);

	$args = apply_filters( 'seopress_export_404_query', $args );

	$seopress_404_query = new WP_Query( $args );

	if ( $seopress_404_query->have_posts() ) {
		// php://temp is an in-memory stream, not a file on disk, so WP_Filesystem does not apply.
		$errors_output = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		while ( $seopress_404_query->have_posts() ) {
			$seopress_404_query->the_post();

			// The title is the URL the visitor requested and the referer comes
			// from their request headers: both are attacker-controlled, so the
			// cells are quoted and escaped rather than concatenated raw.
			$row = array(
				html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( get_the_title() ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
				get_post_meta( get_the_ID(), 'seopress_404_count', true ),
				html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( ( get_post_meta( get_the_ID(), 'seopress_redirections_referer', true ) ) ) ) ) ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			);

			fputcsv( $errors_output, array_map( 'seopress_pro_escape_csv_value', $row ), ';', '"', '\\' );
		}
		wp_reset_postdata();

		rewind( $errors_output );
		$errors_404_html = stream_get_contents( $errors_output );
		fclose( $errors_output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	ignore_user_abort( true );
	nocache_headers();
	header( 'Content-Type: application/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-404-export-' . gmdate( 'm-d-Y' ) . '.csv' );
	header( 'Expires: 0' );
	echo $errors_404_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV / htaccess export payload, printed verbatim as a download body.
	exit;
}
add_action( 'admin_init', 'seopress_export_404_settings' );

/**
 * Admin pages a maintenance action redirects to.
 *
 * The outcome notice is rendered on these and nowhere else, so it keeps out of
 * the rest of wp-admin.
 *
 * @since 10.2.0
 *
 * @return string[]
 */
function seopress_maintenance_pages() {
	return array( 'seopress-redirections', 'seopress-import-export' );
}

/**
 * Where the outcome of a maintenance action waits for the next page load.
 *
 * Keyed on the account that ran it, so one operator's result cannot surface on
 * another's screen.
 *
 * @since 10.2.0
 *
 * @return string
 */
function seopress_maintenance_transient_key() {
	return 'seopress_maintenance_' . get_current_user_id();
}

/**
 * Send the user back from a maintenance action with the outcome attached.
 *
 * The four maintenance actions used to bail out on three bare `return`s: a
 * nonce that had expired because the tab was left open, a filtered capability,
 * or a stripped POST field all ended the request with nothing on screen. They
 * were equally silent on success. So a user clicking "Delete everything" got a
 * page reload either way and no means of telling a completed deletion from a
 * no-op, which is exactly what was reported.
 *
 * The outcome waits in a transient of its own rather than in the URL. An
 * outcome carried in query args survives everything the user does next: the
 * dismiss cross only removes the notice from the page, so a reload rendered it
 * again from the same arguments, and the arguments stay in anything the user
 * copies or bookmarks. A transient is read once, belongs to the account that
 * ran the action, cannot be forged into somebody else's screen, and survives
 * the redirect on older installs that drop query args on the way.
 *
 * @since 10.2.0
 *
 * @param string   $page    Admin page to return to.
 * @param string   $outcome One of `done`, `failed`, `expired`, `denied`.
 * @param int|null $deleted Rows removed, when the action can count them.
 *
 * @return void
 */
function seopress_maintenance_redirect( $page, $outcome, $deleted = null ) {
	set_transient(
		seopress_maintenance_transient_key(),
		array(
			'outcome' => $outcome,
			'deleted' => null === $deleted ? null : (int) $deleted,
		),
		MINUTE_IN_SECONDS
	);

	wp_safe_redirect( admin_url( $page ) );
	exit;
}

/**
 * Whether a maintenance action was asked for, and may proceed.
 *
 * Answers the outcome to report rather than a boolean, so each caller redirects
 * with a reason instead of returning into silence.
 *
 * @since 10.2.0
 *
 * @param string $action     Expected `seopress_action` value.
 * @param string $nonce_name POST field holding the nonce.
 * @param string $capability Capability area passed to seopress_capability().
 *
 * @return string `skip` when the request is not ours, otherwise `ok`,
 *                `expired` or `denied`.
 */
function seopress_maintenance_check( $action, $nonce_name, $capability ) {
	// admin-ajax.php fires admin_init too. The four actions are ordinary form
	// posts to an admin page, never AJAX calls, so an AJAX request carrying our
	// action is not ours: answering it with a redirect would cut short whatever
	// call it really was.
	if ( wp_doing_ajax() ) {
		return 'skip';
	}

	if ( empty( $_POST['seopress_action'] ) || $action !== $_POST['seopress_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return 'skip';
	}

	// Reading the field unguarded warned on its own when the form was posted
	// without it, which is one of the ways this failed silently.
	$nonce = isset( $_POST[ $nonce_name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, $nonce_name ) ) {
		return 'expired';
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', $capability ) ) ) {
		return 'denied';
	}

	return 'ok';
}

/**
 * Render the outcome of a maintenance action.
 *
 * @since 10.2.0
 *
 * @return void
 */
function seopress_maintenance_admin_notice() {
	// The outcome belongs to the screen the action sent the user back to, so it
	// keeps out of the rest of wp-admin.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! in_array( $page, seopress_maintenance_pages(), true ) ) {
		return;
	}

	$key    = seopress_maintenance_transient_key();
	$report = get_transient( $key );

	if ( ! is_array( $report ) || empty( $report['outcome'] ) ) {
		return;
	}

	// Read once. The dismiss cross only takes the notice off the page, so an
	// outcome that stayed available would come straight back on the next reload.
	delete_transient( $key );

	$outcome = (string) $report['outcome'];
	$deleted = isset( $report['deleted'] ) && null !== $report['deleted'] ? (int) $report['deleted'] : null;

	switch ( $outcome ) {
		case 'done':
			$type = 'success';

			if ( null === $deleted ) {
				$message = __( 'Done. The selected data has been deleted.', 'wp-seopress-pro' );
			} else {
				$message = sprintf(
					/* translators: %s: number of database rows deleted. */
					_n( 'Done. %s row deleted.', 'Done. %s rows deleted.', $deleted, 'wp-seopress-pro' ),
					number_format_i18n( $deleted )
				);
			}
			break;

		case 'failed':
			$type    = 'error';
			$message = __( 'Nothing was deleted: the database refused the query. Check your error log, then try again.', 'wp-seopress-pro' );
			break;

		case 'expired':
			$type    = 'error';
			$message = __( 'Nothing was deleted: this page had been open long enough for its security token to expire. Reload it and try again.', 'wp-seopress-pro' );
			break;

		case 'denied':
			$type    = 'error';
			$message = __( 'Nothing was deleted: your account is not allowed to perform this action.', 'wp-seopress-pro' );
			break;

		default:
			return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}
add_action( 'admin_notices', 'seopress_maintenance_admin_notice' );

/**
 * Re-attach the maintenance notice after the free plugin clears the hook.
 *
 * seopress_remove_other_notices() drops every admin_notices callback on
 * `in_admin_header` at priority 1000 whenever the current screen is one of
 * ours, and puts back only its own three. wp-admin/admin-header.php fires
 * `in_admin_header` well before `admin_notices`, and every page a maintenance
 * action redirects to is a SEOPress page, so without this the notice is
 * unhooked before it can render and the actions stay as silent as they were.
 *
 * add_action() keys on the callback and the priority, so registering the same
 * one twice cannot render the notice twice.
 *
 * @since 10.2.0
 *
 * @return void
 */
function seopress_maintenance_restore_admin_notice() {
	add_action( 'admin_notices', 'seopress_maintenance_admin_notice' );
}
add_action( 'in_admin_header', 'seopress_maintenance_restore_admin_notice', 1001 );

/**
 * Drop the object caches a raw SQL deletion leaves behind.
 *
 * The deletions below go straight to the tables, so none of the cache
 * housekeeping wp_delete_post() and delete_post_meta() normally do happens.
 * Without a persistent object cache that is invisible, the caches die with the
 * request. With Redis or Memcached in front of it, which is the norm on managed
 * hosting, WP_Query keeps serving the deleted entries out of the `posts` group:
 * the rows are gone from the database and the screen still lists them, which
 * from the other side looks exactly like "Delete everything did nothing".
 *
 * Post meta is cached per object id rather than behind `last_changed`, so a
 * bulk delete has nothing finer than the whole group to drop, and nothing at
 * all on a drop-in that cannot flush a group.
 *
 * @since 10.2.0
 *
 * @param bool $posts Whether whole posts were deleted, not only their meta.
 *
 * @return void
 */
function seopress_maintenance_flush_caches( $posts = false ) {
	if ( $posts ) {
		wp_cache_set_posts_last_changed();
	}

	if ( wp_cache_supports( 'flush_group' ) ) {
		wp_cache_flush_group( 'post_meta' );
	}

	wp_cache_set_last_changed( 'post_meta' );
}

/**
 * Clean all 404.
 *
 * @param array $args The arguments.
 *
 * @return array $args
 */
function seopress_clean_404_query_hook( $args ) {
	unset( $args['date_query'] );

	return $args;
}

/**
 * Clean all 404.
 *
 * @return void
 */
function seopress_clean_404() {
	$page    = 'admin.php?page=seopress-redirections&view=404';
	$outcome = seopress_maintenance_check( 'clean_404', 'seopress_clean_404_nonce', '404' );

	if ( 'skip' === $outcome ) {
		return;
	}

	if ( 'ok' !== $outcome ) {
		seopress_maintenance_redirect( $page, $outcome );
	}

	add_filter( 'seopress_404_cleaning_query', 'seopress_clean_404_query_hook' );
	do_action( 'seopress_404_cron_cleaning', true );

	// The cleaning runs through a cron action and reports no count.
	seopress_maintenance_redirect( $page, 'done' );
}
add_action( 'admin_init', 'seopress_clean_404' );

/**
 * Reset Count column.
 *
 * @return void
 */
function seopress_clean_counters() {
	$page    = 'admin.php?page=seopress-redirections';
	$outcome = seopress_maintenance_check( 'clean_counters', 'seopress_clean_counters_nonce', '404' );

	if ( 'skip' === $outcome ) {
		return;
	}

	if ( 'ok' !== $outcome ) {
		seopress_maintenance_redirect( $page, $outcome );
	}

	global $wpdb;

	// No placeholder, so no prepare(): calling it on a query with nothing to
	// bind raises "The query argument of wpdb::prepare() must have a
	// placeholder", and on a host that promotes notices to exceptions that
	// stops the request before the delete ever runs.
	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		'DELETE FROM `' . $wpdb->prefix . 'postmeta` WHERE `meta_key` = \'seopress_404_count\''
	);

	// query() answers false on a database error, and (int) false is 0: reported
	// as a count that would read "0 rows deleted" under a green notice, which is
	// the failure this whole change exists to stop hiding.
	if ( false === $deleted ) {
		seopress_maintenance_redirect( $page, 'failed' );
	}

	seopress_maintenance_flush_caches();

	seopress_maintenance_redirect( $page, 'done', $deleted );
}
add_action( 'admin_init', 'seopress_clean_counters' );

/**
 * Clean all (redirects / 404 errors).
 *
 * @return void
 */
function seopress_clean_all() {
	$page    = 'admin.php?page=seopress-redirections';
	$outcome = seopress_maintenance_check( 'clean_all', 'seopress_clean_all_nonce', '404' );

	if ( 'skip' === $outcome ) {
		return;
	}

	if ( 'ok' !== $outcome ) {
		seopress_maintenance_redirect( $page, $outcome );
	}

	global $wpdb;

	// SQL query.
	$sql = 'DELETE `posts`, `pm`
		FROM `' . $wpdb->prefix . 'posts` AS `posts`
		LEFT JOIN `' . $wpdb->prefix . 'postmeta` AS `pm` ON `pm`.`post_id` = `posts`.`ID`
		WHERE `posts`.`post_type` = \'seopress_404\'';

	// No placeholder, so no prepare(). See seopress_clean_counters().
	$deleted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

	// See seopress_clean_counters(): a database error must not come back as a
	// deletion of no rows.
	if ( false === $deleted ) {
		seopress_maintenance_redirect( $page, 'failed' );
	}

	seopress_maintenance_flush_caches( true );

	seopress_maintenance_redirect( $page, 'done', $deleted );
}
add_action( 'admin_init', 'seopress_clean_all' );

/**
 * Export metadata.
 *
 * @return void
 */
function seopress_download_batch_export() {
	if ( empty( $_GET['seopress_action'] ) || 'seopress_download_batch_export' != $_GET['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_GET['nonce'], 'seopress_csv_batch_export_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}
	if ( '' != get_option( 'seopress_metadata_csv' ) ) {
		$csv = get_option( 'seopress_metadata_csv' );

		$csv_fields   = array();
		$csv_fields[] = 'id';
		$csv_fields[] = 'post_title';
		$csv_fields[] = 'url';
		$csv_fields[] = 'slug';
		$csv_fields[] = 'taxonomy';
		$csv_fields[] = 'post_type';
		$csv_fields[] = 'meta_title';
		$csv_fields[] = 'meta_desc';
		$csv_fields[] = 'fb_title';
		$csv_fields[] = 'fb_desc';
		$csv_fields[] = 'fb_img';
		$csv_fields[] = 'tw_title';
		$csv_fields[] = 'tw_desc';
		$csv_fields[] = 'tw_img';
		$csv_fields[] = 'noindex';
		$csv_fields[] = 'nofollow';
		$csv_fields[] = 'noimageindex';
		$csv_fields[] = 'nosnippet';
		$csv_fields[] = 'canonical_url';
		$csv_fields[] = 'primary_cat';
		$csv_fields[] = 'redirect_active';
		$csv_fields[] = 'redirect_status';
		$csv_fields[] = 'redirect_type';
		$csv_fields[] = 'redirect_url';
		$csv_fields[] = 'target_kw';
		$csv_fields[] = 'breadcrumbs';
		ob_start();
		$output_handle = @fopen( 'php://output', 'w' );

		// Insert header row.
		fputcsv( $output_handle, $csv_fields, ';', '"', '\\' );

		// Header.
		ignore_user_abort( true );
		nocache_headers();
		header( 'Content-Type: application/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=seopress-metadata-export-' . gmdate( 'm-d-Y' ) . '.csv' );
		header( 'Expires: 0' );
		header( 'Pragma: public' );

		if ( ! empty( $csv ) ) {
			foreach ( $csv as $value ) {
				fputcsv( $output_handle, array_map( 'seopress_pro_escape_csv_value', (array) $value ), ';', '"', '\\' );
			}
		}

		// Close output file stream.
		fclose( $output_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV export streams through a handle, WP_Filesystem has no streaming API.

		// Clean database.
		delete_option( 'seopress_metadata_csv' );
		exit;
	}
}
add_action( 'admin_init', 'seopress_download_batch_export' );

/**
 * Delete all SEO Issues.
 *
 * @return void
 */
function seopress_clean_audit_scans() {
	$page    = 'admin.php?page=seopress-import-export';
	$outcome = seopress_maintenance_check( 'clean_audit_scans', 'seopress_clean_audit_scans_nonce', 'cleaning' );

	if ( 'skip' === $outcome ) {
		return;
	}

	if ( 'ok' !== $outcome ) {
		seopress_maintenance_redirect( $page, $outcome );
	}

	global $wpdb;

	// Left null when the table was never created: there was nothing to count,
	// which is not the same answer as having counted zero.
	$deleted = null;

	// Clean custom table if it exists.
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}seopress_seo_issues'" ) === $wpdb->prefix . 'seopress_seo_issues' ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		// No placeholder, so no prepare(). See seopress_clean_counters().
		$deleted = $wpdb->query( 'DELETE FROM `' . $wpdb->prefix . 'seopress_seo_issues`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		// See seopress_clean_counters().
		if ( false === $deleted ) {
			seopress_maintenance_redirect( $page, 'failed' );
		}
	}

	seopress_maintenance_redirect( $page, 'done', $deleted );
}
add_action( 'admin_init', 'seopress_clean_audit_scans' );

/**
 * Files SEOPress may have left in the uploads directory.
 *
 * Two of them used to be created with names anybody can work out, in a
 * directory the web server hands out on request:
 *
 *   - `seopress-settings.json`, written there by `wp seopress settings export`
 *     before 10.3, holds the license key, the AI provider keys, the instant
 *     indexing keys and the Google client secret.
 *   - `seopress-metadata-export-MM-DD-YYYY.csv`, left behind by the CSV import
 *     wizard, holds the title, description, target keyword and redirect of
 *     every post and term on the site.
 *
 * Neither is created any more, but an install that ran either of them still has
 * the file. Nothing here deletes anything on its own: the file was put there by
 * a deliberate action, so the choice of removing it stays with the person who
 * made it.
 *
 * What identifies one of them, for the purpose of remembering that somebody
 * chose to keep it. The path alone would not do: an export run again writes a
 * new file over the same name, and a decision taken about the old one says
 * nothing about its replacement.
 *
 * @since 10.3.0
 *
 * @param string $file Absolute path.
 *
 * @return string
 */
function seopress_exposed_file_signature( $file ) {
	return md5( $file . '|' . (int) @filemtime( $file ) . '|' . (int) @filesize( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file that disappeared between the glob and here signs as 0|0, which simply never matches something that was kept.
}

/**
 * Files SEOPress may have left in the uploads directory.
 *
 * See seopress_exposed_file_signature() for how a file somebody chose to keep
 * is told apart from a fresh export carrying the same name.
 *
 * @since 10.3.0
 *
 * @return array List of absolute paths.
 */
function seopress_find_exposed_files() {
	$cached = get_transient( 'seopress_exposed_files' );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$upload = wp_upload_dir();
	$found  = array();

	if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
		$basedir = trailingslashit( wp_normalize_path( $upload['basedir'] ) );

		$patterns = array(
			$basedir . 'seopress-settings.json',
			$basedir . 'seopress-imports/seopress-import-*',
			$basedir . 'seopress-redirections-import/job-*.ndjson',
			$basedir . 'seopress-metadata-export-*.csv',
			// Uploads organised by date, which is the default.
			$basedir . '*/*/seopress-metadata-export-*.csv',
		);

		foreach ( $patterns as $pattern ) {
			$matches = glob( $pattern );

			if ( ! empty( $matches ) ) {
				$found = array_merge( $found, array_map( 'wp_normalize_path', $matches ) );
			}
		}

		// What was kept is remembered by signature rather than by path, so
		// re-running an export puts the name back on the list: the decision to
		// keep one file says nothing about the one that replaced it.
		$ignored = (array) get_option( 'seopress_exposed_files_ignored', array() );

		$found = array_values(
			array_filter(
				$found,
				function ( $file ) use ( $ignored ) {
					return ! in_array( seopress_exposed_file_signature( $file ), $ignored, true );
				}
			)
		);
	}

	// Long enough that the glob does not run on every admin page load, short
	// enough that a file created today is reported today.
	set_transient( 'seopress_exposed_files', $found, 6 * HOUR_IN_SECONDS );

	return $found;
}

/**
 * Delete one of the files above.
 *
 * When the file is also registered as an attachment, which is how the import
 * wizard used to store what it uploaded, the attachment goes with it. Deleting
 * the file alone would leave the media library listing something that is no
 * longer there.
 *
 * @since 10.3.0
 *
 * @param string $file Absolute path.
 *
 * @return bool
 */
function seopress_delete_exposed_file( $file ) {
	$upload = wp_upload_dir();

	if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
		return false;
	}

	$real = realpath( $file );

	if ( false === $real || ! is_file( $real ) ) {
		return false;
	}

	$real    = wp_normalize_path( $real );
	$basedir = realpath( $upload['basedir'] );

	if ( false === $basedir ) {
		return false;
	}

	// Never act on a path that resolved outside the uploads directory.
	if ( 0 !== strpos( $real, trailingslashit( wp_normalize_path( $basedir ) ) ) ) {
		return false;
	}

	$url           = str_replace( trailingslashit( wp_normalize_path( $basedir ) ), trailingslashit( $upload['baseurl'] ), $real );
	$attachment_id = attachment_url_to_postid( $url );

	if ( $attachment_id ) {
		return (bool) wp_delete_attachment( $attachment_id, true );
	}

	$filesystem = seopress_import_files_filesystem();

	return null === $filesystem ? false : (bool) $filesystem->delete( $real );
}

/**
 * Where the outcome of a click on the notice waits for the next page load.
 *
 * Keyed on the account that clicked, like the maintenance actions above, so one
 * operator's result cannot surface on another's screen.
 *
 * @since 10.3.0
 *
 * @return string
 */
function seopress_exposed_files_transient_key() {
	return 'seopress_exposed_files_result_' . get_current_user_id();
}

/**
 * Send the user back with the outcome attached, and stop.
 *
 * @since 10.3.0
 *
 * @param array $result Outcome, and the counts when there are any.
 *
 * @return void
 */
function seopress_exposed_files_redirect( $result ) {
	set_transient( seopress_exposed_files_transient_key(), $result, MINUTE_IN_SECONDS );

	// The scan is cached, and what it was caching has just changed.
	delete_transient( 'seopress_exposed_files' );

	$referer = wp_get_referer();

	wp_safe_redirect( $referer ? $referer : admin_url( 'admin.php?page=seopress-import-export' ) );
	exit;
}

/**
 * Handle the two buttons on the notice.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_handle_exposed_files_action() {
	if ( wp_doing_ajax() ) {
		return;
	}

	$action = isset( $_POST['seopress_action'] ) ? sanitize_key( wp_unslash( $_POST['seopress_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! in_array( $action, array( 'delete_exposed_files', 'ignore_exposed_files' ), true ) ) {
		return;
	}

	$nonce = isset( $_POST['seopress_exposed_files_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['seopress_exposed_files_nonce'] ) ) : '';

	// Every outcome is reported, including the ones that end the request early.
	// A notice about files anybody can download must not answer a click with a
	// plain page reload and the same notice still on it.
	if ( ! wp_verify_nonce( $nonce, 'seopress_exposed_files_nonce' ) ) {
		seopress_exposed_files_redirect( array( 'outcome' => 'expired' ) );
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		seopress_exposed_files_redirect( array( 'outcome' => 'denied' ) );
	}

	$files = seopress_find_exposed_files();

	if ( 'ignore_exposed_files' === $action ) {
		$ignored = (array) get_option( 'seopress_exposed_files_ignored', array() );

		// Capped so that a site whose operator keeps clicking "keep" on a file
		// an automation rewrites every night cannot grow this without end.
		$ignored = array_slice(
			array_values( array_unique( array_merge( $ignored, array_map( 'seopress_exposed_file_signature', $files ) ) ) ),
			-50
		);

		update_option( 'seopress_exposed_files_ignored', $ignored, false );

		seopress_exposed_files_redirect( array( 'outcome' => 'kept' ) );
	}

	$deleted = 0;
	$failed  = 0;

	foreach ( $files as $file ) {
		if ( seopress_delete_exposed_file( $file ) ) {
			++$deleted;
		} else {
			++$failed;
		}
	}

	seopress_exposed_files_redirect(
		array(
			'outcome' => 0 === $failed ? 'deleted' : 'partial',
			'deleted' => $deleted,
			'failed'  => $failed,
		)
	);
}
add_action( 'admin_init', 'seopress_handle_exposed_files_action' );

/**
 * Report what the last click on the notice did.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_exposed_files_outcome_notice() {
	$key    = seopress_exposed_files_transient_key();
	$result = get_transient( $key );

	if ( ! is_array( $result ) || empty( $result['outcome'] ) ) {
		return;
	}

	// Read once: the dismiss cross only takes the notice off the page, so an
	// outcome left available would come back on the next reload.
	delete_transient( $key );

	$deleted = isset( $result['deleted'] ) ? (int) $result['deleted'] : 0;
	$failed  = isset( $result['failed'] ) ? (int) $result['failed'] : 0;

	switch ( $result['outcome'] ) {
		case 'deleted':
			$type    = 'success';
			$message = sprintf(
				/* translators: %s: number of files deleted. */
				_n( '%s file deleted.', '%s files deleted.', $deleted, 'wp-seopress-pro' ),
				number_format_i18n( $deleted )
			);
			break;

		case 'partial':
			$type    = 'error';
			$message = sprintf(
				/* translators: 1: number of files deleted, 2: number of files that could not be deleted. */
				__( '%1$s deleted, %2$s could not be. Check the permissions on your uploads folder, then remove the remaining files yourself.', 'wp-seopress-pro' ),
				sprintf(
					/* translators: %s: number of files. */
					_n( '%s file', '%s files', $deleted, 'wp-seopress-pro' ),
					number_format_i18n( $deleted )
				),
				number_format_i18n( $failed )
			);
			break;

		case 'kept':
			$type    = 'info';
			$message = __( 'Nothing was deleted. These files will not be reported again unless they are recreated.', 'wp-seopress-pro' );
			break;

		case 'expired':
			$type    = 'error';
			$message = __( 'Nothing was done: this page had been open long enough for its security token to expire. Reload it and try again.', 'wp-seopress-pro' );
			break;

		case 'denied':
			$type    = 'error';
			$message = __( 'Nothing was done: your account is not allowed to perform this action.', 'wp-seopress-pro' );
			break;

		default:
			return;
	}

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}

/**
 * Tell people about the files, and give them one click to remove them.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_exposed_files_notice() {
	if ( ! function_exists( 'is_seopress_page' ) || ! is_seopress_page() ) {
		return;
	}

	if ( ! current_user_can( seopress_capability( 'manage_options', 'export_settings' ) ) ) {
		return;
	}

	seopress_exposed_files_outcome_notice();

	$files = seopress_find_exposed_files();

	if ( empty( $files ) ) {
		return;
	}

	$upload  = wp_upload_dir();
	$basedir = trailingslashit( wp_normalize_path( $upload['basedir'] ) );
	?>
	<div class="notice notice-warning">
		<p>
			<strong><?php esc_html_e( 'SEOPress left an export where anyone can download it', 'wp-seopress-pro' ); ?></strong>
		</p>
		<p>
			<?php esc_html_e( 'These files were written to your uploads directory by an earlier version. Your web server hands them to anyone who requests the URL, and a settings export contains your license key and your API keys.', 'wp-seopress-pro' ); ?>
		</p>
		<ul style="list-style:disc;margin-left:2em">
			<?php foreach ( $files as $file ) : ?>
				<li>
					<code><?php echo esc_html( str_replace( $basedir, '', $file ) ); ?></code>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'seopress_exposed_files_nonce', 'seopress_exposed_files_nonce' ); ?>
				<input type="hidden" name="seopress_action" value="delete_exposed_files" />
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Delete these files', 'wp-seopress-pro' ); ?>
				</button>
			</form>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'seopress_exposed_files_nonce', 'seopress_exposed_files_nonce' ); ?>
				<input type="hidden" name="seopress_action" value="ignore_exposed_files" />
				<button type="submit" class="button button-secondary">
					<?php esc_html_e( 'Keep them, stop warning me', 'wp-seopress-pro' ); ?>
				</button>
			</form>
		</p>
	</div>
	<?php
}
add_action( 'admin_notices', 'seopress_exposed_files_notice' );

/**
 * Re-attach the notice after the free plugin clears the hook.
 *
 * See seopress_maintenance_restore_admin_notice(): every admin_notices callback
 * is dropped on `in_admin_header` at priority 1000 whenever the current screen
 * is one of ours, which is exactly the screens this notice belongs on.
 *
 * @since 10.3.0
 *
 * @return void
 */
function seopress_restore_exposed_files_notice() {
	add_action( 'admin_notices', 'seopress_exposed_files_notice' );
}
add_action( 'in_admin_header', 'seopress_restore_exposed_files_notice', 1001 );
