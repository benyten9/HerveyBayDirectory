<?php
/**
 * Sms Individual Message Sender
 * Handles sending individual Sms messages to contacts
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\IndividualMessaging;

use WP_Error;
use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractIndividualMessageSender;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Tracking\Sms;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Core\Validators\PhoneValidator;

/**
 * SmsIndividualSender class
 *
 * Concrete implementation for Sms individual message sending.
 * Extends abstract base class with Sms-specific validation and configuration.
 *
 * @since 1.0.0
 */
class SmsIndividualSender extends AbstractIndividualMessageSender {

	/**
	 * Get channel type
	 *
	 * @since 1.0.0
	 *
	 * @return string Channel type
	 */
	protected function get_channel_type() {
		return CampaignChannel::STR_SMS;
	}

	/**
	 * Get activity type
	 *
	 * @since 1.0.0
	 *
	 * @return string Activity type
	 */
	protected function get_activity_type() {
		return 'sms_sent';
	}

	/**
	 * Get tracking mode
	 *
	 * @since 1.0.0
	 *
	 * @return int Tracking mode constant
	 */
	protected function get_tracking_mode() {
		return CommunicationTrackingModel::MODE_SMS;
	}

	/**
	 * Get tracking class
	 *
	 * @since 1.0.0
	 *
	 * @return string Tracking class name
	 */
	protected function get_tracking_class() {
		return Sms::class;
	}

	/**
	 * Validate recipient phone number
	 *
	 * @since 1.0.0
	 *
	 * @param string $recipient Phone number to validate
	 * @return true|WP_Error True if valid, WP_Error if invalid
	 */
	protected function validate_recipient( $recipient ) {
		// Validate using centralized utility
		$validation = PhoneValidator::validate( $recipient, 'individual_sms' );

		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'invalid_phone',
				$validation['error'],
				array( 'status' => 400 )
			);
		}

		return true;
	}
}

