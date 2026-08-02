<?php

namespace DirectoristStripe\Stripe\Exception\OAuth;

/**
 * Implements properties and methods common to all (non-SPL) Stripe OAuth
 * exceptions.
 */
abstract class OAuthErrorException extends \DirectoristStripe\Stripe\Exception\ApiErrorException
{
    protected function constructErrorObject()
    {
        if (null === $this->jsonBody) {
            return null;
        }
        return \DirectoristStripe\Stripe\OAuthErrorObject::constructFrom($this->jsonBody);
    }
}
