<?php
/**
 * Certified Directorist Booking 1.0.0 nonpaid service-slot lifecycle.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment -- Methods implement the documented native workflow adapter seam.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use Directorist_Booking\App\Providers\Database as NativeBooking;

/** Source-versioned native adapter with explicit preparation and native readback. */
final class BookingAdapter implements NativeWorkflowAdapter {
	public function id(): string {
		return 'booking.request'; }
	public function revision(): string {
		return 'directorist-booking-1.0.0/service-slot-v1'; }
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'date_start' => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'slot_id'    => array(
					'type'    => 'string',
					'pattern' => '^[0-6]\\|[0-9]{1,3}$',
				),
				'guests'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'name'       => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'email'      => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'phone'      => array(
					'type'      => 'string',
					'maxLength' => 80,
				),
				'message'    => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function available( Context $context, int $directory, int $listing ): array {
		try {
			NativeWorkflowScope::listing( $context, $directory, $listing, 'booking' );
			if ( ! function_exists( 'directorist_booking_version' ) || '1.0.0' !== directorist_booking_version() || ! class_exists( NativeBooking::class ) ) {
				return array(
					'available' => false,
					'reason'    => 'The installed booking version has no certified chat adapter.',
				);
			}
			return array(
				'available' => true,
				'reason'    => '',
			);
		} catch ( ContractException $error ) {
			return array(
				'available' => false,
				'reason'    => $error->getMessage(),
			);
		}
	}

