<?php

namespace DirectoristPricingPlan\App\Models;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Database\Resolver;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Model;

class PlanOrderMeta extends Model {
    public static function get_table_name(): string {
        return "directorist_plan_order_meta";
    }

    public function resolver(): Resolver {
        return App::$container->get( Resolver::class );
    }
}
