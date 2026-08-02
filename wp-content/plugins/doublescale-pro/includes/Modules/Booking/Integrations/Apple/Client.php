<?php

/**
 * Client class.
 *
 * @since 1.0.0
 * @package DoubleScale
 */

namespace DoubleScale\Pro\Modules\Booking\Integrations\Apple;

use DoubleScale\Pro\Vendor\Sabre\VObject\Component\VCalendar;
use DoubleScale\Pro\Vendor\Sabre\VObject\Reader;

/**
 * Client class.
 *
 * @since 1.0.0
 */
class Client {


	/**
	 * SabreDAV Client instance.
	 *
	 * @var \DoubleScale\Pro\Vendor\Sabre\DAV\Client
	 */
	private $client;

	/**
	 * Apple ID.
	 *
	 * @var string
	 */
	private $apple_id;

	/**
	 * App-specific password for Apple ID.
	 *
	 * @var string
	 */
	private $app_specific_password;

	/**
	 * Constructor.
	 *
	 * Initializes the SabreDAV client with credentials.
	 *
	 * @param string $apple_id              Apple ID email address.
	 * @param string $app_specific_password App-specific password for Apple ID.
	 */
	public function __construct( $apple_id, $app_specific_password ) {
		$this->apple_id              = $apple_id;
		$this->app_specific_password = $app_specific_password;
		$this->client                = new \DoubleScale\Pro\Vendor\Sabre\DAV\Client(
			array(
				'baseUri'  => 'https://caldav.icloud.com/',
				'userName' => $apple_id,
				'password' => $app_specific_password,
			)
		);
	}

	/**
	 * Get user's calendar list.
	 *
	 * @return array List of calendars with properties.
	 */
	public function get_calendars() {
		// Fetch the principal data.
		$principal = $this->client->propFind( '/', array( '{DAV:}current-user-principal' ), 0 );

		// Extract the principal URL.
		$principal_url = isset( $principal['{DAV:}current-user-principal'][0]['value'] )
			? $principal['{DAV:}current-user-principal'][0]['value']
			: null;

		if ( ! $principal_url ) {
			return array();
		}

		// Perform a PROPFIND on the principal to locate the calendar home.
		$calendar_home_data = $this->client->propFind( $principal_url, array( '{urn:ietf:params:xml:ns:caldav}calendar-home-set' ), 0 );
		$calendar_home      = isset( $calendar_home_data['{urn:ietf:params:xml:ns:caldav}calendar-home-set'][0]['value'] )
			? $calendar_home_data['{urn:ietf:params:xml:ns:caldav}calendar-home-set'][0]['value']
			: null;

		if ( ! $calendar_home ) {
			return array();
		}

		// Fetch calendar properties.
		$calendars = $this->client->propFind(
			$calendar_home,
			array(
				'{DAV:}displayname',
				'{http://apple.com/ns/ical/}calendar-color',
			),
			1
		);

		// Parse the account ID from the calendar home URL.
		preg_match( '/\/(\d+)\/calendars\//', $calendar_home, $matches );
		$account_id = isset( $matches[1] ) ? $matches[1] : 'unknown_account';

		// Process the calendars into the desired format.
		$processed_calendars = array(
			'account_id' => $account_id,
			'calendars'  => array(),
		);

		foreach ( $calendars as $url => $properties ) {
			// Extract the calendar ID after "calendars/"
			preg_match( '/calendars\/([^\/]+)\//', $url, $calendar_matches );
			$calendar_id = isset( $calendar_matches[1] ) ? $calendar_matches[1] : null;

			if ( ! $calendar_id || $calendar_id === '' ) {
				continue; // Skip base calendar path
			}

			// Skip unwanted system calendars
			$unwanted_ids = array( 'inbox', 'outbox', 'notification', 'tasks' );
			if ( in_array( strtolower( $calendar_id ), $unwanted_ids, true ) ) {
				continue;
			}

			$display_name = trim( $properties['{DAV:}displayname'] ?? '' );
			if ( $display_name === '' ) {
				continue; // Skip unnamed calendars
			}

			$processed_calendars['calendars'][ $calendar_id ] = array(
				'name'  => $display_name,
				'color' => $properties['{http://apple.com/ns/ical/}calendar-color'] ?? null,
			);
		}

		return $processed_calendars;
	}

