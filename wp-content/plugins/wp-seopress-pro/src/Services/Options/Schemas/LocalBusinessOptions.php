<?php

namespace SEOPressPro\Services\Options\Schemas;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );

trait LocalBusinessOptions {
	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessPage() {
		return $this->searchOptionByKey( 'seopress_local_business_page' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessType() {
		return $this->searchOptionByKey( 'seopress_local_business_type' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessStreetAddress() {
		return $this->searchOptionByKey( 'seopress_local_business_street_address' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessAddressLocality() {
		return $this->searchOptionByKey( 'seopress_local_business_address_locality' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessAddressRegion() {
		return $this->searchOptionByKey( 'seopress_local_business_address_region' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessPostalCode() {
		return $this->searchOptionByKey( 'seopress_local_business_postal_code' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessAddressCountry() {
		return $this->searchOptionByKey( 'seopress_local_business_address_country' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessLatitude() {
		return $this->searchOptionByKey( 'seopress_local_business_lat' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessLongitude() {
		return $this->searchOptionByKey( 'seopress_local_business_lon' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessPlaceId() {
		return $this->searchOptionByKey( 'seopress_local_business_place_id' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessUrl() {
		return $this->searchOptionByKey( 'seopress_local_business_url' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessPhone() {
		return $this->searchOptionByKey( 'seopress_local_business_phone' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessPriceRange() {
		return $this->searchOptionByKey( 'seopress_local_business_price_range' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessCuisine() {
		return $this->searchOptionByKey( 'seopress_local_business_cuisine' );
	}

	/**
	 * @since 4.5.0
	 *
	 * @return string
	 */
	public function getLocalBusinessOpeningHours() {
		return $this->searchOptionByKey( 'seopress_local_business_opening_hours' );
	}

	/**
	 * @since 9.2.0
	 *
	 * @return string
	 */
	public function getLocalBusinessOpeningHoursDisplayFormat() {
		return $this->searchOptionByKey( 'seopress_local_business_opening_hours_display_format' );
	}

	/**
	 * @since 9.2.0
	 *
	 * @return string
	 */
	public function getLocalBusinessOpeningHoursSeparator() {
		return $this->searchOptionByKey( 'seopress_local_business_opening_hours_separator' );
	}


	/**
	 *
	 * @return string
	 */
	public function getLocalBusinessAcceptsReservations() {
		return $this->searchOptionByKey( 'seopress_local_business_accepts_reservations' );
	}
	/**
	 *
	 * @return string
	 */
	public function getLocalBusinessMenu() {
		return $this->searchOptionByKey( 'seopress_local_business_menu' );
	}
}
