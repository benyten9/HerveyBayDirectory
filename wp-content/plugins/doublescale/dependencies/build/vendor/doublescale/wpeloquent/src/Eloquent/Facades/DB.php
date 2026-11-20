<?php

namespace DoubleScale\Vendor\WPEloquent\Eloquent\Facades;

use DoubleScale\Vendor\Illuminate\Support\Facades\Facade;
use DoubleScale\Vendor\WPEloquent\Eloquent\Database;
/**
 * @see \Illuminate\Database\DatabaseManager
 * @see \Illuminate\Database\Connection
 */
class DB extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return Database::instance();
    }
}
