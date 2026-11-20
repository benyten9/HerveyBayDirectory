<?php

declare (strict_types=1);
namespace DirectoristAnalytics\DI;

use DirectoristAnalytics\Psr\Container\ContainerExceptionInterface;
/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
