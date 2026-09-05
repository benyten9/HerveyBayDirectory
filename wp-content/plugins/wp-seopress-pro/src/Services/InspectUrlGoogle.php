<?php

namespace SEOPressPro\Services;

defined( 'ABSPATH' ) || exit;


class InspectUrlGoogle {
	protected $service = null;

	public function getService() {
		return $this->service;
	}

	public function setupService( $postId = null ) {
		require_once WP_PLUGIN_DIR . '/wp-seopress-pro/vendor/autoload.php';

		$client = new \SEOPressPro\Vendor\Google\Client();

		// Get Google API Key
		$options        = get_option( 'seopress_instant_indexing_option_name' );
		$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

		// Check we have setup at least one API key
		if ( empty( $google_api_key ) ) {
			if ( ! empty( $postId ) ) {
				$data = array(
					'inspect_url' => array(
						'status' => __( 'No API key defined from the settings tab', 'wp-seopress-pro' ),
					),
				);
				update_post_meta( $postId, '_seopress_gsc_inspect_url_data', $data );
			}
			return false;
		}

		$client->setAuthConfig( json_decode( $google_api_key, true ) );
		$client->setScopes( 'https://www.googleapis.com/auth/webmasters.readonly' );

		$this->service = new \SEOPressPro\Vendor\Google\Service\SearchConsole( $client );

		return true;
	}

	protected function getNextType( $type ) {
		switch ( $type ) {
			case 'normal': // First
				return 'with-slash';
			case 'with-slash': // Second
				return 'www';
			case 'www': // Third
				return 'www-with-or-without-slash';
			case 'www-with-or-without-slash':// Four
				return 'sc-domain';
			default:
				return null;
		}
	}

	protected function getUrlRetryRequest( $baseUrl, $type ) {
		if ( $type === 'with-slash' ) {
			return sprintf( '%s/', $baseUrl );
		}

		if ( $type === 'www' ) {
			$parseUrl = wp_parse_url( $baseUrl );
			if ( ! isset( $parseUrl['host'] ) && ! isset( $parseUrl['scheme'] ) ) {
				return null;
			}

			if ( isset( $parseUrl['host'] ) && str_starts_with( $parseUrl['host'], 'www.' ) ) {
				return sprintf( '%s://%s', $parseUrl['scheme'], \str_replace( 'www.', '', $parseUrl['host'] ) );
			} else {
				return sprintf( '%s://www.%s', $parseUrl['scheme'], $parseUrl['host'] );
			}
		}

		if ( $type === 'www-with-or-without-slash' ) {
			$fullStrEnd = substr( $baseUrl, strlen( $baseUrl ) - strlen( '/' ) );
			if ( $fullStrEnd == '/' ) {
				return rtrim( $baseUrl, '/' );
			} else {
				return $baseUrl . '/';
			}
		}

		if ( $type === 'sc-domain' ) {
			return sprintf( 'sc-domain:%s', $baseUrl );
		}

		return null;
	}

	public function request( $siteUrl, $url, $type = 'normal' ) {
		// Fix encoding URL (e.g. cyrillic alphabet)
		$url = htmlspecialchars( rawurldecode( $url ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 );

		$service  = $this->getService();
		$postBody = new \SEOPressPro\Vendor\Google\Service\SearchConsole\InspectUrlIndexRequest();
		$postBody->setInspectionUrl( $url );
		$postBody->setSiteUrl( $siteUrl );

		// Language
		if ( function_exists( 'seopress_normalized_locale' ) ) {
			$language = seopress_normalized_locale( get_locale() );
		} else {
			$language = get_locale();
		}

		$postBody->setLanguageCode( $language );

		try {
			$response = $service->urlInspection_index->inspect( $postBody );
			$response = json_decode( wp_json_encode( $response ) );
			return $response;
		} catch ( \Exception $e ) {
			$type = $this->getNextType( $type );
			if ( $type === null ) {
				return $e->getMessage();
			}

			$siteUrl = $this->getUrlRetryRequest( $siteUrl, $type );
			if ( $siteUrl !== null ) {
				$response = $this->request( $siteUrl, $url, $type );
				return $response;
			} else {
				return $e->getMessage();
			}
		}
	}

	public function handle( $postId ) {
		require_once WP_PLUGIN_DIR . '/wp-seopress-pro/vendor/autoload.php';

		$data = array();

		if ( ! $this->setupService( $postId ) ) {
			return $data;
		}

		// URL to inspect
		$url = apply_filters( 'seopress_inspect_url_permalink', get_permalink( $postId ) );

		// Site URL.
		$site_url = seopress_pro_get_service( 'OptionPro' )->getGSCResolvedUrl();
		$site_url = apply_filters( 'seopress_inspect_url_home_url', $site_url );

		$response = $this->request( $site_url, $url );

		update_post_meta( $postId, '_seopress_gsc_inspect_url_data', $response );

		return $response;
	}
}
