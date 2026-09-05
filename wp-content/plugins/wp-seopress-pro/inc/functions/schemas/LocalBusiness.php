<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Local Business Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

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

	$lb_canonical = '';
	if ( get_post_meta( get_the_ID(), '_seopress_robots_canonical', true ) ) {
		$lb_canonical = get_post_meta( get_the_ID(), '_seopress_robots_canonical', true );
	} else {
		global $wp;
		if ( isset( $wp->request ) ) {
			$lb_canonical = home_url( add_query_arg( array(), $wp->request ) );
		}
	}

	if ( ! empty( $lb_canonical ) ) {
		$json['@id'] = $lb_canonical;
	}

	if ( isset( $lb_street_addr ) || isset( $lb_city ) || isset( $lb_state ) || isset( $lb_pc ) || isset( $lb_country ) ) {
		$json['address'] = array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $lb_street_addr,
			'addressLocality' => $lb_city,
			'addressRegion'   => $lb_state,
			'postalCode'      => $lb_pc,
			'addressCountry'  => $lb_country,
		);
	}

	if ( isset( $lb_lat ) || isset( $lb_lon ) ) {
		$json['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => $lb_lat,
			'longitude' => $lb_lon,
		);
	}

	if ( isset( $lb_serves_cuisine ) &&
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

	echo $json;
}
