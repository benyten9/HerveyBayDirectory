<?php

namespace DirectoristStripe\App\Models;

defined( 'ABSPATH' ) || exit;

use DirectoristStripe\WpMVC\App;
use DirectoristStripe\WpMVC\Database\Eloquent\Model;
use DirectoristStripe\WpMVC\Database\Resolver;

class User extends Model {
    public static function get_table_name():string {
        return 'users';
    }

    public function resolver():Resolver {
        return App::$container->get( Resolver::class );
    }
}