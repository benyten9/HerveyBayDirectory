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
 * Store an imported redirection origin the way it will be matched.
 *
 * A plain path keeps the treatment every importer has always applied: decoded,
 * sometimes sanitized, and stripped of its leading slash, which is how the
 * redirection list and the exact-match lookup expect to read it.
 *
 * A regex origin gets none of that, because none of it is safe on a pattern.
 * `%2F` is not an escape waiting to be resolved, `+` means "one or more" rather
 * than a space, sanitize_text_field() deletes every `%XX` sequence, and the
 * leading slash is what anchors the match. Strip it and the matcher, which does
 * not anchor the stored pattern itself, applies the rest anywhere in the path:
 * `/it(/(.*))?$` becomes `it(/(.*))?$` and catches every URL merely containing
 * "it", /credit and /produits/kit included.
 *
 * The return value is slashed because wp_insert_post() unslashes what it is
 * given. Without it every backslash in a pattern is lost on the way to the
 * database - `\d{4}` stored as `d{4}`, `\.html$` as `.html$` - and both still
 * look like regexes while matching far more than the imported rule did.
 *
 * @param string $raw        Origin exactly as it appears in the imported file.
 * @param string $normalized Origin after the importer's own path normalization.
 * @param bool   $is_regex   Whether the imported redirection matches by regex.
 *
 * @return string Slashed origin, ready to hand to wp_insert_post().
 */
function seopress_import_redirection_origin( $raw, $normalized, $is_regex ) {
	return wp_slash( $is_regex ? trim( (string) $raw ) : (string) $normalized );
}

/**
 * Import / Exports settings page.
 *
 * @return void
 */
function seopress_import_redirections_settings() {
	if ( empty( $_POST['seopress_action'] ) || 'import_redirections_settings' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_redirections_nonce'], 'seopress_import_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'csv' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .csv file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	// Determine separator: auto-detect or use manual override.
	$import_sep = isset( $_POST['import_sep'] ) ? sanitize_text_field( wp_unslash( $_POST['import_sep'] ) ) : 'auto';
	if ( 'auto' !== $import_sep && ! empty( $import_sep ) ) {
		if ( 'comma' === $import_sep ) {
			$sep = ',';
		} elseif ( 'semicolon' === $import_sep ) {
			$sep = ';';
		} else {
			wp_die( esc_html__( 'Invalid separator', 'wp-seopress-pro' ) );
		}
	} else {
		$sep = seopress_detect_csv_separator( $import_file );
	}

	$csv = array_map(
		function ( $item ) use ( $sep ) {
			return str_getcsv( $item, $sep, '"', '\\' );
		},
		file( $import_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES )
	);

	// Remove duplicates from CSV.
	$csv = array_unique( $csv, SORT_REGULAR );

	$imported = 0;
	$skipped  = 0;

	foreach ( $csv as $key => $value ) {
		// Drop the leading quote the export adds in front of a cell a
		// spreadsheet would evaluate, so the round trip stays lossless.
		$csv_line = array_map( 'seopress_pro_unescape_csv_value', (array) $value );

		// Skip rows that weren't split correctly (wrong separator or malformed).
		if ( count( $csv_line ) < 2 || empty( $csv_line[0] ) ) {
			++$skipped;
			continue;
		}

		$csv_type_redirects = array(
			2 => '301',
			3 => '',
			4 => 'exact_match',
		);

		// Third column: redirections type.
		$col2 = $csv_line[2] ?? '';
		if ( '301' === $col2 || '302' === $col2 || '307' === $col2 || '410' === $col2 || '451' === $col2 ) {
			$csv_type_redirects[2] = $col2;
		}

		// Fourth column: redirections enabled.
		$csv_line[3] = strtolower( (string) ( $csv_line[3] ?? '' ) );
		if ( 'yes' == $csv_line[3] ) {
			$csv_type_redirects[3] = $csv_line[3];
		} else {
			$csv_type_redirects[3] = '';
		}

		// Fifth column: redirections query param.
		if ( ! empty( $csv_line[4] ) ) {
			if ( 'exact_match' == $csv_line[4] || 'with_ignored_param' == $csv_line[4] || 'without_param' == $csv_line[4] ) {
				$csv_type_redirects[4] = $csv_line[4];
			} else {
				$csv_type_redirects[4] = 'exact_match';
			}
		}

		// Seventh column: redirect categories.
		$cats = array();
		if ( ! empty( $csv_line[6] ) ) {
			$cats = array_values( explode( ',', $csv_line[6] ) );
			$cats = array_map( 'intval', $cats );
			$cats = array_unique( $cats );
		}

		$regex_enable = '';
		// Regex enabled.
		$csv_line[7] = strtolower( (string) ( $csv_line[7] ?? '' ) );
		if ( 'yes' === $csv_line[7] ) {
			$regex_enable = 'yes';
		}

		// Logged status.
		$logged_status = 'both';
		$csv_line[8]   = strtolower( (string) ( $csv_line[8] ?? '' ) );
		if ( ! empty( $csv_line[8] ) ) {
			$logged_status = $csv_line[8];
		}

		$count = null;
		if ( ! empty( $csv_line[5] ) ) {
			$count = $csv_line[5];
		}
		$id = wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $csv_line[0], ltrim( rawurldecode( $csv_line[0] ), '/' ), 'yes' === $regex_enable ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => rawurldecode( $csv_line[1] ?? '' ),
					'_seopress_redirections_type'          => $csv_type_redirects[2],
					'_seopress_redirections_enabled'       => $csv_type_redirects[3],
					'_seopress_redirections_enabled_regex' => $regex_enable,
					'_seopress_redirections_logged_status' => $logged_status,
					'_seopress_redirections_param'         => $csv_type_redirects[4],
					'seopress_404_count'                   => $count,
				),
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
			++$imported;
			// Assign terms.
			if ( ! empty( $cats ) ) {
				wp_set_object_terms( $id, $cats, 'seopress_404_cat' );
			}
		}
	}

	set_transient(
		'seopress_csv_import_result_' . get_current_user_id(),
		array(
			'imported' => $imported,
			'skipped'  => $skipped,
		),
		60
	);

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_redirections_settings' );

