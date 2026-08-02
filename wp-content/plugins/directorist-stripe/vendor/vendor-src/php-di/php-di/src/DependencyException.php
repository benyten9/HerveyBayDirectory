<?php

declare (strict_types=1);
namespace DirectoristStripe\DI;

use DirectoristStripe\Psr\Container\ContainerExceptionInterface;
/**
 * Exception for the Container.
 */
class DependencyException extends \Exception implements ContainerExceptionInterface
{
}
