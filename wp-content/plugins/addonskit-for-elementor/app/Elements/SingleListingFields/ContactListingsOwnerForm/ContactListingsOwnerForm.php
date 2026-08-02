<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\ContactListingsOwnerForm;

use AddonskitForElementor\Elements\AddListing\Styles;
use AddonskitForElementor\Elements\Common\Button;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Utils\DirectoristHelper;
use Directorist\Directorist_Single_Listing;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ContactListingsOwnerForm extends Widget_Base {

    use Button;
    use Styles;
    use Container;
    use TextControls;

    public function get_name() {
        return 'directorist_single_listing_contact_listings_owner_form';
    }

    public function get_title() {
        return __( 'Listing - Owner Contact Form', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'Owner Contact Form', 'contact form', 'contact', 'reach', 'find',
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
                'label' => esc_html__( 'Form', 'addonskit-for-elementor' ),
            ]
        );
        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will display the listing owner\'s contact form.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>'
            ]
        );

        $this->add_control(
            'label',
            [
                'label'   => __( 'Section Title', 'addonskit-for-elementor' ),
                'type'    => Controls_Manager::TEXT,
                'default' => __( 'Contact Listings Owner Form', 'addonskit-for-elementor' ),
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles() {

        $this->register_container_style_controls( __( 'Container', 'addonskit-for-elementor' ), 'contact_owner', '.directorist-single-wrapper .directorist-card' );
        $this->register_text_controls( __( 'Title', 'addonskit-for-elementor' ), 'author_info_section_title', '.directorist-card__header__title' );
        $this->register_add_listing_form_fields( __( 'Form: Fields', 'addonskit-for-elementor' ), 'form_fields', '.directorist-form-element::placeholder' );
        $this->register_button2_style( __( 'Button Style', 'addonskit-for-elementor' ), 'submit_button_style', '.directorist-btn.directorist-btn-light' );
    }

    public function render(): void {
        $settings = $this->get_settings();
        $single   = Directorist_Single_Listing::instance( get_the_ID() );

        $args = [
            'listing'     => $single,
            'type'        => 'section',
            'fields'        => '',
            'widget_name' => 'contact_listings_owner',
            'label'       => $settings['label'],
        ];

        // $args['section_data'] = $args;

        DirectoristHelper::get_single_listing_other_fields( $args);
    }
}
