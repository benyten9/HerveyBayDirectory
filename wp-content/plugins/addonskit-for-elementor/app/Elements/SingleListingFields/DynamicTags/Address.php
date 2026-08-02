<?php
/**
 * @author  WpWax
 * @since   1.0.0
 * @version 1.0.0
 */

namespace AddonskitForElementor\Elements\SingleListingFields\DynamicTags;

use Elementor\Core\DynamicTags\Tag as Tags;
use Elementor\Modules\DynamicTags\Module;

class Address extends Tags {
    public function get_name() {
        return 'address';
    }

    public function get_title() {
        return esc_html__( 'Listing Address', 'addonskit-for-elementor' );
    }

    public function get_group() {
        return 'directorist';
    }

    public function get_categories() {
        return [
            Module::POST_META_CATEGORY,
        ];
    }

    public function render() {
        $address = get_post_meta( get_the_ID(), '_address', true );
        echo esc_html( $address );
    }
}