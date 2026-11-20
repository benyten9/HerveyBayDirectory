<?php

namespace DoubleScale\Vendor\Carbon\Doctrine;

use DoubleScale\Vendor\Carbon\Carbon;
use DoubleScale\Vendor\Doctrine\DBAL\Types\VarDateTimeType;
class DateTimeType extends VarDateTimeType implements CarbonDoctrineType
{
    /** @use CarbonTypeConverter<Carbon> */
    use CarbonTypeConverter;
}
