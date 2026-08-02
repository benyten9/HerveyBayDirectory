<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AuthorProfile;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;
use AddonskitForElementor\Elements\Common\TextControls;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {

    use TextControls;
    
    protected function register_author_profile_info() {

        $selector = '.directorist-author-profile__avatar img';

        $this->start_controls_section(
            'section_author_image_style',
            [
                'label' => esc_html__( 'Header: Image & Text', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "author_profile_image",
            [
                'label' => __( 'Image', 'addonskit-for-elementor' ),
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_responsive_control(
            'author_image_size',
            [
                'label'      => __( 'Image Size', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em'],
                'range'      => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 200,
                        'step' => 1,
                    ],
                    'em' => [
                        'min'  => 0,
                        'max'  => 5,
                        'step' => .5,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};  max-width: 200px;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'author_image_border',
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            'author_image_border_radius',
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'author_image_button_box_shadow',
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            'author_image_text_padding',
            [
                'label'      => esc_html__( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->register_text_fields( __( 'Profile: Name', 'addonskit-for-elementor' ), 'profile_name', '.directorist-author-profile__avatar__info .directorist-author-profile__avatar__info__name' );

        $this->register_text_fields( __( 'Profile: Registered', 'addonskit-for-elementor' ), 'profile_registered', '.directorist-author-profile__avatar__info p' );

        $this->end_controls_section();
    }

    protected function register_author_title_description() {

        $this->start_controls_section(
            'section_author_title_description_style',
            [
                'label' => esc_html__( 'About: Container', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->register_text_fields( __( 'Title', 'addonskit-for-elementor' ), 'about_title', '.directorist-author-about .directorist-card__header__title' );

        $this->register_text_fields( __( 'Description', 'addonskit-for-elementor' ), 'about_description', '.directorist-author-about .directorist-author-about__content p' );

        $this->end_controls_section();
    }

    protected function register_author_contact_style_controls(): void {
        $prefix   = 'author_contact_info';
        $selector = '.directorist-author-info-list .directorist-author-info-list__item';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => __( 'Contact Info: Text', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            "section_{$prefix}_icon_size",
            [
                'label'      => __( 'Icon Size', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em'],
                'range'      => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 50,
                        'step' => 1,
                    ],
                    'em' => [
                        'min'  => 0,
                        'max'  => 5,
                        'step' => .5,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}} !important',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'global'    => [
                ],
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                              => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} a"                            => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} *"                            => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_typography",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->end_controls_section();
    }

    protected function register_social_accounts_style_controls(): void {
        $prefix   = 'author_social_icons';
        $selector = '.directorist-author-social .directorist-author-social__item';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => __( 'Contact Info: Socials', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "{$prefix}_icon_color",
            [
                'label'   => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'    => Controls_Manager::SELECT,
                'default' => 'default',
                'options' => [
                    'default' => esc_html__( 'Default Color', 'addonskit-for-elementor' ),
                    'custom'  => esc_html__( 'Custom', 'addonskit-for-elementor' ),
                ],
            ]
        );

        $this->add_control(
            "{$prefix}__primary_color",
            [
                'label'     => esc_html__( 'Primary Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'condition' => [
                    "{$prefix}_icon_color" => 'custom',
                ],
                'selectors' => [
                    "{{WRAPPER}} {$selector} a" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_secondary_color",
            [
                'label'     => esc_html__( 'Secondary Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'condition' => [
                    "{$prefix}_icon_color" => 'custom',
                ],
                'selectors' => [
                    '{{WRAPPER}} a .directorist-icon-mask:after' => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            "{$prefix}__size",
            [
                'label'      => esc_html__( 'Size', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                // The `%' and `em` units are not supported as the widget implements icons differently then other icons.
                'size_units' => ['px', 'rem', 'vw', 'custom'],
                'range'      => [
                    'px' => [
                        'min' => 10,
                        'max' => 50,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'height: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            "{$prefix}__padding",
            [
                'label'          => esc_html__( 'Padding', 'addonskit-for-elementor' ),
                'type'           => Controls_Manager::SLIDER,
                'selectors'      => [
                    "{{WRAPPER}} {$selector} a" => 'padding: {{SIZE}}{{UNIT}}',
                ],
                'default'        => [
                    'unit' => 'em',
                ],
                'tablet_default' => [
                    'unit' => 'em',
                ],
                'mobile_default' => [
                    'unit' => 'em',
                ],
                'range'          => [
                    'em' => [
                        'min' => 0,
                        'max' => 5,
                    ],
                ],
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_spacing",
            [
                'label'     => esc_html__( 'Spacing', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::SLIDER,
                'range'     => [
                    'px' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'default'   => [
                    'size' => 5,
                ],
                'selectors' => [
                    '{{WRAPPER}} .directorist-author-social ' => 'gap: {{SIZE}}{{UNIT}}',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'      => "{$prefix}_border", // We know this mistake - TODO: 'icon_border' (for hover control condition also)
                'selector'  => "{{WRAPPER}} {$selector} a",
                'separator' => 'before',
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_radius",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} a" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_hover_options",
            [
                'label'     => esc_html__( 'Hover', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
                'condition' => [
                    "{$prefix}_icon_color" => 'custom',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_hover_primary_color",
            [
                'label'     => esc_html__( 'Primary Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'condition' => [
                    "{$prefix}_icon_color" => 'custom',
                ],
                'selectors' => [
                    "{{WRAPPER}} {$selector}:hover a" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_hover_secondary_color",
            [
                'label'     => esc_html__( 'Secondary Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'condition' => [
                    "{$prefix}_icon_color" => 'custom',
                ],
                'selectors' => [
                    "{{WRAPPER}} {$selector}:hover a .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
                ],
            ]
        );
        $this->end_controls_section();
    }

    protected function register_profile_info_controls( string $section_label = '', string $prefix = '', string $selector = '' ): void {

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label' => $section_label,
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            "{$prefix}_icon",
            [
                'label' => __( 'Icon', 'addonskit-for-elementor' ),
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            "{$prefix}__primary_color",
            [
                'label'     => esc_html__( 'Icon BG Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} i.directorist-icon-mask" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_secondary_color",
            [
                'label'     => esc_html__( 'Icon Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} i.directorist-icon-mask:after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            "{$prefix}__size",
            [
                'label'      => esc_html__( 'Icon Size', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::SLIDER,
                // The `%' and `em` units are not supported as the widget implements icons differently then other icons.
                'size_units' => ['px', 'rem', 'vw', 'custom'],
                'range'      => [
                    'px' => [
                        'min' => 10,
                        'max' => 50,
                    ],
                ],
                'selectors'  => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'height: {{SIZE}}{{UNIT}}; width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->register_text_fields( __( 'Text', 'addonskit-for-elementor' ), 'profile_review_listings_count', '.directorist-author-profile__meta-list__item span' );

        $this->end_controls_section();
    }
}