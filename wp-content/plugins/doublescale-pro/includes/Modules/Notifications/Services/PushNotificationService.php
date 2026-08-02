<?php
/**
 * Push Notification Service
 *
 * Sends push notifications to mobile devices via Firebase Cloud Messaging HTTP v1 Api.
 * Handles JWT generation, access token caching, and stale token cleanup.
 *
 * @since 2.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Notifications\Services;

use DoubleScale\Modules\Notifications\Models\NotificationModel;
use DoubleScale\Modules\Notifications\Services\NotificationPreferences;
use DoubleScale\Core\Settings\Rest\RestSettingsControllerPro;

/**
 * PushNotificationService class
 */
class PushNotificationService {

	/**
	 * FCM HTTP v1 Api endpoint template.
	 *
	 * @var string
	 */
	const FCM_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

	/**
	 * OAuth2 scope required for FCM.
	 *
	 * @var string
	 */
	const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

	/**
	 * Transient key for cached access token.
	 *
	 * @var string
	 */
	const TOKEN_TRANSIENT = '_doublescale_fcm_access_token';

	/**
	 * Accent colour applied to the Android notification icon (#rrggbb).
	 *
	 * Must match the mobile app's brand colour — @color/notification_color in
	 * AndroidManifest and the notifee foreground handler both use #3A3A99, so a
	 * different value here would tint background pushes inconsistently.
	 *
	 * @var string
	 */
	const NOTIFICATION_COLOR = '#3A3A99';