/**
 * Import Redirections from Yoast Premium (CSV).
 *
 * @return void
 */
function seopress_import_yoast_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_yoast_redirections' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_yoast_redirections_nonce'], 'seopress_import_yoast_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'csv' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .csv file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$csv = array_map( 'str_getcsv', file( $import_file ) );

	foreach ( array_slice( $csv, 1 ) as $_key => $_value ) {
		$csv_line = $_value;

		// Third column: redirections type.
		if ( '301' == $csv_line[2] || '302' == $csv_line[2] || '307' == $csv_line[2] || '410' == $csv_line[2] || '451' == $csv_line[2] ) {
			$csv_type_redirects[2] = $csv_line[2];
		}

		// Fourth column: redirections enabled.
		$csv_type_redirects[3] = 'yes';

		// Fifth column: redirections query param.
		$csv_type_redirects[4] = 'exact_match';

		if ( ! empty( $csv_line[0] ) ) {
			$csv_line[0] = substr( $csv_line[0], 1 );
			if ( ! empty( $csv_line[1] ) ) {
				if ( '//' === $csv_line[1] ) {
					$csv_line[1] = '/';
				} else {
					$csv_line[1] = home_url() . $csv_line[1];
				}
			}
			$id = wp_insert_post(
				array(
					'post_title'  => urldecode( $csv_line[0] ),
					'post_type'   => 'seopress_404',
					'post_status' => 'publish',
					'meta_input'  => array(
						'_seopress_redirections_value'   => urldecode( $csv_line[1] ),
						'_seopress_redirections_type'    => $csv_type_redirects[2],
						'_seopress_redirections_enabled' => $csv_type_redirects[3],
						'_seopress_redirections_enabled_regex' => '',
						'_seopress_redirections_logged_status' => 'both',
						'_seopress_redirections_param'   => $csv_type_redirects[4],
					),
				)
			);
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=redirects' ) );
	exit;
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
	fclose( $output );
	header( 'Content-Type: application/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-redirections-export-' . date( 'Y-m-d' ) . '.csv' );
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
	header( 'Content-Disposition: attachment; filename=seopress-slug-changes-export-' . date( 'm-d-Y' ) . '.csv' );
	header( 'Expires: 0' );
	echo $slug_changes_csv;
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
	echo $redirects_html;
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=seopress-redirections-htaccess-export-' . date( 'm-d-Y' ) . '.txt' );
	header( 'Expires: 0' );
	exit;
}
add_action( 'admin_init', 'seopress_export_redirections_htaccess_settings' );

/**
 * Import Redirections from Redirections plugin JSON file.
 *
 * @return void
 */
function seopress_import_redirections_plugin_settings() {
	if ( empty( $_POST['seopress_action'] ) || 'import_redirections_plugin_settings' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_redirections_plugin_nonce'], 'seopress_import_redirections_plugin_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'json' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .json file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$settings = (array) json_decode( file_get_contents( $import_file ), true );

	foreach ( $settings['redirects'] as $redirect_key => $redirect_value ) {
		$type = '';
		if ( ! empty( $redirect_value['action_code'] ) ) {
			$type = $redirect_value['action_code'];
		} else {
			$type = '301';
		}

		$param = '';
		if ( ! empty( $redirect_value['match_data']['source']['flag_query'] ) ) {
			$flag_query = $redirect_value['match_data']['source']['flag_query'];
			if ( 'pass' == $flag_query ) {
				$param = 'with_ignored_param';
			} elseif ( 'ignore' == $flag_query ) {
				$param = 'without_param';
			} else {
				$param = 'exact_match';
			}
		}

		$enabled = '';
		if ( ! empty( true == $redirect_value['enabled'] ) ) {
			$enabled = 'yes';
		}
		$regex_enable = '';
		if ( ! empty( $redirect_value['regex'] ) ) {
			$regex_enable = 'yes';
		}

		wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $redirect_value['url'], ltrim( urldecode( $redirect_value['url'] ), '/' ), 'yes' === $regex_enable ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => urldecode( $redirect_value['action_data']['url'] ),
					'_seopress_redirections_type'          => $type,
					'_seopress_redirections_enabled'       => $enabled,
					'_seopress_redirections_enabled_regex' => $regex_enable,
					'_seopress_redirections_logged_status' => 'both',
					'_seopress_redirections_param'         => $param,
				),
			)
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_redirections_plugin_settings' );

/**
 * Import Redirections from Rank Math plugin JSON file
 *
 * @return void
 */
function seopress_import_rk_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_rk_redirections' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_rk_redirections_nonce'], 'seopress_import_rk_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'json' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .json file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$settings = (array) json_decode( file_get_contents( $import_file ), true );

	foreach ( $settings['redirections'] as $redirect_key => $redirect_value ) {
		$type = '';
		if ( ! empty( $redirect_value['header_code'] ) ) {
			$type = $redirect_value['header_code'];
		}

		$source     = '';
		$raw_source = '';
		if ( ! empty( $redirect_value['sources'] ) ) {
			if ( is_serialized( $redirect_value['sources'] ) ) {

				$source = @unserialize( sanitize_text_field( $redirect_value['sources'] ), array( 'allowed_classes' => false ) );

				if ( is_array( $source ) ) {
					$raw_source = $source[0]['pattern'];
					$source     = ltrim( urldecode( $raw_source ), '/' );
				}
			}
		}

		$param = 'exact_match';

		$enabled = '';
		if ( ! empty( 'active' == $redirect_value['status'] ) ) {
			$enabled = 'yes';
		}

		$redirect = '';
		if ( ! empty( $redirect_value['url_to'] ) ) {
			$redirect = urldecode( $redirect_value['url_to'] );
		}

		$count = '';
		if ( ! empty( $redirect_value['hits'] ) ) {
			$count = (int) $redirect_value['hits'];
		}

		$regex = '';
		if ( ! empty( $redirect_value['sources'] ) ) {
			if ( is_serialized( $redirect_value['sources'] ) ) {
				$sources = @unserialize( sanitize_text_field( $redirect_value['sources'] ), array( 'allowed_classes' => false ) );

				if ( is_array( $sources ) ) {
					if ( in_array( 'regex', array_column( $sources, 'comparison' ) ) ) {
						$regex = 'yes';
					}
				}
			}
		}

		wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $raw_source, $source, 'yes' === $regex ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => $redirect,
					'_seopress_redirections_type'          => $type,
					'_seopress_redirections_enabled'       => $enabled,
					'_seopress_redirections_enabled_regex' => $regex,
					'_seopress_redirections_logged_status' => 'both',
					'seopress_404_count'                   => $count,
					'_seopress_redirections_param'         => $param,
				),
			)
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_rk_redirections' );

