<?php

namespace DoubleScale\Vendor\Egulias\EmailValidator\Validation\Error;

use DoubleScale\Vendor\Egulias\EmailValidator\Exception\InvalidEmail;
class RFCWarnings extends InvalidEmail
{
    const CODE = 997;
    const REASON = 'Warnings were found.';
}
