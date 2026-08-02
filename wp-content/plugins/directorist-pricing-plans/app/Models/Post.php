<?php

namespace DirectoristPricingPlan\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristPricingPlan\WpMVC\App;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Model;
use DirectoristPricingPlan\WpMVC\Database\Eloquent\Relations\HasMany;
use DirectoristPricingPlan\WpMVC\Database\Resolver;

class Post extends Model {
    public static function get_table_name():string {
        return 'posts';
    }

    public function meta(): HasMany {
        return $this->has_many( PostMeta::class, 'post_id', 'ID' );
    }

    public function resolver():Resolver {
        return App::$container->get( Resolver::class );
    }
}