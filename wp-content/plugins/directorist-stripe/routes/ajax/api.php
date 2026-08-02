<?php

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\App\Http\Controllers\UserController;
use DirectoristStripe\WpMVC\Routing\Ajax;

Ajax::get( 'user/{id}', [UserController::class, 'index'], ['admin'] );
