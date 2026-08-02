<?php

namespace DirectoristStripe\App\Providers\Admin;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;
use DirectoristStripe\WpMVC\Contracts\Provider;

class UpdateServiceProvider implements Provider {

    public function boot(): void {
        add_action( 'admin_init', [ $this, 'update' ] );
    }

    public function update(): void {
        $edd_config  = App::$config->get( 'edd-config' );
        $data        = get_user_meta( get_current_user_id(), '_plugins_available_in_subscriptions', true );
        $license_key = ! empty( $data['directorist-stripe'] ) ? $data['directorist-stripe']['license'] : '';
        
        new \EDDPluginUpdaterStripe(
            $edd_config['api_url'], DIRECTORIST_STRIPE_FILE, [
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
