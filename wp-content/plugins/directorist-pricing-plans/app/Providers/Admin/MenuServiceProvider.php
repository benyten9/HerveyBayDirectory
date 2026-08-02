<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Contracts\Provider;

class MenuServiceProvider implements Provider
{
    public function boot() {
        add_action( 'admin_menu', [$this, 'action_admin_menu'] );
        add_filter( 'atbdp_extension_settings_submenu', [ $this, 'add_settings_menu' ] );
        add_filter( 'atbdp_listing_type_settings_field_list', [ $this, 'add_settings_fields' ] );
        add_filter( 'atbdp_pages_settings_fields', [ $this, 'setup_page_settings_field' ] );
        add_filter( 'atbdp_monetization_settings_submenu', [ $this, 'unset_featured_settings' ] );
        // TODO: will be removed later
        // add_filter( 'atbdp_extension_fields', array( $this, 'atbdp_extension_fields' ) );
    }

    public function atbdp_extension_fields( $fields ) {
        // TODO: will be removed later
        // $fields[] = ['fee_manager_enable'];
        return $fields;
    }

    public function unset_featured_settings( $sections ) {
        if ( get_directorist_option( 'enable_featured_listing' ) ) {
            $options                            = get_option( 'atbdp_option' );
            $options['enable_featured_listing'] = '';
            
            update_option( 'atbdp_option', $options );
            
        }

        unset( $sections['featured_listings'] );
        return $sections;
    }

    public function action_admin_menu() {
        add_submenu_page( 'edit.php?post_type=at_biz_dir', esc_html__( 'Packages', 'directorist-pricing-plans' ), esc_html__( 'Packages', 'directorist-pricing-plans' ), 'manage_options', 'edit.php?post_type=at_biz_dir&page=directorist-orders#/packages' );
        add_submenu_page( 'edit.php?post_type=at_biz_dir', esc_html__( 'Pricing Plans', 'directorist-pricing-plans' ), esc_html__( 'Pricing Plans', 'directorist-pricing-plans' ), 'manage_options', 'edit.php?post_type=at_biz_dir&page=directorist-orders#/plans' );
    }

    public function add_settings_menu( $submenu ) {
        $submenu['pricing_plan_submenu'] = [
            'label'    => __( 'Pricing Plans', 'directorist-pricing-plans' ),
            'icon'     => '<i class="fas fa-money-check-alt"></i>',
            'sections' => apply_filters(
                'atbdp_pricing_plan_settings_controls', [
                    'general_section' => [
                        'title'       => __( 'Pricing Plans Settings', 'directorist-pricing-plans' ),
                        'description' => __( 'You can Customize all the settings of Pricing Plans Extension here', 'directorist-pricing-plans' ),
                        'fields'      =>  [ 
                            'plan_direct_purchase',
                        // TODO: will be added later
                        // 'skip_plan_page',
                        // 'tax_placeholder',
                        // 'restrict_listing_deletion'
                        ],
                    ],
                ] 
            ),
        ];

        return $submenu;
    }

    public function add_settings_fields( $pricing_fields ) {

        $pricing_fields['plan_direct_purchase'] = [
            'type'  => 'toggle',
            'label' => __( 'Direct Plan Purchase', 'directorist-pricing-plans' ),
            'value' => false,
        ];

        $pricing_fields['pricing_plans'] = [
            'label'             => __( 'Pricing Plans Page', 'directorist-pricing-plans' ),
            'type'              => 'select',
            'description'       => sprintf( __( 'Following shortcode must be in the selected page %s', 'directorist-pricing-plans' ), '<strong style="color: #ff4500;">[directorist_pricing_plans]</strong>' ),
            'value'             => atbdp_get_option( 'pricing_plans', 'atbdp_general' ),
            'showDefaultOption' => true,
            'options'           => directorist_pricing_plans_get_pages_options(),
        ];

        // TODO: will be added later

        // $pricing_fields['fee_manager_enable'] = [
        //     'label'             => __('Pricing Plans', 'directorist-pricing-plans'),
        //     'type'              => 'toggle',
        //     'value'             => true,
        //     'description'       => __('You can disable it for users.', 'directorist-pricing-plans'),
        // ];
        // $pricing_fields['skip_plan_page'] = [
        //     'label'             => __('Skip Plan Page for Paid Users', 'directorist-pricing-plans'),
        //     'type'              => 'toggle',
        //     'value'             => false,
        // ];
        // $pricing_fields['tax_placeholder'] = [
        //     'type'              => 'text',
        //     'label'             => __('Tax Placeholder', 'directorist-pricing-plans'),
        //     'value'             => __('tax', 'directorist-pricing-plans'),
        // ];
        
        // $pricing_fields['restrict_listing_deletion'] = [
        //     'type'              => 'toggle',
        //     'label'             => __('Restrict Listing Deletion', 'directorist-pricing-plans'),
        //     'description'       => __('User can\'t delete their listing if the listing is assigned with a plan.', 'directorist-pricing-plans'),
        //     'value'             => true,
        // ];


        return $pricing_fields;
    }

    public function setup_page_settings_field( $fields ) {
        $fields[] = ['pricing_plans'];
        return $fields;
    }
}
