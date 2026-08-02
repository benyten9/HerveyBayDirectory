<?php
/**
 * HBL Duplicate Listings Tool
 *
 * Shows duplicate listings grouped by title or phone and allows bulk trash/edit.
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Duplicate_Listings {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 32 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_hbl_load_duplicates', array( $this, 'ajax_load_duplicates' ) );
		add_action( 'wp_ajax_hbl_bulk_trash_duplicates', array( $this, 'ajax_bulk_trash' ) );
	}

	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Duplicate Listings', 'hbl' ),
			__( 'Duplicate Listings', 'hbl' ),
			'manage_options',
			'hbl-duplicate-listings',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-duplicate-listings' !== $hook ) {
			return;
		}

		// Base styles shared across all tools.
		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
			array( 'hbl-admin-font-poppins' ),
			HBL_VERSION
		);

		// Layout styles (stats cards, steps, confirm section, etc.) from Plan Reassign.
		wp_enqueue_style(
			'hbl-bulk-plan-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-plan-reassign.css',
			array( 'hbl-bulk-reassign' ),
			HBL_VERSION
		);

		// Small overrides specific to duplicate listings.
		wp_enqueue_style(
			'hbl-duplicate-listings',
			HBL_THEME_URI . '/inc/admin/css/duplicate-listings.css',
			array( 'hbl-bulk-plan-reassign' ),
			HBL_VERSION
		);
	}

	/* ------------------------------------------------------------------ */
	/* Summary counts                                                        */
	/* ------------------------------------------------------------------ */

	private function count_duplicate_groups( $type ) {
		global $wpdb;

		if ( 'phone' === $type ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT pm.meta_value
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_phone'
					AND pm.meta_value != ''
					AND p.post_type = 'at_biz_dir'
					AND p.post_status IN ('publish','draft','pending','private')
					GROUP BY pm.meta_value
					HAVING COUNT(*) > 1
				) AS sub"
			);
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT LOWER(TRIM(post_title))
				FROM {$wpdb->posts}
				WHERE post_type = 'at_biz_dir'
				AND post_status IN ('publish','draft','pending','private')
				GROUP BY LOWER(TRIM(post_title))
				HAVING COUNT(*) > 1
			) AS sub"
		);
	}

	private function count_affected_listings( $type ) {
		global $wpdb;

		if ( 'phone' === $type ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM (
					SELECT pm.post_id
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_phone'
					AND pm.meta_value != ''
					AND p.post_type = 'at_biz_dir'
					AND p.post_status IN ('publish','draft','pending','private')
					AND pm.meta_value IN (
						SELECT meta_value FROM {$wpdb->postmeta} pm2
						INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm2.post_id
						WHERE pm2.meta_key = '_phone' AND pm2.meta_value != ''
						AND p2.post_type = 'at_biz_dir'
						AND p2.post_status IN ('publish','draft','pending','private')
						GROUP BY pm2.meta_value HAVING COUNT(*) > 1
					)
				) AS sub"
			);
		}

		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'at_biz_dir'
				AND post_status IN ('publish','draft','pending','private')
				AND LOWER(TRIM(post_title)) IN (
					SELECT LOWER(TRIM(post_title)) FROM {$wpdb->posts}
					WHERE post_type = 'at_biz_dir'
					AND post_status IN ('publish','draft','pending','private')
					GROUP BY LOWER(TRIM(post_title))
					HAVING COUNT(*) > 1
				)
			) AS sub"
		);
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: Load duplicates                                                 */
	/* ------------------------------------------------------------------ */

	public function ajax_load_duplicates() {
		check_ajax_referer( 'hbl_duplicate_listings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		global $wpdb;

		$type   = isset( $_POST['type'] ) && 'phone' === $_POST['type'] ? 'phone' : 'title';
		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		if ( 'phone' === $type ) {
			$sql = "SELECT pm.meta_value AS label,
			               GROUP_CONCAT(pm.post_id ORDER BY pm.post_id ASC SEPARATOR ',') AS ids
			        FROM {$wpdb->postmeta} pm
			        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			        WHERE pm.meta_key = '_phone'
			        AND pm.meta_value != ''
			        AND p.post_type = 'at_biz_dir'
			        AND p.post_status IN ('publish','draft','pending','private')";
			if ( $search ) {
				$sql .= $wpdb->prepare( " AND pm.meta_value LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
			}
			$sql .= " GROUP BY pm.meta_value HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC, pm.meta_value ASC";
		} else {
			$sql = "SELECT LOWER(TRIM(post_title)) AS label,
			               GROUP_CONCAT(ID ORDER BY ID ASC SEPARATOR ',') AS ids
			        FROM {$wpdb->posts}
			        WHERE post_type = 'at_biz_dir'
			        AND post_status IN ('publish','draft','pending','private')";
			if ( $search ) {
				$sql .= $wpdb->prepare( " AND post_title LIKE %s", '%' . $wpdb->esc_like( $search ) . '%' );
			}
			$sql .= " GROUP BY LOWER(TRIM(post_title)) HAVING COUNT(*) > 1 ORDER BY COUNT(*) DESC, post_title ASC";
		}

		$rows   = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$groups = array();

		foreach ( $rows as $row ) {
			$ids   = array_map( 'intval', explode( ',', $row->ids ) );
			$posts = array();

			foreach ( $ids as $id ) {
				$post = get_post( $id );
				if ( ! $post ) {
					continue;
				}
				$posts[] = array(
					'id'       => $id,
					'title'    => $post->post_title,
					'status'   => $post->post_status,
					'date'     => get_the_date( 'Y-m-d', $post ),
					'edit_url' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
					'view_url' => get_permalink( $id ),
					'phone'    => get_post_meta( $id, '_phone', true ),
					'address'  => get_post_meta( $id, '_address', true ),
					'author'   => get_the_author_meta( 'display_name', $post->post_author ),
					'plan'     => $this->get_plan_name( get_post_meta( $id, '_fm_plans', true ) ),
				);
			}

			if ( count( $posts ) > 1 ) {
				$groups[] = array(
					'label' => 'title' === $type ? ucwords( $row->label ) : $row->label,
					'posts' => $posts,
					'count' => count( $posts ),
				);
			}
		}

		wp_send_json_success( array(
			'groups' => $groups,
			'total'  => count( $groups ),
		) );
	}

	private function get_plan_name( $plan_id ) {
		if ( ! $plan_id ) {
			return __( 'No Plan', 'hbl' );
		}
		$plan = get_post( (int) $plan_id );
		return $plan ? $plan->post_title : __( 'No Plan', 'hbl' );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: Bulk trash                                                      */
	/* ------------------------------------------------------------------ */

	public function ajax_bulk_trash() {
		check_ajax_referer( 'hbl_duplicate_listings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No listings selected.', 'hbl' ) ) );
		}

		$trashed = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			if ( get_post_type( $id ) !== 'at_biz_dir' ) {
				$failed++;
				continue;
			}
			if ( wp_trash_post( $id ) ) {
				$trashed++;
			} else {
				$failed++;
			}
		}

		wp_send_json_success( array(
			'trashed' => $trashed,
			'failed'  => $failed,
			'message' => sprintf(
				_n( '%d listing moved to Trash.', '%d listings moved to Trash.', $trashed, 'hbl' ),
				$trashed
			) . ( $failed ? ' ' . sprintf( __( '%d failed.', 'hbl' ), $failed ) : '' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Render page                                                           */
	/* ------------------------------------------------------------------ */

	public function render_page() {
		$title_groups   = $this->count_duplicate_groups( 'title' );
		$phone_groups   = $this->count_duplicate_groups( 'phone' );
		$title_affected = $this->count_affected_listings( 'title' );
		$phone_affected = $this->count_affected_listings( 'phone' );
		$nonce          = wp_create_nonce( 'hbl_duplicate_listings_nonce' );
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-dup-wrap">

			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px;">
					<rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<rect x="10" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".45"/>
					<rect x="3" y="10" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".45"/>
					<rect x="10" y="10" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity=".2"/>
				</svg>
				<?php esc_html_e( 'Duplicate Listings', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Find listings that share the same title or phone number, select duplicates, then bulk trash or edit them.', 'hbl' ); ?></p>

			<!-- Stats -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="12" height="12" rx="2" stroke="currentColor" stroke-width="2"/><path d="M4 16V6a2 2 0 012-2h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $title_groups ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Duplicate Title Groups', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $phone_groups ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Duplicate Phone Groups', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--danger">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $title_affected + $phone_affected ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Listings Affected', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<div class="hbl-reassign-container">

				<!-- Step 1: Filter & Select -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">1</span>
						<h2><?php esc_html_e( 'Filter & Select Listings', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-bpr-filters">
						<div class="hbl-bpr-filter-group">
							<label for="hbl-dup-type"><?php esc_html_e( 'Find duplicates by:', 'hbl' ); ?></label>
							<select id="hbl-dup-type" class="hbl-select">
								<option value="title"><?php esc_html_e( 'Same Title', 'hbl' ); ?></option>
								<option value="phone"><?php esc_html_e( 'Same Phone Number', 'hbl' ); ?></option>
							</select>
						</div>
						<div class="hbl-bpr-filter-group">
							<label for="hbl-dup-search"><?php esc_html_e( 'Search within results:', 'hbl' ); ?></label>
							<input type="text" id="hbl-dup-search" class="hbl-input" placeholder="<?php esc_attr_e( 'Type to narrow...', 'hbl' ); ?>">
						</div>
						<div class="hbl-bpr-filter-group hbl-bpr-filter-actions">
							<button type="button" id="hbl-dup-load" class="button button-secondary">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
									<path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<?php esc_html_e( 'Find Duplicates', 'hbl' ); ?>
							</button>
						</div>
					</div>

					<!-- Table -->
					<div id="hbl-dup-listings-wrap" class="hbl-bpr-listings-wrap" style="display:none;">
						<div class="hbl-bpr-table-header">
							<label class="hbl-select-all-wrap">
								<input type="checkbox" id="hbl-dup-select-all">
								<span id="hbl-dup-select-all-label"><?php esc_html_e( 'Select All Duplicates', 'hbl' ); ?></span>
							</label>
							<span class="hbl-selected-count">
								<span id="hbl-dup-selected-count">0</span> <?php esc_html_e( 'listing(s) selected', 'hbl' ); ?>
							</span>
						</div>

						<div id="hbl-dup-table-body">
							<!-- Populated via AJAX -->
						</div>
					</div>

					<div id="hbl-dup-message" class="hbl-bpr-message" style="display:none;"></div>
				</div>

				<!-- Step 2: Choose Action -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">2</span>
						<h2><?php esc_html_e( 'Choose Bulk Action', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-target-section">
						<div class="hbl-bpr-plan-grid">

							<div class="hbl-bpr-plan-card hbl-dup-action-card" data-action="trash" id="hbl-dup-action-trash">
								<label class="hbl-bpr-plan-label">
									<input type="radio" name="hbl_dup_action" value="trash" class="hbl-bpr-plan-radio">
									<div class="hbl-bpr-plan-content">
										<div class="hbl-bpr-plan-icon">
											<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-bpr-plan-info">
											<span class="hbl-bpr-plan-name"><?php esc_html_e( 'Trash Selected', 'hbl' ); ?></span>
											<span class="hbl-bpr-plan-type"><?php esc_html_e( 'Move to WordPress Trash (restorable)', 'hbl' ); ?></span>
										</div>
										<div class="hbl-bpr-plan-check">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
										</div>
									</div>
								</label>
							</div>

							<div class="hbl-bpr-plan-card hbl-dup-action-card" data-action="edit" id="hbl-dup-action-edit">
								<label class="hbl-bpr-plan-label">
									<input type="radio" name="hbl_dup_action" value="edit" class="hbl-bpr-plan-radio">
									<div class="hbl-bpr-plan-content">
										<div class="hbl-bpr-plan-icon">
											<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
										</div>
										<div class="hbl-bpr-plan-info">
											<span class="hbl-bpr-plan-name"><?php esc_html_e( 'Edit Selected', 'hbl' ); ?></span>
											<span class="hbl-bpr-plan-type"><?php esc_html_e( 'Open each listing in a new tab for editing', 'hbl' ); ?></span>
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

				<!-- Step 3: Confirm & Execute -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">3</span>
						<h2><?php esc_html_e( 'Confirm & Execute', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-confirm-section">
						<div class="hbl-summary" id="hbl-dup-summary">
							<p><?php esc_html_e( 'Select listings and an action above to see a summary here.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-actions">
							<button type="button" id="hbl-dup-execute" class="button button-primary button-hero" disabled>
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 11.08V12a10 10 0 11-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span id="hbl-dup-execute-label"><?php esc_html_e( 'Apply Action', 'hbl' ); ?></span>
							</button>
						</div>

						<div class="hbl-result" id="hbl-dup-result" style="display:none;"></div>
					</div>
				</div>

			</div><!-- .hbl-reassign-container -->
		</div><!-- .hbl-dup-wrap -->

		<script>
		(function($) {
			var nonce      = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl    = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var groups     = [];
			var selectedAction = '';

			/* ---- Helpers ---- */
			function getSelectedIds() {
				return $('.hbl-dup-row-cb:checked').map(function(){ return $(this).val(); }).get();
			}

			function updateSummary() {
				var ids    = getSelectedIds();
				var $btn   = $('#hbl-dup-execute');
				var $sum   = $('#hbl-dup-summary');

				if ( ! ids.length || ! selectedAction ) {
					$sum.html('<p><?php echo esc_js( __( 'Select listings and an action above to see a summary here.', 'hbl' ) ); ?></p>');
					$btn.prop('disabled', true);
					return;
				}

				var actionLabel = selectedAction === 'trash'
					? '<?php echo esc_js( __( 'Trash', 'hbl' ) ); ?>'
					: '<?php echo esc_js( __( 'Open for Editing', 'hbl' ) ); ?>';

				$sum.html(
					'<p><strong>' + ids.length + '</strong> <?php echo esc_js( __( 'listing(s) selected', 'hbl' ) ); ?> &mdash; ' +
					'<?php echo esc_js( __( 'Action', 'hbl' ) ); ?>: <strong>' + actionLabel + '</strong></p>'
				);

				var execLabel = selectedAction === 'trash'
					? '<?php echo esc_js( __( 'Trash Selected Listings', 'hbl' ) ); ?>'
					: '<?php echo esc_js( __( 'Open Selected for Editing', 'hbl' ) ); ?>';
				$('#hbl-dup-execute-label').text( execLabel );
				$btn.prop('disabled', false);
			}

			function statusBadge( status ) {
				var cls = {
					publish: 'hbl-bpr-status-badge--publish',
					draft:   'hbl-bpr-status-badge--draft',
					pending: 'hbl-bpr-status-badge--pending',
				}[ status ] || 'hbl-bpr-status-badge--draft';
				var label = status.charAt(0).toUpperCase() + status.slice(1);
				return '<span class="hbl-bpr-status-badge ' + cls + '">' + label + '</span>';
			}

			/* ---- Render table ---- */
			function renderTable( data ) {
				groups = data.groups;

				if ( ! groups.length ) {
					$('#hbl-dup-message')
						.show()
						.removeClass('hbl-bpr-message--info')
						.addClass('hbl-bpr-message--empty')
						.text('<?php echo esc_js( __( 'No duplicate listings found for the current filter.', 'hbl' ) ); ?>');
					$('#hbl-dup-listings-wrap').hide();
					return;
				}

				$('#hbl-dup-message').hide();

				var html = '';
				$.each( groups, function( gi, group ) {
					html += '<div class="hbl-dup-group-block">';
					html += '<div class="hbl-dup-group-bar">';
					html += '<strong class="hbl-dup-group-label">' + $('<div>').text(group.label).html() + '</strong>';
					html += '<span class="hbl-bpr-plan-badge">' + group.count + ' <?php echo esc_js( __( 'listings', 'hbl' ) ); ?></span>';
					html += '</div>';

					html += '<table class="hbl-bpr-table">';
					html += '<thead><tr>';
					html += '<th></th>';
					html += '<th><?php echo esc_js( __( 'Title', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Status', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Phone', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Plan', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Author', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Published', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'ID', 'hbl' ) ); ?></th>';
					html += '<th><?php echo esc_js( __( 'Actions', 'hbl' ) ); ?></th>';
					html += '</tr></thead><tbody>';

					$.each( group.posts, function( pi, post ) {
						var isOriginal = ( pi === 0 );
						var rowClass   = isOriginal ? ' hbl-dup-original-row' : '';
						var badge      = isOriginal
							? '<span class="hbl-bpr-plan-badge hbl-dup-badge-orig"><?php echo esc_js( __( 'Original', 'hbl' ) ); ?></span> '
							: '<span class="hbl-bpr-plan-badge hbl-dup-badge-dupe"><?php echo esc_js( __( 'Duplicate', 'hbl' ) ); ?></span> ';

						html += '<tr class="hbl-bpr-row' + rowClass + '" data-post-id="' + post.id + '">';
						html += '<td data-label=""><input type="checkbox" class="hbl-dup-row-cb" value="' + post.id + '" data-edit-url="' + $('<div>').text(post.edit_url).html() + '"' + (isOriginal ? '' : '') + '></td>';
						html += '<td class="hbl-bpr-listing-title" data-label="<?php echo esc_js( __( 'Title', 'hbl' ) ); ?>">' + badge + '<a href="' + $('<div>').text(post.view_url || post.edit_url).html() + '" target="_blank">' + $('<div>').text(post.title).html() + '</a></td>';
						html += '<td data-label="<?php echo esc_js( __( 'Status', 'hbl' ) ); ?>">' + statusBadge( post.status ) + '</td>';
						html += '<td data-label="<?php echo esc_js( __( 'Phone', 'hbl' ) ); ?>">' + $('<div>').text(post.phone || '—').html() + '</td>';
						html += '<td data-label="<?php echo esc_js( __( 'Plan', 'hbl' ) ); ?>"><span class="hbl-bpr-plan-badge' + (post.plan === '<?php echo esc_js( __( 'No Plan', 'hbl' ) ); ?>' ? ' hbl-bpr-plan-badge--none' : '') + '">' + $('<div>').text(post.plan).html() + '</span></td>';
						html += '<td data-label="<?php echo esc_js( __( 'Author', 'hbl' ) ); ?>">' + $('<div>').text(post.author).html() + '</td>';
						html += '<td data-label="<?php echo esc_js( __( 'Published', 'hbl' ) ); ?>">' + $('<div>').text(post.date).html() + '</td>';
						html += '<td data-label="<?php echo esc_js( __( 'ID', 'hbl' ) ); ?>"><code>' + post.id + '</code></td>';
						html += '<td data-label="<?php echo esc_js( __( 'Actions', 'hbl' ) ); ?>" style="white-space:nowrap;">';
						if ( post.view_url ) {
							html += '<a href="' + $('<div>').text(post.view_url).html() + '" target="_blank" class="button button-small">View Listing</a>';
						}
						html += '</td>';
						html += '</tr>';
					});

					html += '</tbody></table></div>';
				});

				$('#hbl-dup-table-body').html(html);
				$('#hbl-dup-listings-wrap').show();
				updateSelectedCount();
			}

			function updateSelectedCount() {
				var count = getSelectedIds().length;
				$('#hbl-dup-selected-count').text(count);
				updateSummary();
			}

			/* ---- Load button ---- */
			$('#hbl-dup-load').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Loading…', 'hbl' ) ); ?>');
				$('#hbl-dup-message').hide();
				$('#hbl-dup-listings-wrap').hide();
				$('#hbl-dup-result').hide();
				$('#hbl-dup-table-body').html('<div class="hbl-bpr-spinner"><?php echo esc_js( __( 'Scanning for duplicates…', 'hbl' ) ); ?></div>');
				$('#hbl-dup-listings-wrap').show();

				$.post(ajaxUrl, {
					action:  'hbl_load_duplicates',
					nonce:   nonce,
					type:    $('#hbl-dup-type').val(),
					search:  $('#hbl-dup-search').val(),
				}, function(res) {
					if (res.success) {
						renderTable(res.data);
					} else {
						$('#hbl-dup-message').show().addClass('hbl-bpr-message--empty').text(res.data.message || '<?php echo esc_js( __( 'Error loading data.', 'hbl' ) ); ?>');
						$('#hbl-dup-listings-wrap').hide();
					}
				}).always(function() {
					$btn.prop('disabled', false).html('<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/><path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> <?php echo esc_js( __( 'Find Duplicates', 'hbl' ) ); ?>');
				});
			});

			/* ---- Select all ---- */
			$('#hbl-dup-select-all').on('change', function() {
				$('.hbl-dup-row-cb').prop('checked', this.checked);
				$('.hbl-bpr-row').toggleClass('hbl-bpr-selected', this.checked);
				updateSelectedCount();
			});

			$(document).on('change', '.hbl-dup-row-cb', function() {
				$(this).closest('tr').toggleClass('hbl-bpr-selected', this.checked);
				updateSelectedCount();
			});

			/* ---- Action cards ---- */
			$(document).on('change', 'input[name="hbl_dup_action"]', function() {
				selectedAction = $(this).val();
				$('.hbl-dup-action-card').removeClass('hbl-bpr-plan-selected');
				$(this).closest('.hbl-dup-action-card').addClass('hbl-bpr-plan-selected');
				updateSummary();
			});

			$('.hbl-dup-action-card').on('click', function() {
				$(this).find('input[name="hbl_dup_action"]').prop('checked', true).trigger('change');
			});

			/* ---- Execute ---- */
			$('#hbl-dup-execute').on('click', function() {
				var ids = getSelectedIds();
				if ( ! ids.length ) { return; }

				if ( selectedAction === 'edit' ) {
					// Open each listing's edit page in a new tab.
					$('.hbl-dup-row-cb:checked').each(function() {
						window.open($(this).data('edit-url'), '_blank');
					});
					return;
				}

				if ( ! confirm('<?php echo esc_js( __( 'Move these listings to the Trash? This can be undone from the WordPress Trash.', 'hbl' ) ); ?>') ) {
					return;
				}

				var $btn = $(this);
				$btn.prop('disabled', true).find('#hbl-dup-execute-label').text('<?php echo esc_js( __( 'Processing…', 'hbl' ) ); ?>');

				$.post(ajaxUrl, {
					action: 'hbl_bulk_trash_duplicates',
					nonce:  nonce,
					ids:    ids,
				}, function(res) {
					var $result = $('#hbl-dup-result');

					if (res.success) {
						$result
							.removeClass('error').addClass('success')
							.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M22 11.08V12a10 10 0 11-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="22 4 12 14.01 9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> ' + res.data.message)
							.show();

						// Visually remove trashed rows.
						$('.hbl-dup-row-cb:checked').each(function() {
							$(this).closest('tr').fadeOut(400, function(){ $(this).remove(); });
						});
						$('#hbl-dup-selected-count').text(0);
					} else {
						$result
							.removeClass('success').addClass('error')
							.html('<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> ' + (res.data.message || '<?php echo esc_js( __( 'An error occurred.', 'hbl' ) ); ?>'))
							.show();
					}
				}).always(function() {
					$btn.prop('disabled', false);
					updateSummary();
				});
			});

		})(jQuery);
		</script>
		<?php
	}
}

HBL_Duplicate_Listings::get_instance();
