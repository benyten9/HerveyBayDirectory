<?php
/**
 * SEOPress PRO Local Business block.
 *
 * @package SEOPress PRO
 * @subpackage Blocks
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Local Business Block Field display callback
 *
 * @param   array    $attributes  Block attributes.
 * @param   string   $content     Inner block content.
 * @param   WP_Block $block       Actual block.
 * @return  string    $html
 */
function seopress_pro_local_business_field_block( $attributes, $content, $block ) {
	$field    = ! empty( $attributes['field'] ) ? $attributes['field'] : '';
	$tag      = (bool) $attributes['inline'] ? 'span' : 'p';
	$external = (bool) $attributes['external'];
	$attr     = get_block_wrapper_attributes( array( 'class' => sanitize_title( $field ) ) );
	$value    = '';
	switch ( $field ) {
		case 'seopress_local_business_street_address':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessStreetAddress();
			break;
		case 'seopress_local_business_postal_code':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPostalCode();
			break;
		case 'seopress_local_business_address_locality':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessAddressLocality();
			break;
		case 'seopress_local_business_region':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessAddressRegion();
			break;
		case 'seopress_local_business_country':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessAddressCountry();
			break;
		case 'seopress_local_business_phone':
			$value = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPhone();
			break;
		case 'seopress_local_business_map_link':
			$value = seopress_pro_get_local_business_map_link( $external );
			break;
		case 'seopress_local_business_opening_hours':
			$hide_closed_days = ! empty( $attributes['hideClosedDays'] ) ? (bool) $attributes['hideClosedDays'] : false;
			$value            = seopress_pro_get_local_business_opening_hours( $hide_closed_days );
			$tag              = 'div';
			break;
	}
	$html = ! empty( $value ) ? sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, $attr, wp_kses_post( $value ) ) : sprintf( '<p style="color:red">%s</p>', __( 'This value is missing from your Local Business settings', 'wp-seopress-pro' ) );
	return apply_filters( 'seopress_local_business_field_block_html', $html, $attributes, $content, $block );
}

/**
 * Returns a Google Map link to the Local Business
 *
 * @return  string  $html  Link html
 * @param mixed $external Whether to open the link in a new tab.
 */
function seopress_pro_get_local_business_map_link( $external = false ) {
	$lat      = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessLatitude();
	$lon      = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessLongitude();
	$place_id = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessPlaceId();
	$map_url  = 'https://www.google.com/maps/search/?api=1';
	$html     = '';
	$display  = false;
	if ( ! empty( $place_id ) ) {
		$map_url = add_query_arg( 'query_place_id', urlencode( $place_id ), $map_url );
		$display = true;
	}
	if ( $lat && $lon ) {
		$map_url = add_query_arg( 'query', urlencode( $lat . ',' . $lon ), $map_url );
		$display = true;
	}
	$title = $external ? __( 'View this local business on Google Maps (new window)', 'wp-seopress-pro' ) : __( 'View this local business on Google Maps', 'wp-seopress-pro' );
	if ( $display ) {
		$html = sprintf(
			'<a href="%1$s" title="%2$s"%3$s>%4$s</a>',
			esc_url( $map_url ),
			esc_attr( $title ),
			(bool) $external ? ' target="_blank"' : '',
			esc_html__( 'View on Google Maps', 'wp-seopress-pro' )
		);
	}
	return apply_filters( 'seopress_pro_local_business_map_link', $html );
}

/**
 * Returns the Local Business opening hours table
 *
 * @return  string  $html  HTML Table
 * @param mixed $hide_closed_days Whether to hide closed days.
 * @param mixed $attr HTML attributes.
 */
function seopress_pro_get_local_business_opening_hours( $hide_closed_days = false, $attr = 'class="sp-opening-hours-table"' ) {
	$opening_hours = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHours();
	$days          = seopress_pro_get_weekdays();

	// Get display format setting.
	$display_format = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHoursDisplayFormat();
	if ( empty( $display_format ) ) {
		$display_format = '24h'; // Default to 24-hour format.
	}

	// Get separator setting.
	$separator = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHoursSeparator();
	if ( empty( $separator ) ) {
		$separator = ':'; // Default to colon.
	}

	// Apply filter to allow customization of separator.
	$separator = apply_filters( 'seopress_lb_block_opening_hours_separator', $separator );

	// Helper function to format time based on display format and separator.
	$format_time = function ( $hours, $mins ) use ( $display_format, $separator ) {
		if ( '12h' === $display_format ) {
			$time           = $hours . ':' . $mins;
			$formatted_time = date( 'g:i A', strtotime( $time ) );
			// Replace the colon with the custom separator.
			$formatted_time = str_replace( ':', $separator, $formatted_time );
			return $formatted_time;
		} else {
			return $hours . $separator . $mins;
		}
	};

	$html = '';
	if ( ! empty( $opening_hours ) ) {
		$html = sprintf( '<table %s><tbody>', $attr );
		foreach ( $opening_hours as $index => $day ) {
			if ( ! empty( $day['open'] ) && 1 == $day['open'] && $hide_closed_days ) {
				continue;
			}

			$am_open  = ! empty( $day['am'] ) && ! empty( $day['am']['open'] );
			$pm_open  = ! empty( $day['pm'] ) && ! empty( $day['pm']['open'] );
			$am_start = $format_time( $day['am']['start']['hours'], $day['am']['start']['mins'] );
			$pm_start = $format_time( $day['pm']['start']['hours'], $day['pm']['start']['mins'] );
			$am_end   = $format_time( $day['am']['end']['hours'], $day['am']['end']['mins'] );
			$pm_end   = $format_time( $day['pm']['end']['hours'], $day['pm']['end']['mins'] );
			$am_cell  = $am_open ? sprintf( '<td>%s - %s</td>', esc_html( $am_start ), esc_html( $am_end ) ) : '<td class="sp-lb-closed"></td>';
			$pm_cell  = $pm_open ? sprintf( '<td>%s - %s</td>', esc_html( $pm_start ), esc_html( $pm_end ) ) : '<td class="sp-lb-closed"></td>';

			if ( ! $am_open && ! $pm_open ) {
				if ( $hide_closed_days ) {
					continue;
				}
				$html .= sprintf( '<tr class="sp-lb-closed"><th scope="row">%s</th><td colspan="2" class="sp-lb-closed sp-lb-closed-all-day">%s</td></tr>', esc_html( $days[ $index ] ), esc_html( __( 'Closed', 'wp-seopress-pro' ) ) );
			} else {
				$html .= sprintf( '<tr><th scope="row">%s</th>%s%s</tr>', esc_html( $days[ $index ] ), $am_cell, $pm_cell );
			}
		}
		$html .= '</tbody></table>';
	}
	return apply_filters( 'seopress_pro_local_business_opening_hours', $html );
}

/**
 * Returns array of weekdays
 *
 * @return  array
 */
function seopress_pro_get_weekdays() {
	return array(
		__( 'Monday', 'wp-seopress-pro' ),
		__( 'Tuesday', 'wp-seopress-pro' ),
		__( 'Wednesday', 'wp-seopress-pro' ),
		__( 'Thursday', 'wp-seopress-pro' ),
		__( 'Friday', 'wp-seopress-pro' ),
		__( 'Saturday', 'wp-seopress-pro' ),
		__( 'Sunday', 'wp-seopress-pro' ),
	);
}
