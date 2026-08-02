<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\Enqueue\Enqueue;

Enqueue::script( 'directorist-stripe', 'build/js/stripe' );

wp_localize_script(
    'directorist-stripe', 'directoristStripe', [
        'publishableKey' => directorist_stripe_get_publish_key()
    ]
);