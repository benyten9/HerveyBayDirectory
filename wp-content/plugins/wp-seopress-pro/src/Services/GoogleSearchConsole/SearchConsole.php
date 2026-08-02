<?php

namespace SEOPressPro\Services\GoogleSearchConsole;

defined( 'ABSPATH' ) || exit;

class SearchConsole {

	protected $client = null;

	protected function getDateRange( $options = array() ) {
		$date_range = seopress_pro_get_service( 'OptionPro' )->getGSCDateRange() ? seopress_pro_get_service( 'OptionPro' )->getGSCDateRange() : '- 3 months';

		$date_range = apply_filters( 'seopress_search_console_date_range', $date_range );

		$startDate = isset( $options['startDate'] ) ? $options['startDate'] : ( new \DateTime( 'today' ) )->modify( $date_range )->format( 'Y-m-d' );

		$startDate = apply_filters( 'seopress_search_console_start_date', $startDate );

		$endDate = isset( $options['endDate'] ) ? $options['endDate'] : ( new \DateTime( 'today' ) )->format( 'Y-m-d' );

		$endDate = apply_filters( 'seopress_search_console_end_date', $endDate );

		return array(
			'startDate' => $startDate,
			'endDate'   => $endDate,
		);
	}

	protected function getUrl() {
		$url = seopress_pro_get_service( 'OptionPro' )->getGSCResolvedUrl();
		$url = apply_filters( 'seopress_search_console_base_url', $url );

		return $url;
	}

	public function handle( $options = array() ) {
		// Cleaning old data
		delete_post_meta_by_key( '_seopress_search_console_analysis_clicks' );
		delete_post_meta_by_key( '_seopress_search_console_analysis_ctr' );
		delete_post_meta_by_key( '_seopress_search_console_analysis_impressions' );
		delete_post_meta_by_key( '_seopress_search_console_analysis_position' );

		$client = seopress_pro_get_service( 'GoogleClient' )->getClient();

		$range = $this->getDateRange( $options );

		$dimensions = isset( $options['dimensions'] ) ? $options['dimensions'] : array( 'PAGE' );

		$url = $this->getUrl();

		$queryRequest = new \SEOPressPro\Vendor\Google\Service\SearchConsole\SearchAnalyticsQueryRequest();
		$queryRequest->setStartDate( $range['startDate'] );
		$queryRequest->setEndDate( $range['endDate'] );
		$queryRequest->setDimensions( $dimensions );
		try {
			$response = $client->searchanalytics->query( $url, $queryRequest );

			if ( ! property_exists( $response, 'rows' ) ) {
				return array();
			}

			return array(
				'data'   => $response->rows,
				'status' => 'success',
			);
		} catch ( \Exception $e ) {
			return array(
				'error'  => $e->getMessage(),
				'status' => 'error',
			);
		}
	}

	public function getKeywords( $options = array() ) {
		$rows = get_transient( 'seopress_search_console_keywords' );
		if ( $rows ) {
			return array(
				'data'   => $rows,
				'status' => 'success',
			);
		}

		$client = seopress_pro_get_service( 'GoogleClient' )->getClient();

		$url = $this->getUrl();

		$queryRequest = new \SEOPressPro\Vendor\Google\Service\SearchConsole\SearchAnalyticsQueryRequest();

		$range = $this->getDateRange( $options );

		$queryRequest->setStartDate( $range['startDate'] );
		$queryRequest->setEndDate( $range['endDate'] );
		$queryRequest->setDimensions( array( 'QUERY' ) );
		$queryRequest->setRowLimit( isset( $options['rowLimit'] ) ? $options['rowLimit'] : 5 );

		try {
			$response = $client->searchanalytics->query( $url, $queryRequest );
			$rows     = $response->getRows();

			$data = array();

			foreach ( $rows as $key => $value ) {
				$data[] = array(
					'impressions' => $value->getImpressions(),
					'clicks'      => $value->getClicks(),
					'ctr'         => $value->getCtr(),
					'position'    => $value->getPosition(),
					'keyword'     => $value->getKeys()[0],
				);
			}

			set_transient( 'seopress_search_console_keywords', $data, 60 * 60 * 24 ); // 1 day

			return array(
				'data'   => $data,
				'status' => 'success',
			);
		} catch ( \Exception $e ) {
			return array(
				'error'  => $e->getMessage(),
				'status' => 'error',
			);
		}

		// Iterate over each row and print the query (keyword) and clicks.
		foreach ( $rows as $row ) {
			echo 'Keyword: ', $row->getKeys()[0], ', Clicks: ', $row->getClicks(), "\n";
		}
	}

