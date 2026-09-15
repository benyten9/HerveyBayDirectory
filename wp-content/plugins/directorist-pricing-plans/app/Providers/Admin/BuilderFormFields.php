<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class BuilderFormFields implements Provider {
    public function boot() {
        add_filter( 'atbdp_form_preset_widgets', [$this, 'atbdp_form_builder_widgets'], 100 );
        add_filter( 'directorist_localized_data', [$this, 'directorist_localized_data'], 100 );
        add_filter( 'directorist_builder_localize_data', [$this, 'directorist_builder_localize_data'], 100 );
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

    public function directorist_localized_data( array $data ): array {
        if ( empty( $data['directory_type_term_data']['submission_form_fields'] ) || ! is_array( $data['directory_type_term_data']['submission_form_fields'] ) ) {
            return $data;
        }

        $data['directory_type_term_data']['submission_form_fields'] = $this->remove_legacy_listing_type_field(
            $data['directory_type_term_data']['submission_form_fields']
        );

        return $data;
    }

    public function directorist_builder_localize_data( array $data ): array {
        if ( empty( $data['fields']['submission_form_fields']['value'] ) || ! is_array( $data['fields']['submission_form_fields']['value'] ) ) {
            return $data;
        }

        $data['fields']['submission_form_fields']['value'] = $this->remove_legacy_listing_type_field(
            $data['fields']['submission_form_fields']['value']
        );

        return $data;
    }

    protected function remove_legacy_listing_type_field( array $form_fields ): array {
        if ( empty( $form_fields['fields']['listing_type'] ) || $this->is_pricing_listing_type_widget( $form_fields['fields']['listing_type'] ) ) {
            return $form_fields;
        }

        $has_pricing_listing_type_field = false;

        foreach ( $form_fields['fields'] as $field_key => $field ) {
            if ( 'listing_type' === $field_key || ! is_array( $field ) ) {
                continue;
            }

            if ( $this->is_pricing_listing_type_widget( $field ) && $this->is_listing_type_field( $field ) ) {
                $has_pricing_listing_type_field = true;
                break;
            }
        }

        if ( ! $has_pricing_listing_type_field ) {
            return $form_fields;
        }

        unset( $form_fields['fields']['listing_type'] );

        if ( ! empty( $form_fields['groups'] ) && is_array( $form_fields['groups'] ) ) {
            foreach ( $form_fields['groups'] as $group_key => $group ) {
                if ( empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
                    continue;
                }

                $form_fields['groups'][ $group_key ]['fields'] = array_values( array_diff( $group['fields'], [ 'listing_type' ] ) );
            }
        }

        return $form_fields;
    }

    protected function is_listing_type_field( array $field_data ): bool {
        $field_key = ! empty( $field_data['field_key'] ) ? (string) $field_data['field_key'] : '';

        return 0 === strpos( $field_key, 'listing_type' );
    }

    protected function is_pricing_listing_type_widget( array $field_data ): bool {
        $widget_name = ! empty( $field_data['widget_name'] ) ? (string) $field_data['widget_name'] : '';
        $widget_key  = ! empty( $field_data['widget_key'] ) ? (string) $field_data['widget_key'] : '';

        return in_array( 'listing-type', [ $widget_name, $widget_key ], true );
    }
}
