<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class BuilderFormFields implements Provider {
    public function boot() {
        add_filter( 'atbdp_form_preset_widgets', [$this, 'atbdp_form_builder_widgets'] );
    }

    public function atbdp_form_builder_widgets( $widgets ) {
        if ( ! is_array( $widgets ) ) {
            return $widgets;
        }

        $widgets['listing-type'] = [
            'label'   => 'Listing Type',
            'icon'    => 'la la-toggle-on',
            'show'    => true,
            'options' => [
                'type'           => [
                    'type'  => 'hidden',
                    'value' => 'checkbox',
                ],
                'field_key'      => [
                    'type'  => 'hidden',
                    'value' => 'listing_type',
                ],
                'label'          => [
                    'type'  => 'text',
                    'label' => 'Label',
                    'value' => 'Featured',
                ],
                'featured_label' => [
                    'type'  => 'text',
                    'label' => 'Featured label',
                    'value' => 'Mark as featured',
                ],
            ],
        ];
        
        return $widgets;
    }
}