<?php

namespace SEOPressPro\Tags\Schema\Pro\LocalBusiness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Models\GetTagValue;

class Url implements GetTagValue {
	const NAME = 'local_business_url';

	/**
	 * @since 4.5.0
	 *
	 * @param array $args
	 *
	 * @return string
	 */
	public function getValue( $args = null ) {
		$context = isset( $args[0] ) ? $args[0] : null;

		$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessUrl();

		return apply_filters( 'seopress_pro_get_tag_schema_local_business_url', $value, $context );
	}
}
