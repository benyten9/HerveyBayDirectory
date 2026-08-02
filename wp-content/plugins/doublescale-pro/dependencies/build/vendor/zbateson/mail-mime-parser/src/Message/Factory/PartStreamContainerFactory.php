<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\Factory;

use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\PartStreamContainer;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Stream\StreamFactory;
/**
 * Creates PartStreamContainer instances.
 *
 * @author Zaahid Bateson
 */
class PartStreamContainerFactory
{
    /**
     * @var StreamFactory
     */
    protected $streamFactory;
    public function __construct(StreamFactory $streamFactory)
    {
        $this->streamFactory = $streamFactory;
    }
    public function newInstance()
    {
        return new PartStreamContainer($this->streamFactory);
    }
}
