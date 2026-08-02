<?php

declare (strict_types=1);
namespace GuzzleHttp\Promise;

final class Is
{
    /**
     * Returns true if a promise is pending.
     */
    public static function pending(\GuzzleHttp\Promise\PromiseInterface $promise) : bool
    {
        return $promise->getState() === \GuzzleHttp\Promise\PromiseInterface::PENDING;
    }
    /**
     * Returns true if a promise is fulfilled or rejected.
     */
    public static function settled(\GuzzleHttp\Promise\PromiseInterface $promise) : bool
    {
        return $promise->getState() !== \GuzzleHttp\Promise\PromiseInterface::PENDING;
    }
    /**
     * Returns true if a promise is fulfilled.
     */
    public static function fulfilled(\GuzzleHttp\Promise\PromiseInterface $promise) : bool
    {
        return $promise->getState() === \GuzzleHttp\Promise\PromiseInterface::FULFILLED;
    }
    /**
     * Returns true if a promise is rejected.
     */
    public static function rejected(\GuzzleHttp\Promise\PromiseInterface $promise) : bool
    {
        return $promise->getState() === \GuzzleHttp\Promise\PromiseInterface::REJECTED;
    }
}
