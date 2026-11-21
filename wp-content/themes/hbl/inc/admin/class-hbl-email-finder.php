<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Finder — replicates the Place ID Manager workflow for email addresses.
 *
 * For each published listing with an empty email meta value, "Auto Find" looks
 * up the business website (listing meta, then Google Maps as a fallback),
 * crawls the homepage + common contact pages and extracts public email
 * addresses (mailto: links, then plain-text matches).
 */
class HBL_Email_Finder {

	const OPTION_KEY = 'hbl_email_finder_settings';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'add_admin_menu' ), 36 );
		add_action( 'admin_init',            array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_hbl_email_count_missing', array( $this, 'ajax_count_missing' ) );
		add_action( 'wp_ajax_hbl_email_load_page',     array( $this, 'ajax_load_page' ) );
		add_action( 'wp_ajax_hbl_email_save_selected', array( $this, 'ajax_save_selected' ) );
		add_action( 'wp_ajax_hbl_email_auto_find',     array( $this, 'ajax_auto_find' ) );
	}

	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Email Finder', 'hbl' ),
			__( 'Email Finder', 'hbl' ),
			'manage_options',
			'hbl-email-finder',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-email-finder' !== $hook ) {
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
		register_setting( 'hbl_email_finder', self::OPTION_KEY, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	private function get_settings(): array {
		return array_merge(
			array( 'email_key' => '_email', 'website_key' => '_website', 'per_page' => 25 ),
			(array) get_option( self::OPTION_KEY, array() )
		);
	}

	public function sanitize_settings( $input ): array {
		return array(
			'email_key'   => sanitize_text_field( $input['email_key']   ?? '_email' ),
			'website_key' => sanitize_text_field( $input['website_key'] ?? '_website' ),
			'per_page'    => max( 10, min( 100, (int) ( $input['per_page'] ?? 25 ) ) ),
		);
	}


	/* ── Queries ─────────────────────────────────────────────────────── */

	private function count_page( string $meta_key, string $search ): int {
		global $wpdb;
		$sql = "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
		        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
		        WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
		          AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')";
		$args = array( $meta_key );
		if ( $search ) {
			$sql   .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	private function get_page( string $meta_key, string $website_key, int $page, int $per_page, string $search ): array {
		global $wpdb;
		$sql = "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
		        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
		        WHERE p.post_type = 'at_biz_dir' AND p.post_status = 'publish'
		          AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')";
		$args = array( $meta_key );
		if ( $search ) {
			$sql   .= ' AND p.post_title LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql   .= ' ORDER BY p.post_title ASC LIMIT %d OFFSET %d';
		$args[] = $per_page;
		$args[] = ( $page - 1 ) * $per_page;

		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		$items = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row->ID;
			$website = (string) get_post_meta( $id, $website_key, true );
			$items[] = array(
				'id'       => $id,
				'title'    => $row->post_title,
				'website'  => $website,
				'edit_url' => get_edit_post_link( $id, 'raw' ) ?: '',
			);
		}
		return $items;
	}


	/* ── AJAX ────────────────────────────────────────────────────────── */

	private function guard(): void {
		check_ajax_referer( 'hbl_email_finder_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.' );
		}
	}

	public function ajax_count_missing(): void {
		$this->guard();
		$s = $this->get_settings();
		wp_send_json_success( array( 'count' => $this->count_page( $s['email_key'], '' ) ) );
	}

	public function ajax_load_page(): void {
		$this->guard();
		$s        = $this->get_settings();
		$page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
		$search   = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		$per_page = $s['per_page'];
		$total    = $this->count_page( $s['email_key'], $search );

		wp_send_json_success( array(
			'items'     => $this->get_page( $s['email_key'], $s['website_key'], $page, $per_page, $search ),
			'total'     => $total,
			'page'      => $page,
			'max_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 1,
			'per_page'  => $per_page,
		) );
	}

	public function ajax_save_selected(): void {
		$this->guard();
		$s     = $this->get_settings();
		$items = json_decode( wp_unslash( $_POST['items'] ?? '' ), true );

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items provided.' );
		}

		$saved = 0; $failed = 0; $saved_ids = array();
		foreach ( $items as $item ) {
			$id    = (int) ( $item['listing_id'] ?? 0 );
			$email = sanitize_email( $item['email'] ?? '' );
			if ( ! $id || ! is_email( $email ) ) { $failed++; continue; }
			$post = get_post( $id );
			if ( ! $post || 'at_biz_dir' !== $post->post_type ) { $failed++; continue; }
			update_post_meta( $id, $s['email_key'], $email );
			$saved++;
			$saved_ids[] = $id;
		}
		wp_send_json_success( array( 'saved' => $saved, 'failed' => $failed, 'saved_ids' => $saved_ids ) );
	}

	public function ajax_auto_find(): void {
		$this->guard();
		$s          = $this->get_settings();
		$listing_id = (int) ( $_POST['listing_id'] ?? 0 );
		$post       = $listing_id ? get_post( $listing_id ) : null;
		if ( ! $post || 'at_biz_dir' !== $post->post_type ) {
			wp_send_json_error( 'Listing not found.' );
		}

		$title   = get_the_title( $post );
		$website = trim( (string) get_post_meta( $listing_id, $s['website_key'], true ) );

		if ( '' === $website ) {
			$address = implode( ', ', array_filter( array(
				get_post_meta( $listing_id, '_address', true ),
				get_post_meta( $listing_id, '_city',    true ),
				get_post_meta( $listing_id, '_state',   true ),
			) ) );
			$website = $this->find_website_via_maps( $title, $address );
		}

		if ( '' === $website ) {
			wp_send_json_error( 'No website found for this listing, so no email could be located.' );
		}

		$result = $this->scrape_emails( $website );
		if ( empty( $result['emails'] ) ) {
			wp_send_json_error( $result['error'] ?: 'No public email address found on the website.' );
		}

		wp_send_json_success( array(
			'email'   => $result['emails'][0],
			'emails'  => $result['emails'],
			'website' => $result['website'],
			'title'   => $title,
		) );
	}


	/* ── Scraping ────────────────────────────────────────────────────── */

	private function http_get( string $url ) {
		$response = wp_remote_get( $url, array(
			'timeout'     => 12,
			'redirection' => 4,
			'headers'     => array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
				'Accept-Language' => 'en-AU,en;q=0.9',
				'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
				'Cookie'          => 'CONSENT=YES+cb.20210720-07-p0.en+FX+410; SOCS=CAI',
			),
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/** Best-effort: pull the business website out of a Google Maps search result. */
	private function find_website_via_maps( string $title, string $address ): string {
		$query = rawurlencode( trim( $title . ' ' . $address ) );
		$body  = $this->http_get( 'https://www.google.com/maps/search/' . $query . '?hl=en&gl=au' );
		if ( '' === $body ) {
			return '';
		}
		if ( preg_match_all( '#https?://[A-Za-z0-9.\-]+\.[a-z]{2,}(?:/[^\s"\'\\\\<>]*)?#i', $body, $m ) ) {
			$skip = '/(google|gstatic|googleapis|googleusercontent|schema\.org|w3\.org|youtube|facebook|instagram|ggpht|apple\.com)/i';
			foreach ( $m[0] as $u ) {
				if ( ! preg_match( $skip, $u ) ) {
					$p = wp_parse_url( $u );
					return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'];
				}
			}
		}
		return '';
	}

	private function scrape_emails( string $website ): array {
		if ( ! preg_match( '#^https?://#i', $website ) ) {
			$website = 'https://' . $website;
		}
		$parts = wp_parse_url( $website );
		if ( empty( $parts['host'] ) ) {
			return array( 'emails' => array(), 'website' => $website, 'error' => 'Invalid website URL.' );
		}
		$base  = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		$host  = preg_replace( '/^www\./i', '', strtolower( $parts['host'] ) );

		$urls  = array_unique( array( $website, $base, $base . '/contact', $base . '/contact-us', $base . '/about', $base . '/about-us' ) );
		$found = array();
		$reached = false;

		foreach ( $urls as $url ) {
			$body = $this->http_get( $url );
			if ( '' === $body ) {
				continue;
			}
			$reached = true;
			foreach ( $this->extract_emails( $body ) as $email ) {
				$found[ $email ] = isset( $found[ $email ] ) ? $found[ $email ] : $this->score_email( $email, $host );
			}
			if ( $found ) {
				break; // Stop at the first page that yields results.
			}
		}

		if ( ! $reached ) {
			return array( 'emails' => array(), 'website' => $website, 'error' => 'Could not load the business website.' );
		}

		arsort( $found );
		return array( 'emails' => array_keys( $found ), 'website' => $website, 'error' => '' );
	}

	private function extract_emails( string $html ): array {
		$html   = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html   = str_ireplace( array( '[at]', '(at)', ' [at] ', ' (at) ' ), '@', $html );
		$emails = array();

		if ( preg_match_all( '/mailto:([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/i', $html, $m ) ) {
			$emails = array_merge( $emails, $m[1] );
		}
		if ( preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $html, $m ) ) {
			$emails = array_merge( $emails, $m[0] );
		}

		$clean = array();
		foreach ( $emails as $e ) {
			$e = strtolower( trim( $e, ".,;:'\"" ) );
			if ( ! is_email( $e ) ) { continue; }
			if ( preg_match( '/\.(png|jpe?g|gif|webp|svg|css|js|woff2?)$/i', $e ) ) { continue; }
			if ( preg_match( '/(sentry|wixpress|example\.|domain\.com|yourname|your@|email@email|noreply|no-reply|@2x|u003e)/i', $e ) ) { continue; }
			$clean[ $e ] = true;
		}
		return array_keys( $clean );
	}

	/** Prefer addresses on the business's own domain and generic contact mailboxes. */
	private function score_email( string $email, string $host ): int {
		$score = 0;
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( false !== strpos( $domain, $host ) || false !== strpos( $host, $domain ) ) { $score += 10; }
		if ( preg_match( '/^(info|admin|contact|hello|enquiries|enquiry|office|sales|bookings?|reception|mail)$/', $local ) ) { $score += 5; }
		if ( preg_match( '/@(gmail|yahoo|hotmail|outlook|bigpond|live)\./', $email ) ) { $score += 1; }
		return $score;
	}


	/* ── Page ────────────────────────────────────────────────────────── */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->get_settings();
		$nonce    = wp_create_nonce( 'hbl_email_finder_nonce' );
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-pid-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Email Finder', 'hbl' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Find public contact emails for listings by crawling each business website (falls back to Google Maps to locate the website). Review each result before saving.', 'hbl' ); ?>
			</p>

			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-body">
						<span class="hbl-stat-number" id="hbl-em-stat-missing">–</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Missing Emails', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--success">
					<div class="hbl-stat-body">
						<span class="hbl-stat-number" id="hbl-em-stat-saved">0</span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Saved this session', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><code style="font-size:12px;background:#F3F4F6;padding:2px 6px;border-radius:4px"><?php echo esc_html( $settings['email_key'] ); ?></code></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Meta key', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<div class="hbl-reassign-container">
				<form method="post" action="options.php">
					<?php settings_fields( 'hbl_email_finder' ); ?>
					<div class="hbl-reassign-step">
						<div class="hbl-step-header">
							<span class="hbl-step-number">1</span>
							<h2><?php esc_html_e( 'Settings', 'hbl' ); ?></h2>
						</div>
						<div class="hbl-pid-field-inline">
							<div class="hbl-pid-field">
								<label for="hbl-em-email-key"><?php esc_html_e( 'Email meta key', 'hbl' ); ?></label>
								<input type="text" id="hbl-em-email-key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[email_key]" value="<?php echo esc_attr( $settings['email_key'] ); ?>" class="hbl-input hbl-pid-mono" style="max-width:180px">
							</div>
							<div class="hbl-pid-field">
								<label for="hbl-em-website-key"><?php esc_html_e( 'Website meta key', 'hbl' ); ?></label>
								<input type="text" id="hbl-em-website-key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[website_key]" value="<?php echo esc_attr( $settings['website_key'] ); ?>" class="hbl-input hbl-pid-mono" style="max-width:180px">
							</div>
							<div class="hbl-pid-field">
								<label for="hbl-em-per-page"><?php esc_html_e( 'Rows per page', 'hbl' ); ?></label>
								<input type="number" id="hbl-em-per-page" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[per_page]" value="<?php echo esc_attr( (string) $settings['per_page'] ); ?>" class="hbl-input" style="max-width:80px" min="10" max="100">
							</div>
						</div>
						<?php submit_button( __( 'Save Settings', 'hbl' ), 'primary', 'submit', false ); ?>
					</div>
				</form>

				<div class="hbl-reassign-step">
					<div class="hbl-step-header" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
						<div style="display:flex;align-items:center;gap:14px">
							<span class="hbl-step-number">2</span>
							<h2><?php esc_html_e( 'Listings without emails', 'hbl' ); ?></h2>
						</div>
						<button type="button" id="hbl-em-apply-btn" class="hbl-pid-btn hbl-pid-btn--apply" style="display:none" disabled>
							<span class="hbl-pid-btn-label"><?php esc_html_e( 'Apply Selected', 'hbl' ); ?> (<span id="hbl-em-apply-count">0</span>)</span>
						</button>
					</div>

					<div class="hbl-pid-toolbar">
						<div class="hbl-pid-search-wrap">
							<input type="text" id="hbl-em-search" class="hbl-input" placeholder="<?php esc_attr_e( 'Search by name…', 'hbl' ); ?>">
							<button type="button" id="hbl-em-search-btn" class="hbl-pid-btn"><?php esc_html_e( 'Search', 'hbl' ); ?></button>
							<button type="button" id="hbl-em-reset-btn" class="hbl-pid-btn" style="display:none"><?php esc_html_e( 'Clear', 'hbl' ); ?></button>
							<button type="button" id="hbl-em-find-all-btn" class="hbl-pid-btn hbl-pid-btn--find-all">
								<span class="hbl-pid-btn-label"><?php esc_html_e( 'Auto Find All', 'hbl' ); ?></span>
							</button>
						</div>
						<div id="hbl-em-pagination"></div>
					</div>

					<div class="hbl-table-card">
						<table class="hbl-pid-log-table">
							<thead>
								<tr>
									<th style="width:36px;text-align:center"><input type="checkbox" id="hbl-em-select-all"></th>
									<th style="width:36px">#</th>
									<th><?php esc_html_e( 'Business Name', 'hbl' ); ?></th>
									<th style="width:200px"><?php esc_html_e( 'Website', 'hbl' ); ?></th>
									<th style="width:240px"><?php esc_html_e( 'Email', 'hbl' ); ?></th>
									<th style="width:200px"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
								</tr>
							</thead>
							<tbody id="hbl-em-tbody">
								<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280"><?php esc_html_e( 'Loading…', 'hbl' ); ?></td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function () {
			'use strict';
			var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			var AJAX  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			var tbody = document.getElementById( 'hbl-em-tbody' );
			var applyBtn = document.getElementById( 'hbl-em-apply-btn' );
			var applyCount = document.getElementById( 'hbl-em-apply-count' );
			var selectAll = document.getElementById( 'hbl-em-select-all' );
			var pagination = document.getElementById( 'hbl-em-pagination' );
			var searchInput = document.getElementById( 'hbl-em-search' );
			var searchBtn = document.getElementById( 'hbl-em-search-btn' );
			var resetBtn = document.getElementById( 'hbl-em-reset-btn' );
			var findAllBtn = document.getElementById( 'hbl-em-find-all-btn' );
			var statMissing = document.getElementById( 'hbl-em-stat-missing' );
			var statSaved = document.getElementById( 'hbl-em-stat-saved' );
			var state = { page: 1, search: '', saved: 0 };

			function esc( s ) { var d = document.createElement( 'div' ); d.textContent = s || ''; return d.innerHTML; }
			function isEmail( v ) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( v ); }
			function post( action, data ) {
				var fd = new FormData();
				fd.append( 'action', action ); fd.append( 'nonce', NONCE );
				Object.keys( data || {} ).forEach( function (k) { fd.append( k, data[k] ); } );
				return fetch( AJAX, { method: 'POST', body: fd } ).then( function (r) { return r.json(); } );
			}

			function rowCbs() { return Array.prototype.slice.call( tbody.querySelectorAll( '.hbl-em-row-cb:not(:disabled)' ) ); }
			function updateApply() {
				var n = rowCbs().filter( function (cb) { return cb.checked; } ).length;
				applyBtn.style.display = n ? '' : 'none';
				applyBtn.disabled = ! n;
				applyCount.textContent = n;
				var all = rowCbs();
				selectAll.checked = all.length > 0 && all.every( function (cb) { return cb.checked; } );
			}

			tbody.addEventListener( 'change', function (e) { if ( e.target.classList.contains( 'hbl-em-row-cb' ) ) updateApply(); } );
			tbody.addEventListener( 'input', function (e) {
				if ( ! e.target.classList.contains( 'hbl-em-input' ) ) return;
				var v = e.target.value.trim();
				var cb = e.target.closest( 'tr' ).querySelector( '.hbl-em-row-cb' );
				if ( cb ) cb.checked = isEmail( v );
				e.target.classList.toggle( 'hbl-pid-input--invalid', v !== '' && ! isEmail( v ) );
				updateApply();
			} );
			selectAll.addEventListener( 'change', function () {
				rowCbs().forEach( function (cb) {
					var inp = cb.closest( 'tr' ).querySelector( '.hbl-em-input' );
					cb.checked = selectAll.checked && inp && isEmail( inp.value.trim() );
				} );
				updateApply();
			} );

			function refreshMissing() {
				post( 'hbl_email_count_missing' ).then( function (r) { if ( r.success ) statMissing.textContent = r.data.count; } );
			}

			function load( page ) {
				state.page = page;
				tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280">Loading…</td></tr>';
				post( 'hbl_email_load_page', { page: page, search: state.search } ).then( function (res) {
					if ( ! res.success ) { tbody.innerHTML = '<tr><td colspan="6" style="color:#DC2626;padding:16px">Error loading listings.</td></tr>'; return; }
					render( res.data );
				} ).catch( function () { tbody.innerHTML = '<tr><td colspan="6" style="color:#DC2626;padding:16px">Network error.</td></tr>'; } );
			}

			function render( d ) {
				if ( ! d.items.length ) {
					tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#6B7280">No listings missing an email.</td></tr>';
				} else {
					tbody.innerHTML = d.items.map( function (it, i) {
						var site = it.website ? '<a href="' + esc( it.website ) + '" target="_blank" rel="noopener">' + esc( it.website.replace( /^https?:\/\/(www\.)?/, '' ) ) + '</a>' : '—';
						return '<tr data-listing-id="' + it.id + '">' +
							'<td style="text-align:center"><input type="checkbox" class="hbl-em-row-cb"></td>' +
							'<td>' + ( ( d.page - 1 ) * d.per_page + i + 1 ) + '</td>' +
							'<td><a href="' + esc( it.edit_url ) + '" target="_blank">' + esc( it.title ) + '</a></td>' +
							'<td>' + site + '</td>' +
							'<td><input type="text" class="hbl-pid-input hbl-em-input" placeholder="name@example.com"></td>' +
							'<td><div class="hbl-pid-actions">' +
								'<button type="button" class="hbl-pid-btn hbl-pid-btn--find hbl-em-find-btn"><span class="hbl-pid-btn-label">Auto Find</span></button>' +
								'<button type="button" class="hbl-pid-btn hbl-pid-btn--save hbl-em-save-btn"><span class="hbl-pid-btn-label">Save</span></button>' +
							'</div></td></tr>';
					} ).join( '' );
				}
				var h = '<span class="hbl-pid-total">' + d.total + ' listing(s)</span>';
				if ( d.max_pages > 1 ) {
					h += '<div class="hbl-pid-page-btns">';
					if ( d.page > 1 ) h += '<button type="button" class="button hbl-em-page" data-page="' + ( d.page - 1 ) + '">← Prev</button>';
					h += '<span class="hbl-pid-page-info">Page ' + d.page + ' / ' + d.max_pages + '</span>';
					if ( d.page < d.max_pages ) h += '<button type="button" class="button hbl-em-page" data-page="' + ( d.page + 1 ) + '">Next →</button>';
					h += '</div>';
				}
				pagination.innerHTML = h;
				updateApply();
			}

			pagination.addEventListener( 'click', function (e) {
				if ( e.target.classList.contains( 'hbl-em-page' ) ) load( parseInt( e.target.dataset.page, 10 ) );
			} );

			function markSaved( row ) {
				var inp = row.querySelector( '.hbl-em-input' ), cb = row.querySelector( '.hbl-em-row-cb' ), btn = row.querySelector( '.hbl-em-save-btn' );
				inp.disabled = true; inp.classList.add( 'hbl-pid-input--saved' );
				cb.checked = false; cb.disabled = true;
				btn.disabled = true; btn.classList.add( 'is-done' ); btn.querySelector( '.hbl-pid-btn-label' ).textContent = 'Saved';
			}

			function save( items, done ) {
				post( 'hbl_email_save_selected', { items: JSON.stringify( items ) } ).then( function (res) {
					if ( ! res.success ) { alert( 'Save failed: ' + ( res.data || 'Unknown error.' ) ); return; }
					res.data.saved_ids.forEach( function (id) {
						var row = tbody.querySelector( 'tr[data-listing-id="' + id + '"]' );
						if ( row ) markSaved( row );
					} );
					state.saved += res.data.saved;
					statSaved.textContent = state.saved;
					if ( res.data.failed ) alert( res.data.failed + ' item(s) failed to save.' );
					refreshMissing(); updateApply();
					if ( done ) done();
				} ).catch( function (e) { alert( 'Network error: ' + e.message ); } );
			}

			tbody.addEventListener( 'click', function (e) {
				var row = e.target.closest( 'tr' );
				if ( ! row ) return;
				var inp = row.querySelector( '.hbl-em-input' );
				if ( e.target.closest( '.hbl-em-save-btn' ) ) {
					var v = inp.value.trim();
					if ( ! isEmail( v ) ) { alert( 'Please enter a valid email address.' ); return; }
					save( [ { listing_id: parseInt( row.dataset.listingId, 10 ), email: v } ] );
				} else if ( e.target.closest( '.hbl-em-find-btn' ) ) {
					find( row, e.target.closest( '.hbl-em-find-btn' ) );
				}
			} );

			function find( row, btn, quiet ) {
				var inp = row.querySelector( '.hbl-em-input' );
				var lbl = btn.querySelector( '.hbl-pid-btn-label' );
				btn.disabled = true; lbl.textContent = 'Searching…';
				return post( 'hbl_email_auto_find', { listing_id: row.dataset.listingId } ).then( function (res) {
					btn.disabled = false; lbl.textContent = 'Auto Find';
					if ( ! res.success ) {
						inp.placeholder = res.data || 'Not found';
						if ( ! quiet ) alert( 'Auto Find: ' + ( res.data || 'Unknown error.' ) );
						return;
					}
					inp.value = res.data.email;
					inp.title = res.data.emails.length > 1 ? 'Other candidates: ' + res.data.emails.slice( 1 ).join( ', ' ) : '';
					inp.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				} ).catch( function (e) {
					btn.disabled = false; lbl.textContent = 'Auto Find';
					if ( ! quiet ) alert( 'Network error: ' + e.message );
				} );
			}

			findAllBtn.addEventListener( 'click', function () {
				var rows = Array.prototype.slice.call( tbody.querySelectorAll( 'tr[data-listing-id]' ) ).filter( function (r) {
					var i = r.querySelector( '.hbl-em-input' );
					return i && ! i.disabled && ! i.value.trim();
				} );
				if ( ! rows.length ) { alert( 'No empty rows to fill on this page.' ); return; }
				var lbl = findAllBtn.querySelector( '.hbl-pid-btn-label' ), n = 0;
				findAllBtn.disabled = true;
				( function next() {
					if ( n >= rows.length ) { findAllBtn.disabled = false; lbl.textContent = 'Auto Find All'; return; }
					var row = rows[ n++ ];
					lbl.textContent = 'Finding… (' + n + '/' + rows.length + ')';
					find( row, row.querySelector( '.hbl-em-find-btn' ), true ).then( function () { setTimeout( next, 1000 ); } );
				} )();
			} );

			applyBtn.addEventListener( 'click', function () {
				var items = rowCbs().filter( function (cb) { return cb.checked; } ).map( function (cb) {
					var row = cb.closest( 'tr' );
					return { listing_id: parseInt( row.dataset.listingId, 10 ), email: row.querySelector( '.hbl-em-input' ).value.trim() };
				} );
				if ( ! items.length || ! confirm( items.length + ' email(s) will be saved. Continue?' ) ) return;
				save( items );
			} );

			searchBtn.addEventListener( 'click', function () { state.search = searchInput.value.trim(); resetBtn.style.display = state.search ? '' : 'none'; load( 1 ); } );
			searchInput.addEventListener( 'keydown', function (e) { if ( e.key === 'Enter' ) { e.preventDefault(); searchBtn.click(); } } );
			resetBtn.addEventListener( 'click', function () { searchInput.value = ''; state.search = ''; resetBtn.style.display = 'none'; load( 1 ); } );

			refreshMissing();
			load( 1 );
		})();
		</script>
		<?php
	}
}

HBL_Email_Finder::get_instance();
