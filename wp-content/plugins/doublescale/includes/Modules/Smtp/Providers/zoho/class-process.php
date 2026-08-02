<?php
/**
 * Process class.
 *
 * @since 1.0.0
 *
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Zoho;

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
		$this->body['fromAddress'] = $this->phpmailer->addrFormat( array( $email, $name ) );
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

			switch ( $type ) {
				case 'to':
					$this->body['toAddress'] = $this->addrs_format( $emails );
					break;
				case 'cc':
					$this->body['ccAddress'] = $this->addrs_format( $emails );
					break;
				case 'bcc':
					$this->body['bccAddress'] = $this->addrs_format( $emails );
					break;
			}
		}
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
				$this->body['mailFormat'] = 'plaintext';
				$this->body['content']    = $content['text'];
			}

			if ( ! empty( $content['html'] ) ) {
				$this->body['mailFormat'] = 'html';
				$this->body['content']    = $content['html'];
			}
		} elseif ( $this->phpmailer->ContentType === 'text/plain' ) {
				$this->body['mailFormat'] = 'plaintext';
				$this->body['content']    = $content;
		} else {
			$this->body['mailFormat'] = 'html';
			$this->body['content']    = $content;
		}
	}

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
			$file = $this->filesystem->get_contents( $filepath );

			try {
				$account_id = $this->connection['account_id'];
				/** @var Account_API|WP_Error */ // phpcs:ignore
				$account_api = $this->provider->accounts->connect( $account_id );
				if ( is_wp_error( $account_api ) ) {
					throw new \Exception( $account_api->get_error_message() );
				}

				$response = $account_api->upload_attachment( $filename, $file );
				if ( is_wp_error( $response ) ) {
					throw new \Exception( $response->get_error_message() );
				}

				$attachment = $response['data'] ?? array();
				doublescale_get_logger()->info(
					esc_html__( 'Zoho Upload Attachment', 'doublescale' ),
					array(
						'code'     => 'doublescale_smtp_zoho_upload_attachment',
						'response' => $response,
					)
				);
			} catch ( \Exception $e ) {
				doublescale_get_logger()->error(
					esc_html__( 'Zoho Upload Attachment Error', 'doublescale' ),
					array(
						'code'  => 'doublescale_smtp_zoho_upload_attachment_error',
						'error' => array(
							'code'  => $e->getCode(),
							'error' => $e->getMessage(),
						),
					)
				);
			}

			if ( ! empty( $attachment ) ) {
				$this->body['attachments'][] = $attachment;
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
		$this->body['subject'] = $subject;
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
				throw new \Exception( $account_api->get_error_message() );
			}

			$body     = $this->body;
			$response = $account_api->send( $body );

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
				esc_html__( 'Zoho Send Error', 'doublescale' ),
				array(
					'code'  => 'doublescale_smtp_zoho_send_error',
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
