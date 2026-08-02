<?php
/**
 * Save pro licence
 *
 * NOTE:  Whoever sees this.. you can separate the licence activation in new function and use it here and in callbacs/Licence.php
 * So we don't have same code on two different places.
 *
 * @param  string $licence The licence key.
 *
 * @return void
 */
function seopress_save_pro_licence( $licence ) {
	// data to send in our API request.
	$api_params = array(
		'edd_action'  => 'activate_license',
		'license'     => $license,
		'item_id'     => ITEM_ID_SEOPRESS, // the ID of our product in EDD.
		'url'         => home_url(),
		'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
	);

	// Call the custom API.
	$response = wp_remote_post(
		STORE_URL_SEOPRESS,
		array(
			'user-agent' => 'WordPress/' . get_bloginfo( 'version' ),
			'timeout'    => 15,
			'sslverify'  => false,
			'body'       => $api_params,
		)
	);

	// make sure the response came back okay
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
		} else {
			$message = __( 'An error occurred, please try again. Response code: ', 'wp-seopress-pro' ) . wp_remote_retrieve_response_code( $response );
		}
	} else {
		$license_data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( false === $license_data->success ) {
			switch ( $license_data->error ) {
				case 'expired':
					/* translators: %s: localized expiration date */
					$message = sprintf(
						__( 'Your license key expired on %s.', 'wp-seopress-pro' ),
						date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
					);
					break;

				case 'disabled':
				case 'revoked':
					$message = __( 'Your license key has been disabled.', 'wp-seopress-pro' );
					break;

				case 'missing':
					$message = __( 'Invalid license.', 'wp-seopress-pro' );
					break;

				case 'invalid':
				case 'site_inactive':
					$message = __( 'Your license is not active for this URL.', 'wp-seopress-pro' );
					break;

				case 'item_name_mismatch':
					/* translators: %s: SEOPress PRO */
					$message = sprintf( __( 'This appears to be an invalid license key for %s.', 'wp-seopress-pro' ), ITEM_NAME_SEOPRESS );
					break;

				case 'no_activations_left':
					$message = __( 'Your license key has reached its activation limit.', 'wp-seopress-pro' );
					break;

				default:
					$message = __( 'An error occurred, please try again.', 'wp-seopress-pro' );
					break;
			}
		}
	}

	// Check if anything passed on a message constituting a failure
	if ( ! empty( $message ) ) {
		return new \WP_Error(
			500,
			$message
		);
	}

	// $license_data->license will be either "valid" or "invalid"
	if ( 'valid' === $license_data->license ) {
		update_option( 'seopress_pro_license_key', $license );
	}

	update_option( 'seopress_pro_license_status', $license_data->license );

	return true;
}

/**
 * Reset licence key
 *
 * @return void
 */
function seopress_reset_pro_licence() {
	delete_option( 'seopress_pro_license_status' );
	delete_option( 'seopress_pro_license_key' );
}
