<?php
/**
 * HBL Claim Listing Form Widget for Elementor
 * 
 * A widget to display the claim listing form with business search functionality
 *
 * @package HBL
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Claim_Listing_Form extends Widget_Base {

	public function get_name() {
		return 'hbl-claim-listing-form';
	}

	public function get_title() {
		return esc_html__( 'HBL Claim Listing Form', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-checkbox';
	}

	public function get_categories() {
		return array( 'hbl-widgets' );
	}

	public function get_keywords() {
		return array( 'claim', 'listing', 'form', 'business', 'verify', 'hbl' );
	}

	protected function register_controls() {
		// ========== CONTENT: GENERAL ==========
		$this->start_controls_section(
			'section_general',
			array(
				'label' => esc_html__( 'General Settings', 'hbl' ),
			)
		);

		$this->add_control(
			'form_title',
			array(
				'label'   => esc_html__( 'Form Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Claim Your Business', 'hbl' ),
			)
		);

		$this->add_control(
			'show_title',
			array(
				'label'        => esc_html__( 'Show Title', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'form_description',
			array(
				'label'     => esc_html__( 'Form Description', 'hbl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => esc_html__( 'Search for your business and submit a claim request. Our team will verify and approve your ownership.', 'hbl' ),
				'condition' => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_name_field',
			array(
				'label'        => esc_html__( 'Show Name Field', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_phone_field',
			array(
				'label'        => esc_html__( 'Show Phone Field', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'submit_button_text',
			array(
				'label'   => esc_html__( 'Submit Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => esc_html__( 'Submit Claim Request', 'hbl' ),
			)
		);

		$this->add_control(
			'coming_soon_plans',
			array(
				'label'       => esc_html__( 'Coming Soon Plan IDs', 'hbl' ),
				'type'        => Controls_Manager::TEXT,
				'description' => esc_html__( 'Enter Plan IDs separated by commas (e.g., 123, 456) to show "Coming Soon" overlay on specific plans.', 'hbl' ),
			)
		);

		$this->add_control(
			'enable_recaptcha',
			array(
				'label'        => esc_html__( 'Enable reCAPTCHA', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Requires reCAPTCHA v2 keys in Elementor → Settings → Integrations.', 'hbl' ),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TYPOGRAPHY ==========
		$this->start_controls_section(
			'section_style_typography',
			array(
				'label' => esc_html__( 'Typography', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'title_typography',
				'label'    => esc_html__( 'Title Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-claim-form-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-claim-form-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'description_color',
			array(
				'label'     => esc_html__( 'Description Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6C757D',
				'selectors' => array(
					'{{WRAPPER}} .hbl-claim-form-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: BUTTONS ==========
		$this->start_controls_section(
			'section_style_buttons',
			array(
				'label' => esc_html__( 'Buttons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => esc_html__( 'Button Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-form-btn',
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => esc_html__( 'Button Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Button Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Button Hover Background Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary:hover' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Enqueue reCAPTCHA if enabled
		if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			wp_enqueue_script( 'google-recaptcha' );
		}
		$listing_id = 0;
		$listing_title = '';
		
		if ( isset( $_GET['listing_id'] ) ) {
			$listing_id = absint( $_GET['listing_id'] );
			if ( isset( $_GET['listing_title'] ) ) {
				$listing_title = sanitize_text_field( urldecode( $_GET['listing_title'] ) );
			} elseif ( $listing_id ) {
				$listing_title = get_the_title( $listing_id );
			}
		} elseif ( get_post_type() === 'at_biz_dir' ) {
			$listing_id = get_the_ID();
			$listing_title = get_the_title( $listing_id );
		}

		// Get dashboard/listings page URL for back button
		$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/all-listings/' );

		// Check if user is logged in
		if ( ! is_user_logged_in() ) {
			$login_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_login_page_link() : wp_login_url();
			$register_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
			?>
			<div class="hbl-claim-form-widget">
				<?php if ( 'yes' === $settings['show_title'] ) : ?>
					<div class="hbl-listing-form-header">
						<h2 class="hbl-claim-form-title"><?php echo esc_html( $settings['form_title'] ); ?></h2>
						<?php if ( ! empty( $settings['form_description'] ) ) : ?>
							<p class="hbl-claim-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<div class="hbl-form-login-required">
					<div class="hbl-form-login-icon">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<h3><?php esc_html_e( 'Login Required', 'hbl' ); ?></h3>
					<p><?php esc_html_e( 'Please login to claim a listing.', 'hbl' ); ?></p>
					<div class="hbl-form-login-buttons">
						<a href="<?php echo esc_url( $login_url ); ?>" class="hbl-form-btn hbl-form-btn-primary"><?php esc_html_e( 'Login', 'hbl' ); ?></a>
						<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary"><?php esc_html_e( 'Register', 'hbl' ); ?></a>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		// Get current user info
		$current_user = wp_get_current_user();
		$user_name = $current_user->display_name;
		$user_email = $current_user->user_email;
		$user_phone = get_user_meta( $current_user->ID, 'phone', true );

		// Get listing title if not already set
		if ( empty( $listing_title ) && $listing_id ) {
			$listing_title = get_the_title( $listing_id );
		}

		// Determine if we have a pre-selected listing
		$has_preselected = ! empty( $listing_id ) && ! empty( $listing_title );
		
		// Get listing packages/pricing plans from Directorist. All Directorist
		// coupling lives in HBL_Pricing_Plans so future plugin changes only need
		// handling there. The claim form only shows the plan cards, so it does
		// not need the per-plan field restrictions.
		$listing_packages = class_exists( 'HBL_Pricing_Plans' )
			? \HBL_Pricing_Plans::get_plans()
			: array();
		?>
		<div class="hbl-claim-form-widget">
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<div class="hbl-listing-form-header">
					<div class="hbl-listing-form-header-top">
						<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-back">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Back', 'hbl' ); ?>
						</a>
					</div>
					<h2 class="hbl-claim-form-title"><?php echo esc_html( $settings['form_title'] ); ?></h2>
					<?php if ( ! empty( $settings['form_description'] ) ) : ?>
						<p class="hbl-claim-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form id="hbl-claim-listing-form" class="hbl-listing-form" method="post">
				<?php wp_nonce_field( 'hbl_claim_nonce', 'claim_nonce' ); ?>
				<input type="hidden" name="action" value="dcl_submit_claim">
				<input type="hidden" name="post_id" id="hbl-claim-listing-id" value="<?php echo esc_attr( $listing_id ); ?>">
				<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'directorist_claim_nonce' ) ); ?>">

				<?php if ( ! empty( $listing_packages ) ) : ?>
				<!-- Listing Package Section -->
				<div class="hbl-form-section hbl-form-section-highlight">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M21 16V8C20.9996 7.6493 20.9071 7.3048 20.7315 7.00017C20.556 6.69555 20.3037 6.44158 20 6.26L13 2.26C12.696 2.08805 12.3511 1.99804 12 1.99804C11.6489 1.99804 11.304 2.08805 11 2.26L4 6.26C3.69626 6.44158 3.44398 6.69555 3.26846 7.00017C3.09294 7.3048 3.00036 7.6493 3 8V16C3.00036 16.3507 3.09294 16.6952 3.26846 16.9998C3.44398 17.3045 3.69626 17.5584 4 17.74L11 21.74C11.304 21.912 11.6489 22.002 12 22.002C12.3511 22.002 12.696 21.912 13 21.74L20 17.74C20.3037 17.5584 20.556 17.3045 20.7315 16.9998C20.9071 16.6952 20.9996 16.3507 21 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M3.27 6.96L12 12.01L20.73 6.96" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M12 22.08V12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Choose Your Listing Package', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Select a package that best suits your business needs', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="claim_listing_package" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 16V8C20.9996 7.6493 20.9071 7.3048 20.7315 7.00017C20.556 6.69555 20.3037 6.44158 20 6.26L13 2.26C12.696 2.08805 12.3511 1.99804 12 1.99804C11.6489 1.99804 11.304 2.08805 11 2.26L4 6.26C3.69626 6.44158 3.44398 6.69555 3.26846 7.00017C3.09294 7.3048 3.00036 7.6493 3 8V16C3.00036 16.3507 3.09294 16.6952 3.26846 16.9998C3.44398 17.3045 3.69626 17.5584 4 17.74L11 21.74C11.304 21.912 11.6489 22.002 12 22.002C12.3511 22.002 12.696 21.912 13 21.74L20 17.74C20.3037 17.5584 20.556 17.3045 20.7315 16.9998C20.9071 16.6952 20.9996 16.3507 21 16Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Listing Package', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-package-options">
								<?php 
								$coming_soon_ids = array();
								if ( ! empty( $settings['coming_soon_plans'] ) ) {
									$coming_soon_ids = array_map( 'trim', explode( ',', $settings['coming_soon_plans'] ) );
								}

								foreach ( $listing_packages as $index => $package ) : 
									$is_coming_soon = in_array( (string) $package['id'], $coming_soon_ids );
									$is_selected = ( $index === 0 && ! $is_coming_soon ); 
									$coming_soon_class = $is_coming_soon ? 'hbl-plan-coming-soon' : '';
									?>
									<label class="hbl-form-package-option <?php echo $package['recommended'] ? 'hbl-form-package-recommended' : ''; ?> <?php echo esc_attr( $coming_soon_class ); ?>">
										<input type="radio" name="claim_listing_package" id="claim_package_<?php echo esc_attr( $package['id'] ); ?>" value="<?php echo esc_attr( $package['id'] ); ?>" 
											<?php echo $is_selected ? 'checked' : ''; ?> <?php echo $is_coming_soon ? 'disabled onclick="return false;"' : 'required'; ?>>
										<div class="hbl-form-package-card">
											<?php if ( $is_coming_soon ) : ?>
												<div class="hbl-plan-coming-soon-overlay">
													<div class="hbl-rocket-icon-small">🚀</div>
													<span class="hbl-plan-coming-soon-text"><?php esc_html_e( 'Coming Soon', 'hbl' ); ?></span>
												</div>
											<?php endif; ?>
											<?php if ( $package['recommended'] ) : ?>
												<span class="hbl-form-package-badge"><?php esc_html_e( 'Recommended', 'hbl' ); ?></span>
											<?php endif; ?>
											<div class="hbl-form-package-header">
												<span class="hbl-form-package-name"><?php echo esc_html( $package['title'] ); ?></span>
												<span class="hbl-form-package-price">
													<?php if ( $package['is_free'] || $package['price'] <= 0 ) : ?>
														<span class="hbl-price-free"><?php esc_html_e( 'Free', 'hbl' ); ?></span>
													<?php else : ?>
														<span class="hbl-price-currency">$</span><?php echo esc_html( number_format( $package['price'], 2 ) ); ?>
													<?php endif; ?>
												</span>
											</div>
											<?php if ( ! empty( $package['description'] ) ) : ?>
												<p class="hbl-form-package-desc"><?php echo esc_html( $package['description'] ); ?></p>
											<?php endif; ?>
											<div class="hbl-form-package-check">
												<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
													<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												</svg>
											</div>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<!-- Business Search Section -->
				<div class="hbl-form-section hbl-form-section-highlight" id="hbl-section-search">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Find Your Business', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Search for and select the business you want to claim', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<!-- Business Search -->
						<div class="hbl-form-group">
							<label for="hbl-business-search" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="11" cy="11" r="8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M21 21L16.65 16.65" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Search Business', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper hbl-form-search-wrapper">
								<input type="text" id="hbl-business-search" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Type business name to search...', 'hbl' ); ?>" autocomplete="off" <?php echo $has_preselected ? 'style="display:none;"' : ''; ?>>
								<div class="hbl-search-results" id="hbl-search-results" style="display: none;"></div>
							</div>
							<p class="hbl-form-help-text" id="hbl-search-help" <?php echo $has_preselected ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Start typing to find your business in our directory', 'hbl' ); ?></p>
						</div>

						<!-- Selected Business Display -->
						<div class="hbl-form-group hbl-selected-business-wrapper" id="hbl-selected-business" <?php echo ! $has_preselected ? 'style="display:none;"' : ''; ?>>
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M19 21V5C19 4.46957 18.7893 3.96086 18.4142 3.58579C18.0391 3.21071 17.5304 3 17 3H7C6.46957 3 5.96086 3.21071 5.58579 3.58579C5.21071 3.96086 5 4.46957 5 5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M3 21H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M9 7H10M9 11H10M9 15H10M14 7H15M14 11H15M14 15H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Selected Business', 'hbl' ); ?></span>
							</label>
							<div class="hbl-selected-business-card">
								<div class="hbl-selected-business-info">
									<div class="hbl-selected-business-icon">
										<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M19 21V5C19 4.46957 18.7893 3.96086 18.4142 3.58579C18.0391 3.21071 17.5304 3 17 3H7C6.46957 3 5.96086 3.21071 5.58579 3.58579C5.21071 3.96086 5 4.46957 5 5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M3 21H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</div>
									<div class="hbl-selected-business-details">
										<span class="hbl-selected-business-name" id="hbl-selected-business-name"><?php echo esc_html( $listing_title ); ?></span>
										<span class="hbl-selected-business-id" id="hbl-selected-business-id-display"><?php echo $listing_id ? sprintf( esc_html__( 'ID: %d', 'hbl' ), $listing_id ) : ''; ?></span>
									</div>
								</div>
								<button type="button" class="hbl-change-business-btn" id="hbl-change-business">
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Change', 'hbl' ); ?>
								</button>
							</div>
						</div>
					</div>
				</div>

				<!-- Your Information Section -->
				<div class="hbl-form-section" id="hbl-section-info">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Your Information', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Provide your contact details for verification', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<?php if ( 'yes' === $settings['show_name_field'] ) : ?>
						<!-- Full Name -->
						<div class="hbl-form-group">
							<label for="hbl-claim-name" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Full Name', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="hbl-claim-name" name="claimer_name" class="hbl-form-input" value="<?php echo esc_attr( $user_name ); ?>" required>
							</div>
						</div>
						<?php endif; ?>

						<!-- Email & Phone Row -->
						<div class="hbl-form-row">
							<div class="hbl-form-group hbl-form-group-half">
								<label for="hbl-claim-email" class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Email Address', 'hbl' ); ?></span>
									<span class="hbl-form-required">*</span>
								</label>
								<div class="hbl-form-input-wrapper">
									<input type="email" id="hbl-claim-email" name="claimer_email" class="hbl-form-input" value="<?php echo esc_attr( $user_email ); ?>" required readonly>
								</div>
							</div>
							<?php if ( 'yes' === $settings['show_phone_field'] ) : ?>
							<div class="hbl-form-group hbl-form-group-half">
								<label for="hbl-claim-phone" class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1468 21.5902 20.9046 21.7335 20.6407 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77382 17.3147 6.72533 15.2662 5.18999 12.85C3.49997 10.2412 2.44824 7.27099 2.11999 4.18001C2.095 3.90347 2.12787 3.62477 2.21649 3.36163C2.30512 3.09849 2.44756 2.85669 2.63476 2.65163C2.82196 2.44656 3.0498 2.28271 3.30379 2.17053C3.55777 2.05834 3.83233 2.00027 4.10999 2.00001H7.10999C7.5953 1.99523 8.06579 2.16708 8.43376 2.48354C8.80173 2.79999 9.04207 3.23945 9.10999 3.72001C9.23662 4.68007 9.47144 5.62273 9.80999 6.53001C9.94454 6.88793 9.97366 7.27692 9.8939 7.65089C9.81415 8.02485 9.62886 8.36812 9.35999 8.64001L8.08999 9.91001C9.51355 12.4136 11.5864 14.4865 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9751 14.1859 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Phone Number', 'hbl' ); ?></span>
									<span class="hbl-form-required">*</span>
								</label>
								<div class="hbl-form-input-wrapper">
									<input type="tel" id="hbl-claim-phone" name="claimer_phone" class="hbl-form-input" value="<?php echo esc_attr( $user_phone ); ?>" placeholder="<?php esc_attr_e( '04XX XXX XXX', 'hbl' ); ?>" required>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Verification Details Section -->
				<div class="hbl-form-section" id="hbl-section-verification">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Verification Details', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Help us verify your ownership of this business', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<!-- Verification Details -->
						<div class="hbl-form-group">
							<label for="hbl-claim-details" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M14 2V8H20M16 13H8M16 17H8M10 9H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Proof of Ownership', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<textarea id="hbl-claim-details" name="claimer_details" class="hbl-form-textarea" rows="5" placeholder="<?php esc_attr_e( 'Please provide details to verify your ownership of this business. For example:\n• Your role in the business (Owner, Manager, etc.)\n• How long you have owned/managed this business\n• Any documentation you can provide (ABN, business registration, etc.)', 'hbl' ); ?>" required></textarea>
							</div>
							<p class="hbl-form-help-text"><?php esc_html_e( 'Provide as much detail as possible to help us verify your claim quickly.', 'hbl' ); ?></p>
						</div>

						<!-- Notice -->
						<div class="hbl-form-notice">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" stroke="currentColor" stroke-width="2"/>
								<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							</svg>
							<div class="hbl-form-notice-content">
								<strong><?php esc_html_e( 'What happens next?', 'hbl' ); ?></strong>
								<p><?php esc_html_e( 'Our team will review your claim request within 24-48 hours. You will receive an email notification once your claim is approved or if we need additional information.', 'hbl' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<!-- Form Message -->
				<div id="hbl-claim-form-message" class="hbl-form-message" style="display: none;"></div>

				<?php if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) ) : ?>
				<div class="hbl-recaptcha-wrapper" style="margin-bottom:16px;">
					<?php if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) : ?>
						<div style="border:2px dashed #ccc;padding:12px 16px;text-align:center;color:#888;font-size:13px;border-radius:4px;background:#f9f9f9;">reCAPTCHA (renders on frontend)</div>
					<?php else : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( get_option( 'elementor_pro_recaptcha_site_key' ) ); ?>"></div>
					<?php endif; ?>
					<span class="hbl-recaptcha-error" style="display:none;color:#dc3545;font-size:13px;margin-top:4px;"><?php esc_html_e( 'Please complete the CAPTCHA.', 'hbl' ); ?></span>
				</div>
				<?php endif; ?>

				<!-- Submit Button -->
				<div class="hbl-form-actions">
					<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php esc_html_e( 'Cancel', 'hbl' ); ?></span>
					</a>
					<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large" id="hbl-claim-submit-btn">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['submit_button_text'] ); ?></span>
					</button>
				</div>

				<!-- Secure Notice -->
				<div class="hbl-form-secure-notice">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
						<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<span><?php esc_html_e( 'Your information is secure and will only be used for verification purposes.', 'hbl' ); ?></span>
				</div>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var searchTimeout = null;
			var $searchInput = $('#hbl-business-search');
			var $searchResults = $('#hbl-search-results');
			var $selectedBusiness = $('#hbl-selected-business');
			var $listingIdInput = $('#hbl-claim-listing-id');
			var $businessNameDisplay = $('#hbl-selected-business-name');
			var $businessIdDisplay = $('#hbl-selected-business-id-display');
			var $searchHelp = $('#hbl-search-help');
			
			// Function to get URL parameter
			function getUrlParam(param) {
				var urlParams = new URLSearchParams(window.location.search);
				return urlParams.get(param);
			}
			
			// Check for listing_id in URL on load
			function checkUrlParams() {
				var listingId = getUrlParam('listing_id');
				var listingTitle = getUrlParam('listing_title');
				
				if (listingId && listingId !== '0') {
					$listingIdInput.val(listingId);
					
					if (listingTitle) {
						selectBusiness(listingId, decodeURIComponent(listingTitle));
					} else {
						// Fetch title via AJAX if not provided
						fetchBusinessTitle(listingId);
					}
				}
				
				// Also check localStorage
				var storedId = localStorage.getItem('hbl_claim_listing_id');
				var storedTitle = localStorage.getItem('hbl_claim_listing_title');
				
				if (!listingId && storedId && storedId !== '0' && storedId !== 'null') {
					if (storedTitle && storedTitle !== 'null') {
						selectBusiness(storedId, storedTitle);
					} else {
						fetchBusinessTitle(storedId);
					}
				}
			}
			
			// Fetch business title by ID
			function fetchBusinessTitle(listingId) {
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					type: 'POST',
					data: {
						action: 'hbl_get_listing_title',
						listing_id: listingId,
						nonce: '<?php echo wp_create_nonce( 'hbl_search_nonce' ); ?>'
					},
					success: function(response) {
						if (response.success && response.data.title) {
							selectBusiness(listingId, response.data.title);
						}
					}
				});
			}
			
			// Business search
			$searchInput.on('input', function() {
				var query = $(this).val().trim();
				
				if (searchTimeout) {
					clearTimeout(searchTimeout);
				}
				
				if (query.length < 2) {
					$searchResults.hide().empty();
					return;
				}
				
				searchTimeout = setTimeout(function() {
					searchBusinesses(query);
				}, 300);
			});
			
			// Search businesses via AJAX
			function searchBusinesses(query) {
				$searchResults.html('<div class="hbl-search-loading"><span class="hbl-spinner"></span> <?php esc_html_e( 'Searching...', 'hbl' ); ?></div>').show();
				
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					type: 'POST',
					data: {
						action: 'hbl_search_listings',
						query: query,
						nonce: '<?php echo wp_create_nonce( 'hbl_search_nonce' ); ?>'
					},
					success: function(response) {
						if (response.success && response.data.listings.length > 0) {
							var html = '';
							$.each(response.data.listings, function(index, listing) {
								html += '<div class="hbl-search-result-item" data-id="' + listing.id + '" data-title="' + escapeHtml(listing.title) + '">';
								html += '<div class="hbl-search-result-icon">';
								html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M19 21V5C19 4.46957 18.7893 3.96086 18.4142 3.58579C18.0391 3.21071 17.5304 3 17 3H7C6.46957 3 5.96086 3.21071 5.58579 3.58579C5.21071 3.96086 5 4.46957 5 5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
								html += '</div>';
								html += '<div class="hbl-search-result-info">';
								html += '<span class="hbl-search-result-title">' + escapeHtml(listing.title) + '</span>';
								if (listing.address) {
									html += '<span class="hbl-search-result-address">' + escapeHtml(listing.address) + '</span>';
								}
								html += '</div>';
								html += '</div>';
							});
							$searchResults.html(html).show();
						} else {
							$searchResults.html('<div class="hbl-search-no-results"><?php esc_html_e( 'No businesses found. Try a different search term.', 'hbl' ); ?></div>').show();
						}
					},
					error: function() {
						$searchResults.html('<div class="hbl-search-error"><?php esc_html_e( 'Search failed. Please try again.', 'hbl' ); ?></div>').show();
					}
				});
			}
			
			// Select a business from search results
			$(document).on('click', '.hbl-search-result-item', function() {
				var id = $(this).data('id');
				var title = $(this).data('title');
				selectBusiness(id, title);
			});
			
			// Select business function
			function selectBusiness(id, title) {
				$listingIdInput.val(id);
				$businessNameDisplay.text(title);
				$businessIdDisplay.text('<?php esc_html_e( 'ID:', 'hbl' ); ?> ' + id);
				
				$searchInput.val('').hide();
				$searchResults.hide().empty();
				$searchHelp.hide();
				$selectedBusiness.show();
			}
			
			// Change business button
			$('#hbl-change-business').on('click', function() {
				$listingIdInput.val('');
				$businessNameDisplay.text('');
				$businessIdDisplay.text('');
				$selectedBusiness.hide();
				$searchInput.val('').show().focus();
				$searchHelp.show();
				
				// Clear localStorage
				localStorage.removeItem('hbl_claim_listing_id');
				localStorage.removeItem('hbl_claim_listing_title');
			});
			
			// Close search results when clicking outside
			$(document).on('click', function(e) {
				if (!$(e.target).closest('.hbl-form-search-wrapper').length) {
					$searchResults.hide();
				}
			});
			
			// Helper function to escape HTML
			function escapeHtml(text) {
				var div = document.createElement('div');
				div.textContent = text;
				return div.innerHTML;
			}
			
			// Form submission
			$('#hbl-claim-listing-form').on('submit', function(e) {
				e.preventDefault();
				
				var $form = $(this);
				var $btn = $('#hbl-claim-submit-btn');
				var $message = $('#hbl-claim-form-message');
				var originalBtnHtml = $btn.html();
				
				// Get listing ID
				var listingId = $listingIdInput.val();
				
				if (!listingId || listingId === '0') {
					$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'Please select a business to claim.', 'hbl' ); ?>').show();
					$('html, body').animate({ scrollTop: $message.offset().top - 100 }, 300);
					return;
				}

				// reCAPTCHA validation
				if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
					if (!grecaptcha.getResponse()) {
						$form.find('.hbl-recaptcha-error').show();
						return;
					}
					$form.find('.hbl-recaptcha-error').hide();
				}
				
				// Show loading state - spinner on left only
				$btn.prop('disabled', true).html('<span class="hbl-spinner hbl-spinner-btn"></span><span><?php esc_html_e( 'Submitting...', 'hbl' ); ?></span>');
				$message.hide();
				
				// Get selected package
				var planId = $('input[name="claim_listing_package"]:checked').val();
				
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					type: 'POST',
					data: {
						action: 'hbl_submit_claim',
						listing_id: listingId,
						plan_id: planId || '',
						nonce: '<?php echo esc_js( wp_create_nonce( 'hbl_claim_nonce' ) ); ?>',
						claimer_name: $('#hbl-claim-name').val(),
						claimer_phone: $('#hbl-claim-phone').val(),
						claimer_details: $('#hbl-claim-details').val(),
						'g-recaptcha-response': (typeof grecaptcha !== 'undefined') ? grecaptcha.getResponse() : ''
					},
					success: function(response) {
						$btn.prop('disabled', false).html(originalBtnHtml);
						if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
						
						if (response.success) {
							// Clear localStorage
							localStorage.removeItem('hbl_claim_listing_id');
							localStorage.removeItem('hbl_claim_listing_title');
							
							// Handle checkout redirect for paid packages
							if (response.data.requires_payment && response.data.checkout_url) {
								$message.removeClass('error').addClass('success').html(response.data.message + ' <?php esc_html_e( 'Redirecting to checkout...', 'hbl' ); ?>').show();
								setTimeout(function() {
									window.location.href = response.data.checkout_url;
								}, 1500);
							} else {
								// Free claim submitted successfully
								$message.removeClass('error').addClass('success').html(response.data.message).show();
								$form.find('textarea').val('');
								
								// Optionally redirect to dashboard after a delay
								if (response.data.redirect_url) {
									setTimeout(function() {
										window.location.href = response.data.redirect_url;
									}, 2000);
								}
							}
						} else {
							$message.removeClass('success').addClass('error').html(response.data.message || '<?php esc_html_e( 'There was an error submitting your claim. Please try again.', 'hbl' ); ?>').show();
						}
						
						$('html, body').animate({ scrollTop: $message.offset().top - 100 }, 300);
					},
					error: function() {
						$btn.prop('disabled', false).html(originalBtnHtml);
						if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
						$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'There was an error submitting your claim. Please try again.', 'hbl' ); ?>').show();
						$('html, body').animate({ scrollTop: $message.offset().top - 100 }, 300);
					}
				});
			});
			
			// Initialize
			checkUrlParams();
		});
		</script>
		<?php
	}
}
