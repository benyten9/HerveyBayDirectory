<?php

namespace WPEloquent\WP;

use WPEloquent\Eloquent\Model;
class PostMeta extends Model
{
    protected $table = 'postmeta';
    protected $primaryKey = 'meta_id';
    public $timestamps = \false;
    public function getTable()
    {
        return $this->getConnection()->db->prefix . 'postmeta';
    }
}
