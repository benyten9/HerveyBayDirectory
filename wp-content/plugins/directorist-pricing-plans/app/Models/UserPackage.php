<?php

namespace DirectoristPricingPlan\App\Models;

defined( "ABSPATH" ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Database\Resolver;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Model;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Relations\BelongsToOne;
use DirectoristPricingPlan\App\Models\User;

class UserPackage extends Model {
    public static function get_table_name():string {
        return "directorist_user_packages";
    }

    public function resolver():Resolver {
        return App::$container->get( Resolver::class );
    }

    public function user():BelongsToOne {
        return $this->belongs_to_one( User::class, 'ID', 'user_id' );
    }
}

