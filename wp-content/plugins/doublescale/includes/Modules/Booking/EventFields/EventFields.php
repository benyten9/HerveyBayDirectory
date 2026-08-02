<?php
/**
 * Class EventFields
 *
 * This class is responsible for handling the event fields
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\EventFields;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Traits\Singleton;

/**
 * Event Fields class
 */
class EventFields {


	use Singleton;

	/**
	 * Get system fields
	 *
	 * @return array
	 */
	public function get_system_fields() {
		$default_fields = array(
			'name'    => array(
				'label'          => __( 'Your Name', 'doublescale' ),
				'type'           => 'text',
				'required'       => true,
				'group'          => 'system',
				'event_location' => 'all',
				'placeholder'    => __( 'Enter your name', 'doublescale' ),
				'order'          => 1,
			),
			'email'   => array(
				'label'          => __( 'Your Email', 'doublescale' ),
				'type'           => 'email',
				'required'       => true,
				'group'          => 'system',
				'event_location' => 'all',
				'placeholder'    => __( 'Enter your email', 'doublescale' ),
				'order'          => 2,
			),
			'message' => array(
				'label'          => __( 'What is this meeting about?', 'doublescale' ),
				'type'           => 'textarea',
				'required'       => false,
				'group'          => 'system',
				'event_location' => 'all',
				'placeholder'    => __( 'Enter your message', 'doublescale' ),
				'order'          => 3,
				'enabled'        => true,
			),
		);

		return $default_fields;
	}

	/**
	 * Default template for a phone field added on demand by the SMS Notification
	 * tab's "Add phone question" button. Mirrors the shape of `system.name` /
	 * `system.email` so existing field-renderer code handles it without changes.
	 *
	 * @return array
	 */
	public function get_phone_field_template() {
		return array(
			'label'          => __( 'Your Phone', 'doublescale' ),
			'type'           => 'phone',
			'required'       => true,
			'group'          => 'system',
			'event_location' => 'all',
			'placeholder'    => __( 'Enter your phone number', 'doublescale' ),
			'order'          => 99,
			'enabled'        => true,
		);
	}

