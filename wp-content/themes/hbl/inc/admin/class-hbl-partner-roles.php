<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Partner_Roles {

	const TRANSITION_DATE = '2027-01-01';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'after_switch_theme', array( $this, 'register_roles' ) );
		
		add_action( 'init', array( $this, 'maybe_register_roles' ) );
		
		add_action( 'show_user_profile', array( $this, 'add_partner_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'add_partner_fields' ) );
		add_action( 'personal_options_update', array( $this, 'save_partner_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_partner_fields' ) );
		
		add_filter( 'manage_users_columns', array( $this, 'add_user_columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_user_columns' ), 10, 3 );
		
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		
		add_action( 'hbl_check_partner_transitions', array( $this, 'check_role_transitions' ) );
		
		if ( ! wp_next_scheduled( 'hbl_check_partner_transitions' ) ) {
			wp_schedule_event( time(), 'daily', 'hbl_check_partner_transitions' );
		}

		add_action( 'wp_ajax_hbl_create_partner_user', array( $this, 'ajax_create_partner_user' ) );
	}

	public function register_roles() {
		$subscriber = get_role( 'subscriber' );
		$base_caps  = $subscriber ? $subscriber->capabilities : array( 'read' => true );

		$partner_caps = array_merge( $base_caps, array(
			'hbl_manage_client_listings' => true,
			'hbl_view_partner_dashboard' => true,
			'hbl_submit_listings'        => true,
		) );

		remove_role( 'founding_partner' );
		remove_role( 'partner_agency' );

		add_role(
			'founding_partner',
			__( 'Founding Partner', 'hbl' ),
			$partner_caps
		);

		add_role(
			'partner_agency',
			__( 'Partner Agency', 'hbl' ),
			$partner_caps
		);
	}

	public function maybe_register_roles() {
		if ( ! get_role( 'founding_partner' ) || ! get_role( 'partner_agency' ) ) {
			$this->register_roles();
		}
	}

	public function add_partner_fields( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$is_partner = in_array( 'founding_partner', (array) $user->roles, true ) || 
		              in_array( 'partner_agency', (array) $user->roles, true );

		if ( ! $is_partner && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$agency_name     = get_user_meta( $user->ID, '_hbl_agency_name', true );
		$agency_website  = get_user_meta( $user->ID, '_hbl_agency_website', true );
		$discount_rate   = get_user_meta( $user->ID, '_hbl_discount_rate', true );
		$partner_since   = get_user_meta( $user->ID, '_hbl_partner_since', true );
		$admin_notes     = get_user_meta( $user->ID, '_hbl_partner_notes', true );
		$client_count    = get_user_meta( $user->ID, '_hbl_client_count', true );
		?>
		<h2><?php esc_html_e( 'Partner Agency Information', 'hbl' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Partner agencies can submit listings for their clients at special rates.', 'hbl' ); ?></p>
		
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="hbl_agency_name"><?php esc_html_e( 'Agency Name', 'hbl' ); ?></label></th>
				<td>
					<input type="text" name="hbl_agency_name" id="hbl_agency_name" value="<?php echo esc_attr( $agency_name ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'The name of the partner agency.', 'hbl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="hbl_agency_website"><?php esc_html_e( 'Agency Website', 'hbl' ); ?></label></th>
				<td>
					<input type="url" name="hbl_agency_website" id="hbl_agency_website" value="<?php echo esc_attr( $agency_website ); ?>" class="regular-text">
				</td>
			</tr>
			<tr>
				<th><label for="hbl_discount_rate"><?php esc_html_e( 'Discount Rate (%)', 'hbl' ); ?></label></th>
				<td>
					<input type="number" name="hbl_discount_rate" id="hbl_discount_rate" value="<?php echo esc_attr( $discount_rate ); ?>" class="small-text" min="0" max="100">
					<p class="description">
						<?php if ( in_array( 'founding_partner', (array) $user->roles, true ) ) : ?>
							<?php esc_html_e( 'Founding Partners: Special launch pricing. Will become Partner Agency with 25% ongoing discount in 2027.', 'hbl' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Standard partner discount percentage.', 'hbl' ); ?>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="hbl_partner_since"><?php esc_html_e( 'Partner Since', 'hbl' ); ?></label></th>
				<td>
					<input type="date" name="hbl_partner_since" id="hbl_partner_since" value="<?php echo esc_attr( $partner_since ); ?>">
				</td>
			</tr>
			<tr>
				<th><label for="hbl_client_count"><?php esc_html_e( 'Client Listings', 'hbl' ); ?></label></th>
				<td>
					<input type="number" name="hbl_client_count" id="hbl_client_count" value="<?php echo esc_attr( $client_count ); ?>" class="small-text" min="0" readonly>
					<p class="description"><?php esc_html_e( 'Number of client listings submitted through this partner.', 'hbl' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="hbl_partner_notes"><?php esc_html_e( 'Admin Notes', 'hbl' ); ?></label></th>
				<td>
					<textarea name="hbl_partner_notes" id="hbl_partner_notes" rows="4" class="large-text"><?php echo esc_textarea( $admin_notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal notes about pricing agreements, special terms, etc.', 'hbl' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( in_array( 'founding_partner', (array) $user->roles, true ) ) : ?>
		<div style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.1) 0%, rgba(245, 158, 11, 0.05) 100%); border-left: 4px solid #F59E0B; padding: 15px 20px; margin: 20px 0; border-radius: 0 3px 3px 0;">
			<strong style="color: #92400E;">🌟 <?php esc_html_e( 'Founding Partner Status', 'hbl' ); ?></strong>
			<p style="margin: 8px 0 0; color: #78350F;">
				<?php 
				printf(
					esc_html__( 'This user has Founding Partner status with special launch pricing. On %s, their role will automatically transition to Partner Agency with ongoing partner discounts.', 'hbl' ),
					esc_html( date_i18n( get_option( 'date_format' ), strtotime( self::TRANSITION_DATE ) ) )
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		<?php
	}

	public function save_partner_fields( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['hbl_agency_name'] ) ) {
			update_user_meta( $user_id, '_hbl_agency_name', sanitize_text_field( $_POST['hbl_agency_name'] ) );
		}
		if ( isset( $_POST['hbl_agency_website'] ) ) {
			update_user_meta( $user_id, '_hbl_agency_website', esc_url_raw( $_POST['hbl_agency_website'] ) );
		}
		if ( isset( $_POST['hbl_discount_rate'] ) ) {
			update_user_meta( $user_id, '_hbl_discount_rate', absint( $_POST['hbl_discount_rate'] ) );
		}
		if ( isset( $_POST['hbl_partner_since'] ) ) {
			update_user_meta( $user_id, '_hbl_partner_since', sanitize_text_field( $_POST['hbl_partner_since'] ) );
		}
		if ( isset( $_POST['hbl_partner_notes'] ) ) {
			update_user_meta( $user_id, '_hbl_partner_notes', sanitize_textarea_field( $_POST['hbl_partner_notes'] ) );
		}
	}

	public function add_user_columns( $columns ) {
		$columns['hbl_agency']  = __( 'Agency', 'hbl' );
		$columns['hbl_partner'] = __( 'Partner Status', 'hbl' );
		return $columns;
	}

	public function render_user_columns( $value, $column_name, $user_id ) {
		$user = get_userdata( $user_id );

		switch ( $column_name ) {
			case 'hbl_agency':
				$agency_name = get_user_meta( $user_id, '_hbl_agency_name', true );
				return $agency_name ? esc_html( $agency_name ) : '<span style="color:#9ca3af;">—</span>';

			case 'hbl_partner':
				if ( in_array( 'founding_partner', (array) $user->roles, true ) ) {
					return '<span style="background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 600;">FOUNDING PARTNER</span>';
				} elseif ( in_array( 'partner_agency', (array) $user->roles, true ) ) {
					return '<span style="background: #008080; color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 11px; font-weight: 600;">PARTNER AGENCY</span>';
				}
				return '<span style="color:#9ca3af;">—</span>';
		}

		return $value;
	}

	public function add_admin_menu() {
		add_users_page(
			__( 'Partner Agencies', 'hbl' ),
			__( 'Partner Agencies', 'hbl' ),
			'manage_options',
			'hbl-partner-agencies',
			array( $this, 'render_admin_page' )
		);
	}

	public function enqueue_scripts( $hook ) {
		if ( 'users_page_hbl-partner-agencies' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'hbl-partner-agencies',
			HBL_THEME_URI . '/inc/admin/css/partner-agencies.css',
			array(),
			HBL_VERSION
		);

		wp_enqueue_script(
			'hbl-partner-agencies',
			HBL_THEME_URI . '/inc/admin/js/partner-agencies.js',
			array( 'jquery' ),
			HBL_VERSION,
			true
		);

		wp_localize_script(
			'hbl-partner-agencies',
			'hblPartnerAgencies',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hbl_partner_agencies_nonce' ),
				'strings' => array(
					'creating'     => __( 'Creating...', 'hbl' ),
					'success'      => __( 'Partner user created successfully!', 'hbl' ),
					'error'        => __( 'An error occurred. Please try again.', 'hbl' ),
					'fillRequired' => __( 'Please fill in all required fields.', 'hbl' ),
				),
			)
		);
	}

	public function render_admin_page() {
		$partners = $this->get_all_partners();
		$stats    = $this->get_partner_stats();
		?>
		<div class="wrap hbl-partner-agencies-wrap">
			<h1 class="wp-heading-inline">
				<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; margin-right: 8px;">
					<path d="M17 21V19C17 17.9391 16.5786 16.9217 15.8284 16.1716C15.0783 15.4214 14.0609 15 13 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
					<path d="M23 21V19C22.9993 18.1137 22.7044 17.2528 22.1614 16.5523C21.6184 15.8519 20.8581 15.3516 20 15.13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					<path d="M16 3.13C16.8604 3.35031 17.623 3.85071 18.1676 4.55232C18.7122 5.25392 19.0078 6.11683 19.0078 7.005C19.0078 7.89318 18.7122 8.75608 18.1676 9.45769C17.623 10.1593 16.8604 10.6597 16 10.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				</svg>
				<?php esc_html_e( 'Partner Agencies', 'hbl' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Manage partner agencies who can submit listings for their clients at special rates.', 'hbl' ); ?></p>

			<div class="hbl-partner-stats">
				<div class="hbl-stat-card hbl-stat-founding">
					<span class="hbl-stat-number"><?php echo esc_html( $stats['founding_partners'] ); ?></span>
					<span class="hbl-stat-label"><?php esc_html_e( 'Founding Partners', 'hbl' ); ?></span>
				</div>
				<div class="hbl-stat-card hbl-stat-agency">
					<span class="hbl-stat-number"><?php echo esc_html( $stats['partner_agencies'] ); ?></span>
					<span class="hbl-stat-label"><?php esc_html_e( 'Partner Agencies', 'hbl' ); ?></span>
				</div>
				<div class="hbl-stat-card">
					<span class="hbl-stat-number"><?php echo esc_html( $stats['total_clients'] ); ?></span>
					<span class="hbl-stat-label"><?php esc_html_e( 'Total Client Listings', 'hbl' ); ?></span>
				</div>
			</div>

			<div class="hbl-transition-notice">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
					<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
				</svg>
				<span>
					<?php 
					printf(
						esc_html__( 'Founding Partners will automatically transition to Partner Agency status on %s.', 'hbl' ),
						'<strong>' . esc_html( date_i18n( 'F j, Y', strtotime( self::TRANSITION_DATE ) ) ) . '</strong>'
					);
					?>
				</span>
			</div>

			<div class="hbl-add-partner-section">
				<h2><?php esc_html_e( 'Add New Partner', 'hbl' ); ?></h2>
				<form id="hbl-add-partner-form" class="hbl-partner-form">
					<div class="hbl-form-row">
						<div class="hbl-form-field">
							<label for="partner_username"><?php esc_html_e( 'Username', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="partner_username" name="username" required>
						</div>
						<div class="hbl-form-field">
							<label for="partner_email"><?php esc_html_e( 'Email', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="email" id="partner_email" name="email" required>
						</div>
					</div>
					<div class="hbl-form-row">
						<div class="hbl-form-field">
							<label for="partner_first_name"><?php esc_html_e( 'First Name', 'hbl' ); ?></label>
							<input type="text" id="partner_first_name" name="first_name">
						</div>
						<div class="hbl-form-field">
							<label for="partner_last_name"><?php esc_html_e( 'Last Name', 'hbl' ); ?></label>
							<input type="text" id="partner_last_name" name="last_name">
						</div>
					</div>
					<div class="hbl-form-row">
						<div class="hbl-form-field">
							<label for="partner_agency_name"><?php esc_html_e( 'Agency Name', 'hbl' ); ?> <span class="required">*</span></label>
							<input type="text" id="partner_agency_name" name="agency_name" required placeholder="e.g., WebWorthy Digital">
						</div>
						<div class="hbl-form-field">
							<label for="partner_agency_website"><?php esc_html_e( 'Agency Website', 'hbl' ); ?></label>
							<input type="url" id="partner_agency_website" name="agency_website" placeholder="https://">
						</div>
					</div>
					<div class="hbl-form-row">
						<div class="hbl-form-field">
							<label for="partner_role"><?php esc_html_e( 'Partner Type', 'hbl' ); ?> <span class="required">*</span></label>
							<select id="partner_role" name="role" required>
								<option value="founding_partner"><?php esc_html_e( 'Founding Partner (Special Launch Pricing)', 'hbl' ); ?></option>
								<option value="partner_agency"><?php esc_html_e( 'Partner Agency (Standard Partner)', 'hbl' ); ?></option>
							</select>
						</div>
						<div class="hbl-form-field">
							<label for="partner_discount"><?php esc_html_e( 'Discount Rate (%)', 'hbl' ); ?></label>
							<input type="number" id="partner_discount" name="discount_rate" min="0" max="100" value="25">
						</div>
					</div>
					<div class="hbl-form-row">
						<div class="hbl-form-field hbl-form-field-full">
							<label for="partner_notes"><?php esc_html_e( 'Admin Notes', 'hbl' ); ?></label>
							<textarea id="partner_notes" name="notes" rows="3" placeholder="<?php esc_attr_e( 'e.g., Founding Partner rate $100 → renew $150 2027 → Partner 25% discount ongoing.', 'hbl' ); ?>"></textarea>
						</div>
					</div>
					<div class="hbl-form-actions">
						<button type="submit" class="button button-primary">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Create Partner User', 'hbl' ); ?>
						</button>
					</div>
					<div id="hbl-partner-result" class="hbl-partner-result" style="display: none;"></div>
				</form>
			</div>

			<div class="hbl-partners-list-section">
				<h2><?php esc_html_e( 'Current Partners', 'hbl' ); ?></h2>
				<?php if ( ! empty( $partners ) ) : ?>
				<table class="hbl-partners-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-agency"><?php esc_html_e( 'Agency', 'hbl' ); ?></th>
							<th class="column-user"><?php esc_html_e( 'User', 'hbl' ); ?></th>
							<th class="column-status"><?php esc_html_e( 'Status', 'hbl' ); ?></th>
							<th class="column-discount"><?php esc_html_e( 'Discount', 'hbl' ); ?></th>
							<th class="column-since"><?php esc_html_e( 'Partner Since', 'hbl' ); ?></th>
							<th class="column-clients"><?php esc_html_e( 'Clients', 'hbl' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'hbl' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $partners as $partner ) : ?>
						<tr>
							<td class="column-agency">
								<strong><?php echo esc_html( $partner['agency_name'] ?: '—' ); ?></strong>
								<?php if ( $partner['agency_website'] ) : ?>
								<br><a href="<?php echo esc_url( $partner['agency_website'] ); ?>" target="_blank" class="hbl-agency-link"><?php echo esc_html( $partner['agency_website'] ); ?></a>
								<?php endif; ?>
							</td>
							<td class="column-user">
								<?php echo esc_html( $partner['display_name'] ); ?><br>
								<span class="hbl-user-email"><?php echo esc_html( $partner['email'] ); ?></span>
							</td>
							<td class="column-status">
								<?php if ( 'founding_partner' === $partner['role'] ) : ?>
									<span class="hbl-badge hbl-badge-founding"><?php esc_html_e( 'FOUNDING PARTNER', 'hbl' ); ?></span>
								<?php else : ?>
									<span class="hbl-badge hbl-badge-agency"><?php esc_html_e( 'PARTNER AGENCY', 'hbl' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="column-discount">
								<?php echo $partner['discount_rate'] ? esc_html( $partner['discount_rate'] . '%' ) : '—'; ?>
							</td>
							<td class="column-since">
								<?php echo $partner['partner_since'] ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $partner['partner_since'] ) ) ) : '—'; ?>
							</td>
							<td class="column-clients">
								<?php echo esc_html( $partner['client_count'] ?: '0' ); ?>
							</td>
							<td class="column-actions">
								<a href="<?php echo esc_url( get_edit_user_link( $partner['id'] ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'hbl' ); ?></a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
				<div class="hbl-no-partners">
					<p><?php esc_html_e( 'No partner agencies found. Create your first partner above.', 'hbl' ); ?></p>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function get_all_partners() {
		$users = get_users( array(
			'role__in' => array( 'founding_partner', 'partner_agency' ),
			'orderby'  => 'registered',
			'order'    => 'DESC',
		) );

		$partners = array();
		foreach ( $users as $user ) {
			$partners[] = array(
				'id'             => $user->ID,
				'display_name'   => $user->display_name,
				'email'          => $user->user_email,
				'role'           => in_array( 'founding_partner', (array) $user->roles, true ) ? 'founding_partner' : 'partner_agency',
				'agency_name'    => get_user_meta( $user->ID, '_hbl_agency_name', true ),
				'agency_website' => get_user_meta( $user->ID, '_hbl_agency_website', true ),
				'discount_rate'  => get_user_meta( $user->ID, '_hbl_discount_rate', true ),
				'partner_since'  => get_user_meta( $user->ID, '_hbl_partner_since', true ),
				'client_count'   => get_user_meta( $user->ID, '_hbl_client_count', true ),
			);
		}

		return $partners;
	}

	private function get_partner_stats() {
		$founding = get_users( array( 'role' => 'founding_partner', 'fields' => 'ID' ) );
		$agencies = get_users( array( 'role' => 'partner_agency', 'fields' => 'ID' ) );

		$total_clients = 0;
		$all_partners  = array_merge( $founding, $agencies );
		foreach ( $all_partners as $user_id ) {
			$total_clients += (int) get_user_meta( $user_id, '_hbl_client_count', true );
		}

		return array(
			'founding_partners' => count( $founding ),
			'partner_agencies'  => count( $agencies ),
			'total_clients'     => $total_clients,
		);
	}

	public function check_role_transitions() {
		if ( strtotime( 'now' ) < strtotime( self::TRANSITION_DATE ) ) {
			return;
		}

		$founding_partners = get_users( array( 'role' => 'founding_partner' ) );

		foreach ( $founding_partners as $user ) {
			$user->remove_role( 'founding_partner' );
			$user->add_role( 'partner_agency' );
			
			update_user_meta( $user->ID, '_hbl_role_transition_date', current_time( 'mysql' ) );
			
			$current_discount = get_user_meta( $user->ID, '_hbl_discount_rate', true );
			if ( empty( $current_discount ) ) {
				update_user_meta( $user->ID, '_hbl_discount_rate', 25 );
			}
		}
	}

	public function ajax_create_partner_user() {
		check_ajax_referer( 'hbl_partner_agencies_nonce', 'nonce' );

		if ( ! current_user_can( 'create_users' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hbl' ) ) );
		}

		$username     = isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '';
		$email        = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( $_POST['first_name'] ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( $_POST['last_name'] ) : '';
		$agency_name  = isset( $_POST['agency_name'] ) ? sanitize_text_field( $_POST['agency_name'] ) : '';
		$agency_website = isset( $_POST['agency_website'] ) ? esc_url_raw( $_POST['agency_website'] ) : '';
		$role         = isset( $_POST['role'] ) ? sanitize_text_field( $_POST['role'] ) : 'partner_agency';
		$discount     = isset( $_POST['discount_rate'] ) ? absint( $_POST['discount_rate'] ) : 25;
		$notes        = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

		if ( empty( $username ) || empty( $email ) || empty( $agency_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in all required fields.', 'hbl' ) ) );
		}

		if ( username_exists( $username ) ) {
			wp_send_json_error( array( 'message' => __( 'Username already exists.', 'hbl' ) ) );
		}

		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Email already exists.', 'hbl' ) ) );
		}

		if ( ! in_array( $role, array( 'founding_partner', 'partner_agency' ), true ) ) {
			$role = 'partner_agency';
		}

		$password = wp_generate_password( 12, true, true );

		$user_id = wp_create_user( $username, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
		}

		wp_update_user( array(
			'ID'           => $user_id,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => $first_name ? $first_name . ' ' . $last_name : $username,
		) );

		$user = get_user_by( 'ID', $user_id );
		$user->set_role( $role );

		update_user_meta( $user_id, '_hbl_agency_name', $agency_name );
		update_user_meta( $user_id, '_hbl_agency_website', $agency_website );
		update_user_meta( $user_id, '_hbl_discount_rate', $discount );
		update_user_meta( $user_id, '_hbl_partner_since', current_time( 'Y-m-d' ) );
		update_user_meta( $user_id, '_hbl_partner_notes', $notes );
		update_user_meta( $user_id, '_hbl_client_count', 0 );

		wp_new_user_notification( $user_id, null, 'user' );

		$role_label = 'founding_partner' === $role ? __( 'Founding Partner', 'hbl' ) : __( 'Partner Agency', 'hbl' );

		wp_send_json_success( array(
			'message' => sprintf(
				__( 'Partner user "%1$s" created successfully as %2$s! A password reset email has been sent.', 'hbl' ),
				$agency_name,
				$role_label
			),
			'user_id' => $user_id,
		) );
	}
}

HBL_Partner_Roles::get_instance();

