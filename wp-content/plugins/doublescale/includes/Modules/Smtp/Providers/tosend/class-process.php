<?php
/**
 * Process class.
 *
 * @since 1.4.10
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\ToSend;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Smtp\Mailer\Provider\Process as Abstract_Process;
use WP_Error;

/**
 * Builds the POST /v2/emails body: addresses are {email, name} objects.
 *
 * @see https://tosend.com/docs/api/send-email/
 *
 * @since 1.4.10
 */
class Process extends Abstract_Process {

	/**
	 * Set the From information for an email.
	 *
	 * @param string $email Email address.
	 * @param string $name  Display name.
	 */
	public function set_from( $email, $name ) {

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return;
		}

		$this->body['from'] = self::address( $email, $name );
	}

	/**
	 * Set email recipients: to, cc, bcc.
	 *
	 * @param array $recipients Lists keyed by to, cc and bcc.
	 */
	public function set_recipients( $recipients ) {

		if ( empty( $recipients ) ) {
			return;
		}

		foreach ( $recipients as $type => $emails ) {

			if ( ! in_array( $type, array( 'to', 'cc', 'bcc' ), true ) || empty( $emails ) || ! is_array( $emails ) ) {
				continue;
			}

			foreach ( $emails as $user ) {
				$email_address = isset( $user[0] ) ? $user[0] : '';
				$name          = isset( $user[1] ) ? $user[1] : '';

				if ( ! filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
					continue;
				}

				$this->body[ $type ][] = self::address( $email_address, $name );
			}
		}
	}

	/**
	 * Set email subject.
	 *
	 * @param string $subject Subject.
	 */
	public function set_subject( $subject ) {
		$this->body['subject'] = self::sanitize_subject( $subject );
	}

	/**
	 * Set email content.
	 *
	 * @param string|array $content Body, or an array with text and html.
	 */
	public function set_content( $content ) {

		if ( empty( $content ) ) {
			return;
		}

		if ( is_array( $content ) ) {

			if ( ! empty( $content['text'] ) ) {
				$this->body['text'] = $content['text'];
			}

			if ( ! empty( $content['html'] ) ) {
				$this->body['html'] = $content['html'];
			}
		} elseif ( 'text/plain' === $this->phpmailer->ContentType ) {
			$this->body['text'] = $content;
		} else {
			$this->body['html'] = $content;
		}
	}

	/**
	 * Set Reply-To. toSend takes a single address.
	 *
	 * @param array $emails PHPMailer reply-to list, keyed by lowercased address.
	 */
	public function set_reply_to( $emails ) {

		if ( empty( $emails ) ) {
			return;
		}

		foreach ( $emails as $user ) {
			$email_address = isset( $user[0] ) ? $user[0] : '';
			$name          = isset( $user[1] ) ? $user[1] : '';

			if ( filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
				$this->body['reply_to'] = self::address( $email_address, $name );
				return;
			}
		}
	}

	/**
	 * Set attachments for an email.
	 *
	 * @param array $attachments PHPMailer attachments: [0] path or string, [2] name, [4] type, [5] is string.
	 */
	public function set_attachments( $attachments ) {

		if ( empty( $attachments ) ) {
			return;
		}

		foreach ( $attachments as $attachment ) {
			$source    = isset( $attachment[0] ) ? $attachment[0] : '';
			$filename  = isset( $attachment[2] ) ? $attachment[2] : '';
			$is_string = ! empty( $attachment[5] );

			if ( empty( $filename ) || empty( $source ) ) {
				continue;
			}

			// A file that can't be read is left out, not sent as an empty attachment.
			$content = $is_string ? $source : ( is_readable( $source ) ? $this->filesystem->get_contents( $source ) : false );
			if ( false === $content || '' === $content ) {
				continue;
			}

			$this->body['attachments'][] = array(
				'name'    => $filename,
				'type'    => ! empty( $attachment[4] ) ? $attachment[4] : 'application/octet-stream',
				'content' => base64_encode( $content ),
			);
		}
	}

	/**
	 * Get the email headers.
	 *
	 * @return array
	 */
	public function get_headers() {
		/**
		 * Filters toSend email headers.
		 *
		 * @param array $headers Email headers.
		 */
		return apply_filters( 'doublescale_smtp_tosend_mailer_get_headers', $this->headers );
	}

	/**
	 * Get the email body.
	 *
	 * @return array
	 */
	public function get_body() {
		$body = $this->body;

		// toSend rejects the whole message when a header value is not printable US-ASCII.
		$headers = array_filter(
			$this->get_headers(),
			static function ( $value ) {
				return is_string( $value ) && 1 === preg_match( '/^[\x20-\x7E]*$/', $value );
			}
		);

		if ( ! empty( $headers ) ) {
			$body['headers'] = $headers;
		}

		/**
		 * Filters toSend email body.
		 *
		 * @param array $body Email body.
		 */
		return apply_filters( 'doublescale_smtp_tosend_mailer_get_body', $body );
	}

	/**
	 * Send email.
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

			$result = $account_api->send( $this->get_body() );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}

			if ( ! is_array( $result ) || empty( $result['message_id'] ) ) {
				throw new Exception( __( 'toSend did not return a message ID.', 'doublescale' ) );
			}

			$this->log_result(
				array(
					'status'   => self::SUCCEEDED,
					'response' => $result,
				)
			);

			return true;
		} catch ( Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'toSend Send Email Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_tosend_send_error',
					'error' => array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					),
				)
			);
			$this->phpmailer->ErrorInfo = $e->getMessage();
			$this->log_result(
				array(
					'status'   => self::FAILED,
					'response' => $e->getMessage(),
				)
			);
			return false;
		}
	}

	/**
	 * Address object; the name key is left out when empty.
	 *
	 * @param string $email Email.
	 * @param string $name  Display name.
	 *
	 * @return array
	 */
	private static function address( $email, $name ) {
		$address = array( 'email' => $email );

		if ( is_string( $name ) && '' !== trim( $name ) ) {
			$address['name'] = $name;
		}

		return $address;
	}
}
