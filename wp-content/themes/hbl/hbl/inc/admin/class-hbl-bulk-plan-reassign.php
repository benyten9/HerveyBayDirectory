<?php
/**
 * HBL Bulk Plan Reassign Tool
 *
 * Allows admins to bulk or individually reassign listing plans.
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HBL_Bulk_Plan_Reassign
 */
class HBL_Bulk_Plan_Reassign {

	/**
	 * Instance of this class.
	 *
	 * @var HBL_Bulk_Plan_Reassign
	 */
	private static $instance = null;

	/**
	 * Get single instance of this class.
	 *
	 * @return HBL_Bulk_Plan_Reassign
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 31 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_hbl_get_listings_for_plan', array( $this, 'ajax_get_listings' ) );
		add_action( 'wp_ajax_hbl_get_all_listing_ids_for_plan', array( $this, 'ajax_get_all_listing_ids' ) );
		add_action( 'wp_ajax_hbl_bulk_change_plan', array( $this, 'ajax_bulk_change_plan' ) );
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Bulk Plan Reassign', 'hbl' ),
			__( 'Bulk Plan Reassign', 'hbl' ),
			'manage_options',
			'hbl-bulk-plan-reassign',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'directorist-tools_page_hbl-bulk-plan-reassign' !== $hook ) {
			return;
		}

		// Load the same base styles used by the existing Bulk Reassign tool.
		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );
		wp_enqueue_style(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
			array( 'hbl-admin-font-poppins' ),
			HBL_VERSION
		);

		wp_enqueue_style(
			'hbl-bulk-plan-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-plan-reassign.css',
			array( 'hbl-bulk-reassign' ),
			HBL_VERSION
		);

		wp_enqueue_script(
			'hbl-bulk-plan-reassign',
			HBL_THEME_URI . '/inc/admin/js/bulk-plan-reassign.js',
			array( 'jquery' ),
			HBL_VERSION,
			true
		);

		wp_localize_script(
			'hbl-bulk-plan-reassign',
			'hblBulkPlanReassign',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hbl_bulk_plan_reassign_nonce' ),
				'strings' => array(
					'selectListings'  => __( 'Please select at least one listing.', 'hbl' ),
					'selectPlan'      => __( 'Please select a target plan.', 'hbl' ),
					'confirmChange'   => __( 'Are you sure you want to change the plan for the selected listing(s)?', 'hbl' ),
					'processing'      => __( 'Processing...', 'hbl' ),
					'loadingListings' => __( 'Loading listings...', 'hbl' ),
					'noListings'      => __( 'No listings found matching the filter.', 'hbl' ),
					'success'         => __( 'Plan updated successfully.', 'hbl' ),
					'error'           => __( 'An error occurred. Please try again.', 'hbl' ),
				'selectAll'           => __( 'Select All', 'hbl' ),
				'deselectAll'         => __( 'Deselect All', 'hbl' ),
				'selectAllPages'      => __( 'Select all %d listings', 'hbl' ),
				'allPagesSelected'    => __( 'All %d listings are selected.', 'hbl' ),
				'pageSelectedNotice'  => __( 'All %d listings on this page are selected.', 'hbl' ),
				'clearSelection'      => __( 'Clear selection', 'hbl' ),
				'loadingAll'          => __( 'Loading all listings…', 'hbl' ),
				'tryAgain'            => __( 'Try again', 'hbl' ),
					'selected'        => __( 'selected', 'hbl' ),
					'listings'        => __( 'listing(s)', 'hbl' ),
				),
			)
		);
	}

	/**
	 * Get all available pricing plans.
	 *
	 * @return array
	 */
	private function get_plans() {
		// Prefer the HBL_Pricing_Plans provider — it resolves plans from both
		// Directorist Pricing Plans 4.0+ (custom table) and legacy CPT installs.
		// Querying the atbdp_pricing_plans post type directly returns nothing on
		// v4, which is why the tool reported "No pricing plans found".
		if ( class_exists( 'HBL_Pricing_Plans' ) ) {
			$plans = array();
			foreach ( \HBL_Pricing_Plans::get_plans() as $plan ) {
				if ( empty( $plan['id'] ) ) {
					continue;
				}
				$plans[] = array(
					'id'   => (int) $plan['id'],
					'name' => isset( $plan['title'] ) ? (string) $plan['title'] : '',
					'type' => ! empty( $plan['type'] ) ? (string) $plan['type'] : 'plan',
				);
			}
			if ( ! empty( $plans ) ) {
				return $plans;
			}
		}

		// Legacy fallback: pricing-plan CPT (pre-v4 installs, or if the provider
		// class is unavailable for any reason).
		if ( ! post_type_exists( 'atbdp_pricing_plans' ) ) {
			return array();
		}

		$posts = get_posts( array(
			'post_type'      => 'atbdp_pricing_plans',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$plans = array();
		foreach ( $posts as $post ) {
			$plan_type = get_post_meta( $post->ID, '_fm_package_type', true );
			if ( empty( $plan_type ) ) {
				$plan_type = get_post_meta( $post->ID, '_package_type', true );
			}
			$plans[] = array(
				'id'   => $post->ID,
				'name' => $post->post_title,
				'type' => $plan_type ? $plan_type : 'plan',
			);
		}

		return $plans;
	}

	/**
	 * Resolve a map of plan_id => plan name via HBL_Pricing_Plans (works on both
	 * v4 custom-table and legacy CPT installs), falling back to the CPT.
	 *
	 * @param array $ids Plan IDs.
	 * @return array<int,string>
	 */
	private function plan_names_for( array $ids ) {
		$names = array();
		$ids   = array_unique( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return $names;
		}

		if ( class_exists( 'HBL_Pricing_Plans' ) ) {
			foreach ( $ids as $id ) {
				$plan = \HBL_Pricing_Plans::get_plan( $id );
				if ( $plan && ! empty( $plan['title'] ) ) {
					$names[ $id ] = (string) $plan['title'];
				}
			}
			if ( ! empty( $names ) ) {
				return $names;
			}
		}

		// Legacy CPT fallback.
		$plan_posts = get_posts( array(
			'post__in'       => $ids,
			'post_type'      => 'atbdp_pricing_plans',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		) );
		foreach ( $plan_posts as $pp ) {
			$names[ $pp->ID ] = $pp->post_title;
		}

		return $names;
	}

	/**
	 * Whether a plan ID refers to a real, usable pricing plan.
	 *
	 * @param int $plan_id
	 * @return bool
	 */
	private function plan_exists( $plan_id ) {
		$plan_id = (int) $plan_id;
		if ( $plan_id <= 0 ) {
			return false;
		}
		if ( class_exists( 'HBL_Pricing_Plans' ) && \HBL_Pricing_Plans::get_plan( $plan_id ) ) {
			return true;
		}
		$post = get_post( $plan_id );
		return $post && 'atbdp_pricing_plans' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * The post-meta key Directorist reads for a listing's assigned plan.
	 *
	 * Pricing Plans 4.0+ stores it under directorist_plan_key() (`_plan_id`),
	 * which get_listings_package() consults; legacy installs used `_fm_plans`.
	 * Reading/writing the wrong key is why this tool showed — and reassigned —
	 * the wrong plan on v4.
	 *
	 * @return string
	 */
	private function plan_meta_key() {
		return function_exists( 'directorist_plan_key' ) ? directorist_plan_key() : '_fm_plans';
	}

	/**
	 * Get listing count per plan.
	 *
	 * @param int $plan_id The plan post ID (0 = no plan).
	 * @return int
	 */
	private function get_plan_listing_count( $plan_id ) {
		$key = $this->plan_meta_key();

		if ( 0 === $plan_id ) {
			// Listings with no plan assigned.
			$args = array(
				'post_type'      => 'at_biz_dir',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $key,
						'value'   => '',
						'compare' => '=',
					),
				),
			);
		} else {
			$args = array(
				'post_type'      => 'at_biz_dir',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => $key,
						'value' => $plan_id,
					),
				),
			);
		}

		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Get total listing count.
	 *
	 * @return int
	 */
	private function get_total_listings() {
		$args = array(
			'post_type'      => 'at_biz_dir',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$plans          = $this->get_plans();
		$total_listings = $this->get_total_listings();
		$no_plan_count  = $this->get_plan_listing_count( 0 );
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-bpr-wrap">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
					<rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<rect x="14" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<rect x="3" y="14" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M14 17.5H21M17.5 14V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Bulk Plan Reassign', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Filter listings by their current plan, select one or more, then assign a new plan.', 'hbl' ); ?></p>

			<!-- Stats Overview -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M2 7l10 5 10-5-10-5-10 5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $total_listings ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Total Listings', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M20.59 13.41L13.42 20.58a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="7" cy="7" r="1.2" fill="currentColor"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( count( $plans ) ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Available Plans', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--warning">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $no_plan_count ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Without a Plan', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<?php if ( empty( $plans ) ) : ?>
				<div class="hbl-bpr-notice hbl-bpr-notice--warning">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M10.29 3.86L1.82 18C1.64 18.3 1.55 18.64 1.55 19C1.55 19.36 1.64 19.7 1.82 20C2 20.3 2.26 20.55 2.57 20.72C2.88 20.89 3.23 20.98 3.59 21H20.41C20.77 21 21.12 20.91 21.43 20.74C21.74 20.57 22 20.32 22.18 20.02C22.36 19.72 22.45 19.38 22.45 19.02C22.45 18.66 22.36 18.32 22.18 18.02L13.71 3.86C13.53 3.56 13.27 3.31 12.96 3.14C12.65 2.97 12.3 2.88 11.94 2.88C11.58 2.88 11.23 2.97 10.92 3.14C10.61 3.31 10.35 3.56 10.17 3.86H10.29Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M12 9V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<circle cx="12" cy="17" r="1" fill="currentColor"/>
					</svg>
					<span><?php esc_html_e( 'No pricing plans found. Please create plans in the Directorist Pricing Plans section first.', 'hbl' ); ?></span>
				</div>
			<?php else : ?>

			<div class="hbl-reassign-container">

				<!-- Step 1: Filter & Select Listings -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">1</span>
						<h2><?php esc_html_e( 'Filter & Select Listings', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-bpr-filters">
						<div class="hbl-bpr-filter-group">
							<label for="hbl-bpr-filter-plan"><?php esc_html_e( 'Filter by current plan:', 'hbl' ); ?></label>
							<select id="hbl-bpr-filter-plan" class="hbl-select">
								<option value=""><?php esc_html_e( '— All Listings —', 'hbl' ); ?></option>
								<option value="0"><?php esc_html_e( 'No Plan Assigned', 'hbl' ); ?></option>
								<?php foreach ( $plans as $plan ) : ?>
									<option value="<?php echo esc_attr( $plan['id'] ); ?>">
										<?php echo esc_html( $plan['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="hbl-bpr-filter-group">
							<label for="hbl-bpr-search"><?php esc_html_e( 'Search listing name:', 'hbl' ); ?></label>
							<input type="text" id="hbl-bpr-search" class="hbl-input" placeholder="<?php esc_attr_e( 'Type to search...', 'hbl' ); ?>">
						</div>
						<div class="hbl-bpr-filter-group hbl-bpr-filter-actions">
							<button type="button" id="hbl-bpr-load-listings" class="button button-secondary">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2"/>
									<path d="m21 21-4.35-4.35" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<?php esc_html_e( 'Load Listings', 'hbl' ); ?>
							</button>
						</div>
					</div>

					<!-- Listings Table -->
					<div id="hbl-bpr-listings-wrap" class="hbl-bpr-listings-wrap" style="display:none;">
						<div class="hbl-bpr-table-header">
							<label class="hbl-select-all-wrap">
								<input type="checkbox" id="hbl-bpr-select-all">
								<span id="hbl-bpr-select-all-label"><?php esc_html_e( 'Select All', 'hbl' ); ?></span>
							</label>
							<span class="hbl-selected-count">
								<span id="hbl-bpr-selected-count">0</span> <?php esc_html_e( 'listing(s) selected', 'hbl' ); ?>
							</span>
						</div>

						<div id="hbl-bpr-listings-table">
							<!-- Populated via AJAX -->
						</div>

						<div id="hbl-bpr-select-all-pages" class="hbl-bpr-select-all-pages" style="display:none;"></div>

						<div id="hbl-bpr-pagination" class="hbl-bpr-pagination"></div>
					</div>

					<div id="hbl-bpr-listings-message" class="hbl-bpr-message" style="display:none;"></div>
				</div>

				<!-- Step 2: Select Target Plan -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">2</span>
						<h2><?php esc_html_e( 'Select Target Plan', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-target-section">
						<div class="hbl-bpr-plan-grid">
							<?php foreach ( $plans as $plan ) : ?>
								<div class="hbl-bpr-plan-card" data-plan-id="<?php echo esc_attr( $plan['id'] ); ?>">
									<label class="hbl-bpr-plan-label">
										<input type="radio" name="hbl_target_plan" value="<?php echo esc_attr( $plan['id'] ); ?>" class="hbl-bpr-plan-radio">
										<div class="hbl-bpr-plan-content">
											<div class="hbl-bpr-plan-icon">
												<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</div>
											<div class="hbl-bpr-plan-info">
												<span class="hbl-bpr-plan-name"><?php echo esc_html( $plan['name'] ); ?></span>
												<?php if ( ! empty( $plan['type'] ) && 'plan' !== $plan['type'] ) : ?>
													<span class="hbl-bpr-plan-type"><?php echo esc_html( ucwords( str_replace( '_', ' ', $plan['type'] ) ) ); ?></span>
												<?php endif; ?>
											</div>
											<div class="hbl-bpr-plan-check">
												<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</div>
										</div>
									</label>
								</div>
							<?php endforeach; ?>
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
						<div class="hbl-summary" id="hbl-bpr-summary">
							<p><?php esc_html_e( 'Select listings and a target plan above to see a summary here.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-actions">
							<button type="button" id="hbl-bpr-execute" class="button button-primary button-hero" disabled>
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Apply Plan Change', 'hbl' ); ?></span>
							</button>
						</div>

						<div class="hbl-result" id="hbl-bpr-result" style="display:none;"></div>
					</div>
				</div>

			</div><!-- .hbl-reassign-container -->
			<?php endif; ?>
		</div><!-- .hbl-bpr-wrap -->
		<?php
	}

	/**
	 * AJAX: Load listings for the given filters.
	 */
	public function ajax_get_listings() {
		check_ajax_referer( 'hbl_bulk_plan_reassign_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		$filter_plan = isset( $_POST['filter_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_plan'] ) ) : '';
		$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page        = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
		$per_page    = 20;

		$args = array(
			'post_type'      => 'at_biz_dir',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$key = $this->plan_meta_key();

		if ( '' !== $filter_plan ) {
			if ( '0' === $filter_plan ) {
				// No plan assigned.
				$args['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => $key,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $key,
						'value'   => '',
						'compare' => '=',
					),
				);
			} else {
				$args['meta_query'] = array(
					array(
						'key'   => $key,
						'value' => absint( $filter_plan ),
					),
				);
			}
		}

		$query = new WP_Query( $args );

		// Batch-fetch plan names for this page's listings in one query.
		$page_plan_ids = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$pid = get_post_meta( $post->ID, $key, true );
				if ( $pid && is_numeric( $pid ) ) {
					$page_plan_ids[ $post->ID ] = (int) $pid;
				}
			}
		}
		$unique_plan_ids = array_unique( array_values( $page_plan_ids ) );
		$plan_name_cache = $this->plan_names_for( $unique_plan_ids );

		$listings = array();
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$plan_id   = isset( $page_plan_ids[ $post->ID ] ) ? $page_plan_ids[ $post->ID ] : 0;
				$plan_name = ( $plan_id && isset( $plan_name_cache[ $plan_id ] ) ) ? $plan_name_cache[ $plan_id ] : __( 'No Plan', 'hbl' );
				$status_obj = get_post_status_object( $post->post_status );

				$listings[] = array(
					'id'        => $post->ID,
					'title'     => $post->post_title,
					'status'    => $status_obj ? $status_obj->label : $post->post_status,
					'plan_id'   => $plan_id,
					'plan_name' => $plan_name,
					'author'    => get_the_author_meta( 'display_name', $post->post_author ),
					'edit_link' => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
					'view_link' => get_permalink( $post->ID ),
				);
			}
		}

		wp_send_json_success( array(
			'listings'    => $listings,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		) );
	}

	/**
	 * AJAX: Get ALL listing IDs + minimal data for the given filters (no pagination).
	 * Used by the "Select all N listings" across-pages feature.
	 */
	public function ajax_get_all_listing_ids() {
		check_ajax_referer( 'hbl_bulk_plan_reassign_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		// Extend PHP time limit for large datasets.
		@set_time_limit( 120 ); // phpcs:ignore

		global $wpdb;

		$filter_plan = isset( $_POST['filter_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['filter_plan'] ) ) : '';
		$search      = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

		// Single SQL JOIN — fetches IDs + plan meta in one query.
		$where = "p.post_type = 'at_biz_dir' AND p.post_status IN ('publish','draft','pending','private')";
		$args  = array();
		$key   = esc_sql( $this->plan_meta_key() );

		if ( '' !== $search ) {
			$where .= $wpdb->prepare( ' AND p.post_title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
		}

		if ( '0' === $filter_plan ) {
			// No plan: LEFT JOIN and filter for NULL/empty plan.
			$sql = "SELECT p.ID, p.post_title, pm.meta_value AS plan_id
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '{$key}'
					WHERE {$where}
					AND ( pm.meta_value IS NULL OR pm.meta_value = '' )
					ORDER BY p.post_title ASC";
		} elseif ( '' !== $filter_plan ) {
			$sql = $wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_value AS plan_id
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '{$key}'
				 WHERE {$where} AND pm.meta_value = %s
				 ORDER BY p.post_title ASC",
				$filter_plan
			);
		} else {
			$sql = "SELECT p.ID, p.post_title, pm.meta_value AS plan_id
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '{$key}'
					WHERE {$where}
					ORDER BY p.post_title ASC";
		}

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Batch-fetch plan names with a single query for all unique plan IDs.
		$plan_ids   = array_unique( array_filter( array_map( 'intval', array_column( $rows, 'plan_id' ) ) ) );
		$plan_names = $this->plan_names_for( $plan_ids );

		$listings = array();
		foreach ( $rows as $row ) {
			$plan_id    = (int) $row->plan_id;
			$listings[] = array(
				'id'        => $row->ID,
				'title'     => $row->post_title,
				'plan_name' => ( $plan_id && isset( $plan_names[ $plan_id ] ) ) ? $plan_names[ $plan_id ] : __( 'No Plan', 'hbl' ),
			);
		}

		wp_send_json_success( array(
			'listings' => $listings,
			'total'    => count( $listings ),
		) );
	}

	/**
	 * AJAX: Change plan for selected listings.
	 */
	public function ajax_bulk_change_plan() {
		check_ajax_referer( 'hbl_bulk_plan_reassign_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		$listing_ids = isset( $_POST['listing_ids'] ) ? array_map( 'absint', (array) $_POST['listing_ids'] ) : array();
		$plan_id     = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

		if ( empty( $listing_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No listings selected.', 'hbl' ) ) );
		}

		if ( $plan_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No target plan selected.', 'hbl' ) ) );
		}

		// Verify the target plan exists (resolves via HBL_Pricing_Plans on v4).
		if ( ! $this->plan_exists( $plan_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Target plan not found or not published.', 'hbl' ) ) );
		}

		// Resolve the target plan's title (v4 has no plan post to read).
		$target_names = $this->plan_names_for( array( $plan_id ) );
		$plan_title   = isset( $target_names[ $plan_id ] ) ? $target_names[ $plan_id ] : __( 'selected', 'hbl' );

		// The meta key Directorist reads for a listing's plan (v4: _plan_id).
		$plan_key = $this->plan_meta_key();

		$success_count = 0;
		$failed_ids    = array();

		foreach ( $listing_ids as $listing_id ) {
			// Verify post is an at_biz_dir listing.
			if ( 'at_biz_dir' !== get_post_type( $listing_id ) ) {
				$failed_ids[] = $listing_id;
				continue;
			}

			// Write the plan under the key Directorist actually reads (v4: _plan_id,
			// consulted by get_listings_package()), and mirror _fm_plans for any
			// legacy consumers so both stay consistent.
			$updated = update_post_meta( $listing_id, $plan_key, $plan_id );
			if ( '_fm_plans' !== $plan_key ) {
				update_post_meta( $listing_id, '_fm_plans', $plan_id );
			}

			// Mark as assigned by admin so plan restrictions are bypassed.
			update_post_meta( $listing_id, '_fm_plans_by_admin', 1 );

			// Update plan sorting order meta if it exists in the pricing plans plugin.
			if ( defined( 'DPP_META_KEY_PLAN_SORTING_ORDER' ) ) {
				$plan_sort_order = get_post_meta( $plan_id, DPP_META_KEY_PLAN_SORTING_ORDER, true );
				update_post_meta( $listing_id, DPP_META_KEY_PLAN_SORTING_ORDER, $plan_sort_order );
			}

			if ( false !== $updated ) {
				$success_count++;
			} else {
				// update_post_meta returns false if nothing changed (same value), still count as success.
				$existing = get_post_meta( $listing_id, $plan_key, true );
				if ( (int) $existing === $plan_id ) {
					$success_count++;
				} else {
					$failed_ids[] = $listing_id;
				}
			}
		}

		if ( $success_count > 0 ) {
			wp_send_json_success( array(
				'message'       => sprintf(
					/* translators: 1: success count, 2: plan name */
					_n(
						'Successfully assigned %1$d listing to the "%2$s" plan.',
						'Successfully assigned %1$d listings to the "%2$s" plan.',
						$success_count,
						'hbl'
					),
					$success_count,
					$plan_title
				),
				'success_count' => $success_count,
				'failed_count'  => count( $failed_ids ),
			) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to update any listings. Please try again.', 'hbl' ) ) );
		}
	}
}

// Initialize the class.
HBL_Bulk_Plan_Reassign::get_instance();
