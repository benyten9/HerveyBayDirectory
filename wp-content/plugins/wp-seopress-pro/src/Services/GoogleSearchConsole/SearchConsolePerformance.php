<?php

namespace SEOPressPro\Services\GoogleSearchConsole;

defined( 'ABSPATH' ) || exit;

/** Read-only, property-wide performance for the latest twelve calendar months. */
class SearchConsolePerformance {
	public function get_data() {
		$options = get_option( 'seopress_instant_indexing_option_name', array() );
		$key     = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';
		if ( empty( $key ) ) {
			return new \WP_Error( 'seopress_gsc_not_configured', __( 'Configure your Google Search Console connection first.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
		}

		$property = apply_filters( 'seopress_search_console_base_url', seopress_pro_get_service( 'OptionPro' )->getGSCResolvedUrl() );
		$today    = new \DateTimeImmutable( 'today', new \DateTimeZone( 'America/Los_Angeles' ) );
		$start    = $today->modify( 'first day of this month' )->modify( '-11 months' )->format( 'Y-m-d' );
		$end      = $today->format( 'Y-m-d' );
		// A changed property or credential must never reuse another property's data.
		$cache_key = 'seopress_gsc_performance_' . md5( $property . '|' . $key . '|' . $start . '|' . $end );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		try {
			$client  = seopress_pro_get_service( 'GoogleClient' )->getClient();
			if ( null === $client ) {
				return new \WP_Error( 'seopress_gsc_missing_credentials', __( 'No valid Google service account key found. Check the key pasted in SEO, Instant Indexing.', 'wp-seopress-pro' ), array( 'status' => 400 ) );
			}
			$request = new \SEOPressPro\Vendor\Google\Service\SearchConsole\SearchAnalyticsQueryRequest();
			$request->setStartDate( $start );
			$request->setEndDate( $end );
			$request->setDimensions( array( 'date' ) );
			$request->setType( 'web' );
			$request->setDataState( 'final' );
			$request->setAggregationType( 'byProperty' );
			$request->setRowLimit( 400 );
			$response = $client->searchanalytics->query( $property, $request );
			$rows     = $response->getRows();
			if ( null !== $rows && ! is_array( $rows ) ) {
				throw new \UnexpectedValueException( 'Invalid performance response' );
			}
			$data = array(
				'property'   => $property,
				'startDate'  => $start,
				'endDate'    => $end,
				'updatedAt'  => gmdate( 'c' ),
				'months'     => $this->aggregate_months( $rows ?: array(), $start, $end ),
			);
			set_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS );
			return $data;
		} catch ( \Throwable $error ) {
			// Do not expose upstream exception details or service-account credentials.
			return new \WP_Error( 'seopress_gsc_performance_failed', __( 'Search Console performance could not be loaded. Check the property, access permissions and API quota, then try again.', 'wp-seopress-pro' ), array( 'status' => 502 ) );
		}
	}

	/** Missing months stay unknown; CTR and position are weighted by impressions. */
	public function aggregate_months( $rows, $start, $end ) {
		$months = array();
		$date   = new \DateTimeImmutable( $start );
		for ( $index = 0; $index < 12; ++$index ) {
			$month = $date->modify( '+' . $index . ' months' )->format( 'Y-m' );
			$months[ $month ] = array( 'month' => $month, 'clicks' => null, 'impressions' => null, 'ctr' => null, 'position' => null );
		}
		$weighted_positions = array();
		foreach ( $rows as $row ) {
			$keys = $row->getKeys();
			$day  = isset( $keys[0] ) ? $keys[0] : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || $day < $start || $day > $end ) {
				continue;
			}
			$month = substr( $day, 0, 7 );
			if ( ! isset( $months[ $month ] ) ) {
				continue;
			}
			$clicks      = (float) $row->getClicks();
			$impressions = (float) $row->getImpressions();
			$position    = (float) $row->getPosition();
			$months[ $month ]['clicks']      += $clicks;
			$months[ $month ]['impressions'] += $impressions;
			$weighted_positions[ $month ]   = ( $weighted_positions[ $month ] ?? 0 ) + $position * $impressions;
		}
		foreach ( $months as $month => &$values ) {
			if ( $values['impressions'] > 0 ) {
				$values['ctr']      = round( 100 * $values['clicks'] / $values['impressions'], 2 );
				$values['position'] = round( $weighted_positions[ $month ] / $values['impressions'], 2 );
			}
		}
		unset( $values );
		return array_values( $months );
	}
}
