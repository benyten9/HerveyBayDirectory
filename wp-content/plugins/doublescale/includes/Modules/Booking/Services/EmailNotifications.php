<?php
/**
 * Email notifications listener.
 *
 * Subscribes to the booking lifecycle hooks emitted by {@see BookingEvents}
 * and dispatches the matching template through {@see Emails::send()}. The
 * sender identity (From / Reply-To) comes from {@see EmailIdentityResolver},
 * which applies the personal -> shared -> admin fallback chain. When the
 * host has no personal mailbox configured, booking emails fall through to
 * the shared/team mailbox automatically.
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\Services;

use Illuminate\Support\Arr;
use DoubleScale\Core\Communication\EmailIdentityResolver;
use DoubleScale\Core\Utils\Utils as CoreUtils;
use DoubleScale\Modules\Emails\Emails;
use DoubleScale\Modules\Booking\Models\BookingModel;
use DoubleScale\Modules\Booking\Models\CalendarModel;
use DoubleScale\Modules\Booking\Managers\MergeTagsManager;
use DoubleScale\Modules\Tracking\Models\CommunicationTrackingModel;
use DoubleScale\Core\Constants\MessageDirection;
use DoubleScale\Core\Constants\MessageSourceTypes;
use DoubleScale\Core\Constants\TrackingStatus;

defined( 'ABSPATH' ) || exit;

final class EmailNotifications {

	/**
	 * @var MergeTagsManager
	 */
	protected $merge_tags_manager;

	public function __construct() {
		$this->merge_tags_manager = MergeTagsManager::instance();

		$this->init_hooks();
	}

	/**
	 * Subscribe to the EventBus bare-hook tail. Each lifecycle event fires
	 * `doublescale_booking_{event}` after the bus's structured
	 * handlers run; we listen there and send the matching email
	 * synchronously inside the same request.
	 *
	 * Failures are caught and logged on the booking so a broken SMTP
	 * transport cannot abort the booking-create response.
	 */
	public function init_hooks(): void {
		add_action( 'doublescale_booking_created', array( $this, 'on_created' ), 10, 2 );
		add_action( 'doublescale_booking_cancelled', array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'doublescale_booking_rescheduled', array( $this, 'on_rescheduled' ), 10, 2 );
		add_action( 'doublescale_booking_confirmed', array( $this, 'on_confirmed' ), 10, 2 );
		add_action( 'doublescale_booking_pending', array( $this, 'on_pending' ), 10, 2 );
		add_action( 'doublescale_booking_rejected', array( $this, 'on_rejected' ), 10, 2 );
		add_action( 'doublescale_booking_waiting_list_joined', array( $this, 'on_waiting_list_joined' ), 10, 2 );
		add_action( 'doublescale_booking_waiting_list_available', array( $this, 'on_waiting_list_available' ), 10, 2 );

		add_action( 'init', array( $this, 'register_reminder_hooks' ) );
	}

	// ------------------------------------------------------------------
	// Hook listeners — synchronous email sends. Each is wrapped in a
	// try/catch so a mailer failure cannot bubble up and break the
	// booking-create request that triggered the event.
	// ------------------------------------------------------------------

	public function on_created( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_booking_created_email( $b );
			}
		);
	}

	public function on_cancelled( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) use ( $context ) {
				$actor = is_array( $context ) ? ( $context['actor'] ?? '' ) : '';
				// Email content differs by who initiated the cancel. For system-driven
				// cancellations (payment timeout / deletion) we fall through to the
				// attendee variant since there's no organizer action to acknowledge.
				if ( 'organizer' === $actor ) {
					$this->send_organizer_cancelled_email( $b );
				} else {
					$this->send_attendee_cancelled_email( $b );
				}
			}
		);
	}

	public function on_rescheduled( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) use ( $context ) {
				$actor = is_array( $context ) ? ( $context['actor'] ?? '' ) : '';
				if ( 'organizer' === $actor ) {
					$this->send_organizer_rescheduled_email( $b );
				} else {
					$this->send_attendee_rescheduled_email( $b );
				}
			}
		);
	}

	public function on_confirmed( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_booking_confirmed_email( $b );
			}
		);
	}

	public function on_pending( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_booking_pending_email( $b );
			}
		);
	}

	public function on_rejected( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_booking_rejected_email( $b );
			}
		);
	}

	public function on_waiting_list_joined( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_waiting_list_confirmation_email( $b );
			}
		);
	}

	public function on_waiting_list_available( $booking, $context = array() ): void {
		$this->safely(
			$booking,
			function ( BookingModel $b ) {
				$this->send_waiting_list_availability_email( $b );
			}
		);
	}

	/**
	 * Run a send callback against the booking, swallowing exceptions so a
	 * broken mailer cannot break the request that emitted the event. Errors
	 * are recorded on the booking log and surfaced via standard ops tooling.
	 */
	private function safely( $booking, callable $send ): void {
		if ( ! ( $booking instanceof BookingModel ) ) {
			return;
		}

		try {
			$send( $booking );
		} catch ( \Throwable $e ) {
			$this->create_booking_log(
				$booking,
				array(
					'type'    => 'error',
					'message' => __( 'Email notification failed', 'doublescale' ),
					'details' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Check whether a notification type is enabled in the per-event config.
	 */
	private function is_notification_enabled( $notifications, $key, $fallback = true ) {
		$enabled = Arr::get( $notifications, $key . '.enabled' );

		return null === $enabled ? (bool) $fallback : (bool) $enabled;
	}

	/**
	 * Wire the WP-Cron reminder callbacks. Runs on `init` so the cron events
	 * scheduled by {@see \DoubleScale\Modules\Booking\Services\BookingTasks}
	 * have a registered listener by the time they fire.
	 */
	public function register_reminder_hooks() {
		add_action( 'booking_organizer_reminder', array( $this, 'send_organizer_reminder_email' ) );
		add_action( 'booking_attendee_reminder', array( $this, 'send_attendee_reminder_email' ) );
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_booking_rejected_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$host_rejection = $this->is_notification_enabled( $email_notifications, 'host_rejection' );
		if ( $host_rejection ) {
			$host_template = Arr::get( $email_notifications, 'host_rejection.template' );
			$email         = $booking->contact->email;
			$result        = $this->send_email( $booking, $host_template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Reject email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send reject email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_booking_confirmed_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$host_confirmation = $this->is_notification_enabled( $email_notifications, 'host_approved_attendee' );
		if ( $host_confirmation ) {
			$host_template = Arr::get( $email_notifications, 'host_approved_attendee.template' );
			$email         = $booking->contact->email;
			$result        = $this->send_email( $booking, $host_template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Confirmation email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send confirmation email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_booking_pending_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$host_approval      = $this->is_notification_enabled( $email_notifications, 'host_approval' );
		$attendee_submitted = $this->is_notification_enabled( $email_notifications, 'attendee_submitted' );
		if ( $host_approval ) {
			$host_template = Arr::get( $email_notifications, 'host_approval.template' );
			foreach ( $booking->getOrganizerRecipientEmails() as $email ) {
				$result = $this->send_email( $booking, $host_template, $email );
				if ( $result ) {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'info',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Approval email sent to %s', 'doublescale' ), $email ),
						)
					);
				} else {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'error',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Failed to send approval email to %s', 'doublescale' ), $email ),
						)
					);
				}
			}
		}

		if ( $attendee_submitted ) {
			$attendee_template = Arr::get( $email_notifications, 'attendee_submitted.template' );
			$email             = $booking->contact->email;
			$result            = $this->send_email( $booking, $attendee_template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Submitted email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send submitted email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * Reminder cron callback. WP-Cron passes the booking id as the first arg.
	 *
	 * @param int $booking_id
	 */
	public function send_organizer_reminder_email( $booking_id ) {
		$booking = BookingModel::find( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		$email_notifications = $booking->getNotificationSettings();

		$organizer_reminder = $this->is_notification_enabled( $email_notifications, 'organizer_reminder' );
		if ( $organizer_reminder ) {
			$template = Arr::get( $email_notifications, 'organizer_reminder.template' );
			foreach ( $booking->getOrganizerRecipientEmails() as $email ) {
				$result = $this->send_email( $booking, $template, $email );
				if ( $result ) {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'info',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Reminder email sent to %s', 'doublescale' ), $email ),
						)
					);
				} else {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'error',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Failed to send reminder email to %s', 'doublescale' ), $email ),
						)
					);
				}
			}
		}
	}

	/**
	 * @param int $booking_id
	 */
	public function send_attendee_reminder_email( $booking_id ) {
		$booking = BookingModel::find( (int) $booking_id );
		if ( ! $booking ) {
			return;
		}
		$email_notifications = $booking->getNotificationSettings();

		$attendee_reminder = $this->is_notification_enabled( $email_notifications, 'attendee_reminder' );
		if ( $attendee_reminder ) {
			$template = Arr::get( $email_notifications, 'attendee_reminder.template' );
			$email    = $booking->contact->email;
			$result   = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Reminder email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send reminder email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_organizer_rescheduled_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$organizer_rescheduled = $this->is_notification_enabled( $email_notifications, 'organizer_rescheduled_attendee' );
		if ( $organizer_rescheduled ) {
			$template = Arr::get( $email_notifications, 'organizer_rescheduled_attendee.template' );
			$email    = $booking->contact->email;
			$result   = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Rescheduled email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send rescheduled email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_attendee_rescheduled_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$attendee_rescheduled = $this->is_notification_enabled( $email_notifications, 'attendee_rescheduled_organizer' );
		if ( $attendee_rescheduled ) {
			$template = Arr::get( $email_notifications, 'attendee_rescheduled_organizer.template' );
			foreach ( $booking->getOrganizerRecipientEmails() as $email ) {
				$result = $this->send_email( $booking, $template, $email );
				if ( $result ) {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'info',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Rescheduled email sent to %s', 'doublescale' ), $email ),
						)
					);
				} else {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'error',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Failed to send rescheduled email to %s', 'doublescale' ), $email ),
						)
					);
				}
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_organizer_cancelled_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$organizer_cancellation = $this->is_notification_enabled( $email_notifications, 'organizer_cancelled_attendee' );
		if ( $organizer_cancellation ) {
			$template = Arr::get( $email_notifications, 'organizer_cancelled_attendee.template' );
			$email    = $booking->contact->email;
			$result   = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Cancellation email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send cancellation email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_attendee_cancelled_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$attendee_cancellation = $this->is_notification_enabled( $email_notifications, 'attendee_cancelled_organizer' );
		if ( $attendee_cancellation ) {
			$attendee_template = Arr::get( $email_notifications, 'attendee_cancelled_organizer.template' );
			foreach ( $booking->getOrganizerRecipientEmails() as $email ) {
				$result = $this->send_email( $booking, $attendee_template, $email );
				if ( $result ) {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'info',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Cancellation email sent to %s', 'doublescale' ), $email ),
						)
					);
				} else {
					$this->create_booking_log(
						$booking,
						array(
							'type'    => 'error',
							/* translators: %s: email address */
							'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
							/* translators: %s: email address */
							'details' => sprintf( __( 'Failed to send cancellation email to %s', 'doublescale' ), $email ),
						)
					);
				}
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_waiting_list_confirmation_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$enabled = $this->is_notification_enabled( $email_notifications, 'waiting_list_confirmation' );
		if ( $enabled ) {
			$template = Arr::get( $email_notifications, 'waiting_list_confirmation.template' );
			$email    = $booking->contact->email;
			$result   = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						'details' => __( 'Waiting list confirmation email sent', 'doublescale' ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						'details' => __( 'Failed to send waiting list confirmation email', 'doublescale' ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_waiting_list_availability_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$enabled = $this->is_notification_enabled( $email_notifications, 'waiting_list_availability' );
		if ( $enabled ) {
			$template = Arr::get( $email_notifications, 'waiting_list_availability.template' );
			$email    = $booking->contact->email;
			$result   = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						'details' => __( 'Waiting list availability email sent', 'doublescale' ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						'details' => __( 'Failed to send waiting list availability email', 'doublescale' ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 */
	public function send_booking_created_email( $booking ) {
		$email_notifications = $booking->getNotificationSettings();

		$attendee_confirmation  = $this->is_notification_enabled( $email_notifications, 'attendee_confirmation' );
		$organizer_notification = $this->is_notification_enabled( $email_notifications, 'organizer_notification' );

		if ( $attendee_confirmation ) {
			$attendee_template = Arr::get( $email_notifications, 'attendee_confirmation.template' );
			$this->send_attendee_confirmation_email( $booking, $attendee_template );
		}

		if ( $organizer_notification ) {
			$organizer_template = Arr::get( $email_notifications, 'organizer_notification.template' );
			$this->send_organizer_notification_email( $booking, $organizer_template );
		}
	}

	/**
	 * @param BookingModel $booking
	 * @param array        $template
	 */
	private function send_attendee_confirmation_email( $booking, $template ) {
		$email  = $booking->contact->email;
		$result = $this->send_email( $booking, $template, $email );
		if ( $result ) {
			$this->create_booking_log(
				$booking,
				array(
					'type'    => 'info',
					/* translators: %s: email address */
					'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
					/* translators: %s: email address */
					'details' => sprintf( __( 'Confirmation email sent to %s', 'doublescale' ), $email ),
				)
			);
		} else {
			$this->create_booking_log(
				$booking,
				array(
					'type'    => 'error',
					/* translators: %s: email address */
					'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
					/* translators: %s: email address */
					'details' => sprintf( __( 'Failed to send confirmation email to %s', 'doublescale' ), $email ),
				)
			);
		}
	}

	/**
	 * @param BookingModel $booking
	 * @param array        $template
	 */
	private function send_organizer_notification_email( $booking, $template ) {
		foreach ( $booking->getOrganizerRecipientEmails() as $email ) {
			$result = $this->send_email( $booking, $template, $email );
			if ( $result ) {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'info',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Notification email sent to %s', 'doublescale' ), $email ),
					)
				);
			} else {
				$this->create_booking_log(
					$booking,
					array(
						'type'    => 'error',
						/* translators: %s: email address */
						'message' => sprintf( __( 'Email not sent to %s', 'doublescale' ), $email ),
						/* translators: %s: email address */
						'details' => sprintf( __( 'Failed to send notification email to %s', 'doublescale' ), $email ),
					)
				);
			}
		}
	}

	/**
	 * @param BookingModel $booking
	 * @param array        $template
	 * @param string       $email
	 *
	 * @return bool
	 */
	private function send_email( $booking, $template, $email ) {
		$subject = Arr::get( $template, 'subject' );
		$body    = Arr::get( $template, 'message' );

		// Refresh booking from database to get the latest data
		// (e.g., meeting links added by integrations like Zoom/Google Meet).
		$booking = BookingModel::find( $booking->id );

		$subject = $this->merge_tags_manager->process_merge_tags( $subject, $booking );
		$body    = $this->merge_tags_manager->process_merge_tags( $body, $booking );

		$general = (array) get_option( 'doublescale_booking_settings', array() );
		$general = isset( $general['general'] ) && is_array( $general['general'] ) ? $general['general'] : array();

		$attachments = array();
		if ( ! empty( $general['include_ics'] ) ) {
			$ics_path = $this->build_ics_attachment( $booking );
			if ( $ics_path ) {
				$attachments[] = $ics_path;
			}
		}

		$host_user_id = ( $booking->calendar instanceof CalendarModel )
			? (int) $booking->calendar->user_id
			: 0;
		$identity     = EmailIdentityResolver::resolve( $host_user_id ?: null );

		$emails               = new Emails();
		$emails->from_address = $identity['from_address'];
		$emails->from_name    = $identity['from_name'];
		$emails->reply_to     = $identity['reply_to'];

		$result = $emails->send( $email, $subject, $body, $attachments );

		foreach ( $attachments as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		if ( $result ) {
			doublescale_get_logger()->info(
				'Booking email sent',
				array(
					'source'     => 'booking-email-notifications',
					'recipient'  => $email,
					'subject'    => $subject,
					'booking_id' => (int) $booking->id,
				)
			);
			$this->record_tracking_row( $booking, $email, $host_user_id );
		} else {
			doublescale_get_logger()->warning(
				'Failed to send booking email',
				array(
					'source'     => 'booking-email-notifications',
					'recipient'  => $email,
					'subject'    => $subject,
					'booking_id' => (int) $booking->id,
				)
			);
		}

		return $result;
	}

	/**
	 * Record an outbound communication-tracking row so the contact's Emails tab
	 * surfaces booking confirmation / cancellation / etc. emails alongside campaign
	 * and individual sends.
	 *
	 * Only writes when the recipient is the booking's contact — host-side organizer
	 * notifications would link to the wrong contact otherwise. A failed write
	 * MUST NOT abort the email; we swallow the exception and log it.
	 */
	private function record_tracking_row( BookingModel $booking, string $recipient, int $host_user_id ): void {
		if ( ! $booking->contact_id ) {
			return;
		}
		if ( ! $booking->contact || strcasecmp( (string) $booking->contact->email, $recipient ) !== 0 ) {
			return;
		}

		try {
			CommunicationTrackingModel::create(
				array(
					'contact_id'  => (int) $booking->contact_id,
					'template_id' => null,
					'hash_key'    => CoreUtils::generate_hash_key(),
					'mode'        => CommunicationTrackingModel::MODE_EMAIL,
					'direction'   => MessageDirection::OUTBOUND,
					'source_type' => MessageSourceTypes::BOOKING,
					'source_id'   => (int) $booking->id,
					'author_id'   => $host_user_id ?: null,
					'recipient'   => $recipient,
					'status'      => TrackingStatus::SENT,
					'sent_at'     => current_time( 'mysql', true ),
				)
			);
		} catch ( \Throwable $e ) {
			doublescale_get_logger()->error(
				'Failed to record booking email tracking row',
				array(
					'source'     => 'booking-email-notifications',
					'booking_id' => (int) $booking->id,
					'exception'  => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Generate a temporary `.ics` attachment for a booking.
	 *
	 * Writes a minimal RFC 5545 VCALENDAR file to the WP uploads tmp dir and
	 * returns the absolute path. Caller is responsible for deleting the file
	 * after `Emails::send()` returns (PHPMailer reads it during send).
	 *
	 * @param BookingModel $booking
	 * @return string|null Path to the generated file, or null on failure.
	 */
	private function build_ics_attachment( $booking ): ?string {
		if ( empty( $booking->start_time ) || empty( $booking->end_time ) ) {
			return null;
		}

		try {
			$dtstart = ( new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
			$dtend   = ( new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) ) )->format( 'Ymd\THis\Z' );
			$dtstamp = gmdate( 'Ymd\THis\Z' );
		} catch ( \Exception $e ) {
			return null;
		}

		$summary   = $booking->event->name ?? __( 'Booking', 'doublescale' );
		$location  = is_string( $booking->location ) ? $booking->location : '';
		$uid       = sprintf( 'doublescale-booking-%d@%s', (int) $booking->id, wp_parse_url( home_url(), PHP_URL_HOST ) );
		$organizer = '';
		if ( $booking->calendar && $booking->calendar->user ) {
			$organizer = sprintf( 'ORGANIZER;CN=%s:mailto:%s', $booking->calendar->user->display_name, $booking->calendar->user->user_email );
		}

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//DoubleScale//Booking//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:REQUEST',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
			'SUMMARY:' . $this->ics_escape( $summary ),
		);
		if ( $location ) {
			$lines[] = 'LOCATION:' . $this->ics_escape( $location );
		}
		if ( $organizer ) {
			$lines[] = $organizer;
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		$ics = implode( "\r\n", $lines ) . "\r\n";

		$tmp_dir = trailingslashit( get_temp_dir() );
		$path    = $tmp_dir . sprintf( 'doublescale-booking-%d-%s.ics', (int) $booking->id, wp_generate_password( 8, false ) );

		if ( false === file_put_contents( $path, $ics ) ) {
			return null;
		}

		return $path;
	}

	/**
	 * Escape a string for inclusion in an RFC 5545 ICS field.
	 *
	 * @param string $text
	 * @return string
	 */
	private function ics_escape( string $text ): string {
		return str_replace(
			array( '\\', "\r\n", "\n", ',', ';' ),
			array( '\\\\', '\\n', '\\n', '\\,', '\\;' ),
			$text
		);
	}

	/**
	 * @param BookingModel $booking
	 * @param array        $data
	 */
	public function create_booking_log( $booking, $data ) {
		$booking->logs()->create( $data );
	}
}
