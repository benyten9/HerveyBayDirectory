<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\AllAuthors;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use AddonskitForElementor\Elements\Common\Button;
use AddonskitForElementor\Elements\Common\Container;
use AddonskitForElementor\Elements\Common\TextControls;
use AddonskitForElementor\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AllAuthors extends Widget_Base {

    use TextControls;
    use Container;
    use Button;
    use Styles;

    public function get_name() {
        return 'directorist_all_authors';
    }

    public function get_title() {
        return __( 'All Authors', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['directorist-widgets'];
    }

    public function get_keywords() {
        return [
            'authors', 'users', 'all authors',
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
            'sec_heading',
            [
                'label'     => __( 'This widget works only in All Authors page. It has no additional elementor settings.', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );

        $this->end_controls_section();
    }

    protected function register_styles(): void {

        $this->register_container_style_controls( __( 'Header: Container', 'addonskit-for-elementor' ), 'alphabet_container', '.directorist-authors__nav' );
        $this->register_button1_style( __( 'Header: Style', 'addonskit-for-elementor' ), 'alphabet_style', '.directorist-authors__nav li a', '.directorist-authors__nav li.active a' );
        $this->register_container_style_controls( __( 'Card: Container', 'addonskit-for-elementor' ), 'card_container', '.directorist-authors__card' );
        $this->register_author_image_style_controls();
        $this->register_text_controls( __( 'Card: Author Name', 'addonskit-for-elementor' ), 'author_name', '.directorist-authors__card h2' );
        $this->register_author_contact_style_controls();
        $this->register_text_controls( __( 'Card: Author Bio', 'addonskit-for-elementor' ), 'author_bio', '.directorist-authors__card p' );
        $this->register_social_accounts_style_controls();
        $this->register_button1_style( __( 'Card: Button', 'addonskit-for-elementor' ), 'card_button_style', '.directorist-authors__card .directorist-btn' );
    }

    protected function render(): void {
        Helper::run_shortcode( 'directorist_all_authors' );
    }
}
