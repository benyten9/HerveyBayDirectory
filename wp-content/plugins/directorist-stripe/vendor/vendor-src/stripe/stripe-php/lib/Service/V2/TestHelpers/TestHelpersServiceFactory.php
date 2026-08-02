<?php

// File generated from our OpenAPI spec
namespace DirectoristStripe\Stripe\Service\V2\TestHelpers;

/**
 * Service factory class for API resources in the TestHelpers namespace.
 *
 * @property FinancialAddressService $financialAddresses
 */
class TestHelpersServiceFactory extends \DirectoristStripe\Stripe\Service\AbstractServiceFactory
{
    /**
     * @var array<string, string>
     */
    private static $classMap = ['financialAddresses' => FinancialAddressService::class];
    protected function getServiceClass($name)
    {
        return \array_key_exists($name, self::$classMap) ? self::$classMap[$name] : null;
    }
}
