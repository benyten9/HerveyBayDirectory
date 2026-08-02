<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Options Import / Export.
 *
 * @package SEOPress PRO
 * @subpackage Options
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Import / Exports settings page.
 *
 * @return void
 */
/**
 * Auto-detect the CSV separator by analyzing the first lines of the file.
 *
 * @param string $file_path Path to the CSV file.
 *
 * @return string Detected separator character (',' or ';').
 */
function seopress_detect_csv_separator( $file_path ) {
	$candidates = array(
		',' => 0,
		';' => 0,
	);
	$lines      = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( false === $lines || empty( $lines ) ) {
		return ',';
	}
	$sample_size = min( 5, count( $lines ) );

	for ( $i = 0; $i < $sample_size; $i++ ) {
		foreach ( $candidates as $sep => &$score ) {
			$cols = str_getcsv( $lines[ $i ], $sep, '"', '\\' );
			if ( count( $cols ) >= 3 ) {
				++$score;
			}
		}
		unset( $score );
	}

	return $candidates[';'] >= $candidates[','] ? ';' : ',';
}

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
		$csv_line = $value;

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
				'post_title'  => ltrim( rawurldecode( $csv_line[0] ), '/' ),
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
	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
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
				html_entity_decode( urldecode( esc_attr( wp_filter_nohtml_kses( get_the_title() ) ) ) ),
				html_entity_decode( urldecode( esc_attr( get_post_meta( get_the_ID(), '_seopress_redirections_value', true ) ) ) ),
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

			// Write row to CSV.
			fputcsv( $output, $row, ',', '"', '\\' );
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
		foreach ( $slug_changes as $slug ) {
			$slug_changes_csv .= html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( $slug['before_url'] ) ) ) ) );
			$slug_changes_csv .= ';';
			$slug_changes_csv .= html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( $slug['new_url'] ) ) ) ) );
			$slug_changes_csv .= ';';
			$slug_changes_csv .= esc_html( $slug['type'] );
			$slug_changes_csv .= "\n";
		}
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
				'post_title'  => ltrim( urldecode( $redirect_value['url'] ), '/' ),
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

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
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

		$source = '';
		if ( ! empty( $redirect_value['sources'] ) ) {
			if ( is_serialized( $redirect_value['sources'] ) ) {

				$source = @unserialize( sanitize_text_field( $redirect_value['sources'] ), array( 'allowed_classes' => false ) );

				if ( is_array( $source ) ) {
					$source = ltrim( urldecode( $source[0]['pattern'] ), '/' );
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
				'post_title'  => $source,
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

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
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
					'post_title'  => ltrim( urldecode( $pattern ), '/' ),
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

		$source      = ltrim( urldecode( $get( $row, 'from' ) ), '/' );
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
				'post_title'  => $source,
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

		$source = '';
		if ( ! empty( $redirect_value['source_url'] ) ) {
			$source = sanitize_text_field( $redirect_value['source_url'] );
			$source = ltrim( urldecode( $source ), '/' );
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
				'post_title'  => $source,
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

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
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

		$source = '';
		if ( ! empty( $redirect_value['source'] ) ) {
			$source = sanitize_text_field( $redirect_value['source'] );
			$source = wp_parse_url( $source );
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
				'post_title'  => $source,
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

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
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
		while ( $seopress_404_query->have_posts() ) {
			$seopress_404_query->the_post();

			$errors_404_html .= html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( get_the_title() ) ) ) ) );
			$errors_404_html .= ';';
			$errors_404_html .= esc_html( get_post_meta( get_the_ID(), 'seopress_404_count', true ) );
			$errors_404_html .= ';';
			$errors_404_html .= html_entity_decode( urldecode( urlencode( esc_attr( wp_filter_nohtml_kses( ( get_post_meta( get_the_ID(), 'seopress_redirections_referer', true ) ) ) ) ) ) );
			$errors_404_html .= "\n";
		}
		wp_reset_postdata();
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
	if ( empty( $_POST['seopress_action'] ) || 'clean_404' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_clean_404_nonce'], 'seopress_clean_404_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', '404' ) ) ) {
		return;
	}

	add_filter( 'seopress_404_cleaning_query', 'seopress_clean_404_query_hook' );
	do_action( 'seopress_404_cron_cleaning', true );
	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections&view=404' ) );
	exit;
}
add_action( 'admin_init', 'seopress_clean_404' );

/**
 * Reset Count column.
 *
 * @return void
 */
function seopress_clean_counters() {
	if ( empty( $_POST['seopress_action'] ) || 'clean_counters' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_clean_counters_nonce'], 'seopress_clean_counters_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', '404' ) ) ) {
		return;
	}

	global $wpdb;

	// SQL query.
	$sql = 'DELETE  FROM `' . $wpdb->prefix . 'postmeta` WHERE `meta_key` = \'seopress_404_count\'';

	$sql = $wpdb->prepare( $sql );

	$wpdb->query( $sql );

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
	exit;
}
add_action( 'admin_init', 'seopress_clean_counters' );

/**
 * Clean all (redirects / 404 errors).
 *
 * @return void
 */
function seopress_clean_all() {
	if ( empty( $_POST['seopress_action'] ) || 'clean_all' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_clean_all_nonce'], 'seopress_clean_all_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', '404' ) ) ) {
		return;
	}

	global $wpdb;

	// SQL query.
	$sql = 'DELETE `posts`, `pm`
		FROM `' . $wpdb->prefix . 'posts` AS `posts`
		LEFT JOIN `' . $wpdb->prefix . 'postmeta` AS `pm` ON `pm`.`post_id` = `posts`.`ID`
		WHERE `posts`.`post_type` = \'seopress_404\'';

	$sql = $wpdb->prepare( $sql );

	$wpdb->query( $sql );

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-redirections' ) );
	exit;
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
				fputcsv( $output_handle, $value, ';', '"', '\\' );
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
	if ( empty( $_POST['seopress_action'] ) || 'clean_audit_scans' != $_POST['seopress_action'] ) {
		return;
	}
	if ( ! wp_verify_nonce( $_POST['seopress_clean_audit_scans_nonce'], 'seopress_clean_audit_scans_nonce' ) ) {
		return;
	}
	if ( ! current_user_can( seopress_capability( 'manage_options', 'cleaning' ) ) ) {
		return;
	}

	global $wpdb;

	// Clean custom table if it exists.
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}seopress_seo_issues'" ) === $wpdb->prefix . 'seopress_seo_issues' ) {
		$sql = 'DELETE FROM `' . $wpdb->prefix . 'seopress_seo_issues`';
		$sql = $wpdb->prepare( $sql );
		$wpdb->query( $sql );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=seopress-import-export' ) );
	exit;
}
add_action( 'admin_init', 'seopress_clean_audit_scans' );
