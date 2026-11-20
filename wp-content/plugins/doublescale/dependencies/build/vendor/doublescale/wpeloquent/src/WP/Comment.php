<?php

namespace DoubleScale\Vendor\WPEloquent\WP;

use DoubleScale\Vendor\WPEloquent\Eloquent\Model;
class Comment extends Model
{
    protected $table = 'comments';
    protected $primaryKey = 'comment_ID';
    /**
     * Post relation for a comment
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function post()
    {
        return $this->hasOne('DoubleScale\\Vendor\\WPEloquent\\WP\\Post');
    }
}
