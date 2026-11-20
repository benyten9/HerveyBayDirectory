<?php
/**
 * Process class.
 *
 * @since 1.4.10
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Emailit;

defined( 'ABSPATH' ) || exit;

use Exception;
use DoubleScale\Modules\Smtp\Mailer\Provider\Process as Abstract_Process;
use WP_Error;

/**
 * Builds the POST /v2/emails body: From is a "Name <email>" string,
 * recipients and Reply-To are plain addresses.
 *
 * @see https://emailit.com/docs/api-reference/emails/
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

		$this->body['from'] = self::format_from( $email, $name );
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

				if ( ! filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
					continue;
				}

				$this->body[ $type ][] = $email_address;
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
	 * Set Reply-To. Emailit takes a single address.
	 *
	 * @param array $emails PHPMailer reply-to list, keyed by lowercased address.
	 */
	public function set_reply_to( $emails ) {

		if ( empty( $emails ) ) {
			return;
		}

		foreach ( $emails as $user ) {
			$email_address = isset( $user[0] ) ? $user[0] : '';

			if ( filter_var( $email_address, FILTER_VALIDATE_EMAIL ) ) {
				$this->body['reply_to'] = $email_address;
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

			$this->body['attachments'][] = array(
				'filename'     => $filename,
				'content'      => base64_encode( $is_string ? $source : $this->filesystem->get_contents( $source ) ),
				'content_type' => ! empty( $attachment[4] ) ? $attachment[4] : 'application/octet-stream',
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
		 * Filters Emailit email headers.
		 *
		 * @param array $headers Email headers.
		 */
		return apply_filters( 'doublescale_smtp_emailit_mailer_get_headers', $this->headers );
	}

	/**
	 * Get the email body.
	 *
	 * @return array
	 */
	public function get_body() {
		$body    = $this->body;
		$headers = $this->get_headers();

		if ( ! empty( $headers ) ) {
			$body['headers'] = $headers;
		}

		/**
		 * Filters Emailit email body.
		 *
		 * @param array $body Email body.
		 */
		return apply_filters( 'doublescale_smtp_emailit_mailer_get_body', $body );
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

			if ( ! is_array( $result ) || empty( $result['id'] ) ) {
				throw new Exception( __( 'Emailit did not return an email ID.', 'doublescale' ) );
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
				esc_html__( 'Emailit Send Email Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_emailit_send_error',
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
	 * RFC 5322 From value. Names with specials are quoted; UTF-8 stays raw
	 * because the value travels in JSON, not in a MIME header.
	 *
	 * @param string $email Email.
	 * @param string $name  Display name.
	 *
	 * @return string
	 */
	private static function format_from( $email, $name ) {
		$name = is_string( $name ) ? trim( $name ) : '';

		if ( '' === $name ) {
			return $email;
		}

		if ( preg_match( '/[()<>\[\]:;@\\\\,."]/', $name ) ) {
			$name = '"' . addcslashes( $name, '"\\' ) . '"';
		}

		return $name . ' <' . $email . '>';
	}
}
