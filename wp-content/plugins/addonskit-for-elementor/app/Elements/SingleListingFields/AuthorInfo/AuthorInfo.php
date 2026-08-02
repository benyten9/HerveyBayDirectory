<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\AuthorInfo;

use AddonskitForElementor\Elements\Common\Button;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Elements\SingleListingFields\SocialInfo\Styles as SocialInfoStyle;
use AddonskitForElementor\Utils\DirectoristHelper;
use Directorist\Directorist_Single_Listing;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AuthorInfo extends Widget_Base {

    use Styles;
    use SocialInfoStyle;
    use Button;
    use Container;
    use TextControls;

    public function get_name() {
        return 'directorist_single_listing_author_info';
    }

    public function get_title() {
        return __( 'Listing - Author Info', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'author', 'info', 'listing author', 'owner', 'owner info',
        ];
    }

    public function show_in_panel() {
        return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Author Info', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will show the listing author information\'s.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->add_control(
            'label',
            [
                'label'   => __( 'Section Title', 'addonskit-for-elementor' ),
                'type'    => Controls_Manager::TEXT,
                'default' => __( 'Author Info', 'addonskit-for-elementor' ),
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles() {

        $this->register_container_style_controls( __( 'Container', 'addonskit-for-elementor' ), 'profile_area', '.directorist-single-wrapper .directorist-card' );
        $this->register_text_controls( __( 'Section label', 'addonskit-for-elementor' ), 'author_info_section_title', '.directorist-card__header__title' );
        $this->register_author_profile_info();
        $this->register_author_contact_style_controls();
        $this->register_icon_style( __( 'Author Social', 'addonskit-for-elementor' ), 'author_social_info', '.directorist-author-social-item a' );
        $this->register_button2_style( __( 'View Profile Button', 'addonskit-for-elementor' ), 'view_profile_style', '.directorist-btn.directorist-btn-light' );
    }

    public function render(): void {
        $settings = $this->get_settings();
        $single   = Directorist_Single_Listing::instance( get_the_ID() );

        $args = [
            'listing'     => $single,
            'type'        => 'section',
            'widget_name' => 'author_info',
            'label'       => $settings['label'],
        ];

        DirectoristHelper::get_single_listing_other_fields($args);
    }
}