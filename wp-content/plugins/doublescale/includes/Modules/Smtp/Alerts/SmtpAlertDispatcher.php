<?php
/**
 * Delivery failure alerts (Slack, generic webhook, Discord) — parity with smtp alerts-admin.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Smtp\Alerts;

use DoubleScale\Modules\Smtp\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches alert payloads synchronously when mail fails (no Action Scheduler dependency).
 */
final class SmtpAlertDispatcher {

	/**
	 * Admin URL to the SMTP email log screen inside DoubleScale.
	 */
	public static function logs_screen_url(): string {
		$page = (string) apply_filters( 'doublescale_admin_menu_slug', 'doublescale' );

		return admin_url( 'admin.php?page=' . rawurlencode( $page ) . '&path=smtp%2Flogs' );
	}

	/**
	 * Register runtime hooks.
	 */
	public static function boot(): void {
		add_action( 'doublescale_smtp_mailer_handle_failed_email', array( __CLASS__, 'on_failed' ), 10, 2 );
	}

	/**
	 * @param string|array $error Error payload.
	 * @param array        $email_data Keys: subject, to (smtp-compatible).
	 */
	public static function on_failed( $error, $email_data ): void {
		$cfg = Settings::get( 'alerts_settings', array() );
		if ( ! is_array( $cfg ) ) {
			return;
		}
		$error_s = is_array( $error ) ? wp_json_encode( $error ) : (string) $error;
		$email   = self::normalize_email( $email_data );

		if ( ! empty( $cfg['enable_slack_alerts'] ) && ! empty( $cfg['slack_data'] ) && is_array( $cfg['slack_data'] ) ) {
			foreach ( $cfg['slack_data'] as $url ) {
				if ( is_string( $url ) && $url !== '' ) {
					self::send_slack( $url, $error_s, $email );
				}
			}
		}
		if ( ! empty( $cfg['enable_webhook_alerts'] ) && ! empty( $cfg['webhook_data'] ) && is_array( $cfg['webhook_data'] ) ) {
			foreach ( $cfg['webhook_data'] as $url ) {
				if ( is_string( $url ) && $url !== '' ) {
					self::send_webhook( $url, $error_s, $email );
				}
			}
		}
		if ( ! empty( $cfg['enable_discord_alerts'] ) && ! empty( $cfg['discord_data'] ) && is_array( $cfg['discord_data'] ) ) {
			foreach ( $cfg['discord_data'] as $url ) {
				if ( is_string( $url ) && $url !== '' ) {
					self::send_discord( $url, $error_s, $email );
				}
			}
		}
	}