	/**
	 * Determine whether an event's `fields` meta already contains a phone-type
	 * field. Used to gate the "Add phone question" button on the SMS Notification
	 * tab (and the related warning notice that prompts the admin to click it).
	 *
	 * Walks both system fields and any custom fields, looking for `type === 'phone'`.
	 *
	 * @param array|null $fields The event's `fields` meta value.
	 * @return bool
	 */
	public function has_phone_field( $fields ) {
		if ( ! is_array( $fields ) ) {
			return false;
		}
		foreach ( $fields as $group_key => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $field ) {
				if ( is_array( $field ) && ( $field['type'] ?? '' ) === 'phone' ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Get other fields
	 *
	 * @return array
	 */
	public function get_other_fields() {
		$other_fields = array(
			'cancellation_reason' => array(
				'label'          => __( 'Reason for cancellation', 'doublescale' ),
				'type'           => 'textarea',
				'required'       => true,
				'group'          => 'other',
				'event_location' => 'all',
				'placeholder'    => __( 'Why are you cancelling?', 'doublescale' ),
				'order'          => 1,
				'enabled'        => true,
			),
			'rescheduling_reason' => array(
				'label'          => __( 'Reason for reschedule', 'doublescale' ),
				'type'           => 'textarea',
				'required'       => true,
				'group'          => 'other',
				'event_location' => 'all',
				'placeholder'    => __( 'Let others know why you need to reschedule', 'doublescale' ),
				'order'          => 2,
				'enabled'        => true,
			),
		);

		return $other_fields;
	}

	/**
	 * Get default additional settings values
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type
	 *
	 * @return array
	 */
	public function get_default_additional_settings( $event_type ) {
		$values = array(
			'allow_attendees_to_select_duration' => false,
			'default_duration'                   => 15,
			'selectable_durations'               => array(
				15,
			),
		);

		switch ( $event_type ) {
			case 'one-to-one':
			case 'round-robin':
			case 'collective':
				$values['invitee'] = array(
					'allow_additional_guests' => false,
				);
				break;
			case 'group':
				$values['invitees'] = array(
					'max_invitees'   => 4,
					'show_remaining' => false,
				);
				break;
		}

		return $values;
	}

	/**
	 * Get duration options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_duration_options() {
		// Range from 5 minutes to 480 minutes
		$minutes = range( 5, 480, 5 );
		$options = array();

		foreach ( $minutes as $minute ) {
			/* translators: %d: number of minutes */
			$options[ $minute ] = sprintf( _n( '%d minute', '%d minutes', $minute, 'doublescale' ), $minute );
		}

		return $options;
	}

	/**
	 * Get default limit settings values
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_limit_settings() {
		$values = array(
			'general'       => array(
				'buffer_before'       => 0,
				'buffer_after'        => 0,
				'minimum_notices'     => 4,
				'minimum_notice_unit' => 'hours',
				'time_slot'           => 0,
			),
			'frequency'     => array(
				'enable' => false,
				'limits' => array(
					array(
						'limit' => 5,
						'unit'  => 'days',
					),
				),
			),
			'duration'      => array(
				'enable' => false,
				'limits' => array(
					array(
						'limit' => 120,
						'unit'  => 'days',
					),
				),
			),
			'timezone_lock' => array(
				'enable'   => false,
				'timezone' => wp_timezone_string(),
			),
		);

		return $values;
	}

	/**
	 * Get email notification settings
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_email_notification_settings() {
		return array(
			'attendee_confirmation'          => array(
				'label'    => __( 'Attendee Confirmation', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Confirmation', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'attendee_confirmation' ),
				),
			),
			'organizer_notification'         => array(
				'label'    => __( 'Organizer Notification', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'New Booking', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'organizer_notification' ),
				),
			),
			'attendee_reminder'              => array(
				'label'    => __( 'Attendee Reminder', 'doublescale' ),
				'default'  => false,
				'template' => array(
					'subject' => __( 'Booking Reminder', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'attendee_reminder' ),
				),
				'times'    => array(
					array(
						'unit'  => 'hours',
						'value' => 24,
					),
				),
			),
			'organizer_reminder'             => array(
				'label'    => __( 'Organizer Reminder', 'doublescale' ),
				'default'  => false,
				'template' => array(
					'subject' => __( 'Booking Reminder', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'organizer_reminder' ),
				),
				'times'    => array(
					array(
						'unit'  => 'hours',
						'value' => 24,
					),
				),
			),
			'attendee_cancelled_organizer'   => array(
				'label'    => __( 'Booking Cancelled by Attendee to Organizer', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Cancelled', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'attendee_cancelled_organizer' ),
				),
			),
			'organizer_cancelled_attendee'   => array(
				'label'    => __( 'Booking Cancelled by Organizer to Attendee', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Cancelled', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'organizer_cancelled_attendee' ),
				),
			),
			'attendee_rescheduled_organizer' => array(
				'label'    => __( 'Booking Rescheduled by Attendee to Organizer', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Rescheduled', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'attendee_rescheduled_organizer' ),
				),
			),
			'organizer_rescheduled_attendee' => array(
				'label'    => __( 'Booking Rescheduled by Organizer to Attendee', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Rescheduled', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'organizer_rescheduled_attendee' ),
				),
			),
			'host_approval'                  => array(
				'label'    => __( 'Host Approval', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Request', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'host_approval' ),
				),
			),
			'host_rejection'                 => array(
				'label'    => __( 'Host Rejection', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Rejected', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'host_rejection' ),
				),
			),
			'host_approved_attendee'         => array(
				'label'    => __( 'Host Approved to Attendee', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Your Booking is Confirmed!', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'host_approved_attendee' ),
				),
			),
			'attendee_submitted'             => array(
				'label'    => __( 'Booking Submitted by Attendee', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'Booking Pending Confirmation', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'attendee_submitted' ),
				),
			),
			'waiting_list_confirmation'      => array(
				'label'    => __( 'Waiting List Confirmation', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'You\'ve Joined the Waiting List', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'waiting_list_confirmation' ),
				),
			),
			'waiting_list_availability'      => array(
				'label'    => __( 'Waiting List Spot Available', 'doublescale' ),
				'default'  => true,
				'template' => array(
					'subject' => __( 'A Spot is Now Available!', 'doublescale' ),
					'message' => $this->get_default_email_body_template( 'waiting_list_availability' ),
				),
			),
		);
	}

	/**
	 * Get default email body template
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Template
	 *
	 * @return string
	 */
	public function get_default_email_body_template( $template ) {
		$attendee_confirmation = '
		<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
			<h1 style="color: #0073aa; text-align: center;">Booking Confirmation</h1>
			<p style="margin-bottom: 20px;">Dear {{guest:name}},</p>
			<p>Your booking has been confirmed successfully. Below are the details of your booking:</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Booking Details</h2>
			<p><strong>Booking For:</strong> {{booking:event_name}}</p>
			<p><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</p>
			<p><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</p>
			<p><strong>Location:</strong> {{booking:event_location}}</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Host Details</h2>
			<p><strong>Name:</strong> {{host:name}}</p>
			<p><strong>Email:</strong> {{host:email}}</p>
			<div style="margin: 20px 0; display: flex;">
				<a href="{{booking:reschedule_url}}" style="display: inline-block; padding: 10px 20px; color: #fff; background-color: #0073aa; text-decoration: none; border-radius: 3px; margin-right:20px">Reschedule Booking</a>
				<a href="{{booking:cancel_url}}" style="display: inline-block; padding: 10px 20px; color: #fff; background-color: #e74c3c; text-decoration: none; border-radius: 3px;">Cancel Booking</a>
			</div>
			<p>If you have any questions, feel free to contact us.</p>
			<p>Best regards,</p>
		</div>';

		$host_confirmation = '
		<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
			<h1 style="color: #0073aa; text-align: center;">New Booking Notification</h1>
			<p style="margin-bottom: 20px;">Dear {{host:name}},</p>
			<p>A new booking has been successfully created. Below are the details of the booking:</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Booking Details</h2>
			<p><strong>Booking For:</strong> {{booking:event_name}}</p>
			<p><strong>Start Time:</strong> {{booking:start_time timezone="host"}}</p>
			<p><strong>End Time:</strong> {{booking:end_time timezone="host"}}</p>
			<p><strong>Location:</strong> {{booking:event_location}}</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Guest Details</h2>
			<p><strong>Name:</strong> {{guest:name}}</p>
			<p><strong>Email:</strong> {{guest:email}}</p>
			<p><strong>Note:</strong> {{guest:note}}</p>
			<p style="margin: 20px 0;"><a href="{{booking:details_url}}" style="display: inline-block; padding: 10px 20px; color: #fff; background-color: #0073aa; text-decoration: none; border-radius: 3px;">View Booking Details</a></p>
		</div>';

		$attendee_reschedule = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">The booking has been rescheduled!</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the updated details of the rescheduled booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Attendee Name:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Attendee Email:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:email}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">New Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">New End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">You can view the booking details and make further adjustments using the link below.</p>
				<a href="{{booking:details_url}}" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #0073aa; color: #fff; text-decoration: none; border-radius: 4px; font-size: 16px;">View Booking Details</a>
			</div>
		</div>';

		$organizer_rescheduled = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">Your booking has been rescheduled by the host!</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the updated details of your booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">New Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">New End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Location:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_location}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">If you have any questions or need further assistance, please reach out.</p>
				<div style="margin-top: 20px;">
					<a href="{{booking:details_url}}" style="display: inline-block; margin-right: 10px; padding: 10px 20px; background-color: #0073aa; color: #fff; text-decoration: none; border-radius: 4px; font-size: 16px;">View Booking Details</a>
					<a href="{{booking:cancel_url}}" style="display: inline-block; padding: 10px 20px; background-color: #d9534f; color: #fff; text-decoration: none; border-radius: 4px; font-size: 16px;">Cancel Booking</a>
				</div>
			</div>
		</div>';

		$organizer_cancel = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">We regret to inform you that your booking has been cancelled.</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the details of the cancelled booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">If you have any questions or need assistance, feel free to contact us.</p>
			</div>
		</div>';

		$attendee_cancel = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">The following booking has been cancelled by the attendee.</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the details of the cancelled booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Attendee Name:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Attendee Email:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:email}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">If you have any questions or need further details, please reach out to the attendee directly.</p>
			</div>
		</div>';

		$attendee_reminder = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">We\'re looking forward to seeing you soon!</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the details of your upcoming booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Your Name:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Location:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_location}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">If you need to reschedule or cancel, please use the links below:</p>
				<p style="font-size: 16px; color: #555; margin-top: 10px;">Cancel Booking: {{booking:cancel_url}}</p>
			</div>
		</div>';

		$organizer_reminder = '
		<div style="font-family: Arial, sans-serif; background-color: #f9f9f9; padding: 20px;">
			<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
				<h1 style="font-size: 24px; color: #333; margin-bottom: 20px;">A booking is coming up soon!</h1>
				<p style="font-size: 16px; color: #555; line-height: 1.5;">Here are the details of the scheduled booking:</p>
				<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Guest Name:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Guest Email:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{guest:email}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Booking For:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_name}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled Start Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:start_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Scheduled End Time:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:end_time}}</td>
					</tr>
					<tr>
						<td style="font-weight: bold; color: #333; padding: 8px; border-bottom: 1px solid #ddd;">Location:</td>
						<td style="color: #555; padding: 8px; border-bottom: 1px solid #ddd;">{{booking:event_location}}</td>
					</tr>
				</table>
				<p style="font-size: 16px; color: #555; margin-top: 20px;">For more details, you can view the booking:</p>
				<p style="font-size: 16px; color: #555; margin-top: 10px;">
					<a href="{{booking:details_url}}" style="color: #0073aa; text-decoration: none;">View Booking Details</a>
				</p>
			</div>
		</div>';

		$host_approve = '
		<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.5;">
			<p>A new booking requires your approval.</p>
			<p>Here are the details of the booking:</p>
			<ul>
				<li><strong>Booking Name:</strong> {{booking:name}}</li>
				<li><strong>Start Time:</strong> {{booking:start_time timezone="host"}}</li>
				<li><strong>End Time:</strong> {{booking:end_time timezone="host"}}</li>
				<li><strong>Guest Name:</strong> {{guest:name}}</li>
				<li><strong>Guest Email:</strong> {{guest:email}}</li>
			</ul>
			<div style="margin-top: 20px; display: flex;">
				<a href="{{booking:confirm_url}}" style="background-color: #28a745; color: #fff; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;">Confirm Booking</a>
				<a href="{{booking:reject_url}}" style="background-color: #dc3545; color: #fff; padding: 10px 15px; text-decoration: none; border-radius: 5px;">Reject Booking</a>
			</div>
		</div>';

		$host_approved_attendee = '
		<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.5;">
			<p>Good news! Your booking has been confirmed.</p>
			<p>Booking Details:</p>
			<ul>
				<li><strong>Booking For:</strong> {{booking:event_name}}</li>
				<li><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</li>
				<li><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</li>
				<li><strong>Host Name:</strong> {{host:name}}</li>
			</ul>
			<p>
				<a href="{{booking:cancel_url}}" style="background-color: #dc3545; color: #fff; padding: 10px 15px; text-decoration: none; border-radius: 5px;">Cancel Booking</a>
			</p>
		</div>';

		$attendee_submitted = '
		<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.5;">
			<p>Your booking has been submitted and is awaiting confirmation from the host.</p>
			<p>Booking Details:</p>
			<ul>
				<li><strong>Booking For:</strong> {{booking:event_name}}</li>
				<li><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</li>
				<li><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</li>
				<li><strong>Host Name:</strong> {{host:name}}</li>
			</ul>
			<p>You will receive a confirmation email once the host confirms the booking.</p>
		</div>';

		$host_reject_attendee = '
		<div style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.5;">
			<p>We regret to inform you that your booking request has been rejected.</p>
			<p>Here are the details of your booking:</p>
			<ul>
				<li><strong>Booking Name:</strong> {{booking:name}}</li>
				<li><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</li>
				<li><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</li>
			</ul>
			<p>If you have any questions, please contact the organizer.</p>
		</div>';

		$waiting_list_confirmation = '
		<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
			<h1 style="color: #d97706; text-align: center;">You\'re on the Waiting List</h1>
			<p style="margin-bottom: 20px;">Dear {{guest:name}},</p>
			<p>You have been added to the waiting list. We will notify you if a spot becomes available.</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Booking Details</h2>
			<p><strong>Booking For:</strong> {{booking:event_name}}</p>
			<p><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</p>
			<p><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</p>
			<p><strong>Your Position:</strong> #{{booking:waiting_list_position}}</p>
			<p style="margin-top: 20px; font-size: 14px; color: #666;">If you have any questions, please contact the organizer.</p>
		</div>';

		$waiting_list_availability = '
		<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;">
			<h1 style="color: #0EA473; text-align: center;">A Spot is Now Available!</h1>
			<p style="margin-bottom: 20px;">Dear {{guest:name}},</p>
			<p>Great news! A spot has opened up for the booking you were waiting for. Click the link below to claim your spot now.</p>
			<h2 style="color: #555; border-bottom: 1px solid #ddd; padding-bottom: 5px;">Booking Details</h2>
			<p><strong>Booking For:</strong> {{booking:event_name}}</p>
			<p><strong>Start Time:</strong> {{booking:start_time timezone="attendee"}}</p>
			<p><strong>End Time:</strong> {{booking:end_time timezone="attendee"}}</p>
			<div style="text-align: center; margin: 30px 0;">
				<a href="{{booking:waiting_list_claim_url}}" style="display: inline-block; padding: 12px 30px; background-color: #0EA473; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold;">Claim Your Spot</a>
			</div>
			<p style="font-size: 14px; color: #666;">This is a first-come, first-served offer. If someone else claims the spot before you, it will no longer be available.</p>
		</div>';

		$templates = array(
			'attendee_confirmation'          => $attendee_confirmation,
			'organizer_notification'         => $host_confirmation,
			'attendee_reminder'              => $attendee_reminder,
			'organizer_reminder'             => $organizer_reminder,
			'attendee_cancelled_organizer'   => $attendee_cancel,
			'organizer_cancelled_attendee'   => $organizer_cancel,
			'attendee_rescheduled_organizer' => $attendee_reschedule,
			'organizer_rescheduled_attendee' => $organizer_rescheduled,
			'host_approval'                  => $host_approve,
			'host_approved_attendee'         => $host_approved_attendee,
			'attendee_submitted'             => $attendee_submitted,
			'host_rejection'                 => $host_reject_attendee,
			'waiting_list_confirmation'      => $waiting_list_confirmation,
			'waiting_list_availability'      => $waiting_list_availability,
		);

		return Arr::get( $templates, $template, '' );
	}

	/**
	 * SMS notification settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_sms_notification_settings() {
		return array(
			'attendee_confirmation'  => array(
				'label'    => __( 'Attendee Confirmation', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'attendee_confirmation' ),
				),
				'default'  => true,
			),
			'organizer_confirmation' => array(
				'label'    => __( 'Organizer Confirmation', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'organizer_confirmation' ),
				),
				'default'  => true,
			),
			'organizer_cancellation' => array(
				'label'    => __( 'Organizer Cancellation', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'organizer_cancellation' ),
				),
				'default'  => true,
			),
			'attendee_cancellation'  => array(
				'label'    => __( 'Attendee Cancellation', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'attendee_cancellation' ),
				),
				'default'  => true,
			),
			'organizer_reschedule'   => array(
				'label'    => __( 'Organizer Reschedule', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'organizer_reschedule' ),
				),
				'default'  => true,
			),
			'attendee_reschedule'    => array(
				'label'    => __( 'Attendee Reschedule', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'attendee_reschedule' ),
				),
				'default'  => true,
			),
			'organizer_reminder'     => array(
				'label'    => __( 'Organizer Reminder', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'organizer_reminder' ),
				),
				'default'  => true,
			),
			'attendee_reminder'      => array(
				'label'    => __( 'Attendee Reminder', 'doublescale' ),
				'template' => array(
					'type'    => 'sms',
					'message' => $this->get_sms_notification_template( 'attendee_reminder' ),
				),
				'default'  => true,
			),
		);
	}

	/**
	 * Get sms notification template.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template
	 *
	 * @return string
	 */
	public function get_sms_notification_template( $template ) {
		$attendee_confirmation = 'Dear {{guest:name}}, Your booking for "{{booking:event_name}}" scheduled from {{booking:start_time timezone="attendee"}} to {{booking:end_time timezone="attendee"}} ({{booking:timezone}}) has been successfully created.';

		$host_confirmation = 'Dear {{host:name}}, A new booking has been successfully created for "{{booking:event_name}}" scheduled from {{booking:start_time timezone="host"}} to {{booking:end_time timezone="host"}} ({{host:timezone}}). If you need further details, please check the booking in your dashboard. Thank you.';

		$organizer_sms_cancellation = 'Dear {{host:name}}, The attendee {{guest:name}} has canceled their booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="host"}}. If you need further details, please check the booking in your dashboard. Thank you.';

		$attendee_sms_cancellation = 'Dear {{guest:name}}, Your booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="attendee"}} has been canceled. If you have any questions, please contact the organizer.';

		$organizer_sms_reschedule = 'Dear {{host:name}}, The attendee {{guest:name}} has rescheduled their booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="host"}}. If you need further details, please check the booking in your dashboard. Thank you.';

		$attendee_sms_reschedule = 'Dear {{guest:name}}, Your booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="attendee"}} has been rescheduled. If you have any questions, please contact the organizer.';

		$organizer_sms_reminder = 'Dear {{host:name}}, Just a reminder that you have a booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="host"}}.';

		$attendee_sms_reminder = 'Dear {{guest:name}}, Just a reminder that you have a booking for "{{booking:event_name}}" scheduled for {{booking:start_time timezone="attendee"}}.';

		$templates = array(
			'attendee_confirmation'  => $attendee_confirmation,
			'organizer_confirmation' => $host_confirmation,
			'organizer_cancellation' => $organizer_sms_cancellation,
			'attendee_cancellation'  => $attendee_sms_cancellation,
			'organizer_reschedule'   => $organizer_sms_reschedule,
			'attendee_reschedule'    => $attendee_sms_reschedule,
			'organizer_reminder'     => $organizer_sms_reminder,
			'attendee_reminder'      => $attendee_sms_reminder,
		);

		return Arr::get( $templates, $template, '' );
	}

	/**
	 * Get default booking advanced settings values
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_advanced_settings() {
		return array(
			'event_title'                  => '',
			'submit_button_text'           => __( 'Submit Booking', 'doublescale' ),
			'redirect_after_submit'        => false,
			'redirect_url'                 => '',
			'require_confirmation'         => false,
			'confirmation_time'            => 'always',
			'confirmation_time_value'      => 24,
			'confirmation_time_unit'       => 'hours',
			'allow_multiple_bookings'      => false,
			'maximum_bookings'             => 1,
			'attendee_cannot_cancel'       => false,
			'cannot_cancel_time'           => 'event_start',
			'cannot_cancel_time_value'     => 24,
			'cannot_cancel_time_unit'      => 'hours',
			'permission_denied_message'    => __( 'You do not have permission to view this page.', 'doublescale' ),
			'attendee_cannot_reschedule'   => false,
			'cannot_reschedule_time'       => 'event_start',
			'cannot_reschedule_time_value' => 24,
			'cannot_reschedule_time_unit'  => 'hours',
			'reschedule_denied_message'    => __( 'You do not have permission to view this page.', 'doublescale' ),
			'event_slug'                   => '',
		);
	}



	/**
	 * Get default payment settings values
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_default_payments_settings() {
		return array(
			'enable_payment'                 => false,
			'type'                           => 'native',
			'enable_items_based_on_duration' => false,
			'enable_stripe'                  => false,
			'items'                          => array(
				array(
					'item'  => 'Booking',
					'price' => 100,
				),
			),
			'multi_duration_items'           => array(),
			'currency'                       => 'USD',
		);
	}

	/**
	 * Get time slot options
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_time_slot_options() {
		$minutes = range( 5, 120, 5 );
		$options = array();

		foreach ( $minutes as $minute ) {
			/* translators: %d: number of minutes */
			$options[ $minute ] = sprintf( _n( '%d minute', '%d minutes', $minute, 'doublescale' ), $minute );
		}

		return $options;
	}
}
