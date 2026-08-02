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

class FieldsUrl extends Tags {
    public function get_name() {
        return 'fields_url';
    }

    public function get_title() {
        return esc_html__( 'Listing Fields', 'addonskit-for-elementor' );
    }

    public function get_group() {
        return 'directorist';
    }

    public function get_categories() {
        return [
            Module::URL_CATEGORY,
        ];
    }

    protected function register_controls(): void {
        $this->add_control(
            "field",
            [
                'label'  => esc_html__( 'Select Field', 'addonskit-for-elementor' ),
                'type'   => Controls_Manager::SELECT,
                'groups' => DirectoristHelper::get_custom_group_fields(),
            ]
        );
    }

    public function render(): void {
        $settings = $this->get_settings();

        if ( 'file' === $settings['field'] ) {
            ob_start();
            DirectoristHelper::get_single_listing_fields( $settings['field'] );
            $value = ob_get_clean();
            $done  = str_replace( '|||', '', $value );

            echo esc_url($done);
        } else {
            DirectoristHelper::get_single_listing_fields( $settings['field'] );
        }
    }
}