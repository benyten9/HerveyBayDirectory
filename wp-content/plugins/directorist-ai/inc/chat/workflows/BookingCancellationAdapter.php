<?php
/**
 * Owner-bound nonpaid native booking cancellation; native checkout/refunds remain native.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat\Workflows;

// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.FunctionCommentThrowTag, Squiz.Commenting.VariableComment -- Methods implement the documented native workflow adapter seam.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use Directorist_Booking\App\Providers\Database as NativeBooking;

/** Source-versioned native adapter with explicit preparation and native readback. */
final class BookingCancellationAdapter implements NativeWorkflowAdapter {
	private BookingAdapter $booking;
	public function __construct() {
		$this->booking = new BookingAdapter(); }
	public function id(): string {
		return 'booking.cancel'; }
	public function revision(): string {
		return 'directorist-booking-1.0.0/nonpaid-cancel-v2'; }
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id' => array(
					'oneOf' => array(
						array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						array(
							'type'    => 'string',
							'pattern' => '^[1-9][0-9]{0,9}$',
						),
					),
				),
			),
			'additionalProperties' => false,
		); }
	public function available( Context $context, int $directory, int $listing ): array {
		if ( $context->actor()->is_guest() ) {
			return array(
				'available' => false,
				'reason'    => 'Sign in to inspect or cancel your native bookings.',
			); }
		return $this->booking->available( $context, $directory, $listing ); }
	public function prepare( Context $context, int $directory, int $listing, object $values ): array {
		NativeWorkflowScope::inputs( $values, array( 'booking_id' ) );
		$availability = $this->available( $context, $directory, $listing );
		if ( ! $availability['available'] ) {
			return NativeWorkflowScope::decision( 'unavailable', array(), $values, null, $availability['reason'] ); }
		$choices = array();
		foreach ( NativeBooking::get_newest_bookings(
			array(
				'bookings_author' => $context->actor()->user_id(),
				'listing_id'      => $listing,
				'type'            => 'reservation',
			),
			25
		) as $row ) {
			if ( (int) $row['bookings_author'] !== $context->actor()->user_id() || (int) $row['listing_id'] !== $listing || 'reservation' !== $row['type'] ) {
				continue; }
			$choices[] = array(
				'value' => (string) $row['ID'],
				'label' => '#' . (int) $row['ID'] . ' · ' . $row['date_start'] . ' · ' . $row['status'],
			);
		}
		$fields = array(
			array(
				'id'       => 'booking_id',
				'label'    => 'Your booking',
				'type'     => 'select',
				'required' => true,
				'choices'  => $choices,
			),
		);
		if ( ! isset( $values->booking_id ) ) {
			return NativeWorkflowScope::decision( $choices ? 'needs_input' : 'unavailable', $fields, $values, null, $choices ? 'Choose one of your current native bookings.' : 'No current native booking for this listing is available for your account.' );
		}
		if ( ! ( is_int( $values->booking_id ) && $values->booking_id > 0 ) && ! ( is_string( $values->booking_id ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $values->booking_id ) ) ) {
			throw new ContractException( 'invalid_input', 'Choose a valid native booking number.' ); }
		$values             = clone $values;
		$values->booking_id = (string) $values->booking_id;
		$receipt            = $this->booking->status( $context, $directory, $listing, (int) $values->booking_id );
		if ( $receipt['payment_required'] || 'paid' === $receipt['native_state'] || 'unknown' === $receipt['native_state'] ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $values, null, 'Cancel this booking through the native dashboard. No cancellation or refund has been made.' ); }
		if ( 'cancelled' !== $receipt['native_state'] && new \DateTimeImmutable( $receipt['date_start'], wp_timezone() ) <= new \DateTimeImmutable( 'now', wp_timezone() ) ) {
			return NativeWorkflowScope::decision( 'unavailable', $fields, $values, null, 'This booking has started. Use its native dashboard options.' ); }
		$proposal = (object) array(
			'inputs'       => clone $values,
			'native_state' => $receipt['native_state'],
			'date_start'   => $receipt['date_start'],
			'date_end'     => $receipt['date_end'],
			'title'        => $receipt['title'],
		);
		return NativeWorkflowScope::decision( 'ready', $fields, $values, $proposal, 'Confirm cancellation of this native booking. This does not issue a refund.' );
	}
	public function execute( Context $context, int $directory, int $listing, object $proposal, string $operation ): array {
		NativeWorkflowScope::marker( $operation );
		return NativeWorkflowScope::locked(
			'booking:' . $listing,
			function () use ( $context, $directory, $listing, $proposal ): array {
				$current = $this->prepare( $context, $directory, $listing, $proposal->inputs ?? (object) array() );
				if ( 'ready' !== $current['status'] ) {
					throw new ContractException( 'stale_quote', 'Native cancellation eligibility changed.' ); }
				$id = (int) $proposal->inputs->booking_id;
				if ( 'cancelled' === $current['proposal']->native_state ) {
					return $this->booking->status( $context, $directory, $listing, $id ); }
				if ( wp_json_encode( $current['proposal'] ) !== wp_json_encode( $proposal ) ) {
					throw new ContractException( 'stale_quote', 'The booking changed. Review cancellation again.' ); }
				NativeBooking::set_booking_status( $id, 'cancelled' );
				return $this->booking->status( $context, $directory, $listing, $id );
			}
		);
	}
	public function reconcile( Context $context, int $directory, int $listing, array $identity ): array {
		$id = $identity['native_id'] ?? ( $identity['proposal']->inputs->booking_id ?? null );
		if ( ! ( is_int( $id ) && $id > 0 ) && ! ( is_string( $id ) && preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) ) ) {
			throw new ContractException( 'invalid_input', 'The native cancellation identity is unavailable.' ); }
		return $this->booking->status( $context, $directory, $listing, (int) $id );
	}
}
