<?php
/**
 * HBL Bulk Category Reassign Tool
 *
 * Allows admins to bulk reassign listings from one category to another.
 *
 * @package HBL
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HBL_Bulk_Category_Reassign
 */
class HBL_Bulk_Category_Reassign {

	/**
	 * Instance of this class.
	 *
	 * @var HBL_Bulk_Category_Reassign
	 */
	private static $instance = null;

	/**
	 * Get single instance of this class.
	 *
	 * @return HBL_Bulk_Category_Reassign
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
		add_action( 'admin_menu', array( $this, 'register_parent_menu' ), 29 );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_hbl_bulk_reassign_categories', array( $this, 'ajax_bulk_reassign' ) );
	}

	/**
	 * Register the top-level "Directorist Tools" parent menu.
	 * Runs before all three sub-tools so the parent exists when they attach.
	 */
	public function register_parent_menu() {
		add_menu_page(
			__( 'Directorist Tools', 'hbl' ),
			__( 'Directorist Tools', 'hbl' ),
			'manage_options',
			'hbl-directorist-tools',
			array( $this, 'render_parent_page' ),
			'dashicons-admin-tools',
			8
		);

		// Remove the auto-generated duplicate first submenu entry.
		remove_submenu_page( 'hbl-directorist-tools', 'hbl-directorist-tools' );
	}

