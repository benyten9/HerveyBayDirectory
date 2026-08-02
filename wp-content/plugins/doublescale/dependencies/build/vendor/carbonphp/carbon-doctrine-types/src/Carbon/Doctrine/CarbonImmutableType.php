<?php

namespace Carbon\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
class CarbonImmutableType extends \Carbon\Doctrine\DateTimeImmutableType implements \Carbon\Doctrine\CarbonDoctrineType
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName()
    {
        return 'carbon_immutable';
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
