<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AuthorProfile;

use AddonskitForElementor\Elements\AllListings\Styles as AllListingsStyles;
use AddonskitForElementor\Elements\Common\DirectoryTypeStyles;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use Elementor\Controls_Manager;
use AddonskitForElementor\Utils\Helper;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuthorProfile extends Widget_Base {
    use Styles;
    use Container;
    use TextControls;
    use AllListingsStyles;
    use DirectoryTypeStyles;

    public function get_name() {
        return 'directorist_author_profile';
    }

    public function get_title() {
        return __( 'Author Profile', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['directorist-widgets'];
    }

    public function get_keywords() {
        return [
            'all-authors', 'author', 'profile', 'owner',
        ];
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {
        $this->start_controls_section(
            'sec_general',
            [
                'label' => __( 'General', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'user',
            [
                'label'     => __( 'Only For Logged In User?', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::SWITCHER,
                'label_on'  => esc_html__( 'Yes', 'addonskit-for-elementor' ),
                'label_off' => esc_html__( 'No', 'addonskit-for-elementor' ),
                'default'   => 'no',
                'separator' => 'before',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles(): void {

        $this->register_container_style_controls( __( 'Header: Container', 'addonskit-for-elementor' ), 'profile_area', '.directorist-author-profile__wrap' );

        $this->register_author_profile_info();

        $this->register_container_style_controls( __( 'Header: Info Container', 'addonskit-for-elementor' ), 'profile_review_listings_area', '.directorist-author-profile__meta-list__item' );

        $this->register_profile_info_controls( __( 'Header: Info', 'addonskit-for-elementor' ), 'profile_review_listings_icon', '.directorist-author-profile__meta-list' );

        $this->register_container_style_controls( __( 'Contact Info: Container', 'addonskit-for-elementor' ), 'profile_contact_info', '.directorist-author-contact' );

        $this->register_text_controls( __( 'Contact Info: Title', 'addonskit-for-elementor' ), 'contact_info', '.directorist-author-contact .directorist-card__header__title' );

        $this->register_author_contact_style_controls();
        $this->register_social_accounts_style_controls();

        $this->register_container_style_controls( __( 'About: Container', 'addonskit-for-elementor' ), 'profile_about', '.directorist-author-about' );

        $this->register_author_title_description();

        /**
         * All Listings - Controls
         */
        $this->register_text_controls( __( 'Listings Section Title', 'addonskit-for-elementor' ), 'author_listings_title', '.directorist-author-listing-top__title' );

        $this->register_container_style_controls( __( 'Type Container', 'addonskit-for-elementor' ), 'directory_type_container', '.directorist-author-listing-top__filter' );

        $this->register_directory_type_style_controls( '.directorist-type-nav__list .directorist-type-nav__link', [], '.directorist-type-nav__list__current .directorist-type-nav__link' );

        $this->register_text_controls( __( 'Listings: Filter', 'addonskit-for-elementor' ), 'listings_filter', '#directorist-dropdown-menu-link' );

        $this->register_listing_card_info();
        $this->register_listing_footer();

        $this->register_container_style_controls( __( 'Pagination Container', 'addonskit-for-elementor' ), 'author_pagination_container', '.directorist-pagination' );

        $this->register_all_listing_pagination();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();

        $atts = [];

        $atts['logged_in_user_only'] = $settings['user'] ?? 'no';

        /**
         * Filters the Elementor Author Profile atts to modify or extend it
         *
         * @since 1.0.0
         *
         * @param array     $atts       Available atts in the widgets
         * @param array     $settings   All the settings of the widget
         */
        $atts = apply_filters( 'directorist_author_profile_elementor_widget_atts', $atts, $settings );

        Helper::run_shortcode( 'directorist_author_profile', $atts );
    }
}