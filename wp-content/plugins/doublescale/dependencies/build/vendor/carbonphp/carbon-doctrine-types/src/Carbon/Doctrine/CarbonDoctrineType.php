<?php

namespace DoubleScale\Vendor\Carbon\Doctrine;

use DoubleScale\Vendor\Doctrine\DBAL\Platforms\AbstractPlatform;
interface CarbonDoctrineType
{
    public const MAXIMUM_PRECISION = 10;
    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform);
    public function convertToPHPValue($value, AbstractPlatform $platform);
    public function convertToDatabaseValue($value, AbstractPlatform $platform);
}
