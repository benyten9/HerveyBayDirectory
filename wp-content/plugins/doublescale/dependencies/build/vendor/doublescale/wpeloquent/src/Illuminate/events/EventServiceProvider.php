<?php

namespace DoubleScale\Vendor\Illuminate\Events;

use DoubleScale\Vendor\Illuminate\Support\ServiceProvider;
use DoubleScale\Vendor\Illuminate\Contracts\Queue\Factory as QueueFactoryContract;
class EventServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton('events', function ($app) {
            return (new Dispatcher($app))->setQueueResolver(function () use($app) {
                return $app->make(QueueFactoryContract::class);
            });
        });
    }
}
