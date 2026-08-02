<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\RelatedListings;

use AddonskitForElementor\Elements\AllListings\Styles;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Utils\DirectoristHelper;
use AddonskitForElementor\Utils\Helper;
use Directorist\Directorist_Single_Listing;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RelatedListings extends Widget_Base {

    use Styles;
    use Container;
    use TextControls;

    public function get_name() {
        return 'directorist_single_listing_related_listings';
    }

    public function get_title() {
        return __( 'Listing - Related Listings', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'Related Listings', 'similar', 'same', 'similar listing', 'relevant listing',
        ];
    }

    public function show_in_panel() {
        return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
    }

    public function get_script_depends() {
        return ['directorist-listing-slider', 'directorist-swiper'];
    }

    protected function register_controls(): void {
        $this->register_contents();
        $this->register_styles();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Listing - Related Listings', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will display similar listings on a slider.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles(): void {
        $this->register_container_style_controls( __( 'Listing: Card', 'addonskit-for-elementor' ), 'related_listing', '.directorist-listing-single--bg' );
        $this->register_listing_card_info();
        $this->register_listing_footer();
    }

    public function render(): void {

        $single = Directorist_Single_Listing::instance( get_the_ID() );

        $args = [
            'listing'     => $single,
            'type'        => 'section',
            'widget_name' => 'related_listings',
            'label'       => '',
        ];

        if ( Helper::is_edit() ) {
            $this->get_script_depends();
        }

        DirectoristHelper::get_single_listing_other_fields( $args );
    }
}