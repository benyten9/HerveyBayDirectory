<?php
/**
 * HBL Payment Receipt Widget
 * 
 * Displays a beautiful payment confirmation/receipt after successful transaction.
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

class HBL_Payment_Receipt extends Widget_Base {

	public function get_name() {
		return 'hbl-payment-receipt';
	}

	public function get_title() {
		return esc_html__( 'HBL Payment Receipt', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-document-file';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'payment', 'receipt', 'success', 'confirmation', 'order', 'invoice' );
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
			'success_title',
			array(
				'label'   => esc_html__( 'Success Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Payment Successful!',
			)
		);

		$this->add_control(
			'success_message',
			array(
				'label'   => esc_html__( 'Success Message', 'hbl' ),
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'Thank you for your purchase. Your payment has been processed successfully.',
			)
		);

		$this->add_control(
			'show_confetti',
			array(
				'label'        => esc_html__( 'Show Celebration Animation', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_receipt_details',
			array(
				'label'        => esc_html__( 'Show Receipt Details', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_download_button',
			array(
				'label'        => esc_html__( 'Show Download Receipt Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_print_button',
			array(
				'label'        => esc_html__( 'Show Print Button', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
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
			'primary_button_text',
			array(
				'label'   => esc_html__( 'Primary Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Go to Dashboard',
			)
		);

		$this->add_control(
			'primary_button_url',
			array(
				'label'       => esc_html__( 'Primary Button URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/dashboard/' ),
				'default'     => array(
					'url' => '',
				),
			)
		);

		$this->add_control(
			'secondary_button_text',
			array(
				'label'   => esc_html__( 'Secondary Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'View Listing',
			)
		);

		$this->add_control(
			'secondary_button_url',
			array(
				'label'       => esc_html__( 'Secondary Button URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/all-listings/' ),
				'default'     => array(
					'url' => '',
				),
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
			'support_message',
			array(
				'label'     => esc_html__( 'Support Message', 'hbl' ),
				'type'      => Controls_Manager::TEXTAREA,
				'default'   => 'If you have any questions about your purchase, please don\'t hesitate to contact us.',
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
			'success_icon_color',
			array(
				'label'     => esc_html__( 'Success Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#10B981',
				'selectors' => array(
					'{{WRAPPER}} .hbl-receipt-success-icon' => 'color: {{VALUE}}; border-color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-receipt-title' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-receipt-message, {{WRAPPER}} .hbl-receipt-details' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .hbl-receipt-title',
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

		// Get transaction data from URL parameters
		$transaction_id = isset( $_GET['transaction_id'] ) ? sanitize_text_field( $_GET['transaction_id'] ) : '';
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( $_GET['session_id'] ) : '';
		$listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
		
		// Verify Stripe payment if session_id is present
		if ( $session_id && $order_id ) {
			// Call the global function (defined in functions.php)
			if ( function_exists( 'hbl_verify_stripe_payment' ) ) {
				$verified = hbl_verify_stripe_payment();
				if ( $verified && ! $transaction_id ) {
					// Get the payment intent as transaction ID
					$transaction_id = get_post_meta( $order_id, '_stripe_payment_intent', true );
				}
			}
		}
		
		// Get receipt data
		$receipt_data = $this->get_receipt_data( $transaction_id, $order_id );
		
		// Get the actual listing URL from the order for the View Listing button
		$actual_listing_url = '';
		
		// Try to get listing_id from URL first
		if ( $listing_id ) {
			$actual_listing_url = get_permalink( $listing_id );
		}
		
		// If not in URL, try to get from order meta
		if ( ! $actual_listing_url && $order_id ) {
			$order_listing_id = get_post_meta( $order_id, '_listing_id', true );
			if ( $order_listing_id ) {
				$actual_listing_url = get_permalink( $order_listing_id );
				// Also update listing_id for other uses
				if ( ! $listing_id ) {
					$listing_id = $order_listing_id;
				}
			}
		}
		
		// Get button URLs
		$primary_url = ! empty( $settings['primary_button_url']['url'] ) 
			? $settings['primary_button_url']['url'] 
			: ( class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' ) );
		
		// Secondary button: Use actual listing URL if available, otherwise fall back to settings or all-listings
		$secondary_url = '';
		if ( $actual_listing_url ) {
			// Dynamically use the approved listing URL
			$secondary_url = $actual_listing_url;
		} elseif ( ! empty( $settings['secondary_button_url']['url'] ) ) {
			$secondary_url = $settings['secondary_button_url']['url'];
		} else {
			$secondary_url = home_url( '/all-listings/' );
		}
		?>
		<div class="hbl-payment-receipt-widget">
			<?php if ( 'yes' === $settings['show_confetti'] ) : ?>
			<div class="hbl-receipt-confetti" id="hbl-receipt-confetti"></div>
			<?php endif; ?>
			
			<div class="hbl-receipt-container">
				<!-- Success Header -->
				<div class="hbl-receipt-header">
					<div class="hbl-receipt-success-icon">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<h1 class="hbl-receipt-title"><?php echo esc_html( $settings['success_title'] ); ?></h1>
					<p class="hbl-receipt-message"><?php echo esc_html( $settings['success_message'] ); ?></p>
				</div>

				<?php if ( 'yes' === $settings['show_receipt_details'] ) : ?>
				<!-- Receipt Details -->
				<div class="hbl-receipt-card" id="hbl-receipt-printable">
					<div class="hbl-receipt-card-header">
						<div class="hbl-receipt-logo">
							<?php
							$logo_id = get_theme_mod( 'custom_logo' );
							if ( $logo_id ) {
								echo wp_get_attachment_image( $logo_id, 'medium', false, array( 'class' => 'hbl-receipt-logo-img' ) );
							} else {
								echo '<span class="hbl-receipt-logo-text">' . esc_html( get_bloginfo( 'name' ) ) . '</span>';
							}
							?>
						</div>
						<div class="hbl-receipt-meta">
							<span class="hbl-receipt-label"><?php esc_html_e( 'Receipt', 'hbl' ); ?></span>
							<span class="hbl-receipt-number">#<?php echo esc_html( $receipt_data['receipt_number'] ); ?></span>
						</div>
					</div>

					<div class="hbl-receipt-card-body">
						<!-- Transaction Info -->
						<div class="hbl-receipt-info-grid">
							<div class="hbl-receipt-info-item">
								<span class="hbl-receipt-info-label"><?php esc_html_e( 'Transaction ID', 'hbl' ); ?></span>
								<span class="hbl-receipt-info-value"><?php echo esc_html( $receipt_data['transaction_id'] ); ?></span>
							</div>
							<div class="hbl-receipt-info-item">
								<span class="hbl-receipt-info-label"><?php esc_html_e( 'Date', 'hbl' ); ?></span>
								<span class="hbl-receipt-info-value"><?php echo esc_html( $receipt_data['date'] ); ?></span>
							</div>
							<div class="hbl-receipt-info-item">
								<span class="hbl-receipt-info-label"><?php esc_html_e( 'Payment Method', 'hbl' ); ?></span>
								<span class="hbl-receipt-info-value"><?php echo esc_html( $receipt_data['payment_method'] ); ?></span>
							</div>
							<div class="hbl-receipt-info-item">
								<span class="hbl-receipt-info-label"><?php esc_html_e( 'Status', 'hbl' ); ?></span>
								<span class="hbl-receipt-info-value hbl-receipt-status-success">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<?php esc_html_e( 'Paid', 'hbl' ); ?>
								</span>
							</div>
						</div>

						<!-- Billing Details -->
						<div class="hbl-receipt-section">
							<h4 class="hbl-receipt-section-title"><?php esc_html_e( 'Bill To', 'hbl' ); ?></h4>
							<div class="hbl-receipt-billing">
								<span class="hbl-receipt-billing-name"><?php echo esc_html( $receipt_data['billing_name'] ); ?></span>
								<span class="hbl-receipt-billing-email"><?php echo esc_html( $receipt_data['billing_email'] ); ?></span>
							</div>
						</div>

						<!-- Items -->
						<div class="hbl-receipt-section">
							<h4 class="hbl-receipt-section-title"><?php esc_html_e( 'Items', 'hbl' ); ?></h4>
							<div class="hbl-receipt-items">
								<?php foreach ( $receipt_data['items'] as $item ) : ?>
								<div class="hbl-receipt-item">
									<div class="hbl-receipt-item-info">
										<span class="hbl-receipt-item-name"><?php echo esc_html( $item['name'] ); ?></span>
										<?php if ( ! empty( $item['description'] ) ) : ?>
										<span class="hbl-receipt-item-desc"><?php echo esc_html( $item['description'] ); ?></span>
										<?php endif; ?>
									</div>
									<span class="hbl-receipt-item-price">$<?php echo esc_html( number_format( $item['price'], 2 ) ); ?></span>
								</div>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- Totals -->
						<div class="hbl-receipt-totals">
							<div class="hbl-receipt-total-row">
								<span><?php esc_html_e( 'Subtotal', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $receipt_data['subtotal'], 2 ) ); ?></span>
							</div>
							<?php if ( $receipt_data['tax'] > 0 ) : ?>
							<div class="hbl-receipt-total-row">
								<span><?php esc_html_e( 'GST (10%)', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $receipt_data['tax'], 2 ) ); ?></span>
							</div>
							<?php endif; ?>
							<?php if ( $receipt_data['discount'] > 0 ) : ?>
							<div class="hbl-receipt-total-row hbl-receipt-discount">
								<span><?php esc_html_e( 'Discount', 'hbl' ); ?></span>
								<span>-$<?php echo esc_html( number_format( $receipt_data['discount'], 2 ) ); ?></span>
							</div>
							<?php endif; ?>
							<div class="hbl-receipt-total-row hbl-receipt-grand-total">
								<span><?php esc_html_e( 'Total', 'hbl' ); ?></span>
								<span>$<?php echo esc_html( number_format( $receipt_data['total'], 2 ) ); ?></span>
							</div>
						</div>
					</div>

					<!-- Receipt Actions -->
					<div class="hbl-receipt-card-footer">
						<?php if ( 'yes' === $settings['show_print_button'] ) : ?>
						<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-icon" id="hbl-print-receipt">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M6 9V2H18V9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M6 18H4C3.46957 18 2.96086 17.7893 2.58579 17.4142C2.21071 17.0391 2 16.5304 2 16V11C2 10.4696 2.21071 9.96086 2.58579 9.58579C2.96086 9.21071 3.46957 9 4 9H20C20.5304 9 21.0391 9.21071 21.4142 9.58579C21.7893 9.96086 22 10.4696 22 11V16C22 16.5304 21.7893 17.0391 21.4142 17.4142C21.0391 17.7893 20.5304 18 20 18H18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M18 14H6V22H18V14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Print', 'hbl' ); ?>
						</button>
						<?php endif; ?>
						<?php if ( 'yes' === $settings['show_download_button'] ) : ?>
						<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-icon" id="hbl-download-receipt">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M12 15V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Download PDF', 'hbl' ); ?>
						</button>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<!-- Action Buttons -->
				<div class="hbl-receipt-actions">
					<a href="<?php echo esc_url( $primary_url ); ?>" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['primary_button_text'] ); ?></span>
					</a>
					<a href="<?php echo esc_url( $secondary_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M1 12S5 4 12 4S23 12 23 12S19 20 12 20S1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['secondary_button_text'] ); ?></span>
					</a>
				</div>

				<?php if ( 'yes' === $settings['show_support'] ) : ?>
				<!-- Support Section -->
				<div class="hbl-receipt-support">
					<div class="hbl-receipt-support-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<div class="hbl-receipt-support-content">
						<h4><?php esc_html_e( 'Need Help?', 'hbl' ); ?></h4>
						<p><?php echo esc_html( $settings['support_message'] ); ?></p>
						<a href="mailto:<?php echo esc_attr( $settings['support_email'] ); ?>" class="hbl-receipt-support-email">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['support_email'] ); ?>
						</a>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			<?php if ( 'yes' === $settings['show_confetti'] ) : ?>
			// Simple confetti animation
			function createConfetti() {
				var colors = ['#008080', '#F9532A', '#10B981', '#F59E0B', '#8B5CF6'];
				var $container = $('#hbl-receipt-confetti');
				
				for (var i = 0; i < 50; i++) {
					var $confetti = $('<div class="hbl-confetti-piece"></div>');
					$confetti.css({
						'left': Math.random() * 100 + '%',
						'background-color': colors[Math.floor(Math.random() * colors.length)],
						'animation-delay': Math.random() * 3 + 's',
						'animation-duration': (Math.random() * 2 + 2) + 's'
					});
					$container.append($confetti);
				}
				
				// Remove after animation
				setTimeout(function() {
					$container.fadeOut(1000);
				}, 4000);
			}
			createConfetti();
			<?php endif; ?>
			
			// Print receipt
			$('#hbl-print-receipt').on('click', function() {
				var printContent = document.getElementById('hbl-receipt-printable').innerHTML;
				var printWindow = window.open('', '', 'width=800,height=600');
				printWindow.document.write('<html><head><title><?php esc_html_e( 'Payment Receipt', 'hbl' ); ?></title>');
				printWindow.document.write('<style>body{font-family:system-ui,-apple-system,sans-serif;padding:40px;}.hbl-receipt-card-header{display:flex;justify-content:space-between;margin-bottom:30px;}.hbl-receipt-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:30px;}.hbl-receipt-item{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #eee;}.hbl-receipt-totals{margin-top:20px;}.hbl-receipt-total-row{display:flex;justify-content:space-between;padding:8px 0;}.hbl-receipt-grand-total{font-weight:bold;font-size:1.2em;border-top:2px solid #000;padding-top:15px;}.hbl-receipt-card-footer{display:none;}</style>');
				printWindow.document.write('</head><body>');
				printWindow.document.write(printContent);
				printWindow.document.write('</body></html>');
				printWindow.document.close();
				printWindow.print();
			});
			
			// Download as PDF (placeholder - would need a PDF library)
			$('#hbl-download-receipt').on('click', function() {
				alert('<?php esc_html_e( 'PDF download functionality will be available once payment integration is complete.', 'hbl' ); ?>');
			});
		});
		</script>
		<?php
	}

	/**
	 * Get receipt data from order
	 */
	private function get_receipt_data( $transaction_id, $order_id ) {
		// Get current user
		$current_user = wp_get_current_user();
		
		// Default values
		$receipt_data = array(
			'receipt_number'  => $order_id ? sprintf( 'HBL-%06d', $order_id ) : 'HBL-' . strtoupper( substr( md5( time() ), 0, 8 ) ),
			'transaction_id'  => $transaction_id ?: 'TXN-' . strtoupper( substr( md5( time() . wp_rand() ), 0, 12 ) ),
			'date'            => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'payment_method'  => __( 'Credit Card (Stripe)', 'hbl' ),
			'billing_name'    => $current_user->display_name ?: __( 'Guest Customer', 'hbl' ),
			'billing_email'   => $current_user->user_email ?: __( 'guest@example.com', 'hbl' ),
			'items'           => array(),
			'subtotal'        => 0,
			'tax'             => 0,
			'discount'        => 0,
			'total'           => 0,
		);
		
		// If we have an order ID, get real data
		if ( $order_id ) {
			$order = get_post( $order_id );
			if ( $order && $order->post_type === 'atbdp_orders' ) {
				// Get order meta
				$billing_name = get_post_meta( $order_id, '_billing_name', true );
				$billing_email = get_post_meta( $order_id, '_billing_email', true );
				$plan_id = get_post_meta( $order_id, '_fm_plan_ordered', true );
				$order_amount = get_post_meta( $order_id, '_order_amount', true );
				$payment_intent = get_post_meta( $order_id, '_stripe_payment_intent', true );
				$listing_id = get_post_meta( $order_id, '_listing_id', true );
				
				// Update receipt data with real values
				if ( $billing_name ) {
					$receipt_data['billing_name'] = $billing_name;
				}
				if ( $billing_email ) {
					$receipt_data['billing_email'] = $billing_email;
				}
				if ( $payment_intent ) {
					$receipt_data['transaction_id'] = $payment_intent;
				}
				
				// Get plan details
				if ( $plan_id ) {
					$plan = get_post( $plan_id );
					if ( $plan ) {
						$plan_price = 0;
						if ( function_exists( 'atpp_total_price' ) ) {
							$plan_price = atpp_total_price( $plan_id );
						} else {
							$plan_price = floatval( get_post_meta( $plan_id, 'fm_price', true ) );
						}
						
						$plan_description = get_post_meta( $plan_id, 'fm_description', true );
						
						$receipt_data['items'][] = array(
							'name'        => $plan->post_title,
							'description' => $plan_description ?: __( 'Business Listing Package', 'hbl' ),
							'price'       => $plan_price,
						);
						
						$receipt_data['subtotal'] = $plan_price;
						
						// Calculate tax if applicable
						if ( function_exists( 'atpp_total_tax' ) ) {
							$receipt_data['tax'] = atpp_total_tax( $plan_id );
						}
						
						$receipt_data['total'] = $receipt_data['subtotal'] + $receipt_data['tax'] - $receipt_data['discount'];
					}
				}
				
				// If no plan but we have order amount
				if ( empty( $receipt_data['items'] ) && $order_amount ) {
					$receipt_data['items'][] = array(
						'name'        => __( 'Business Listing', 'hbl' ),
						'description' => __( 'Listing package', 'hbl' ),
						'price'       => floatval( $order_amount ),
					);
					$receipt_data['subtotal'] = floatval( $order_amount );
					$receipt_data['total'] = floatval( $order_amount );
				}
				
				// Get order date
				$receipt_data['date'] = date_i18n( 
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
					strtotime( $order->post_date ) 
				);
			}
		}
		
		// Fallback if no items
		if ( empty( $receipt_data['items'] ) ) {
			$receipt_data['items'][] = array(
				'name'        => __( 'Business Listing', 'hbl' ),
				'description' => __( 'Listing package', 'hbl' ),
				'price'       => 0,
			);
		}
		
		return $receipt_data;
	}
}

