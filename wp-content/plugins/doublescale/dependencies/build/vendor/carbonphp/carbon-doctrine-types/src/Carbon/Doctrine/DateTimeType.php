<?php

namespace Carbon\Doctrine;

use Carbon\Carbon;
use Doctrine\DBAL\Types\VarDateTimeType;
class DateTimeType extends VarDateTimeType implements \Carbon\Doctrine\CarbonDoctrineType
{
    /** @use CarbonTypeConverter<Carbon> */
    use \Carbon\Doctrine\CarbonTypeConverter;
}
