<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Service Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets services option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_services_option( $schema_datas ) {
	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		// Init.
		global $product;

		$service_name          = $schema_datas['name'];
		$service_type          = $schema_datas['type'];
		$service_desc          = $schema_datas['description'];
		$service_img           = $schema_datas['img'];
		$service_area          = $schema_datas['area'];
		$service_provider_name = $schema_datas['provider_name'];
		$service_lb_img        = $schema_datas['lb_img'];
		$service_provider_mob  = $schema_datas['provider_mobility'];
		$service_slogan        = $schema_datas['slogan'];
		$service_street_addr   = $schema_datas['street_addr'];
		$service_city          = $schema_datas['city'];
		$service_state         = $schema_datas['state'];
		$service_postal_code   = $schema_datas['pc'];
		$service_country       = $schema_datas['country'];
		$service_lat           = $schema_datas['lat'];
		$service_lon           = $schema_datas['lon'];
		$service_tel           = $schema_datas['tel'];
		$service_price         = $schema_datas['price'];

		$json = array(
			'@context'         => seopress_check_ssl() . 'schema.org/',
			'@type'            => 'Service',
			'name'             => $service_name,
			'serviceType'      => $service_type,
			'description'      => $service_desc,
			'image'            => $service_img,
			'areaServed'       => $service_area,
			'providerMobility' => $service_provider_mob,
			'slogan'           => $service_slogan,
		);

		$service_canonical = '';
		if ( get_post_meta( get_the_ID(), '_seopress_robots_canonical', true ) ) {
			$service_canonical = get_post_meta( get_the_ID(), '_seopress_robots_canonical', true );
		} else {
			global $wp;
			if ( isset( $wp->request ) ) {
				$service_canonical = home_url( add_query_arg( array(), $wp->request ) );
			}
		}

		if ( ! empty( $service_canonical ) ) {
			$json['@id'] = $service_canonical;
		}

		if ( '' != $service_provider_name ) {
			$provider = array(
				'@type'      => 'LocalBusiness',
				'name'       => $service_provider_name,
				'telephone'  => $service_tel,
				'image'      => $service_lb_img,
				'priceRange' => $service_price,
			);

			// Only add the postal address when at least one field is filled, and
			// keep only the non-empty fields, so an all-empty PostalAddress is
			// never emitted (it was previously always added because the address
			// variables are always set as empty strings).
			$address = array_filter(
				array(
					'streetAddress'   => $service_street_addr,
					'addressLocality' => $service_city,
					'addressRegion'   => $service_state,
					'postalCode'      => $service_postal_code,
					'addressCountry'  => $service_country,
				),
				static function ( $value ) {
					return '' !== $value && null !== $value;
				}
			);
			if ( ! empty( $address ) ) {
				$provider['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
			}

			if ( '' != $service_lat || '' != $service_lon ) {
				$provider['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $service_lat,
					'longitude' => $service_lon,
				);
			}

			// Drop empty provider fields (telephone, image, priceRange) so the
			// LocalBusiness provider node stays clean and valid.
			$json['provider'] = array_filter(
				$provider,
				static function ( $value ) {
					return '' !== $value && null !== $value && array() !== $value;
				}
			);
		}

		if ( isset( $product ) && true === comments_open( get_the_ID() ) ) {
			// Review.
			$args = array(
				'meta_key'    => 'rating',
				'number'      => 1,
				'status'      => 'approve',
				'post_status' => 'publish',
				'parent'      => 0,
				'orderby'     => 'meta_value_num',
				'order'       => 'DESC',
				'post_id'     => get_the_ID(),
				'post_type'   => 'product',
			);

			$comments = get_comments( $args );

			if ( ! empty( $comments ) ) {
				$json['review'] = array(
					'@type'        => 'Review',
					'reviewRating' => array(
						'@type'       => 'Rating',
						'ratingValue' => get_comment_meta( $comments[0]->comment_ID, 'rating', true ),
					),
					'author'       => array(
						'@type' => 'Person',
						'name'  => get_comment_author( $comments[0]->comment_ID ),
					),
				);
			}
		}

		if ( isset( $product ) && $product->get_review_count() >= 1 ) {
			$json['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $product->get_average_rating(),
				'reviewCount' => $product->get_review_count(),
			);
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_service_json', $json );

		$json = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_service_html', $json );

		echo $json;
	}
}
