<?php
/**
 * Abstract BounceHandler
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Abstracts;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * BounceHandler abstract class
 */
abstract class BounceHandler {

	// Meta key constants to avoid magic strings.
	const META_SOFT_BOUNCE_COUNT  = '_soft_bounce_count';
	const META_LAST_BOUNCE_REASON = '_last_bounce_reason';
	const META_LAST_BOUNCE_DATE   = '_last_bounce_date';
	const META_BOUNCE_REASON      = '_bounce_reason';
	const META_BOUNCE_TYPE        = '_bounce_type';
	const META_BOUNCE_DATE        = '_bounce_date';
	const META_BOUNCE_DIAGNOSTIC  = '_bounce_diagnostic';
	const META_BOUNCE_PROVIDER    = '_bounce_provider';

	/**
	 * Provider Name
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * Provider Description
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Documentation URL
	 *
	 * @var string
	 */
	protected $doc_url = '';

	/**
	 * Setup Instructions
	 *
	 * @var string
	 */
	protected $setup_instructions = '';

	/**
	 * Webhook data
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Get provider name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get provider description
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get documentation URL
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_doc_url() {
		return $this->doc_url;
	}

	/**
	 * Get setup instructions
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_setup_instructions() {
		return $this->setup_instructions;
	}

	/**
	 * Get provider metadata
	 *
	 * Returns all provider metadata in a single array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_metadata() {
		return array(
			'name'              => $this->get_name(),
			'description'       => $this->get_description(),
			'doc_url'           => $this->get_doc_url(),
			'setup_instructions' => $this->get_setup_instructions(),
		);
	}

	/**
	 * Set webhook data
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Webhook data.
	 *
	 * @return void
	 */
	public function set_data( $data ) {
		$this->data = $data;
	}

	/**
	 * Handle webhook
	 * Must be implemented by provider-specific handlers
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	abstract public function handle();

	/**
	 * Mark contact as bounced
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Contact email.
	 * @param array  $metadata Bounce metadata.
	 *
	 * @return bool
	 */
	protected function mark_contact_bounced( $email, $metadata = array() ) {
		// Sanitize email first.
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			$this->log(
				'Invalid email for bounce: ' . $email,
				'warning',
				array( 'email' => $email )
			);
			return false;
		}

		// Sanitize metadata before processing.
		$metadata = $this->sanitize_metadata( $metadata );

		// Get contact.
		$contact = ContactModel::get_by_email( $email );

		if ( ! $contact ) {
			$this->log(
				"Contact not found for email: $email",
				'info',
				array( 'email' => $email )
			);
			return false;
		}

		// Handle soft bounces.
		if ( isset( $metadata['bounce_type'] ) && $metadata['bounce_type'] === 'soft' ) {
			return $this->handle_soft_bounce( $contact, $metadata );
		}