	/**
	 * Get the calendar URL for a specific account and calendar ID.
	 *
	 * @param string $account_id The account ID.
	 * @param string $calendar_id The calendar ID.
	 * @return string The full calendar URL.
	 */
	private function get_calendar_url( $account_id, $calendar_id ) {
		return "/{$account_id}/calendars/{$calendar_id}/";
	}


	/**
	 * Get events from a calendar.
	 *
	 * @param string $account_id Account ID.
	 * @param string $calendar_id Calendar ID.
	 * @param string $start Start date in 'Ymd\THis\Z' format.
	 * @param string $end End date in 'Ymd\THis\Z' format.
	 *
	 * @return array List of events.
	 */
	public function get_events( $account_id, $calendar_id, $start, $end ) {
		$calendar_url = $this->get_calendar_url( $account_id, $calendar_id );
		$xml          = <<<XML
<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
    <D:prop>
        <D:getetag/>
        <C:calendar-data/>
    </D:prop>
    <C:filter>
        <C:comp-filter name="VCALENDAR">
            <C:comp-filter name="VEVENT">
                <C:time-range start="$start" end="$end"/>
            </C:comp-filter>
        </C:comp-filter>
    </C:filter>
</C:calendar-query>
XML;

		$response = $this->client->request( 'REPORT', $calendar_url, $xml, array( 'Depth' => 1 ) );
		if ( empty( $response['body'] ) ) {
			return array();
		}

		$events       = array();
		$xml_response = simplexml_load_string( $response['body'] );

		if ( ! $xml_response ) {
			return array();
		}

		foreach ( $xml_response->response as $event ) {
			$ical_data = (string) $event->propstat->prop->{'calendar-data'};
			$reader    = Reader::read( $ical_data );

			$eventDetails = array();
			if ( isset( $reader->VEVENT ) ) {
				foreach ( $reader->VEVENT->children() as $child ) {
					// Skip components like VALARM - only process properties
					if ( $child instanceof \DoubleScale\Pro\Vendor\Sabre\VObject\Component ) {
						continue;
					}

					$propertyName  = $child->name;
					$propertyValue = $child->getValue();

					// Add it to the event details array
					$eventDetails[ $propertyName ] = $propertyValue;
				}
			}

			$events[] = $eventDetails;
		}

		return $events;
	}

