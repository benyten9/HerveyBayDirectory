<?php

namespace Carbon\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
class CarbonType extends \Carbon\Doctrine\DateTimeType implements \Carbon\Doctrine\CarbonDoctrineType
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName()
    {
        return 'carbon';
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform)
    {
        return \true;
    }
}
