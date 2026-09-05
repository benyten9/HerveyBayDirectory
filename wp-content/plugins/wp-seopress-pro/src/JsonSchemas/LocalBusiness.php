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
			'id'                  => array(
				'default' => '%%schema_article_canonical%%',
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

		return apply_filters( 'seopress_pro_get_json_data_local_business', $data, $context );
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