/**
 * Import Redirections from Rank Math (CSV export).
 *
 * Rank Math Pro exports its redirections as a CSV with a header row and the
 * following named columns: id, source, matching, destination, type, category,
 * status, ignore (some exports also include a hits column). Columns are matched
 * by name so their order in the file does not matter.
 *
 * @return void
 */
function seopress_import_rk_csv_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_rk_csv_redirections' !== $_POST['seopress_action'] ) {
		return;
	}
	if ( ! isset( $_POST['seopress_import_rk_csv_redirections_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seopress_import_rk_csv_redirections_nonce'] ) ), 'seopress_import_rk_csv_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
	$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

	if ( 'csv' !== $extension ) {
		wp_die( esc_html__( 'Please upload a valid .csv file', 'wp-seopress-pro' ) );
	}
	$import_file = isset( $_FILES['import_file']['tmp_name'] ) ? $_FILES['import_file']['tmp_name'] : '';
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$sep  = seopress_detect_csv_separator( $import_file );
	$rows = file( $import_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $rows || empty( $rows ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$csv = array_map(
		function ( $item ) use ( $sep ) {
			return str_getcsv( $item, $sep, '"', '\\' );
		},
		$rows
	);

	// Strip a UTF-8 BOM from the first cell if present.
	if ( isset( $csv[0][0] ) ) {
		$csv[0][0] = preg_replace( '/^\xEF\xBB\xBF/', '', $csv[0][0] );
	}

	// Detect the header row and build a "column name => index" map. Rank Math
	// always exports a header; fall back to the documented column order.
	$default_order = array( 'id', 'source', 'matching', 'destination', 'type', 'category', 'status', 'ignore' );
	$header        = array_map(
		function ( $cell ) {
			return strtolower( trim( (string) $cell ) );
		},
		$csv[0]
	);

	if ( in_array( 'source', $header, true ) && in_array( 'destination', $header, true ) ) {
		$map  = array_flip( $header );
		$data = array_slice( $csv, 1 );
	} else {
		$map  = array_flip( $default_order );
		$data = $csv;
	}

	$get = function ( $row, $key ) use ( $map ) {
		if ( ! isset( $map[ $key ] ) || ! isset( $row[ $map[ $key ] ] ) ) {
			return '';
		}
		return trim( (string) $row[ $map[ $key ] ] );
	};

	$imported = 0;
	$skipped  = 0;
	$degraded = 0;

	foreach ( $data as $row ) {
		if ( ! is_array( $row ) ) {
			++$skipped;
			continue;
		}

		$source      = $get( $row, 'source' );
		$destination = $get( $row, 'destination' );

		// A source is always required; "DELETE" destinations only make sense
		// inside Rank Math, so skip those rows.
		if ( '' === $source || 'DELETE' === $destination ) {
			++$skipped;
			continue;
		}

		// Redirection type — keep only the codes SEOPress understands.
		$type = $get( $row, 'type' );
		if ( ! in_array( $type, array( '301', '302', '307', '410', '451' ), true ) ) {
			$type = '301';
		}

		// A destination is required for 3xx redirects (410/451 may be empty).
		if ( '' === $destination && in_array( $type, array( '301', '302', '307' ), true ) ) {
			++$skipped;
			continue;
		}

		// Status active/inactive => enabled yes/empty.
		$status  = strtolower( $get( $row, 'status' ) );
		$enabled = ( '' === $status || 'active' === $status ) ? 'yes' : '';

		// Hit counter (only present in some exports).
		$count = '';
		$hits  = $get( $row, 'hits' );
		if ( '' !== $hits && is_numeric( $hits ) ) {
			$count = (int) $hits;
		}

		// Categories — Rank Math exports comma-separated slugs.
		$cats = array();
		foreach ( explode( ',', $get( $row, 'category' ) ) as $slug ) {
			$slug = trim( $slug );
			if ( '' !== $slug ) {
				$cats[] = $slug;
			}
		}

		// One Rank Math row may carry several sources as a JSON array.
		$patterns  = array();
		$matchings = array();
		if ( '[' === substr( $source, 0, 1 ) && ']' === substr( $source, -1 ) ) {
			$decoded = json_decode( $source, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $entry ) {
					if ( ! empty( $entry['pattern'] ) ) {
						$patterns[]  = (string) $entry['pattern'];
						$matchings[] = isset( $entry['comparison'] ) ? (string) $entry['comparison'] : $get( $row, 'matching' );
					}
				}
			}
		}
		if ( empty( $patterns ) ) {
			$patterns[]  = $source;
			$matchings[] = $get( $row, 'matching' );
		}

		foreach ( $patterns as $i => $pattern ) {
			$matching = strtolower( trim( (string) ( $matchings[ $i ] ?? '' ) ) );

			$regex = '';
			if ( 'regex' === $matching ) {
				$regex = 'yes';
			} elseif ( in_array( $matching, array( 'contains', 'start', 'end' ), true ) ) {
				// SEOPress has no direct equivalent for these comparisons, so we
				// fall back to an exact match and report the loss of precision.
				++$degraded;
			}

			$id = wp_insert_post(
				array(
					'post_title'  => seopress_import_redirection_origin( $pattern, ltrim( urldecode( $pattern ), '/' ), 'yes' === $regex ),
					'post_type'   => 'seopress_404',
					'post_status' => 'publish',
					'meta_input'  => array(
						'_seopress_redirections_value'         => urldecode( $destination ),
						'_seopress_redirections_type'          => $type,
						'_seopress_redirections_enabled'       => $enabled,
						'_seopress_redirections_enabled_regex' => $regex,
						'_seopress_redirections_logged_status' => 'both',
						'_seopress_redirections_param'         => 'exact_match',
						'seopress_404_count'                   => $count,
					),
				)
			);

			if ( $id && ! is_wp_error( $id ) ) {
				++$imported;
				if ( ! empty( $cats ) ) {
					wp_set_object_terms( $id, $cats, 'seopress_404_cat' );
				}
			} else {
				++$skipped;
			}
		}
	}

	set_transient(
		'seopress_csv_import_result_' . get_current_user_id(),
		array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'degraded' => $degraded,
		),
		60
	);

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_rk_csv_redirections' );

