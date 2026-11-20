<?php

namespace DirectoristAnalytics\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristAnalytics\WpMVC\App;
use DirectoristAnalytics\WpMVC\Database\Eloquent\Model;
use DirectoristAnalytics\WpMVC\Database\Eloquent\Relations\HasMany;
use DirectoristAnalytics\WpMVC\Database\Resolver;

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