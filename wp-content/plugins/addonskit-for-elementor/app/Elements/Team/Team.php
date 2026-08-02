<?php
/**
 * @author  wpWax
 * @since   1.0
 * @version 1.0
 */

namespace AddonskitForElementor\Elements\Team;

use AddonskitForElementor\Elements\Elements;
use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Team extends Widget_Base {

    public function get_name() {
        return 'akfe_team';
    }

    public function get_title() {
        return __( 'Team Members', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['general'];
    }

    public function get_keywords() {
        return [
            'team', 'member',
        ];
    }

    protected function register_controls(): void {
        $this->register_contents();
        //$this->register_styles();
    }

    public function register_contents(): void {

        $this->start_controls_section(
            'sec_general',
            [
                'label' => __( 'Team Members', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'team_image',
            [
                'type' => Controls_Manager::MEDIA,
                'label' => esc_html__( 'Image', 'addonskit-for-elementor' ),
                'default' => [
                    'url' => \Elementor\Utils::get_placeholder_image_src(),
                ],
            ]
        );

        $this->add_control(
            'image_size',
            [
                'label' => esc_html__( 'Max Width', 'addonskit-for-elementor' ),
                'type' => Controls_Manager::SLIDER,
                'size_units' => ['px', '%'],
                'range' => [
                    'px' => [
                        'min' => 0,
                        'max' => 1000,
                        'step' => 5,
                    ],
                    '%' => [
                        'min' => 0,
                        'max' => 100,
                    ],
                ],
                'default' => [
                    'unit' => '%',
                    'size' => 100,
                ],
                'selectors' => [
                    '{{WRAPPER}} img' => 'max-width: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'image_border_radius',
            [
                'label' => esc_html__( 'Border Radius', 'addonskit-for-elementor' ),
                'type' => Controls_Manager::DIMENSIONS,
                'size_units' => ['px', '%', 'em'],
                'selectors' => [
                    '{{WRAPPER}} img' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ]
        );

        $this->add_control(
            'name',
            [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Name', 'addonskit-for-elementor' ),
                'default' => __( 'John Doe', 'addonskit-for-elementor' ),
            ]
        );
        $this->add_control(
            'designation',
            [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Designation', 'addonskit-for-elementor' ),
                'default' => __( 'Founder & CEO', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'facebook', [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Facebook', 'addonskit-for-elementor' ),
            ] );
        $this->add_control(
            'twitter', [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Twitter', 'addonskit-for-elementor' ),
            ] );
        $this->add_control(
            'youtube', [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Youtube', 'addonskit-for-elementor' ),
            ] );
        $this->add_control(
            'instagram', [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Instagram', 'addonskit-for-elementor' ),
            ] );
        $this->add_control(
            'pinterest', [
                'type' => Controls_Manager::TEXT,
                'label' => esc_html__( 'Pinterest', 'addonskit-for-elementor' ),
            ] );

        $this->add_control(
            'content_alignment', [
                'type' => Controls_Manager::CHOOSE,
                'label' => esc_html__( 'Alignment', 'addonskit-for-elementor' ),
                'options' => [
                    'left' => [
                        'title' => esc_html__( 'Left', 'addonskit-for-elementor' ),
                        'icon' => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => esc_html__( 'Center', 'addonskit-for-elementor' ),
                        'icon' => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => esc_html__( 'Right', 'addonskit-for-elementor' ),
                        'icon' => 'eicon-text-align-right',
                    ],
                ],
                'default' => 'center',
                'toggle' => true,
                'selectors' => [
                    '{{WRAPPER}} .theme-team-single__img' => 'text-align: {{VALUE}}',
                    '{{WRAPPER}} figcaption' => 'text-align: {{VALUE}}',
                ],
            ] );

        $this->end_controls_section();
    }

    protected function render() {
        $data = $this->get_settings();

        $template = 'Team/view';

        return Elements::wpwax_template( $template, $data );
    }
}