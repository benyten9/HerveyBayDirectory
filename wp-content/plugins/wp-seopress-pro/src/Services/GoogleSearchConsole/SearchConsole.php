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
		$client = seopress_pro_get_service( 'GoogleClient' )->getClient();

		// An absent or invalid service account key leaves the client null, which would
		// otherwise raise an \Error that the catch blocks below do not intercept.
		if ( null === $client ) {
			return array(
				'error'  => __( 'No valid Google service account key found. Check the key pasted in SEO, Instant Indexing.', 'wp-seopress-pro' ),
				'status' => 'error',
			);
		}

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

			// Keep the previous metrics until a replacement response is available.
			delete_post_meta_by_key( '_seopress_search_console_analysis_clicks' );
			delete_post_meta_by_key( '_seopress_search_console_analysis_ctr' );
			delete_post_meta_by_key( '_seopress_search_console_analysis_impressions' );
			delete_post_meta_by_key( '_seopress_search_console_analysis_position' );
			delete_post_meta_by_key( '_seopress_search_console_analysis_rows' );

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

		// An absent or invalid service account key leaves the client null, which would
		// otherwise raise an \Error that the catch blocks below do not intercept.
		if ( null === $client ) {
			return array(
				'error'  => __( 'No valid Google service account key found. Check the key pasted in SEO, Instant Indexing.', 'wp-seopress-pro' ),
				'status' => 'error',
			);
		}

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
			echo 'Keyword: ', esc_html( $row->getKeys()[0] ), ', Clicks: ', esc_html( $row->getClicks() ), "\n";
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

		// Several rows can resolve to the same post (trailing slash, query
		// string, case). Keep each row of this sync by URL, so the post shows
		// their sum, and a row saved twice replaces itself instead of counting
		// twice. handle() clears these rows when a new sync starts.
		$rows = get_post_meta( $postId, '_seopress_search_console_analysis_rows', true );
		$rows = is_array( $rows ) ? $rows : array();

		$rows[ $url ] = array(
			'clicks'      => (float) $clicks,
			'ctr'         => (float) $ctr,
			'impressions' => (float) $impressions,
			'position'    => (float) $position,
		);

		update_post_meta( $postId, '_seopress_search_console_analysis_rows', $rows );

		$data = self::sumRows( $rows );

		update_post_meta( $postId, '_seopress_search_console_analysis_clicks', sanitize_text_field( $data['clicks'] ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_ctr', sanitize_text_field( $data['ctr'] ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_impressions', sanitize_text_field( $data['impressions'] ) );
		update_post_meta( $postId, '_seopress_search_console_analysis_position', sanitize_text_field( $data['position'] ) );

		return array(
			'post_id' => $postId,
			'data'    => $data,
		);
	}

	/**
	 * Metrics of a post from all the Search Console rows that resolve to it.
	 *
	 * Clicks and impressions add up. The CTR is recomputed from the totals and
	 * the position is weighted by impressions: a row seen 20 times at position 1
	 * and a row seen 8,416 times at position 12 do not rank at 6.5.
	 *
	 * @param array $rows Metrics of each row, keyed by URL.
	 *
	 * @return array clicks, ctr, impressions, position.
	 */
	protected static function sumRows( $rows ) {
		$clicks      = 0;
		$impressions = 0;
		$weighted    = 0;

		foreach ( $rows as $row ) {
			$clicks      += $row['clicks'];
			$impressions += $row['impressions'];
			$weighted    += $row['position'] * $row['impressions'];
		}

		return array(
			'clicks'      => $clicks,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0,
			'impressions' => $impressions,
			'position'    => $impressions > 0 ? $weighted / $impressions : 0,
		);
	}
}
