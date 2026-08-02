<?php

namespace SEOPressPro\Services\Options;

defined( 'ABSPATH' ) or exit( 'Cheatin&#8217; uh?' );


class GoogleAnalyticsWidgetsOptionPro {
	/**
	 * @since 5.9.0
	 *
	 * @return array
	 */
	public function getOption() {
		return get_option( 'seopress_google_analytics_option_name' );
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

	public function getGA4DashboardWidget() {
		return $this->searchOptionByKey( 'seopress_google_analytics_dashboard_widget' );
	}

	public function getMatomoDashboardWidget() {
		return $this->searchOptionByKey( 'seopress_google_analytics_matomo_dashboard_widget' );
	}
}
