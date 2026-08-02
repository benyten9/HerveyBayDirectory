<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AllListings;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Text_Shadow;
use Elementor\Group_Control_Text_Stroke;
use Elementor\Group_Control_Typography;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Styles {
    protected function register_view_as_sort_by(): void {

        $view        = '.directorist-viewas .directorist-viewas__item';
        $view_active = '.directorist-viewas .directorist-viewas__item.active';
        $sort        = '.directorist-dropdown .directorist-dropdown__toggle';

        $this->start_controls_section(
            'section_view_as_sort_by_style',
            [
                'label'     => esc_html__( 'Listing View As & Sort Button', 'addonskit-for-elementor' ),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => ['header' => 'yes'],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'view_as_sort_by_typography',
                'selector' => "{{WRAPPER}} {$sort}",
                'exclude' => ['text_decoration'],
            ]
        );

        $this->start_controls_tabs( 'view_as_sort_by_tabs_normal' );

        $this->start_controls_tab(
            'view_as_sort_by_tab_normal', [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            'view_as_sort_by_color',
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'global'    => [
                ],
                'selectors' => [
                    "{{WRAPPER}} {$view}.directorist-viewas__item i::after" => 'background-color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$sort}"                                   => 'color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$sort}.directorist-toggle-has-icon:after" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'view_as_sort_by_bg_color',
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'global'    => [
                ],
                'selectors' => [
                    "{{WRAPPER}} {$view}" => 'background-color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$sort}" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'      => 'view_as_sort_by_border',
                'selector'  => "{{WRAPPER}} {$view}, {{WRAPPER}} {$sort}",
            ]
        );

        $this->add_responsive_control(
            'view_as_sort_by_border_radius',
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$view}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                    "{{WRAPPER}} {$sort}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'view_as_sort_by_button_box_shadow',
                'selector' => "{{WRAPPER}} {$view}, {{WRAPPER}} {$sort}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            'view_as_sort_by_tab_active', [
                'label' => esc_html__( 'Active', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            'view_as_sort_by_color_active',
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'global'    => [
                ],
                'selectors' => [
                    "{{WRAPPER}} {$view_active}.directorist-viewas__item i::after" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );
        
        $this->add_control(
            'view_as_sort_by_bg_color_active',
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'global'    => [
                ],
                'selectors' => [
                    "{{WRAPPER}} {$view_active}" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'      => 'view_as_sort_by_border_active',
                'selector'  => "{{WRAPPER}} {$view_active}",
            ]
        );

        $this->add_responsive_control(
            'view_as_sort_by_border_radius_active',
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$view_active}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'view_as_sort_by_button_box_shadow_active',
                'selector' => "{{WRAPPER}} {$view_active}",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function register_listing_footer(): void {
        $selector = '.directorist-listing-single .directorist-listing-single__meta';

        $this->start_controls_section(
            'section_listing_info_meta_style',
            [
                'label' => esc_html__( 'Listing: Card Footer', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'listing_info_meta_icon_color',
            [
                'label'     => esc_html__( 'Icon Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_responsive_control(
            'listing_info_meta_icon_size',
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
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
                ],
            ]
        );

        $this->add_control(
            'listing_info_meta_color',
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} .directorist-listing-single__meta__left, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right,{{WRAPPER}} {$selector} .directorist-listing-single__meta__left a, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right a,
                    {{WRAPPER}} {$selector} .directorist-listing-single__meta__left span, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right span" => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'listing_info_meta_typography',
                'selector' => "{{WRAPPER}} {$selector} .directorist-listing-single__meta__left *, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right *",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => 'listing_info_meta_text_stroke',
                'selector' => "{{WRAPPER}} {$selector} .directorist-listing-single__meta__left *, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right *",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => 'listing_info_meta_text_shadow',
                'selector' => "{{WRAPPER}} {$selector} .directorist-listing-single__meta__left *, {{WRAPPER}} {$selector} .directorist-listing-single__meta__right *",
            ]
        );

        $this->end_controls_section();
    }

    protected function register_filters_button( $conditions = [], $prefix = '' ) {

        $selector = '.directorist-listings-header__left .directorist-filter-btn';
        $prefix   = 'filters';

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label'     => __( 'Filters Button', 'addonskit-for-elementor' ),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => $conditions,
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_typography",
                'selector' => "{{WRAPPER}} {$selector}",
                'exclude'  => ['text_decoration'],
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_icon_size",
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
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
                ],
            ]
        );

        $this->start_controls_tabs( "tabs_{$prefix}_style" );

        $this->start_controls_tab(
            "{$prefix}_normal",
            [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}_text_color_normal",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                               => 'fill: {{VALUE}}; color: {{VALUE}};',
                    "{{WRAPPER}} {$selector}"                               => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_normal",
            [
                'label'     => esc_html__( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_normal",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_normal",
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
                'name'     => "{$prefix}_box_shadow_normal",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_button_hover",
            [
                'label' => esc_html__( 'Hover', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            "{$prefix}_hover_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}:hover, {{WRAPPER}} {$selector}:focus"         => 'color: {{VALUE}}; border-color: {{VALUE}}!important',
                    "{{WRAPPER}} {$selector}:hover svg, {{WRAPPER}} {$selector}:focus svg" => 'fill: {{VALUE}};',
                    "{{WRAPPER}} {$selector}:hover .directorist-icon-mask::after"          => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_hover",
            [
                'label'     => esc_html__( 'Background', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}:hover" => 'background-color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$selector}:focus" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_hover",
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_hover",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}:hover" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_hover",
                'selector' => "{{WRAPPER}} {$selector}:hover",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->add_responsive_control(
            "{$prefix}_text_padding",
            [
                'label'      => esc_html__( 'Padding', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'vw', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$selector}" => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function register_all_listing_pagination( $condition = '' ): void {

        $prefix   = 'pagination';
        $selector = '.directorist-pagination';
        $normal   = "{$selector} a.page-numbers";
        $arrow    = "{$selector} a.page-numbers .directorist-icon-mask:after";
        $active   = "{$selector} span.page-numbers.current";
        $hover    = "{$selector} a.page-numbers:hover";

        $this->start_controls_section(
            "section_{$prefix}_style",
            [
                'label'     => __( 'Pagination', 'addonskit-for-elementor' ),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => $condition,
            ]
        );

        $this->start_controls_tabs( "{$prefix}_tabs_normal" );

        $this->start_controls_tab(
            "{$prefix}_tab_normal", [
                'label' => esc_html__( 'Normal', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$normal}" => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$arrow}"  => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$normal}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border",
                'selector' => "{{WRAPPER}} {$normal}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$normal}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow",
                'selector' => "{{WRAPPER}} {$normal}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_tab_active", [
                'label' => esc_html__( 'Active', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            "{$prefix}_color_active",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$active}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color_active",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$active}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_active",
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_active",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$active}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_active",
                'selector' => "{{WRAPPER}} {$active}",
            ]
        );

        $this->end_controls_tab();

        $this->start_controls_tab(
            "{$prefix}_tab_hover", [
                'label' => esc_html__( 'Hover', 'addonskit-for-elementor' ),
            ] 
        );

        $this->add_control(
            "{$prefix}_color_hover",
            [
                'label'     => esc_html__( 'Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$hover}" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            "{$prefix}_background_color_hover",
            [
                'label'     => esc_html__( 'Background Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$hover}" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => "{$prefix}_border_hover",
                'selector' => "{{WRAPPER}} {$hover}",
            ]
        );

        $this->add_responsive_control(
            "{$prefix}_border_radius_hover",
            [
                'label'      => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type'       => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em', 'rem', 'custom'],
                'selectors'  => [
                    "{{WRAPPER}} {$hover}" => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => "{$prefix}_box_shadow_hover",
                'selector' => "{{WRAPPER}} {$hover}",
            ]
        );

        $this->end_controls_tab();

        $this->end_controls_tabs();

        $this->end_controls_section();
    }

    protected function register_listing_card_info(): void {

        $prefix     = 'listing_card_info';
        $selector   = '.directorist-listing-single .directorist-listing-single__info .directorist-listing-single__info__top';
        $no_preview = '.directorist-listing-no-thumb .directorist-listing-single__info';

        $this->start_controls_section(
            "section{$prefix}_style",
            [
                'label' => esc_html__( 'Listing: Card Settings', 'addonskit-for-elementor' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->register_text_fields( __( 'Title', 'addonskit-for-elementor' ), 'listing_title', '.directorist-listing-single .directorist-listing-title a' );

        $this->add_control(
            "{$prefix}_top",
            [
                'label' => __( 'Top Info', 'addonskit-for-elementor' ),
                'type'  => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            "{$prefix}_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} *"   => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$no_preview} *" => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_typography",
                'selector' => "{{WRAPPER}} {$selector} span",
                // 'selector' => "{{WRAPPER}} {$no_preview} span",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => "{$prefix}_text_stroke",
                'selector' => "{{WRAPPER}} {$selector} span",
                // 'selector' => "{{WRAPPER}} {$no_preview}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => "{$prefix}_text_shadow",
                'selector' => "{{WRAPPER}} {$selector} span",
                // 'selector' => "{{WRAPPER}} {$no_preview}",
            ]
        );

        $this->register_listing_card_info_bottom();

        $this->end_controls_section();
    }

    protected function register_listing_card_info_bottom(): void {
        $selector   = '.directorist-listing-single .directorist-listing-single__info .directorist-listing-single__info__list';
        $no_preview = '.directorist-listing-single .directorist-listing-single__content .directorist-listing-single__info__list';

        $this->add_control(
            "listing_card_info_bottom_top",
            [
                'label'     => __( 'Bottom Info', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            'listing_info_item_list_icon_color',
            [
                'label'     => esc_html__( 'Icon Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after"   => 'background-color: {{VALUE}} !important;',
                    "{{WRAPPER}} {$no_preview} .directorist-icon-mask::after" => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_responsive_control(
            'listing_info_item_list_icon_size',
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
                    "{{WRAPPER}} {$selector} .directorist-icon-mask::after"   => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
                    "{{WRAPPER}} {$no_preview} .directorist-icon-mask::after" => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}}',
                ],
            ]
        );

        $this->add_control(
            'listing_info_item_list_color',
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}"                                => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$selector} .directorist-icon-mask:after"   => 'background-color: {{VALUE}};',
                    "{{WRAPPER}} {$no_preview} *"                            => 'color: {{VALUE}};',
                    "{{WRAPPER}} {$no_preview} .directorist-icon-mask:after" => 'background-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'listing_info_item_list_typography',
                'selector' => ".directorist-content-active {{WRAPPER}} {$selector} li *, .directorist-content-active {{WRAPPER}} {$no_preview}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Stroke::get_type(),
            [
                'name'     => 'listing_info_item_list_text_stroke',
                'selector' => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$no_preview}",
            ]
        );

        $this->add_group_control(
            Group_Control_Text_Shadow::get_type(),
            [
                'name'     => 'listing_info_item_list_text_shadow',
                'selector' => "{{WRAPPER}} {$selector}, {{WRAPPER}} {$no_preview}",
            ]
        );
    }

    protected function register_all_listings_form_fields_controls( string $section_label = '', string $prefix = '', string $selector = '', $condition = '' ): void {

        $this->start_controls_section(
            "{$prefix}_style",
            [
                'label' => $section_label,
                'tab'   => Controls_Manager::TAB_STYLE,
                'condition' => $condition,
            ]
        );

        $this->add_control(
            "{$prefix}_label",
            [
                'label'     => __( 'Field Label', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
            ]
        );

        $this->add_control(
            "{$prefix}_label_color",
            [
                'label'     => esc_html__( 'Text Color', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    "{{WRAPPER}} {$selector}" => 'color: {{VALUE}}',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => "{$prefix}_label_typography",
                'selector' => "{{WRAPPER}} {$selector}",
            ]
        );
        

        $this->end_controls_section();
    }
}