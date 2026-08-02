<?php

// File generated from our OpenAPI spec
namespace DirectoristStripe\Stripe\Events;

/**
 * @property \Stripe\RelatedObject $related_object Object containing the reference to API resource relevant to the event
 * @property \Stripe\EventData\V1BillingMeterErrorReportTriggeredEventData $data data associated with the event
 */
class V1BillingMeterErrorReportTriggeredEvent extends \DirectoristStripe\Stripe\V2\Event
{
    const LOOKUP_TYPE = 'v1.billing.meter.error_report_triggered';
    /**
     * Retrieves the related object from the API. Make an API request on every call.
     *
     * @return \Stripe\Billing\Meter
     *
     * @throws \Stripe\Exception\ApiErrorException if the request fails
     */
    public function fetchRelatedObject()
    {
        $apiMode = \DirectoristStripe\Stripe\Util\Util::getApiMode($this->related_object->url);
        list($object, $options) = $this->_request('get', $this->related_object->url, [], ['stripe_account' => $this->context], [], $apiMode);
        return \DirectoristStripe\Stripe\Util\Util::convertToStripeObject($object, $options, $apiMode);
    }
    public static function constructFrom($values, $opts = null, $apiMode = 'v2')
    {
        $evt = parent::constructFrom($values, $opts, $apiMode);
        if (null !== $evt->data) {
            $evt->data = \DirectoristStripe\Stripe\EventData\V1BillingMeterErrorReportTriggeredEventData::constructFrom($evt->data, $opts, $apiMode);
        }
        return $evt;
    }
}
