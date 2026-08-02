<?php

/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Header;

use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Header\Consumer\AbstractConsumer;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Header\Consumer\ConsumerService;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Header\Part\MimeLiteralPart;
use DoubleScale\Pro\Vendor\ZBateson\MailMimeParser\Header\Part\MimeLiteralPartFactory;
/**
 * Allows a header to be mime-encoded and be decoded with a consumer after
 * decoding.
 *
 * @author Zaahid Bateson
 */
abstract class MimeEncodedHeader extends AbstractHeader
{
    /**
     * @var MimeLiteralPartFactory for mime decoding.
     */
    protected $mimeLiteralPartFactory;
    public function __construct(MimeLiteralPartFactory $mimeLiteralPartFactory, ConsumerService $consumerService, $name, $value)
    {
        $this->mimeLiteralPartFactory = $mimeLiteralPartFactory;
        parent::__construct($consumerService, $name, $value);
    }
    /**
     * Mime-decodes any mime-encoded parts prior to invoking the passed
     * consumer.
     *
     * @return static
     */
    protected function setParseHeaderValue(AbstractConsumer $consumer)
    {
        $value = $this->rawValue;
        $matchp = '~' . MimeLiteralPart::MIME_PART_PATTERN . '~';
        $value = \preg_replace_callback($matchp, function ($matches) {
            return $this->mimeLiteralPartFactory->newInstance($matches[0]);
        }, $value);
        $this->parts = $consumer($value);
        return $this;
    }
}
