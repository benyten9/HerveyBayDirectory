<?php
/**
 * Class Booking_StartDate
 *
 * Booking Start Date Merge Tag
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Modules\Booking\MergeTags\Booking;

defined( 'ABSPATH' ) || exit;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Abstracts\MergeTag;
use DoubleScale\Modules\Booking\Models\BookingModel;

/**
 * Booking Start Date Merge Tag
 */
class BookingStartDate extends MergeTag {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Booking Start Date';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'start_time';

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
		if ( empty( $booking->start_time ) ) {
			return '';
		}

		try {
			// Database stores times in UTC, so we need to create DateTime with UTC timezone
			$start_time = new \DateTime( $booking->start_time, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return '';
		}

		$timezone = Arr::get( $options, 'timezone', 'attendee' );
		$format   = Arr::get( $options, 'format', $this->get_default_format() );

		switch ( $timezone ) {
			case 'attendee':
				if ( ! empty( $booking->timezone ) ) {
					$start_time->setTimezone( new \DateTimeZone( $booking->timezone ) );
				}
				break;
			case 'host':
				$start_time->setTimezone( new \DateTimeZone( $booking->getHostTimezone() ) );
				break;
			case 'utc':
				$start_time->setTimezone( new \DateTimeZone( 'UTC' ) );
				break;
		}

		return $start_time->format( $format );
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
