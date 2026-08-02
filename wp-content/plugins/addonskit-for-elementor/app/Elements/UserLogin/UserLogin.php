<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\UserLogin;

use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Utils\Helper;
use Directorist\Directorist_Account;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UserLogin extends Widget_Base {
    use Styles;
    use Container;
    use TextControls;

    public function get_name() {
        return 'directorist_user_login';
    }

    public function get_title() {
        return __( 'Sign In & Sign Up', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['directorist-widgets'];
    }

    public function get_keywords() {
        return [
            'user-login', 'login', 'sign-in', 'registration','user-registration','account','login-form',
        ];
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {
        $this->register_form_type_option();
        $this->register_signin_form_fields();
        $this->register_signup_form_fields();
    }

    protected function register_form_type_option(): void {
        $this->start_controls_section(
            'sec_general',
            [
                'label' => __( 'Form Type', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'active_form',
            [
                'label'       => __('Select Active Form', 'addonskit-for-elementor'),
                'description' => __('Choose which form should be displayed when the page loads - Sign In or Sign Up form', 'addonskit-for-elementor'),
                'type'        => Controls_Manager::SELECT,
                'multiple'    => false,
                'options'     => [
                    'signin' => __('Sign In', 'addonskit-for-elementor'),
                    'signup' => __('Sign Up', 'addonskit-for-elementor'),
                ],
                'default'     => method_exists('Directorist\Directorist_Account', 'shortcode_atts') ? Directorist_Account::shortcode_atts()['active_form'] : '',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_signin_form_fields(): void {
        $settings = method_exists('Directorist\Directorist_Account', 'shortcode_atts') ? Directorist_Account::shortcode_atts() : [];

        $this->start_controls_section(
            'sec_signin',
            [
                'label' => __('Sign In Form Fields', 'addonskit-for-elementor'),
                'condition' => ['active_form' => 'signin'],
            ]
        );

        $this->add_control('signin_username_label', [
            'label'   => __('Username Field Label', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signin_username_label']) ? $settings['signin_username_label'] : '',
        ]);

        $this->add_control('signin_button_label', [
            'label'   => __('Sign In Button Text', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signin_button_label']) ? $settings['signin_button_label'] : '',
        ]);

        $this->add_control('enable_recovery_password', [
            'label'   => __('Enable Password Recovery', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['enable_recovery_password']) ? $settings['enable_recovery_password'] : '',
        ]);

        $this->add_control('recovery_password_label', [
            'label'     => __('Recovery Link Text', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['recovery_password_label']) ? $settings['recovery_password_label'] : '',
            'condition' => ['enable_recovery_password' => 'yes'],
        ]);

        $this->add_control('recovery_password_description', [
            'label'     => __('Recovery Description', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXTAREA,
            'default'   => isset($settings['recovery_password_description']) ? $settings['recovery_password_description'] : '',
            'condition' => ['enable_recovery_password' => 'yes'],
        ]);

        $this->add_control('recovery_password_email_label', [
            'label'     => __('Recovery Email Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['recovery_password_email_label']) ? $settings['recovery_password_email_label'] : '',
            'condition' => ['enable_recovery_password' => 'yes'],
        ]);

        $this->add_control('recovery_password_email_placeholder', [
            'label'     => __('Recovery Email Placeholder', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['recovery_password_email_placeholder']) ? $settings['recovery_password_email_placeholder'] : '',
            'condition' => ['enable_recovery_password' => 'yes'],
        ]);

        $this->add_control('recovery_password_button_label', [
            'label'     => __('Recovery Button Text', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['recovery_password_button_label']) ? $settings['recovery_password_button_label'] : '',
            'condition' => ['enable_recovery_password' => 'yes'],
        ]);

        $this->add_control('signup_label', [
            'label'   => __('Sign Up Prompt Text', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signup_label']) ? $settings['signup_label'] : '',
        ]);

        $this->add_control('signup_linking_text', [
            'label'   => __('Sign Up Link Text', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signup_linking_text']) ? $settings['signup_linking_text'] : '',
        ]);

        $this->end_controls_section();
    }

    protected function register_signup_form_fields(): void {
        $settings = method_exists('Directorist\Directorist_Account', 'shortcode_atts') ? Directorist_Account::shortcode_atts() : [];

        $this->start_controls_section('signup_form_fields_section', [
            'label' => __('Sign Up Form Fields', 'addonskit-for-elementor'),
            'tab'   => Controls_Manager::TAB_CONTENT,
            'condition' => ['active_form' =>'signup'],
        ]);

        $this->add_control('user_role', [
            'label'   => __('Display User Role Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['user_role']) ? $settings['user_role'] : '',
        ]);

        $this->add_control('author_role_label', [
            'label'     => __('Author Role Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['author_role_label']) ? $settings['author_role_label'] : '',
            'condition' => ['user_role' => 'yes'],
        ]);

        $this->add_control('user_role_label', [
            'label'     => __('User Role Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['user_role_label']) ? $settings['user_role_label'] : '',
            'condition' => ['user_role' => 'yes'],
        ]);

        $this->add_control('username_label', [
            'label'   => __('Username Label', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['username_label']) ? $settings['username_label'] : '',
        ]);

        $this->add_control('password', [
            'label'   => __('Display Password Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['password']) ? $settings['password'] : '',
        ]);

        $this->add_control('password_label', [
            'label'     => __('Password Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['password_label']) ? $settings['password_label'] : '',
            'condition' => ['password' => 'yes'],
        ]);

        $this->add_control('email_label', [
            'label'   => __('Email Label', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['email_label']) ? $settings['email_label'] : '',
        ]);

        $this->add_control('website', [
            'label'   => __('Display Website Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['website']) ? $settings['website'] : '',
        ]);

        $this->add_control('website_label', [
            'label'     => __('Website Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['website_label']) ? $settings['website_label'] : '',
            'condition' => ['website' => 'yes'],
        ]);

        $this->add_control('website_required', [
            'label'     => __('Website Required?', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::SWITCHER,
            'default'   => isset($settings['website_required']) ? $settings['website_required'] : '',
            'condition' => ['website' => 'yes'],
        ]);

        $this->add_control('firstname', [
            'label'   => __('Display First Name Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['firstname']) ? $settings['firstname'] : '',
        ]);

        $this->add_control('firstname_label', [
            'label'     => __('First Name Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['firstname_label']) ? $settings['firstname_label'] : '',
            'condition' => ['firstname' => 'yes'],
        ]);

        $this->add_control('firstname_required', [
            'label'     => __('First Name Required?', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::SWITCHER,
            'default'   => isset($settings['firstname_required']) ? $settings['firstname_required'] : '',
            'condition' => ['firstname' => 'yes'],
        ]);

        $this->add_control('lastname', [
            'label'   => __('Display Last Name Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['lastname']) ? $settings['lastname'] : '',
        ]);

        $this->add_control('lastname_label', [
            'label'     => __('Last Name Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['lastname_label']) ? $settings['lastname_label'] : '',
            'condition' => ['lastname' => 'yes'],
        ]);

        $this->add_control('lastname_required', [
            'label'     => __('Last Name Required?', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::SWITCHER,
            'default'   => isset($settings['lastname_required']) ? $settings['lastname_required'] : '',
            'condition' => ['lastname' => 'yes'],
        ]);

        $this->add_control('bio', [
            'label'   => __('Display Bio Field', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['bio']) ? $settings['bio'] : '',
        ]);

        $this->add_control('bio_label', [
            'label'     => __('Bio Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['bio_label']) ? $settings['bio_label'] : '',
            'condition' => ['bio' => 'yes'],
        ]);

        $this->add_control('bio_required', [
            'label'     => __('Bio Required?', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::SWITCHER,
            'default'   => isset($settings['bio_required']) ? $settings['bio_required'] : '',
            'condition' => ['bio' => 'yes'],
        ]);

        $this->add_control('privacy', [
            'label'   => __('Display Privacy Policy', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['privacy']) ? $settings['privacy'] : '',
        ]);

        $this->add_control('privacy_label', [
            'label'     => __('Privacy Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['privacy_label']) ? $settings['privacy_label'] : '',
            'condition' => ['privacy' => 'yes'],
        ]);

        $this->add_control('privacy_linking_text', [
            'label'     => __('Privacy Link Text', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['privacy_linking_text']) ? $settings['privacy_linking_text'] : '',
            'condition' => ['privacy' => 'yes'],
        ]);

        $this->add_control('terms', [
            'label'   => __('Display Terms & Conditions', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['terms']) ? $settings['terms'] : '',
        ]);

        $this->add_control('terms_label', [
            'label'     => __('Terms Label', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['terms_label']) ? $settings['terms_label'] : '',
            'condition' => ['terms' => 'yes'],
        ]);

        $this->add_control('terms_linking_text', [
            'label'     => __('Terms Link Text', 'addonskit-for-elementor'),
            'type'      => Controls_Manager::TEXT,
            'default'   => isset($settings['terms_linking_text']) ? $settings['terms_linking_text'] : '',
            'condition' => ['terms' => 'yes'],
        ]);

        $this->add_control('signup_button_label', [
            'label'   => __('Sign Up Button Label', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signup_button_label']) ? $settings['signup_button_label'] : '',
        ]);

        $this->add_control('signin_message', [
            'label'   => __('Sign In Message', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signin_message']) ? $settings['signin_message'] : '',
        ]);

        $this->add_control('signin_linking_text', [
            'label'   => __('Sign In Link Text', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::TEXT,
            'default' => isset($settings['signin_linking_text']) ? $settings['signin_linking_text'] : '',
        ]);

        $this->add_control('signin_after_signup', [
            'label'   => __('Auto Sign In After Registration', 'addonskit-for-elementor'),
            'type'    => Controls_Manager::SWITCHER,
            'default' => isset($settings['signin_after_signup']) ? $settings['signin_after_signup'] : '',
        ]);

        $this->end_controls_section();
    }

    protected function register_styles(): void {
        $this->register_container_style_controls(
            __( 'Form Container', 'addonskit-for-elementor' ),
            'directorist_login',
            '.directorist-authentication__form'
        );

        $this->register_text_controls( __( 'Fields Label', 'addonskit-for-elementor' ), 'login_form_label', '.directorist-form-group label' );
        
        $this->register_form_fields_separator_controls( __( 'Fields Separator', 'addonskit-for-elementor' ), 'login_form_fields_separator', '.directorist-form-element' );
        
        $this->register_account_text_controls( __( 'Text', 'addonskit-for-elementor' ), 'account_form_text' );


        $this->register_button_2_style_controls( __( 'Button', 'addonskit-for-elementor' ), 'login_button', '.directorist-authentication__form__btn' );
    }

    protected function render(): void {
        $settings = $this->get_settings();

        // Verify nonce for request parameters
        if ( isset( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'directorist_signin_signup' ) )  {
            // Nonce verification failed
            $settings['user_type'] = ! empty( $_REQUEST['user_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['user_type'] ) ) : '';
        } else {
            $settings['user_type'] = '';
        }

        Helper::run_shortcode( 'directorist_signin_signup', $settings );
    }
}
