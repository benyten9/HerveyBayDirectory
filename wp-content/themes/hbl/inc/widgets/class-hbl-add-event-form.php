<?php

namespace HBL\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HBL_Add_Event_Form extends Widget_Base {

	private static $event_type_tags = array(
		'community'     => 'event-type-community',
		'workshop'      => 'event-type-workshop',
		'market'        => 'event-type-market',
		'business'      => 'event-type-business',
		'entertainment' => 'event-type-entertainment',
		'other'         => 'event-type-other',
	);

	private static $cost_tags = array(
		'free' => 'event-free',
		'paid' => 'event-paid',
	);

	private static $frequency_tags = array(
		'once'      => 'event-once',
		'weekly'    => 'event-weekly',
		'monthly'   => 'event-monthly',
		'recurring' => 'event-recurring',
	);

	private static $organiser_tags = array(
		'individual' => 'organiser-individual',
		'community'  => 'organiser-community',
		'business'   => 'organiser-business',
	);

	public function get_name() {
		return 'hbl-add-event-form';
	}

	public function get_title() {
		return esc_html__( 'HBL Add Event Form', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'add', 'event', 'form', 'calendar', 'piecal', 'create', 'submit' );
	}

	protected function register_controls() {

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
				'default' => 'Submit Your Event',
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
				'default'   => 'Submit an event to help locals and visitors discover what’s happening across the Fraser Coast.',
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
				'description'  => esc_html__( 'Show login prompt if user is not logged in', 'hbl' ),
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
			'transparency_note',
			array(
				'label'       => esc_html__( 'Transparency Note', 'hbl' ),
				'type'        => Controls_Manager::TEXTAREA,
				'default'     => 'Event listings on Hervey Bay Local are currently free. In future, optional promotion features may be available for organisers who want extra visibility.',
				'description' => esc_html__( 'Shown at the bottom of the form', 'hbl' ),
			)
		);

