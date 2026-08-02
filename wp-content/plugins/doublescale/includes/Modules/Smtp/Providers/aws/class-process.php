<?php
/**
 * Process class.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Aws;

use Exception;
use DoubleScale\Modules\Smtp\Mailer\Provider\Process as Abstract_Process;
use WP_Error;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-ses-query-client.php';

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
		$this->phpmailer->From   = $email;
		$this->phpmailer->Sender = $email;
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
				throw new Exception( $account_api->get_error_message() );
			}

			$this->phpmailer->preSend();

			$body   = array(
				'RawMessage' => array(
					'Data' => $this->phpmailer->getSentMIMEMessage(),
				),
			);
			$client = $account_api->get_client();
			$result = $client->sendRawEmail( $body );

			if ( ! empty( $result->get( 'MessageId' ) ) ) {
				$this->log_result(
					array(
						'status'   => self::SUCCEEDED,
						'response' => array(
							'MessageId' => $result->get( 'MessageId' ),
						),
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
		} catch ( Ses_Exception $e ) {
			doublescale_get_logger()->error(
				esc_html__( 'Aws Send Email Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_aws_send_error',
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
