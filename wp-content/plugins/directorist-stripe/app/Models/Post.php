<?php

namespace DirectoristStripe\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;
use DirectoristStripe\WpMVC\Database\Eloquent\Model;
use DirectoristStripe\WpMVC\Database\Eloquent\Relations\HasMany;
use DirectoristStripe\WpMVC\Database\Resolver;

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