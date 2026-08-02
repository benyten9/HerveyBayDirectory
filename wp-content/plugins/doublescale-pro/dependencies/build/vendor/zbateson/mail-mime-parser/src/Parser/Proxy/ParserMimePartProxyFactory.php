<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\Proxy;

use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\Factory\PartHeaderContainerFactory;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Message\MimePart;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\IParser;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\Part\ParserPartChildrenContainerFactory;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\Part\ParserPartStreamContainerFactory;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Parser\PartBuilder;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Stream\StreamFactory;
/**
 * Responsible for creating proxied IMimePart instances wrapped in a
 * ParserMimePartProxy with a MimeParser.
 *
 * @author Zaahid Bateson
 */
class ParserMimePartProxyFactory extends ParserPartProxyFactory
{
    /**
     * @var StreamFactory the StreamFactory instance
     */
    protected $streamFactory;
    /**
     * @var ParserPartStreamContainerFactory
     */
    protected $parserPartStreamContainerFactory;
    /**
     * @var PartHeaderContainerFactory
     */
    protected $partHeaderContainerFactory;
    /**
     * @var ParserPartChildrenContainerFactory
     */
    protected $parserPartChildrenContainerFactory;
    public function __construct(StreamFactory $sdf, PartHeaderContainerFactory $phcf, ParserPartStreamContainerFactory $pscf, ParserPartChildrenContainerFactory $ppccf)
    {
        $this->streamFactory = $sdf;
        $this->partHeaderContainerFactory = $phcf;
        $this->parserPartStreamContainerFactory = $pscf;
        $this->parserPartChildrenContainerFactory = $ppccf;
    }
    /**
     * Constructs a new ParserMimePartProxy wrapping an IMimePart object that
     * will dynamically parse a message's content and parts as they're
     * requested.
     *
     * @return ParserMimePartProxy
     */
    public function newInstance(PartBuilder $partBuilder, IParser $parser)
    {
        $parserProxy = new ParserMimePartProxy($partBuilder, $parser);
        $streamContainer = $this->parserPartStreamContainerFactory->newInstance($parserProxy);
        $headerContainer = $this->partHeaderContainerFactory->newInstance($parserProxy->getHeaderContainer());
        $childrenContainer = $this->parserPartChildrenContainerFactory->newInstance($parserProxy);
        $part = new MimePart($partBuilder->getParent()->getPart(), $streamContainer, $headerContainer, $childrenContainer);
        $parserProxy->setPart($part);
        $streamContainer->setStream($this->streamFactory->newMessagePartStream($part));
        $part->attach($streamContainer);
        return $parserProxy;
    }
}
