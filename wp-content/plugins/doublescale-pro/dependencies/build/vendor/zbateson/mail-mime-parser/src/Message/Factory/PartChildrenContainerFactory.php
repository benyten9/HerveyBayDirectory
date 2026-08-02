<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\Factory;

use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\PartChildrenContainer;
/**
 * Creates PartChildrenContainer instances.
 *
 * @author Zaahid Bateson
 */
class PartChildrenContainerFactory
{
    public function newInstance()
    {
        return new PartChildrenContainer();
    }
}