		// Mark as hard bounced.
		return $this->mark_hard_bounced( $contact, $metadata );
	}

	/**
	 * Sanitize metadata before storing
	 *
	 * @since 1.0.0
	 *
	 * @param array $metadata Raw metadata from webhook.
	 *
	 * @return array Sanitized metadata.
	 */
	protected function sanitize_metadata( $metadata ) {
		$clean = array();

		if ( isset( $metadata['reason'] ) ) {
			$clean['reason'] = sanitize_text_field( $metadata['reason'] );
		}

		if ( isset( $metadata['bounce_type'] ) && in_array( $metadata['bounce_type'], array( 'hard', 'soft' ), true ) ) {
			$clean['bounce_type'] = $metadata['bounce_type'];
		}

		if ( isset( $metadata['timestamp'] ) ) {
			$clean['timestamp'] = absint( $metadata['timestamp'] );
		}

		if ( isset( $metadata['diagnostic_code'] ) ) {
			$clean['diagnostic_code'] = sanitize_textarea_field( $metadata['diagnostic_code'] );
		}

		if ( isset( $metadata['provider'] ) ) {
			$clean['provider'] = sanitize_key( $metadata['provider'] );
		}

		return $clean;
	}

	/**
	 * Handle soft bounce
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact model instance.
	 * @param array         $metadata Bounce metadata.
	 *
	 * @return bool
	 */
	protected function handle_soft_bounce( $contact, $metadata ) {
		try {
			$soft_bounce_count = (int) doublescale_get_contact_meta( $contact->id, self::META_SOFT_BOUNCE_COUNT, true );
			$soft_bounce_limit = apply_filters( 'doublescale_soft_bounce_limit', 3 ); // Increased from 1 to 3

			if ( $soft_bounce_count < $soft_bounce_limit ) {
				$new_count = $soft_bounce_count + 1;

				// Update soft bounce metadata
				$update_results = array(
					'count'  => doublescale_update_contact_meta( $contact->id, self::META_SOFT_BOUNCE_COUNT, $new_count ),
					'reason' => doublescale_update_contact_meta( $contact->id, self::META_LAST_BOUNCE_REASON, $metadata['reason'] ?? 'Soft bounce' ),
					'date'   => doublescale_update_contact_meta( $contact->id, self::META_LAST_BOUNCE_DATE, current_time( 'mysql' ) ),
				);

				// Check if metadata updates were successful
				$failed_updates = array_filter(
					$update_results,
					function( $result ) {
						return $result === false;
					}
				);

				if ( ! empty( $failed_updates ) ) {
					$this->log(
						sprintf(
							'Failed to update some soft bounce metadata for contact ID %d (%s)',
							$contact->id,
							$contact->email
						),
						'warning',
						array(
							'contact_id'     => $contact->id,
							'email'          => $contact->email,
							'failed_updates' => array_keys( $failed_updates ),
							'update_results' => $update_results,
							'recovery'       => 'Retry updating contact metadata or check database connectivity',
						)
					);
				}

				do_action( 'doublescale_contact_soft_bounced', $contact, $metadata );

				$this->log(
					sprintf(
						'Soft bounce recorded for contact ID %d (%s). Count: %d/%d. Reason: %s',
						$contact->id,
						$contact->email,
						$new_count,
						$soft_bounce_limit,
						$metadata['reason'] ?? 'Unknown'
					),
					'info',
					array(
						'contact_id'    => $contact->id,
						'email'         => $contact->email,
						'bounce_count'  => $new_count,
						'bounce_limit'  => $soft_bounce_limit,
						'bounce_reason' => $metadata['reason'] ?? 'Unknown',
						'provider'      => $metadata['provider'] ?? 'Unknown',
						'next_action'   => $new_count >= $soft_bounce_limit ? 'convert_to_hard' : 'continue_monitoring',
					)
				);

				return true;
			}

			// Exceeded soft bounce limit, convert to hard bounce
			$this->log(
				sprintf(
					'Contact ID %d (%s) exceeded soft bounce limit (%d). Converting to hard bounce.',
					$contact->id,
					$contact->email,
					$soft_bounce_limit
				),
				'warning',
				array(
					'contact_id'      => $contact->id,
					'email'           => $contact->email,
					'bounce_limit'    => $soft_bounce_limit,
					'current_count'   => $soft_bounce_count,
					'original_reason' => $metadata['reason'] ?? 'Unknown',
					'provider'        => $metadata['provider'] ?? 'Unknown',
					'action'          => 'converting_to_hard_bounce',
					'recovery'        => 'Contact will be marked as hard bounced and excluded from future campaigns',
				)
			);

			$metadata['reason']      = 'Exceeded soft bounce limit (' . $soft_bounce_limit . ')';
			$metadata['bounce_type'] = 'hard';

			return $this->mark_hard_bounced( $contact, $metadata );

		} catch ( \Exception $e ) {
			$this->log(
				sprintf(
					'Exception while handling soft bounce for contact ID %d (%s): %s',
					$contact->id,
					$contact->email,
					$e->getMessage()
				),
				'error',
				array(
					'contact_id' => $contact->id,
					'email'      => $contact->email,
					'exception'  => $e->getMessage(),
					'trace'      => $e->getTraceAsString(),
					'metadata'   => $metadata,
					'recovery'   => 'Contact soft bounce handling failed, manual review may be required',
				)
			);

			return false;
		}
	}

	/**
	 * Mark contact as hard bounced
	 *
	 * @since 1.0.0
	 *
	 * @param ContactModel $contact Contact model instance.
	 * @param array         $metadata Bounce metadata.
	 *
	 * @return bool
	 */
	protected function mark_hard_bounced( $contact, $metadata ) {
		try {
			$old_status = $contact->email_status;

			// Skip if already bounced to avoid unnecessary processing
			if ( $old_status === 'bounced' ) {
				$this->log(
					sprintf(
						'Contact ID %d (%s) is already marked as bounced, updating metadata only',
						$contact->id,
						$contact->email
					),
					'info',
					array(
						'contact_id' => $contact->id,
						'email'      => $contact->email,
						'status'     => $old_status,
						'provider'   => $metadata['provider'] ?? 'Unknown',
					)
				);
			} else {
				// Update contact status
				$contact->email_status = 'bounced';
				$save_result           = $contact->save();

				if ( ! $save_result ) {
					$this->log(
						sprintf(
							'Failed to save contact status for ID %d (%s)',
							$contact->id,
							$contact->email
						),
						'error',
						array(
							'contact_id'       => $contact->id,
							'email'            => $contact->email,
							'old_status'       => $old_status,
							'attempted_status' => 'bounced',
							'recovery'         => 'Contact status update failed, manual intervention may be required',
						)
					);
					return false;
				}
			}

			// Store bounce metadata using constants
			$metadata_updates = array(
				self::META_BOUNCE_REASON => $metadata['reason'] ?? 'Email bounced',
				self::META_BOUNCE_TYPE   => $metadata['bounce_type'] ?? 'hard',
				self::META_BOUNCE_DATE   => current_time( 'mysql' ),
			);

			// Optional metadata
			if ( isset( $metadata['diagnostic_code'] ) ) {
				$metadata_updates[ self::META_BOUNCE_DIAGNOSTIC ] = $metadata['diagnostic_code'];
			}

			if ( isset( $metadata['provider'] ) ) {
				$metadata_updates[ self::META_BOUNCE_PROVIDER ] = $metadata['provider'];
			}

			// Update all metadata and track failures
			$failed_meta_updates = array();
			foreach ( $metadata_updates as $meta_key => $meta_value ) {
				$result = doublescale_update_contact_meta( $contact->id, $meta_key, $meta_value );
				if ( $result === false ) {
					$failed_meta_updates[ $meta_key ] = $meta_value;
				}
			}

			// Log metadata update failures
			if ( ! empty( $failed_meta_updates ) ) {
				$this->log(
					sprintf(
						'Failed to update some bounce metadata for contact ID %d (%s)',
						$contact->id,
						$contact->email
					),
					'warning',
					array(
						'contact_id'          => $contact->id,
						'email'               => $contact->email,
						'failed_meta_updates' => $failed_meta_updates,
						'recovery'            => 'Some bounce metadata was not saved, contact may need manual review',
					)
				);
			}

			do_action( 'doublescale_contact_bounced', $contact, $old_status, $metadata );

			$this->log(
				sprintf(
					'Contact ID %d (%s) marked as hard bounced. Previous status: %s. Reason: %s. Provider: %s',
					$contact->id,
					$contact->email,
					$old_status,
					$metadata['reason'] ?? 'Unknown',
					$metadata['provider'] ?? 'Unknown'
				),
				'notice',
				array(
					'contact_id'      => $contact->id,
					'email'           => $contact->email,
					'previous_status' => $old_status,
					'bounce_reason'   => $metadata['reason'] ?? 'Unknown',
					'bounce_provider' => $metadata['provider'] ?? 'Unknown',
					'diagnostic_code' => $metadata['diagnostic_code'] ?? '',
					'timestamp'       => $metadata['timestamp'] ?? time(),
					'meta_failures'   => ! empty( $failed_meta_updates ),
				)
			);

			return true;

		} catch ( \Exception $e ) {
			$this->log(
				sprintf(
					'Exception while marking contact ID %d (%s) as hard bounced: %s',
					$contact->id,
					$contact->email,
					$e->getMessage()
				),
				'error',
				array(
					'contact_id' => $contact->id,
					'email'      => $contact->email,
					'exception'  => $e->getMessage(),
					'trace'      => $e->getTraceAsString(),
					'metadata'   => $metadata,
					'recovery'   => 'Hard bounce processing failed, contact may need manual review and status update',
				)
			);

			return false;
		}
	}

	/**
	 * Log message
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level (info, notice, warning, error).
	 * @param array  $context Additional context data.
	 *
	 * @return void
	 */
	protected function log( $message, $level = 'info', $context = array() ) {
		// Add provider name to context.
		$context = array_merge(
			array(
				'source'   => 'bounce-handler',
				'provider' => $this->name,
			),
			$context
		);

		// Use Plugin's centralized logger.
		$logger = doublescale_get_logger();

		switch ( $level ) {
			case 'error':
				$logger->error( $message, $context );
				break;
			case 'warning':
				$logger->info( $message, $context );
				break;
			case 'notice':
				$logger->notice( $message, $context );
				break;
			case 'info':
			default:
				$logger->info( $message, $context );
				break;
		}
	}
}
