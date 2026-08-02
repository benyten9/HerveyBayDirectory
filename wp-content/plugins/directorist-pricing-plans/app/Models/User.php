<?php

namespace DirectoristPricingPlan\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Model;
use DirectoristPricingPlan\WpMVC\Database\Resolver;

class User extends Model {
    public static function get_table_name():string {
        return 'users';
    }

    public function resolver():Resolver {
        return App::$container->get( Resolver::class );
    }
}