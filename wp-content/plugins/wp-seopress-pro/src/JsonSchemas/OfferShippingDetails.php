<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class OfferShippingDetails extends JsonSchemaValue implements GetJsonData {
	const NAME = 'offer-shipping-details';

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 7.4.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$destinationSchema = ! empty( $context['variables']['shippingDestination'] ) ? $context['variables']['shippingDestination'] : array();
		if ( ! empty( $destinationSchema ) ) {
			$data['shippingDestination'] = $destinationSchema;
		}

		// The template carries the amount as a "[[shippingAmount]]" placeholder, so the
		// rendered value ended up a string while the automatic schema emitted a number.
		// Set it directly to keep MonetaryAmount.value numeric in both outputs.
		if ( isset( $context['variables']['shippingAmount'], $data['shippingRate']['value'] ) && is_numeric( $context['variables']['shippingAmount'] ) ) {
			$data['shippingRate']['value'] = (float) $context['variables']['shippingAmount'];
		}

		return apply_filters( 'seopress_pro_get_json_data_offer_shipping_details', $data, $context );
	}
}
