<?php
/**
 * Account_API class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\SendGrid;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Account_API class.
 *
 * @since 1.0.0
 */
class Account_API {

	/**
	 * API
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Sending Domain
	 *
	 * @var string
	 */
	protected $sending_domain;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $api_key API key.
	 * @param string $sending_domain Sending domain.
	 */
	public function __construct( $api_key, $sending_domain ) {
		$this->api_key        = $api_key;
		$this->sending_domain = $sending_domain;
	}

	/**
	 * Get Client.
	 *
	 * @since 1.0.0
	 *
	 * @return Http_Client
	 */
	public function get_client() {
		return new Http_Client( $this->api_key );
	}

	/**
	 * Get the sending domain.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_sending_domain() {
		return $this->sending_domain;
	}

	/**
	 * Send batch emails
	 *
	 * SendGrid supports up to 1000 recipients per API call using personalizations.
	 * Each personalization can have its own substitution variables.
	 *
	 * @see https://docs.sendgrid.com/api-reference/mail-send/mail-send
	 * @see https://docs.sendgrid.com/for-developers/sending-email/personalizations
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_args {
	 *     Batch email arguments.
	 *
	 *     @type string $from_email          Sender email address
	 *     @type string $from_name           Sender name
	 *     @type array  $to                  Array of recipient email addresses
	 *     @type string $subject             Email subject (can contain {{key}} substitutions)
	 *     @type string $html                HTML body (can contain {{key}} substitutions)
	 *     @type string $text                Plain text body (optional)
	 *     @type string $reply_to            Reply-to email (optional)
	 *     @type array  $recipient_variables Associative array keyed by email with substitution data
	 *     @type array  $categories          Categories for tracking (optional, max 10)
	 *     @type array  $headers             Custom headers (optional)
	 *     @type string $connection_id       Connection ID for logging (optional)
	 *     @type string $account_id          Account ID for logging (optional)
	 * }
	 *
	 * @return WP_Error|array
	 */
	public function send_batch( $batch_args ) {
		// Validate recipients
		if ( empty( $batch_args['to'] ) || ! is_array( $batch_args['to'] ) ) {
			return new WP_Error( 'invalid_recipients', __( 'Recipients array is required.', 'doublescale' ) );
		}

		// SendGrid limit: 1000 personalizations per request
		if ( count( $batch_args['to'] ) > 1000 ) {
			return new WP_Error( 'too_many_recipients', __( 'Maximum 1000 recipients per batch.', 'doublescale' ) );
		}

		$recipients = array();
		foreach ( $batch_args['to'] as $email ) {
			if ( ! is_email( $email ) ) {
				continue;
			}
			$recipients[] = $email;
		}

		if ( empty( $recipients ) ) {
			return new WP_Error( 'no_valid_recipients', __( 'No valid recipient emails found.', 'doublescale' ) );
		}

		try {
			// Create the Mail object
			$email = new Mail();

			// Set from
			$from_email = $batch_args['from_email'] ?? '';
			$from_name  = $batch_args['from_name'] ?? '';
			$email->setFrom( $from_email, $from_name );

			// Set subject (global, can be overridden per personalization)
			$email->setSubject( $batch_args['subject'] ?? '' );

			// Add content
			if ( ! empty( $batch_args['text'] ) ) {
				$email->addContent( 'text/plain', $batch_args['text'] );
			}
			if ( ! empty( $batch_args['html'] ) ) {
				$email->addContent( 'text/html', $batch_args['html'] );
			}

			// Set reply-to
			if ( ! empty( $batch_args['reply_to'] ) ) {
				$email->setReplyTo( $batch_args['reply_to'] );
			}

			// Add custom headers
			if ( ! empty( $batch_args['headers'] ) && is_array( $batch_args['headers'] ) ) {
				foreach ( $batch_args['headers'] as $name => $value ) {
					$email->addHeader( $name, $value );
				}
			}

			// Add categories (SendGrid's version of tags)
			if ( ! empty( $batch_args['categories'] ) && is_array( $batch_args['categories'] ) ) {
				foreach ( array_slice( $batch_args['categories'], 0, 10 ) as $category ) {
					$email->addCategory( $category );
				}
			} elseif ( ! empty( $batch_args['tags'] ) && is_array( $batch_args['tags'] ) ) {
				// Support 'tags' parameter as alias for categories
				foreach ( array_slice( $batch_args['tags'], 0, 10 ) as $tag ) {
					$email->addCategory( $tag );
				}
			}

			// Enable tracking
			$email->setClickTracking( true, true );
			$email->setOpenTracking( true );

			// Add personalizations (one per recipient with their substitutions)
			foreach ( $recipients as $recipient_email ) {
				$personalization = new Personalization();
				$personalization->addTo( new To( $recipient_email ) );

				// Add substitutions for this recipient
				if ( ! empty( $batch_args['recipient_variables'][ $recipient_email ] ) ) {
					$variables = $batch_args['recipient_variables'][ $recipient_email ];
					foreach ( $variables as $key => $value ) {
						// SendGrid uses {{key}} syntax for substitutions
						$personalization->addSubstitution( '{{' . $key . '}}', (string) $value );
					}
				}

				$email->addPersonalization( $personalization );
			}

			// Send the email
			$client   = $this->get_client();
			$response = $client->send( $email );

			$status_code = $response->statusCode();

			if ( $status_code === 202 ) {
				$result = array(
					'id'         => '', // SendGrid doesn't return message ID in batch send
					'message'    => __( 'Batch email sent successfully.', 'doublescale' ),
					'sent_count' => count( $recipients ),
					'failed'     => array(),
				);

				// Log the batch
				$this->log_batch_emails( $batch_args, $recipients, $result );

				return $result;
			} else {
				$body  = $response->body();
				$error = new WP_Error(
					'sendgrid_batch_error',
					__( 'SendGrid API error.', 'doublescale' ),
					array(
						'status' => $status_code,
						'body'   => $body,
					)
				);

				$this->log_batch_emails( $batch_args, $recipients, $error );

				return $error;
			}
		} catch ( \Exception $e ) {
			$error = new WP_Error(
				'sendgrid_exception',
				$e->getMessage(),
				array( 'code' => $e->getCode() )
			);

			$this->log_batch_emails( $batch_args, $recipients, $error );

			return $error;
		}
	}

