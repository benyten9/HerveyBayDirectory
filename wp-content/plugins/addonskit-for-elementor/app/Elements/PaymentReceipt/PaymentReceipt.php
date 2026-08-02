<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\PaymentReceipt;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use AddonskitForElementor\Utils\Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PaymentReceipt extends Widget_Base {
    public function get_name() {
        return 'directorist_payment_receipt';
    }

    public function get_title() {
        return __( 'Payment Receipt', 'addonskit-for-elementor' );
    }

    public function get_icon() {
        return 'directorist-el-custom';
    }

    public function get_categories() {
        return ['directorist-widgets'];
    }

    public function get_keywords() {
        return [
            'payment', 'checkout',
        ];
    }

    protected function register_controls(): void {
        $this->start_controls_section(
            'sec_general',
            [
                'label' => __( 'General', 'addonskit-for-elementor' ),
            ]
        );

        $this->add_control(
            'sec_heading',
            [
                'label'     => __( 'This widget works only in Payment Receipt page. It has no additional elementor settings.', 'addonskit-for-elementor' ),
                'type'      => Controls_Manager::HEADING,
                'separator' => 'before',
            ]
        );
        $this->end_controls_section();
    }

    protected function render(): void {
        Helper::run_shortcode( 'directorist_payment_receipt' );
    }
}