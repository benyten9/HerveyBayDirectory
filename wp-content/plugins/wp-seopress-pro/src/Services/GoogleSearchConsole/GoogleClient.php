<?php

namespace SEOPressPro\Services\GoogleSearchConsole;

defined( 'ABSPATH' ) || exit;

class GoogleClient {
	protected $client = null;

	public function getClient() {
		if ( $this->client === null ) {
			$this->setup();
		}

		return $this->client;
	}

	public function setup() {
		require_once WP_PLUGIN_DIR . '/wp-seopress-pro/vendor/autoload.php';

		$client = new \SEOPressPro\Vendor\Google\Client();

		// Get Google API Key
		$options        = get_option( 'seopress_instant_indexing_option_name' );
		$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

		// Check we have setup at least one API key
		if ( empty( $google_api_key ) ) {
			$data['inspect_url']['status'] = __( 'No API key defined from the settings tab', 'wp-seopress-pro' );
			update_post_meta( $postId, '_seopress_gsc_inspect_url_data', $data );
			return false;
		}

		$client->setAuthConfig( json_decode( $google_api_key, true ) );
		$client->setScopes( 'https://www.googleapis.com/auth/webmasters.readonly' );

		$this->client = new \SEOPressPro\Vendor\Google\Service\SearchConsole( $client );

		return true;
	}
}