	/**
	 * Log batch emails to smtp email log
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_args  Original batch arguments
	 * @param array $recipients  Valid recipient emails
	 * @param mixed $result      API response (array on success, WP_Error on failure)
	 */
	protected function log_batch_emails( $batch_args, $recipients, $result ) {
		// Check if email logging function exists
		if ( ! function_exists( 'doublescale_get_smtp_email_log' ) ) {
			return;
		}

		$smtp_outbound_log = doublescale_get_smtp_email_log();
		if ( ! $smtp_outbound_log ) {
			return;
		}

		// Get connection info from settings if not provided
		$connection_id = $batch_args['connection_id'] ?? '';
		$account_id    = $batch_args['account_id'] ?? '';

		// If no connection info, try to get from settings
		if ( empty( $connection_id ) || empty( $account_id ) ) {
			$settings    = get_option( 'doublescale_smtp_settings', array() );
			$connections = $settings['connections'] ?? array();

			// Find SendGrid connection from default or fallback
			foreach ( array( 'default_connection', 'fallback_connection' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) && isset( $connections[ $settings[ $key ] ] ) ) {
					$conn = $connections[ $settings[ $key ] ];
					if ( ( $conn['mailer'] ?? '' ) === 'sendgrid' ) {
						$connection_id = $settings[ $key ];
						$account_id    = $conn['account_id'] ?? '';
						break;
					}
				}
			}
		}

		$status   = is_wp_error( $result ) ? 'failed' : 'succeeded';
		$response = is_wp_error( $result ) ? $result->get_error_message() : $result;

		// Build from string
		$from_email = $batch_args['from_email'] ?? '';
		$from_name  = $batch_args['from_name'] ?? '';
		$from       = ! empty( $from_name ) ? "{$from_name} <{$from_email}>" : $from_email;

		// Log one entry for the batch (not per recipient to avoid log spam)
		$subject         = $batch_args['subject'] ?? '';
		$body            = $batch_args['html'] ?? $batch_args['text'] ?? '';
		$headers         = $batch_args['headers'] ?? array();
		$attachments     = array();
		$recipients_data = array(
			'to'       => implode( ', ', $recipients ),
			'cc'       => '',
			'bcc'      => '',
			'reply_to' => $batch_args['reply_to'] ?? '',
		);

		$smtp_outbound_log->handle(
			$subject . ' [Batch: ' . count( $recipients ) . ' recipients]',
			$body,
			$headers,
			$attachments,
			$from,
			$recipients_data,
			$status,
			'sendgrid',
			$connection_id,
			$account_id,
			$response
		);
	}
}
