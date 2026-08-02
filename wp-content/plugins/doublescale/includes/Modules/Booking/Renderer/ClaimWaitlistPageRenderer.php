<?php
/**
 * Claim Waitlist Page Renderer
 *
 * Handles the atomic claim flow when a waitlisted customer
 * clicks the "claim your spot" link from the notification email.
 */

namespace DoubleScale\Modules\Booking\Renderer;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Services\BookingEvents;
use DoubleScale\Modules\Booking\Services\BookingService;
use DoubleScale\Modules\Booking\Models\BookedSlotModel;
use DoubleScale\Modules\Booking\Models\BookingHostsModel;
use DoubleScale\Modules\Booking\Models\BookingModel;
use Illuminate\Support\Arr;

class ClaimWaitlistPageRenderer extends BaseTemplateRenderer {

	private string $eventModelClass;

	public function __construct( string $eventModelClass ) {
		parent::__construct();
		$this->eventModelClass = $eventModelClass;
	}

	public function render( $booking ) {
		$calendar = $booking ? $booking->calendar : null;
		if ( ! $calendar || 'active' !== $calendar->status ) {
			return $this->render_unavailable();
		}

		$entity      = $booking->getBookableEntity();
		$wl_settings = $entity ? $entity->waiting_list_settings : array();

		if ( 'waiting' !== $booking->status ) {
			$denied_url = ! empty( $wl_settings['redirect_url_denied'] ) ? $wl_settings['redirect_url_denied'] : '';
			if ( $denied_url ) {
				\doublescale_safe_redirect( $denied_url );
			}
			return $this->render_claim_result( $booking, false, __( 'This booking is no longer on the waiting list.', 'doublescale' ) );
		}

		$result = $this->attempt_claim( $booking );

		if ( is_wp_error( $result ) ) {
			$denied_url = ! empty( $wl_settings['redirect_url_denied'] ) ? $wl_settings['redirect_url_denied'] : '';
			if ( $denied_url ) {
				\doublescale_safe_redirect( $denied_url );
			}
			return $this->render_claim_result( $booking, false, $result->get_error_message() );
		}

		$success_url = ! empty( $wl_settings['redirect_url_success'] ) ? $wl_settings['redirect_url_success'] : '';
		if ( $success_url ) {
			\doublescale_safe_redirect( $success_url );
		}

		return $this->render_claim_result( $booking, true, __( 'Your booking has been confirmed!', 'doublescale' ) );
	}

	/**
	 * Atomically attempt to claim the waiting-list slot.
	 *
	 * @param BookingModel $booking
	 * @return true|\WP_Error
	 */
	private function attempt_claim( $booking ) {
		global $wpdb;

		$entity = $booking->getBookableEntity();
		$start  = $booking->start_time;
		$end    = $booking->end_time;

		// Serialize claims on the same slot via a MySQL named lock so two
		// concurrent requests can't both pass the availability check before
		// either acquires the slot. The lock is released in finally to keep
		// the transaction commit/rollback path tight.
		$lock_token = 'ds_claim_' . substr(
			md5( $booking->calendar_id . '|' . $start . '|' . $end ),
			0,
			52
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$got_lock = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_token ) );
		if ( 1 !== $got_lock ) {
			return new \WP_Error( 'slot_busy', __( 'Sorry, this spot is being claimed by another customer.', 'doublescale' ) );
		}

		try {
			$wpdb->query( 'START TRANSACTION' );
			try {
				if ( $booking->event_id && $entity ) {
					$slot_available = $this->check_event_availability( $entity, $booking, $start, $end );
					if ( is_wp_error( $slot_available ) ) {
						$wpdb->query( 'ROLLBACK' );
						return $slot_available;
					}
				}

				BookedSlotModel::acquire(
					$booking->calendar_id,
					$booking->start_time,
					$booking->end_time,
					$booking->id,
					$booking->event_id
				);

				$booking->update( array( 'status' => 'scheduled' ) );
				BookingHostsModel::where( 'booking_id', $booking->id )
					->update( array( 'status' => 'scheduled' ) );
				$booking->refresh();

				$wpdb->query( 'COMMIT' );
			} catch ( \Exception $e ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'slot_taken', __( 'Sorry, this spot has already been claimed by another customer.', 'doublescale' ) );
			}
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_token ) );
		}

		$booking->logs()->create(
			array(
				'type'    => 'info',
				'message' => __( 'Claimed from waiting list', 'doublescale' ),
				'details' => __( 'Customer claimed their spot from the waiting list', 'doublescale' ),
			)
		);

		BookingEvents::emit( 'created', (int) $booking->id, array( 'actor' => 'attendee' ) );

		BookingModel::rebalanceWaitingListPositions( $booking );

		return true;
	}

	private function check_event_availability( $entity, $booking, $start, $end ) {
		switch ( $entity->type ) {
			case 'one-to-one':
				if ( BookedSlotModel::has_overlap( $booking->calendar_id, $start, $end ) ) {
					return new \WP_Error( 'slot_taken', __( 'Sorry, this spot has already been claimed by another customer.', 'doublescale' ) );
				}
				break;

			case 'round-robin':
			case 'collective':
				$host_ids = $booking->hosts->pluck( 'ID' )->toArray();
				foreach ( $host_ids as $host_id ) {
					if ( BookingService::host_has_overlap( (int) $host_id, $start, $end ) ) {
						return new \WP_Error( 'slot_taken', __( 'Sorry, this spot has already been claimed by another customer.', 'doublescale' ) );
					}
				}
				break;

			case 'group':
				$max      = Arr::get( $entity->group_settings, 'max_invites', 2 );
				$overlaps = BookedSlotModel::count_overlaps( $booking->calendar_id, $start, $end );
				if ( $overlaps >= $max ) {
					return new \WP_Error( 'slot_taken', __( 'Sorry, this spot has already been claimed by another customer.', 'doublescale' ) );
				}
				break;
		}

		return true;
	}

	private function render_claim_result( $booking, $success, $message ) {
		$time_format            = $this->get_time_format();
		$booking_array          = $this->dataFormatter->format_booking_data( $booking, $time_format );
		$booking_array['hosts'] = $this->format_hosts_data( $booking );

		$template_path = __DIR__ . '/templates/claim-waitlist.php';

		return $this->render_template_page(
			$template_path,
			array(
				'booking_array' => $booking_array,
				'title'         => $success
					? __( 'Booking Confirmed', 'doublescale' )
					: __( 'Spot No Longer Available', 'doublescale' ),
				'success'       => $success,
				'message'       => $message,
			)
		);
	}
}