	/**
	 * Send a test alert (used by REST).
	 *
	 * @param string $slug slack|webhook|discord.
	 * @param string $data Webhook URL.
	 * @return true|\WP_Error
	 */
	public static function test( $slug, $data ) {
		$data = (string) $data;
		if ( ! $data ) {
			return new \WP_Error( 'doublescale_smtp_alert_empty', __( 'Invalid data.', 'doublescale' ), array( 'status' => 400 ) );
		}
		$admin = get_option( 'admin_email' );
		$email = array(
			'to'      => $admin,
			'subject' => __( 'DoubleScale SMTP: Test Alert', 'doublescale' ),
		);
		$error = __( 'This is a test error message.', 'doublescale' );
		switch ( $slug ) {
			case 'slack':
				self::send_slack( $data, $error, $email );
				return true;
			case 'webhook':
				self::send_webhook( $data, $error, $email );
				return true;
			case 'discord':
				self::send_discord( $data, $error, $email );
				return true;
			default:
				return new \WP_Error( 'doublescale_smtp_alert_slug', __( 'Invalid alert type.', 'doublescale' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param array $email_data Raw email data.
	 * @return array{subject:string,to:string}
	 */
	private static function normalize_email( $email_data ): array {
		$email_data = is_array( $email_data ) ? $email_data : array();

		return array(
			'subject' => isset( $email_data['subject'] ) ? (string) $email_data['subject'] : '',
			'to'      => isset( $email_data['to'] ) ? (string) $email_data['to'] : '',
		);
	}

	/**
	 * @param string $slack_webhook URL.
	 * @param string $error Error text.
	 * @param array  $email subject/to.
	 */
	public static function send_slack( $slack_webhook, $error, $email ): void {
		if ( ! filter_var( $slack_webhook, FILTER_VALIDATE_URL ) ) {
			return;
		}
		$logs = self::logs_screen_url();
		$msg  = array(
			array(
				'type' => 'header',
				'text' => array(
					'type'  => 'plain_text',
					'text'  => __( 'DoubleScale SMTP: Failed to send an email', 'doublescale' ),
					'emoji' => true,
				),
			),
			array(
				'type'   => 'section',
				'fields' => array(
					array(
						'type' => 'mrkdwn',
						'text' => sprintf( '*%s*: %s', __( 'Site URL', 'doublescale' ), esc_url( home_url() ) ),
					),
				),
			),
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => sprintf( '*%s*: %s', __( 'Subject', 'doublescale' ), $email['subject'] ),
				),
			),
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => sprintf( '*%s*: %s', __( 'To', 'doublescale' ), $email['to'] ),
				),
			),
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => sprintf( '*%s*: %s', __( 'Error', 'doublescale' ), $error ),
				),
			),
			array(
				'type'   => 'section',
				'fields' => array(
					array(
						'type' => 'mrkdwn',
						'text' => '<' . $logs . '|' . esc_html__( 'View email log', 'doublescale' ) . '>',
					),
				),
			),
		);

		$response = wp_remote_post(
			$slack_webhook,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'blocks' => $msg ) ),
				'timeout' => 15,
			)
		);
		self::log_remote_result( $response, 'slack', $msg );
	}

	/**
	 * @param string $webhook_url URL.
	 * @param string $error Error text.
	 * @param array  $email subject/to.
	 */
	public static function send_webhook( $webhook_url, $error, $email ): void {
		if ( ! filter_var( $webhook_url, FILTER_VALIDATE_URL ) ) {
			return;
		}
		$message = array(
			'title'     => __( 'DoubleScale SMTP: Failed to send an email', 'doublescale' ),
			'site_url'  => esc_url( home_url() ),
			'subject'   => $email['subject'],
			'to'        => $email['to'],
			'error'     => json_decode( $error, true ) !== null ? json_decode( $error, true ) : $error,
			'email_log' => self::logs_screen_url(),
		);

		$response = wp_remote_post(
			$webhook_url,
			array(
				'body'    => wp_json_encode( $message ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
			)
		);
		self::log_remote_result( $response, 'webhook', $message );
	}

	/**
	 * @param string $discord_webhook URL.
	 * @param string $error Error text.
	 * @param array  $email subject/to.
	 */
	public static function send_discord( $discord_webhook, $error, $email ): void {
		if ( ! filter_var( $discord_webhook, FILTER_VALIDATE_URL ) ) {
			return;
		}
		$logs    = self::logs_screen_url();
		$message = array(
			'content' => __( 'DoubleScale SMTP: Failed to send an email', 'doublescale' ),
			'embeds'  => array(
				array(
					'title'     => __( 'DoubleScale SMTP: Failed to send an email', 'doublescale' ),
					'url'       => esc_url( home_url() ),
					'timestamp' => gmdate( 'c' ),
					'color'     => hexdec( 'FF0000' ),
					'fields'    => array(
						array(
							'name'  => __( 'Site URL', 'doublescale' ),
							'value' => esc_url( home_url() ),
						),
						array(
							'name'  => __( 'Subject', 'doublescale' ),
							'value' => $email['subject'],
						),
						array(
							'name'  => __( 'To', 'doublescale' ),
							'value' => $email['to'],
						),
						array(
							'name'  => __( 'Error', 'doublescale' ),
							'value' => self::discord_error_text( $error ),
						),
						array(
							'name'  => __( 'View email log', 'doublescale' ),
							'value' => $logs,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			$discord_webhook,
			array(
				'body'    => wp_json_encode( $message ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
			)
		);
		self::log_remote_result( $response, 'discord', $message );
	}

	/**
	 * Discord embed field values must be strings (max ~1024 chars).
	 *
	 * @param string $error Raw error.
	 */
	private static function discord_error_text( $error ): string {
		$decoded = json_decode( (string) $error, true );
		if ( is_array( $decoded ) || is_object( $decoded ) ) {
			$t = wp_json_encode( $decoded );
		} else {
			$t = (string) $error;
		}
		if ( strlen( $t ) > 1000 ) {
			return substr( $t, 0, 997 ) . '...';
		}
		return $t;
	}

	/**
	 * @param array|\WP_Error $response HTTP response.
	 * @param string          $channel slack|webhook|discord.
	 * @param mixed           $context Context for logs.
	 */
	private static function log_remote_result( $response, $channel, $context ): void {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}
		$log = doublescale_get_logger();
		if ( is_wp_error( $response ) ) {
			$log->error(
				sprintf(
					/* translators: %s: channel name */
					__( '%s alert request error', 'doublescale' ),
					ucfirst( $channel )
				),
				array(
					'response' => $response,
					'context'  => $context,
				)
			);
			return;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( 'slack' === $channel ) {
			$body = wp_remote_retrieve_body( $response );
			if ( 'ok' !== $body ) {
				$log->error( __( 'Slack alert error', 'doublescale' ), array( 'body' => $body ) );
			} else {
				$log->info( __( 'Slack alert sent', 'doublescale' ), array() );
			}
			return;
		}
		if ( 'webhook' === $channel ) {
			if ( 200 !== (int) $code ) {
				$log->error( __( 'Webhook alert error', 'doublescale' ), array( 'code' => $code ) );
			} else {
				$log->info( __( 'Webhook alert sent', 'doublescale' ), array() );
			}
			return;
		}
		if ( 200 !== (int) $code && 204 !== (int) $code ) {
			$log->error( __( 'Discord alert error', 'doublescale' ), array( 'code' => $code ) );
		} else {
			$log->info( __( 'Discord alert sent', 'doublescale' ), array() );
		}
	}
}
