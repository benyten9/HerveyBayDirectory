<?php

declare (strict_types=1);
namespace DoubleScale\Pro\Vendor\Sabre\CalDAV\Schedule;

/**
 * Implement this interface to have a node be recognized as a CalDAV scheduling
 * inbox.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface IInbox extends \DoubleScale\Pro\Vendor\Sabre\CalDAV\ICalendarObjectContainer, \DoubleScale\Pro\Vendor\Sabre\DAVACL\IACL
{
}
