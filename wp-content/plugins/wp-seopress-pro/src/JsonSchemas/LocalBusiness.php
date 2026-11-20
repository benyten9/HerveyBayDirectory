<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class LocalBusiness extends JsonSchemaValue implements GetJsonData {
	const NAME = 'local-business';

	const ALIAS = array( 'localbusiness' );

	protected function getName() {
		return self::NAME;
	}

	protected function getDayByKey( $key ) {
		switch ( $key ) {
			case 0:
				return 'Monday';
			case 1:
				return 'Tuesday';
			case 2:
				return 'Wednesday';
			case 3:
				return 'Thursday';
			case 4:
				return 'Friday';
			case 5:
				return 'Saturday';
			case 6:
				return 'Sunday';
		}
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 */
	protected function getKeysForOptionLocalBusiness() {
		return array(
			'type'                => '%%local_business_type%%',
			'image'               => '%%social_knowledge_image%%',
			'id'                  => '%%siteurl%%',
			'name'                => '%%social_knowledge_name%%',
			'url'                 => '%%local_business_url%%',
			'telephone'           => '%%local_business_phone%%',
			'priceRange'          => '%%local_business_price_range%%',
			'servesCuisine'       => '%%local_business_cuisine%%',
			'acceptsReservations' => '%%local_business_accepts_reservations%%',
			'menu'                => '%%local_business_menu%%',
		);
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 */
	protected function getKeysForSchemaManual() {
		return array(
			'type'                => array(
				'value'   => '_seopress_pro_rich_snippets_lb_type',
				'default' => 'LocalBusiness',
			),
			'image'               => '_seopress_pro_rich_snippets_lb_img',
			'url'                 => '_seopress_pro_rich_snippets_lb_website',
			'telephone'           => '_seopress_pro_rich_snippets_lb_tel',
			'priceRange'          => '_seopress_pro_rich_snippets_lb_price',
			'country'             => '_seopress_pro_rich_snippets_lb_country',
			'postalCode'          => '_seopress_pro_rich_snippets_lb_pc',
			'state'               => '_seopress_pro_rich_snippets_lb_state',
			'city'                => '_seopress_pro_rich_snippets_lb_city',
			'address'             => '_seopress_pro_rich_snippets_lb_street_addr',
			'menu'                => '_seopress_pro_rich_snippets_lb_menu',
			'acceptsReservations' => '_seopress_pro_rich_snippets_lb_accepts_reservations',
			'servesCuisine'       => '_seopress_pro_rich_snippets_lb_cuisine',
			'name'                => array(
				'value'   => '_seopress_pro_rich_snippets_lb_name',
				'default' => '%%sitetitle%%',
			),
			// A fragment, not the bare canonical. The breadcrumb's last crumb
			// publishes `<canonical>` as the WebPage, and the page and the
			// business on it are not the same thing.
			'id'                  => array(
				'default' => '%%schema_article_canonical%%#localbusiness',
			),
			'openingHours'        => '_seopress_pro_rich_snippets_lb_opening_hours',
		);
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 */
	protected function getTypeFood() {
		return array(
			'FoodEstablishment',
			'Bakery',
			'BarOrPub',
			'Brewery',
			'CafeOrCoffeeShop',
			'FastFoodRestaurant',
			'IceCreamShop',
			'Restaurant',
			'Winery',
		);
	}

	/**
	 * @since 4.6.0
	 *
	 * @return array
	 *
	 * @param array $keys
	 * @param array $data
	 */
	protected function getVariablesByKeysAndData( $keys, $data = array() ) {
		$variables = parent::getVariablesByKeysAndData( $keys, $data );

		if ( isset( $variables['servesCuisine'] ) && ! in_array( $variables['type'], $this->getTypeFood(), true ) ) {
			unset( $variables['servesCuisine'] );
		}

		if ( isset( $variables['openingHours']['seopress_local_business_opening_hours'] ) ) {
			$variables['openingHours'] = $variables['openingHours']['seopress_local_business_opening_hours'];
		}

		return $variables;
	}

	/**
	 * @since 4.5.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::OPTION_LOCAL_BUSINESS;

		$openingHours = array();
		$variables    = $this->getVariablesByType( $typeSchema, $context );

		if ( RichSnippetType::OPTION_LOCAL_BUSINESS === $typeSchema ) {
			$openingHours = seopress_pro_get_service( 'OptionPro' )->getLocalBusinessOpeningHours();
		} elseif ( isset( $variables['openingHours'] ) ) {
			$openingHours = $variables['openingHours'];
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );
		// Global business settings describe this entity only, never a manual
		// schema for another location. An unset image keeps the existing fallback.
		if ( RichSnippetType::OPTION_LOCAL_BUSINESS === $typeSchema ) {
			$options = get_option( 'seopress_pro_option_name', array() );
			$options = \SEOPressPro\Helpers\Settings\LocalBusinessHelper::sanitize_additional_fields( is_array( $options ) ? $options : array() );
			foreach ( array( 'email' => 'email', 'logo' => 'logo', 'image' => 'image', 'area_served' => 'areaServed', 'same_as' => 'sameAs' ) as $field => $property ) {
				$value = $options[ 'seopress_local_business_' . $field ] ?? '';
				if ( '' !== $value ) {
					$data[ $property ] = in_array( $field, array( 'area_served', 'same_as' ), true ) ? explode( "\n", $value ) : $value;
				}
			}
		}


		$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( PostalAddress::NAME, $context, array( 'remove_empty' => true ) );

		if ( count( $schema ) > 1 ) {
			$data['address'] = $schema;
		}

		$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Geo::NAME, $context, array( 'remove_empty' => true ) );

		if ( count( $schema ) > 1 ) {
			$data['geo'] = $schema;
		}

		if ( ! empty( $openingHours ) ) {
			foreach ( $openingHours as $key => $day ) {
				if ( isset( $day['open'] ) && '1' === $day['open'] ) { // bad name => reality is closed
					continue;
				}

				foreach ( $day as $keyHalfDay => $halfDay ) {
					if ( ! isset( $halfDay['open'] ) || '1' !== $halfDay['open'] ) {
						continue;
					}

					$variablesOpeningHours = array(
						'dayOfWeek' => $this->getDayByKey( $key ),
						'opens'     => \sprintf( '%02d:%02d', (int) ( $halfDay['start']['hours'] ?? 0 ), (int) ( $halfDay['start']['mins'] ?? 0 ) ),
						'closes'    => \sprintf( '%02d:%02d', (int) ( $halfDay['end']['hours'] ?? 0 ), (int) ( $halfDay['end']['mins'] ?? 0 ) ),
					);

					$schema = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( OpeningHours::NAME, array( 'variables' => $variablesOpeningHours ), array( 'remove_empty' => true ) );
					if ( count( $schema ) > 1 ) {
						$data['openingHoursSpecification'][] = $schema;
					}
				}
			}
		}

		// The business the global settings describe is the site's own business,
		// which is the entity the Free side publishes as Organization. It
		// identified itself with the bare site root instead, so the two nodes
		// described the same business and said nothing about each other. The
		// shared organization identity is what connects them.
		//
		// Only that path. A manual LocalBusiness is another business the author
		// entered on a post, and the inline `provider` of a Service is the
		// business that provides it: giving either of them the site's identity
		// would publish someone else's plumber as this agency, and would undo
		// the explicit Knowledge Graph choice ServiceProviderReference exists
		// to make. Free applies the same rule, no identity on a SUB_TYPE.
		//
		// The id is read back from the Organization generator, like the
		// publisher and the Service provider, so a site that moved the node
		// with `seopress_get_json_data_organization` (the workaround #1597
		// hands customers) gets its business on the same identity.
		if ( RichSnippetType::OPTION_LOCAL_BUSINESS === $typeSchema ) {
			$data['@id'] = $this->organizationIdentity();
		}

		// Two manual businesses on one page resolve their own values from their
		// own entry, but both derived their identity from the same canonical,
		// so a consumer merging on `@id` read one business with the other's
		// address. The first keeps the bare `#localbusiness` fragment, so the
		// common case of a single business is unchanged.
		if ( RichSnippetType::MANUAL === $typeSchema ) {
			$data = $this->numberManualIdentity( $data, $context );
		}

		return apply_filters( 'seopress_pro_get_json_data_local_business', $data, $context );
	}

	/**
	 * The identifier of the site's Organization node.
	 *
	 * Read from the Organization generator when the Knowledge Graph publishes
	 * one. Otherwise, a Person, an empty or a disabled Knowledge Graph, it is
	 * the default organization identity, which is what this node carried
	 * before. With a Free version older than the shared helper, the same
	 * identity is built by hand: falling back to the site root would make the
	 * business and the home WebPage of the breadcrumb one node.
	 *
	 * @since 10.4.0
	 *
	 * @return string
	 */
	protected function organizationIdentity() {
		if ( ! $this->hasEntityIdHelper() ) {
			return trailingslashit( home_url() ) . '#organization';
		}

		$identity = \SEOPressPro\Helpers\PublisherEntity::get_id();

		return '' !== $identity ? $identity : \SEOPress\Helpers\SchemaEntityId::for_home( 'organization' );
	}

	/**
	 * Whether the Free side ships the shared identity helper (10.3+).
	 *
	 * @since 10.4.0
	 *
	 * @return bool
	 */
	protected function hasEntityIdHelper() {
		return class_exists( '\\SEOPress\\Helpers\\SchemaEntityId' );
	}

	public function cleanValues( $data ) {
		if ( isset( $data['@type'] ) && ! in_array( $data['@type'], $this->getTypeFood(), true ) ) {
			$removeKeys = array( 'menu', 'acceptsReservations', 'servesCuisine' );
			foreach ( $removeKeys as $key => $value ) {
				if ( isset( $data[ $value ] ) ) {
					unset( $data[ $value ] );
				}
			}
		}

		return $data;
	}
}
