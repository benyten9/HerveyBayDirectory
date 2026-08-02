<?php
/**
 * Process class.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SocketLabs;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Smtp\Mailer\Provider\Process as Abstract_Process;
use WP_Error;

/**
 * Process class.
 *
 * @since 1.0.0
 */
class Process extends Abstract_Process {

	/**
	 * Set email header.
	 *
	 * @since 1.0.0
	 */
	public function set_header( $name, $value ) {

		$name = sanitize_text_field( $name );

		$this->headers['CustomHeaders'][] = array(
			'Name'  => $name,
			'Value' => $value,
		);
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

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$this->body['From'] = array(
			'EmailAddress' => $email,
		);

		if ( ! empty( $name ) ) {
			$this->body['From']['FriendlyName'] = $name;
		}
	}

	/**
	 * Set email recipients: to, cc, bcc.
	 *
	 * @since 1.0.0
	 *
	 * @param array $recipients
	 */
	public function set_recipients( $recipients ) {

		if ( empty( $recipients ) ) {
			return;
		}

		foreach ( $recipients as $type => $emails ) {

			if ( empty( $emails ) || ! is_array( $emails ) ) {
				continue;
			}

			foreach ( $emails as $user ) {
				$email_address = isset( $user[0] ) ? $user[0] : false;
				$name          = isset( $user[1] ) ? $user[1] : false;

				if ( ! filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
					continue;
				}

				switch ( $type ) {
					case 'to':
						$this->body['To'][] = array(
							'EmailAddress' => $email_address,
							'FriendlyName' => $name,
						);
						break;
					case 'cc':
						$this->body['CC'][] = array(
							'EmailAddress' => $email_address,
							'FriendlyName' => $name,
						);
						break;
					case 'bcc':
						$this->body['BCC'][] = array(
							'EmailAddress' => $email_address,
							'FriendlyName' => $name,
						);
						break;
				}
			}
		}
	}

	/**
	 * Set email subject.
	 *
	 * @since 1.0.0
	 *
	 * @param string $subject
	 */
	public function set_subject( $subject ) {
		$this->body['Subject'] = sanitize_text_field( $subject );
	}

	/**
	 * Set email content.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array $content
	 */
	public function set_content( $content ) {

		if ( empty( $content ) ) {
			return;
		}

		if ( is_array( $content ) ) {

			if ( ! empty( $content['text'] ) ) {
				$this->body['TextBody'] = $content['text'];
			}

			if ( ! empty( $content['html'] ) ) {
				$this->body['HtmlBody'] = $content['html'];
			}
		} elseif ( $this->phpmailer->ContentType === 'text/plain' ) {
				$this->body['TextBody'] = $content;
		} else {
			$this->body['HtmlBody'] = $content;
		}
	}

	/**
	 * Set the Reply To headers if not set already.
	 *
	 * @since 1.0.0
	 *
	 * @param array $emails
	 */
	public function set_reply_to( $emails ) {

		if ( empty( $emails ) ) {
			return;
		}

		foreach ( $emails as $email ) {
			$email_address = isset( $email[0] ) ? $email[0] : false;
			$name          = isset( $email[1] ) ? $email[1] : false;

			if ( ! filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
				continue;
			}

			$this->body['ReplyTo'] = array(
				'EmailAddress' => $email_address,
				'FriendlyName' => $name,
			);
		}
	}

	/**
	 * Set attachments for an email.
	 *
	 * @since 1.0.0
	 *
	 * @param array $attachments The array of attachments data.
	 */
	public function set_attachments( $attachments ) {

		if ( empty( $attachments ) ) {
			return;
		}

		foreach ( $attachments as $attachment ) {
			$filepath = isset( $attachment[0] ) ? $attachment[0] : false;
			$filename = isset( $attachment[2] ) ? $attachment[2] : false;

			if ( empty( $filename ) || empty( $filepath ) ) {
				continue;
			}

			$this->body['Attachments'][] = array(
				'Name'        => $filename,
				'Content'     => base64_encode( $this->filesystem->get_contents( $filepath ) ),
				'ContentType' => mime_content_type( $filepath ),
			);
		}
	}

	/**
	 * Get the email headers.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_headers() {
		/**
		 * Filters Postmark email headers.
		 *
		 * @since 1.0.0
		 *
		 * @param array $headers Email headers.
		 */
		$headers = apply_filters( 'doublescale_smtp_socketlabs_mailer_get_headers', $this->headers );

		return $headers;
	}

	/**
	 * Get the email body.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_body() {
		/**
		 * Filters Postmark email body.
		 *
		 * @since 1.0.0
		 *
		 * @param array $body Email body.
		 */
		$body = apply_filters( 'doublescale_smtp_socketlabs_mailer_get_body', $this->body );

		return $body;
	}

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
				throw new Exception( $account_api->get_error_message() );
			}

			$body   = $this->get_body();
			$result = $account_api->send( $body );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			if ( 'Success' === $result['ErrorCode'] ) {
				$this->log_result(
					array(
						'status'   => self::SUCCEEDED,
						'response' => $result,
					)
				);

				return true;
			} else {
				$this->log_result(
					array(
						'status'   => self::FAILED,
						'response' => $result,
					)
				);

				return false;
			}
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'SocketLabs Send Email Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_socketlabs_send_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
			$this->log_result(
				array(
					'status'   => self::FAILED,
					'response' => $e->getMessage(),
				)
			);
			return false;
		}
	}
}
