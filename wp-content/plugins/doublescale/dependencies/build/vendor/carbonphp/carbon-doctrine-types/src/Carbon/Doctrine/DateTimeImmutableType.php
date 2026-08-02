<?php

namespace Carbon\Doctrine;

use Carbon\CarbonImmutable;
use Doctrine\DBAL\Types\VarDateTimeImmutableType;
class DateTimeImmutableType extends VarDateTimeImmutableType implements \Carbon\Doctrine\CarbonDoctrineType
{
    /** @use CarbonTypeConverter<CarbonImmutable> */
    use \Carbon\Doctrine\CarbonTypeConverter;
    /**
     * @return class-string<CarbonImmutable>
     */
    protected function getCarbonClassName() : string
    {
        return CarbonImmutable::class;
    }
}
