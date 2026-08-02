<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\Helpers\Helpers;

return [
    'api_url' => 'https://directorist.com',
    'version' => Helpers::get_plugin_version( 'directorist-stripe' ),
    'item_id' => 13700,
    'author'  => 'AazzTech',
    'beta'    => false,
];
