<?php
/**
 * HBL Transaction Failure Widget
 * 
 * Displays a user-friendly error message when a payment transaction fails.
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

class HBL_Transaction_Failure extends Widget_Base {

	public function get_name() {
		return 'hbl-transaction-failure';
	}

	public function get_title() {
		return esc_html__( 'HBL Transaction Failure', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-warning';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'payment', 'failure', 'error', 'transaction', 'failed', 'declined' );
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
			'failure_title',
			array(
				'label'   => esc_html__( 'Failure Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Payment Failed',
			)
		);

		$this->add_control(
			'failure_message',
			array(
				'label'   => esc_html__( 'Failure Message', 'hbl' ),
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'We were unable to process your payment. Please check your payment details and try again.',
			)
		);

		$this->add_control(
			'show_error_code',
			array(
				'label'        => esc_html__( 'Show Error Code', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => esc_html__( 'Display error code from URL parameter', 'hbl' ),
			)
		);

		$this->add_control(
			'show_troubleshooting',
			array(
				'label'        => esc_html__( 'Show Troubleshooting Tips', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: TROUBLESHOOTING ==========
		$this->start_controls_section(
			'section_troubleshooting',
			array(
				'label'     => esc_html__( 'Troubleshooting Tips', 'hbl' ),
				'condition' => array(
					'show_troubleshooting' => 'yes',
				),
			)
		);

		$this->add_control(
			'tip_1',
			array(
				'label'   => esc_html__( 'Tip 1', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Check that your card number, expiry date, and CVC are correct',
			)
		);

		$this->add_control(
			'tip_2',
			array(
				'label'   => esc_html__( 'Tip 2', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Ensure you have sufficient funds in your account',
			)
		);

		$this->add_control(
			'tip_3',
			array(
				'label'   => esc_html__( 'Tip 3', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Try using a different payment method',
			)
		);

		$this->add_control(
			'tip_4',
			array(
				'label'   => esc_html__( 'Tip 4', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Contact your bank if the problem persists',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: BUTTONS ==========
		$this->start_controls_section(
			'section_buttons',
			array(
				'label' => esc_html__( 'Action Buttons', 'hbl' ),
			)
		);

		$this->add_control(
			'retry_button_text',
			array(
				'label'   => esc_html__( 'Retry Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Try Again',
			)
		);

		$this->add_control(
			'retry_button_url',
			array(
				'label'       => esc_html__( 'Retry Button URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/checkout/' ),
				'default'     => array(
					'url' => '',
				),
				'description' => esc_html__( 'Leave empty to go back to checkout', 'hbl' ),
			)
		);

		$this->add_control(
			'alternative_button_text',
			array(
				'label'   => esc_html__( 'Alternative Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Contact Support',
			)
		);

		$this->add_control(
			'alternative_button_url',
			array(
				'label'       => esc_html__( 'Alternative Button URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/contact/' ),
				'default'     => array(
					'url' => '',
				),
			)
		);

		$this->add_control(
			'show_dashboard_link',
			array(
				'label'        => esc_html__( 'Show Dashboard Link', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: SUPPORT ==========
		$this->start_controls_section(
			'section_support',
			array(
				'label' => esc_html__( 'Support Information', 'hbl' ),
			)
		);

		$this->add_control(
			'show_support',
			array(
				'label'        => esc_html__( 'Show Support Section', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'support_email',
			array(
				'label'     => esc_html__( 'Support Email', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'support@herveyBaylocal.com.au',
				'condition' => array(
					'show_support' => 'yes',
				),
			)
		);

		$this->add_control(
			'support_phone',
			array(
				'label'     => esc_html__( 'Support Phone', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array(
					'show_support' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: COLORS ==========
		$this->start_controls_section(
			'section_style_colors',
			array(
				'label' => esc_html__( 'Colors', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'error_icon_color',
			array(
				'label'     => esc_html__( 'Error Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#EF4444',
				'selectors' => array(
					'{{WRAPPER}} .hbl-failure-icon' => 'color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-failure-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6C757D',
				'selectors' => array(
					'{{WRAPPER}} .hbl-failure-message, {{WRAPPER}} .hbl-failure-tips' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .hbl-failure-title',
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

		$this->end_controls_section();

		// ========== STYLE: BUTTONS ==========
		$this->start_controls_section(
			'section_style_buttons',
			array(
				'label' => esc_html__( 'Buttons', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
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

		// Get error information from URL parameters
		$error_code = isset( $_GET['error_code'] ) ? sanitize_text_field( $_GET['error_code'] ) : '';
		$error_message = isset( $_GET['error_message'] ) ? sanitize_text_field( urldecode( $_GET['error_message'] ) ) : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
		$plan_id = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
		$cancelled = isset( $_GET['cancelled'] ) ? sanitize_text_field( $_GET['cancelled'] ) : '';
		
		// Get friendly error message
		$friendly_error = $this->get_friendly_error_message( $error_code );
		
		// If this is a cancelled payment from Stripe, set appropriate message
		if ( $cancelled === '1' && empty( $error_code ) ) {
			$friendly_error = __( 'You cancelled the payment. Please try again if you wish to complete your purchase.', 'hbl' );
		}
		
		// Get button URLs - construct checkout URL with all necessary params
		$retry_url = ! empty( $settings['retry_button_url']['url'] ) 
			? $settings['retry_button_url']['url'] 
			: home_url( '/checkout/' );
		
		// Add all relevant parameters to retry URL
		$retry_params = array();
		if ( $order_id ) {
			$retry_params['order_id'] = $order_id;
		}
		if ( $listing_id ) {
			$retry_params['listing_id'] = $listing_id;
		}
		if ( $plan_id ) {
			$retry_params['plan_id'] = $plan_id;
		}
		if ( ! empty( $retry_params ) ) {
			$retry_url = add_query_arg( $retry_params, $retry_url );
		}
		
		$alternative_url = ! empty( $settings['alternative_button_url']['url'] ) 
			? $settings['alternative_button_url']['url'] 
			: home_url( '/contact/' );
		
		$dashboard_url = class_exists( 'ATBDP_Permalink' ) 
			? \ATBDP_Permalink::get_dashboard_page_link() 
			: home_url( '/dashboard/' );
		?>
		<div class="hbl-transaction-failure-widget">
			<div class="hbl-failure-container">
				<!-- Error Header -->
				<div class="hbl-failure-header">
					<div class="hbl-failure-icon">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<h1 class="hbl-failure-title"><?php echo esc_html( $settings['failure_title'] ); ?></h1>
					<p class="hbl-failure-message"><?php echo esc_html( $settings['failure_message'] ); ?></p>
				</div>

				<?php if ( 'yes' === $settings['show_error_code'] && ( $error_code || $friendly_error ) ) : ?>
				<!-- Error Details -->
				<div class="hbl-failure-error-box">
					<div class="hbl-failure-error-header">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M10.29 3.86L1.82 18C1.64 18.3 1.54 18.64 1.54 19C1.54 19.36 1.65 19.7 1.85 20C2.05 20.3 2.33 20.53 2.67 20.67C3 20.82 3.37 20.87 3.73 20.82L12 20.82L20.27 20.82C20.63 20.87 21 20.82 21.33 20.67C21.67 20.53 21.95 20.3 22.15 20C22.35 19.7 22.46 19.36 22.46 19C22.46 18.64 22.36 18.3 22.18 18L13.71 3.86C13.53 3.56 13.27 3.32 12.95 3.16C12.64 3 12.29 2.91 11.93 2.91C11.57 2.91 11.22 3 10.91 3.16C10.59 3.32 10.33 3.56 10.15 3.86H10.29Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M12 9V13M12 17H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<span><?php esc_html_e( 'Error Details', 'hbl' ); ?></span>
					</div>
					<div class="hbl-failure-error-content">
						<?php if ( $friendly_error ) : ?>
						<p class="hbl-failure-error-message"><?php echo esc_html( $friendly_error ); ?></p>
						<?php endif; ?>
						<?php if ( $error_code ) : ?>
						<p class="hbl-failure-error-code">
							<?php esc_html_e( 'Error Code:', 'hbl' ); ?> 
							<code><?php echo esc_html( $error_code ); ?></code>
						</p>
						<?php endif; ?>
						<?php if ( $order_id ) : ?>
						<p class="hbl-failure-order-id">
							<?php esc_html_e( 'Order ID:', 'hbl' ); ?> 
							<code>#<?php echo esc_html( $order_id ); ?></code>
						</p>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_troubleshooting'] ) : ?>
				<!-- Troubleshooting Tips -->
				<div class="hbl-failure-tips">
					<h3 class="hbl-failure-tips-title">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M9.09 9C9.3251 8.33167 9.78915 7.76811 10.4 7.40913C11.0108 7.05016 11.7289 6.91894 12.4272 7.03871C13.1255 7.15849 13.7588 7.52152 14.2151 8.06353C14.6713 8.60553 14.9211 9.29152 14.92 10C14.92 12 11.92 13 11.92 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M12 17H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php esc_html_e( 'What you can try:', 'hbl' ); ?>
					</h3>
					<ul class="hbl-failure-tips-list">
						<?php if ( ! empty( $settings['tip_1'] ) ) : ?>
						<li>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['tip_1'] ); ?>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['tip_2'] ) ) : ?>
						<li>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['tip_2'] ); ?>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['tip_3'] ) ) : ?>
						<li>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['tip_3'] ); ?>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['tip_4'] ) ) : ?>
						<li>
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M20 6L9 17L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['tip_4'] ); ?>
						</li>
						<?php endif; ?>
					</ul>
				</div>
				<?php endif; ?>

				<!-- Action Buttons -->
				<div class="hbl-failure-actions">
					<a href="<?php echo esc_url( $retry_url ); ?>" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M23 4V10H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M1 20V14H7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M3.51 9C4.01717 7.56678 4.87913 6.2854 6.01547 5.27542C7.1518 4.26543 8.52547 3.55976 10.0083 3.22426C11.4911 2.88875 13.0348 2.93434 14.4952 3.35677C15.9556 3.77921 17.2853 4.56471 18.36 5.64L23 10M1 14L5.64 18.36C6.71475 19.4353 8.04437 20.2208 9.50481 20.6432C10.9652 21.0657 12.5089 21.1112 13.9917 20.7757C15.4745 20.4402 16.8482 19.7346 17.9845 18.7246C19.1209 17.7146 19.9828 16.4332 20.49 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['retry_button_text'] ); ?></span>
					</a>
					<a href="<?php echo esc_url( $alternative_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['alternative_button_text'] ); ?></span>
					</a>
				</div>

				<?php if ( 'yes' === $settings['show_dashboard_link'] ) : ?>
				<!-- Dashboard Link -->
				<div class="hbl-failure-dashboard-link">
					<a href="<?php echo esc_url( $dashboard_url ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<?php esc_html_e( 'Return to Dashboard', 'hbl' ); ?>
					</a>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_support'] ) : ?>
				<!-- Support Section -->
				<div class="hbl-failure-support">
					<div class="hbl-failure-support-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
					</div>
					<div class="hbl-failure-support-content">
						<h4><?php esc_html_e( 'Still having issues?', 'hbl' ); ?></h4>
						<p><?php esc_html_e( 'Our support team is here to help you complete your purchase.', 'hbl' ); ?></p>
						<div class="hbl-failure-support-contacts">
							<?php if ( ! empty( $settings['support_email'] ) ) : ?>
							<a href="mailto:<?php echo esc_attr( $settings['support_email'] ); ?>" class="hbl-failure-support-link">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php echo esc_html( $settings['support_email'] ); ?>
							</a>
							<?php endif; ?>
							<?php if ( ! empty( $settings['support_phone'] ) ) : ?>
							<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $settings['support_phone'] ) ); ?>" class="hbl-failure-support-link">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 16.92V19.92C22.0011 20.1985 21.9441 20.4742 21.8325 20.7294C21.7209 20.9845 21.5573 21.2136 21.3521 21.4019C21.1468 21.5902 20.9046 21.7335 20.6407 21.8227C20.3769 21.9119 20.0974 21.9451 19.82 21.92C16.7428 21.5856 13.787 20.5342 11.19 18.85C8.77382 17.3147 6.72533 15.2662 5.18999 12.85C3.49997 10.2412 2.44824 7.27099 2.11999 4.18001C2.095 3.90347 2.12787 3.62477 2.21649 3.36163C2.30512 3.09849 2.44756 2.85669 2.63476 2.65163C2.82196 2.44656 3.0498 2.28271 3.30379 2.17053C3.55777 2.05834 3.83233 2.00027 4.10999 2.00001H7.10999C7.5953 1.99523 8.06579 2.16708 8.43376 2.48354C8.80173 2.79999 9.04207 3.23945 9.10999 3.72001C9.23662 4.68007 9.47144 5.62273 9.80999 6.53001C9.94454 6.88793 9.97366 7.27692 9.8939 7.65089C9.81415 8.02485 9.62886 8.36812 9.35999 8.64001L8.08999 9.91001C9.51355 12.4136 11.5864 14.4865 14.09 15.91L15.36 14.64C15.6319 14.3711 15.9751 14.1859 16.3491 14.1061C16.7231 14.0263 17.1121 14.0555 17.47 14.19C18.3773 14.5286 19.3199 14.7634 20.28 14.89C20.7658 14.9585 21.2094 15.2032 21.5265 15.5775C21.8437 15.9518 22.0122 16.4296 22 16.92Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<?php echo esc_html( $settings['support_phone'] ); ?>
							</a>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get a user-friendly error message based on error code
	 */
	private function get_friendly_error_message( $error_code ) {
		$error_messages = array(
			// Stripe error codes
			'card_declined'                => __( 'Your card was declined. Please try a different card or contact your bank.', 'hbl' ),
			'insufficient_funds'           => __( 'Your card has insufficient funds. Please try a different card.', 'hbl' ),
			'expired_card'                 => __( 'Your card has expired. Please use a different card.', 'hbl' ),
			'incorrect_cvc'                => __( 'The CVC code is incorrect. Please check and try again.', 'hbl' ),
			'incorrect_number'             => __( 'The card number is incorrect. Please check and try again.', 'hbl' ),
			'processing_error'             => __( 'An error occurred while processing your card. Please try again.', 'hbl' ),
			'rate_limit'                   => __( 'Too many requests. Please wait a moment and try again.', 'hbl' ),
			
			// PayPal error codes
			'paypal_declined'              => __( 'Your PayPal payment was declined. Please try again or use a different payment method.', 'hbl' ),
			'paypal_cancelled'             => __( 'You cancelled the PayPal payment. Please try again if you wish to complete your purchase.', 'hbl' ),
			
			// General errors
			'invalid_request'              => __( 'The payment request was invalid. Please try again.', 'hbl' ),
			'authentication_required'      => __( 'Additional authentication is required. Please complete the verification.', 'hbl' ),
			'timeout'                      => __( 'The payment request timed out. Please try again.', 'hbl' ),
			'network_error'                => __( 'A network error occurred. Please check your connection and try again.', 'hbl' ),
		);
		
		return isset( $error_messages[ $error_code ] ) ? $error_messages[ $error_code ] : '';
	}
}

