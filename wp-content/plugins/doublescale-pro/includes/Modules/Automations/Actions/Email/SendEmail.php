<?php

/**
 * Send Email Action
 * Auto-generates templates and creates tracking records
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Email;

use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractSendMessage;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Modules\Campaigns\Campaign\EmailProcessing;

/**
 * Send Email Action
 */
class SendEmail extends AbstractSendMessage {





	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Send Email';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'send_email';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'email';

	/**
	 * Trigger Group
	 *
	 * @var string
	 */
	public $group = 'email';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will send an email to the user with full tracking and analytics.';

	/**
	 * Action Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Get channel type
	 *
	 * @return string
	 */
	protected function get_channel_type() {
		 return 'email';
	}

	/**
	 * Get tracking mode
	 *
	 * @return int
	 */
	protected function get_tracking_mode() {
		return CommunicationTrackingModel::MODE_EMAIL;
	}

	/**
	 * Get recipient from contact
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return string|null
	 */
	protected function get_recipient( ContactModel $contact ) {
		return $contact->email;
	}

	/**
	 * Validate recipient
	 *
	 * @param ContactModel $contact Contact Model.
	 * @return array|null
	 */
	protected function validate_recipient( ContactModel $contact ) {
		if ( empty( $contact->email ) || ! filter_var( $contact->email, FILTER_VALIDATE_EMAIL ) ) {
			return array(
				'status'  => 'skipped',
				'message' => 'Contact has no valid email address',
			);
		}
		return null;
	}

	/**
	 * Get processing instance
	 *
	 * @return EmailProcessing
	 */
	protected function get_processing_instance() {
		return EmailProcessing::instance();
	}

	/**
	 * Get channel name for logging
	 *
	 * @return string
	 */
	protected function get_channel_name() {
		 return 'Email';
	}

	/**
	 * Get fields for UI
	 * User composes email directly (no template selection)
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'subject'    => array(
				'label'       => __( 'Subject', 'doublescale'),
				'type'        => 'text',
				'required'    => true,
				'placeholder' => __( 'Enter email subject...', 'doublescale'),
			),
			'body'       => array(
				'label'    => __( 'Body', 'doublescale'),
				'type'     => 'open_builder',
				'required' => true,
			),
			'from_name'  => array(
				'label'       => __( 'From Name', 'doublescale'),
				'type'        => 'text',
				'placeholder' => get_bloginfo( 'name' ),
			),
			'from_email' => array(
				'label'       => __( 'From Email', 'doublescale'),
				'type'        => 'from_email',
				'placeholder' => get_option( 'admin_email' ),
			),
			'reply_to'   => array(
				'label'       => __( 'Reply To', 'doublescale'),
				'type'        => 'email',
				'placeholder' => __( 'Optional reply-to address', 'doublescale'),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @return array
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'subject'    => array(
					'type'     => 'string',
					'required' => true,
				),
				'body'       => array(
					'type'     => 'string',
					'required' => true,
				),
				'from_name'  => array(
					'type' => 'string',
				),
				'from_email' => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'reply_to'   => array(
					'type'   => 'string',
					'format' => 'email',
				),
			),
		);
	}

	/**
	 * Prepare channel-specific message data for sending
	 *
	 * For Email, this returns the subject, body, and optional from/reply fields.
	 * Template auto-generation is handled by AutomationStepModel events.
	 *
	 * @param AutomationStepModel        $step     Automation Step Model.
	 * @param ContactModel                $contact  Contact Model.
	 * @param CommunicationTrackingModel $tracking Communication Tracking Model.
	 * @return array Prepared message data with subject, body, from_name, from_email, reply_to.
	 * @throws \Exception If required fields are empty.
	 */
	protected function prepare_message_data( AutomationStepModel $step, ContactModel $contact, CommunicationTrackingModel $tracking ) {
		$subject    = $step->get_setting( 'subject' );
		$body       = $step->get_setting( 'body' );
		$from_name  = $step->get_setting( 'from_name' );
		$from_email = $step->get_setting( 'from_email' );
		$reply_to   = $step->get_setting( 'reply_to' );

		if ( empty( $subject ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Email subject is empty.', 'doublescale') );
		}

		if ( empty( $body ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Email body is empty.', 'doublescale') );
		}

		return array(
			'subject'    => $subject,
			'body'       => $body,
			'from_name'  => $from_name,
			'from_email' => $from_email,
			'reply_to'   => $reply_to,
		);
	}
}

SendEmail::instance();