/**
 * Import Redirections from Slim SEO (CSV).
 *
 * Slim SEO exports a CSV with the columns:
 * Type, Condition, From, To, Note, Enable, Ignore Parameters.
 * Columns are matched by header name, so the column order does not matter.
 *
 * @return void
 */
function seopress_import_slimseo_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_slimseo_redirections' !== $_POST['seopress_action'] ) {
		return;
	}
	if ( ! isset( $_POST['seopress_import_slimseo_redirections_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seopress_import_slimseo_redirections_nonce'] ) ), 'seopress_import_slimseo_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$file_name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['import_file']['name'] ) ) : '';
	$extension = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

	if ( 'csv' !== $extension ) {
		wp_die( esc_html__( 'Please upload a valid .csv file', 'wp-seopress-pro' ) );
	}
	$import_file = isset( $_FILES['import_file']['tmp_name'] ) ? $_FILES['import_file']['tmp_name'] : '';
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$sep  = seopress_detect_csv_separator( $import_file );
	$rows = file( $import_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $rows || empty( $rows ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$csv = array_map(
		function ( $item ) use ( $sep ) {
			return str_getcsv( $item, $sep, '"', '\\' );
		},
		$rows
	);

	// Strip a UTF-8 BOM from the first cell if present.
	if ( isset( $csv[0][0] ) ) {
		$csv[0][0] = preg_replace( '/^\xEF\xBB\xBF/', '', $csv[0][0] );
	}

	// Slim SEO always exports a header; match columns by name and fall back to
	// the documented column order if the header is missing.
	$default_order = array( 'type', 'condition', 'from', 'to', 'note', 'enable', 'ignore parameters' );
	$header        = array_map(
		function ( $cell ) {
			return strtolower( trim( (string) $cell ) );
		},
		$csv[0]
	);

	if ( in_array( 'from', $header, true ) && in_array( 'to', $header, true ) ) {
		$map  = array_flip( $header );
		$data = array_slice( $csv, 1 );
	} else {
		$map  = array_flip( $default_order );
		$data = $csv;
	}

	$get = function ( $row, $key ) use ( $map ) {
		if ( ! isset( $map[ $key ] ) || ! isset( $row[ $map[ $key ] ] ) ) {
			return '';
		}
		return trim( (string) $row[ $map[ $key ] ] );
	};

	$imported = 0;
	$skipped  = 0;
	$degraded = 0;

	foreach ( $data as $row ) {
		if ( ! is_array( $row ) ) {
			++$skipped;
			continue;
		}

		$raw_source  = $get( $row, 'from' );
		$source      = ltrim( urldecode( $raw_source ), '/' );
		$destination = urldecode( $get( $row, 'to' ) );

		// A source is always required.
		if ( '' === $source ) {
			++$skipped;
			continue;
		}

		// Redirection type — keep only the codes SEOPress understands.
		$type = $get( $row, 'type' );
		if ( ! in_array( $type, array( '301', '302', '307', '410', '451' ), true ) ) {
			$type = '301';
		}

		// A destination is required for 3xx redirects (410/451 may be empty).
		if ( '' === $destination && in_array( $type, array( '301', '302', '307' ), true ) ) {
			++$skipped;
			continue;
		}

		// Slim SEO stores relative targets; make them absolute like the other
		// importers do, leaving already-absolute URLs untouched.
		if ( '' !== $destination && ! preg_match( '#^https?://#i', $destination ) ) {
			$destination = home_url( '/' . ltrim( $destination, '/' ) );
		}

		// Enable column: "1" / "yes" / "true" => enabled.
		$enable_raw = strtolower( $get( $row, 'enable' ) );
		$enabled    = in_array( $enable_raw, array( '1', 'yes', 'true' ), true ) ? 'yes' : '';

		// Condition: "exact-match" (default) or "regex". Anything Slim SEO
		// supports that SEOPress does not is degraded to an exact match.
		$condition = strtolower( $get( $row, 'condition' ) );
		$regex     = '';
		$param     = 'exact_match';
		if ( 'regex' === $condition ) {
			$regex = 'yes';
		} elseif ( '' !== $condition && ! in_array( $condition, array( 'exact-match', 'exact_match', 'exact' ), true ) ) {
			++$degraded;
		}

		// "Ignore Parameters" => match the URL ignoring query parameters.
		$ignore = strtolower( $get( $row, 'ignore parameters' ) );
		if ( '' === $regex && in_array( $ignore, array( '1', 'yes', 'true' ), true ) ) {
			$param = 'with_ignored_param';
		}

		$id = wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $raw_source, $source, 'yes' === $regex ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => $destination,
					'_seopress_redirections_type'          => $type,
					'_seopress_redirections_enabled'       => $enabled,
					'_seopress_redirections_enabled_regex' => $regex,
					'_seopress_redirections_logged_status' => 'both',
					'_seopress_redirections_param'         => $param,
				),
			)
		);

		if ( $id && ! is_wp_error( $id ) ) {
			++$imported;
		} else {
			++$skipped;
		}
	}

	set_transient(
		'seopress_csv_import_result_' . get_current_user_id(),
		array(
			'imported' => $imported,
			'skipped'  => $skipped,
			'degraded' => $degraded,
		),
		60
	);

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-import-export#tab=tab_seopress_tool_redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_slimseo_redirections' );

