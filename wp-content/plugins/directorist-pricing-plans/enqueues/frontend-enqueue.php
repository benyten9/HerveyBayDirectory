<?php

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\Enqueue\Enqueue;

Enqueue::register_style( 'directorist-pricing-plans-frontend', 'build/css/frontend' );
Enqueue::script( 'directorist-pricing-plans-listing-owner-dashboard', 'build/js/frontend/listing-owner-dashboard' );

Enqueue::register_script( 'directorist-pricing-plans-plans', 'build/js/frontend/plans', ['jquery'] );