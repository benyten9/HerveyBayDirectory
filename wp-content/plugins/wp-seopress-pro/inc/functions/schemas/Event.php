<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Event Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets events option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_events_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		// Init.
		global $post;

		$events_type                   = $schema_datas['type'];
		$events_name                   = $schema_datas['name'];
		$events_desc                   = $schema_datas['desc'];
		$events_img                    = $schema_datas['img'];
		$events_start_date             = $schema_datas['start_date'];
		$events_start_date_timezone    = $schema_datas['start_date_timezone'];
		$events_start_time             = $schema_datas['start_time'];
		$events_end_date               = $schema_datas['end_date'];
		$events_end_time               = $schema_datas['end_time'];
		$events_previous_start_date    = $schema_datas['previous_start_date'];
		$events_previous_start_time    = $schema_datas['previous_start_time'];
		$events_location_name          = $schema_datas['location_name'];
		$events_location_url           = $schema_datas['location_url'];
		$events_location_address       = $schema_datas['location_address'];
		$events_offers_name            = $schema_datas['offers_name'];
		$events_offers_cat             = $schema_datas['offers_cat'];
		$events_offers_price           = $schema_datas['offers_price'];
		$events_offers_price_currency  = $schema_datas['offers_price_currency'];
		$events_offers_availability    = $schema_datas['offers_availability'];
		$events_offers_valid_from_date = $schema_datas['offers_valid_from_date'];
		$events_offers_valid_from_time = $schema_datas['offers_valid_from_time'];
		$events_offers_url             = $schema_datas['offers_url'];
		$events_performer              = $schema_datas['performer'];
		$events_organizer_name         = $schema_datas['organizer_name'];
		$events_organizer_url          = $schema_datas['organizer_url'];
		$events_status                 = $schema_datas['status'];
		$event_attendance_mode         = $schema_datas['attendance_mode'];

		if ( 'events_start_date' === $events_start_date && 'events_start_time' === $events_start_time ) {
			if ( get_post_meta( $post->ID, '_EventStartDateUTC', true ) ) {
				$events_start_date = get_post_meta( $post->ID, '_EventStartDateUTC', true );
			} elseif ( get_post_meta( $post->ID, '_EventStartDate', true ) ) {
				$events_start_date = get_post_meta( $post->ID, '_EventStartDate', true );
			}
			$events_start_date = explode( ' ', $events_start_date );
			$events_start_date = $events_start_date[0] . 'T' . $events_start_date[1];
		} elseif ( '' != $events_start_date && '' != $events_start_time ) {
			$events_start_date = $events_start_date . 'T' . $events_start_time;
		}
		if ( '' != $events_start_date_timezone && '' != $events_start_date ) {
			$events_start_date = $events_start_date . $events_start_date_timezone;
		}

		if ( 'events_end_date' === $events_end_date && 'events_end_time' === $events_end_time ) {
			if ( get_post_meta( $post->ID, '_EventEndDateUTC', true ) ) {
				$events_end_date = get_post_meta( $post->ID, '_EventEndDateUTC', true );
			} elseif ( get_post_meta( $post->ID, '_EventEndDate', true ) ) {
				$events_end_date = get_post_meta( $post->ID, '_EventEndDate', true );
			}
			$events_end_date = explode( ' ', $events_end_date );
			$events_end_date = $events_end_date[0] . 'T' . $events_end_date[1];
		} elseif ( '' != $events_end_date && '' != $events_end_time ) {
			$events_end_date = $events_end_date . 'T' . $events_end_time;
		}

		if ( '' != $events_previous_start_date && '' != $events_previous_start_time ) {
			$events_previous_start_date = $events_previous_start_date . 'T' . $events_previous_start_time;
		}

		if ( '' != $events_offers_valid_from_date && '' != $events_offers_valid_from_time ) {
			$events_offers_valid_from_date = $events_offers_valid_from_date . 'T' . $events_offers_valid_from_time;
		}

		if ( '' != $events_status ) {
			$events_status = seopress_check_ssl() . 'schema.org/' . $events_status;
		}

		if ( 'events_cost' === $events_offers_price ) {
			if ( get_post_meta( $post->ID, '_EventCost', true ) ) {
				$events_offers_price = get_post_meta( $post->ID, '_EventCost', true );
			}
		}

		if ( 'events_currency' === $events_offers_price_currency ) {
			if ( get_post_meta( $post->ID, '_EventCurrencySymbol', true ) ) {
				$events_offers_price_currency = get_post_meta( $post->ID, '_EventCurrencySymbol', true );
			}
		}

		if ( 'events_location_name' === $events_location_name ) {
			if ( get_the_title( get_post_meta( $post->ID, '_EventVenueID', true ) ) ) {
				$events_location_name = get_the_title( get_post_meta( $post->ID, '_EventVenueID', true ) );
			}
		}

		if ( 'events_website' === $events_location_url ) {
			if ( get_post_meta( $post->ID, '_EventURL', true ) ) {
				$events_location_url = get_post_meta( $post->ID, '_EventURL', true );
			}
		}

		if ( 'events_location_address' === $events_location_address ) {
			$event_id                = get_post_meta( $post->ID, '_EventVenueID', true );
			$events_location_address = array();
			if ( get_post_meta( $event_id, '_VenueAddress', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueAddress', true );
			}
			if ( get_post_meta( $event_id, '_VenueCity', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueCity', true );
			}
			if ( get_post_meta( $event_id, '_VenueProvince', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueProvince', true );
			}
			if ( get_post_meta( $event_id, '_VenueStateProvince', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueStateProvince', true );
			}
			if ( get_post_meta( $event_id, '_VenueZip', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueZip', true );
			}
			if ( get_post_meta( $event_id, '_VenueCountry', true ) ) {
				$events_location_address[] = get_post_meta( $event_id, '_VenueCountry', true );
			}
			if ( ! empty( $events_location_address ) ) {
				$events_location_address = implode( ', ', $events_location_address );
			}
		}

		$json = array(
			'@context'    => seopress_check_ssl() . 'schema.org',
			'@type'       => $events_type,
			'name'        => $events_name,
			'description' => $events_desc,
			'image'       => $events_img,
			'url'         => $events_location_url,
			'startDate'   => $events_start_date,
			'endDate'     => $events_end_date,
		);

		if ( seopress_check_ssl() . 'schema.org/EventRescheduled' === $events_status && '' != $events_previous_start_date ) {
			$json['previousStartDate'] = $events_previous_start_date;
		}

		if ( '' != $events_status && 'none' != $events_status ) {
			$json['eventStatus'] = $events_status;
		}

		if ( '' != $event_attendance_mode && 'none' != $event_attendance_mode ) {
			if (
						( 'OnlineEventAttendanceMode' == $event_attendance_mode && '' != $events_location_url )
						||
						( 'MixedEventAttendanceMode' == $event_attendance_mode && '' != $events_location_url )
					) {
				$json['eventAttendanceMode'] = $event_attendance_mode;
			} else {
				$json['eventAttendanceMode'] = $event_attendance_mode;
			}
		}

		if ( '' != $events_location_name && '' != $events_location_address ) {
			if ( 'OnlineEventAttendanceMode' == $event_attendance_mode && '' != $events_location_url ) {
				$json['location'] = array(
					'@type' => 'VirtualLocation',
					'url'   => $events_location_url,
				);
			} elseif ( 'MixedEventAttendanceMode' == $event_attendance_mode && '' != $events_location_url ) {
				$json['location'][] = array(
					'@type' => 'VirtualLocation',
					'url'   => $events_location_url,
				);
				$json['location'][] = array(
					'@type'   => 'Place',
					'name'    => $events_location_name,
					'address' => $events_location_address,
				);
			} else {
				$json['location'] = array(
					'@type'   => 'Place',
					'name'    => $events_location_name,
					'address' => $events_location_address,
				);
			}
		}

		if ( '' != $events_offers_name ) {
			$json['offers'] = array(
				'@type'         => 'Offer',
				'name'          => $events_offers_name,
				'category'      => $events_offers_cat,
				'price'         => is_float( $events_offers_price ) ? number_format( $events_offers_price, 2, '.', '' ) : $events_offers_price,
				'priceCurrency' => $events_offers_price_currency,
				'url'           => $events_offers_url,
				'availability'  => $events_offers_availability,
				'validFrom'     => $events_offers_valid_from_date,
			);
		}

		if ( '' != $events_performer ) {
			$json['performer'] = array(
				'@type' => 'Person',
				'name'  => $events_performer,
			);
		}

		if ( '' != $events_organizer_name ) {
			$json['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $events_organizer_name,
				'url'   => $events_organizer_url,
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_event_json', $json );

		$json = '<script type="application/ld+json">' . wp_json_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_event_html', $json );

		echo $json;
	}
}