/**
 * Import Redirections from AIOSEO plugin JSON file
 *
 * @return void
 */
function seopress_import_aioseo_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_aioseo_redirections' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_aioseo_redirections_nonce'], 'seopress_import_aioseo_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'json' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .json file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$settings = (array) json_decode( file_get_contents( $import_file ), true );

	foreach ( $settings as $redirect_key => $redirect_value ) {
		$type = '';
		if ( ! empty( $redirect_value['type'] ) ) {

			switch ( $redirect_value['type'] ) {
				case '301':
					$type = '301';
					break;
				case '302':
					$type = '302';
					break;
				case '307':
					$type = '307';
					break;
				case '410':
					$type = '410';
					break;
				case '451':
					$type = '451';
					break;
				default:
					$param = '301';
			}
		}

		$source     = '';
		$raw_source = '';
		if ( ! empty( $redirect_value['source_url'] ) ) {
			$raw_source = (string) $redirect_value['source_url'];
			$source     = ltrim( urldecode( sanitize_text_field( $raw_source ) ), '/' );
		}

		$param = 'exact_match';
		if ( ! empty( $redirect_value['query_param'] ) ) {

			switch ( $redirect_value['query_param'] ) {
				case 'exact':
					$param = 'exact_match';
					break;
				case 'ignore':
					$param = 'without_param';
					break;
				case 'pass':
					$param = 'with_ignored_param';
					break;
				case 'utm':
					$param = 'with_ignored_param';
					break;
				default:
					$param = 'exact_match';
			}
		}

		$enabled = '';
		if ( ! empty( '1' === $redirect_value['enabled'] ) ) {
			$enabled = 'yes';
		}

		$redirect = '';
		if ( ! empty( $redirect_value['target_url'] ) ) {
			$redirect = urldecode( $redirect_value['target_url'] );
		}

		$count = '';

		$regex = '';
		if ( ! empty( '1' === $redirect_value['regex'] ) ) {
			$regex = 'yes';
		}

		$logged_status = 'both';
		if ( ! empty( $redirect_value['custom_rules'] ) ) {
			$custom_rules = json_decode( $redirect_value['custom_rules'], true );

			foreach ( $custom_rules as $rule_key => $rule_value ) {
				if ( $rule_value['type'] === 'login' ) {
					switch ( $rule_value['value'] ) {
						case 'loggedin':
							$logged_status = 'only_logged_in';
							break;
						case 'loggedout':
							$logged_status = 'only_not_logged_in';
							break;
					}
				}
			}
		}

		wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $raw_source, $source, 'yes' === $regex ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => $redirect,
					'_seopress_redirections_type'          => $type,
					'_seopress_redirections_enabled'       => $enabled,
					'_seopress_redirections_enabled_regex' => $regex,
					'_seopress_redirections_logged_status' => $logged_status,
					'seopress_404_count'                   => $count,
					'_seopress_redirections_param'         => $param,
				),
			)
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=redirects' ) );
	exit;
}
add_action( 'admin_init', 'seopress_import_aioseo_redirections' );

