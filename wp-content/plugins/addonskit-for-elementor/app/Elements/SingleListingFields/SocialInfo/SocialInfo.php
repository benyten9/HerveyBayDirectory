<?php
/**
 * Author: WpWax
 * Since: 1.0.0
 * Version: 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\SocialInfo;

use AddonskitForElementor\Utils\DirectoristHelper;
use AddonskitForElementor\Utils\Helper;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SocialInfo extends Widget_Base {

    use Styles;

    public function get_name() {
        return 'directorist_single_listing_socialinfo';
    }

    public function get_title() {
        return __( 'Listing - Social Info', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['theme-elements-single'];
    }

    public function get_keywords() {
        return [
            'socialinfo', 'social', 'info', 'facebook', 'twitter',
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
                'label' => esc_html__( 'Social Info', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'important_note',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div id="elementor-panel-elements-notice-area">
								<div id="elementor-panel-notice-wrapper">
									<div class="elementor-panel-notice elementor-panel-alert elementor-panel-info-info">
										<strong>'. esc_html__( 'This widget will show the listing social icons.', 'addonskit-for-elementor' ) . '</strong>
									</div>
								</div>
							</div>',
            ]
        );

        $this->add_responsive_control(
			'action_type_align',
			[
				'label'     => esc_html__( 'Alignment', 'addonskit-for-elementor' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .directorist-social-links' => 'justify-content: {{VALUE}};',
				],
				'default'   => '',
			]
		);

        $this->end_controls_section();
    }

    protected function register_styles(): void {
        $this->register_icon_style();
    }

    public function render(): void {
        if ( Helper::is_edit() ) {
            $social = get_post_meta( get_the_ID(), '_social', true );
            if ( empty( $social ) ) {
                printf(
                    wp_kses(
                        '<div class="elementor-alert elementor-alert-info" role="alert">
                            <span class="elementor-alert-title">%s</span>
                            <span class="elementor-alert-description">%s</span>
                        </div>',
                        [
                            'div'  => ['class' => [], 'role' => []],
                            'span' => ['class' => []]
                        ]
                    ),
                    esc_html__( 'Nothing to found!', 'addonskit-for-elementor' ),
                    esc_html__( 'There is no social item in the current listing.', 'addonskit-for-elementor' )
                );
            }
        }
        
        DirectoristHelper::get_single_listing_fields( 'social_info' );
    }
}
