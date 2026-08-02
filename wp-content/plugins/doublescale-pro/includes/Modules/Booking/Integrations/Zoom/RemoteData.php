<?php
/**
 * Class Zoom Remote Data
 *
 * This class is responsible for handling the Zoom Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Zoom;

use Illuminate\Support\Arr;
use DoubleScale\Modules\Booking\Integration\RemoteData as Abstracts_RemoteData;

/**
 * Zoom Remote Data class
 */
class RemoteData extends Abstracts_RemoteData {

	/**
	 * Fetch Calendars
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function fetch_calendars() {
		$response = $this->integration->api->get_calendars();
		if ( ! $response['success'] ) {
			return array();
		}

		$calendars = array();
		foreach ( Arr::get( $response, 'data.value', array() ) as $calendar ) {
			$name        = Arr::get( $calendar, 'name' );
			$owner_name  = Arr::get( $calendar, 'owner.address' );
			$id          = Arr::get( $calendar, 'id' );
			$calendars[] = array(
				'id'   => $id,
				'name' => sprintf( '%s (%s)', $name, $owner_name ),
			);
		}

		return $calendars;
	}

	/**
	 * Fetch Events
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Data.
	 *
	 * @return array
	 */
	public function fetch_events( $data ) {
		$calendar_id = $data['calendar'];
		$start_date  = $data['start_date'];
		$end_date    = $data['end_date'];

		// Convert date in 2011-06-03T10:00:00Z
		$start_date = new \DateTime( $start_date, new \DateTimeZone( 'UTC' ) );
		$end_date   = new \DateTime( $end_date, new \DateTimeZone( 'UTC' ) );

		$args = array(
			'startdatetime' => $start_date->format( 'Y-m-d\TH:i:s\Z' ),
			'enddatetime'   => $end_date->format( 'Y-m-d\TH:i:s\Z' ),
			'$select'       => 'subject,recurrence,showAs,start,end,subject,isAllDay,transactionId',
			'$top'          => 100,
		);

		$headers = array(
			'Prefer' => 'zoom.timezone="UTC"',
		);

		$response = $this->integration->api->get_events( $calendar_id, $args, $headers );
		if ( ! $response['success'] ) {
			return array();
		}

		$events = array();
		foreach ( Arr::get( $response, 'data.value', array() ) as $event ) {
			$event_start = Arr::get( $event, 'start.dateTime' );
			$event_end   = Arr::get( $event, 'end.dateTime' );
			$start       = new \DateTime( $event_start, new \DateTimeZone( 'UTC' ) );
			$end         = new \DateTime( $event_end, new \DateTimeZone( 'UTC' ) );

			// Format date in 2011-06-03 10:00:00
			$start = $start->format( 'Y-m-d H:i:s' );
			$end   = $end->format( 'Y-m-d H:i:s' );

			$events[] = array(
				'id'      => $event['id'],
				'start'   => $start,
				'end'     => $end,
				'subject' => $event['subject'],
			);
		}

		return $events;
	}
}
