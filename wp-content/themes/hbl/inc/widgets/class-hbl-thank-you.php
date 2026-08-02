<?php
/**
 * HBL Thank You Widget
 * 
 * A beautiful thank you page widget for form submissions, registrations, etc.
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

class HBL_Thank_You extends Widget_Base {

	public function get_name() {
		return 'hbl-thank-you';
	}

	public function get_title() {
		return esc_html__( 'HBL Thank You', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-heart';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'thank', 'you', 'success', 'confirmation', 'complete', 'submitted' );
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
			'thank_you_title',
			array(
				'label'   => esc_html__( 'Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Thank You!',
			)
		);

		$this->add_control(
			'thank_you_message',
			array(
				'label'   => esc_html__( 'Message', 'hbl' ),
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'Your submission has been received. We appreciate you taking the time to reach out to us.',
			)
		);

		$this->add_control(
			'icon_type',
			array(
				'label'   => esc_html__( 'Icon Type', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'checkmark',
				'options' => array(
					'checkmark' => esc_html__( 'Checkmark', 'hbl' ),
					'heart'     => esc_html__( 'Heart', 'hbl' ),
					'star'      => esc_html__( 'Star', 'hbl' ),
					'thumbsup'  => esc_html__( 'Thumbs Up', 'hbl' ),
					'envelope'  => esc_html__( 'Envelope', 'hbl' ),
					'rocket'    => esc_html__( 'Rocket', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'show_animation',
			array(
				'label'        => esc_html__( 'Show Celebration Animation', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: DETAILS BOX ==========
		$this->start_controls_section(
			'section_details',
			array(
				'label' => esc_html__( 'Details Box', 'hbl' ),
			)
		);

		$this->add_control(
			'show_details_box',
			array(
				'label'        => esc_html__( 'Show Details Box', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'details_title',
			array(
				'label'     => esc_html__( 'Details Title', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'What happens next?',
				'condition' => array(
					'show_details_box' => 'yes',
				),
			)
		);

		$this->add_control(
			'detail_1',
			array(
				'label'     => esc_html__( 'Detail 1', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'We will review your submission within 24-48 hours',
				'condition' => array(
					'show_details_box' => 'yes',
				),
			)
		);

		$this->add_control(
			'detail_2',
			array(
				'label'     => esc_html__( 'Detail 2', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'You will receive an email confirmation shortly',
				'condition' => array(
					'show_details_box' => 'yes',
				),
			)
		);

		$this->add_control(
			'detail_3',
			array(
				'label'     => esc_html__( 'Detail 3', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Our team will contact you if we need additional information',
				'condition' => array(
					'show_details_box' => 'yes',
				),
			)
		);

		$this->add_control(
			'detail_4',
			array(
				'label'     => esc_html__( 'Detail 4', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '',
				'condition' => array(
					'show_details_box' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: REFERENCE INFO ==========
		$this->start_controls_section(
			'section_reference',
			array(
				'label' => esc_html__( 'Reference Information', 'hbl' ),
			)
		);

		$this->add_control(
			'show_reference',
			array(
				'label'        => esc_html__( 'Show Reference Number', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => esc_html__( 'Display reference number from URL parameter', 'hbl' ),
			)
		);

		$this->add_control(
			'reference_label',
			array(
				'label'     => esc_html__( 'Reference Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Reference Number',
				'condition' => array(
					'show_reference' => 'yes',
				),
			)
		);

		$this->add_control(
			'show_timestamp',
			array(
				'label'        => esc_html__( 'Show Timestamp', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => '',
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
				'default' => 'Back to Home',
			)
		);

		$this->add_control(
			'secondary_button_url',
			array(
				'label'       => esc_html__( 'Secondary Button URL', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => home_url( '/' ),
				'default'     => array(
					'url' => '',
				),
			)
		);

		$this->add_control(
			'show_social_share',
			array(
				'label'        => esc_html__( 'Show Social Share', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$this->add_control(
			'share_message',
			array(
				'label'     => esc_html__( 'Share Message', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Check out Hervey Bay Local!',
				'condition' => array(
					'show_social_share' => 'yes',
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
			'support_title',
			array(
				'label'     => esc_html__( 'Support Title', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Need Help?',
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
				'default'   => 'If you have any questions, our support team is here to help.',
				'condition' => array(
					'show_support' => 'yes',
				),
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
			'icon_color',
			array(
				'label'     => esc_html__( 'Icon Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#10B981',
				'selectors' => array(
					'{{WRAPPER}} .hbl-thankyou-icon' => 'color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'icon_background',
			array(
				'label'     => esc_html__( 'Icon Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '',
				'selectors' => array(
					'{{WRAPPER}} .hbl-thankyou-icon' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#1F2937',
				'selectors' => array(
					'{{WRAPPER}} .hbl-thankyou-title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'text_color',
			array(
				'label'     => esc_html__( 'Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6B7280',
				'selectors' => array(
					'{{WRAPPER}} .hbl-thankyou-message, {{WRAPPER}} .hbl-thankyou-details' => 'color: {{VALUE}};',
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
				'selector' => '{{WRAPPER}} .hbl-thankyou-title',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'message_typography',
				'label'    => esc_html__( 'Message Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-thankyou-message',
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

		// Get reference from URL parameters
		$reference = isset( $_GET['ref'] ) ? sanitize_text_field( $_GET['ref'] ) : '';
		if ( empty( $reference ) && isset( $_GET['order_id'] ) ) {
			$reference = 'HBL-' . absint( $_GET['order_id'] );
		}
		if ( empty( $reference ) && isset( $_GET['id'] ) ) {
			$reference = sanitize_text_field( $_GET['id'] );
		}
		
		// Get button URLs
		$primary_url = ! empty( $settings['primary_button_url']['url'] ) 
			? $settings['primary_button_url']['url'] 
			: ( class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' ) );
		
		$secondary_url = ! empty( $settings['secondary_button_url']['url'] ) 
			? $settings['secondary_button_url']['url'] 
			: home_url( '/' );

		// Get icon SVG
		$icon_svg = $this->get_icon_svg( $settings['icon_type'] );
		?>
		<div class="hbl-thank-you-widget">
			<?php if ( 'yes' === $settings['show_animation'] ) : ?>
			<div class="hbl-thankyou-confetti" id="hbl-thankyou-confetti"></div>
			<?php endif; ?>
			
			<div class="hbl-thankyou-container">
				<!-- Success Header -->
				<div class="hbl-thankyou-header">
					<div class="hbl-thankyou-icon">
						<?php echo $icon_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<h1 class="hbl-thankyou-title"><?php echo esc_html( $settings['thank_you_title'] ); ?></h1>
					<p class="hbl-thankyou-message"><?php echo esc_html( $settings['thank_you_message'] ); ?></p>
				</div>

				<?php if ( 'yes' === $settings['show_reference'] && $reference ) : ?>
				<!-- Reference Info -->
				<div class="hbl-thankyou-reference">
					<div class="hbl-thankyou-reference-label"><?php echo esc_html( $settings['reference_label'] ); ?></div>
					<div class="hbl-thankyou-reference-value"><?php echo esc_html( $reference ); ?></div>
					<?php if ( 'yes' === $settings['show_timestamp'] ) : ?>
					<div class="hbl-thankyou-timestamp">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M12 6V12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_details_box'] ) : ?>
				<!-- What Happens Next -->
				<div class="hbl-thankyou-details">
					<h3 class="hbl-thankyou-details-title">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
							<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
						<?php echo esc_html( $settings['details_title'] ); ?>
					</h3>
					<ul class="hbl-thankyou-details-list">
						<?php if ( ! empty( $settings['detail_1'] ) ) : ?>
						<li>
							<span class="hbl-thankyou-detail-number">1</span>
							<span class="hbl-thankyou-detail-text"><?php echo esc_html( $settings['detail_1'] ); ?></span>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['detail_2'] ) ) : ?>
						<li>
							<span class="hbl-thankyou-detail-number">2</span>
							<span class="hbl-thankyou-detail-text"><?php echo esc_html( $settings['detail_2'] ); ?></span>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['detail_3'] ) ) : ?>
						<li>
							<span class="hbl-thankyou-detail-number">3</span>
							<span class="hbl-thankyou-detail-text"><?php echo esc_html( $settings['detail_3'] ); ?></span>
						</li>
						<?php endif; ?>
						<?php if ( ! empty( $settings['detail_4'] ) ) : ?>
						<li>
							<span class="hbl-thankyou-detail-number">4</span>
							<span class="hbl-thankyou-detail-text"><?php echo esc_html( $settings['detail_4'] ); ?></span>
						</li>
						<?php endif; ?>
					</ul>
				</div>
				<?php endif; ?>

				<!-- Action Buttons -->
				<div class="hbl-thankyou-actions">
					<?php if ( ! empty( $settings['primary_button_text'] ) ) : ?>
					<a href="<?php echo esc_url( $primary_url ); ?>" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['primary_button_text'] ); ?></span>
					</a>
					<?php endif; ?>
					<?php if ( ! empty( $settings['secondary_button_text'] ) ) : ?>
					<a href="<?php echo esc_url( $secondary_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M3 9L12 2L21 9V20C21 20.5304 20.7893 21.0391 20.4142 21.4142C20.0391 21.7893 19.5304 22 19 22H5C4.46957 22 3.96086 21.7893 3.58579 21.4142C3.21071 21.0391 3 20.5304 3 20V9Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M9 22V12H15V22" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo esc_html( $settings['secondary_button_text'] ); ?></span>
					</a>
					<?php endif; ?>
				</div>

				<?php if ( 'yes' === $settings['show_social_share'] ) : ?>
				<!-- Social Share -->
				<div class="hbl-thankyou-share">
					<span class="hbl-thankyou-share-label"><?php esc_html_e( 'Share with friends:', 'hbl' ); ?></span>
					<div class="hbl-thankyou-share-buttons">
						<a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode( home_url() ); ?>&quote=<?php echo urlencode( $settings['share_message'] ); ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-facebook" title="<?php esc_attr_e( 'Share on Facebook', 'hbl' ); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M18 2H15C13.6739 2 12.4021 2.52678 11.4645 3.46447C10.5268 4.40215 10 5.67392 10 7V10H7V14H10V22H14V14H17L18 10H14V7C14 6.73478 14.1054 6.48043 14.2929 6.29289C14.4804 6.10536 14.7348 6 15 6H18V2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</a>
						<a href="https://twitter.com/intent/tweet?text=<?php echo urlencode( $settings['share_message'] ); ?>&url=<?php echo urlencode( home_url() ); ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-twitter" title="<?php esc_attr_e( 'Share on Twitter', 'hbl' ); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M4 4L10.5 12.5M20 20L13.5 11.5M10.5 12.5L4 20H8L13.5 11.5M10.5 12.5L16 4H20L13.5 11.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</a>
						<a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode( home_url() ); ?>&title=<?php echo urlencode( $settings['share_message'] ); ?>" target="_blank" rel="noopener" class="hbl-share-btn hbl-share-linkedin" title="<?php esc_attr_e( 'Share on LinkedIn', 'hbl' ); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M16 8C17.5913 8 19.1174 8.63214 20.2426 9.75736C21.3679 10.8826 22 12.4087 22 14V21H18V14C18 13.4696 17.7893 12.9609 17.4142 12.5858C17.0391 12.2107 16.5304 12 16 12C15.4696 12 14.9609 12.2107 14.5858 12.5858C14.2107 12.9609 14 13.4696 14 14V21H10V14C10 12.4087 10.6321 10.8826 11.7574 9.75736C12.8826 8.63214 14.4087 8 16 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<rect x="2" y="9" width="4" height="12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="4" cy="4" r="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</a>
						<button type="button" class="hbl-share-btn hbl-share-copy" id="hbl-copy-link" title="<?php esc_attr_e( 'Copy Link', 'hbl' ); ?>" data-url="<?php echo esc_url( home_url() ); ?>">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="9" y="9" width="13" height="13" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
								<path d="M5 15H4C3.46957 15 2.96086 14.7893 2.58579 14.4142C2.21071 14.0391 2 13.5304 2 13V4C2 3.46957 2.21071 2.96086 2.58579 2.58579C2.96086 2.21071 3.46957 2 4 2H13C13.5304 2 14.0391 2.21071 14.4142 2.58579C14.7893 2.96086 15 3.46957 15 4V5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					</div>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['show_support'] ) : ?>
				<!-- Support Section -->
				<div class="hbl-thankyou-support">
					<div class="hbl-thankyou-support-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M21 11.5C21.0034 12.8199 20.6951 14.1219 20.1 15.3C19.3944 16.7118 18.3098 17.8992 16.9674 18.7293C15.6251 19.5594 14.0782 19.9994 12.5 20C11.1801 20.0035 9.87812 19.6951 8.7 19.1L3 21L4.9 15.3C4.30493 14.1219 3.99656 12.8199 4 11.5C4.00061 9.92179 4.44061 8.37488 5.27072 7.03258C6.10083 5.69028 7.28825 4.6056 8.7 3.90003C9.87812 3.30496 11.1801 2.99659 12.5 3.00003H13C15.0843 3.11502 17.053 3.99479 18.5291 5.47089C20.0052 6.94699 20.885 8.91568 21 11V11.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
					<div class="hbl-thankyou-support-content">
						<h4><?php echo esc_html( $settings['support_title'] ); ?></h4>
						<p><?php echo esc_html( $settings['support_message'] ); ?></p>
						<?php if ( ! empty( $settings['support_email'] ) ) : ?>
						<a href="mailto:<?php echo esc_attr( $settings['support_email'] ); ?>" class="hbl-thankyou-support-email">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php echo esc_html( $settings['support_email'] ); ?>
						</a>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			<?php if ( 'yes' === $settings['show_animation'] ) : ?>
			// Confetti animation
			function createConfetti() {
				var colors = ['#008080', '#F9532A', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899'];
				var $container = $('#hbl-thankyou-confetti');
				
				for (var i = 0; i < 60; i++) {
					var $confetti = $('<div class="hbl-confetti-piece"></div>');
					var size = Math.random() * 8 + 6;
					$confetti.css({
						'left': Math.random() * 100 + '%',
						'width': size + 'px',
						'height': size + 'px',
						'background-color': colors[Math.floor(Math.random() * colors.length)],
						'animation-delay': Math.random() * 2 + 's',
						'animation-duration': (Math.random() * 2 + 3) + 's'
					});
					$container.append($confetti);
				}
				
				setTimeout(function() {
					$container.fadeOut(1000);
				}, 4000);
			}
			createConfetti();
			<?php endif; ?>
			
			// Copy link button
			$('#hbl-copy-link').on('click', function() {
				var url = $(this).data('url');
				var $btn = $(this);
				
				if (navigator.clipboard) {
					navigator.clipboard.writeText(url).then(function() {
						$btn.addClass('copied');
						setTimeout(function() {
							$btn.removeClass('copied');
						}, 2000);
					});
				} else {
					// Fallback
					var $temp = $('<input>');
					$('body').append($temp);
					$temp.val(url).select();
					document.execCommand('copy');
					$temp.remove();
					$btn.addClass('copied');
					setTimeout(function() {
						$btn.removeClass('copied');
					}, 2000);
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Get icon SVG based on type
	 */
	private function get_icon_svg( $type ) {
		$icons = array(
			'checkmark' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			'heart' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M20.84 4.61C20.3292 4.099 19.7228 3.69365 19.0554 3.41708C18.3879 3.14052 17.6725 2.99817 16.95 2.99817C16.2275 2.99817 15.5121 3.14052 14.8446 3.41708C14.1772 3.69365 13.5708 4.099 13.06 4.61L12 5.67L10.94 4.61C9.9083 3.57831 8.50903 2.99871 7.05 2.99871C5.59096 2.99871 4.19169 3.57831 3.16 4.61C2.1283 5.64169 1.54871 7.04097 1.54871 8.5C1.54871 9.95903 2.1283 11.3583 3.16 12.39L4.22 13.45L12 21.23L19.78 13.45L20.84 12.39C21.351 11.8792 21.7563 11.2728 22.0329 10.6054C22.3095 9.93789 22.4518 9.22249 22.4518 8.5C22.4518 7.77751 22.3095 7.0621 22.0329 6.39464C21.7563 5.72718 21.351 5.12075 20.84 4.61Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			'star' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			'thumbsup' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M14 9V5C14 4.20435 13.6839 3.44129 13.1213 2.87868C12.5587 2.31607 11.7956 2 11 2L7 10V22H18.28C18.7623 22.0055 19.2304 21.8364 19.5979 21.524C19.9654 21.2116 20.2077 20.7769 20.28 20.3L21.66 11.3C21.7035 11.0134 21.6842 10.7207 21.6033 10.4423C21.5225 10.1638 21.3821 9.90629 21.1919 9.68751C21.0016 9.46873 20.7661 9.29393 20.5016 9.17522C20.2371 9.0565 19.9499 8.99672 19.66 9H14Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M7 22H4C3.46957 22 2.96086 21.7893 2.58579 21.4142C2.21071 21.0391 2 20.5304 2 20V12C2 11.4696 2.21071 10.9609 2.58579 10.5858C2.96086 10.2107 3.46957 10 4 10H7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			'envelope' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>',
			'rocket' => '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path d="M4.5 16.5C3 18 3 21 3 21C3 21 6 21 7.5 19.5C8.32843 18.6716 8.32843 17.3284 7.5 16.5C6.67157 15.6716 5.32843 15.6716 4.5 16.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M14.5 4C14.5 4 18 6 19 10C20 14 15 19 15 19L10.5 14.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M4 10C4 10 6 6 10 5C14 4 19 9 19 9L14.5 13.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<path d="M9 15L5 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
				<circle cx="15" cy="9" r="1" fill="currentColor"/>
			</svg>',
		);

		return isset( $icons[ $type ] ) ? $icons[ $type ] : $icons['checkmark'];
	}
}

