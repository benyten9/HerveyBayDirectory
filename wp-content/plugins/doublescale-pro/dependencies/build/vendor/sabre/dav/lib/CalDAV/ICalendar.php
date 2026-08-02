<?php

declare (strict_types=1);
namespace DoubleScale\Pro\Vendor\Sabre\CalDAV;

use DoubleScale\Pro\Vendor\Sabre\DAVACL;
/**
 * Calendar interface.
 *
 * Implement this interface to allow a node to be recognized as an calendar.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface ICalendar extends ICalendarObjectContainer, DAVACL\IACL
{
}
