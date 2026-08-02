<?php
/**
 * HBL Checkout Widget
 * 
 * A beautiful checkout widget with payment method selection.
 * Integrated with Stripe for payment processing.
 *
 * @package HBL
 * @since 1.3.0
 */

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Note: Stripe AJAX handlers (hbl_create_stripe_session, hbl_verify_stripe_payment, hbl_build_stripe_body) 
// are registered in functions.php for global availability

class HBL_Checkout extends Widget_Base {

	public function get_name() {
		return 'hbl-checkout';
	}

	public function get_title() {
		return esc_html__( 'HBL Checkout', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-checkout';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'checkout', 'payment', 'paypal', 'stripe', 'cart', 'order', 'buy' );
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
			'page_title',
			array(
				'label'   => esc_html__( 'Page Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Checkout',
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
			'page_description',
			array(
				'label'     => esc_html__( 'Page Description', 'hbl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => 'Complete your purchase securely.',
				'condition' => array(
					'show_title' => 'yes',
				),
			)
		);

		$this->add_control(
			'require_login',
			array(
				'label'        => esc_html__( 'Require Login', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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

		$this->add_control(
			'success_redirect',
			array(
				'label'       => esc_html__( 'Success Redirect URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/payment-receipt/' ),
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'Where to redirect after successful payment', 'hbl' ),
			)
		);

		$this->add_control(
			'failure_redirect',
			array(
				'label'       => esc_html__( 'Failure Redirect URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/transaction-failed/' ),
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'Where to redirect after failed payment', 'hbl' ),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: PAYMENT METHODS ==========
		$this->start_controls_section(
			'section_payment_methods',
			array(
				'label' => esc_html__( 'Payment Methods', 'hbl' ),
			)
		);

		$this->add_control(
			'enable_stripe',
			array(
				'label'        => esc_html__( 'Enable Stripe', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Uses Stripe API keys from Elementor Pro settings or theme options.', 'hbl' ),
			)
		);

		$this->add_control(
			'stripe_test_mode',
			array(
				'label'        => esc_html__( 'Stripe Test Mode', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Enable test mode for Stripe payments.', 'hbl' ),
				'condition'    => array(
					'enable_stripe' => 'yes',
				),
			)
		);

		$this->add_control(
			'enable_paypal',
			array(
				'label'        => esc_html__( 'Enable PayPal', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'enable_bank_transfer',
			array(
				'label'        => esc_html__( 'Enable Bank Transfer', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'bank_details',
			array(
				'label'       => esc_html__( 'Bank Transfer Details', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => "Bank: Example Bank\nBSB: 000-000\nAccount: 12345678\nAccount Name: HBL Pty Ltd",
				'condition'   => array(
					'enable_bank_transfer' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: SECURE BADGE ==========
		$this->start_controls_section(
			'section_security',
			array(
				'label' => esc_html__( 'Security Badge', 'hbl' ),
			)
		);

		$this->add_control(
			'show_security_badge',
			array(
				'label'        => esc_html__( 'Show Security Badge', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'security_text',
			array(
				'label'     => esc_html__( 'Security Text', 'hbl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => 'Your payment information is encrypted and secure. We never store your card details.',
				'condition' => array(
					'show_security_badge' => 'yes',
				),
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
				'selector' => '{{WRAPPER}} .hbl-checkout-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-checkout-title' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-checkout-description' => 'color: {{VALUE}};',
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
		// Start session for coupon data
		if ( ! session_id() ) {
			session_start();
		}
		
		$settings = $this->get_settings_for_display();

		// Enqueue reCAPTCHA if enabled
		if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			wp_enqueue_script( 'google-recaptcha' );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$plan_id = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
		$listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
		
		// Sample order data - in production, fetch from database
		$order_data = $this->get_order_data( $order_id, $plan_id, $listing_id );

		// Check if user needs to login
		if ( 'yes' === $settings['require_login'] && ! is_user_logged_in() ) {
			$this->render_login_required( $settings );
			return;
		}

		// Check if there's anything to checkout
		if ( empty( $order_data['items'] ) ) {
			$this->render_empty_cart( $settings );
			return;
		}

		// Get current user info
		$current_user = wp_get_current_user();
		$user_name = $current_user->display_name;
		$user_email = $current_user->user_email;
		$user_phone = get_user_meta( $current_user->ID, 'phone', true );

		// Dashboard URL for back button
		$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
		?>
		<div class="hbl-checkout-widget">
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<div class="hbl-checkout-header">
					<div class="hbl-checkout-header-top">
						<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-back">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Back', 'hbl' ); ?>
						</a>
					</div>
					<h2 class="hbl-checkout-title"><?php echo esc_html( $settings['page_title'] ); ?></h2>
					<?php if ( ! empty( $settings['page_description'] ) ) : ?>
						<p class="hbl-checkout-description"><?php echo esc_html( $settings['page_description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="hbl-checkout-layout">
				<!-- Main Checkout Form -->
				<div class="hbl-checkout-main">
					<form id="hbl-checkout-form" class="hbl-checkout-form" method="post">
						<?php wp_nonce_field( 'hbl_checkout_nonce', 'checkout_nonce' ); ?>
						<input type="hidden" name="action" value="hbl_process_checkout">
						<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
						<input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan_id ); ?>">
						<input type="hidden" name="listing_id" value="<?php echo esc_attr( $listing_id ); ?>">

						<!-- Billing Information Section -->
						<div class="hbl-form-section">
							<div class="hbl-form-section-header">
								<div class="hbl-form-section-icon">
									<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
								</div>
								<div class="hbl-form-section-title">
									<h3><?php esc_html_e( 'Billing Information', 'hbl' ); ?></h3>
									<p><?php esc_html_e( 'Enter your billing details', 'hbl' ); ?></p>
								</div>
							</div>
							<div class="hbl-form-section-content">
								<!-- Full Name -->
								<div class="hbl-form-group">
									<label for="billing_name" class="hbl-form-label">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<span><?php esc_html_e( 'Full Name', 'hbl' ); ?></span>
										<span class="hbl-form-required">*</span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="text" id="billing_name" name="billing_name" class="hbl-form-input" value="<?php echo esc_attr( $user_name ); ?>" required>
									</div>
								</div>

								<!-- Email & Phone Row -->
								<div class="hbl-form-row">
									<div class="hbl-form-group hbl-form-group-half">
										<label for="billing_email" class="hbl-form-label">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
												<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<span><?php esc_html_e( 'Email Address', 'hbl' ); ?></span>
											<span class="hbl-form-required">*</span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="email" id="billing_email" name="billing_email" class="hbl-form-input" value="<?php echo esc_attr( $user_email ); ?>" required>
										</div>
									</div>
									<div class="hbl-form-group hbl-form-group-half">
										<label for="billing_phone" class="hbl-form-label">
											<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
												<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1468 21.5902 20.9046 21.7335 20.6407 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77382 17.3147 6.72533 15.2662 5.18999 12.85C3.49997 10.2412 2.44824 7.27099 2.11999 4.18001C2.095 3.90347 2.12787 3.62477 2.21649 3.36163C2.30512 3.09849 2.44756 2.85669 2.63476 2.65163C2.82196 2.44656 3.0498 2.28271 3.30379 2.17053C3.55777 2.05834 3.83233 2.00027 4.10999 2.00001H7.10999C7.5953 1.99523 8.06579 2.16708 8.43376 2.48354C8.80173 2.79999 9.04207 3.23945 9.10999 3.72001C9.23662 4.68007 9.47144 5.62273 9.80999 6.53001C9.94454 6.88793 9.97366 7.27692 9.8939 7.65089C9.81415 8.02485 9.62886 8.36812 9.35999 8.64001L8.08999 9.91001C9.51355 12.4136 11.5864 14.4865 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9751 14.1859 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											</svg>
											<span><?php esc_html_e( 'Phone Number', 'hbl' ); ?></span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="tel" id="billing_phone" name="billing_phone" class="hbl-form-input" value="<?php echo esc_attr( $user_phone ); ?>" placeholder="<?php esc_attr_e( '04XX XXX XXX', 'hbl' ); ?>">
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Hidden payment method - always Stripe -->
						<input type="hidden" name="payment_method" value="stripe">

						<!-- Form Message -->
						<div id="hbl-checkout-message" class="hbl-form-message" style="display: none;"></div>
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
							<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large hbl-form-btn-block" id="hbl-checkout-submit-btn">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php printf( esc_html__( 'Pay %s', 'hbl' ), '$' . number_format( $order_data['total'], 2 ) ); ?></span>
							</button>
						</div>

						<?php if ( 'yes' === $settings['show_security_badge'] ) : ?>
						<!-- Security Notice -->
						<div class="hbl-form-secure-notice">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 22S20 18 20 12V5L12 2L4 5V12C4 18 12 22 12 22Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<span><?php echo esc_html( $settings['security_text'] ); ?></span>
						</div>
						<?php endif; ?>
					</form>
				</div>

				<!-- Order Summary Sidebar -->
				<div class="hbl-checkout-sidebar">
					<div class="hbl-order-summary">
						<div class="hbl-order-summary-header">
							<h3>
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M6 2L3 6V20C3 20.5304 3.21071 21.0391 3.58579 21.4142C3.96086 21.7893 4.46957 22 5 22H19C19.5304 22 20.0391 21.7893 20.4142 21.4142C20.7893 21.0391 21 20.5304 21 20V6L18 2H6Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M3 6H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M16 10C16 11.0609 15.5786 12.0783 14.8284 12.8284C14.0783 13.5786 13.0609 14 12 14C10.9391 14 9.92172 13.5786 9.17157 12.8284C8.42143 12.0783 8 11.0609 8 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Order Summary', 'hbl' ); ?>
							</h3>
						</div>
						<div class="hbl-order-summary-items">
							<?php foreach ( $order_data['items'] as $item ) : ?>
							<div class="hbl-order-item">
								<div class="hbl-order-item-info">
									<span class="hbl-order-item-name"><?php echo esc_html( $item['name'] ); ?></span>
									<?php if ( ! empty( $item['description'] ) ) : ?>
									<span class="hbl-order-item-desc"><?php echo esc_html( $item['description'] ); ?></span>
									<?php endif; ?>
								</div>
								<span class="hbl-order-item-price">$<?php echo esc_html( number_format( $item['price'], 2 ) ); ?></span>
							</div>
							<?php endforeach; ?>
						</div>
						<div class="hbl-order-summary-totals">
							<div class="hbl-order-subtotal">
								<span><?php esc_html_e( 'Subtotal', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $order_data['subtotal'], 2 ) ); ?></span>
							</div>
							<?php if ( $order_data['tax'] > 0 ) : ?>
							<div class="hbl-order-tax">
								<span><?php esc_html_e( 'GST (10%)', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $order_data['tax'], 2 ) ); ?></span>
							</div>
							<?php endif; ?>
							<?php if ( $order_data['discount'] > 0 ) : ?>
							<div class="hbl-order-discount">
								<span><?php esc_html_e( 'Discount', 'hbl' ); ?></span>
								<span>-$<?php echo esc_html( number_format( $order_data['discount'], 2 ) ); ?></span>
							</div>
							<?php endif; ?>
							<div class="hbl-order-total">
								<span><?php esc_html_e( 'Total', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $order_data['total'], 2 ) ); ?></span>
							</div>
						</div>
					</div>

					<!-- Coupon Code (Directorist Coupon Extension) -->
					<div class="hbl-coupon-section">
						
						<div class="hbl-coupon-toggle-wrapper">
							<button type="button" class="hbl-coupon-toggle" id="hbl-coupon-toggle">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.59 13.41L13.42 20.58C13.2343 20.766 13.0137 20.9135 12.7709 21.0141C12.5281 21.1148 12.2678 21.1666 12.005 21.1666C11.7422 21.1666 11.4819 21.1148 11.2391 21.0141C10.9963 20.9135 10.7757 20.766 10.59 20.58L2 12V2H12L20.59 10.59C20.9625 10.9647 21.1716 11.4716 21.1716 12C21.1716 12.5284 20.9625 13.0353 20.59 13.41Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M7 7H7.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php esc_html_e( 'Have a coupon code?', 'hbl' ); ?>
							</button>
						</div>
						<div class="hbl-coupon-form" id="hbl-coupon-form" style="display: none;">
							<div class="hbl-coupon-input-wrapper">
								<div class="hbl-form-input-wrapper">
									<input type="text" id="coupon_code" name="coupon_code" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Enter coupon code', 'hbl' ); ?>">
									<button type="button" class="hbl-form-btn hbl-form-btn-secondary" id="hbl-apply-coupon">
										<?php esc_html_e( 'Apply', 'hbl' ); ?>
									</button>
								</div>
								<div id="coupon-message" class="coupon-message" style="display: none;"></div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			// Coupon toggle - prevent multiple bindings and conflicts
			$('#hbl-coupon-toggle').off('click').on('click', function(e) {
				e.preventDefault();
				e.stopPropagation();
				
				var $form = $('#hbl-coupon-form');
				var isVisible = $form.is(':visible');
				
				if (isVisible) {
					$form.slideUp(300);
				} else {
					$form.slideDown(300);
				}
				
				return false;
			});
			
			// Prevent any other click handlers from interfering
			$('.hbl-coupon-section').off('click', '**');
			
			// Coupon application functionality
			$('#hbl-apply-coupon').on('click', function() {
				var $btn = $(this);
				var $input = $('#coupon_code');
				var couponCode = $input.val().trim();
				var $message = $('#coupon-message');
				var $checkoutMessage = $('#hbl-checkout-message');
				
				if (!couponCode) {
					$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'Please enter a coupon code.', 'hbl' ); ?>').show();
					return;
				}
				
				var originalBtnText = $btn.text();
				$btn.prop('disabled', true).text('<?php esc_html_e( 'Applying...', 'hbl' ); ?>');
				$message.hide();
				
				// Apply coupon via AJAX
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					type: 'POST',
					data: {
						action: 'hbl_apply_directorist_coupon',
						coupon_code: couponCode,
						listing_id: $('input[name="listing_id"]').val(),
						plan_id: $('input[name="plan_id"]').val(),
						checkout_nonce: $('input[name="checkout_nonce"]').val()
					},
					success: function(response) {
						$btn.prop('disabled', false).text(originalBtnText);
						
						if (response.success) {
							$message.removeClass('error').addClass('success').html(response.data.message).show();
							$input.prop('disabled', true);
							$btn.text('<?php esc_html_e( 'Applied', 'hbl' ); ?>').prop('disabled', true);
							
							// Note: Removed checkout message display to avoid duplicate notifications
							
							// Update order totals if discount info is provided
							if (response.data.discount_amount && response.data.new_total) {
								// Update discount line (show it if hidden)
								var $discountRow = $('.hbl-order-discount');
								if ($discountRow.length === 0) {
									// Create discount row if it doesn't exist
									$('.hbl-order-tax').after('<div class="hbl-order-discount"><span><?php esc_html_e( 'Discount', 'hbl' ); ?></span><span class="discount-amount">-$0.00</span></div>');
									$discountRow = $('.hbl-order-discount');
								}
								$discountRow.find('.discount-amount').text('-$' + parseFloat(response.data.discount_amount).toFixed(2));
								$discountRow.show();
								
								// Update total
								$('.hbl-order-total span:last-child').text('$' + parseFloat(response.data.new_total).toFixed(2));
								
								// Update payment button
								$('#hbl-checkout-submit-btn span').text('<?php esc_html_e( 'Pay', 'hbl' ); ?> $' + parseFloat(response.data.new_total).toFixed(2));
							}
							
							// Optionally reload to update order summary
							if (response.data.reload) {
								setTimeout(function() {
									window.location.reload();
								}, 2000);
							}
						} else {
							$message.removeClass('success').addClass('error').html(response.data.message || '<?php esc_html_e( 'Invalid coupon code.', 'hbl' ); ?>').show();
						}
					},
					error: function() {
						$btn.prop('disabled', false).text(originalBtnText);
						$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'Connection error. Please try again.', 'hbl' ); ?>').show();
					}
				});
			});
			
			// Form submission - always use Stripe
			$('#hbl-checkout-form').on('submit', function(e) {
				e.preventDefault();
				
				var $btn = $('#hbl-checkout-submit-btn');
				var $message = $('#hbl-checkout-message');
				var originalBtnHtml = $btn.html();
				
				// Validate required fields
				var billingName = $('#billing_name').val();
				var billingEmail = $('#billing_email').val();
				
				if (!billingName || !billingEmail) {
					$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'Please fill in all required fields.', 'hbl' ); ?>').show();
					return;
				}

				// reCAPTCHA validation
				if (typeof grecaptcha !== 'undefined' && $('#hbl-checkout-form').find('.g-recaptcha').length) {
					if (!grecaptcha.getResponse()) {
						$('#hbl-checkout-form').find('.hbl-recaptcha-error').show();
						return;
					}
					$('#hbl-checkout-form').find('.hbl-recaptcha-error').hide();
				}
				
				// Show loading - only one spinner on the left
				$btn.prop('disabled', true).html('<span class="hbl-spinner hbl-spinner-btn"></span><span><?php esc_html_e( 'Processing...', 'hbl' ); ?></span>');
				$message.hide();
				
				// Create Stripe Checkout Session
				$.ajax({
					url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
					type: 'POST',
					data: {
						action: 'hbl_create_stripe_session',
						checkout_nonce: $('input[name="checkout_nonce"]').val(),
						listing_id: $('input[name="listing_id"]').val(),
						plan_id: $('input[name="plan_id"]').val(),
						billing_name: billingName,
						billing_email: billingEmail,
					billing_phone: $('#billing_phone').val(),
					'g-recaptcha-response': (typeof grecaptcha !== 'undefined') ? grecaptcha.getResponse() : ''
				},
					success: function(response) {
						if (response.success && response.data.checkout_url) {
							$message.removeClass('error').addClass('success').html('<?php esc_html_e( 'Redirecting to secure payment...', 'hbl' ); ?>').show();
							// Redirect to Stripe Checkout
							window.location.href = response.data.checkout_url;
						} else {
							$btn.prop('disabled', false).html(originalBtnHtml);
							if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
							$message.removeClass('success').addClass('error').html(response.data.message || '<?php esc_html_e( 'Failed to initialize payment. Please try again.', 'hbl' ); ?>').show();
						}
					},
					error: function() {
						$btn.prop('disabled', false).html(originalBtnHtml);
						if (typeof grecaptcha !== 'undefined') { grecaptcha.reset(); }
						$message.removeClass('success').addClass('error').html('<?php esc_html_e( 'Connection error. Please try again.', 'hbl' ); ?>').show();
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render login required message
	 */
	private function render_login_required( $settings ) {
		$login_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_login_page_link() : wp_login_url();
		$register_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
		?>
		<div class="hbl-checkout-widget">
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<h2 class="hbl-checkout-title"><?php echo esc_html( $settings['page_title'] ); ?></h2>
				<?php if ( ! empty( $settings['page_description'] ) ) : ?>
					<p class="hbl-checkout-description"><?php echo esc_html( $settings['page_description'] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<div class="hbl-form-login-required">
				<div class="hbl-form-login-icon">
					<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</div>
				<h3><?php esc_html_e( 'Login Required', 'hbl' ); ?></h3>
				<p><?php esc_html_e( 'Please login to complete your purchase.', 'hbl' ); ?></p>
				<div class="hbl-form-login-buttons">
					<a href="<?php echo esc_url( $login_url ); ?>" class="hbl-form-btn hbl-form-btn-primary"><?php esc_html_e( 'Login', 'hbl' ); ?></a>
					<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary"><?php esc_html_e( 'Register', 'hbl' ); ?></a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render empty cart message
	 */
	private function render_empty_cart( $settings ) {
		$dashboard_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
		?>
		<div class="hbl-checkout-widget">
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<h2 class="hbl-checkout-title"><?php echo esc_html( $settings['page_title'] ); ?></h2>
			<?php endif; ?>
			<div class="hbl-empty-cart">
				<div class="hbl-empty-cart-icon">
					<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="9" cy="21" r="1" stroke="currentColor" stroke-width="2"/>
						<circle cx="20" cy="21" r="1" stroke="currentColor" stroke-width="2"/>
						<path d="M1 1H5L7.68 14.39C7.77 14.83 8.02 15.22 8.38 15.5C8.74 15.78 9.19 15.92 9.64 15.9H19.4C19.85 15.92 20.3 15.78 20.66 15.5C21.02 15.22 21.27 14.83 21.36 14.39L23 6H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</div>
				<h3><?php esc_html_e( 'Your cart is empty', 'hbl' ); ?></h3>
				<p><?php esc_html_e( 'It looks like you haven\'t added anything to your cart yet.', 'hbl' ); ?></p>
				<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-primary">
					<?php esc_html_e( 'Browse Listings', 'hbl' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Get order data from plan and listing
	 */
	private function get_order_data( $order_id, $plan_id, $listing_id ) {
		$items = array();
		$subtotal = 0;
		
		// If plan_id is provided, get plan details from Directorist
		if ( $plan_id ) {
			$plan = get_post( $plan_id );
			if ( $plan && $plan->post_type === 'atbdp_pricing_plans' ) {
				// Use Directorist's price function if available
				$price = 0;
				if ( function_exists( 'atpp_total_price' ) ) {
					$price = floatval( atpp_total_price( $plan_id ) );
				} else {
					// Fallback to direct meta
					$price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
				}
				
				// Check if it's a free plan
				$is_free = get_post_meta( $plan_id, 'free_plan', true );
				if ( $is_free ) {
					$price = 0;
				}
				
				$items[] = array(
					'name'        => $plan->post_title,
					'description' => get_post_meta( $plan_id, 'fm_description', true ) ?: __( 'Business Listing Package', 'hbl' ),
					'price'       => $price,
				);
				$subtotal = $price;
			}
		}
		
		// Fallback sample data if no items found
		if ( empty( $items ) || $subtotal <= 0 ) {
			// Try to get price from listing's assigned plan
			if ( $listing_id ) {
				$listing_plan_id = get_post_meta( $listing_id, '_fm_plans', true );
				if ( $listing_plan_id && $listing_plan_id != $plan_id ) {
					$listing_plan = get_post( $listing_plan_id );
					if ( $listing_plan && $listing_plan->post_type === 'atbdp_pricing_plans' ) {
						$price = 0;
						if ( function_exists( 'atpp_total_price' ) ) {
							$price = floatval( atpp_total_price( $listing_plan_id ) );
						} else {
							$price = floatval( get_post_meta( $listing_plan_id, 'fm_price', true ) );
						}
						
						if ( $price > 0 ) {
							$items = array(
								array(
									'name'        => $listing_plan->post_title,
									'description' => get_post_meta( $listing_plan_id, 'fm_description', true ) ?: __( 'Business Listing Package', 'hbl' ),
									'price'       => $price,
								)
							);
							$subtotal = $price;
						}
					}
				}
			}
		}
		
		// Final fallback if still no items
		if ( empty( $items ) || $subtotal <= 0 ) {
			$items[] = array(
				'name'        => __( 'Business Listing Package', 'hbl' ),
				'description' => __( 'Premium listing package', 'hbl' ),
				'price'       => 99.00,
			);
			$subtotal = 99.00;
		}
		
		// Calculate tax (10% GST for Australia)
		$tax = $subtotal * 0.10;
		
		// Check for applied coupon discount from session
		$discount = 0;
		if ( isset( $_SESSION['hbl_coupon_discount'] ) && $_SESSION['hbl_coupon_discount'] > 0 ) {
			$coupon_discount = floatval( $_SESSION['hbl_coupon_discount'] );
			$coupon_type = isset( $_SESSION['hbl_coupon_type'] ) ? $_SESSION['hbl_coupon_type'] : 'fixed';
			
			if ( $coupon_type === 'percentage' ) {
				// Apply percentage discount to subtotal
				$discount = ( $subtotal * $coupon_discount ) / 100;
			} else {
				// Apply fixed discount
				$discount = $coupon_discount;
			}
			
			// Make sure discount doesn't exceed subtotal
			$discount = min( $discount, $subtotal );
		}
		
		$total = $subtotal + $tax - $discount;
		
		return array(
			'items'    => $items,
			'subtotal' => $subtotal,
			'tax'      => $tax,
			'discount' => $discount,
			'total'    => $total,
		);
	}
}

