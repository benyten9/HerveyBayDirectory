<?php

namespace WPEloquent\WP;

use WPEloquent\Eloquent\Model;
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
        return $this->hasOne('WPEloquent\\WP\\Post');
    }
}
