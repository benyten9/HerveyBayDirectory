<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Local Business Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

require_once __DIR__ . '/Canonical.php';

/**
 * Automatic rich snippets local business option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_lb_option( $schema_datas ) {
	$lb_name                 = $schema_datas['name'];
	$lb_type                 = $schema_datas['type'];
	$lb_img                  = $schema_datas['img'];
	$lb_street_addr          = $schema_datas['street_addr'];
	$lb_city                 = $schema_datas['city'];
	$lb_state                = $schema_datas['state'];
	$lb_pc                   = $schema_datas['pc'];
	$lb_country              = $schema_datas['country'];
	$lb_lat                  = $schema_datas['lat'];
	$lb_lon                  = $schema_datas['lon'];
	$lb_website              = $schema_datas['website'];
	$lb_tel                  = $schema_datas['tel'];
	$lb_price                = $schema_datas['price'];
	$lb_serves_cuisine       = $schema_datas['serves_cuisine'];
	$lb_menu                 = $schema_datas['menu'];
	$lb_accepts_reservations = $schema_datas['accepts_reservations'];
	$lb_opening_hours        = $schema_datas['opening_hours'];

	if ( '' == $lb_name ) {
		$lb_name = get_bloginfo( 'name' );
	}

	if ( '' == $lb_type ) {
		$lb_type = 'LocalBusiness';
	}

	if ( '' == $lb_menu && '' != $lb_website ) {
		$lb_menu = $lb_website;
	}

	$json = array(
		'@context'   => seopress_check_ssl() . 'schema.org',
		'@type'      => $lb_type,
		'name'       => $lb_name,
		'image'      => $lb_img,
		'url'        => $lb_website,
		'telephone'  => $lb_tel,
		'priceRange' => $lb_price,
	);

	$lb_canonical = seopress_schema_current_canonical();

	if ( ! empty( $lb_canonical ) ) {
		// The business, not the page it is described on. The breadcrumb trail
		// publishes the bare canonical as the WebPage node.
		$json['@id'] = $lb_canonical . '#localbusiness';
	}

	// Keep only the address fields the user filled, and add the node only when
	// at least one of them is. These variables all come from $schema_datas and
	// are always set, as empty strings when nothing was entered, so an isset()
	// guard here is always true and published an all-empty PostalAddress on
	// every page. Same defect as the Service provider node fixed in #1478.
	$lb_address = array_filter(
		array(
			'streetAddress'   => $lb_street_addr,
			'addressLocality' => $lb_city,
			'addressRegion'   => $lb_state,
			'postalCode'      => $lb_pc,
			'addressCountry'  => $lb_country,
		),
		static function ( $value ) {
			return '' !== $value && null !== $value;
		}
	);

	if ( ! empty( $lb_address ) ) {
		$json['address'] = array_merge( array( '@type' => 'PostalAddress' ), $lb_address );
	}

	// Both coordinates or none: a GeoCoordinates carrying one of the two locates
	// nothing. Compared against '' rather than tested for truthiness so that a
	// latitude or longitude of 0, which is a real coordinate, is kept.
	if ( '' !== $lb_lat && null !== $lb_lat && '' !== $lb_lon && null !== $lb_lon ) {
		$json['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => $lb_lat,
			'longitude' => $lb_lon,
		);
	}

	if ( '' !== $lb_serves_cuisine &&
		(
			'FoodEstablishment' === $lb_type
			|| 'Bakery' === $lb_type
			|| 'BarOrPub' === $lb_type
			|| 'Brewery' === $lb_type
			|| 'CafeOrCoffeeShop' === $lb_type
			|| 'FastFoodRestaurant' === $lb_type
			|| 'IceCreamShop' === $lb_type
			|| 'Restaurant' === $lb_type
			|| 'Winery' === $lb_type
		)
	) {
		$json['servesCuisine']       = $lb_serves_cuisine;
		$json['menu']                = $lb_menu;
		$json['acceptsReservations'] = $lb_accepts_reservations;
	}

	if ( ! empty( $lb_opening_hours ) ) {
		$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

		foreach ( $lb_opening_hours as $key => $day ) { // DAY.
			// The meta is stored unsanitized, so a malformed day would make
			// array_key_exists() throw a TypeError and take the page down.
			if ( ! is_array( $day ) || ! isset( $days[ $key ] ) ) {
				continue;
			}
			if ( ! array_key_exists( 'open', $day ) ) {// CLOSED?
				foreach ( $day as $keys => $ampm ) { // AM/PM.
					if ( ! is_array( $ampm ) ) {
						continue;
					}
					if ( ! array_key_exists( 'open', $ampm ) ) {
						// OPEN?
						continue;
					}
					$hours = array(
						'@type'     => 'OpeningHoursSpecification',
						'dayOfWeek' => $days[ $key ],
					);

					foreach ( $ampm as $_key => $value ) { // HOURS.
						// Skips the scalar 'open' flag, and a malformed start/end
						// that would otherwise warn on the foreach below.
						if ( ! is_array( $value ) ) {
							continue;
						}
						$i         = 0;
						$full_time = null;
						if ( 'start' == $_key ) { // START AM/PM.
							foreach ( $value as $__key => $time ) {
								$full_time .= $time;
								if ( 0 === $i ) {
									$full_time .= ':';
								}
								++$i;
							}
							$hours['opens'][] = $full_time;
						}
						if ( 'end' == $_key ) { // CLOSE AM/PM.
							foreach ( $value as $__key => $time ) {
								$full_time .= $time;
								if ( 0 === $i ) {
									$full_time .= ':';
								}
								++$i;
							}
							$hours['closes'][] = $full_time;
						}
					}

					$json['openingHoursSpecification'][] = $hours;

				}
			}
		}
	}

	$json = array_filter( $json );

	$json = apply_filters( 'seopress_schemas_auto_lb_json', $json );

	$json = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";

	$json = apply_filters( 'seopress_schemas_auto_lb_html', $json );

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD document encoded with wp_json_encode() above, filterable by third parties.
}
