<?php

namespace DoubleScale\Vendor\Illuminate\Database\Events;

use DoubleScale\Vendor\Illuminate\Database\Migrations\Migration;
use DoubleScale\Vendor\Illuminate\Contracts\Database\Events\MigrationEvent as MigrationEventContract;
abstract class MigrationEvent implements MigrationEventContract
{
    /**
     * An migration instance.
     *
     * @var \Illuminate\Database\Migrations\Migration
     */
    public $migration;
    /**
     * The migration method that was called.
     *
     * @var string
     */
    public $method;
    /**
     * Create a new event instance.
     *
     * @param  \Illuminate\Database\Migrations\Migration  $migration
     * @param  string  $method
     * @return void
     */
    public function __construct(Migration $migration, $method)
    {
        $this->method = $method;
        $this->migration = $migration;
    }
}
