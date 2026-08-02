<?php

namespace DoubleScale\Pro\Vendor\Stripe\Util;

class EventTypes
{
    const thinEventMapping = [
        // The beginning of the section generated from our OpenAPI spec
        \DoubleScale\Pro\Vendor\Stripe\Events\V1BillingMeterErrorReportTriggeredEvent::LOOKUP_TYPE => \DoubleScale\Pro\Vendor\Stripe\Events\V1BillingMeterErrorReportTriggeredEvent::class,
        \DoubleScale\Pro\Vendor\Stripe\Events\V1BillingMeterNoMeterFoundEvent::LOOKUP_TYPE => \DoubleScale\Pro\Vendor\Stripe\Events\V1BillingMeterNoMeterFoundEvent::class,
    ];
}