	public function saveDataFromRowResult( $row ) {
		if ( is_array( $row ) ) {
			$keys = isset( $row['keys'] ) ? $row['keys'] : array();
		} else {
			$keys = property_exists( $row, 'keys' ) ? $row->keys : array();
		}

		$url = null;

		if ( ! isset( $keys[0] ) ) {
			return;
		}

		$url = $keys[0];

		if ( ! is_array( $row ) ) {
			$clicks      = property_exists( $row, 'clicks' ) ? $row->clicks : 0;
			$ctr         = property_exists( $row, 'ctr' ) ? $row->ctr : 0;
			$impressions = property_exists( $row, 'impressions' ) ? $row->impressions : 0;
			$position    = property_exists( $row, 'position' ) ? $row->position : 0;
		} else {
			$clicks      = isset( $row['clicks'] ) ? $row['clicks'] : 0;
			$ctr         = isset( $row['ctr'] ) ? $row['ctr'] : 0;
			$impressions = isset( $row['impressions'] ) ? $row['impressions'] : 0;
			$position    = isset( $row['position'] ) ? $row['position'] : 0;
		}


		$parseUrl = wp_parse_url( $url );
		if ( ! isset( $parseUrl['scheme'] ) || ! isset( $parseUrl['host'] ) || ! isset( $parseUrl['path'] ) ) {
			$cleanUrl = $url;
		} else {
			$cleanUrl = $parseUrl['scheme'] . '://' . $parseUrl['host'] . $parseUrl['path'];
		}

		/**
		 * Filter the URL used to match a Search Console row to a WordPress post.
		 *
		 * WordPress url_to_postid() resolves a URL against core rewrite rules. Multilingual
		 * plugins (TranslatePress, Polylang, WPML…) that prepend a language segment to public
		 * URLs (e.g. /en/) do not register that segment in WP_Rewrite, so the raw GSC URL
		 * cannot be matched. This filter lets such setups normalize the URL before matching.
		 *
		 * @param string $cleanUrl Normalized GSC page URL (scheme + host + path).
		 * @param string $url      Raw URL returned by the Search Console API.
		 */
		$cleanUrl = apply_filters( 'seopress_search_console_match_url', $cleanUrl, $url );

		$postId = url_to_postid( $cleanUrl );

		/**
		 * Filter the resolved post ID for a Search Console row, as a last-resort fallback
		 * when url_to_postid() returns 0 (e.g. translated permalinks resolved via the
		 * multilingual plugin's own API).
		 *
		 * @param int    $postId   Post ID resolved by url_to_postid() (0 if no match).
		 * @param string $cleanUrl Normalized GSC page URL used for matching.
		 * @param string $url      Raw URL returned by the Search Console API.
		 */
		$postId = (int) apply_filters( 'seopress_search_console_match_post_id', $postId, $cleanUrl, $url );

		if ( ! $postId ) {
			return;
		}

		update_post_meta( $postId, '_seopress_search_console_analysis_clicks', sanitize_text_field( $clicks ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_ctr', sanitize_text_field( $ctr ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_impressions', sanitize_text_field( $impressions ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_position', sanitize_text_field( $position ) );

		return array(
			'post_id' => $postId,
			'data'    => array(
				'clicks'      => $clicks,
				'ctr'         => $ctr,
				'impressions' => $impressions,
				'position'    => $position,
			),
		);
	}
}
