<?php

namespace DirectoristPricingPlan\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Contracts\Provider;
use DirectoristPricingPlan\Database\Setup;

class UpdateServiceProvider implements Provider {
    public function boot(): void {
        add_action( 'admin_init', [ $this, 'update' ] );
    }

    public function update(): void {
        ( new Setup() )->maybe_add_large_scale_query_indexes();

        $edd_config  = App::$config->get( 'edd-config' );
        $data        = get_user_meta( get_current_user_id(), '_plugins_available_in_subscriptions', true );
        $license_key = ! empty( $data['directorist-pricing-plans'] ) ? $data['directorist-pricing-plans']['license'] : '';
        
        new \EDDPluginUpdaterPricingPlan(
            $edd_config['api_url'], DIRECTORIST_PRICING_PLANS_FILE, [
                'version' => $edd_config['version'],
                'license' => $license_key,
                'item_id' => $edd_config['item_id'],
                'author'  => $edd_config['author'],
                'url'     => home_url(),
                'beta'    => $edd_config['beta'],
            ]
        );
    }
}