	/**
	 * Create a new event.
	 *
	 * @param string $account_id Account ID.
	 * @param string $calendar_id Calendar ID.
	 * @param array  $event_data Event data.
	 *
	 * @return array Event data.
	 */
	public function create_event( $account_id, $calendar_id, $event_data ) {
		$calendar_url = $this->get_calendar_url( $account_id, $calendar_id );
		$prepare_data = $event_data;
		unset( $prepare_data['ATTENDEES'] );
		unset( $prepare_data['ORGANIZER_NAME'] );
		unset( $prepare_data['ORGANIZER'] );

		// Create a new VCalendar object.
		$vcalendar = new VCalendar(
			array(
				'VEVENT' => $prepare_data,
			)
		);

		// Add attendees to the event.
		foreach ( $event_data['ATTENDEES'] as $attendee ) {
			$vcalendar->VEVENT->add(
				'ATTENDEE',
				"mailto:{$attendee['MAIL']}",
				array(
					'CN'       => $attendee['CN'],
					'PARTSTAT' => 'NEEDS-ACTION',
					'ROLE'     => 'REQ-PARTICIPANT',
				)
			);
		}
		$vcalendar->VEVENT->add( 'ORGANIZER', $event_data['ORGANIZER'], array( 'CN' => $event_data['ORGANIZER_NAME'] ) );

		// Serialize the VCalendar object to a string.
		$ical_data   = array();
		$ical_data[] = 'BEGIN:VCALENDAR';
		$ical_data[] = 'PRODID:-//DoubleScale//EN';
		foreach ( $vcalendar->children() as $child ) {
			// Check if child is PRODID ignore it.
			if ( 'PRODID' === $child->name ) {
				continue;
			}

			$ical_data[] = $child->serialize();
		}
		$ical_data[] = 'END:VCALENDAR';
		$ical_data   = implode( "\r\n", $ical_data );

		$calendar_url .= $event_data['UID'] . '.ics';

		// Create the event.
		$response = wp_remote_request(
			"https://caldav.icloud.com{$calendar_url}",
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Content-Type'  => 'text/calendar',
					'User-Agent'    => 'DoubleScale-Booking',
					'Authorization' => 'Basic ' . base64_encode( $this->apple_id . ':' . $this->app_specific_password ),
					'If-None-Match' => '*',
				),
				'body'    => $ical_data,
			)
		);
		$code     = wp_remote_retrieve_response_code( $response );
		if ( $code !== 201 ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to create event.', 'doublescale' ),
			);
		}

		return $this->parse_event_data( $ical_data );
	}

	/**
	 * Update an existing event.
	 *
	 * @param string $account_id Account ID.
	 * @param string $calendar_id Calendar ID.
	 * @param array  $event_data Event data.
	 *
	 * @return array Event data.
	 */
	public function update_event( $account_id, $calendar_id, $event_data ) {
		$calendar_url = $this->get_calendar_url( $account_id, $calendar_id );

		// Check if UID is set in the event data.
		if ( ! isset( $event_data['UID'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'UID is required for updating event.', 'doublescale' ),
			);
		}

		$prepare_data = $event_data;

		// Create a new VCalendar object.
		$vcalendar = new VCalendar(
			array(
				'VEVENT' => $prepare_data,
			)
		);

		// Serialize the VCalendar object to a string.
		$ical_data   = array();
		$ical_data[] = 'BEGIN:VCALENDAR';
		$ical_data[] = 'PRODID:-//DoubleScale//EN';
		foreach ( $vcalendar->children() as $child ) {
			// Check if child is PRODID ignore it.
			if ( 'PRODID' === $child->name ) {
				continue;
			}

			$ical_data[] = $child->serialize();
		}
		$ical_data[]   = 'END:VCALENDAR';
		$ical_data     = implode( "\r\n", $ical_data );
		$calendar_url .= $event_data['UID'] . '.ics';

		// Retrieve the ETag to perform a conditional update.
		$response = $this->client->request( 'GET', $calendar_url );
		if ( ! isset( $response['headers']['etag'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to retrieve ETag for the event.', 'doublescale' ),
			);
		}
		$etag = $response['headers']['etag'];
		// Update the event.
		$response = wp_remote_request(
			"https://caldav.icloud.com{$calendar_url}",
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Content-Type'  => 'text/calendar',
					'User-Agent'    => 'DoubleScale-Booking',
					'Authorization' => 'Basic ' . base64_encode( $this->apple_id . ':' . $this->app_specific_password ),
					'If-Match'      => $etag[0] ?? null,
				),
				'body'    => $ical_data,
			)
		);

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 204 ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to update event.', 'doublescale' ),
			);
		}

		return $this->parse_event_data( $ical_data );
	}

	/**
	 * Remove an existing event.
	 *
	 * @param string $account_id Account ID.
	 * @param string $calendar_id Calendar ID.
	 * @param string $event_uid   Event UID.
	 *
	 * @return array Success or failure message.
	 */
	public function delete_event( $account_id, $calendar_id, $event_uid ) {
		// Construct the URL for the event to be deleted.
		$calendar_url = $this->get_calendar_url( $account_id, $calendar_id ) . $event_uid . '.ics';

		// Retrieve the ETag to perform a conditional delete.
		$response = $this->client->request( 'GET', $calendar_url );
		if ( ! isset( $response['headers']['etag'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to retrieve ETag for the event.', 'doublescale' ),
			);
		}

		$etag = $response['headers']['etag'];

		// Perform the delete with the If-Match header to ensure it's only deleted if it hasn't changed.
		$response = $this->client->request(
			'DELETE',
			$calendar_url,
			null,
			array()
		);

		if ( $response['statusCode'] !== 204 ) {
			return array(
				'success' => false,
				'message' => __( 'Failed to remove event.', 'doublescale' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Event removed successfully.', 'doublescale' ),
		);
	}

	/**
	 * Parse event details from iCal data.
	 *
	 * @param string $ical_data The serialized iCal data.
	 *
	 * @return array Parsed event details.
	 */
	private function parse_event_data( $ical_data ) {
		$reader       = Reader::read( $ical_data );
		$eventDetails = array();

		if ( isset( $reader->VEVENT ) ) {
			foreach ( $reader->VEVENT->children() as $child ) {
				// Skip components like VALARM - only process properties
				if ( $child instanceof \DoubleScale\Pro\Vendor\Sabre\VObject\Component ) {
					continue;
				}

				$propertyName                  = $child->name;
				$propertyValue                 = $child->getValue();
				$eventDetails[ $propertyName ] = $propertyValue;
			}
		}

		return array(
			'success' => true,
			'data'    => $eventDetails,
		);
	}
}
