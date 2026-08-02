<?php

namespace DoubleScale\Pro\Vendor\Stripe\Exception\OAuth;

/**
 * Implements properties and methods common to all (non-SPL) Stripe OAuth
 * exceptions.
 */
abstract class OAuthErrorException extends \DoubleScale\Pro\Vendor\Stripe\Exception\ApiErrorException
{
    protected function constructErrorObject()
    {
        if (null === $this->jsonBody) {
            return null;
        }
        return \DoubleScale\Pro\Vendor\Stripe\OAuthErrorObject::constructFrom($this->jsonBody);
    }
}
