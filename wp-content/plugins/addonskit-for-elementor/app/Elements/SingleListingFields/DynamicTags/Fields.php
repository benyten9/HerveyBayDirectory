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

class Fields extends Tags {
    public function get_name() {
        return 'fields';
    }

    public function get_title() {
        return esc_html__( 'Listing Fields', 'addonskit-for-elementor' );
    }

    public function get_group() {
        return 'directorist';
    }

    public function get_categories() {
        return [
            Module::TEXT_CATEGORY,
        ];
    }

    protected function register_controls(): void {
        $this->add_control(
            "field",
            [
                'label'  => esc_html__( 'Select Field', 'addonskit-for-elementor' ),
                'type'   => Controls_Manager::SELECT,
                'groups' => DirectoristHelper::get_group_fields(),
            ]
        );
    }

    public function render(): void {
        $settings = $this->get_settings();
        DirectoristHelper::get_single_listing_fields( $settings['field'], get_the_ID() );
    }
}