<?php
/**
 * Process class.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Outlook;

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
	 * Set the From information for an email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email
	 * @param string $name
	 */
	public function set_from( $email, $name ) {
		$this->phpmailer->setFrom( $email, $name );
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

			$base64   = chunk_split( base64_encode( $this->phpmailer->getSentMIMEMessage() ), 76, "\n" );
			$response = $account_api->send( $base64 );
			if ( is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			$this->log_result(
				array(
					'status'   => self::SUCCEEDED,
					'response' => $response,
				)
			);
			return true;
		} catch ( \Exception $e ) {
			$this->phpmailer->ErrorInfo = $e->getMessage();
			doublescale_get_logger()->error(
				esc_html__( 'Outlook Send Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_outlook_send_error',
					'error' => array(
						'code'  => $e->getCode(),
						'error' => $e->getMessage(),
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
