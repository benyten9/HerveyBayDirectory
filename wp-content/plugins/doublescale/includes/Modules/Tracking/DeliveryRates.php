<?php
/**
 * Delivery and read rates from per-status message counts.
 *
 * A message holds one status that only moves forward (sent → delivered →
 * read; DeliveryStatusProgression rejects regressions), so the counts are
 * exclusive: a read message is no longer counted as delivered, a delivered one
 * no longer as sent. Dividing those counts by each other (delivered / sent,
 * read / delivered) showed 0% for a campaign whose one message was delivered
 * and 300% once most messages had moved past "sent".
 *
 * Every rate here is a share of the messages attempted — sent, delivered, read
 * or failed; pending and scheduled have not been tried yet — so the rates add
 * up the same way the per-status chart does.
 *
 * @package DoubleScale\Modules\Tracking
 */

namespace DoubleScale\Modules\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * DeliveryRates class
 */
final class DeliveryRates {

	/**
	 * Rates for a set of exclusive per-status counts.
	 *
	 * @param int $sent      Accepted by the provider, not yet confirmed delivered.
	 * @param int $delivered Delivered, not read.
	 * @param int $read      Read (was delivered first).
	 * @param int $failed    Failed.
	 * @return array{attempted:int,accepted:int,delivery_rate:float|int,read_rate:float|int}
	 */
	public static function from_counts( $sent, $delivered, $read, $failed ) {
		$sent      = max( 0, (int) $sent );
		$delivered = max( 0, (int) $delivered );
		$read      = max( 0, (int) $read );
		$failed    = max( 0, (int) $failed );

		$accepted  = $sent + $delivered + $read;
		$attempted = $accepted + $failed;

		return array(
			'attempted'     => $attempted,
			'accepted'      => $accepted,
			'delivery_rate' => self::percent( $delivered + $read, $attempted ),
			'read_rate'     => self::percent( $read, $attempted ),
		);
	}

	/**
	 * @param int $part  Part.
	 * @param int $whole Whole.
	 * @return float|int
	 */
	private static function percent( $part, $whole ) {
		return $whole > 0 ? round( ( $part / $whole ) * 100, 2 ) : 0;
	}
}