	/**
	 * Render the parent "Directorist Tools" landing page.
	 */
	public function render_parent_page() {
		$tools = array(
			array(
				'title'       => __( 'Bulk Category Reassign', 'hbl' ),
				'description' => __( 'Move listings from one category to another in bulk — filter, select, and reassign with one click.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-bulk-reassign' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M15 15l3 3 3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			),
			array(
				'title'       => __( 'Bulk Plan Reassign', 'hbl' ),
				'description' => __( 'Filter listings by their current plan, select one or more, then assign a new plan in bulk.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-bulk-plan-reassign' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			),
			array(
				'title'       => __( 'Duplicate Listings', 'hbl' ),
				'description' => __( 'Find listings that share the same title or phone number, then bulk trash or open them for editing.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-duplicate-listings' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2"/><rect x="10" y="3" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" opacity=".45"/><rect x="3" y="10" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" opacity=".45"/><rect x="10" y="10" width="7" height="7" rx="1" stroke="currentColor" stroke-width="2" opacity=".2"/></svg>',
			),
			array(
				'title'       => __( 'Missing Images', 'hbl' ),
				'description' => __( 'Find listings with no logo or the fallback placeholder image so you can upload a real one.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-missing-images' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><line x1="3" y1="3" x2="21" y2="21" stroke="#ef4444" stroke-width="2" stroke-linecap="round"/></svg>',
			),
			array(
				'title'       => __( 'AI Descriptions', 'hbl' ),
				'description' => __( 'Generate descriptions for listings with no content, using Claude AI and each listing\'s name and category.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-ai-descriptions' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
			),
			array(
				'title'       => __( 'Place ID Manager', 'hbl' ),
				'description' => __( 'Find and assign Google Place IDs to listings automatically, or import them from an Excel/CSV file.', 'hbl' ),
				'url'         => admin_url( 'admin.php?page=hbl-place-id' ),
				'icon'        => '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="2"/></svg>',
			),
		);
		?>
		<div class="wrap hbl-bulk-reassign-wrap hbl-dt-landing">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px;color:var(--hbl-primary)">
					<path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Directorist Tools', 'hbl' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'A collection of admin tools for managing your Directorist listings.', 'hbl' ); ?>
			</p>

			<div class="hbl-dt-grid">
				<?php foreach ( $tools as $tool ) : ?>
					<a href="<?php echo esc_url( $tool['url'] ); ?>" class="hbl-dt-card">
						<div class="hbl-dt-card-arrow">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M9 18l6-6-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</div>
						<div class="hbl-dt-card-icon"><?php echo $tool['icon']; // phpcs:ignore ?></div>
						<h2 class="hbl-dt-card-title"><?php echo esc_html( $tool['title'] ); ?></h2>
						<p class="hbl-dt-card-desc"><?php echo esc_html( $tool['description'] ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>
		</div>

		<style>
		.hbl-dt-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
			gap: 16px;
			margin-top: 8px;
		}
		.hbl-dt-card {
			position: relative;
			display: flex;
			flex-direction: column;
			align-items: flex-start;
			gap: 4px;
			padding: 20px 22px;
			background: #fff;
			border: 1px solid var(--hbl-border);
			border-radius: 5px;
			box-shadow: var(--hbl-shadow-card);
			text-decoration: none;
			color: inherit;
			min-width: 0;
			transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
		}
		.hbl-dt-card:hover {
			box-shadow: var(--hbl-shadow-card-hover);
			transform: translateY(-2px);
			border-color: transparent;
			color: inherit;
		}
		.hbl-dt-card-icon {
			flex-shrink: 0;
			width: 44px;
			height: 44px;
			display: flex;
			align-items: center;
			justify-content: center;
			background: rgba(0, 128, 128, 0.1);
			border-radius: var(--hbl-radius-lg);
			color: var(--hbl-primary);
			margin-bottom: 10px;
		}
		.hbl-dt-card-title {
			margin: 0 0 4px;
			font-size: 15px;
			font-weight: 600;
			color: var(--hbl-text);
		}
		.hbl-dt-card-desc {
			margin: 0;
			font-size: 13px;
			color: var(--hbl-text-secondary);
			line-height: 1.5;
		}
		.hbl-dt-card-arrow {
			position: absolute;
			top: 18px;
			right: 18px;
			color: var(--hbl-text-secondary);
			flex-shrink: 0;
		}
		.hbl-dt-card:hover .hbl-dt-card-arrow { color: var(--hbl-primary); }

		@media (max-width: 782px) {
			.hbl-dt-grid { grid-template-columns: 1fr; }
			.hbl-dt-card { padding: 16px; }
		}
		</style>
		<?php
	}

	/**
	 * Add admin menu item.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'hbl-directorist-tools',
			__( 'Bulk Category Reassign', 'hbl' ),
			__( 'Bulk Category Reassign', 'hbl' ),
			'manage_options',
			'hbl-bulk-reassign',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_scripts( $hook ) {
		wp_enqueue_style( 'hbl-admin-font-poppins', 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap', array(), null );

		// Load base styles on the parent landing page too.
		if ( 'toplevel_page_hbl-directorist-tools' === $hook ) {
			wp_enqueue_style(
				'hbl-bulk-reassign',
				HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
				array( 'hbl-admin-font-poppins' ),
				HBL_VERSION
			);
			return;
		}

		if ( 'directorist-tools_page_hbl-bulk-reassign' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/css/bulk-reassign.css',
			array( 'hbl-admin-font-poppins' ),
			HBL_VERSION
		);

		wp_enqueue_script(
			'hbl-bulk-reassign',
			HBL_THEME_URI . '/inc/admin/js/bulk-reassign.js',
			array( 'jquery' ),
			HBL_VERSION,
			true
		);

		wp_localize_script(
			'hbl-bulk-reassign',
			'hblBulkReassign',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hbl_bulk_reassign_nonce' ),
				'strings' => array(
					'selectSource'    => __( 'Please select at least one source category.', 'hbl' ),
					'selectTarget'    => __( 'Please select a target category.', 'hbl' ),
					'sameCategory'    => __( 'Source and target categories cannot be the same.', 'hbl' ),
					'confirmReassign' => __( 'Are you sure you want to reassign all listings from the selected categories?', 'hbl' ),
					'processing'      => __( 'Processing...', 'hbl' ),
					'success'         => __( 'Successfully reassigned listings.', 'hbl' ),
					'error'           => __( 'An error occurred. Please try again.', 'hbl' ),
				),
			)
		);
	}

	/**
	 * Get all categories with listing counts.
	 *
	 * @return array
	 */
	private function get_categories() {
		$categories = array();

		if ( ! taxonomy_exists( 'at_biz_dir-category' ) ) {
			return $categories;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'at_biz_dir-category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				// Get actual published listing count
				$count = $this->get_category_listing_count( $term->term_id );
				
				$categories[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $count,
				);
			}
		}

		return $categories;
	}

	/**
	 * Get listing count for a category.
	 *
	 * @param int $term_id The term ID.
	 * @return int
	 */
	private function get_category_listing_count( $term_id ) {
		$args = array(
			'post_type'      => 'at_biz_dir',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'at_biz_dir-category',
					'field'    => 'term_id',
					'terms'    => $term_id,
				),
			),
		);
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
		$categories     = $this->get_categories();
		$total_listings = $this->get_total_listings();
		?>
		<div class="wrap hbl-bulk-reassign-wrap">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
					<path d="M16 3H21V8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M4 20L21 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M21 16V21H16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M15 15L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M4 4L9 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Bulk Category Reassign', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Select source categories and reassign all their listings to a target category.', 'hbl' ); ?></p>

			<!-- Stats Overview -->
			<div class="hbl-reassign-stats">
				<div class="hbl-stat-card">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( count( $categories ) ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Total Categories', 'hbl' ); ?></span>
					</div>
				</div>
				<div class="hbl-stat-card hbl-stat-card--info">
					<div class="hbl-stat-icon"><svg viewBox="0 0 24 24" fill="none"><path d="M2 7l10 5 10-5-10-5-10 5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
					<div class="hbl-stat-body">
						<span class="hbl-stat-number"><?php echo esc_html( $total_listings ); ?></span>
						<span class="hbl-stat-label"><?php esc_html_e( 'Total Listings', 'hbl' ); ?></span>
					</div>
				</div>
			</div>

			<div class="hbl-reassign-container">
				<!-- Step 1: Select Source Categories -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">1</span>
						<h2><?php esc_html_e( 'Select Source Categories', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-categories-section">
						<div class="hbl-categories-header">
							<label class="hbl-select-all-wrap">
								<input type="checkbox" id="hbl-select-all-categories">
								<span><?php esc_html_e( 'Select All', 'hbl' ); ?></span>
							</label>
							<span class="hbl-selected-count"><span id="hbl-selected-cat-count">0</span> <?php esc_html_e( 'categories selected', 'hbl' ); ?> (<span id="hbl-selected-listing-count">0</span> <?php esc_html_e( 'listings', 'hbl' ); ?>)</span>
						</div>

						<div class="hbl-categories-grid">
							<?php if ( ! empty( $categories ) ) : ?>
								<?php foreach ( $categories as $cat ) : ?>
									<div class="hbl-category-card" data-category-id="<?php echo esc_attr( $cat['id'] ); ?>" data-listing-count="<?php echo esc_attr( $cat['count'] ); ?>">
										<label class="hbl-category-label">
											<input type="checkbox" name="source_categories[]" value="<?php echo esc_attr( $cat['id'] ); ?>" class="hbl-source-category-checkbox">
											<div class="hbl-category-content">
												<div class="hbl-category-icon">
													<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
														<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
													</svg>
												</div>
												<div class="hbl-category-info">
													<span class="hbl-category-name"><?php echo esc_html( $cat['name'] ); ?></span>
													<span class="hbl-category-count">
														<?php 
														printf( 
															/* translators: %d: number of listings */
															esc_html( _n( '%d listing', '%d listings', $cat['count'], 'hbl' ) ), 
															esc_html( $cat['count'] ) 
														); 
														?>
													</span>
												</div>
												<div class="hbl-category-check">
													<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
														<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
													</svg>
												</div>
											</div>
										</label>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<div class="hbl-no-categories">
									<p><?php esc_html_e( 'No categories found.', 'hbl' ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Step 2: Select Target Category -->
				<div class="hbl-reassign-step">
					<div class="hbl-step-header">
						<span class="hbl-step-number">2</span>
						<h2><?php esc_html_e( 'Select Target Category', 'hbl' ); ?></h2>
					</div>

					<div class="hbl-target-section">
						<div class="hbl-target-group">
							<label for="hbl-target-category"><?php esc_html_e( 'Reassign all selected listings to:', 'hbl' ); ?></label>
							<select id="hbl-target-category" class="hbl-select hbl-select-large">
								<option value=""><?php esc_html_e( '— Select Target Category —', 'hbl' ); ?></option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat['id'] ); ?>">
										<?php echo esc_html( $cat['name'] ); ?> (<?php echo esc_html( $cat['count'] ); ?> <?php echo esc_html( _n( 'listing', 'listings', $cat['count'], 'hbl' ) ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="hbl-reassign-options">
							<label class="hbl-option-wrap">
								<input type="radio" name="hbl-reassign-mode" value="replace" checked>
								<span><?php esc_html_e( 'Replace existing categories', 'hbl' ); ?></span>
								<small><?php esc_html_e( 'Remove all current categories and assign only the target', 'hbl' ); ?></small>
							</label>
							<label class="hbl-option-wrap">
								<input type="radio" name="hbl-reassign-mode" value="add">
								<span><?php esc_html_e( 'Add to existing categories', 'hbl' ); ?></span>
								<small><?php esc_html_e( 'Keep current categories and add the target category', 'hbl' ); ?></small>
							</label>
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
						<div class="hbl-summary" id="hbl-summary">
							<p><?php esc_html_e( 'Select source categories and a target category to see a summary here.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-actions">
							<button type="button" id="hbl-execute-reassign" class="button button-primary button-hero" disabled>
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Reassign Categories', 'hbl' ); ?></span>
							</button>
						</div>

						<div class="hbl-result" id="hbl-result" style="display: none;"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to bulk reassign categories.
	 */
	public function ajax_bulk_reassign() {
		check_ajax_referer( 'hbl_bulk_reassign_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		$source_categories = isset( $_POST['source_categories'] ) ? array_map( 'absint', (array) $_POST['source_categories'] ) : array();
		$target_category   = isset( $_POST['target_category'] ) ? absint( $_POST['target_category'] ) : 0;
		$mode              = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'replace';

		if ( empty( $source_categories ) ) {
			wp_send_json_error( array( 'message' => __( 'No source categories selected.', 'hbl' ) ) );
		}

		if ( $target_category <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No target category selected.', 'hbl' ) ) );
		}

		// Verify the target category exists
		$target_term = get_term( $target_category, 'at_biz_dir-category' );
		if ( ! $target_term || is_wp_error( $target_term ) ) {
			wp_send_json_error( array( 'message' => __( 'Target category not found.', 'hbl' ) ) );
		}

		// Get all listings from source categories
		$args = array(
			'post_type'      => 'at_biz_dir',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array(
				array(
					'taxonomy' => 'at_biz_dir-category',
					'field'    => 'term_id',
					'terms'    => $source_categories,
				),
			),
		);

		$query       = new WP_Query( $args );
		$listing_ids = $query->posts;

		if ( empty( $listing_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No listings found in the selected categories.', 'hbl' ) ) );
		}

		$success_count = 0;
		$failed_ids    = array();

		foreach ( $listing_ids as $listing_id ) {
			if ( 'replace' === $mode ) {
				// Replace all existing categories with the new one
				$result = wp_set_object_terms( $listing_id, array( $target_category ), 'at_biz_dir-category' );
			} else {
				// Add to existing categories
				$result = wp_set_object_terms( $listing_id, array( $target_category ), 'at_biz_dir-category', true );
			}

			if ( is_wp_error( $result ) ) {
				$failed_ids[] = $listing_id;
			} else {
				$success_count++;
			}
		}

		if ( $success_count > 0 ) {
			// Get source category names for the message
			$source_names = array();
			foreach ( $source_categories as $cat_id ) {
				$term = get_term( $cat_id, 'at_biz_dir-category' );
				if ( $term && ! is_wp_error( $term ) ) {
					$source_names[] = $term->name;
				}
			}

			wp_send_json_success(
				array(
					'message'       => sprintf(
						/* translators: 1: success count, 2: source categories, 3: target category name */
						__( 'Successfully reassigned %1$d listing(s) from "%2$s" to "%3$s".', 'hbl' ),
						$success_count,
						implode( ', ', $source_names ),
						$target_term->name
					),
					'success_count' => $success_count,
					'failed_count'  => count( $failed_ids ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to reassign any listings. Please try again.', 'hbl' ) ) );
		}
	}
}

// Initialize the class.
HBL_Bulk_Category_Reassign::get_instance();
