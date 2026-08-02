<?php
/**
 * Booking End Date Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Booking;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;
use Illuminate\Support\Arr;

/**
 * Booking End Date Merge Tag
 */
class BookingEndDate extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking End Date';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'end_time';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'booking';

	/**
	 * Get options
	 *
	 * @return array
	 */
	public function get_options() {
		return array(
			'format'   => array(
				'type'    => 'text',
				'label'   => __( 'Format', 'doublescale' ),
				'default' => 'F j, Y',
			),
			'timezone' => array(
				'type'    => 'select',
				'label'   => __( 'Timezone', 'doublescale' ),
				'options' => array(
					'attendee' => __( 'Attendee Timezone', 'doublescale' ),
					'host'     => __( 'Host Timezone', 'doublescale' ),
					'utc'      => __( 'UTC', 'doublescale' ),
				),
				'default' => 'attendee',
			),
		);
	}

	/**
	 * Get Value
	 *
	 * @param BookingModel $booking Booking model.
	 * @param array        $options Options.
	 *
	 * @return string
	 */
	public function get_value( $booking, $options = array() ) {

		if ( empty( $booking->end_time ) ) {
			return '';
		}

		$timezone = Arr::get( $options, 'timezone', 'attendee' );
		$format   = Arr::get( $options, 'format', $this->get_default_format() );

		try {
			// Database stores times in UTC, so we need to create DateTime with UTC timezone
			$end_time = new \DateTime( $booking->end_time, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}

		if ( empty( $booking->timezone ) ) {
			$booking->timezone = 'UTC';
		}

		switch ( $timezone ) {
			case 'attendee':
				$end_time->setTimezone( new \DateTimeZone( $booking->timezone ) );
				break;
			case 'host':
				$end_time->setTimezone( new \DateTimeZone( $booking->getHostTimezone() ) );
				break;
			case 'utc':
				$end_time->setTimezone( new \DateTimeZone( 'UTC' ) );
				break;
		}

		return $end_time->format( $format );
	}

	/**
	 * Get default format based on global time format setting
	 *
	 * @return string
	 */
	private function get_default_format() {
		$global_settings = get_option( 'doublescale_booking_settings', array() );
		$time_format     = $global_settings['general']['time_format'] ?? '12';

		// Return appropriate PHP date format based on time format setting
		return $time_format === '24' ? 'F j, Y, H:i' : 'F j, Y, g:i A';
	}
}
