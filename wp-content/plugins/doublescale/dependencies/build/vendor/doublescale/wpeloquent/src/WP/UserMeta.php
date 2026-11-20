<?php

namespace DoubleScale\Vendor\WPEloquent\WP;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
class UserMeta extends Model
{
    protected $table = 'usermeta';
    protected $primaryKey = 'umeta_id';
    public $timestamps = \false;
}
