<?php

namespace DoubleScale\Vendor\Egulias\EmailValidator\Warning;

use DoubleScale\Vendor\Egulias\EmailValidator\EmailParser;
class EmailTooLong extends Warning
{
    const CODE = 66;
    public function __construct()
    {
        $this->message = 'Email is too long, exceeds ' . EmailParser::EMAIL_MAX_LENGTH;
    }
}
