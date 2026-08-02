<?php

namespace DoubleScale\Pro\Vendor\Sabre\VObject\TimezoneGuesser;

use DateTimeZone;
use DoubleScale\Pro\Vendor\Sabre\VObject\Component\VTimeZone;
interface TimezoneGuesser
{
    public function guess(VTimeZone $vtimezone, bool $failIfUncertain = \false) : ?DateTimeZone;
}
