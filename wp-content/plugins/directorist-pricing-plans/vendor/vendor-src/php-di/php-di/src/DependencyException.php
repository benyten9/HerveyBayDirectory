<?php

declare (strict_types=1);
namespace DirectoristPricingPlan\DI;

use DirectoristPricingPlan\Psr\Container\ContainerExceptionInterface;
/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
