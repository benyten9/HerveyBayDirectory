<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Map;

use AddonskitForElementor\Utils\DirectoristHelper;
use AddonskitForElementor\Utils\Helper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Map extends Widget_Base {
    public function get_name() {
        return 'directorist_single_listing_map';
    }

    public function get_title() {
        return __( 'Listing - Map', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_script_depends() {
        $map_type = get_directorist_option( 'select_listing_map', 'openstreet' );

        if ( 'openstreet' === $map_type ) {
            $script = ['directorist-openstreet-map'];
        } else {
            $script = ['directorist-google-map'];
        }

        return $script;
    }

    public function get_keywords() {
        return ['map'];
    }

    public function show_in_panel() {
        return true;
        // return is_singular( ATBDP_POST_TYPE ) || is_singular( 'elementor_library' );
    }

    protected function register_controls(): void {
        $this->register_contents();
    }

    protected function register_contents(): void {

        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__( 'Map', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will show the listing address on a map.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->end_controls_section();
    }

    public function render(): void {
        if ( Helper::is_edit() ) {
            $this->get_script_depends();
        }

        DirectoristHelper::get_single_listing_fields('map');
    }
}