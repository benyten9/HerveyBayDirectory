<?php

namespace WPEloquent\WP;

use WPEloquent\Eloquent\Model;
class UserMeta extends Model
{
    protected $table = 'usermeta';
    protected $primaryKey = 'umeta_id';
    public $timestamps = \false;
}
