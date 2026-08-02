<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\Proxy;

use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\IParser;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\PartBuilder;
/**
 * Base class for factories creating ParserPartProxy classes.
 *
 * @author Zaahid Bateson
 */
abstract class ParserPartProxyFactory
{
    /**
     * Constructs a new ParserPartProxy wrapping an IMessagePart object.
     *
     * @return ParserPartProxy
     */
    public abstract function newInstance(PartBuilder $partBuilder, IParser $parser);
}
