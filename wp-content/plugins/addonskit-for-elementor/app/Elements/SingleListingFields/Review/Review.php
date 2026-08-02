<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\Review;

use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Review extends Widget_Base {

    use Styles;
    use Container;
    use TextControls;

    public function get_name() {
        return 'directorist_single_listing_review';
    }

    public function get_title() {
        return __( 'Listing - Review', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'review', 'rating', 'feedback', 'rate', 'fav',
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
                'label' => esc_html__( 'Review Form', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will show the listing review form.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles() {

        //Review
        $this->register_container_style_controls( __( 'Review: Container', 'addonskit-for-elementor' ), 'single_review', '.directorist-review-container .directorist-review-content' );

        $this->register_text_controls( __( 'Review: Section Title', 'addonskit-for-elementor' ), 'single_review_title', '.directorist-review-content .directorist-card__header-text' );

        $this->register_review_button_style();

        //Review Form
        $this->register_container_style_controls( __( 'Review Form: Container', 'addonskit-for-elementor' ), 'single_review_form', '.directorist-review-container .directorist-review-submit' );

        $this->register_text_controls( __( 'Review Form: Section Title', 'addonskit-for-elementor' ), 'single_review_form_title', '.directorist-review-submit .directorist-card__header--title' );

        $this->register_submit_button_style();
    }

    public function render(): void {
        if ( ! directorist_is_review_enabled() ) {
            return;
        }

        comments_template();
    }
}
