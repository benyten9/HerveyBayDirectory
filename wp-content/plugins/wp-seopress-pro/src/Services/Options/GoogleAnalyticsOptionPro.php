<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );


class GoogleAnalyticsOptionPro {
	/**
	 * @since 5.9.0
	 *
	 * @return array
	 */
	public function getOption() {
		return get_option( 'seopress_google_analytics_option_name1' );
	}

	/**
	 * @since 5.9.0
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function searchOptionByKey( $key ) {
		$data = $this->getOption();

		if ( empty( $data ) ) {
			return null;
		}

		if ( ! isset( $data[ $key ] ) ) {
			return null;
		}

		return $data[ $key ];
	}

	public function getAccessToken() {
		return $this->searchOptionByKey( 'access_token' );
	}

	public function getRefreshToken() {
		return $this->searchOptionByKey( 'refresh_token' );
	}

	public function getDebug() {
		return $this->searchOptionByKey( 'debug' );
	}
}