/**
 * Import Redirections from SmartCrawl plugin JSON file
 *
 * @return void
 */
function seopress_import_smartcrawl_redirections() {
	if ( empty( $_POST['seopress_action'] ) || 'import_smartcrawl_redirections' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_import_smartcrawl_redirections_nonce'], 'seopress_import_smartcrawl_redirections_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'import_settings' ) ) ) {
		return;
	}

	$extension = pathinfo( $_FILES['import_file']['name'], PATHINFO_EXTENSION );

	if ( 'json' != $extension ) {
		wp_die( esc_html__( 'Please upload a valid .json file', 'wp-seopress-pro' ) );
	}
	$import_file = $_FILES['import_file']['tmp_name'];
	if ( empty( $import_file ) ) {
		wp_die( esc_html__( 'Please upload a file to import', 'wp-seopress-pro' ) );
	}

	$settings = (array) json_decode( file_get_contents( $import_file ), true );

	foreach ( $settings as $redirect_key => $redirect_value ) {
		$type = '';
		if ( ! empty( $redirect_value['type'] ) ) {

			switch ( $redirect_value['type'] ) {
				case '301':
					$type = '301';
					break;
				case '302':
					$type = '302';
					break;
				case '307':
					$type = '307';
					break;
				case '410':
					$type = '410';
					break;
				case '451':
					$type = '451';
					break;
				default:
					$param = '301';
			}
		}

		$source     = '';
		$raw_source = '';
		if ( ! empty( $redirect_value['source'] ) ) {
			$raw_source = (string) $redirect_value['source'];
			$source     = sanitize_text_field( $raw_source );
			$source     = wp_parse_url( $source );
			if ( is_array( $source ) && isset( $source['path'] ) ) {
				$source = $source['path'];
			}

			$source = ltrim( rawurldecode( (string) $source ), '/' );
		}

		$param = 'exact_match';

		$enabled = 'yes';

		$redirect = '';
		if ( ! empty( $redirect_value['destination'] ) ) {
			if ( is_string( $redirect_value['destination'] ) ) {
				$redirect = rawurldecode( $redirect_value['destination'] );
			}
			if ( is_array( $redirect_value['destination'] ) && ! empty( $redirect_value['destination']['id'] ) ) {
				$redirect = esc_url( get_permalink( $redirect_value['destination']['id'] ) );
			}
		}

		$count = '';

		$regex = '';
		if ( isset( $redirect_value['options'][0] ) && 'regex' === $redirect_value['options'][0] ) {
			$regex = 'yes';
		}

		$logged_status = 'both';

		wp_insert_post(
			array(
				'post_title'  => seopress_import_redirection_origin( $raw_source, $source, 'yes' === $regex ),
				'post_type'   => 'seopress_404',
				'post_status' => 'publish',
				'meta_input'  => array(
					'_seopress_redirections_value'         => $redirect,
					'_seopress_redirections_type'          => $type,
					'_seopress_redirections_enabled'       => $enabled,
					'_seopress_redirections_enabled_regex' => $regex,
					'_seopress_redirections_logged_status' => $logged_status,
					'seopress_404_count'                   => $count,
					'_seopress_redirections_param'         => $param,
				),
			)
		);
	}

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=redirects' ) );
	exit;
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
	header( 'Content-Disposition: attachment; filename=seopress-404-export-' . date( 'm-d-Y' ) . '.csv' );
	header( 'Expires: 0' );
	echo $errors_404_html;
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
		header( 'Content-Disposition: attachment; filename=seopress-metadata-export-' . date( 'm-d-Y' ) . '.csv' );
		header( 'Expires: 0' );
		header( 'Pragma: public' );

		if ( ! empty( $csv ) ) {
			foreach ( $csv as $value ) {
				fputcsv( $output_handle, array_map( 'seopress_pro_escape_csv_value', (array) $value ), ';', '"', '\\' );
			}
		}

		// Close output file stream.
		fclose( $output_handle );

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
