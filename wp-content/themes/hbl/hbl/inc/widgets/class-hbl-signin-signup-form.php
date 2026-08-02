<?php
/**
 * HBL Sign In/Signup Form Widget
 * 
 * A beautiful Elementor widget for login and registration
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

class HBL_Signin_Signup_Form extends Widget_Base {

	public function get_name() {
		return 'hbl-signin-signup-form';
	}

	public function get_title() {
		return esc_html__( 'HBL Sign In/Signup Form', 'hbl' );
	}

	public function get_icon() {
		return 'eicon-lock-user';
	}

	public function get_categories() {
		return array( 'hbl' );
	}

	public function get_keywords() {
		return array( 'login', 'signin', 'signup', 'register', 'authentication', 'directorist' );
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
			'default_tab',
			array(
				'label'   => esc_html__( 'Default Tab', 'hbl' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'signin',
				'options' => array(
					'signin'  => esc_html__( 'Sign In', 'hbl' ),
					'signup'  => esc_html__( 'Sign Up', 'hbl' ),
				),
			)
		);

		$this->add_control(
			'show_registration',
			array(
				'label'        => esc_html__( 'Show Registration Form', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_remember_me',
			array(
				'label'        => esc_html__( 'Show Remember Me', 'hbl' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'Yes', 'hbl' ),
				'label_off'    => esc_html__( 'No', 'hbl' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_forgot_password',
			array(
				'label'        => esc_html__( 'Show Forgot Password', 'hbl' ),
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
			'redirect_after_login',
			array(
				'label'       => esc_html__( 'Redirect After Login', 'hbl' ),
				'type'        => Controls_Manager::URL,
				'placeholder' => esc_html__( 'Leave empty for default', 'hbl' ),
				'default'     => array(
					'url' => '',
				),
			)
		);

		$this->end_controls_section();

		// ========== CONTENT: LABELS ==========
		$this->start_controls_section(
			'section_labels',
			array(
				'label' => esc_html__( 'Form Labels', 'hbl' ),
			)
		);

		$this->add_control(
			'signin_title',
			array(
				'label'   => esc_html__( 'Sign In Title', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Sign In',
			)
		);

		$this->add_control(
			'signup_title',
			array(
				'label'     => esc_html__( 'Sign Up Title', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Create Account',
				'condition' => array(
					'show_registration' => 'yes',
				),
			)
		);

		$this->add_control(
			'username_label',
			array(
				'label'   => esc_html__( 'Username/Email Label', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Username or Email',
			)
		);

		$this->add_control(
			'password_label',
			array(
				'label'   => esc_html__( 'Password Label', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Password',
			)
		);

		$this->add_control(
			'email_label',
			array(
				'label'     => esc_html__( 'Email Label', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Email',
				'condition' => array(
					'show_registration' => 'yes',
				),
			)
		);

		$this->add_control(
			'signin_button_text',
			array(
				'label'   => esc_html__( 'Sign In Button Text', 'hbl' ),
				'type'    => Controls_Manager::TEXT,
				'default' => 'Sign In',
			)
		);

		$this->add_control(
			'signup_button_text',
			array(
				'label'     => esc_html__( 'Sign Up Button Text', 'hbl' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => 'Create Account',
				'condition' => array(
					'show_registration' => 'yes',
				),
			)
		);

		$this->end_controls_section();

		// ========== STYLE: TABS ==========
		$this->start_controls_section(
			'section_style_tabs',
			array(
				'label' => esc_html__( 'Tabs', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'tab_bg_color',
			array(
				'label'     => esc_html__( 'Tab Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F8F9FA',
				'selectors' => array(
					'{{WRAPPER}} .hbl-auth-tab' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'tab_active_bg_color',
			array(
				'label'     => esc_html__( 'Active Tab Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-auth-tab.active' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'tab_text_color',
			array(
				'label'     => esc_html__( 'Tab Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#6C757D',
				'selectors' => array(
					'{{WRAPPER}} .hbl-auth-tab' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'tab_active_text_color',
			array(
				'label'     => esc_html__( 'Active Tab Text Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-auth-tab.active' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'tab_typography',
				'label'    => esc_html__( 'Tab Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-auth-tab',
			)
		);

		$this->end_controls_section();

		// ========== STYLE: FORM FIELDS ==========
		$this->start_controls_section(
			'section_style_fields',
			array(
				'label' => esc_html__( 'Form Fields', 'hbl' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'field_bg_color',
			array(
				'label'     => esc_html__( 'Field Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-input' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'field_border_color',
			array(
				'label'     => esc_html__( 'Field Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#E9ECEF',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-input' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'field_focus_border_color',
			array(
				'label'     => esc_html__( 'Field Focus Border Color', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-input:focus' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'field_typography',
				'label'    => esc_html__( 'Field Typography', 'hbl' ),
				'selector' => '{{WRAPPER}} .hbl-form-input',
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
			'button_bg_color',
			array(
				'label'     => esc_html__( 'Button Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#F9532A',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_hover_bg_color',
			array(
				'label'     => esc_html__( 'Button Hover Background', 'hbl' ),
				'type'      => Controls_Manager::COLOR,
				'default'   => '#008080',
				'selectors' => array(
					'{{WRAPPER}} .hbl-form-btn-primary:hover' => 'background-color: {{VALUE}};',
				),
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

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		// Enqueue reCAPTCHA script if enabled
		if ( 'yes' === $settings['enable_recaptcha'] && get_option( 'elementor_pro_recaptcha_site_key' ) && ! \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			wp_enqueue_script( 'google-recaptcha' );
		}

		// Check if user is already logged in
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$logout_url = wp_logout_url( home_url() );
			?>
			<div class="hbl-signin-signup-form-widget">
				<div class="hbl-form-already-logged-in">
					<div class="hbl-form-user-avatar">
						<?php echo get_avatar( $current_user->ID, 64 ); ?>
					</div>
					<h3><?php echo esc_html( sprintf( __( 'Welcome, %s!', 'hbl' ), $current_user->display_name ) ); ?></h3>
					<p><?php esc_html_e( 'You are already logged in.', 'hbl' ); ?></p>
					<div class="hbl-form-logout-button">
						<a href="<?php echo esc_url( $logout_url ); ?>" class="hbl-form-btn hbl-form-btn-primary">
							<?php esc_html_e( 'Logout', 'hbl' ); ?>
						</a>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$default_tab = $settings['default_tab'];

		// Allow a URL param to force the starting tab, e.g. links that should
		// land on the Create Account form use ?tab=signup (also accepts
		// register / signin / login).
		if ( isset( $_GET['tab'] ) ) {
			$requested_tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
			if ( in_array( $requested_tab, array( 'signup', 'register' ), true ) ) {
				$default_tab = 'signup';
			} elseif ( in_array( $requested_tab, array( 'signin', 'login' ), true ) ) {
				$default_tab = 'signin';
			}
		}

		// If registration is disabled, never force the signup tab.
		if ( 'signup' === $default_tab && 'yes' !== $settings['show_registration'] ) {
			$default_tab = 'signin';
		}

		$redirect_url = ! empty( $settings['redirect_after_login']['url'] )
			? $settings['redirect_after_login']['url'] 
			: ( isset( $_GET['redirect_to'] ) ? esc_url_raw( $_GET['redirect_to'] ) : home_url() );
		?>
		<div class="hbl-signin-signup-form-widget" data-default-tab="<?php echo esc_attr( $default_tab ); ?>">
			<div class="hbl-auth-tabs">
				<button class="hbl-auth-tab <?php echo 'signin' === $default_tab ? 'active' : ''; ?>" data-tab="signin">
					<?php echo esc_html( $settings['signin_title'] ); ?>
				</button>
				<?php if ( 'yes' === $settings['show_registration'] ) : ?>
				<button class="hbl-auth-tab <?php echo 'signup' === $default_tab ? 'active' : ''; ?>" data-tab="signup">
					<?php echo esc_html( $settings['signup_title'] ); ?>
				</button>
				<?php endif; ?>
			</div>

			<div class="hbl-auth-content">
				<!-- Sign In Form -->
				<form class="hbl-auth-form hbl-signin-form <?php echo 'signin' === $default_tab ? 'active' : ''; ?>" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
					<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect_url ); ?>">
					
					<div class="hbl-form-group">
						<label for="signin_username" class="hbl-form-label">
							<?php echo esc_html( $settings['username_label'] ); ?>
						</label>
						<input type="text" id="signin_username" name="log" class="hbl-form-input" required>
					</div>

					<div class="hbl-form-group">
						<label for="signin_password" class="hbl-form-label">
							<?php echo esc_html( $settings['password_label'] ); ?>
						</label>
						<div class="hbl-form-password-wrapper">
							<input type="password" id="signin_password" name="pwd" class="hbl-form-input" required>
							<button type="button" class="hbl-form-password-toggle" aria-label="Show password">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M1 12S5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
						</div>
					</div>

					<?php if ( 'yes' === $settings['show_remember_me'] || 'yes' === $settings['show_forgot_password'] ) : ?>
					<div class="hbl-form-row hbl-form-row-between">
						<?php if ( 'yes' === $settings['show_remember_me'] ) : ?>
						<label class="hbl-form-checkbox-label">
							<input type="checkbox" name="rememberme" value="forever" class="hbl-form-checkbox">
							<span><?php esc_html_e( 'Remember me', 'hbl' ); ?></span>
						</label>
						<?php endif; ?>
						
						<?php if ( 'yes' === $settings['show_forgot_password'] ) : ?>
						<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" class="hbl-form-link">
							<?php esc_html_e( 'Forgot password?', 'hbl' ); ?>
						</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>

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

						<div class="hbl-form-actions">
						<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-block">
							<?php echo esc_html( $settings['signin_button_text'] ); ?>
						</button>
					</div>

					<?php wp_nonce_field( 'ajax-login-nonce', 'security', false ); ?>
				</form>

				<!-- Sign Up Form -->
				<?php if ( 'yes' === $settings['show_registration'] ) : ?>
				<form class="hbl-auth-form hbl-signup-form <?php echo 'signup' === $default_tab ? 'active' : ''; ?>" method="post" action="<?php echo esc_url( wp_registration_url() ); ?>">
					<div class="hbl-form-group">
						<label for="signup_username" class="hbl-form-label">
							<?php esc_html_e( 'Username', 'hbl' ); ?>
							<span class="hbl-form-required">*</span>
						</label>
						<input type="text" id="signup_username" name="user_login" class="hbl-form-input" required>
					</div>

					<div class="hbl-form-group">
						<label for="signup_email" class="hbl-form-label">
							<?php echo esc_html( $settings['email_label'] ); ?>
							<span class="hbl-form-required">*</span>
						</label>
						<input type="email" id="signup_email" name="user_email" class="hbl-form-input" required>
					</div>

					<div class="hbl-form-group">
						<label for="signup_password" class="hbl-form-label">
							<?php echo esc_html( $settings['password_label'] ); ?>
							<span class="hbl-form-required">*</span>
						</label>
						<div class="hbl-form-password-wrapper">
							<input type="password" id="signup_password" name="user_pass" class="hbl-form-input" required>
							<button type="button" class="hbl-form-password-toggle" aria-label="Show password">
								<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M1 12S5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
									<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
								</svg>
							</button>
						</div>
					</div>
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
					<div class="hbl-form-actions">
						<button type="submit" class="hbl-form-btn hbl-form-btn-primary hbl-form-btn-block">
							<?php echo esc_html( $settings['signup_button_text'] ); ?>
						</button>
					</div>

					<?php wp_nonce_field( 'ajax-register-nonce', 'security', false ); ?>
				</form>
				<?php endif; ?>
			</div>

			<div class="hbl-form-messages"></div>
		</div>
		<?php
	}
}

