<?php
/**
 * HBL Place ID Manager Tool
 *
 * Site tool under Directorist Tools. Two modes:
 *
 * MODE A — Manual entry
 *   Lists all Directorist listings that are missing a Place ID.
 *   Each row has a "Find on Google Maps" link (free, no API key) and an
 *   inline input. Admin pastes the Place ID, clicks Save Row or selects
 *   several and clicks Apply Selected.
 *
 * MODE B — Import from Excel / CSV
 *   Admin uploads an XLSX or CSV file that already contains Place IDs
 *   (columns: Business Name, Category, Place ID).  The tool matches each
 *   row to a Directorist listing by name (with category as a tiebreaker)
 *   and shows a review table before writing anything.
 *
 *   Matching levels:
 *     1. Exact      – name matches exactly (case-insensitive)
 *     2. Normalised – matches after stripping punctuation / common suffixes
 *     3. Partial    – one name contains the other
 *     4. No match   – nothing found (row shown but not selectable)
 *
 *   Parsing is done with built-in PHP extensions only (ZipArchive +
 *   SimpleXML for XLSX, fgetcsv for CSV).  No third-party libraries needed.
 *
 * Place ID meta key defaults to `_place_id`. Change it in Settings to match
 * the field key you defined in the Directorist directory builder.
 *
 * @package HBL
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Place_ID {

	const OPTION_KEY    = 'hbl_place_id_settings';
	const IMPORT_EXPIRY = 7200; // 2 hours

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'add_admin_menu' ), 35 );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Mode A: manual
		add_action( 'wp_ajax_hbl_place_id_count_missing',  array( $this, 'ajax_count_missing' ) );
		add_action( 'wp_ajax_hbl_place_id_load_page',      array( $this, 'ajax_load_page' ) );
		add_action( 'wp_ajax_hbl_place_id_save_selected',  array( $this, 'ajax_save_selected' ) );

		// Mode B: import
		add_action( 'wp_ajax_hbl_place_id_upload',         array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_hbl_place_id_match_all',      array( $this, 'ajax_match_all' ) );
		add_action( 'wp_ajax_hbl_place_id_import_apply',   array( $this, 'ajax_import_apply' ) );

		// Auto-find (Google Maps scrape)
		add_action( 'wp_ajax_hbl_pid_auto_find',           array( $this, 'ajax_auto_find' ) );
	}

	// ── Menu & assets ─────────────────────────────────────────────────────────

	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Place ID Manager', 'hbl' ),
			__( 'Place ID Manager', 'hbl' ),
			'manage_options',
			'hbl-place-id',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-place-id' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style( 'hbl-bulk-reassign', HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css', array( 'hbl-admin-font-poppins' ), HBL_VERSION );
		wp_enqueue_style( 'hbl-place-id',      HBL_THEME_URI . '/inc/admin/css/place-id.css',      array( 'hbl-bulk-reassign' ), HBL_VERSION );
	}

	public function register_settings() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		register_setting( 'hbl_place_id', self::OPTION_KEY, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	private function get_settings(): array {
		return array_merge(
			array( 'place_id_key' => '_place_id', 'per_page' => 25 ),
			(array) get_option( self::OPTION_KEY, array() )
		);
	}

	public function sanitize_settings( $input ): array {
		return array(
			'place_id_key' => sanitize_text_field( $input['place_id_key'] ?? '_place_id' ),
			'per_page'     => max( 10, min( 100, (int) ( $input['per_page'] ?? 25 ) ) ),
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// MODE A — MANUAL ENTRY HELPERS
	// ══════════════════════════════════════════════════════════════════════════

	private function count_missing( string $meta_key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')",
			$meta_key
		) );
	}

	private function get_page( string $meta_key, int $page, int $per_page, string $search ): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;

		if ( $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
				   AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
				   AND p.post_title LIKE %s
				 ORDER BY p.post_title ASC
				 LIMIT %d OFFSET %d",
				$meta_key,
				'%' . $wpdb->esc_like( $search ) . '%',
				$per_page,
				$offset
			) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
				 WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
				   AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
				 ORDER BY p.post_title ASC
				 LIMIT %d OFFSET %d",
				$meta_key,
				$per_page,
				$offset
			) );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row->ID;
			$address = (string) get_post_meta( $id, '_address', true );
			if ( ! $address ) {
				$address = trim( get_post_meta( $id, '_city', true ) . ' ' . get_post_meta( $id, '_state', true ) );
			}
			$items[] = array(
				'id'       => $id,
				'title'    => $row->post_title,
				'address'  => $address,
				'edit_url' => get_edit_post_link( $id, 'raw' ) ?: '',
				'maps_url' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $row->post_title . ' ' . $address ),
			);
		}
		return $items;
	}

	private function count_page( string $meta_key, string $search ): int {
		global $wpdb;
		if ( ! $search ) {
			return $this->count_missing( $meta_key );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
			 WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
			   AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
			   AND p.post_title LIKE %s",
			$meta_key, '%' . $wpdb->esc_like( $search ) . '%'
		) );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// MODE B — FILE PARSING HELPERS
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Parses an XLSX file into a 2-D array of strings.
	 * Uses only built-in PHP extensions: ZipArchive + SimpleXML.
	 *
	 * @return array{rows: array[]|null, error: string}
	 */
	private function parse_xlsx( string $path ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'rows' => null, 'error' => 'The ZipArchive PHP extension is not available on this server.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array( 'rows' => null, 'error' => 'Could not open the XLSX file (is it a valid Excel file?).' );
		}

		// ── Shared strings ──
		$shared = array();
		$raw    = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( $raw ) {
			// Suppress namespace warnings common in some XLSX exports.
			$doc = @simplexml_load_string( $raw );
			if ( $doc ) {
				foreach ( $doc->si as $si ) {
					if ( isset( $si->t ) ) {
						$shared[] = (string) $si->t;
					} else {
						$text = '';
						foreach ( $si->r as $r ) {
							if ( isset( $r->t ) ) {
								$text .= (string) $r->t;
							}
						}
						$shared[] = $text;
					}
				}
			}
		}

		// ── First sheet ──
		$sheet_raw = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();

		if ( ! $sheet_raw ) {
			return array( 'rows' => null, 'error' => 'Could not read Sheet 1. Ensure data is on the first worksheet.' );
		}

		$sheet = @simplexml_load_string( $sheet_raw );
		if ( ! $sheet ) {
			return array( 'rows' => null, 'error' => 'Could not parse the worksheet XML.' );
		}

		$sparse    = array();
		$max_cols  = 0;

		foreach ( $sheet->sheetData->row as $row_el ) {
			$row_idx  = (int) $row_el['r'] - 1;
			$row_data = array();

			foreach ( $row_el->c as $cell ) {
				$ref  = (string) $cell['r'];
				$type = (string) $cell['t'];
				$col  = $this->col_letter_to_index( (string) preg_replace( '/[0-9]/', '', $ref ) );

				if ( 's' === $type ) {
					$idx          = isset( $cell->v ) ? (int) (string) $cell->v : 0;
					$row_data[ $col ] = $shared[ $idx ] ?? '';
				} elseif ( 'inlineStr' === $type ) {
					$row_data[ $col ] = isset( $cell->is->t ) ? (string) $cell->is->t : '';
				} else {
					$row_data[ $col ] = isset( $cell->v ) ? (string) $cell->v : '';
				}

				if ( $col + 1 > $max_cols ) {
					$max_cols = $col + 1;
				}
			}

			$sparse[ $row_idx ] = $row_data;
		}

		ksort( $sparse );
		$rows = array();
		foreach ( $sparse as $row_data ) {
			$dense = array();
			for ( $c = 0; $c < $max_cols; $c++ ) {
				$dense[] = $row_data[ $c ] ?? '';
			}
			$rows[] = $dense;
		}

		return array( 'rows' => $rows, 'error' => '' );
	}

	/** Converts a spreadsheet column letter (A, B, … Z, AA, AB …) to a 0-based index. */
	private function col_letter_to_index( string $col ): int {
		$col    = strtoupper( $col );
		$result = 0;
		for ( $i = 0, $len = strlen( $col ); $i < $len; $i++ ) {
			$result = $result * 26 + ( ord( $col[ $i ] ) - ord( 'A' ) + 1 );
		}
		return max( 0, $result - 1 );
	}

	/** Parses a CSV file into a 2-D array of strings. */
	private function parse_csv( string $path ): array {
		$handle = @fopen( $path, 'r' );
		if ( ! $handle ) {
			return array( 'rows' => null, 'error' => 'Could not open the CSV file.' );
		}

		$rows = array();
		while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
			$rows[] = array_map( 'trim', $row );
		}
		fclose( $handle );

		return array( 'rows' => $rows, 'error' => '' );
	}

	// ── Matching ──────────────────────────────────────────────────────────────

	/** Normalise a business name for fuzzy matching. */
	private function normalise( string $name ): string {
		$name = strtolower( trim( $name ) );
		// Remove punctuation.
		$name = preg_replace( '/[^\w\s]/', ' ', $name );
		// Strip common legal suffixes.
		$name = preg_replace( '/\b(pty\s*ltd|pty|ltd|limited|inc|llc|co|and|the|&|group|australia|aus)\b/', ' ', $name );
		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Loads all published listings with their categories into a lookup structure:
	 *   [
	 *     'by_exact'  => [ 'lowercase title' => [ {ID, post_title, categories}, … ] ],
	 *     'by_norm'   => [ 'normalised title' => [ … ] ],
	 *     'all'       => [ {ID, post_title, categories}, … ],
	 *   ]
	 */
	private function build_listing_index(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_title,
			        GROUP_CONCAT(DISTINCT LOWER(t.name) ORDER BY t.name SEPARATOR '|') AS categories
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->term_relationships} tr  ON tr.object_id          = p.ID
			 LEFT JOIN {$wpdb->term_taxonomy}      tt  ON tt.term_taxonomy_id   = tr.term_taxonomy_id
			                                           AND tt.taxonomy           = 'at_biz_dir-category'
			 LEFT JOIN {$wpdb->terms}              t   ON t.term_id             = tt.term_id
			 WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
			 GROUP BY p.ID
			 ORDER BY p.post_title ASC"
		);

		$by_exact = array();
		$by_norm  = array();

		foreach ( $rows as $r ) {
			$exact_key = strtolower( trim( $r->post_title ) );
			$norm_key  = $this->normalise( $r->post_title );

			$by_exact[ $exact_key ][] = $r;

			if ( $norm_key && $norm_key !== $exact_key ) {
				$by_norm[ $norm_key ][] = $r;
			}
		}

		return array(
			'by_exact' => $by_exact,
			'by_norm'  => $by_norm,
			'all'      => $rows,
		);
	}

	/**
	 * Finds the best Directorist listing match for a given business name + category.
	 *
	 * @return array{listing_id: int, listing_title: string, match_type: string}|null
	 */
	private function find_match( array $index, string $name, string $category ): ?array {
		$exact_key    = strtolower( trim( $name ) );
		$norm_key     = $this->normalise( $name );
		$cat_filter   = $this->normalise( $category );
		$candidates   = null;
		$match_type   = '';

		// 1. Exact.
		if ( isset( $index['by_exact'][ $exact_key ] ) ) {
			$candidates = $index['by_exact'][ $exact_key ];
			$match_type = 'exact';
		}

		// 2. Normalised.
		if ( ! $candidates && $norm_key && isset( $index['by_norm'][ $norm_key ] ) ) {
			$candidates = $index['by_norm'][ $norm_key ];
			$match_type = 'normalised';
		}

		// 3. Partial: one name contains the other.
		if ( ! $candidates ) {
			foreach ( $index['all'] as $r ) {
				$listing_lower = strtolower( $r->post_title );
				if ( false !== strpos( $listing_lower, $exact_key ) || false !== strpos( $exact_key, $listing_lower ) ) {
					$candidates[] = $r;
				}
			}
			if ( $candidates ) {
				$match_type = 'partial';
			}
		}

		if ( ! $candidates ) {
			return null;
		}

		// If multiple candidates and we have a category, prefer the one whose
		// category list contains the filter category.
		if ( count( $candidates ) > 1 && $cat_filter ) {
			$filtered = array_filter( $candidates, function ( $r ) use ( $cat_filter ) {
				return false !== strpos( (string) $r->categories, $cat_filter );
			} );
			if ( ! empty( $filtered ) ) {
				$candidates = array_values( $filtered );
			}
		}

		$winner = $candidates[0];

		return array(
			'listing_id'    => (int) $winner->ID,
			'listing_title' => $winner->post_title,
			'match_type'    => $match_type,
			'ambiguous'     => count( $candidates ) > 1,
		);
	}

	// ══════════════════════════════════════════════════════════════════════════
	// AJAX HANDLERS — MODE A
	// ══════════════════════════════════════════════════════════════════════════

	public function ajax_count_missing(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }
		$s = $this->get_settings();
		wp_send_json_success( array( 'count' => $this->count_missing( $s['place_id_key'] ) ) );
	}

	public function ajax_load_page(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		$s        = $this->get_settings();
		$page     = max( 1, (int) ( $_POST['page']   ?? 1 ) );
		$search   = sanitize_text_field( $_POST['search'] ?? '' );
		$per_page = $s['per_page'];
		$total    = $this->count_page( $s['place_id_key'], $search );
		$items    = $this->get_page( $s['place_id_key'], $page, $per_page, $search );

		wp_send_json_success( array(
			'items'     => $items,
			'total'     => $total,
			'page'      => $page,
			'max_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
			'per_page'  => $per_page,
		) );
	}

	public function ajax_save_selected(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		$s         = $this->get_settings();
		$meta_key  = $s['place_id_key'];
		$items     = json_decode( wp_unslash( $_POST['items'] ?? '' ), true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items provided.' ); return;
		}

		$saved = 0; $failed = 0; $saved_ids = array();
		foreach ( $items as $item ) {
			$id  = (int) ( $item['listing_id'] ?? 0 );
			$pid = sanitize_text_field( $item['place_id'] ?? '' );
			if ( ! $id || ! $pid ) { $failed++; continue; }
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'at_biz_dir' ) { $failed++; continue; }
			update_post_meta( $id, $meta_key, $pid );
			$saved++;
			$saved_ids[] = $id;
		}
		wp_send_json_success( array( 'saved' => $saved, 'failed' => $failed, 'saved_ids' => $saved_ids ) );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// AJAX HANDLERS — MODE B
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Receives the uploaded file, parses it, stores rows in a transient,
	 * and returns column headers + a preview of the first 5 rows.
	 */
	public function ajax_upload(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( 'No file received.' ); return;
		}

		$file = $_FILES['file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( 'Upload error code: ' . $file['error'] ); return;
		}

		if ( $file['size'] > 20 * 1024 * 1024 ) {
			wp_send_json_error( 'File too large (max 20 MB).' ); return;
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'xlsx', 'csv' ), true ) ) {
			wp_send_json_error( 'Only .xlsx and .csv files are supported. Please re-save your Excel file as one of those formats.' ); return;
		}

		$tmp = $file['tmp_name'];

		$result = ( 'xlsx' === $ext )
			? $this->parse_xlsx( $tmp )
			: $this->parse_csv( $tmp );

		if ( $result['error'] || empty( $result['rows'] ) ) {
			wp_send_json_error( $result['error'] ?: 'The file appears to be empty.' ); return;
		}

		$rows = $result['rows'];

		// Limit to 20 000 rows to keep memory sensible.
		if ( count( $rows ) > 20000 ) {
			wp_send_json_error( 'File contains more than 20,000 rows. Please split it into smaller batches.' ); return;
		}

		// Generate a unique token for this upload session.
		$token = wp_generate_password( 16, false );

		set_transient( 'hbl_pid_rows_' . $token, $rows, self::IMPORT_EXPIRY );

		$headers = $rows[0] ?? array();
		$preview = array_slice( $rows, 0, 6 ); // header + first 5 data rows

		wp_send_json_success( array(
			'token'      => $token,
			'total_rows' => count( $rows ) - 1, // subtract header row
			'headers'    => $headers,
			'preview'    => $preview,
		) );
	}

	/**
	 * Matches all uploaded rows against Directorist listings and returns
	 * the results. Runs in a single AJAX call (loads all listings once into
	 * memory, then matches in PHP — fast even at 10k+ rows).
	 */
	public function ajax_match_all(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		$token      = sanitize_text_field( $_POST['token']        ?? '' );
		$name_col   = (int) ( $_POST['name_col']   ?? 0 );
		$cat_col    = (int) ( $_POST['cat_col']    ?? -1 ); // -1 = not mapped
		$pid_col    = (int) ( $_POST['pid_col']    ?? 0 );
		$has_header = ! empty( $_POST['has_header'] ) && 'true' === $_POST['has_header'];

		if ( ! $token ) {
			wp_send_json_error( 'No upload token. Please upload a file first.' ); return;
		}

		$rows = get_transient( 'hbl_pid_rows_' . $token );
		if ( ! is_array( $rows ) ) {
			wp_send_json_error( 'Upload session expired or not found. Please upload the file again.' ); return;
		}

		if ( $has_header ) {
			$rows = array_slice( $rows, 1 );
		}

		// Build listing index (loaded once).
		$index = $this->build_listing_index();

		$results   = array();
		$found     = 0;
		$not_found = 0;

		foreach ( $rows as $i => $row ) {
			$name     = trim( (string) ( $row[ $name_col ] ?? '' ) );
			$category = $cat_col >= 0 ? trim( (string) ( $row[ $cat_col ] ?? '' ) ) : '';
			$place_id = trim( (string) ( $row[ $pid_col ] ?? '' ) );

			if ( ! $name || ! $place_id ) {
				continue; // Skip blank rows.
			}

			$match = $this->find_match( $index, $name, $category );

			if ( $match ) {
				$found++;
				$results[] = array(
					'row'           => $i,
					'excel_name'    => $name,
					'excel_cat'     => $category,
					'place_id'      => $place_id,
					'listing_id'    => $match['listing_id'],
					'listing_title' => $match['listing_title'],
					'edit_url'      => get_edit_post_link( $match['listing_id'], 'raw' ) ?: '',
					'match_type'    => $match['match_type'],
					'ambiguous'     => $match['ambiguous'],
				);
			} else {
				$not_found++;
				$results[] = array(
					'row'           => $i,
					'excel_name'    => $name,
					'excel_cat'     => $category,
					'place_id'      => $place_id,
					'listing_id'    => 0,
					'listing_title' => '',
					'edit_url'      => '',
					'match_type'    => 'none',
					'ambiguous'     => false,
				);
			}
		}

		// Store results so the client can page through them.
		set_transient( 'hbl_pid_results_' . $token, $results, self::IMPORT_EXPIRY );

		wp_send_json_success( array(
			'total'     => count( $results ),
			'found'     => $found,
			'not_found' => $not_found,
			'results'   => $results,     // all rows — client paginates in JS
		) );
	}

	/** Writes approved Place IDs from the import to their matched listings. */
	public function ajax_import_apply(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		$s         = $this->get_settings();
		$meta_key  = $s['place_id_key'];
		$items     = json_decode( wp_unslash( $_POST['items'] ?? '' ), true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items provided.' ); return;
		}

		$applied = 0; $failed = 0; $applied_ids = array();

		foreach ( $items as $item ) {
			$id  = (int) ( $item['listing_id'] ?? 0 );
			$pid = sanitize_text_field( $item['place_id'] ?? '' );
			if ( ! $id || ! $pid ) { $failed++; continue; }
			$post = get_post( $id );
			if ( ! $post || $post->post_type !== 'at_biz_dir' ) { $failed++; continue; }
			update_post_meta( $id, $meta_key, $pid );
			$applied++;
			$applied_ids[] = $id;
		}

		wp_send_json_success( array( 'applied' => $applied, 'failed' => $failed, 'applied_ids' => $applied_ids ) );
	}

	// ── Auto-find Place ID (Google Maps scrape) ──────────────────────────────

	public function ajax_auto_find(): void {
		check_ajax_referer( 'hbl_place_id_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized.' ); return; }

		$listing_id = (int) ( $_POST['listing_id'] ?? 0 );
		if ( ! $listing_id ) { wp_send_json_error( 'Invalid listing ID.' ); return; }

		$post = get_post( $listing_id );
		if ( ! $post || 'at_biz_dir' !== $post->post_type ) {
			wp_send_json_error( 'Listing not found.' ); return;
		}

		$title   = get_the_title( $post );
		$address = implode( ', ', array_filter( array(
			get_post_meta( $listing_id, '_address', true ),
			get_post_meta( $listing_id, '_city',    true ),
			get_post_meta( $listing_id, '_state',   true ),
		) ) );

		$result = $this->scrape_place_id( $title, $address );

		if ( ! $result['ok'] ) {
			wp_send_json_error( $result['error'] ); return;
		}

		wp_send_json_success( array( 'pid' => $result['pid'], 'title' => $title ) );
	}

	/**
	 * Finds a Google Place ID for a business by querying Google's internal
	 * map-search endpoint (the one Maps itself uses), falling back to the
	 * public Maps search page. Tries progressively broader queries: full
	 * address first, then city/state, then business name alone.
	 *
	 * @return array{ok: bool, pid: string, error: string}
	 */
	private function scrape_place_id( string $title, string $address ): array {
		$title = trim( $title );
		if ( '' === $title ) {
			return array( 'ok' => false, 'pid' => '', 'error' => 'Listing has no title.' );
		}

		// Progressively broader query variants.
		$queries = array();
		if ( $address ) {
			$queries[] = $title . ', ' . $address;
			// City/state only (drop the street address — often mismatched).
			$parts = array_map( 'trim', explode( ',', $address ) );
			if ( count( $parts ) > 1 ) {
				$queries[] = $title . ', ' . implode( ', ', array_slice( $parts, -2 ) );
			}
		}
		$queries[] = $title . ', Australia';
		$queries   = array_values( array_unique( $queries ) );

		$last_error = 'Place ID not found in Google Maps results. Try another listing or verify the business name.';

		foreach ( $queries as $query ) {
			$encoded = rawurlencode( $query );

			// Endpoint 1: Google's internal map-search endpoint. Returns the
			// raw result payload (no JS rendering) containing ChIJ Place IDs.
			// Endpoint 2: public Maps search page as fallback.
			$urls = array(
				'https://www.google.com/search?tbm=map&tch=1&hl=en&gl=au&q=' . $encoded,
				'https://www.google.com/maps/search/' . $encoded . '?hl=en&gl=au',
			);

			foreach ( $urls as $url ) {
				$response = wp_remote_get( $url, array(
					'timeout'     => 15,
					'redirection' => 5,
					'headers'     => array(
						'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
						'Accept-Language' => 'en-US,en;q=0.9',
						'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
						// Pre-set consent cookies so Google doesn't serve the
						// EU consent interstitial instead of results.
						'Cookie'          => 'CONSENT=YES+cb.20210720-07-p0.en+FX+410; SOCS=CAI',
					),
				) );

				if ( is_wp_error( $response ) ) {
					$last_error = $response->get_error_message();
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 !== $code ) {
					$last_error = "Google returned HTTP {$code}." . ( 429 === $code ? ' Rate-limited — wait a few minutes and try again.' : '' );
					continue;
				}

				$body = wp_remote_retrieve_body( $response );

				if ( false !== stripos( $body, 'recaptcha' ) && false !== stripos( $body, 'unusual traffic' ) ) {
					$last_error = 'Google is showing a CAPTCHA to this server. Wait a while and try again.';
					continue;
				}

				// Prefer the !1s-prefixed match (the selected place), then any
				// ChIJ token in the payload (first result).
				if ( preg_match( '/!1s(ChIJ[A-Za-z0-9_\-]{20,})/', $body, $m )
					|| preg_match( '/(ChIJ[A-Za-z0-9_\-]{20,})/', $body, $m ) ) {
					return array( 'ok' => true, 'pid' => $m[1], 'error' => '' );
				}
			}
		}

		return array( 'ok' => false, 'pid' => '', 'error' => $last_error );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ADMIN PAGE
	// ══════════════════════════════════════════════════════════════════════════

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->get_settings();
		$nonce = wp_create_nonce( 'hbl_place_id_nonce' );
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-pid-wrap">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px">
					<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
					<circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.8" fill="none"/>
				</svg>
				<?php esc_html_e( 'Place ID Manager', 'hbl' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Assign Google Place IDs to listings — manually or by importing an Excel / CSV file.', 'hbl' ); ?>
			</p>

			<!-- Stats -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number" id="hbl-pid-stat-missing">–</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Missing Place IDs', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--success">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number" id="hbl-pid-stat-saved">0</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Saved this session', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M20.59 13.41L13.42 20.58a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="7" r="1.2" fill="currentColor"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number">
							<code style="font-size:12px;background:#F3F4F6;padding:2px 6px;border-radius:4px"><?php echo esc_html( $settings['place_id_key'] ); ?></code>
						</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Meta key', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Mode tabs -->
			<div class="hbl-pid-tabs">
				<button type="button" class="hbl-pid-tab hbl-pid-tab--active" data-tab="manual">
					<?php esc_html_e( 'Manual Entry', 'hbl' ); ?>
				</button>
				<button type="button" class="hbl-pid-tab" data-tab="import">
					<?php esc_html_e( 'Import from Excel / CSV', 'hbl' ); ?>
				</button>
			</div>

			<div class="hbl-reassign-container">

				<!-- ════════════════════════════════════════════
				     TAB: MANUAL ENTRY
				     ════════════════════════════════════════════ -->
				<div id="hbl-pid-tab-manual">

					<!-- Settings -->
					<form method="post" action="options.php">
						<?php settings_fields( 'hbl_place_id' ); ?>
						<div class="hbl-reassign-step">
							<div class="hbl-step-header">
								<span class="hbl-step-number">1</span>
								<h2><?php esc_html_e( 'Settings', 'hbl' ); ?></h2>
							</div>
							<div class="hbl-pid-field-inline">
								<div class="hbl-pid-field">
									<label for="hbl-pid-meta-key"><?php esc_html_e( 'Place ID meta key', 'hbl' ); ?></label>
									<input type="text" id="hbl-pid-meta-key"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[place_id_key]"
										value="<?php echo esc_attr( $settings['place_id_key'] ); ?>"
										class="hbl-input hbl-pid-mono" style="max-width:180px" placeholder="_place_id">
									<p class="description"><?php esc_html_e( 'Must match the field key in your Directorist builder.', 'hbl' ); ?></p>
								</div>
								<div class="hbl-pid-field">
									<label for="hbl-pid-per-page"><?php esc_html_e( 'Rows per page', 'hbl' ); ?></label>
									<input type="number" id="hbl-pid-per-page"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[per_page]"
										value="<?php echo esc_attr( (string) $settings['per_page'] ); ?>"
										class="hbl-input" style="max-width:80px" min="10" max="100">
								</div>
							</div>
							<?php submit_button( __( 'Save Settings', 'hbl' ), 'primary', 'submit', false ); ?>
						</div>
					</form>

					<!-- Listings table -->
					<div class="hbl-reassign-step">
						<div class="hbl-step-header" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
							<div style="display:flex;align-items:center;gap:14px">
								<span class="hbl-step-number">2</span>
								<h2><?php esc_html_e( 'Listings without Place IDs', 'hbl' ); ?></h2>
							</div>
							<button type="button" id="hbl-pid-apply-btn" class="hbl-pid-btn hbl-pid-btn--apply" style="display:none" disabled>
								<svg class="hbl-pid-btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
								<span class="hbl-pid-btn-label"><?php esc_html_e( 'Apply Selected', 'hbl' ); ?> (<span id="hbl-pid-apply-count">0</span>)</span>
							</button>
						</div>

						<div class="hbl-pid-toolbar">
							<div class="hbl-pid-search-wrap">
								<input type="text" id="hbl-pid-search" class="hbl-input" placeholder="<?php esc_attr_e( 'Search by name…', 'hbl' ); ?>">
								<button type="button" id="hbl-pid-search-btn" class="hbl-pid-btn"><?php esc_html_e( 'Search', 'hbl' ); ?></button>
								<button type="button" id="hbl-pid-reset-btn" class="hbl-pid-btn" style="display:none"><?php esc_html_e( 'Clear', 'hbl' ); ?></button>
								<button type="button" id="hbl-pid-claude-all-btn" class="hbl-pid-btn hbl-pid-btn--find-all">
									<svg class="hbl-pid-btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
									<span class="hbl-pid-btn-label"><?php esc_html_e( 'Auto Find All', 'hbl' ); ?></span>
								</button>
							</div>
							<div id="hbl-pid-pagination"></div>
						</div>

						<label class="hbl-pid-mobile-selectall">
							<input type="checkbox" id="hbl-pid-select-all-mobile">
							<span><?php esc_html_e( 'Select All', 'hbl' ); ?></span>
						</label>

						<div class="hbl-table-card">
							<table class="hbl-pid-log-table" id="hbl-pid-table">
								<thead>
									<tr>
										<th style="width:36px;text-align:center"><input type="checkbox" id="hbl-pid-select-all" title="<?php esc_attr_e( 'Select all', 'hbl' ); ?>"></th>
										<th style="width:36px">#</th>
										<th><?php esc_html_e( 'Business Name', 'hbl' ); ?></th>
										<th style="width:200px"><?php esc_html_e( 'Address', 'hbl' ); ?></th>
										<th style="width:210px"><?php esc_html_e( 'Place ID', 'hbl' ); ?></th>
										<th style="width:220px"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
									</tr>
								</thead>
								<tbody id="hbl-pid-tbody">
									<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280"><?php esc_html_e( 'Loading…', 'hbl' ); ?></td></tr>
								</tbody>
							</table>
						</div>
					</div>
				</div><!-- #hbl-pid-tab-manual -->

				<!-- ════════════════════════════════════════════
				     TAB: IMPORT
				     ════════════════════════════════════════════ -->
				<div id="hbl-pid-tab-import" style="display:none">

					<!-- Upload -->
					<div class="hbl-reassign-step">
						<div class="hbl-step-header">
							<span class="hbl-step-number">1</span>
							<h2><?php esc_html_e( 'Upload your Excel or CSV file', 'hbl' ); ?></h2>
						</div>
						<p class="description" style="margin-bottom:16px">
							<?php esc_html_e( 'The file should have columns for Business Name, Category, and Place ID (already known). Supports .xlsx and .csv — no API key needed.', 'hbl' ); ?>
						</p>

						<div class="hbl-pid-dropzone" id="hbl-pid-dropzone">
							<svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
							<p><?php esc_html_e( 'Drag & drop your file here, or', 'hbl' ); ?></p>
							<label class="button button-secondary hbl-pid-file-label">
								<?php esc_html_e( 'Choose File', 'hbl' ); ?>
								<input type="file" id="hbl-pid-file-input" accept=".xlsx,.csv" style="display:none">
							</label>
							<p class="description" style="margin-top:8px;font-size:12px"><?php esc_html_e( '.xlsx or .csv · max 20 MB · up to 20,000 rows', 'hbl' ); ?></p>
						</div>

						<div id="hbl-pid-upload-status" style="display:none;margin-top:12px"></div>
					</div>

					<!-- Column mapping (shown after upload) -->
					<div class="hbl-reassign-step" id="hbl-pid-mapping-step" style="display:none">
						<div class="hbl-step-header">
							<span class="hbl-step-number">2</span>
							<h2><?php esc_html_e( 'Map columns', 'hbl' ); ?></h2>
						</div>

						<div class="hbl-pid-mapping-grid" id="hbl-pid-mapping-grid">
							<!-- Populated by JS -->
						</div>

						<div id="hbl-pid-preview-table-wrap" style="overflow-x:auto;margin-top:16px">
							<!-- Preview table populated by JS -->
						</div>

						<div style="margin-top:16px;display:flex;align-items:center;gap:12px">
							<label style="display:flex;align-items:center;gap:6px;font-size:13px">
								<input type="checkbox" id="hbl-pid-has-header" checked>
								<?php esc_html_e( 'First row is a header', 'hbl' ); ?>
							</label>
							<button type="button" id="hbl-pid-match-btn" class="button button-primary">
								<?php esc_html_e( 'Match Listings →', 'hbl' ); ?>
							</button>
							<span id="hbl-pid-match-status" style="font-size:13px;color:#6B7280"></span>
						</div>
					</div>

					<!-- Match results -->
					<div class="hbl-reassign-step" id="hbl-pid-results-step" style="display:none">
						<div class="hbl-step-header" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
							<div style="display:flex;align-items:center;gap:14px">
								<span class="hbl-step-number">3</span>
								<h2><?php esc_html_e( 'Review & Apply', 'hbl' ); ?></h2>
							</div>
							<div style="display:flex;align-items:center;gap:8px">
								<span id="hbl-pid-import-summary" style="font-size:13px;color:#6B7280"></span>
								<button type="button" id="hbl-pid-import-apply-btn" class="button button-primary" disabled>
									<?php esc_html_e( 'Apply Selected', 'hbl' ); ?> (<span id="hbl-pid-import-apply-count">0</span>)
								</button>
							</div>
						</div>

						<!-- Filter chips -->
						<div class="hbl-pid-filter-chips" id="hbl-pid-filter-chips">
							<button type="button" class="hbl-pid-chip hbl-pid-chip--active" data-filter="all"><?php esc_html_e( 'All', 'hbl' ); ?></button>
							<button type="button" class="hbl-pid-chip" data-filter="exact"><?php esc_html_e( 'Exact', 'hbl' ); ?></button>
							<button type="button" class="hbl-pid-chip" data-filter="normalised"><?php esc_html_e( 'Normalised', 'hbl' ); ?></button>
							<button type="button" class="hbl-pid-chip" data-filter="partial"><?php esc_html_e( 'Partial', 'hbl' ); ?></button>
							<button type="button" class="hbl-pid-chip" data-filter="none"><?php esc_html_e( 'No Match', 'hbl' ); ?></button>
						</div>

						<div class="hbl-table-card">
							<table class="hbl-pid-log-table">
								<thead>
									<tr>
										<th style="width:36px;text-align:center"><input type="checkbox" id="hbl-pid-import-select-all"></th>
										<th style="width:36px">#</th>
										<th><?php esc_html_e( 'File: Name', 'hbl' ); ?></th>
										<th style="width:130px"><?php esc_html_e( 'File: Category', 'hbl' ); ?></th>
										<th style="width:200px"><?php esc_html_e( 'Place ID from File', 'hbl' ); ?></th>
										<th><?php esc_html_e( 'Matched Listing', 'hbl' ); ?></th>
										<th style="width:90px"><?php esc_html_e( 'Match', 'hbl' ); ?></th>
									</tr>
								</thead>
								<tbody id="hbl-pid-import-tbody"></tbody>
							</table>
						</div>

						<div class="hbl-pid-import-pagination" id="hbl-pid-import-pagination"></div>
					</div>
				</div><!-- #hbl-pid-tab-import -->

			</div><!-- .hbl-reassign-container -->
		</div>

		<script>
		(function () {
			'use strict';

			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var AJAX  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function htmlEsc( s ) {
				var d = document.createElement( 'div' );
				d.textContent = s || '';
				return d.innerHTML;
			}

			function isValidPID( v ) { return /^Ch[A-Za-z0-9_-]{10,}$/.test( v ); }

				// ── Tabs ───────────────────────────────────────────────────────────
			document.querySelectorAll( '.hbl-pid-tab' ).forEach( function (tab) {
				tab.addEventListener( 'click', function () {
					document.querySelectorAll( '.hbl-pid-tab' ).forEach( function (t) { t.classList.remove( 'hbl-pid-tab--active' ); } );
					tab.classList.add( 'hbl-pid-tab--active' );
					document.getElementById( 'hbl-pid-tab-manual' ).style.display = tab.dataset.tab === 'manual' ? '' : 'none';
					document.getElementById( 'hbl-pid-tab-import' ).style.display = tab.dataset.tab === 'import' ? '' : 'none';
				} );
			} );

			// ══════════════════════════════════════════════════════
			// MODE A — MANUAL ENTRY
			// ══════════════════════════════════════════════════════

			var tbody       = document.getElementById( 'hbl-pid-tbody' );
			var applyBtn    = document.getElementById( 'hbl-pid-apply-btn' );
			var applyCount  = document.getElementById( 'hbl-pid-apply-count' );
			var selectAllCb = document.getElementById( 'hbl-pid-select-all' );
			var selectAllCbMobile = document.getElementById( 'hbl-pid-select-all-mobile' );
			var pagination  = document.getElementById( 'hbl-pid-pagination' );
			var searchInput = document.getElementById( 'hbl-pid-search' );
			var searchBtn   = document.getElementById( 'hbl-pid-search-btn' );
			var resetBtn    = document.getElementById( 'hbl-pid-reset-btn' );
			var claudeAllBtn = document.getElementById( 'hbl-pid-claude-all-btn' );
			var statMissing  = document.getElementById( 'hbl-pid-stat-missing' );
			var statSaved    = document.getElementById( 'hbl-pid-stat-saved' );

			var manualState = { page: 1, maxPages: 1, search: '', savedCount: 0 };

			if ( claudeAllBtn ) { claudeAllBtn.style.display = ''; }

			function getAllRowCbs() {
				if ( ! tbody ) return [];
				return Array.prototype.slice.call( tbody.querySelectorAll( '.hbl-pid-row-cb' ) );
			}

			function getFilledCbs() {
				return getAllRowCbs().filter( function (cb) {
					var row = cb.closest( 'tr' );
					var inp = row && row.querySelector( '.hbl-pid-input' );
					return inp && inp.value.trim();
				} );
			}

			function updateApplyBtn() {
				var checked = getFilledCbs().filter( function (cb) { return cb.checked; } );
				var n = checked.length;
				if ( applyBtn ) { applyBtn.style.display = n > 0 ? '' : 'none'; applyBtn.disabled = n === 0; }
				if ( applyCount ) applyCount.textContent = n;
				// Sync every select-all control (header + mobile) against ALL rows on
				// the page (not just filled ones) so they reflect reality instead of
				// silently resetting to unchecked whenever no row has a Place ID yet.
				var allCbs = getAllRowCbs();
				var total  = allCbs.length;
				var allChecked = total > 0 && allCbs.every( function (cb) { return cb.checked; } );
				var someChecked = allCbs.some( function (cb) { return cb.checked; } );
				[ selectAllCb, selectAllCbMobile ].forEach( function (cb) {
					if ( ! cb ) return;
					cb.disabled = total === 0;
					cb.checked = allChecked;
					cb.indeterminate = ! allChecked && someChecked;
				} );
			}

			if ( tbody ) {
				tbody.addEventListener( 'change', function (e) {
					if ( e.target.classList.contains( 'hbl-pid-row-cb' ) || e.target.classList.contains( 'hbl-pid-input' ) ) updateApplyBtn();
				} );
				tbody.addEventListener( 'input', function (e) {
					if ( ! e.target.classList.contains( 'hbl-pid-input' ) ) return;
					var val = e.target.value.trim();
					var row = e.target.closest( 'tr' );
					var cb  = row && row.querySelector( '.hbl-pid-row-cb' );
					var link = row && row.querySelector( '.hbl-pid-verify-link' );
					if ( cb ) cb.checked = val !== '';
					if ( link ) { link.href = 'https://maps.google.com/?q=place_id:' + encodeURIComponent( val ); link.style.display = val ? '' : 'none'; }
					e.target.classList.toggle( 'hbl-pid-input--invalid', val !== '' && ! isValidPID( val ) );
					updateApplyBtn();
				} );
			}
			[ selectAllCb, selectAllCbMobile ].forEach( function (master) {
				if ( ! master ) return;
				master.addEventListener( 'change', function () {
					getAllRowCbs().forEach( function (cb) { cb.checked = master.checked; } );
					updateApplyBtn();
				} );
			} );

			function loadManualPage( page ) {
				manualState.page = page;
				if ( tbody ) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280">Loading…</td></tr>';
				if ( applyBtn ) applyBtn.style.display = 'none';
				[ selectAllCb, selectAllCbMobile ].forEach( function (cb) { if ( cb ) { cb.checked = false; cb.indeterminate = false; } } );

				var fd = new FormData();
				fd.append( 'action', 'hbl_place_id_load_page' );
				fd.append( 'nonce',  NONCE );
				fd.append( 'page',   page );
				fd.append( 'search', manualState.search );

				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) {
						if ( ! res.success ) { tbody.innerHTML = '<tr><td colspan="6" style="color:#DC2626;padding:16px">Error loading listings.</td></tr>'; return; }
						var d = res.data;
						manualState.maxPages = d.max_pages;
						renderManualRows( d.items, d.page, d.per_page );
						renderManualPagination( d.page, d.max_pages, d.total );
					} )
					.catch( function () { tbody.innerHTML = '<tr><td colspan="6" style="color:#DC2626;padding:16px">Network error.</td></tr>'; } );
			}

			function renderManualRows( items, page, perPage ) {
				if ( ! items || ! items.length ) {
					tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280"><?php echo esc_js( __( 'No listings missing a Place ID — great work!', 'hbl' ) ); ?></td></tr>';
					return;
				}
				var html = '';
				items.forEach( function (item, idx) {
					var rowNum = ( page - 1 ) * perPage + idx + 1;
					html +=
						'<tr data-listing-id="' + htmlEsc( String( item.id ) ) + '" data-listing-title="' + htmlEsc( item.title ) + '" data-listing-address="' + htmlEsc( item.address || '' ) + '">' +
						'<td style="text-align:center" data-label=""><input type="checkbox" class="hbl-pid-row-cb" value="' + htmlEsc( String( item.id ) ) + '"></td>' +
						'<td data-label="No.">' + rowNum + '</td>' +
						'<td data-label="Business Name"><a href="' + htmlEsc( item.edit_url ) + '" target="_blank">' + htmlEsc( item.title ) + '</a></td>' +
						'<td class="hbl-pid-addr-cell" data-label="Address">' + htmlEsc( item.address || '—' ) + '</td>' +
						'<td data-label="Place ID"><div class="hbl-pid-input-wrap">' +
							'<input type="text" class="hbl-pid-input hbl-pid-mono" placeholder="ChIJ…">' +
							'<a href="#" class="hbl-pid-verify-link" target="_blank" title="Verify" style="display:none">' +
								'<svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
							'</a></div></td>' +
						'<td class="hbl-pid-actions-cell" data-label="Actions"><div class="hbl-pid-actions">' +
							'<button type="button" class="hbl-pid-btn hbl-pid-btn--find hbl-pid-claude-btn" title="Auto-find via Google Maps">' +
								'<svg class="hbl-pid-btn-icon" width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
								'<span class="hbl-pid-btn-spinner" aria-hidden="true"></span>' +
								'<span class="hbl-pid-btn-label">Auto Find</span>' +
							'</button>' +
							'<button type="button" class="hbl-pid-btn hbl-pid-btn--save hbl-pid-save-row-btn" title="Save Place ID">' +
								'<svg class="hbl-pid-btn-icon" width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
								'<span class="hbl-pid-btn-label">Save</span>' +
							'</button>' +
						'</div></td></tr>';
				} );
				tbody.innerHTML = html;

				// Wire Auto Find per-row buttons
				tbody.querySelectorAll( '.hbl-pid-claude-btn' ).forEach( function (btn) {
					btn.addEventListener( 'click', function () {
						var row = btn.closest( 'tr' );
						var inp = row.querySelector( '.hbl-pid-input' );
						var lid = parseInt( row.dataset.listingId, 10 );
						autoFindPID( lid, btn, inp );
					} );
				} );

				// Wire save-row buttons
				tbody.querySelectorAll( '.hbl-pid-save-row-btn' ).forEach( function (btn) {
					btn.addEventListener( 'click', function () {
						var row = btn.closest( 'tr' );
						var inp = row.querySelector( '.hbl-pid-input' );
						var val = inp ? inp.value.trim() : '';
						var lid = parseInt( row.dataset.listingId, 10 );
						if ( ! val ) { alert( '<?php echo esc_js( __( 'Please enter a Place ID first.', 'hbl' ) ); ?>' ); return; }
						if ( ! isValidPID( val ) && ! confirm( '<?php echo esc_js( __( 'This doesn\'t look like a valid Place ID. Save anyway?', 'hbl' ) ); ?>' ) ) return;
						btn.classList.add( 'is-loading' );
						doSave( [ { listing_id: lid, place_id: val } ], function () {
							btn.classList.remove( 'is-loading' );
							btn.classList.add( 'is-done' );
							var lbl = btn.querySelector( '.hbl-pid-btn-label' );
							if ( lbl ) lbl.textContent = 'Saved';
							btn.disabled = true;
							if ( inp ) { inp.disabled = true; inp.classList.add( 'hbl-pid-input--saved' ); }
							row.querySelector( '.hbl-pid-row-cb' ).checked  = false;
							row.querySelector( '.hbl-pid-row-cb' ).disabled = true;
							manualState.savedCount++;
							if ( statSaved ) statSaved.textContent = manualState.savedCount;
							updateApplyBtn();
							refreshMissingCount();
						}, function () {
							btn.classList.remove( 'is-loading' );
						} );
					} );
				} );
			}

			function renderManualPagination( page, maxPages, total ) {
				if ( ! pagination ) return;
				var html = '<span class="hbl-pid-total">' + total + ' listing(s)</span>';
				if ( maxPages > 1 ) {
					html += '<div class="hbl-pid-page-btns">';
					if ( page > 1 ) html += '<button type="button" class="button hbl-pid-page-btn" data-page="' + (page-1) + '">← Prev</button>';
					html += '<span class="hbl-pid-page-info">Page ' + page + ' / ' + maxPages + '</span>';
					if ( page < maxPages ) html += '<button type="button" class="button hbl-pid-page-btn" data-page="' + (page+1) + '">Next →</button>';
					html += '</div>';
				}
				pagination.innerHTML = html;
				pagination.querySelectorAll( '.hbl-pid-page-btn' ).forEach( function (b) {
					b.addEventListener( 'click', function () { loadManualPage( parseInt( b.dataset.page, 10 ) ); } );
				} );
			}

			function doSave( items, callback, onError ) {
				var fd = new FormData();
				fd.append( 'action', 'hbl_place_id_save_selected' );
				fd.append( 'nonce',  NONCE );
				fd.append( 'items',  JSON.stringify( items ) );
				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) {
						if ( ! res.success ) {
							alert( 'Save failed: ' + ( res.data || 'Unknown error.' ) );
							if ( onError ) onError();
							return;
						}
						if ( callback ) callback( res.data );
					} )
					.catch( function (e) {
						alert( 'Network error: ' + e.message );
						if ( onError ) onError();
					} );
			}

			if ( applyBtn ) {
				applyBtn.addEventListener( 'click', function () {
					var checked = getFilledCbs().filter( function (cb) { return cb.checked; } );
					if ( ! checked.length ) return;
					if ( ! confirm( checked.length + ' Place ID(s) will be saved. Continue?' ) ) return;
					var items = checked.map( function (cb) {
						var row = cb.closest( 'tr' );
						return { listing_id: parseInt( row.dataset.listingId, 10 ), place_id: row.querySelector( '.hbl-pid-input' ).value.trim() };
					} ).filter( function (it) { return it.place_id; } );
					applyBtn.disabled = true;
					applyBtn.classList.add( 'is-loading' );
					var applyLabel = applyBtn.textContent;
					applyBtn.textContent = 'Saving…';
					doSave( items, function (d) {
						d.saved_ids.forEach( function (lid) {
							var row = tbody.querySelector( 'tr[data-listing-id="' + lid + '"]' );
							if ( ! row ) return;
							var inp = row.querySelector( '.hbl-pid-input' ), btn = row.querySelector( '.hbl-pid-save-row-btn' ), cb = row.querySelector( '.hbl-pid-row-cb' );
							if ( inp ) { inp.disabled = true; inp.classList.add( 'hbl-pid-input--saved' ); }
							if ( btn ) {
								btn.classList.add( 'is-done' );
								var lbl = btn.querySelector( '.hbl-pid-btn-label' );
								if ( lbl ) lbl.textContent = 'Saved';
								btn.disabled = true;
							}
							if ( cb  ) { cb.checked = false; cb.disabled = true; }
						} );
						manualState.savedCount += d.saved;
						if ( statSaved ) statSaved.textContent = manualState.savedCount;
						applyBtn.style.display = 'none';
						applyBtn.classList.remove( 'is-loading' );
						[ selectAllCb, selectAllCbMobile ].forEach( function (cb) { if ( cb ) { cb.checked = false; cb.indeterminate = false; } } );
						refreshMissingCount();
					}, function () {
						applyBtn.disabled = false;
						applyBtn.classList.remove( 'is-loading' );
						applyBtn.textContent = applyLabel;
					} );
				} );
			}

			if ( searchBtn ) searchBtn.addEventListener( 'click', function () { manualState.search = searchInput ? searchInput.value.trim() : ''; if ( resetBtn ) resetBtn.style.display = manualState.search ? '' : 'none'; loadManualPage(1); } );
			if ( searchInput ) searchInput.addEventListener( 'keydown', function (e) { if ( e.key === 'Enter' ) { e.preventDefault(); searchBtn.click(); } } );
			if ( resetBtn ) resetBtn.addEventListener( 'click', function () { if ( searchInput ) searchInput.value = ''; manualState.search = ''; resetBtn.style.display = 'none'; loadManualPage(1); } );

			function refreshMissingCount() {
				var fd = new FormData();
				fd.append( 'action', 'hbl_place_id_count_missing' );
				fd.append( 'nonce',  NONCE );
				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) { if ( res.success && statMissing ) statMissing.textContent = res.data.count; } );
			}

			refreshMissingCount();
			loadManualPage(1);

			// ══════════════════════════════════════════════════════
			// MODE B — IMPORT
			// ══════════════════════════════════════════════════════

			var importState = {
				token     : '',
				results   : [],   // all rows
				filtered  : [],   // after filter chip
				page      : 1,
				perPage   : 50,
				filter    : 'all',
			};

			var dropzone      = document.getElementById( 'hbl-pid-dropzone' );
			var fileInput     = document.getElementById( 'hbl-pid-file-input' );
			var uploadStatus  = document.getElementById( 'hbl-pid-upload-status' );
			var mappingStep   = document.getElementById( 'hbl-pid-mapping-step' );
			var mappingGrid   = document.getElementById( 'hbl-pid-mapping-grid' );
			var previewWrap   = document.getElementById( 'hbl-pid-preview-table-wrap' );
			var matchBtn      = document.getElementById( 'hbl-pid-match-btn' );
			var matchStatus   = document.getElementById( 'hbl-pid-match-status' );
			var resultsStep   = document.getElementById( 'hbl-pid-results-step' );
			var importTbody   = document.getElementById( 'hbl-pid-import-tbody' );
			var importSummary = document.getElementById( 'hbl-pid-import-summary' );
			var importApplyBtn= document.getElementById( 'hbl-pid-import-apply-btn' );
			var importApplyCt = document.getElementById( 'hbl-pid-import-apply-count' );
			var importSelAll  = document.getElementById( 'hbl-pid-import-select-all' );
			var importPagEl   = document.getElementById( 'hbl-pid-import-pagination' );
			var hasHeaderCb   = document.getElementById( 'hbl-pid-has-header' );

			var MATCH_LABEL = {
				exact      : '<span class="hbl-pid-match-badge hbl-pid-match--exact">Exact</span>',
				normalised : '<span class="hbl-pid-match-badge hbl-pid-match--norm">Normalised</span>',
				partial    : '<span class="hbl-pid-match-badge hbl-pid-match--partial">Partial</span>',
				none       : '<span class="hbl-pid-match-badge hbl-pid-match--none">No Match</span>',
			};

			// Drag & drop
			if ( dropzone ) {
				dropzone.addEventListener( 'dragover', function (e) { e.preventDefault(); dropzone.classList.add( 'hbl-pid-dropzone--over' ); } );
				dropzone.addEventListener( 'dragleave', function () { dropzone.classList.remove( 'hbl-pid-dropzone--over' ); } );
				dropzone.addEventListener( 'drop', function (e) {
					e.preventDefault(); dropzone.classList.remove( 'hbl-pid-dropzone--over' );
					if ( e.dataTransfer.files.length ) uploadFile( e.dataTransfer.files[0] );
				} );
			}
			if ( fileInput ) fileInput.addEventListener( 'change', function () { if ( fileInput.files.length ) uploadFile( fileInput.files[0] ); } );

			function uploadFile( file ) {
				var ext = file.name.split('.').pop().toLowerCase();
				if ( ext !== 'xlsx' && ext !== 'csv' ) { alert( 'Only .xlsx and .csv files are supported.' ); return; }

				uploadStatus.style.display = '';
				uploadStatus.innerHTML = '<span style="color:#6B7280">Uploading and parsing…</span>';
				if ( mappingStep ) mappingStep.style.display = 'none';
				if ( resultsStep ) resultsStep.style.display = 'none';

				var fd = new FormData();
				fd.append( 'action', 'hbl_place_id_upload' );
				fd.append( 'nonce',  NONCE );
				fd.append( 'file',   file );

				fetch( AJAX, { method: 'POST', body: fd } )
					.then( function (r) { return r.json(); } )
					.then( function (res) {
						if ( ! res.success ) {
							uploadStatus.innerHTML = '<span style="color:#DC2626">Error: ' + htmlEsc( res.data || 'Unknown error.' ) + '</span>';
							return;
						}
						var d = res.data;
						importState.token = d.token;
						uploadStatus.innerHTML = '<span style="color:#065F46">✓ Parsed ' + d.total_rows + ' row(s) from <strong>' + htmlEsc( file.name ) + '</strong></span>';
						buildMappingUI( d.headers, d.preview );
						if ( mappingStep ) mappingStep.style.display = '';
					} )
					.catch( function (e) { uploadStatus.innerHTML = '<span style="color:#DC2626">Network error: ' + htmlEsc( e.message ) + '</span>'; } );
			}

			function buildMappingUI( headers, preview ) {
				if ( ! mappingGrid ) return;

				var colOptions = headers.map( function (h, i) {
					return '<option value="' + i + '">' + htmlEsc( h || 'Column ' + (i+1) ) + '</option>';
				} ).join('');

				var noneOpt = '<option value="-1">— Not in file —</option>';

				// Guess columns by name.
				function guessCol( keywords ) {
					for ( var k = 0; k < keywords.length; k++ ) {
						for ( var i = 0; i < headers.length; i++ ) {
							if ( headers[i] && headers[i].toLowerCase().indexOf( keywords[k] ) !== -1 ) return i;
						}
					}
					return 0;
				}

				var nameIdx = guessCol( ['name', 'business', 'title', 'company'] );
				var catIdx  = guessCol( ['category', 'cat', 'type', 'industry'] );
				var pidIdx  = guessCol( ['place', 'place_id', 'placeid', 'google', 'gplace'] );

				mappingGrid.innerHTML =
					'<div class="hbl-pid-map-field"><label>Business Name column <span class="hbl-pid-required">*</span></label>' +
					'<select id="hbl-pid-col-name" class="hbl-select">' + colOptions + '</select></div>' +

					'<div class="hbl-pid-map-field"><label>Category column</label>' +
					'<select id="hbl-pid-col-cat" class="hbl-select">' + noneOpt + colOptions + '</select></div>' +

					'<div class="hbl-pid-map-field"><label>Place ID column <span class="hbl-pid-required">*</span></label>' +
					'<select id="hbl-pid-col-pid" class="hbl-select">' + colOptions + '</select></div>';

				document.getElementById( 'hbl-pid-col-name' ).value = nameIdx;
				document.getElementById( 'hbl-pid-col-cat'  ).value = catIdx >= 0 ? catIdx : -1;
				document.getElementById( 'hbl-pid-col-pid'  ).value = pidIdx;

				// Preview table
				var tHead = '<thead><tr>' + headers.map( function (h, i) { return '<th>' + htmlEsc( h || 'Col ' + (i+1) ) + '</th>'; } ).join('') + '</tr></thead>';
				var tBody = '<tbody>' + preview.slice(1, 6).map( function (row) {
					return '<tr>' + row.map( function (cell) { return '<td>' + htmlEsc( cell ) + '</td>'; } ).join('') + '</tr>';
				} ).join('') + '</tbody>';
				if ( previewWrap ) previewWrap.innerHTML = '<p style="font-size:12px;color:#6B7280;margin-bottom:6px">Preview (first 5 rows):</p><table class="hbl-pid-preview-table">' + tHead + tBody + '</table>';
			}

			if ( matchBtn ) {
				matchBtn.addEventListener( 'click', function () {
					var nameCol = parseInt( document.getElementById('hbl-pid-col-name').value, 10 );
					var catCol  = parseInt( document.getElementById('hbl-pid-col-cat').value,  10 );
					var pidCol  = parseInt( document.getElementById('hbl-pid-col-pid').value,  10 );
					var hasHdr  = hasHeaderCb ? hasHeaderCb.checked : true;

					matchBtn.disabled    = true;
					matchStatus.textContent = 'Matching… this may take a moment for large files.';

					var fd = new FormData();
					fd.append( 'action',     'hbl_place_id_match_all' );
					fd.append( 'nonce',      NONCE );
					fd.append( 'token',      importState.token );
					fd.append( 'name_col',   nameCol );
					fd.append( 'cat_col',    catCol );
					fd.append( 'pid_col',    pidCol );
					fd.append( 'has_header', hasHdr ? 'true' : 'false' );

					fetch( AJAX, { method: 'POST', body: fd } )
						.then( function (r) { return r.json(); } )
						.then( function (res) {
							matchBtn.disabled = false;
							if ( ! res.success ) { matchStatus.textContent = 'Error: ' + ( res.data || 'Unknown.' ); return; }
							var d = res.data;
							matchStatus.textContent = d.found + ' matched, ' + d.not_found + ' not found.';
							importState.results = d.results;
							importState.filter  = 'all';
							importState.page    = 1;
							applyImportFilter();
							if ( resultsStep ) resultsStep.style.display = '';
							if ( importSummary ) importSummary.textContent = d.found + ' matched / ' + d.not_found + ' no match out of ' + d.total + ' rows';
						} )
						.catch( function (e) { matchBtn.disabled = false; matchStatus.textContent = 'Network error: ' + e.message; } );
				} );
			}

			// Filter chips
			document.querySelectorAll( '.hbl-pid-chip' ).forEach( function (chip) {
				chip.addEventListener( 'click', function () {
					document.querySelectorAll( '.hbl-pid-chip' ).forEach( function (c) { c.classList.remove( 'hbl-pid-chip--active' ); } );
					chip.classList.add( 'hbl-pid-chip--active' );
					importState.filter = chip.dataset.filter;
					importState.page   = 1;
					applyImportFilter();
				} );
			} );

			function applyImportFilter() {
				var f = importState.filter;
				importState.filtered = f === 'all'
					? importState.results
					: importState.results.filter( function (r) { return r.match_type === f; } );
				renderImportPage( 1 );
				updateImportApplyBtn();
			}

			function renderImportPage( page ) {
				importState.page = page;
				if ( ! importTbody ) return;

				var start = ( page - 1 ) * importState.perPage;
				var rows  = importState.filtered.slice( start, start + importState.perPage );

				if ( ! rows.length ) {
					importTbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:#6B7280">No results for this filter.</td></tr>';
					renderImportPagination();
					return;
				}

				var html = '';
				rows.forEach( function (r, idx) {
					var selectable = r.match_type !== 'none';
					var cbHtml     = selectable
						? '<input type="checkbox" class="hbl-pid-imp-cb" value="' + idx + '" ' + ( r.match_type === 'exact' ? 'checked' : '' ) + '>'
						: '<input type="checkbox" disabled style="opacity:.3">';

					var matchedHtml = selectable
						? '<a href="' + htmlEsc( r.edit_url ) + '" target="_blank">' + htmlEsc( r.listing_title ) + '</a>' + ( r.ambiguous ? ' <span class="hbl-pid-ambiguous" title="Multiple listings matched — review carefully">⚠</span>' : '' )
						: '<span style="color:#9CA3AF">—</span>';

					html +=
						'<tr data-listing-id="' + r.listing_id + '" data-place-id="' + htmlEsc( r.place_id ) + '" data-match="' + r.match_type + '">' +
						'<td style="text-align:center" data-label="">' + cbHtml + '</td>' +
						'<td data-label="No.">' + ( start + idx + 1 ) + '</td>' +
						'<td data-label="File: Name">' + htmlEsc( r.excel_name ) + '</td>' +
						'<td data-label="File: Category">' + htmlEsc( r.excel_cat || '—' ) + '</td>' +
						'<td data-label="Place ID from File"><code class="hbl-pid-code-cell">' + htmlEsc( r.place_id ) + '</code></td>' +
						'<td data-label="Matched Listing">' + matchedHtml + '</td>' +
						'<td data-label="Match">' + ( MATCH_LABEL[ r.match_type ] || htmlEsc( r.match_type ) ) + '</td>' +
						'</tr>';
				} );
				importTbody.innerHTML = html;

				importTbody.addEventListener( 'change', function (e) {
					if ( e.target.classList.contains( 'hbl-pid-imp-cb' ) ) updateImportApplyBtn();
				} );

				renderImportPagination();
				updateImportApplyBtn();
			}

			function renderImportPagination() {
				if ( ! importPagEl ) return;
				var total    = importState.filtered.length;
				var maxPages = Math.ceil( total / importState.perPage ) || 1;
				var page     = importState.page;
				var html = '<span class="hbl-pid-total">' + total + ' row(s)</span>';
				if ( maxPages > 1 ) {
					html += '<div class="hbl-pid-page-btns">';
					if ( page > 1 ) html += '<button type="button" class="button hbl-pid-page-btn" data-page="' + (page-1) + '">← Prev</button>';
					html += '<span class="hbl-pid-page-info">Page ' + page + ' / ' + maxPages + '</span>';
					if ( page < maxPages ) html += '<button type="button" class="button hbl-pid-page-btn" data-page="' + (page+1) + '">Next →</button>';
					html += '</div>';
				}
				importPagEl.innerHTML = html;
				importPagEl.querySelectorAll( '.hbl-pid-page-btn' ).forEach( function (b) {
					b.addEventListener( 'click', function () { renderImportPage( parseInt( b.dataset.page, 10 ) ); } );
				} );
			}

			function getCheckedImportRows() {
				if ( ! importTbody ) return [];
				return Array.prototype.slice.call( importTbody.querySelectorAll( '.hbl-pid-imp-cb:checked' ) ).map( function (cb) {
					return cb.closest( 'tr' );
				} );
			}

			function updateImportApplyBtn() {
				var n = getCheckedImportRows().length;
				if ( importApplyBtn ) { importApplyBtn.disabled = n === 0; }
				if ( importApplyCt ) importApplyCt.textContent = n;
				if ( importSelAll ) {
					var all = importTbody ? importTbody.querySelectorAll( '.hbl-pid-imp-cb' ).length : 0;
					importSelAll.checked = n > 0 && n === all;
					importSelAll.indeterminate = n > 0 && n < all;
				}
			}

			if ( importSelAll ) {
				importSelAll.addEventListener( 'change', function () {
					if ( importTbody ) importTbody.querySelectorAll( '.hbl-pid-imp-cb' ).forEach( function (cb) { cb.checked = importSelAll.checked; } );
					updateImportApplyBtn();
				} );
			}

			if ( importApplyBtn ) {
				importApplyBtn.addEventListener( 'click', function () {
					var rows = getCheckedImportRows();
					if ( ! rows.length ) return;
					if ( ! confirm( rows.length + ' Place ID(s) will be written to their matched listings. Continue?' ) ) return;

					var items = rows.map( function (row) {
						return { listing_id: parseInt( row.dataset.listingId, 10 ), place_id: row.dataset.placeId };
					} ).filter( function (it) { return it.listing_id && it.place_id; } );

					importApplyBtn.disabled    = true;
					importApplyBtn.textContent = 'Applying…';

					var fd = new FormData();
					fd.append( 'action', 'hbl_place_id_import_apply' );
					fd.append( 'nonce',  NONCE );
					fd.append( 'items',  JSON.stringify( items ) );

					fetch( AJAX, { method: 'POST', body: fd } )
						.then( function (r) { return r.json(); } )
						.then( function (res) {
							importApplyBtn.disabled    = false;
							importApplyBtn.innerHTML   = 'Apply Selected (<span id="hbl-pid-import-apply-count">0</span>)';
							importApplyCt = document.getElementById( 'hbl-pid-import-apply-count' );
							if ( ! res.success ) { alert( 'Error: ' + ( res.data || 'Unknown.' ) ); return; }
							var d = res.data;
							alert( d.applied + ' Place ID(s) applied successfully.' + ( d.failed ? ' ' + d.failed + ' failed.' : '' ) );
							manualState.savedCount += d.applied;
							if ( statSaved ) statSaved.textContent = manualState.savedCount;
							refreshMissingCount();
							// Mark applied rows in the table
							rows.forEach( function (row) {
								var lid = parseInt( row.dataset.listingId, 10 );
								if ( d.applied_ids.indexOf( lid ) !== -1 ) {
									row.style.opacity = '0.5';
									var cb = row.querySelector( '.hbl-pid-imp-cb' );
									if ( cb ) { cb.checked = false; cb.disabled = true; }
								}
							} );
						} )
						.catch( function (e) {
							importApplyBtn.disabled = false;
							importApplyBtn.innerHTML = 'Apply Selected (<span id="hbl-pid-import-apply-count">0</span>)';
							alert( 'Network error: ' + e.message );
						} );
				} );
			}

		// ── Auto Find (Google Maps scrape) ────────────────────────────────────

		function autoFindPID( listingId, btn, inp ) {
			btn.disabled = true;
			btn.classList.add( 'is-loading' );

			var fd = new FormData();
			fd.append( 'action',     'hbl_pid_auto_find' );
			fd.append( 'nonce',      NONCE );
			fd.append( 'listing_id', listingId );

			fetch( AJAX, { method: 'POST', body: fd } )
				.then( function (r) { return r.json(); } )
				.then( function (res) {
					btn.disabled = false;
					btn.classList.remove( 'is-loading' );
					if ( ! res.success ) {
						alert( 'Auto Find: ' + ( res.data || 'Unknown error.' ) );
						return;
					}
					var pid = res.data.pid || '';
					if ( pid && inp ) {
						inp.value = pid;
						inp.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						inp.focus();
						btn.classList.add( 'is-found' );
						setTimeout( function () { btn.classList.remove( 'is-found' ); }, 1200 );
					}
				} )
				.catch( function (e) {
					btn.disabled = false;
					btn.classList.remove( 'is-loading' );
					alert( 'Network error: ' + e.message );
				} );
		}

		function autoFindAllPIDs() {
			var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr' ) ).filter( function (row) {
				var inp = row.querySelector( '.hbl-pid-input' );
				return inp && ! inp.disabled && ! inp.value.trim();
			} );
			if ( ! rows.length ) { alert( 'No empty rows to fill on this page.' ); return; }

			var claudeAllLabel = claudeAllBtn ? claudeAllBtn.querySelector( '.hbl-pid-btn-label' ) : null;
			if ( claudeAllBtn ) { claudeAllBtn.disabled = true; claudeAllBtn.classList.add( 'is-loading' ); if ( claudeAllLabel ) claudeAllLabel.textContent = 'Finding… (0/' + rows.length + ')'; }
			var idx = 0;

			function next() {
				if ( idx >= rows.length ) {
					if ( claudeAllBtn ) { claudeAllBtn.disabled = false; claudeAllBtn.classList.remove( 'is-loading' ); if ( claudeAllLabel ) claudeAllLabel.textContent = 'Auto Find All'; }
					return;
				}
				var row = rows[ idx++ ];
				var btn = row.querySelector( '.hbl-pid-claude-btn' ) || { disabled: false, textContent: '', style: {}, classList: { add: function () {}, remove: function () {} } };
				var inp = row.querySelector( '.hbl-pid-input' );
				var lid = parseInt( row.dataset.listingId, 10 );
				if ( claudeAllLabel ) claudeAllLabel.textContent = 'Finding… (' + idx + '/' + rows.length + ')';
				autoFindPID( lid, btn, inp );
				setTimeout( next, 1500 );
			}
			next();
		}

		if ( claudeAllBtn ) {
			claudeAllBtn.addEventListener( 'click', autoFindAllPIDs );
		}

		})();
		</script>
		<?php
	}
}

HBL_Place_ID::get_instance();