	/**
	 * Auto-provision Firebase config from the bundled service account.
	 *
	 * Reads the encrypted service account shipped with the plugin, decrypts it
	 * with the bundled key, then re-encrypts with the site's own wp_salt and
	 * stores in wp_options. This runs once per site — subsequent calls are no-ops.
	 *
	 * @since 2.0.0
	 */
	public static function ensure_config() {
		$enc_path = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Firebase/service-account.enc';
		$key_path = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Firebase/bundle.key';

		if ( ! file_exists( $enc_path ) || ! file_exists( $key_path ) ) {
			return;
		}

		$encrypted_blob = file_get_contents( $enc_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bundle_key     = trim( file_get_contents( $key_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( empty( $encrypted_blob ) || empty( $bundle_key ) ) {
			return;
		}

		// Check if the bundled credentials have changed since last provision.
		$bundle_hash = md5( $encrypted_blob );
		$config      = get_option( 'doublescale_firebase_config', array() );

		if ( ! empty( $config['service_account'] ) && ( $config['bundle_hash'] ?? '' ) === $bundle_hash ) {
			return;
		}

		// Decrypt with the bundled key.
		$key  = hash( 'sha256', $bundle_key, true );
		$data = base64_decode( $encrypted_blob );

		if ( strlen( $data ) < 17 ) {
			return;
		}

		$iv        = substr( $data, 0, 16 );
		$plaintext = openssl_decrypt( substr( $data, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $plaintext ) {
			return;
		}

		$sa = json_decode( $plaintext, true );
		if ( ! $sa || empty( $sa['project_id'] ) || empty( $sa['private_key'] ) ) {
			return;
		}

		// Re-encrypt with the site's own salt and store.
		$site_encrypted = RestSettingsControllerPro::encrypt_firebase( $plaintext );
		if ( false === $site_encrypted ) {
			return;
		}

		// Clear stale access token when credentials change.
		delete_transient( self::TOKEN_TRANSIENT );

		update_option(
			'doublescale_firebase_config',
			array(
				'project_id'      => sanitize_text_field( $sa['project_id'] ),
				'service_account' => $site_encrypted,
				'bundle_hash'     => $bundle_hash,
				'configured_at'   => current_time( 'mysql', true ),
			),
			false
		);
	}

	/**
	 * Send push notification for a given user and notification.
	 *
	 * Called by Action Scheduler asynchronously. Supports two modes:
	 * 1. $notification_id > 0: Load notification from DB (bell/browser record exists).
	 * 2. $notification_id === 0 with $inline_data: Use inline data (push-only, no DB record).
	 *
	 * @since 2.0.0
	 *
	 * @param int   $user_id         WordPress user ID.
	 * @param int   $notification_id Notification row ID (0 if using inline data).
	 * @param array $inline_data     Optional inline notification data when no DB record exists.
	 *                               Keys: title, message, mobile_link, subcategory.
	 */
	public static function send( $user_id, $notification_id, $inline_data = array() ) {
		if ( ! get_option( 'doublescale_push_enabled', false ) ) {
			return;
		}

		self::ensure_config();

		$config = get_option( 'doublescale_firebase_config', array() );
		if ( empty( $config['service_account'] ) || empty( $config['project_id'] ) ) {
			return;
		}

		// Resolve notification data from either DB record or inline data.
		if ( $notification_id > 0 ) {
			$notification = NotificationModel::find( $notification_id );
			if ( ! $notification || (int) $notification->user_id !== (int) $user_id ) {
				return;
			}
			$subcategory = $notification->subcategory;
			$push_data   = (object) array(
				'id'          => $notification->id,
				'title'       => $notification->title ?? '',
				'message'     => $notification->message ?? '',
				'mobile_link' => $notification->mobile_link ?? '',
				'subcategory' => $subcategory,
			);
		} elseif ( ! empty( $inline_data ) ) {
			$subcategory = $inline_data['subcategory'] ?? '';
			$push_data   = (object) array(
				'id'          => 0,
				'title'       => $inline_data['title'] ?? '',
				'message'     => $inline_data['message'] ?? '',
				'mobile_link' => $inline_data['mobile_link'] ?? '',
				'subcategory' => $subcategory,
			);
		} else {
			return;
		}

		if ( ! NotificationPreferences::is_push_enabled( $user_id, $subcategory ) ) {
			return;
		}

		$tokens = DeviceTokenService::get_tokens( $user_id );
		if ( empty( $tokens ) ) {
			return;
		}

		$access_token = self::get_access_token( $config );
		if ( ! $access_token ) {
			return;
		}

		$endpoint = sprintf( self::FCM_ENDPOINT, $config['project_id'] );

		foreach ( $tokens as $entry ) {
			$payload = self::build_payload( $entry['token'], $push_data );
			self::send_to_fcm( $endpoint, $access_token, $payload, $entry['token'] );
		}
	}

	/**
	 * Build FCM message payload.
	 *
	 * Public so the settings "send test push" endpoint builds the exact same
	 * payload as a real notification — a divergent test payload previously
	 * reported success while real pushes were missing icon/deep-link fields.
	 *
	 * @since 2.0.0
	 *
	 * @param string $device_token FCM device token.
	 * @param object $push_data    Object with id, title, message, mobile_link, subcategory.
	 * @return array
	 */
	public static function build_payload( $device_token, $push_data ) {
		$title   = $push_data->title ?? '';
		$body    = $push_data->message ?? '';
		$link    = (string) ( $push_data->mobile_link ?? '' );
		$subcat  = (string) ( $push_data->subcategory ?? '' );

		return array(
			'message' => array(
				'token'        => $device_token,
				'notification' => array(
					'title' => $title,
					'body'  => $body,
				),
				// The React Native app routes taps from remoteMessage.data via
				// onNotificationOpenedApp()/getInitialNotification(), so the deep
				// link must live here. FCM requires every data value to be a string.
				'data' => array(
					'notification_id' => (string) ( $push_data->id ?? 0 ),
					'mobile_link'     => $link,
					'subcategory'     => $subcat,
				),
				'android' => array(
					'priority'     => 'high',
					'notification' => array(
						'channel_id' => 'doublescale',
						// react-native-firebase prefers the payload icon over the
						// manifest default; without it Android falls back to
						// ic_launcher, rendered as a tiny monochrome silhouette.
						'icon'       => 'ic_notification',
						'color'      => self::NOTIFICATION_COLOR,
					),
				),
				'apns' => array(
					'headers' => array(
						'apns-priority' => '10',
					),
					'payload' => array(
						'aps' => array(
							'alert' => array(
								'title' => $title,
								'body'  => $body,
							),
							'sound'            => 'default',
							// Lets the app group/route taps by notification type.
							'category'         => 'doublescale',
							// Required for a Notification Service Extension to
							// enrich the notification (e.g. attach the app icon).
							'mutable-content'  => 1,
						),
					),
				),
			),
		);
	}

	/**
	 * Send a single message to FCM.
	 *
	 * @since 2.0.0
	 *
	 * @param string $endpoint     FCM Api URL.
	 * @param string $access_token OAuth2 access token.
	 * @param array  $payload      FCM payload.
	 * @param string $device_token The device token (for stale cleanup).
	 */
	private static function send_to_fcm( $endpoint, $access_token, $payload, $device_token ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Plugin Push] FCM request failed: ' . $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Handle stale tokens — 404 or UNREGISTERED means the app was uninstalled.
		if ( 404 === $code || self::is_unregistered_error( $body ) ) {
			DeviceTokenService::remove_stale_token( $device_token );
			return;
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_msg = $body['error']['message'] ?? 'HTTP ' . $code;
			error_log( '[Plugin Push] FCM error: ' . $error_msg );
		}
	}

	/**
	 * Check if FCM response indicates an unregistered token.
	 *
	 * @since 2.0.0
	 *
	 * @param array|null $body Decoded response body.
	 * @return bool
	 */
	private static function is_unregistered_error( $body ) {
		if ( ! is_array( $body ) ) {
			return false;
		}

		$details = $body['error']['details'] ?? array();
		foreach ( $details as $detail ) {
			if ( 'UNREGISTERED' === ( $detail['errorCode'] ?? '' ) ) {
				return true;
			}
		}

		$status = $body['error']['status'] ?? '';
		return 'NOT_FOUND' === $status;
	}

	/**
	 * Get a cached or fresh OAuth2 access token for FCM.
	 *
	 * @since 2.0.0
	 *
	 * @param array $config The doublescale_firebase_config option value.
	 * @return string|false Access token string or false on failure.
	 */
	public static function get_access_token( $config ) {
		// Check cache.
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		// Decrypt service account.
		$json = RestSettingsControllerPro::decrypt_firebase( $config['service_account'] );
		if ( false === $json ) {
			error_log( '[Plugin Push] Failed to decrypt Firebase service account.' );
			return false;
		}

		$sa = json_decode( $json, true );
		if ( ! $sa || empty( $sa['private_key'] ) || empty( $sa['client_email'] ) ) {
			error_log( '[Plugin Push] Invalid service account data.' );
			return false;
		}

		// Generate JWT.
		$jwt = self::generate_jwt( $sa );
		if ( is_wp_error( $jwt ) ) {
			error_log( '[Plugin Push] JWT generation failed: ' . $jwt->get_error_message() );
			return false;
		}

		// Exchange JWT for access token.
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => http_build_query( array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[Plugin Push] Token exchange failed: ' . $response->get_error_message() );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $data['access_token'] ) ) {
			$error = $data['error_description'] ?? 'Unknown error';
			error_log( '[Plugin Push] Token exchange error: ' . $error );
			return false;
		}

		// Cache for 55 minutes (tokens are valid for 60).
		$expires_in = min( (int) ( $data['expires_in'] ?? 3600 ), 3600 );
		set_transient( self::TOKEN_TRANSIENT, $data['access_token'], $expires_in - 300 );

		return $data['access_token'];
	}

	/**
	 * Generate a JWT for Google OAuth2 service account auth.
	 *
	 * Uses openssl_sign() — no external dependencies.
	 *
	 * @since 2.0.0
	 *
	 * @param array $service_account Decoded service account JSON.
	 * @return string|\WP_Error JWT string or WP_Error on failure.
	 */
	public static function generate_jwt( $service_account ) {
		$now = time();

		$header = self::base64url_encode( wp_json_encode( array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		) ) );

		$claim = self::base64url_encode( wp_json_encode( array(
			'iss'   => $service_account['client_email'],
			'scope' => self::FCM_SCOPE,
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		) ) );

		$signature_input = $header . '.' . $claim;

		$private_key = openssl_pkey_get_private( $service_account['private_key'] );
		if ( ! $private_key ) {
			return new \WP_Error( 'invalid_key', __( 'Invalid private key in service account.', 'doublescale') );
		}

		$signature = '';
		$signed    = openssl_sign( $signature_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $signed ) {
			return new \WP_Error( 'sign_failed', __( 'Failed to sign JWT.', 'doublescale') );
		}

		return $signature_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Base64url encode (RFC 4648 §5).
	 *
	 * @since 2.0.0
	 *
	 * @param string $data Data to encode.
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
