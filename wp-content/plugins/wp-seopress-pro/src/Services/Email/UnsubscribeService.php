<?php

namespace SEOPressPro\Services\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Handles email unsubscribe functionality.
 *
 * Generates HMAC-signed URLs so recipients can unsubscribe from SEOPress
 * email alerts without logging into the WordPress admin.
 *
 * @since 9.8.0
 */
class UnsubscribeService {

	/**
	 * Alert types and their corresponding option keys.
	 *
	 * @var array
	 */
	const ALERT_TYPES = array(
		'seo_alerts' => array(
			'option' => 'seopress_pro_option_name',
			'key'    => 'seopress_seo_alerts_recipients',
		),
		'404_alerts' => array(
			'option' => 'seopress_pro_option_name',
			'key'    => 'seopress_404_enable_mails_from',
		),
		'site_audit' => array(
			'option' => 'seopress_bot_option_name',
			'key'    => 'seopress_bot_scan_settings_email',
		),
	);

	/**
	 * Cached secret key.
	 *
	 * @var string|null
	 */
	private $secret_key = null;

	/**
	 * Get or generate the secret key used for HMAC signing.
	 *
	 * The key is cached in memory to avoid repeated database queries
	 * when generating tokens for multiple recipients.
	 *
	 * @return string
	 */
	private function get_secret_key() {
		if ( null !== $this->secret_key ) {
			return $this->secret_key;
		}

		$this->secret_key = get_option( 'seopress_unsubscribe_secret_key' );

		if ( empty( $this->secret_key ) ) {
			$this->secret_key = wp_generate_password( 64, true, true );
			update_option( 'seopress_unsubscribe_secret_key', $this->secret_key, false );
		}

		return $this->secret_key;
	}

	/**
	 * Generate an HMAC token for the given email and alert type.
	 *
	 * @param string $email The email address.
	 * @param string $type  The alert type (seo_alerts, 404_alerts, site_audit).
	 *
	 * @return string
	 */
	public function generate_token( $email, $type ) {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ) . '|' . $type, $this->get_secret_key() );
	}

	/**
	 * Verify an HMAC token.
	 *
	 * @param string $email The email address.
	 * @param string $type  The alert type.
	 * @param string $token The token to verify.
	 *
	 * @return bool
	 */
	public function verify_token( $email, $type, $token ) {
		$expected = $this->generate_token( $email, $type );

		return hash_equals( $expected, $token );
	}

	/**
	 * Build the unsubscribe URL for a given email and alert type.
	 *
	 * @param string $email The email address.
	 * @param string $type  The alert type.
	 *
	 * @return string
	 */
	public function get_unsubscribe_url( $email, $type ) {
		$email = strtolower( trim( $email ) );

		return add_query_arg(
			array(
				'seopress_unsubscribe' => '1',
				'type'                 => $type,
				'email'                => rawurlencode( $email ),
				'token'                => $this->generate_token( $email, $type ),
			),
			home_url()
		);
	}

	/**
	 * Process an unsubscribe request.
	 *
	 * @param string $email The email address to unsubscribe.
	 * @param string $type  The alert type.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function process_unsubscribe( $email, $type ) {
		if ( ! isset( self::ALERT_TYPES[ $type ] ) ) {
			return false;
		}

		$config     = self::ALERT_TYPES[ $type ];
		$option_name = $config['option'];
		$option_key  = $config['key'];
		$email       = strtolower( trim( $email ) );

		$options = get_option( $option_name );

		if ( ! is_array( $options ) || ! isset( $options[ $option_key ] ) ) {
			return false;
		}

		$current_value = $options[ $option_key ];

		// Handle comma-separated email lists.
		$emails         = array_map( 'trim', array_map( 'strtolower', explode( ',', $current_value ) ) );
		$original_count = count( $emails );

		$emails = array_filter( $emails, function ( $e ) use ( $email ) {
			return $e !== $email;
		} );

		// Email was not in the list.
		if ( count( $emails ) === $original_count ) {
			return false;
		}

		$options[ $option_key ] = implode( ', ', $emails );

		update_option( $option_name, $options );

		return true;
	}

	/**
	 * Get the translatable label for an alert type.
	 *
	 * @param string $type The alert type.
	 *
	 * @return string
	 */
	public function get_alert_label( $type ) {
		$labels = array(
			'seo_alerts' => __( 'SEO Alerts', 'wp-seopress-pro' ),
			'404_alerts' => __( '404 Alerts', 'wp-seopress-pro' ),
			'site_audit' => __( 'Site Audit', 'wp-seopress-pro' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : '';
	}
}
