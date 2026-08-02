<?php
/**
 * Account_API class.
 *
 * @since 1.0.0
 * @package smtp
 * @subpackage mailers
 */

namespace DoubleScale\Modules\Smtp\Providers\Aws;

use WP_Error;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-ses-query-client.php';

/**
 * Account_API class.
 *
 * @since 1.0.0
 */
class Account_API {

	/**
	 * Access Key
	 *
	 * @var string
	 */
	protected $access_key;

	/**
	 * Secret Key
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * Region
	 *
	 * @var string
	 */
	protected $region;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $access_key Access Key.
	 * @param string $secret_key Secret Key.
	 * @param string $region Region.
	 */
	public function __construct( $access_key, $secret_key, $region ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = $region;
	}

	/**
	 * Get SES client (Query API + SigV4, no aws-sdk-php).
	 *
	 * @since 1.0.0
	 *
	 * @return Ses_Client
	 */
	public function get_client() {
		return new Ses_Client(
			new Ses_Query_Client(
				$this->access_key,
				$this->secret_key,
				$this->region
			)
		);
	}

	/**
	 * Send batch emails using AWS SES SendBulkTemplatedEmail API
	 *
	 * This method uses the native AWS SES bulk sending API which can send
	 * up to 50 personalized emails per API call.
	 *
	 * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_SendBulkTemplatedEmail.html
	 * @see https://docs.aws.amazon.com/ses/latest/APIReference/API_CreateTemplate.html
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_args {
	 *     Batch email arguments.
	 *
	 *     @type string $from_email          Sender email address
	 *     @type string $from_name           Sender name
	 *     @type array  $to                  Array of recipient email addresses (max 50)
	 *     @type string $subject             Email subject (can contain {{key}} placeholders)
	 *     @type string $html                HTML body (can contain {{key}} placeholders)
	 *     @type string $text                Plain text body (optional)
	 *     @type string $reply_to            Reply-to email (optional)
	 *     @type array  $recipient_variables Associative array keyed by email with personalization data
	 *     @type array  $tags                Tags for tracking (optional)
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

		// AWS SES SendBulkTemplatedEmail supports max 50 destinations per call
		if ( count( $batch_args['to'] ) > 50 ) {
			return new WP_Error( 'too_many_recipients', __( 'Maximum 50 recipients per batch.', 'doublescale' ) );
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

		// Build from address
		$from_email = $batch_args['from_email'] ?? '';
		$from_name  = $batch_args['from_name'] ?? '';
		$from       = ! empty( $from_name ) ? "{$from_name} <{$from_email}>" : $from_email;

		// Get SES client
		try {
			$client = $this->get_client();
		} catch ( \Exception $e ) {
			$error = new WP_Error( 'aws_client_error', $e->getMessage() );
			$this->log_batch_emails( $batch_args, $recipients, $error );
			return $error;
		}

		// Generate unique template name for this batch
		$template_name = 'doublescale_smtp_bulk_' . uniqid() . '_' . time();

		// Convert {{key}} placeholders to SES format {{key}}
		// SES uses the same format, so we just need to ensure proper escaping
		$subject = $batch_args['subject'] ?? '';
		$html    = $batch_args['html'] ?? '';
		$text    = $batch_args['text'] ?? '';

		// Create template
		try {
			$template_params = array(
				'Template' => array(
					'TemplateName' => $template_name,
					'SubjectPart'  => $subject,
					'HtmlPart'     => $html,
				),
			);

			if ( ! empty( $text ) ) {
				$template_params['Template']['TextPart'] = $text;
			}

			$client->createTemplate( $template_params );
		} catch ( \Exception $e ) {
			$error = new WP_Error( 'template_creation_failed', $e->getMessage() );
			$this->log_batch_emails( $batch_args, $recipients, $error );
			return $error;
		}

		// Track results
		$sent_count  = 0;
		$failed      = array();
		$message_ids = array();

		// Prepare default template data and clean null values
		$default_data = $this->get_default_template_data( $batch_args );
		$default_data = array_map(
			function ( $v ) {
				return is_null( $v ) ? '' : (string) $v;
			},
			$default_data
		);

		// Build destinations array
		$destinations = array();
		foreach ( $recipients as $email ) {
			$recipient_vars = $batch_args['recipient_variables'][ $email ] ?? array();

			// Clean recipient vars: convert null to empty string and cast to string
			$clean_recipient_vars = array_map(
				function ( $v ) {
					return is_null( $v ) ? '' : (string) $v;
				},
				$recipient_vars
			);

			// Merge with default data to ensure all template placeholders have values
			// This prevents Rendering Failures when a recipient is missing some variables
			$final_vars = array_merge( $default_data, $clean_recipient_vars );

			$destinations[] = array(
				'Destination'             => array(
					'ToAddresses' => array( $email ),
				),
				'ReplacementTemplateData' => wp_json_encode( $final_vars ),
			);
		}

		// Build bulk email params
		$bulk_params = array(
			'Source'              => $from,
			'Template'            => $template_name,
			'Destinations'        => $destinations,
			'DefaultTemplateData' => wp_json_encode( $default_data ),
		);

		// Add Reply-To
		if ( ! empty( $batch_args['reply_to'] ) ) {
			$bulk_params['ReplyToAddresses'] = array( $batch_args['reply_to'] );
		}

		// Add tags (SES supports up to 50 tags per email, each tag name must be unique)
		if ( ! empty( $batch_args['tags'] ) && is_array( $batch_args['tags'] ) ) {
			$bulk_params['DefaultTags'] = array();
			foreach ( array_slice( $batch_args['tags'], 0, 50 ) as $index => $tag ) {
				// Tag can be "name:value" format or just a value
				if ( strpos( $tag, ':' ) !== false ) {
					list( $tag_name, $tag_value ) = explode( ':', $tag, 2 );
				} else {
					// Use indexed name to ensure uniqueness
					$tag_name  = 'tag_' . $index;
					$tag_value = $tag;
				}
				$bulk_params['DefaultTags'][] = array(
					'Name'  => $tag_name,
					'Value' => $tag_value,
				);
			}
		}

		// Send bulk email
		try {
			$send_result = $client->sendBulkTemplatedEmail( $bulk_params );

			$status_list = $send_result->get( 'Status' );
			if ( ! empty( $status_list ) && is_array( $status_list ) ) {
				foreach ( $status_list as $index => $status ) {
					$recipient_email = $recipients[ $index ] ?? '';

					if ( ( $status['Status'] ?? '' ) === 'Success' ) {
						++$sent_count;
						if ( ! empty( $status['MessageId'] ) ) {
							$message_ids[] = $status['MessageId'];
						}
					} else {
						$failed[] = array(
							'email' => $recipient_email,
							'error' => $status['Error'] ?? $status['Status'] ?? __( 'Unknown error', 'doublescale' ),
						);
					}
				}
			}
		} catch ( \Exception $e ) {
			// If bulk send fails, mark all recipients as failed
			foreach ( $recipients as $email ) {
				$failed[] = array(
					'email' => $email,
					'error' => $e->getMessage(),
				);
			}
		}

		// Clean up: delete the temporary template
		try {
			$client->deleteTemplate(
				array(
					'TemplateName' => $template_name,
				)
			);
		} catch ( \Exception $e ) {
			// Log but don't fail the operation if template deletion fails.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional best-effort diagnostic when SES template cleanup fails; the send already succeeded.
			error_log( 'smtp: Failed to delete temporary SES template: ' . $e->getMessage() );
		}

		// Build result
		$result = array(
			'id'          => ! empty( $message_ids ) ? $message_ids[0] : '',
			'message'     => sprintf(
				/* translators: 1: sent count, 2: total count */
				__( 'Sent %1$d of %2$d emails successfully.', 'doublescale' ),
				$sent_count,
				count( $recipients )
			),
			'sent_count'  => $sent_count,
			'failed'      => $failed,
			'message_ids' => $message_ids,
		);

		// Log the batch
		$this->log_batch_emails( $batch_args, $recipients, $sent_count > 0 ? $result : new WP_Error( 'all_failed', __( 'All emails failed to send.', 'doublescale' ) ) );

		// Return error if all failed
		if ( $sent_count === 0 ) {
			return new WP_Error(
				'batch_send_failed',
				__( 'All emails in batch failed to send.', 'doublescale' ),
				array( 'failed' => $failed )
			);
		}

		return $result;
	}

	/**
	 * Get default template data for fallback values
	 *
	 * Extracts all placeholder keys from subject/body and creates default values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $batch_args Batch arguments containing subject, html, text
	 *
	 * @return array Default template data with empty strings for all placeholders
	 */
	protected function get_default_template_data( $batch_args ) {
		$defaults = array();
		$content  = ( $batch_args['subject'] ?? '' ) . ( $batch_args['html'] ?? '' ) . ( $batch_args['text'] ?? '' );

		// Extract all {{key}} placeholders
		if ( preg_match_all( '/\{\{([^}]+)\}\}/', $content, $matches ) ) {
			foreach ( $matches[1] as $key ) {
				$defaults[ $key ] = '';
			}
		}

		return $defaults;
	}

	/**
	 * Replace placeholders in content with recipient-specific values
	 *
	 * Replaces {{key}} with corresponding values from recipient variables.
	 *
	 * @since 1.0.0
	 *
	 * @param string $content         Content with placeholders
	 * @param array  $recipient_vars  Recipient-specific variables
	 *
	 * @return string Content with replaced values
	 */
	protected function replace_placeholders( $content, $recipient_vars ) {
		if ( empty( $recipient_vars ) || ! is_array( $recipient_vars ) ) {
			return $content;
		}

		foreach ( $recipient_vars as $key => $value ) {
			// Replace {{key}} with value
			$content = str_replace( '{{' . $key . '}}', $value, $content );
		}

		return $content;
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

			// Find AWS connection from default or fallback
			foreach ( array( 'default_connection', 'fallback_connection' ) as $key ) {
				if ( ! empty( $settings[ $key ] ) && isset( $connections[ $settings[ $key ] ] ) ) {
					$conn = $connections[ $settings[ $key ] ];
					if ( ( $conn['mailer'] ?? '' ) === 'aws' ) {
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
			'aws',
			$connection_id,
			$account_id,
			$response
		);
	}
}
