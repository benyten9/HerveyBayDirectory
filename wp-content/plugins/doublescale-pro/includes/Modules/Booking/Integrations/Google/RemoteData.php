<?php

/**
 * Class Google Remote Data
 *
 * This class is responsible for handling the Google Remote Data
 *
 * @since 1.0.0
 *
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Google;

use DoubleScale\Modules\Booking\Integration\RemoteData as Abstracts_RemoteData;

/**
 * Google Remote Data class
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
		$calendars = array();

		$page_token = null;
		do {
			$query = array( 'maxResults' => 250 );
			if ( $page_token ) {
				$query['pageToken'] = $page_token;
			}

			$response = $this->integration->api->get_calendars( $query );
			if ( ! $response['success'] ) {
				return array();
			}

			foreach ( $response['data']['items'] ?? array() as $calendar ) {
				$access_role = $calendar['accessRole'] ?? 'reader';
				$can_edit    = in_array( $access_role, array( 'owner', 'writer' ), true );

				$calendars[] = array(
					'id'       => $calendar['id'],
					'name'     => $calendar['summary'],
					'can_edit' => $can_edit,
				);
			}

			$page_token = isset( $response['data']['nextPageToken'] ) ? (string) $response['data']['nextPageToken'] : null;
		} while ( ! empty( $page_token ) );

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
			'timeMin'  => $start_date->format( 'c' ),
			'timeMax'  => $end_date->format( 'c' ),
			'timeZone' => 'UTC',
		);

		$response = $this->integration->api->get_events( $calendar_id, $args );
		if ( ! $response['success'] ) {
			return array();
		}

		$events = array();
		foreach ( $response['data']['items'] ?? array() as $event ) {
			$start = new \DateTime( $event['start']['dateTime'], new \DateTimeZone( 'UTC' ) );
			$end   = new \DateTime( $event['end']['dateTime'], new \DateTimeZone( 'UTC' ) );

			// Format date in 2011-06-03 10:00:00
			$start = $start->format( 'Y-m-d H:i:s' );
			$end   = $end->format( 'Y-m-d H:i:s' );

			$events[] = array(
				'id'      => $event['id'],
				'start'   => $start,
				'end'     => $end,
				'summary' => $event['summary'],
			);
		}

		return $events;
	}
}
