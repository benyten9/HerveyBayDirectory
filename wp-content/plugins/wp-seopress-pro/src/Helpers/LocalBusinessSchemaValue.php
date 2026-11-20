<?php
namespace SEOPressPro\Helpers;
defined( 'ABSPATH' ) || exit;

/** Resolve an explicit Local Business variable from an automatic manual-text mapping. */
class LocalBusinessSchemaValue {
	public static function resolve( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^%%local_business_(type|street_address|address_locality|address_region|address_postal_code|address_country|latitude|longitude|phone|price_range|url|cuisine|menu|accepts_reservations)%%$/D', $value ) ) {
			return $value;
		}
		return seopress_get_service( 'TagsToString' )->getValueFromTag( trim( $value, '%' ) );
	}
}