		$this->end_controls_section();

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
				'selector' => '{{WRAPPER}} .hbl-add-event-form-title',
			)
		);

		$this->add_control(
			'title_color',
			array(
				'label'     => esc_html__( 'Title Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#000000',
				'selectors' => array(
					'{{WRAPPER}} .hbl-add-event-form-title' => 'color: {{VALUE}};',
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
					'{{WRAPPER}} .hbl-add-event-form-description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

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

	public function get_script_depends() {
		return array();
	}

	public function get_style_depends() {
		return array();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			wp_enqueue_script( 'google-recaptcha' );
		}
		$editing_event_id = 0;
		$event_data = array();
		
		$editing_event_id = absint( get_query_var( 'hbl_edit_event', 0 ) );
		
		if ( ! $editing_event_id ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			if ( preg_match( '/\/edit\/(\d+)\/?/', $request_uri, $matches ) ) {
				$editing_event_id = absint( $matches[1] );
			}
		}
		if ( ! $editing_event_id && isset( $_GET['edit_event'] ) ) {
			$editing_event_id = absint( $_GET['edit_event'] );
		}
		if ( ! $editing_event_id && isset( $_GET['event_id'] ) ) {
			$editing_event_id = absint( $_GET['event_id'] );
		}
		
		if ( $editing_event_id && function_exists( 'hbl_events_db' ) ) {
			$db = hbl_events_db();
			$event = $db->get( $editing_event_id );
			$current_user_id = get_current_user_id();
			
			if ( $event && ( (int) $event->user_id === $current_user_id || current_user_can( 'edit_others_posts' ) ) ) {
					
				$start_date_formatted = $event->start_date ? date( 'Y-m-d\TH:i', strtotime( $event->start_date ) ) : '';
				$end_date_formatted = $event->end_date ? date( 'Y-m-d\TH:i', strtotime( $event->end_date ) ) : '';
					
				$event_data = array(
					'id'                  => $event->id,
					'title'               => wp_unslash( $event->title ),
					'content'             => wp_unslash( $event->description ),
					'category'            => $event->category_id,
				'tags'                => ! empty( $event->tags ) ? array_filter( array_map( 'absint', explode( ',', $event->tags ) ) ) : array(),
					'start_date'          => $start_date_formatted,
					'end_date'            => $end_date_formatted,
					'is_allday'           => $event->is_allday,
					'color'               => $event->event_color ?: '#008080',
					'thumbnail_id'        => $event->featured_image,
					'venue'               => wp_unslash( $event->venue ),
					'address'             => wp_unslash( $event->address ),
					'event_url'           => $event->event_url,
					'contact_email'       => $event->contact_email,
					'event_type'          => $event->event_type,
					'event_cost'          => $event->event_cost,
					'scheduling_type'     => isset($event->scheduling_type) ? $event->scheduling_type : 'single',
					'daily_start_time'    => isset($event->daily_start_time) ? substr($event->daily_start_time, 0, 5) : '',
					'daily_end_time'      => isset($event->daily_end_time)   ? substr($event->daily_end_time, 0, 5)   : '',
					'event_frequency'     => $event->event_frequency,
					'recurrence_type'     => $event->recurrence_type,
					'recurrence_interval' => $event->recurrence_interval,
					'recurrence_days'     => $event->recurrence_days,
					'recurrence_week'     => $event->recurrence_week,
					'is_program'          => $event->is_program ? 'yes' : '',
					'organiser_type'      => $event->organiser_type,
				);
			} else {
				$editing_event_id = 0;
			}
		}
		
		$is_editing = ! empty( $event_data );
		
		$dashboard_base = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_dashboard_page_link() : home_url( '/dashboard/' );
		$dashboard_url = add_query_arg( 'tab', 'events', $dashboard_base );

		if ( 'yes' === $settings['require_login'] && ! is_user_logged_in() ) {
			$login_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_login_page_link() : wp_login_url();
			$register_url = class_exists( 'ATBDP_Permalink' ) ? \ATBDP_Permalink::get_registration_page_link() : wp_registration_url();
			?>
			<div class="hbl-add-event-form-widget">
				<?php if ( 'yes' === $settings['show_title'] ) : ?>
					<h2 class="hbl-add-event-form-title"><?php echo esc_html( $settings['form_title'] ); ?></h2>
					<?php if ( ! empty( $settings['form_description'] ) ) : ?>
						<p class="hbl-add-event-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
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
					<p><?php esc_html_e( 'Please login to submit an event.', 'hbl' ); ?></p>
					<div class="hbl-form-login-buttons">
						<a href="<?php echo esc_url( $login_url ); ?>" class="hbl-form-btn hbl-form-btn-primary"><?php esc_html_e( 'Login', 'hbl' ); ?></a>
						<a href="<?php echo esc_url( $register_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary"><?php esc_html_e( 'Register', 'hbl' ); ?></a>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$event_categories = get_terms( array(
			'taxonomy'   => 'event_category',
			'hide_empty' => false,
		) );

		$event_tags = get_terms( array(
			'taxonomy'   => 'event_tag',
			'hide_empty' => false,
		) );

		$user_id = get_current_user_id();
		$user = get_userdata( $user_id );
		$user_email = $user ? $user->user_email : '';
		?>
		<div class="hbl-add-event-form-widget">
			<?php if ( 'yes' === $settings['show_title'] ) : ?>
				<div class="hbl-event-form-header">
					<div class="hbl-event-form-header-top">
						<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-back">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<?php esc_html_e( 'Back to Events', 'hbl' ); ?>
						</a>
					</div>
					<h2 class="hbl-add-event-form-title">
						<?php 
						if ( $is_editing ) {
							esc_html_e( 'Edit Event', 'hbl' );
						} else {
							echo esc_html( $settings['form_title'] );
						}
						?>
					</h2>
					<?php if ( ! empty( $settings['form_description'] ) && ! $is_editing ) : ?>
						<p class="hbl-add-event-form-description"><?php echo esc_html( $settings['form_description'] ); ?></p>
					<?php elseif ( $is_editing ) : ?>
						<p class="hbl-add-event-form-description"><?php esc_html_e( 'Update your event information below.', 'hbl' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form id="hbl-event-form" class="hbl-event-form" method="post">
				<div class="hbl-form-disclaimer alert alert-info">
					<p><strong>Disclaimer:</strong> Sometimes details change due to weather, holidays, or other circumstances. We recommend you check the event’s official website or social media for the latest details before attending.</p>
				</div>
				<?php wp_nonce_field( 'hbl_nonce', 'nonce' ); ?>
				<input type="hidden" name="action" value="hbl_save_event">
				<input type="hidden" name="is_event" value="1">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $editing_event_id ); ?>">

				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Event Basics', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'The essential details about your event', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="event_title" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M11 4H4C3.46957 4 2.96086 4.21071 2.58579 4.58579C2.21071 4.96086 2 5.46957 2 6V20C2 20.5304 2.21071 21.0391 2.58579 21.4142C2.96086 21.7893 3.46957 22 4 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M18.5 2.50023C18.8978 2.1024 19.4374 1.87891 20 1.87891C20.5626 1.87891 21.1022 2.1024 21.5 2.50023C21.8978 2.89805 22.1213 3.43762 22.1213 4.00023C22.1213 4.56284 21.8978 5.1024 21.5 5.50023L12 15.0002L8 16.0002L9 12.0002L18.5 2.50023Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Event Title', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="event_title" name="event_title" class="hbl-form-input" placeholder="<?php esc_attr_e( 'What is your event called?', 'hbl' ); ?>" value="<?php echo esc_attr( $event_data['title'] ?? '' ); ?>" required>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Use the public name of the event and the day (if relevant). Eg Torquay Beachside Markets (Saturday) … or … Howard Country Markets (First Sunday)', 'hbl' ); ?></p>
						</div>

						<div class="hbl-form-group">
							<label for="event_content" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M14 2V8H20" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M16 13H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M16 17H8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Event Description', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<textarea id="event_content" name="event_content" class="hbl-form-textarea" rows="4" placeholder="<?php esc_attr_e( 'Tell people what your event is about...', 'hbl' ); ?>" required><?php echo esc_textarea( $event_data['content'] ?? '' ); ?></textarea>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Describe what the event is, who it’s for, and what visitors can expect (in up to 120 words).', 'hbl' ); ?></p>
						</div>
						
						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
									<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2"/>
								</svg>
								<span><?php esc_html_e( 'Event Type', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-radio-group hbl-scheduling-type-selector">
								<label class="hbl-form-radio-option hbl-form-radio-option-stacked">
									<input type="radio" name="scheduling_type" value="single" class="hbl-form-radio" <?php checked( ( $event_data['scheduling_type'] ?? 'single' ), 'single' ); ?>>
									<span class="hbl-form-radio-custom"></span>
									<span class="hbl-form-radio-label-group">
										<span class="hbl-radio-title"><?php esc_html_e( 'One-off or regular event', 'hbl' ); ?></span>
										<span class="hbl-radio-desc"><?php esc_html_e( 'Markets, classes, workshops, recurring activities', 'hbl' ); ?></span>
									</span>
								</label>
								<label class="hbl-form-radio-option hbl-form-radio-option-stacked">
									<input type="radio" name="scheduling_type" value="multi" class="hbl-form-radio" <?php checked( ( $event_data['scheduling_type'] ?? '' ), 'multi' ); ?>>
									<span class="hbl-form-radio-custom"></span>
									<span class="hbl-form-radio-label-group">
										<span class="hbl-radio-title"><?php esc_html_e( 'Multi-day event', 'hbl' ); ?></span>
										<span class="hbl-radio-desc"><?php esc_html_e( 'Exhibitions, festivals, shows, installations', 'hbl' ); ?></span>
									</span>
								</label>
							</div>
						</div>

						<div id="date-block-single" class="hbl-date-block">
							<div class="hbl-form-section-title" style="margin-bottom: 15px;">
								<h3><?php esc_html_e( 'Next occurrence', 'hbl' ); ?></h3>
								<p><?php esc_html_e( 'Enter the date of the next time this event runs.', 'hbl' ); ?></p>
							</div>

							<div class="hbl-form-row">
								<div class="hbl-form-group hbl-form-group-third">
									<label for="start_date_single" class="hbl-form-label">
										<span><?php esc_html_e( 'Date', 'hbl' ); ?></span>
										<span class="hbl-form-required">*</span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="date" id="start_date_single" name="start_date_single" class="hbl-form-input" value="<?php echo !empty($event_data['start_date']) ? date('Y-m-d', strtotime($event_data['start_date'])) : ''; ?>">
									</div>
								</div>
								<div class="hbl-form-group hbl-form-group-third">
									<label for="start_time_single" class="hbl-form-label">
										<span><?php esc_html_e( 'Start Time', 'hbl' ); ?></span>
										<span class="hbl-form-required">*</span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="time" id="start_time_single" name="start_time_single" class="hbl-form-input" value="<?php echo !empty($event_data['start_date']) ? date('H:i', strtotime($event_data['start_date'])) : ''; ?>">
									</div>
								</div>
								<div class="hbl-form-group hbl-form-group-third">
									<label for="end_time_single" class="hbl-form-label">
										<span><?php esc_html_e( 'End Time', 'hbl' ); ?></span>
										<span class="hbl-form-required">*</span>
									</label>
									<div class="hbl-form-input-wrapper">
										<input type="time" id="end_time_single" name="end_time_single" class="hbl-form-input" value="<?php echo !empty($event_data['end_date']) ? date('H:i', strtotime($event_data['end_date'])) : ''; ?>">
									</div>
								</div>
							</div>
						</div>

						<div id="date-block-multi" class="hbl-date-block" style="display: none;">
							<div class="hbl-form-section-title" style="margin-bottom: 15px;">
								<h3><?php esc_html_e( 'Event dates', 'hbl' ); ?></h3>
								<p><?php esc_html_e( 'Select the start and end dates of the event and the regular opening hours.', 'hbl' ); ?></p>
							</div>

							<div class="hbl-form-group">
								<label class="hbl-form-label"><?php esc_html_e( 'Event runs from', 'hbl' ); ?></label>
								<div class="hbl-form-row">
									<div class="hbl-form-group hbl-form-group-half">
										<label for="start_date_multi" class="hbl-form-label">
											<span><?php esc_html_e( 'Start Date', 'hbl' ); ?></span>
											<span class="hbl-form-required">*</span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="date" id="start_date_multi" name="start_date_multi" class="hbl-form-input" value="<?php echo !empty($event_data['start_date']) ? date('Y-m-d', strtotime($event_data['start_date'])) : ''; ?>">
										</div>
									</div>
									<div class="hbl-form-group hbl-form-group-half">
										<label for="end_date_multi" class="hbl-form-label">
											<span><?php esc_html_e( 'End Date', 'hbl' ); ?></span>
											<span class="hbl-form-required">*</span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="date" id="end_date_multi" name="end_date_multi" class="hbl-form-input" value="<?php echo !empty($event_data['end_date']) ? date('Y-m-d', strtotime($event_data['end_date'])) : ''; ?>">
										</div>
									</div>
								</div>
							</div>

							<div class="hbl-form-group">
								<label class="hbl-form-label"><?php esc_html_e( 'Daily opening hours', 'hbl' ); ?></label>
								<div class="hbl-form-row">
									<div class="hbl-form-group hbl-form-group-half">
										<label for="daily_start_time" class="hbl-form-label">
											<span><?php esc_html_e( 'Start Time', 'hbl' ); ?></span>
											<span class="hbl-form-required">*</span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="time" id="daily_start_time" name="daily_start_time" class="hbl-form-input" value="<?php echo esc_attr( $event_data['daily_start_time'] ?? '' ); ?>">
										</div>
									</div>
									<div class="hbl-form-group hbl-form-group-half">
										<label for="daily_end_time" class="hbl-form-label">
											<span><?php esc_html_e( 'End Time', 'hbl' ); ?></span>
											<span class="hbl-form-required">*</span>
										</label>
										<div class="hbl-form-input-wrapper">
											<input type="time" id="daily_end_time" name="daily_end_time" class="hbl-form-input" value="<?php echo esc_attr( $event_data['daily_end_time'] ?? '' ); ?>">
										</div>
									</div>
								</div>
							</div>

							<div class="hbl-form-group">
								<label class="hbl-form-label">
									<span><?php esc_html_e( 'Open days', 'hbl' ); ?></span>
									<span class="hbl-form-hint"><?php esc_html_e( '(Click to deselect closed days)', 'hbl' ); ?></span>
								</label>
								
								<div class="hbl-form-days-grid hbl-form-days-grid-multi">
									<?php
									$days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
									$saved_days = !empty($event_data['recurrence_days']) ? explode(',', $event_data['recurrence_days']) : $days;
									
									foreach ( $days as $day ) :
										$is_selected = in_array( $day, $saved_days );
										?>
										<div class="hbl-form-day-option-multi hbl-form-day-option-monthly <?php echo $is_selected ? 'hbl-selected' : ''; ?>" data-day="<?php echo esc_attr( $day ); ?>">
											<span class="hbl-form-day-label"><?php echo esc_html( ucfirst( $day ) ); ?></span>
										</div>
									<?php endforeach; ?>
								</div>
								<input type="hidden" id="multi_open_days" name="multi_open_days" value="<?php echo esc_attr( implode(',', $saved_days) ); ?>">
							</div>
							
							<div class="hbl-alert hbl-alert-info">
								<p style="margin:0; font-size: 14px;"><?php esc_html_e( 'Any one-off changes should be noted in the event description.', 'hbl' ); ?></p>
							</div>
						</div>

						<div class="hbl-form-group">
							<label for="event_venue" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
								</svg>
								<span><?php esc_html_e( 'Venue Name', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="event_venue" name="event_venue" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Where is your event happening?', 'hbl' ); ?>" value="<?php echo esc_attr( $event_data['venue'] ?? '' ); ?>" required>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Name of the park, hall, venue, or location.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-form-group">
							<label for="event_address" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M21 10C21 17 12 23 12 23S3 17 3 10C3 7.61305 3.94821 5.32387 5.63604 3.63604C7.32387 1.94821 9.61305 1 12 1C14.3869 1 16.6761 1.94821 18.364 3.63604C20.0518 5.32387 21 7.61305 21 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
								</svg>
								<span><?php esc_html_e( 'Venue Address', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="text" id="event_address" name="event_address" class="hbl-form-input" placeholder="<?php esc_attr_e( 'Street address', 'hbl' ); ?>" value="<?php echo esc_attr( $event_data['address'] ?? '' ); ?>">
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Street address if known. You can leave this blank for well-known public locations.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-form-group">
							<label for="event_url" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M10 13C10.4295 13.5741 10.9774 14.0491 11.6066 14.3929C12.2357 14.7367 12.9315 14.9411 13.6467 14.9923C14.3618 15.0435 15.0796 14.9404 15.7513 14.6897C16.4231 14.4391 17.0331 14.0471 17.54 13.54L20.54 10.54C21.4508 9.59695 21.9548 8.33394 21.9434 7.02296C21.932 5.71198 21.4061 4.45791 20.4791 3.53087C19.5521 2.60383 18.298 2.07799 16.987 2.0666C15.676 2.0552 14.413 2.55918 13.47 3.46997L11.75 5.17997" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M14 11C13.5705 10.4259 13.0226 9.95083 12.3934 9.60706C11.7642 9.26329 11.0684 9.05886 10.3533 9.00765C9.63816 8.95643 8.92037 9.05961 8.24861 9.3103C7.57685 9.56099 6.96684 9.95293 6.45996 10.46L3.45996 13.46C2.54917 14.403 2.04519 15.666 2.05659 16.977C2.06798 18.288 2.59382 19.5421 3.52086 20.4691C4.4479 21.3961 5.70197 21.922 7.01295 21.9334C8.32393 21.9448 9.58694 21.4408 10.53 20.53L12.24 18.82" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Event Website or Booking Link', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="url" id="event_url" name="event_url" class="hbl-form-input" placeholder="<?php esc_attr_e( 'https://...', 'hbl' ); ?>" value="<?php echo esc_attr( $event_data['event_url'] ?? '' ); ?>" required>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Link to the official website or Facebook page where updates are posted.', 'hbl' ); ?></p>
						</div>

						<div class="hbl-form-group">
							<label for="contact_email" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<path d="M22 6L12 13L2 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Contact Email', 'hbl' ); ?></span>
								<span class="hbl-form-hint"><?php esc_html_e( '(not displayed publicly)', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<input type="email" id="contact_email" name="contact_email" class="hbl-form-input" placeholder="<?php esc_attr_e( 'your@email.com', 'hbl' ); ?>" value="<?php echo esc_attr( $event_data['contact_email'] ?? $user_email ); ?>" required>
							</div>
						</div>
					</div>
				</div>

				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Event Type', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Help us categorise your event', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<?php if ( ! empty( $event_categories ) && ! is_wp_error( $event_categories ) ) : ?>
						<div class="hbl-form-group">
							<label for="event_category" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M22 19C22 19.5304 21.7893 20.0391 21.4142 20.4142C21.0391 20.7893 20.5304 21 20 21H4C3.46957 21 2.96086 20.7893 2.58579 20.4142C2.21071 20.0391 2 19.5304 2 19V5C2 4.46957 2.21071 3.96086 2.58579 3.58579C2.96086 3.21071 3.46957 3 4 3H9L11 6H20C20.5304 6 21.0391 6.21071 21.4142 6.58579C21.7893 6.96086 22 7.46957 22 8V19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Event Category', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<select id="event_category" name="event_category" class="hbl-form-select" required>
									<option value=""><?php esc_html_e( '— Select Category —', 'hbl' ); ?></option>
									<?php foreach ( $event_categories as $category ) : ?>
										<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( $event_data['category'] ?? 0, $category->term_id ); ?>>
											<?php echo esc_html( $category->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Choose the category that best matches what visitors attend this event for.', 'hbl' ); ?></p>
						</div>
						<?php endif; ?>

						<?php if ( ! empty( $event_tags ) && ! is_wp_error( $event_tags ) ) : ?>
						<div class="hbl-form-group">
							<label for="event_tags" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20.59 13.41L13.42 20.58C13.2343 20.766 13.0137 20.9135 12.7709 21.0141C12.5281 21.1148 12.2678 21.1666 12.005 21.1666C11.7422 21.1666 11.4819 21.1148 11.2391 21.0141C10.9963 20.9135 10.7757 20.766 10.59 20.58L2 12V2H12L20.59 10.59C20.9625 10.9647 21.1716 11.4716 21.1716 12C21.1716 12.5284 20.9625 13.0353 20.59 13.41Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<line x1="7" y1="7" x2="7.01" y2="7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
								<span><?php esc_html_e( 'Event Tags', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-input-wrapper">
								<?php
								$selected_tags = array_map( 'intval', (array) ( $event_data['tags'] ?? array() ) );
								$selected_tag  = ! empty( $selected_tags ) ? $selected_tags[0] : 0;
								?>
								<select id="event_tags" name="event_tags" class="hbl-form-select">
									<option value=""><?php esc_html_e( '— Select Tag —', 'hbl' ); ?></option>
									<?php foreach ( $event_tags as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag->term_id ); ?>" <?php selected( $selected_tag, (int) $tag->term_id ); ?>><?php echo esc_html( $tag->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'Add a tag to help people find your event (optional).', 'hbl' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 1V23" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M17 5H9.5C8.57174 5 7.6815 5.36875 7.02513 6.02513C6.36875 6.6815 6 7.57174 6 8.5C6 9.42826 6.36875 10.3185 7.02513 10.9749C7.6815 11.6313 8.57174 12 9.5 12H14.5C15.4283 12 16.3185 12.3687 16.9749 13.0251C17.6313 13.6815 18 14.5717 18 15.5C18 16.4283 17.6313 17.3185 16.9749 17.9749C16.3185 18.6313 15.4283 19 14.5 19H6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Cost to Attend', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Let people know if there\'s a cost', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label class="hbl-form-label">
								<span><?php esc_html_e( 'Is this event free or paid to attend?', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-radio-group">
								<label class="hbl-form-radio-option">
									<input type="radio" name="event_cost" value="free" class="hbl-form-radio" <?php checked( ( $event_data['event_cost'] ?? 'free' ), 'free' ); ?>>
									<span class="hbl-form-radio-custom"></span>
									<span class="hbl-form-radio-label">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Free', 'hbl' ); ?>
									</span>
								</label>
								<label class="hbl-form-radio-option">
									<input type="radio" name="event_cost" value="paid" class="hbl-form-radio" <?php checked( ( $event_data['event_cost'] ?? '' ), 'paid' ); ?>>
									<span class="hbl-form-radio-custom"></span>
									<span class="hbl-form-radio-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<rect x="1" y="4" width="22" height="16" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
											<path d="M1 10H23" stroke="currentColor" stroke-width="2"/>
									</svg>
										<?php esc_html_e( 'Paid', 'hbl' ); ?>
									</span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<div class="hbl-form-section" id="frequency-block-single">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M17 1L21 5L17 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M3 11V9C3 7.93913 3.42143 6.92172 4.17157 6.17157C4.92172 5.42143 5.93913 5 7 5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M7 23L3 19L7 15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M21 13V15C21 16.0609 20.5786 17.0783 19.8284 17.8284C19.0783 18.5786 18.0609 19 17 19H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Frequency', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Example: “Weekly on Saturdays” or “1st Sunday of each month”.', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="event_frequency" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
									<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
								</svg>
								<span><?php esc_html_e( 'Example: “Weekly on Saturdays” or “1st Sunday of each month”.', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
								</label>
								<div class="hbl-form-input-wrapper">
								<select id="event_frequency" name="event_frequency" class="hbl-form-select" required>
									<option value=""><?php esc_html_e( '— Select Frequency —', 'hbl' ); ?></option>
									<option value="once" <?php selected( $event_data['event_frequency'] ?? '', 'once' ); ?>><?php esc_html_e( 'One-off event', 'hbl' ); ?></option>
									<option value="weekly" <?php selected( $event_data['event_frequency'] ?? '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'hbl' ); ?></option>
									<option value="monthly" <?php selected( $event_data['event_frequency'] ?? '', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'hbl' ); ?></option>
								</select>
							</div>
						</div>

						<?php 
						$recurrence_days_raw = $event_data['recurrence_days'] ?? '';
						$recurrence_days_array = ! empty( $recurrence_days_raw ) ? explode( ',', $recurrence_days_raw ) : array();
						$recurrence_interval = $event_data['recurrence_interval'] ?? 1;
						?>
						<div class="hbl-form-recurrence-options hbl-form-recurrence-weekly" style="display: none;">
							<div class="hbl-form-group">
								<label class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M8 7V3M16 7V3M7 11H17M5 21H19C20.1046 21 21 20.1046 21 19V7C21 5.89543 20.1046 5 19 5H5C3.89543 5 3 5.89543 3 7V19C3 20.1046 3.89543 21 5 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Which day(s) of the week?', 'hbl' ); ?></span>
									<span class="hbl-form-required">*</span>
								</label>
								<div class="hbl-form-days-grid">
									<?php
									$days = array(
										'mon' => __( 'Mon', 'hbl' ),
										'tue' => __( 'Tue', 'hbl' ),
										'wed' => __( 'Wed', 'hbl' ),
										'thu' => __( 'Thu', 'hbl' ),
										'fri' => __( 'Fri', 'hbl' ),
										'sat' => __( 'Sat', 'hbl' ),
										'sun' => __( 'Sun', 'hbl' ),
									);
									foreach ( $days as $day_value => $day_label ) :
									?>
									<label class="hbl-form-day-option">
										<input type="checkbox" name="recurrence_days[]" value="<?php echo esc_attr( $day_value ); ?>" <?php checked( in_array( $day_value, $recurrence_days_array, true ) ); ?>>
										<span class="hbl-form-day-label"><?php echo esc_html( $day_label ); ?></span>
									</label>
									<?php endforeach; ?>
								</div>
								<p class="hbl-form-field-hint"><?php esc_html_e( 'Select one or more days. E.g., select Wednesday and Saturday for "Twice per week".', 'hbl' ); ?></p>
							</div>

							<div class="hbl-form-group">
								<label for="recurrence_interval_weekly" class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M17 1L21 5L17 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										<path d="M3 11V9C3 7.93913 3.42143 6.92172 4.17157 6.17157C4.92172 5.42143 5.93913 5 7 5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'How often?', 'hbl' ); ?></span>
								</label>
								<div class="hbl-form-input-wrapper">
									<select id="recurrence_interval_weekly" name="recurrence_interval" class="hbl-form-select">
										<option value="1" <?php selected( $recurrence_interval, 1 ); ?>><?php esc_html_e( 'Every week', 'hbl' ); ?></option>
										<option value="2" <?php selected( $recurrence_interval, 2 ); ?>><?php esc_html_e( 'Every second week (fortnightly)', 'hbl' ); ?></option>
									</select>
								</div>
								<p class="hbl-form-field-hint"><?php esc_html_e( 'E.g., "Every second Friday" or "Every week on Wednesday and Saturday".', 'hbl' ); ?></p>
							</div>
						</div>

						<?php 
						$recurrence_week = ! empty( $event_data['recurrence_week'] ) ? explode( ',', $event_data['recurrence_week'] ) : array();
						$recurrence_type = $event_data['recurrence_type'] ?? 'day_of_week';
						?>
						<div class="hbl-form-recurrence-options hbl-form-recurrence-monthly" style="display: none;">
							<div class="hbl-form-group">
								<label class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
										<path d="M16 2V6M8 2V6M3 10H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
									</svg>
									<span><?php esc_html_e( 'Which week(s) of the month?', 'hbl' ); ?></span>
									<span class="hbl-form-required">*</span>
								</label>
								<div class="hbl-form-weeks-grid">
									<?php
									$weeks_options = array(
										'1' => __( '1st', 'hbl' ),
										'2' => __( '2nd', 'hbl' ),
										'3' => __( '3rd', 'hbl' ),
										'4' => __( '4th', 'hbl' ),
										'5' => __( '5th', 'hbl' ),
									);
									foreach ( $weeks_options as $week_value => $week_label ) :
									$week_value_str = (string) $week_value;
									?>
									<label class="hbl-form-week-option">
										<input type="checkbox" name="recurrence_week[]" value="<?php echo esc_attr( $week_value_str ); ?>" <?php checked( in_array( $week_value_str, $recurrence_week, true ) ); ?>>
										<span class="hbl-form-week-label"><?php echo esc_html( $week_label ); ?></span>
									</label>
									<?php endforeach; ?>
								</div>
								<p class="hbl-form-field-hint"><?php esc_html_e( 'Select which week(s). E.g., "1st and 3rd" for events on the first and third week of each month.', 'hbl' ); ?></p>
							</div>

							<div class="hbl-form-group">
								<label class="hbl-form-label">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
										<path d="M8 7V3M16 7V3M7 11H17M5 21H19C20.1046 21 21 20.1046 21 19V7C21 5.89543 20.1046 5 19 5H5C3.89543 5 3 5.89543 3 7V19C3 20.1046 3.89543 21 5 21Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									</svg>
									<span><?php esc_html_e( 'Which day of the week?', 'hbl' ); ?></span>
									<span class="hbl-form-required">*</span>
								</label>
								<?php $selected_monthly_day = ! empty( $recurrence_days_raw ) ? $recurrence_days_raw : ''; ?>
								<input type="hidden" name="recurrence_day_monthly" id="recurrence_day_monthly" value="<?php echo esc_attr( $selected_monthly_day ); ?>">
								<div class="hbl-form-days-grid hbl-form-days-grid-monthly">
									<?php
									foreach ( $days as $day_value => $day_label ) :
									$is_selected = ( $selected_monthly_day === $day_value );
									?>
									<div class="hbl-form-day-option hbl-form-day-option-monthly <?php echo $is_selected ? 'hbl-selected' : ''; ?>" data-day="<?php echo esc_attr( $day_value ); ?>">
										<span class="hbl-form-day-label"><?php echo esc_html( $day_label ); ?></span>
									</div>
									<?php endforeach; ?>
								</div>
								<p class="hbl-form-field-hint"><?php esc_html_e( 'E.g., "Sunday" for "Every 1st and 3rd Sunday of the month". Click again to deselect.', 'hbl' ); ?></p>
							</div>
						</div>

						<input type="hidden" name="recurrence_type" id="recurrence_type" value="<?php echo esc_attr( $recurrence_type ); ?>">

						<div class="hbl-form-group hbl-form-group-checkbox">
							<label class="hbl-form-checkbox-label">
								<input type="checkbox" name="is_program" value="1" class="hbl-form-checkbox" id="is_program" <?php checked( ! empty( $event_data['is_program'] ) ); ?>>
								<span class="hbl-form-checkbox-custom"></span>
								<span class="hbl-form-checkbox-text"><?php esc_html_e( 'This is part of a regular class, program, or series', 'hbl' ); ?></span>
							</label>
							<p class="hbl-form-field-hint"><?php esc_html_e( 'e.g., "Weekly yoga class" vs a one-off workshop', 'hbl' ); ?></p>
						</div>
					</div>
				</div>

				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M17 21V19C17 17.9391 16.5786 16.9217 15.8284 16.1716C15.0783 15.4214 14.0609 15 13 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
								<path d="M23 21V19C22.9993 18.1137 22.7044 17.2528 22.1614 16.5523C21.6184 15.8519 20.8581 15.3516 20 15.13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M16 3.13C16.8604 3.35031 17.623 3.85071 18.1676 4.55232C18.7122 5.25392 19.0078 6.11683 19.0078 7.005C19.0078 7.89318 18.7122 8.75608 18.1676 9.45769C17.623 10.1593 16.8604 10.6597 16 10.88" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Organiser', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Who is running this event?', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<div class="hbl-form-group">
							<label for="organiser_type" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 5.17157 16.1716C4.42143 16.9217 4 17.9391 4 19V21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
								</svg>
								<span><?php esc_html_e( 'Who is organising this event?', 'hbl' ); ?></span>
								<span class="hbl-form-required">*</span>
							</label>
							<div class="hbl-form-input-wrapper">
								<select id="organiser_type" name="organiser_type" class="hbl-form-select" required>
									<option value=""><?php esc_html_e( '— Select Organiser Type —', 'hbl' ); ?></option>
									<option value="individual" <?php selected( $event_data['organiser_type'] ?? '', 'individual' ); ?>><?php esc_html_e( 'Individual', 'hbl' ); ?></option>
									<option value="community" <?php selected( $event_data['organiser_type'] ?? '', 'community' ); ?>><?php esc_html_e( 'Community group or not-for-profit', 'hbl' ); ?></option>
									<option value="business" <?php selected( $event_data['organiser_type'] ?? '', 'business' ); ?>><?php esc_html_e( 'Business', 'hbl' ); ?></option>
								</select>
							</div>
						</div>
							</div>
						</div>

				<div class="hbl-form-section">
					<div class="hbl-form-section-header">
						<div class="hbl-form-section-icon">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
								<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2"/>
								<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</div>
						<div class="hbl-form-section-title">
							<h3><?php esc_html_e( 'Event Image', 'hbl' ); ?></h3>
							<p><?php esc_html_e( 'Optional. Upload a logo or image.', 'hbl' ); ?></p>
						</div>
					</div>
					<div class="hbl-form-section-content">
						<?php 
						$thumbnail_id = $event_data['thumbnail_id'] ?? '';
						$thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '';
						?>
						<div class="hbl-form-group">
							<div class="hbl-form-image-upload">
								<input type="hidden" id="featured_image" name="featured_image" value="<?php echo esc_attr( $thumbnail_id ); ?>">
								<div class="hbl-form-image-preview" id="hbl-event-image-preview">
									<?php if ( $thumbnail_url ) : ?>
										<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php esc_attr_e( 'Event image', 'hbl' ); ?>">
									<?php else : ?>
									<div class="hbl-form-image-placeholder">
										<svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<rect x="3" y="3" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<circle cx="8.5" cy="8.5" r="1.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M21 15L16 10L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<p><?php esc_html_e( 'Upload an image to help promote your event', 'hbl' ); ?></p>
									</div>
									<?php endif; ?>
								</div>
								<div class="hbl-form-image-buttons">
									<button type="button" class="hbl-form-btn hbl-form-btn-secondary hbl-upload-image">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M21 15V19C21 19.5304 20.7893 20.0391 20.4142 20.4142C20.0391 20.7893 19.5304 21 19 21H5C4.46957 21 3.96086 20.7893 3.58579 20.4142C3.21071 20.0391 3 19.5304 3 19V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M17 8L12 3L7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
											<path d="M12 3V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Upload Image', 'hbl' ); ?>
									</button>
									<button type="button" class="hbl-form-btn hbl-form-btn-danger hbl-remove-image" style="<?php echo $thumbnail_url ? '' : 'display: none;'; ?>">
										<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
											<path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
										<?php esc_html_e( 'Remove', 'hbl' ); ?>
									</button>
								</div>
							</div>
						</div>

						<?php $current_color = $event_data['color'] ?? '#008080'; ?>
						<div class="hbl-form-group">
							<label for="event_color" class="hbl-form-label">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
									<circle cx="12" cy="12" r="4" fill="currentColor"/>
								</svg>
								<span><?php esc_html_e( 'Calendar Colour', 'hbl' ); ?></span>
								<span class="hbl-form-optional"><?php esc_html_e( '(optional)', 'hbl' ); ?></span>
							</label>
							<div class="hbl-form-color-wrapper">
								<input type="color" id="event_color" name="event_color" class="hbl-form-color-input" value="<?php echo esc_attr( $current_color ); ?>">
								<div class="hbl-form-color-preview" style="background-color: <?php echo esc_attr( $current_color ); ?>;"></div>
								<span class="hbl-form-color-value"><?php echo esc_html( $current_color ); ?></span>
							</div>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $settings['transparency_note'] ) ) : ?>
				<div class="hbl-form-transparency-note">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
						<path d="M12 16V12M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<p><?php echo esc_html( $settings['transparency_note'] ); ?></p>
				</div>
				<?php endif; ?>

				<?php if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) ) : ?>
				<div class="hbl-recaptcha-wrapper" style="margin:0 0 16px;">
					<?php if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) : ?>
						<div style="border:2px dashed #ccc;padding:12px 16px;text-align:center;color:#888;font-size:13px;border-radius:4px;background:#f9f9f9;">reCAPTCHA (renders on frontend)</div>
					<?php else : ?>
						<div class="g-recaptcha" data-sitekey="<?php echo esc_attr( get_option( 'elementor_pro_recaptcha_site_key' ) ); ?>"></div>
					<?php endif; ?>
					<span class="hbl-recaptcha-error" style="display:none;color:#dc3545;font-size:13px;margin-top:4px;"><?php esc_html_e( 'Please complete the CAPTCHA.', 'hbl' ); ?></span>
				</div>
				<?php endif; ?>

				<div class="hbl-form-actions">
					<?php if ( $is_editing ) : ?>
						<a href="<?php echo esc_url( $dashboard_url ); ?>" class="hbl-form-btn hbl-form-btn-secondary hbl-form-btn-large">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M19 12H5M12 19L5 12L12 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<span><?php esc_html_e( 'Cancel', 'hbl' ); ?></span>
						</a>
					<?php endif; ?>
					<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-large <?php echo $is_editing ? '' : 'hbl-form-btn-block'; ?>" id="hbl-event-submit-btn">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M22 11.08V12C21.9988 14.1564 21.3005 16.2547 20.0093 17.9818C18.7182 19.709 16.9033 20.9725 14.8354 21.5839C12.7674 22.1953 10.5573 22.1219 8.53447 21.3746C6.51168 20.6273 4.78465 19.2461 3.61096 17.4371C2.43727 15.628 1.87979 13.4881 2.02168 11.3363C2.16356 9.18457 2.99721 7.13633 4.39828 5.49707C5.79935 3.85782 7.69279 2.71538 9.79619 2.24015C11.8996 1.76491 14.1003 1.98234 16.07 2.86" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							<path d="M22 4L12 14.01L9 11.01" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						<span><?php echo $is_editing ? esc_html__( 'Update Event', 'hbl' ) : esc_html__( 'Submit Event', 'hbl' ); ?></span>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	public static function get_event_internal_tags( $event_id ) {
		$tags = array();

		$event_type = get_post_meta( $event_id, '_hbl_event_type', true );
		if ( $event_type && isset( self::$event_type_tags[ $event_type ] ) ) {
			$tags[] = self::$event_type_tags[ $event_type ];
		}

		$event_cost = get_post_meta( $event_id, '_hbl_event_cost', true );
		if ( $event_cost && isset( self::$cost_tags[ $event_cost ] ) ) {
			$tags[] = self::$cost_tags[ $event_cost ];
		}

		$event_frequency = get_post_meta( $event_id, '_hbl_event_frequency', true );
		if ( $event_frequency && isset( self::$frequency_tags[ $event_frequency ] ) ) {
			$tags[] = self::$frequency_tags[ $event_frequency ];
		}

		$is_program = get_post_meta( $event_id, '_hbl_event_is_program', true );
		if ( $is_program ) {
			$tags[] = 'event-program';
		}

		$organiser_type = get_post_meta( $event_id, '_hbl_event_organiser_type', true );
		if ( $organiser_type && isset( self::$organiser_tags[ $organiser_type ] ) ) {
			$tags[] = self::$organiser_tags[ $organiser_type ];
		}

		return $tags;
	}

	public static function get_tag_mappings() {
		return array(
			'event_type'     => self::$event_type_tags,
			'cost'           => self::$cost_tags,
			'frequency'      => self::$frequency_tags,
			'organiser_type' => self::$organiser_tags,
		);
	}
}
