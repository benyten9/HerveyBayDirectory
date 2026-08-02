<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\DynamicTags;

use AddonskitForElementor\Utils\DirectoristHelper;
use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag as Tags;
use Elementor\Modules\DynamicTags\Module;

class FieldColor extends Tags {
    public function get_name() {
        return 'field_color';
    }

    public function get_title() {
        return esc_html__( 'Listing Color', 'addonskit-for-elementor' );
    }

    public function get_group() {
        return 'directorist';
    }

    public function get_categories() {
        return [
            Module::COLOR_CATEGORY,
        ];
    }

    protected function register_controls(): void {
        $this->add_control(
            "field",
            [
                'label'  => esc_html__( 'Select Color Field', 'addonskit-for-elementor' ),
                'type'   => Controls_Manager::SELECT,
                'groups' => DirectoristHelper::get_custom_color_group_fields(),
            ]
        );
    }

    public function render(): void {
        $settings = $this->get_settings();
        DirectoristHelper::get_single_listing_fields( $settings['field'], get_the_ID() );
    }
}