	public function prepare( Context $context, int $directory, int $listing, object $values ): array {
		NativeWorkflowScope::inputs( $values, array_keys( $this->input_schema()['properties'] ) );
		$availability = $this->available( $context, $directory, $listing );
		if ( ! $availability['available'] ) {
			return NativeWorkflowScope::decision( 'unavailable', array(), $values, null, $availability['reason'] ); }
		$manifest = NativeWorkflowScope::listing( $context, $directory, $listing, 'booking' );
		$type     = get_post_meta( $listing, '_bdb_booking_type', true );
		$mode     = get_post_meta( $listing, '_bdb_slot_status', true );
		if ( 'service' !== $type || 'time_slot' !== $mode ) {
			return NativeWorkflowScope::decision( 'unavailable', array(), $values, null, 'This booking mode has no proven chat availability. Use the native booking form.' );
		}
		$maximum = max( 1, min( 100, (int) get_post_meta( $listing, '_bdb_reservation_guest', true ) ) );
		$fields  = array(
			array(
				'id'       => 'date_start',
				'label'    => 'Date',
				'type'     => 'date',
				'required' => true,
			),
			array(
				'id'       => 'slot_id',
				'label'    => 'Time slot',
				'type'     => 'select',
				'required' => true,
				'choices'  => array(),
			),
			array(
				'id'       => 'guests',
				'label'    => 'Guests',
				'type'     => 'integer',
				'required' => true,
				'min'      => 1,
				'max'      => $maximum,
			),
			array(
				'id'        => 'name',
				'label'     => 'Name',
				'type'      => 'text',
				'required'  => true,
				'maxLength' => 120,
			),
			array(
				'id'        => 'email',
				'label'     => 'Email',
				'type'      => 'email',
				'required'  => true,
				'maxLength' => 120,
			),
			array(
				'id'        => 'phone',
				'label'     => 'Phone',
				'type'      => 'text',
				'required'  => false,
				'maxLength' => 80,
			),
			array(
				'id'        => 'message',
				'label'     => 'Message',
				'type'      => 'textarea',
				'required'  => false,
				'maxLength' => 1000,
			),
		);
		$inputs  = clone $values;
		if ( ! isset( $inputs->guests ) ) {
			$inputs->guests = 1; }
		if ( empty( $inputs->date_start ) ) {
			return NativeWorkflowScope::decision( 'needs_input', $fields, $inputs, null, 'Choose a date and time slot for this booking.' ); }
		$date  = $this->date( $inputs->date_start );
		$day   = (int) $date->format( 'N' ) - 1;
		$slots = (array) get_post_meta( $listing, '_bdb', true );
		foreach ( (array) ( $slots[ $day ] ?? array() ) as $index => $slot ) {
			if ( ! is_array( $slot ) || ! $this->valid_slot( $slot ) || ! ctype_digit( (string) $index ) ) {
				continue; }
			$fields[1]['choices'][] = array(
				'value' => $day . '|' . $index,
				'label' => $slot['start'] . ' – ' . $slot['close'],
			);
		}
		if ( ! $fields[1]['choices'] ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $inputs, null, 'There are no supported native service slots on this date.' ); }
		if ( empty( $inputs->slot_id ) ) {
			return NativeWorkflowScope::decision( 'needs_input', $fields, $inputs, null, 'Choose one of the current native time slots.' ); }
		if ( ! is_string( $inputs->slot_id ) || ! in_array( $inputs->slot_id, array_column( $fields[1]['choices'], 'value' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Choose a current time slot for the selected date.' ); }
		if ( ! is_int( $inputs->guests ) || $inputs->guests < 1 || $inputs->guests > $maximum ) {
			throw new ContractException( 'invalid_input', 'The guest count exceeds the native booking limit.' ); }
		list( , $index ) = explode( '|', $inputs->slot_id );
		$slot            = $slots[ $day ][ $index ];
		$native_slot     = wp_json_encode( array( $slot['start'] . ' - ' . $slot['close'], $inputs->slot_id ) );
		$start           = $inputs->date_start . ' ' . $slot['start'] . ':00';
		$end             = $inputs->date_start . ' ' . $slot['close'] . ':00';
		if ( new \DateTimeImmutable( $start, wp_timezone() ) <= new \DateTimeImmutable( 'now', wp_timezone() ) ) {
			throw new ContractException( 'invalid_input', 'Choose a future booking slot.' ); }
		$free = max( 0, (int) NativeBooking::count_free_places( $listing, $inputs->date_start, $inputs->date_start, $native_slot ) );
		if ( 0 === $free ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $inputs, null, 'This native slot is full.' ); }
		$amount = (float) NativeBooking::calculate_price( $listing, $inputs->date_start, $inputs->date_start );
		if ( ! is_finite( $amount ) || $amount < 0 ) {
			throw new ContractException( 'unsupported', 'The native price is unavailable.' ); }
		$payment = (bool) get_post_meta( $listing, '_bdb_payment_booking', true );
		$quote   = (object) array(
			'amount'           => $amount,
			'currency'         => (string) get_directorist_option( 'g_currency', 'USD' ),
			'formatted'        => wp_strip_all_tags( atbdp_format_payment_amount( $amount ) ),
			'payment_required' => $payment,
		);
		if ( $payment ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $inputs, null, 'This booking requires native checkout. Continue on the listing. No reservation or payment has been made.' ); }
		if ( $context->actor()->is_guest() ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $inputs, null, 'Sign in to request this booking in chat. The native guest form remains available according to site settings.' ); }
		foreach ( array( 'name', 'email' ) as $required ) {
			if ( empty( $inputs->$required ) || ( is_string( $inputs->$required ) && '' === trim( $inputs->$required ) ) ) {
				return NativeWorkflowScope::decision( 'needs_input', $fields, $inputs, null, 'Add your name and email before reviewing the booking request.' ); }
		}
		foreach ( array(
			'name'    => 120,
			'email'   => 120,
			'phone'   => 80,
			'message' => 1000,
		) as $key => $limit ) {
			if ( isset( $inputs->$key ) && ( ! is_string( $inputs->$key ) || mb_strlen( $inputs->$key ) > $limit || wp_strip_all_tags( $inputs->$key ) !== trim( $inputs->$key ) ) ) {
				throw new ContractException( 'invalid_input', 'The booking contact fields are invalid.' ); }
		}
		if ( ! is_email( $inputs->email ) ) {
			throw new ContractException( 'invalid_input', 'Enter a valid booking email.' ); }
		$status = apply_filters( 'bdb_service_slots_default_status', 'waiting' );
		if ( get_post_meta( $listing, '_bdb_instant_booking', true ) ) {
			$status = 'confirmed'; }
		if ( ! in_array( $status, array( 'waiting', 'confirmed' ), true ) ) {
			throw new ContractException( 'unsupported', 'The native default booking status is unsupported.' ); }
		$proposal = (object) array(
			'inputs'       => $inputs,
			'quote'        => $quote,
			'title'        => $manifest['title'],
			'url'          => $manifest['url'],
			'date_start'   => $start,
			'date_end'     => $end,
			'native_slot'  => $native_slot,
			'slot_label'   => $slot['start'] . ' – ' . $slot['close'],
			'free_places'  => $free,
			'native_state' => $status,
			'owner_id'     => (int) get_post_field( 'post_author', $listing ),
		);
		return NativeWorkflowScope::decision( 'ready', $fields, $inputs, $proposal, 'Review this booking request. It is submitted only after your confirmation.' );
	}

	public function execute( Context $context, int $directory, int $listing, object $proposal, string $operation ): array {
		$marker = NativeWorkflowScope::marker( $operation );
		return NativeWorkflowScope::locked(
			'booking:' . $listing,
			function () use ( $context, $directory, $listing, $proposal, $marker ): array {
				$context->assert_execution_identity();
				$existing = $this->operation_record( $context, $listing, $marker );
				if ( $existing ) {
					return $this->receipt( $context, $directory, $listing, $existing ); }
				$current = $this->prepare( $context, $directory, $listing, $proposal->inputs ?? (object) array() );
				if ( 'ready' !== $current['status'] || wp_json_encode( $current['proposal'] ) !== wp_json_encode( $proposal ) ) {
					throw new ContractException( 'stale_quote', 'The native slot or price changed. Review a fresh booking request.' ); }
				$inputs  = $proposal->inputs;
				$comment = array(
					'first_name'                      => $inputs->name,
					'email'                           => $inputs->email,
					'phone'                           => $inputs->phone ?? '',
					'message'                         => $inputs->message ?? '',
					'adults'                          => $inputs->guests,
					'tickets'                         => 0,
					'service'                         => false,
					'directorist_ai_operation'        => $marker,
					'directorist_ai_payment_required' => false,
					'directorist_ai_payment_context'  => $this->payment_signature( $listing, $context->actor()->user_id(), $marker ),
				);
				$id      = (int) NativeBooking::insert_booking(
					array(
						'owner_id'   => $proposal->owner_id,
						'listing_id' => $listing,
						'date_start' => $proposal->date_start,
						'date_end'   => $proposal->date_end,
						'comment'    => wp_json_encode( $comment ),
						'type'       => 'reservation',
						'price'      => $proposal->quote->amount,
					)
				);
				$row     = $this->operation_record( $context, $listing, $marker );
				if ( ! $row || (int) $row['ID'] !== $id ) {
					throw new ContractException( 'native_unknown', 'The native booking result requires reconciliation before any retry.' ); }
				NativeBooking::set_booking_status( $id, $proposal->native_state );
				return $this->status( $context, $directory, $listing, $id );
			}
		);
	}

	public function reconcile( Context $context, int $directory, int $listing, array $identity ): array {
		$context->assert_execution_identity();
		if ( isset( $identity['native_id'] ) ) {
			$row = NativeBooking::get_booking_by_id( (int) $identity['native_id'] );
			return $this->receipt( $context, $directory, $listing, is_array( $row ) ? $row : array(), $identity ); }
		$row = isset( $identity['operation_key'] ) ? $this->operation_record( $context, $listing, NativeWorkflowScope::marker( $identity['operation_key'] ) ) : null;
		return $row ? $this->receipt( $context, $directory, $listing, $row, $identity ) : array(
			'type'         => 'booking',
			'status'       => 'unknown',
			'native_state' => 'unknown',
			'summary'      => 'No exact native operation receipt is available. Do not resubmit automatically.',
		);
	}

	public function status( Context $context, int $directory, int $listing, int $id ): array {
		$context->assert_execution_identity();
		$row = NativeBooking::get_booking_by_id( $id );
		return $this->receipt( $context, $directory, $listing, is_array( $row ) ? $row : array() );
	}

	private function receipt( Context $context, int $directory, int $listing, array $row, ?array $historical = null ): array {
		if ( $context->actor()->is_guest() || (int) ( $row['bookings_author'] ?? 0 ) !== $context->actor()->user_id() || (int) ( $row['listing_id'] ?? 0 ) !== $listing || 'reservation' !== ( $row['type'] ?? '' ) ) {
			throw new ContractException( 'unauthorized', 'This booking is unavailable for this account.' ); }
		$manifest = NativeWorkflowScope::listing( $context, $directory, $listing, 'view' );
		$state    = in_array( $row['status'] ?? null, array( 'waiting', 'confirmed', 'paid', 'cancelled' ), true ) ? $row['status'] : 'unknown';
		$payment  = $this->receipt_payment( $row, $historical );
		return array(
			'type'             => 'booking',
			'status'           => 'unknown' === $state ? 'unknown' : 'succeeded',
			'native_id'        => (int) $row['ID'],
			'native_state'     => $state,
			'payment_required' => $payment['required'],
			'payment_status'   => $payment['status'],
			'title'            => $manifest['title'],
			'url'              => $manifest['url'],
			'date_start'       => $row['date_start'],
			'date_end'         => $row['date_end'],
			'amount'           => null === $row['price'] ? null : (float) $row['price'],
			'summary'          => 'waiting' === $state ? 'Your native booking request is awaiting owner approval.' : ( 'confirmed' === $state ? 'The native booking is confirmed.' : ( 'cancelled' === $state ? 'The native booking is cancelled. No refund is claimed.' : ( 'paid' === $state ? 'The native booking record reports paid.' : 'The native booking status requires reconciliation.' ) ) ),
		);
	}

	/** Immutable nonpaid context is bound to this server-created native operation. */
	private function payment_signature( int $listing, int $customer, string $marker ): string {
		return hash_hmac( 'sha256', wp_json_encode( array( 'booking-payment-context-v1', get_current_blog_id(), $listing, $customer, $marker, false ) ), wp_salt( 'auth' ) );
	}

	/** Later listing settings cannot turn a historical nonpaid receipt into pending payment. */
	private function receipt_payment( array $row, ?array $historical ): array {
		$state = $row['status'] ?? 'unknown';
		if ( 'paid' === $state ) {
			return array(
				'required' => false,
				'status'   => 'paid',
			);
		}
		$prior = null !== $historical && (int) ( $historical['native_id'] ?? 0 ) === (int) ( $row['ID'] ?? -1 ) && 'succeeded' === ( $historical['status'] ?? null ) && is_bool( $historical['payment_required'] ?? null );
		if ( $prior && 'paid' === ( $historical['payment_status'] ?? null ) ) {
			return array(
				'required' => false,
				'status'   => 'paid',
			);
		}
		$comment        = json_decode( $row['comment'] ?? '', true );
		$marker         = $comment['directorist_ai_operation'] ?? null;
		$signature      = $comment['directorist_ai_payment_context'] ?? null;
		$signed_nonpaid = is_string( $marker ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $marker ) && false === ( $comment['directorist_ai_payment_required'] ?? null ) && is_string( $signature ) && hash_equals( $this->payment_signature( (int) ( $row['listing_id'] ?? 0 ), (int) ( $row['bookings_author'] ?? 0 ), $marker ), $signature );
		if ( empty( $row['order_id'] ) && ( $signed_nonpaid || ( $prior && false === $historical['payment_required'] && 'not_required' === ( $historical['payment_status'] ?? null ) ) ) ) {
			return array(
				'required' => false,
				'status'   => 'not_required',
			);
		}
		if ( 'cancelled' === $state ) {
			return array(
				'required' => false,
				'status'   => 'unknown',
			);
		}
		// Legacy records lack a payment snapshot. Preserve native handoff safety,
		// but do not fabricate a financial status from today's listing flag.
		return array(
			'required' => ! empty( $row['order_id'] ) || (bool) get_post_meta( (int) ( $row['listing_id'] ?? 0 ), '_bdb_payment_booking', true ),
			'status'   => 'unknown',
		);
	}

	private function operation_record( Context $context, int $listing, string $marker ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'directorist_booking WHERE bookings_author=%d AND listing_id=%d AND type=%s AND comment LIKE %s LIMIT 2', $context->actor()->user_id(), $listing, 'reservation', '%' . $wpdb->esc_like( '"directorist_ai_operation":"' . $marker . '"' ) . '%' ), ARRAY_A );
		if ( count( $rows ) > 1 ) {
			throw new ContractException( 'native_unknown', 'The native operation has ambiguous receipts.' ); }
		if ( ! $rows ) {
			return null; }
		$comment = json_decode( $rows[0]['comment'], true );
		return ( $comment['directorist_ai_operation'] ?? null ) === $marker ? $rows[0] : null;
	}

	private function date( $value ): \DateTimeImmutable {
		if ( ! is_string( $value ) ) {
			throw new ContractException( 'invalid_input', 'Choose a valid future date.' ); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value || $date < new \DateTimeImmutable( 'today', wp_timezone() ) ) {
			throw new ContractException( 'invalid_input', 'Choose a valid future date.' ); }
		return $date;
	}

	private function valid_slot( array $slot ): bool {
		return is_string( $slot['start'] ?? null ) && is_string( $slot['close'] ?? null ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $slot['start'] ) && preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $slot['close'] ) && $slot['close'] > $slot['start'] && (int) ( $slot['slots'] ?? 0 ) > 0;
	}
}
