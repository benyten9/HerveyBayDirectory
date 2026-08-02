<?php
/**
 * Validates bundled SMTP connections: account exists and OAuth tokens present when required.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Providers\Mailers;
use WP_Error;

/**
 * SMTP settings / connection validation helpers.
 */
class SmtpConnectionValidator {

	/**
	 * Mailers that use OAuth and must have stored tokens on the account.
	 *
	 * @var string[]
	 */
	private static $oauth_mailers = array( 'gmail', 'outlook', 'zoho' );

	/**
	 * Validate every connection in a merged settings array.
	 *
	 * @param array $settings Full settings including `connections`.
	 * @return true|WP_Error
	 */
	public static function validate_settings_connections( $settings ) {
		if ( empty( $settings['connections'] ) || ! is_array( $settings['connections'] ) ) {
			return true;
		}

		/**
		 * Short-circuit or augment validation.
		 *
		 * @param null|bool|\WP_Error $result   Pre-result; return non-null to skip built-in checks.
		 * @param array               $settings Full settings payload.
		 */
		$pre = apply_filters( 'doublescale_smtp_validate_connections', null, $settings );
		if ( null !== $pre ) {
			return true === $pre ? true : $pre;
		}

		foreach ( $settings['connections'] as $connection_id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}

			$single = self::validate_single_connection( (string) $connection_id, $connection, 'storage' );
			if ( is_wp_error( $single ) ) {
				return $single;
			}
		}

		return true;
	}

	/**
	 * Validate one connection row.
	 *
	 * @param string $connection_id Connection key.
	 * @param array  $connection    Connection data.
	 * @param string $context       `storage` = saving settings (SMTP-style: OAuth rows may exist before account_id is set).
	 *                              `send` = must be able to send (default).
	 * @return true|WP_Error
	 */
	public static function validate_single_connection( $connection_id, array $connection, $context = 'send' ) {
		$mailer_slug = isset( $connection['mailer'] ) ? sanitize_key( (string) $connection['mailer'] ) : '';
		if ( '' === $mailer_slug || 'phpmailer' === $mailer_slug ) {
			return true;
		}

		$mailer = Mailers::get_mailer( $mailer_slug );
		if ( ! $mailer ) {
			return new WP_Error(
				'doublescale_smtp_unknown_mailer',
				sprintf(
					/* translators: 1: mailer slug, 2: connection id */
					__( 'Unknown mailer "%1$s" on connection "%2$s".', 'doublescale' ),
					$mailer_slug,
					$connection_id
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $mailer->accounts ) ) {
			return true;
		}

		$account_id = isset( $connection['account_id'] ) ? trim( (string) $connection['account_id'] ) : '';

		// Gmail / Outlook / Zoho: accounts are created by OAuth callback with provider IDs (not connection ids). Allow saving a connection before the user links an account (same as SMTP settings POST).
		if ( 'storage' === $context && in_array( $mailer_slug, self::$oauth_mailers, true ) && '' === $account_id ) {
			return true;
		}

		if ( '' === $account_id ) {
			return new WP_Error(
				'doublescale_smtp_missing_account',
				sprintf(
					/* translators: 1: connection id, 2: mailer slug */
					__( 'Connection "%1$s" (%2$s) requires an account. Select or add an account.', 'doublescale' ),
					$connection_id,
					$mailer_slug
				),
				array( 'status' => 400 )
			);
		}

		$accounts = $mailer->accounts->get_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			return new WP_Error(
				'doublescale_smtp_account_not_found',
				sprintf(
					/* translators: 1: account id, 2: mailer slug, 3: connection id */
					__( 'Account "%1$s" was not found for mailer "%2$s" (connection "%3$s"). Re-authorize or choose a valid account.', 'doublescale' ),
					$account_id,
					$mailer_slug,
					$connection_id
				),
				array( 'status' => 400 )
			);
		}

		if ( in_array( $mailer_slug, self::$oauth_mailers, true ) ) {
			return self::validate_oauth_account( $mailer_slug, $accounts[ $account_id ], $connection_id, $account_id );
		}

		return true;
	}

	/**
	 * Ensure OAuth mailers have usable tokens.
	 *
	 * @param string $mailer_slug gmail|outlook|zoho.
	 * @param array  $account_data Stored account row.
	 * @param string $connection_id Connection key.
	 * @param string $account_id Account id.
	 * @return true|WP_Error
	 */
	public static function validate_oauth_account( $mailer_slug, array $account_data, $connection_id = '', $account_id = '' ) {
		$creds = isset( $account_data['credentials'] ) && is_array( $account_data['credentials'] )
			? $account_data['credentials']
			: array();

		if ( 'gmail' === $mailer_slug ) {
			$refresh = isset( $creds['refresh_token'] ) ? (string) $creds['refresh_token'] : '';
			$access  = isset( $creds['access_token'] ) ? $creds['access_token'] : array();
			$token   = is_array( $access ) ? (string) ( $access['access_token'] ?? '' ) : (string) $access;
			if ( '' === $refresh && '' === $token ) {
				return new WP_Error(
					'doublescale_smtp_oauth_incomplete',
					__( 'Gmail account is missing OAuth tokens. Connect the account again with Google.', 'doublescale' ),
					array(
						'status'        => 400,
						'connection_id' => $connection_id,
						'account_id'    => $account_id,
						'mailer'        => $mailer_slug,
					)
				);
			}
			return true;
		}

		if ( 'outlook' === $mailer_slug || 'zoho' === $mailer_slug ) {
			$refresh = isset( $creds['refresh_token'] ) ? (string) $creds['refresh_token'] : '';
			$access  = isset( $creds['access_token'] ) ? (string) $creds['access_token'] : '';
			if ( '' === $refresh && '' === $access ) {
				return new WP_Error(
					'doublescale_smtp_oauth_incomplete',
					sprintf(
						/* translators: %s: provider label */
						__( '%s account is missing OAuth tokens. Connect the account again.', 'doublescale' ),
						'outlook' === $mailer_slug ? __( 'Microsoft Outlook', 'doublescale' ) : __( 'Zoho', 'doublescale' )
					),
					array(
						'status'        => 400,
						'connection_id' => $connection_id,
						'account_id'    => $account_id,
						'mailer'        => $mailer_slug,
					)
				);
			}
			return true;
		}

		return true;
	}
}
