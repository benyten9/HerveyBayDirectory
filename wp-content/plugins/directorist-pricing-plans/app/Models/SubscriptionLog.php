<?php

namespace DirectoristPricingPlan\App\Models;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Database\Resolver;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Model;

class SubscriptionLog extends Model {
    public static function get_table_name():string {
        return "directorist_subscription_logs";
    }

    public function resolver():Resolver {
        return App::$container->get( Resolver::class );
    }
}

