<?php

namespace DoubleScale\Vendor\WPEloquent\WP;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
class User extends Model
{
    protected $table = 'users';
    protected $primaryKey = 'ID';
    protected $timestamp = \false;
    public function meta()
    {
        return $this->hasMany('DoubleScale\\Vendor\\WPEloquent\\WP\\UserMeta', 'user_id');
    }
}
