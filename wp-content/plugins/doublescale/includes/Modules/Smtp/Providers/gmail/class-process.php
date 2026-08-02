<?php
/**
 * Process class.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Gmail;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Smtp\Mailer\Provider\Process as Abstract_Process;
use WP_Error;

/**
 * Process class.
 *
 * @since 1.0.0
 */
class Process extends Abstract_Process {

	/**
	 * Gmail API send endpoint.
	 */
	private const GMAIL_SEND_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	/**
	 * Extract a short error message from a Gmail API JSON error body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private function format_gmail_api_error_body( $body ) {
		$json = json_decode( (string) $body, true );
		if ( is_array( $json ) ) {
			if ( ! empty( $json['error']['errors'][0]['message'] ) ) {
				return (string) $json['error']['errors'][0]['message'];
			}
			if ( ! empty( $json['error']['message'] ) ) {
				return (string) $json['error']['message'];
			}
		}

		return is_string( $body ) && $body !== '' ? substr( $body, 0, 500 ) : 'Gmail API error';
	}

	/**
	 * Set the From information for an email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email
	 * @param string $name
	 */
	public function set_from( $email, $name ) {
		try {
			$account_id = $this->connection['account_id'];
			/** @var Account_API|WP_Error */ // phpcs:ignore
			$account_api = $this->provider->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new \Exception( $account_api->get_error_message() );
			}

			$user = $account_api->get_profile();
			if ( is_wp_error( $user ) ) {
				throw new \Exception( $user->get_error_message() );
			}
			$email = $user->emailAddress;

			$this->phpmailer->From   = $email;
			$this->phpmailer->Sender = $email;
		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Gmail Send Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_gmail_send_error',
					'error' => array(
						'code'  => $e->getCode(),
						'error' => $e->getMessage(),
					),
				)
			);
			// Still set From from the connection / PHPMailer so the MIME "From" matches the
			// OAuth account; Gmail API rejects sends when the raw message From ≠ authorized user.
			$fallback = $this->get_from_email();
			if ( is_email( $fallback ) ) {
				$this->phpmailer->From   = $fallback;
				$this->phpmailer->Sender = $fallback;
			}
			return;
		}
	}

	/**
	 * Set email recipients: to, cc, bcc.
	 *
	 * @since 1.0.0
	 *
	 * @param array $recipients
	 */
	public function set_recipients( $recipients ) {}

	/**
	 * Set email content.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array $content
	 */
	public function set_content( $content ) {}

	/**
	 * Set the Reply To headers if not set already.
	 *
	 * @since 1.0.0
	 *
	 * @param array $emails
	 */
	public function set_reply_to( $emails ) {}

	/**
	 * Set attachments for an email.
	 *
	 * @since 1.0.0
	 *
	 * @param array $attachments The array of attachments data.
	 */
	public function set_attachments( $attachments ) {}

	/**
	 * Set email subject.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject
	 */
	public function set_subject( $subject ) {}

	/**
	 * Send email.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function send() {
		try {
			$account_id = $this->connection['account_id'];
			/** @var Account_API|WP_Error */ // phpcs:ignore
			$account_api = $this->provider->accounts->connect( $account_id );
			if ( is_wp_error( $account_api ) ) {
				throw new \Exception( $account_api->get_error_message() );
			}
			$this->phpmailer->preSend();

			$access_token = $account_api->get_access_token();
			if ( is_wp_error( $access_token ) ) {
				throw new \Exception( $access_token->get_error_message() );
			}

			$base64 = str_replace(
				array( '+', '/', '=' ),
				array( '-', '_', '' ),
				base64_encode( $this->phpmailer->getSentMIMEMessage() ) //phpcs:ignore
			);

			$response = wp_remote_post(
				self::GMAIL_SEND_URL,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . trim( (string) $access_token ),
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'raw' => $base64 ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( $code < 200 || $code > 299 ) {
				throw new \Exception( $this->format_gmail_api_error_body( $body ) );
			}

			$decoded = json_decode( $body, true );
			$this->log_result(
				array(
					'status'   => self::SUCCEEDED,
					'response' => is_array( $decoded ) ? $decoded : $body,
				)
			);
			return true;
		} catch ( \Throwable $e ) {
			$detail                     = $e->getMessage();
			$this->phpmailer->ErrorInfo = $detail;
			doublescale_get_logger()->error(
				esc_html__( 'Gmail Send Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_gmail_send_error',
					'error' => array(
						'code'  => $e->getCode(),
						'error' => $detail,
					),
				)
			);
			$this->log_result(
				array(
					'status'   => self::FAILED,
					'response' => $detail,
				)
			);
			return false;
		}
	}
}
