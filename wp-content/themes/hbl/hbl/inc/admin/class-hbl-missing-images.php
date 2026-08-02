<?php
/**
 * HBL Missing Listing Images Tool
 *
 * Finds listings with no logo or the fallback placeholder image.
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Missing_Images {

	const FALLBACK_URL = 'https://herveybaylocal.com.au/wp-content/uploads/2025/12/HBL-fallback-landscape-600x400-1.webp';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 33 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_hbl_load_missing_images', array( $this, 'ajax_load_listings' ) );
	}

	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Missing Images', 'hbl' ),
			__( 'Missing Images', 'hbl' ),
			'manage_options',
			'hbl-missing-images',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-missing-images' !== $hook ) {
			return;
		}

		// Base layout styles (stats, steps, filters, table, confirm section).
		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
			array( 'hbl-admin-font-poppins' ),
			HBL_VERSION
		);

		// Action-card grid styles (hbl-bpr-plan-grid, hbl-bpr-plan-card, etc.).
		wp_enqueue_style(
			'hbl-bulk-plan-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-plan-reassign.css',
			array( 'hbl-bulk-reassign' ),
			HBL_VERSION
		);

		// Small overrides (badges, group bar).
		wp_enqueue_style(
			'hbl-duplicate-listings',
			HBL_THEME_URI . '/inc/admin/css/duplicate-listings.css',
			array( 'hbl-bulk-plan-reassign' ),
			HBL_VERSION
		);
	}

	/* ------------------------------------------------------------------ */
	/* Counts                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * A listing has NO image when all three image sources are empty/zero.
	 * Sources: _custom-file (Directorist logo), _listing_prv_img (preview),
	 * _thumbnail_id (WP featured image).
	 */
	private function count_no_image() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm_logo  ON pm_logo.post_id  = p.ID AND pm_logo.meta_key  = '_custom-file'
			 LEFT JOIN {$wpdb->postmeta} pm_prv   ON pm_prv.post_id   = p.ID AND pm_prv.meta_key   = '_listing_prv_img'
			 LEFT JOIN {$wpdb->postmeta} pm_thumb ON pm_thumb.post_id = p.ID AND pm_thumb.meta_key = '_thumbnail_id'
			 WHERE p.post_type = 'at_biz_dir'
			 AND p.post_status IN ('publish','draft','pending','private')
			 AND ( pm_logo.meta_value  IS NULL OR pm_logo.meta_value  = '' )
			 AND ( pm_prv.meta_value   IS NULL OR pm_prv.meta_value   = '' OR pm_prv.meta_value   = '0' )
			 AND ( pm_thumb.meta_value IS NULL OR pm_thumb.meta_value = '' OR pm_thumb.meta_value = '0' )"
		);
	}

	private function count_placeholder() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_custom-file'
			 WHERE p.post_type = 'at_biz_dir'
			 AND p.post_status IN ('publish','draft','pending','private')
			 AND pm.meta_value LIKE %s",
			$wpdb->esc_like( self::FALLBACK_URL ) . '%'
		) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: Load listings                                                   */
	/* ------------------------------------------------------------------ */

	public function ajax_load_listings() {
		check_ajax_referer( 'hbl_missing_images_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		global $wpdb;

		$filter = isset( $_POST['filter'] ) ? sanitize_key( $_POST['filter'] ) : 'all';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// Shared JOIN — check all three image sources.
		$base_joins = "LEFT JOIN {$wpdb->postmeta} pm_logo  ON pm_logo.post_id  = p.ID AND pm_logo.meta_key  = '_custom-file'
					   LEFT JOIN {$wpdb->postmeta} pm_prv   ON pm_prv.post_id   = p.ID AND pm_prv.meta_key   = '_listing_prv_img'
					   LEFT JOIN {$wpdb->postmeta} pm_thumb ON pm_thumb.post_id = p.ID AND pm_thumb.meta_key = '_thumbnail_id'";

		// "Truly no image" condition: all three sources are absent/empty.
		$no_image_where = "( pm_logo.meta_value  IS NULL OR pm_logo.meta_value  = '' )
						   AND ( pm_prv.meta_value   IS NULL OR pm_prv.meta_value   = '' OR pm_prv.meta_value   = '0' )
						   AND ( pm_thumb.meta_value IS NULL OR pm_thumb.meta_value = '' OR pm_thumb.meta_value = '0' )";

		if ( 'no_image' === $filter ) {
			$sql = "SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_author, p.post_date,
					       pm_logo.meta_value AS logo_val, pm_prv.meta_value AS prv_val, pm_thumb.meta_value AS thumb_val
					FROM {$wpdb->posts} p
					{$base_joins}
					WHERE p.post_type = 'at_biz_dir'
					AND p.post_status IN ('publish','draft','pending','private')
					AND {$no_image_where}";
		} elseif ( 'placeholder' === $filter ) {
			// Has fallback placeholder in the logo field (regardless of other images).
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_author, p.post_date,
				        pm_logo.meta_value AS logo_val, pm_prv.meta_value AS prv_val, pm_thumb.meta_value AS thumb_val
				 FROM {$wpdb->posts} p
				 {$base_joins}
				 WHERE p.post_type = 'at_biz_dir'
				 AND p.post_status IN ('publish','draft','pending','private')
				 AND pm_logo.meta_value LIKE %s",
				$wpdb->esc_like( self::FALLBACK_URL ) . '%'
			);
		} else {
			// All: truly no image anywhere, OR logo is the placeholder.
			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_author, p.post_date,
				        pm_logo.meta_value AS logo_val, pm_prv.meta_value AS prv_val, pm_thumb.meta_value AS thumb_val
				 FROM {$wpdb->posts} p
				 {$base_joins}
				 WHERE p.post_type = 'at_biz_dir'
				 AND p.post_status IN ('publish','draft','pending','private')
				 AND (
				     ( {$no_image_where} )
				     OR pm_logo.meta_value LIKE %s
				 )",
				$wpdb->esc_like( self::FALLBACK_URL ) . '%'
			);
		}

		if ( $search ) {
			$sql .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		$sql .= ' ORDER BY p.post_title ASC';

		$rows     = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$listings = array();

		foreach ( $rows as $row ) {
			$plan_id   = get_post_meta( $row->ID, '_fm_plans', true );
			$plan_name = $plan_id ? ( get_post( (int) $plan_id ) ? get_post( (int) $plan_id )->post_title : __( 'No Plan', 'hbl' ) ) : __( 'No Plan', 'hbl' );

			// Determine image status badge.
			if ( ! empty( $row->logo_val ) && strpos( $row->logo_val, self::FALLBACK_URL ) === 0 ) {
				$image_type = 'placeholder';
			} elseif ( empty( $row->logo_val ) && empty( $row->prv_val ) && empty( $row->thumb_val ) ) {
				$image_type = 'none';
			} else {
				$image_type = 'none'; // fallback (shouldn't reach here in practice)
			}

			// Build admin edit URL directly — get_edit_post_link() can return NULL in AJAX context.
			$edit_url = admin_url( 'post.php?post=' . $row->ID . '&action=edit' );

			$listings[] = array(
				'id'         => $row->ID,
				'title'      => $row->post_title,
				'status'     => $row->post_status,
				'date'       => get_date_from_gmt( $row->post_date, 'Y-m-d' ),
				'edit_url'   => $edit_url,
				'view_url'   => get_permalink( $row->ID ),
				'author'     => get_the_author_meta( 'display_name', $row->post_author ),
				'plan'       => $plan_name,
				'image_type' => $image_type,
			);
		}

		wp_send_json_success( array(
			'listings' => $listings,
			'total'    => count( $listings ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                                */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		$no_image    = $this->count_no_image();
		$placeholder = $this->count_placeholder();
		$nonce       = wp_create_nonce( 'hbl_missing_images_nonce' );
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-dup-wrap">

			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px;">
					<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
					<circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/>
					<path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<line x1="3" y1="3" x2="21" y2="21" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/>
				</svg>
				<?php esc_html_e( 'Missing Listing Images', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Find listings with no logo or the fallback placeholder image, then open them for editing.', 'hbl' ); ?></p>

			<!-- Stats -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $no_image + $placeholder ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Total Listings Affected', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--danger">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/><path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $no_image ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'No Image At All', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2" stroke-dasharray="4 3"/><circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $placeholder ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Using Placeholder Image', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<div class="hbl-reassign-container">

				<!-- Step 1: Filter & Find -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">1</span>
						<h2><?php esc_html_e( 'Filter & Find Listings', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-bpr-filters">
						<div class="hbl-bpr-filter-group">
							<label for="hbl-mi-filter"><?php esc_html_e( 'Show listings that have:', 'hbl' ); ?></label>
							<select id="hbl-mi-filter" class="hbl-select">
								<option value="all"><?php esc_html_e( 'No image OR placeholder', 'hbl' ); ?></option>
								<option value="no_image"><?php esc_html_e( 'No image at all', 'hbl' ); ?></option>
								<option value="placeholder"><?php esc_html_e( 'Placeholder image only', 'hbl' ); ?></option>
							</select>
						</div>
						<div class="hbl-bpr-filter-group">
							<label for="hbl-mi-search"><?php esc_html_e( 'Search by listing name:', 'hbl' ); ?></label>
							<input type="text" id="hbl-mi-search" class="hbl-input" placeholder="<?php esc_attr_e( 'Type to narrow...', 'hbl' ); ?>">
						</div>
						<div class="hbl-bpr-filter-group hbl-bpr-filter-actions">
							<button type="button" id="hbl-mi-load" class="button button-secondary">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
									<path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<?php esc_html_e( 'Find Listings', 'hbl' ); ?>
							</button>
						</div>
					</div>

					<!-- Table -->
					<div id="hbl-mi-listings-wrap" class="hbl-bpr-listings-wrap" style="display:none;">
						<div class="hbl-bpr-table-header">
							<label class="hbl-select-all-wrap">
								<input type="checkbox" id="hbl-mi-select-all">
								<span><?php esc_html_e( 'Select All', 'hbl' ); ?></span>
							</label>
							<span class="hbl-selected-count">
								<span id="hbl-mi-selected-count">0</span> <?php esc_html_e( 'listing(s) selected', 'hbl' ); ?>
							</span>
						</div>
						<div id="hbl-mi-table-body"></div>
					</div>

					<div id="hbl-mi-message" class="hbl-bpr-message" style="display:none;"></div>
				</div>

				<!-- Step 2: Action -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">2</span>
						<h2><?php esc_html_e( 'Choose Bulk Action', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-target-section">
						<div class="hbl-bpr-plan-grid">

							<div class="hbl-bpr-plan-card hbl-dup-action-card" data-action="edit" id="hbl-mi-action-edit">
								<label class="hbl-bpr-plan-label">
									<input type="radio" name="hbl_mi_action" value="edit" class="hbl-bpr-plan-radio">
									<div class="hbl-bpr-plan-content">
										<div class="hbl-bpr-plan-icon">
											<svg width="22" height="22" viewBox="0 0 24 24" fill="none">
												<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-bpr-plan-info">
											<span class="hbl-bpr-plan-name"><?php esc_html_e( 'Edit Selected', 'hbl' ); ?></span>
											<span class="hbl-bpr-plan-type"><?php esc_html_e( 'Open each listing in a new admin tab to upload an image', 'hbl' ); ?></span>
										</div>
										<div class="hbl-bpr-plan-check">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
										</div>
									</div>
								</label>
							</div>

						</div>
					</div>
				</div>

				<!-- Step 3: Execute -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">3</span>
						<h2><?php esc_html_e( 'Confirm & Execute', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-confirm-section">
						<div class="hbl-summary" id="hbl-mi-summary">
							<p><?php esc_html_e( 'Select listings and an action above to see a summary here.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-actions">
							<button type="button" id="hbl-mi-execute" class="button button-primary button-hero" disabled>
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none">
									<path d="M22 11.08V12a10 10 0 11-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span id="hbl-mi-execute-label"><?php esc_html_e( 'Apply Action', 'hbl' ); ?></span>
							</button>
						</div>
					</div>
				</div>

			</div><!-- .hbl-reassign-container -->
		</div>

		<script>
		(function($) {
			var nonce         = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl       = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var selectedAction = '';

			function getSelectedIds() {
				return $('.hbl-mi-row-cb:checked').map(function(){ return $(this).val(); }).get();
			}

			function getSelectedEditUrls() {
				var urls = [];
				$('.hbl-mi-row-cb:checked').each(function(){
					urls.push( $(this).data('edit-url') );
				});
				return urls;
			}

			function updateSummary() {
				var ids  = getSelectedIds();
				var $btn = $('#hbl-mi-execute');
				var $sum = $('#hbl-mi-summary');

				if ( ! ids.length || ! selectedAction ) {
					$sum.html('<p><?php echo esc_js( __( 'Select listings and an action above to see a summary here.', 'hbl' ) ); ?></p>');
					$btn.prop('disabled', true);
					return;
				}

				$sum.html('<p><strong>' + ids.length + '</strong> <?php echo esc_js( __( 'listing(s) selected', 'hbl' ) ); ?> &mdash; <?php echo esc_js( __( 'Action', 'hbl' ) ); ?>: <strong><?php echo esc_js( __( 'Open for Editing', 'hbl' ) ); ?></strong></p>');
				$('#hbl-mi-execute-label').text('<?php echo esc_js( __( 'Open Selected for Editing', 'hbl' ) ); ?>');
				$btn.prop('disabled', false);
			}

			function statusBadge( status ) {
				var cls = { publish: 'hbl-bpr-status-badge--publish', draft: 'hbl-bpr-status-badge--draft', pending: 'hbl-bpr-status-badge--pending' }[ status ] || 'hbl-bpr-status-badge--draft';
				return '<span class="hbl-bpr-status-badge ' + cls + '">' + status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
			}

			function imageBadge( type ) {
				if ( type === 'placeholder' ) {
					return '<span class="hbl-bpr-plan-badge" style="background:rgba(245,158,11,.12);color:#92400e;border-color:rgba(245,158,11,.3);"><?php echo esc_js( __( 'Placeholder', 'hbl' ) ); ?></span>';
				}
				return '<span class="hbl-bpr-plan-badge hbl-dup-badge-dupe"><?php echo esc_js( __( 'No Image', 'hbl' ) ); ?></span>';
			}

			function renderTable( listings ) {
				if ( ! listings.length ) {
					$('#hbl-mi-message').show().addClass('hbl-bpr-message--empty').text('<?php echo esc_js( __( 'No listings found matching the filter. Great news — all listings have images!', 'hbl' ) ); ?>');
					$('#hbl-mi-listings-wrap').hide();
					return;
				}

				$('#hbl-mi-message').hide();

				var html = '<table class="hbl-bpr-table">';
				html += '<thead><tr>';
				html += '<th></th>';
				html += '<th><?php echo esc_js( __( 'Listing', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Image Status', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Status', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Plan', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Author', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Date', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'ID', 'hbl' ) ); ?></th>';
				html += '<th><?php echo esc_js( __( 'Actions', 'hbl' ) ); ?></th>';
				html += '</tr></thead><tbody>';

				$.each( listings, function( i, l ) {
					var editUrl = $('<div>').text( l.edit_url ).html();
					var viewUrl = $('<div>').text( l.view_url ).html();
					html += '<tr class="hbl-bpr-row">';
					html += '<td data-label=""><input type="checkbox" class="hbl-mi-row-cb" value="' + l.id + '" data-edit-url="' + editUrl + '"></td>';
					html += '<td class="hbl-bpr-listing-title" data-label="<?php echo esc_js( __( 'Listing', 'hbl' ) ); ?>"><a href="' + (viewUrl || editUrl) + '" target="_blank">' + $('<div>').text(l.title).html() + '</a></td>';
					html += '<td data-label="<?php echo esc_js( __( 'Image Status', 'hbl' ) ); ?>">' + imageBadge( l.image_type ) + '</td>';
					html += '<td data-label="<?php echo esc_js( __( 'Status', 'hbl' ) ); ?>">' + statusBadge( l.status ) + '</td>';
					html += '<td data-label="<?php echo esc_js( __( 'Plan', 'hbl' ) ); ?>"><span class="hbl-bpr-plan-badge' + (l.plan === '<?php echo esc_js( __( 'No Plan', 'hbl' ) ); ?>' ? ' hbl-bpr-plan-badge--none' : '') + '">' + $('<div>').text(l.plan).html() + '</span></td>';
					html += '<td data-label="<?php echo esc_js( __( 'Author', 'hbl' ) ); ?>">' + $('<div>').text(l.author).html() + '</td>';
					html += '<td data-label="<?php echo esc_js( __( 'Date', 'hbl' ) ); ?>">' + $('<div>').text(l.date).html() + '</td>';
					html += '<td data-label="<?php echo esc_js( __( 'ID', 'hbl' ) ); ?>"><code>' + l.id + '</code></td>';
					html += '<td data-label="<?php echo esc_js( __( 'Actions', 'hbl' ) ); ?>" style="white-space:nowrap;">';
					html += '<a href="' + editUrl + '" target="_blank" class="button button-small"><?php echo esc_js( __( 'Edit', 'hbl' ) ); ?></a> ';
					if ( l.view_url ) {
						html += '<a href="' + viewUrl + '" target="_blank" class="button button-small"><?php echo esc_js( __( 'View Listing', 'hbl' ) ); ?></a>';
					}
					html += '</td>';
					html += '</tr>';
				});

				html += '</tbody></table>';

				$('#hbl-mi-table-body').html(html);
				$('#hbl-mi-listings-wrap').show();
				updateSelectedCount();
			}

			function updateSelectedCount() {
				$('#hbl-mi-selected-count').text( getSelectedIds().length );
				updateSummary();
			}

			/* Load */
			$('#hbl-mi-load').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Loading…', 'hbl' ) ); ?>');
				$('#hbl-mi-message').hide();
				$('#hbl-mi-listings-wrap').hide();

				$.post( ajaxUrl, {
					action:  'hbl_load_missing_images',
					nonce:   nonce,
					filter:  $('#hbl-mi-filter').val(),
					search:  $('#hbl-mi-search').val(),
				}, function(res) {
					if ( res.success ) {
						renderTable( res.data.listings );
					} else {
						$('#hbl-mi-message').show().addClass('hbl-bpr-message--empty').text( res.data.message || '<?php echo esc_js( __( 'Error loading data.', 'hbl' ) ); ?>' );
					}
				}).always(function() {
					$btn.prop('disabled', false).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> <?php echo esc_js( __( 'Find Listings', 'hbl' ) ); ?>');
				});
			});

			/* Select all */
			$('#hbl-mi-select-all').on('change', function() {
				$('.hbl-mi-row-cb').prop('checked', this.checked);
				$('.hbl-bpr-row').toggleClass('hbl-bpr-selected', this.checked);
				updateSelectedCount();
			});

			$(document).on('change', '.hbl-mi-row-cb', function() {
				$(this).closest('tr').toggleClass('hbl-bpr-selected', this.checked);
				updateSelectedCount();
			});

			/* Action cards */
			$(document).on('change', 'input[name="hbl_mi_action"]', function() {
				selectedAction = $(this).val();
				$('.hbl-dup-action-card').removeClass('hbl-bpr-plan-selected');
				$(this).closest('.hbl-dup-action-card').addClass('hbl-bpr-plan-selected');
				updateSummary();
			});

			$('.hbl-dup-action-card').on('click', function() {
				$(this).find('input[name="hbl_mi_action"]').prop('checked', true).trigger('change');
			});

			/* Execute */
			$('#hbl-mi-execute').on('click', function() {
				var urls = getSelectedEditUrls();
				if ( ! urls.length ) { return; }
				$.each( urls, function( i, url ) {
					window.open( url, '_blank' );
				});
			});

		})(jQuery);
		</script>
		<?php
	}
}

HBL_Missing_Images::get_instance();
