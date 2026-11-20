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

	/**
	 * Decode and validate the Google service account credentials stored in the settings.
	 *
	 * A key pasted truncated or double escaped decodes to null, and passing that straight to
	 * setAuthConfig() builds an unauthenticated client that fails with a silent 401.
	 *
	 * @param string $google_api_key Raw value stored in the Instant Indexing settings.
	 *
	 * @return array|null The credentials array, or null when the key is unusable.
	 */
	public function getCredentials( $google_api_key ) {
		if ( ! is_string( $google_api_key ) || '' === trim( $google_api_key ) ) {
			return null;
		}

		$credentials = json_decode( $google_api_key, true );

		if ( ! is_array( $credentials ) ) {
			return null;
		}

		if ( empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
			return null;
		}

		return $credentials;
	}

	public function setup() {
		require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'vendor/autoload.php';

		// Get Google API Key
		$options        = get_option( 'seopress_instant_indexing_option_name' );
		$google_api_key = isset( $options['seopress_instant_indexing_google_api_key'] ) ? $options['seopress_instant_indexing_google_api_key'] : '';

		$credentials = $this->getCredentials( $google_api_key );

		// Without usable credentials, leave the client null so callers can report the problem.
		if ( null === $credentials ) {
			return false;
		}

		$client = new \SEOPressPro\Vendor\Google\Client();
		$client->setAuthConfig( $credentials );
		$client->setScopes( 'https://www.googleapis.com/auth/webmasters.readonly' );

		$this->client = new \SEOPressPro\Vendor\Google\Service\SearchConsole( $client );

		return true;
	}
}
