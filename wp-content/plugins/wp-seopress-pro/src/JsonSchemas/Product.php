<?php

namespace SEOPressPro\JsonSchemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\GetJsonData;
use SEOPressPro\Models\JsonSchemaValue;

class Product extends JsonSchemaValue implements GetJsonData {
	const NAME = 'product';

	const ALIAS = array( 'products' );

	protected function getName() {
		return self::NAME;
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 */
	protected function getKeysForSchemaManual() {
		return array(
			'type'               => '_seopress_pro_rich_snippets_type',
			'name'               => array(
				'value'   => '_seopress_pro_rich_snippets_product_name',
				'default' => '%%post_title%%',
			),
			'description'        => array(
				'value'   => '_seopress_pro_rich_snippets_product_description',
				'default' => '%%post_excerpt%%',
			),
			'image'              => array(
				'value'   => '_seopress_pro_rich_snippets_product_img',
				'default' => '%%post_thumbnail_url%%',
			),
			'price'              => array(
				'value'   => '_seopress_pro_rich_snippets_product_price',
				'default' => '%%wc_get_price%%',
			),
			'priceValidDate'     => array(
				'value'   => '_seopress_pro_rich_snippets_product_price_valid_date',
				'default' => '%%wc_price_valid_date%%',
			),
			'sku'                => array(
				'value'   => '_seopress_pro_rich_snippets_product_sku',
				'default' => '%%wc_sku%%',
			),
			'brand'              => '_seopress_pro_rich_snippets_product_brand',
			'globalIds'          => '_seopress_pro_rich_snippets_product_global_ids',
			'globalIdsValue'     => '_seopress_pro_rich_snippets_product_global_ids_value',
			'priceCurrency'      => '_seopress_pro_rich_snippets_product_price_currency',
			'condition'          => array(
				'value'   => '_seopress_pro_rich_snippets_product_condition',
				'default' => 'NewCondition',
			),
			'availability'       => '_seopress_pro_rich_snippets_product_availability',
			'positiveNotes'      => '_seopress_pro_rich_snippets_product_positive_notes',
			'negativeNotes'      => '_seopress_pro_rich_snippets_product_negative_notes',
			'energy_consumption' => '_seopress_pro_rich_snippets_product_energy_consumption',
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

		if ( 'none' === $variables['globalIds'] ) {
			$variables['globalIds'] = '';
		}
		if ( 'none' === $variables['priceCurrency'] ) {
			$variables['priceCurrency'] = '';
		}

		return $variables;
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $context
	 *
	 * @return array
	 */
	public function getJsonData( $context = null ) {
		$data = $this->getArrayJson();

		/**
		 * Give the Product node a stable @id. A relative "#product" (default)
		 * lets a third-party emitter that outputs its own Product node with the
		 * same @id, e.g. a reviews plugin adding aggregateRating / review, merge
		 * into a single Product in the search engine's graph. Return an absolute
		 * value to change it, or an empty string to omit the @id entirely.
		 *
		 * @since 10.1.0
		 *
		 * @param string $id      Default '#product'.
		 * @param array  $context The schema context.
		 */
		$product_id = apply_filters( 'seopress_pro_wc_schema_product_id', '#product', $context );
		if ( '' !== $product_id && null !== $product_id ) {
			$data['@id'] = $product_id;
		}

		$typeSchema = isset( $context['type'] ) ? $context['type'] : RichSnippetType::MANUAL;

		$variables = $this->getVariablesByType( $typeSchema, $context );

		if ( isset( $variables['globalIds'], $variables['globalIdsValue'] ) && ! empty( $variables['globalIds'] ) && ! empty( $variables['globalIdsValue'] ) ) {
			$data[ $variables['globalIds'] ] = $variables['globalIdsValue'];
		}

		if ( isset( $variables['brand'], $context['post']->ID ) && ! empty( $variables['brand'] ) ) {
			$term_list = wp_get_post_terms( $context['post']->ID, $variables['brand'], array( 'fields' => 'names' ) );

			if ( ! empty( $term_list ) && ! is_wp_error( $term_list ) ) {
				$variables['brand'] = $term_list[0];
			} else {
				unset( $variables['brand'] );
			}
		}

		// brand
		if ( isset( $variables['brand'] ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'name' => $variables['brand'],
			);
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Brand::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['brand'] = $schema;
			}
		}

		if ( isset( $variables['energy_consumption'] ) ) {
			$data['hasEnergyConsumptionDetails'] = array(
				'@type'                       => 'EnergyConsumptionDetails',
				'hasEnergyEfficiencyCategory' => $variables['energy_consumption'],
				'energyEfficiencyScaleMin'    => 'https://schema.org/EUEnergyEfficiencyCategoryG',
				'energyEfficiencyScaleMax'    => 'https://schema.org/EUEnergyEfficiencyCategoryA3Plus',
			);
		}

		// Just for WooCommerce
		if ( isset( $context['product'] ) && null !== $context['product'] && isset( $context['post']->ID ) && comments_open( $context['post']->ID ) ) {
			$args = array(
				'meta_key'    => 'rating',
				'number'      => 1,
				'status'      => 'approve',
				'post_status' => 'publish',
				'parent'      => 0,
				'orderby'     => 'meta_value_num',
				'order'       => 'DESC',
				'post_id'     => $context['post']->ID,
				'post_type'   => 'product',
			);

			$comments = get_comments( $args );

			if ( ! empty( $comments ) ) {
				$contextWithVariables              = $context;
				$contextWithVariables['variables'] = array(
					'ratingValue'  => get_comment_meta( $comments[0]->comment_ID, 'rating', true ),
					'ratingAuthor' => get_comment_author( $comments[0]->comment_ID ),
				);
				$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
				$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Review::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
				if ( count( $schema ) > 1 ) {
					$data['review'] = $schema;
				}
			}

			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $context['post']->ID );

				if ( method_exists( $product, 'get_review_count' ) && $product->get_review_count() >= 1 ) {
					$contextWithVariables              = $context;
					$contextWithVariables['variables'] = array(
						'ratingValue' => $product->get_average_rating(),
						'reviewCount' => $product->get_review_count(),
					);
					$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
					$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( AggregateRating::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
					if ( count( $schema ) > 1 ) {
						$data['aggregateRating'] = $schema;
					}
				}
			}
		}
		// Like article review
		elseif ( isset( $variables['positiveNotes'] ) || isset( $variables['negativeNotes'] ) ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'ratingAuthor'  => '%%post_author%%',
				'positiveNotes' => isset( $variables['positiveNotes'] ) ? $variables['positiveNotes'] : array(),
				'negativeNotes' => isset( $variables['negativeNotes'] ) ? $variables['negativeNotes'] : array(),
			);
			$contextWithVariables['type']      = RichSnippetType::SUB_TYPE;
			$schema                            = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Review::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
			if ( count( $schema ) > 1 ) {
				$data['review'] = $schema;
			}
		}

		$offer_price        = isset( $variables['price'] ) ? $variables['price'] : '';
		$offer_availability = isset( $variables['availability'] ) ? $variables['availability'] : '';

		// Out-of-stock products (notably variable products with every variation out of
		// stock) no longer expose an active price. Google still requires the offers field,
		// so keep it with OutOfStock availability and a best-effort price rather than
		// dropping the whole offers block.
		if ( ( '' === $offer_price || null === $offer_price ) && isset( $context['product'] ) && is_a( $context['product'], 'WC_Product' ) && method_exists( $context['product'], 'is_in_stock' ) && ! $context['product']->is_in_stock() ) {
			$wc_product = $context['product'];

			// seopress_get_wc_product_best_effort_price() lives in the automatic rich
			// snippets module, which is only loaded when that option is enabled. Pull it
			// in on demand (the file only declares functions) so manual schemas resolve
			// the price the same way — including the lowest price of hidden, out-of-stock
			// variations rather than falling back to 0.
			if ( ! function_exists( 'seopress_get_wc_product_best_effort_price' ) && defined( 'SEOPRESS_PRO_PLUGIN_DIR_PATH' ) ) {
				require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/schemas/Product.php';
			}

			if ( function_exists( 'seopress_get_wc_product_best_effort_price' ) ) {
				$offer_price = seopress_get_wc_product_best_effort_price( $wc_product );
			}

			// Never let a non-numeric price (false/'') reach the schema: Google rejects
			// "price": false as an invalid price format.
			if ( ! is_numeric( $offer_price ) ) {
				$offer_price = '0';
			}

			if ( '' === $offer_availability || null === $offer_availability ) {
				// A product can be out of stock yet still accept backorders.
				$offer_availability = ( method_exists( $wc_product, 'is_on_backorder' ) && $wc_product->is_on_backorder() ) ? 'BackOrder' : 'OutOfStock';
			}
		}

		if ( '' !== $offer_price && null !== $offer_price ) {
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array(
				'url'             => '%%post_url%%',
				'priceCurrency'   => isset( $variables['priceCurrency'] ) ? $variables['priceCurrency'] : '',
				'price'           => $offer_price,
				'priceValidUntil' => isset( $variables['priceValidDate'] ) ? $variables['priceValidDate'] : '',
				'itemCondition'   => isset( $variables['condition'] ) ? sprintf( '%sschema.org/%s', seopress_check_ssl(), $variables['condition'] ) : sprintf( '%sschema.org/%s', seopress_check_ssl(), $variables['NewCondition'] ),
				'availability'    => '' !== $offer_availability ? sprintf( '%sschema.org/%s', seopress_check_ssl(), $offer_availability ) : '',
			);

			// Get woocommerce currency if it is not set
			if ( empty( $contextWithVariables['variables']['priceCurrency'] ) ) {
				if ( isset( $context['product'] ) && null !== $context['product'] && is_a( $context['product'], 'WC_Product' ) ) {
					$contextWithVariables['variables']['priceCurrency'] = get_woocommerce_currency();
				}
			}

			$contextWithVariables['type'] = RichSnippetType::SUB_TYPE;
			$schema                       = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( Offer::NAME, $contextWithVariables, array( 'remove_empty' => true ) );

			if ( count( $schema ) > 1 ) {
				$data['offers'] = $schema;

				// Sale price: expose the regular price as a ListPrice specification so Google
				// can render the strikethrough price in merchant listings.
				$list_price_specification = $this->get_woocommerce_list_price_specification( $context, $contextWithVariables['variables']['priceCurrency'] );
				if ( ! empty( $list_price_specification ) ) {
					$data['offers']['priceSpecification'] = $list_price_specification;
				}

				// Woocommerce shipping details
				$shipping_details_schema = $this->get_woocommerce_shipping_details_schema( $context );
				if ( ! empty( $shipping_details_schema ) ) {
					$data['offers']['shippingDetails'] = $shipping_details_schema;
				}

				// Merchant return policy: the Organization schema carrying it is only printed
				// on the front page, so without this a product page has no return policy.
				$return_policy_schema = $this->get_merchant_return_policy_schema();
				if ( ! empty( $return_policy_schema ) ) {
					$data['offers']['hasMerchantReturnPolicy'] = $return_policy_schema;
				}
			}
		}

		$data = seopress_get_service( 'VariablesToString' )->replaceDataToString( $data, $variables );

		$data = $this->maybeAddProductGroup( $data, $context );

		return apply_filters( 'seopress_pro_get_json_data_product', $data, $context );
	}

	/**
	 * Turn the Product schema into a ProductGroup when the WooCommerce product has
	 * variations, which is what Google expects for product variants.
	 *
	 * Opt-in: switching the @type changes the markup of every variable product on
	 * the site, so it stays behind the WooCommerce setting.
	 *
	 * @since 10.1.0
	 *
	 * @param array $data    The Product schema built so far.
	 * @param array $context The schema context.
	 *
	 * @return array
	 */
	protected function maybeAddProductGroup( $data, $context ) {
		if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getWCProductGroupEnable() ) {
			return $data;
		}

		if ( ! isset( $context['product'] ) || ! is_a( $context['product'], 'WC_Product' ) || ! function_exists( 'wc_get_product' ) ) {
			return $data;
		}

		$product = $context['product'];

		if ( ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
			return $data;
		}

		$children = $product->get_children();

		if ( empty( $children ) ) {
			return $data;
		}

		/**
		 * Maximum number of variants embedded in the ProductGroup. A product with
		 * hundreds of variations would otherwise produce a JSON-LD block far larger
		 * than search engines can make use of.
		 *
		 * @since 10.1.0
		 *
		 * @param int         $limit   Maximum number of variants.
		 * @param \WC_Product $product The variable product.
		 */
		$limit = (int) apply_filters( 'seopress_pro_wc_product_group_max_variants', 50, $product );

		if ( $limit > 0 ) {
			$children = array_slice( $children, 0, $limit );
		}

		$variants  = array();
		$varies_by = array();

		foreach ( $children as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation ) {
				continue;
			}

			$variant = array( '@type' => 'Product' );

			$name = $variation->get_name();
			if ( ! empty( $name ) ) {
				$variant['name'] = $name;
			}

			$sku = $variation->get_sku();
			if ( ! empty( $sku ) ) {
				$variant['sku'] = $sku;
			}

			$image_id = $variation->get_image_id();
			if ( $image_id ) {
				$image_url = wp_get_attachment_image_url( $image_id, 'full' );
				if ( $image_url ) {
					$variant['image'] = $image_url;
				}
			}

			foreach ( $this->getVariantAttributes( $variation ) as $property => $value ) {
				$variant[ $property ]   = $value;
				$varies_by[ $property ] = true;
			}

			$price = $variation->get_price();
			if ( '' !== $price && null !== $price ) {
				$variant['offers'] = array(
					'@type'         => 'Offer',
					'price'         => $price,
					'priceCurrency' => get_woocommerce_currency(),
					'availability'  => 'https://schema.org/' . ( $variation->is_in_stock() ? 'InStock' : 'OutOfStock' ),
					'url'           => $variation->get_permalink(),
				);
			}

			$variants[] = $variant;
		}

		if ( empty( $variants ) ) {
			return $data;
		}

		$data['@type'] = 'ProductGroup';

		// productGroupID ties the variants together. The parent SKU is the natural
		// identifier; fall back to the product ID when no SKU is set.
		$group_id = $product->get_sku();
		if ( empty( $group_id ) ) {
			$group_id = (string) $product->get_id();
		}
		$data['productGroupID'] = $group_id;

		if ( ! empty( $varies_by ) ) {
			$data['variesBy'] = array_map(
				function ( $property ) {
					return 'https://schema.org/' . $property;
				},
				array_keys( $varies_by )
			);
		}

		$data['hasVariant'] = $variants;

		/**
		 * Filter the generated ProductGroup schema.
		 *
		 * @since 10.1.0
		 *
		 * @param array       $data    The ProductGroup schema.
		 * @param \WC_Product $product The variable product.
		 * @param array       $context The schema context.
		 */
		return apply_filters( 'seopress_pro_wc_product_group_schema', $data, $product, $context );
	}

	/**
	 * Map the attributes of a variation to the schema.org properties Google
	 * supports for product variants.
	 *
	 * @since 10.1.0
	 *
	 * @param \WC_Product $variation The variation.
	 *
	 * @return array<string,string> schema.org property => readable value.
	 */
	protected function getVariantAttributes( $variation ) {
		// The map lives in the automatic rich snippets module, which is only loaded when
		// that option is enabled. Pull it in on demand (the file only declares functions)
		// so both schema paths expose the same properties and honour the same filter.
		if ( ! function_exists( 'seopress_get_wc_variant_attributes' ) && defined( 'SEOPRESS_PRO_PLUGIN_DIR_PATH' ) ) {
			require_once SEOPRESS_PRO_PLUGIN_DIR_PATH . 'inc/functions/schemas/Product.php';
		}

		if ( ! function_exists( 'seopress_get_wc_variant_attributes' ) ) {
			return array();
		}

		return seopress_get_wc_variant_attributes( $variation );
	}

	/**
	 * @since 4.6.0
	 *
	 * @param  $data
	 *
	 * @return array
	 */
	public function cleanValues( $data ) {
		if ( isset( $data['review'] ) && isset( $data['review']['@context'] ) ) {
			unset( $data['review']['@context'] );
		}

		return parent::cleanValues( $data );
	}

	/**
	 * @since 10.1.0
	 *
	 * Returns the merchant return policy schema attached to the offer.
	 *
	 * The Organization schema — where the policy is normally declared — is only printed
	 * on the front page, so a product page carried no return policy at all. Google
	 * supports declaring it per offer, so the configured policy is reused here.
	 *
	 * @return  array  MerchantReturnPolicy schema, or an empty array when not configured.
	 */
	public function get_merchant_return_policy_schema() {
		if ( ! class_exists( '\SEOPressPro\Actions\Front\Schemas\MerchantReturnPolicy' ) ) {
			return array();
		}

		/**
		 * Filter to disable the return policy on Product schema offers.
		 *
		 * Set to false to keep the policy on the Organization schema only.
		 *
		 * @since 10.1.0
		 *
		 * @param bool $enabled Whether the policy should be attached to offers.
		 */
		if ( ! apply_filters( 'seopress_pro_wc_schema_offer_return_policy_enabled', true ) ) {
			return array();
		}

		return \SEOPressPro\Actions\Front\Schemas\MerchantReturnPolicy::getPolicySchema();
	}

	/**
	 * @since 10.1.0
	 *
	 * Returns the ListPrice UnitPriceSpecification for a discounted WooCommerce product.
	 *
	 * Google renders a strikethrough price in merchant listings when the Offer carries a
	 * UnitPriceSpecification of type ListPrice next to the sale price. Returns an empty
	 * array when the product is not genuinely discounted.
	 *
	 * @param   array  $context
	 * @param   string $currency The currency code used by the offer.
	 * @return  array            UnitPriceSpecification schema, or an empty array.
	 */
	public function get_woocommerce_list_price_specification( $context, $currency ) {
		if ( ! isset( $context['product'] ) || ! is_a( $context['product'], 'WC_Product' ) ) {
			return array();
		}

		$product = $context['product'];

		if ( ! method_exists( $product, 'is_on_sale' ) || ! $product->is_on_sale() ) {
			return array();
		}

		$regular_price = $product->get_regular_price();
		$active_price  = $product->get_price();

		// Only advertise a list price when it is actually higher than what is charged.
		if ( ! is_numeric( $regular_price ) || ! is_numeric( $active_price ) || (float) $regular_price <= (float) $active_price ) {
			return array();
		}

		$specification = array(
			'@type'         => 'UnitPriceSpecification',
			'priceType'     => sprintf( '%sschema.org/ListPrice', seopress_check_ssl() ),
			'price'         => number_format( (float) $regular_price, 2, '.', '' ),
			'priceCurrency' => ! empty( $currency ) ? $currency : get_woocommerce_currency(),
		);

		/**
		 * Filter the ListPrice specification attached to a manual Product schema offer.
		 *
		 * @since 10.1.0
		 *
		 * @param array      $specification UnitPriceSpecification schema.
		 * @param WC_Product $product       The current WooCommerce product.
		 * @param array      $context       The schema generation context.
		 */
		return apply_filters( 'seopress_pro_wc_schema_list_price_specification', $specification, $product, $context );
	}

	/**
	 * @since 7.4.0
	 *
	 * Returns shippingDetails schema for a Woocommerce product
	 *
	 * @param   array $context
	 * @return  array  $schema
	 */
	public function get_woocommerce_shipping_details_schema( $context ) {
		$schema = array();
		if ( isset( $context['product'] ) && null !== $context['product'] ) {
			$product = $context['product'];
			if ( is_a( $product, 'WC_Product' ) && $product->needs_shipping() ) {
				/**
				 * Filter to disable WooCommerce shippingDetails schema generation.
				 *
				 * @since 9.5.0
				 *
				 * @param bool       $enabled Whether shippingDetails should be generated.
				 * @param WC_Product $product The current WooCommerce product.
				 * @param array      $context The schema generation context.
				 */
				$enabled = apply_filters( 'seopress_pro_wc_schema_shipping_details_enabled', true, $product, $context );
				if ( ! $enabled ) {
					return array();
				}

				$shipping_class_id = (int) $product->get_shipping_class_id();
				$currency          = get_woocommerce_currency();

				// Cache computed shipping offers by shipping class (request-level).
				static $shipping_details_cache = array();
				$cache_key                     = sprintf( '%d|%s', $shipping_class_id, (string) $currency );
				if ( isset( $shipping_details_cache[ $cache_key ] ) ) {
					return $shipping_details_cache[ $cache_key ];
				}

				// Persist cache if an object cache is available.
				$object_cache_key = 'wc_shipping_details_schema_' . md5( $cache_key );
				$cached           = wp_cache_get( $object_cache_key, 'seopress_pro' );
				if ( is_array( $cached ) ) {
					$shipping_details_cache[ $cache_key ] = $cached;
					return $cached;
				}

				// Cache zones (and their shipping methods) per request.
				static $zones_cache = null;
				if ( null === $zones_cache ) {
					$zones_cache = \WC_Shipping_Zones::get_zones();
				}

				foreach ( $zones_cache as $zone ) {
					$destinationSchema = $this->get_woocommerce_shipping_destination_schema( $zone, $context );

					// A zone whose locations cannot be expressed as a DefinedRegion would emit
					// an OfferShippingDetails without any destination, which Google rejects.
					if ( empty( $destinationSchema ) ) {
						continue;
					}

					foreach ( $zone['shipping_methods'] as $method ) {
						// WC_Shipping_Zones::get_zones() returns every method, enabled or not.
						// Publishing a disabled rate advertises a cost that can never apply.
						if ( method_exists( $method, 'is_enabled' ) && ! $method->is_enabled() ) {
							continue;
						}

						$contextWithVariables              = $context;
						$contextWithVariables['variables'] = array(
							'shippingAmount'      => $this->get_woocommerce_shipping_amount( $method, $product ),
							'currency'            => $currency,
							'shippingDestination' => $destinationSchema,
						);
						$schema[]                          = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( OfferShippingDetails::NAME, $contextWithVariables, array( 'remove_empty' => false ) );
					}
				}

				/**
				 * Filter the generated WooCommerce shippingDetails schema.
				 *
				 * @since 9.5.0
				 *
				 * @param array      $schema  ShippingDetails schema array.
				 * @param WC_Product $product The current WooCommerce product.
				 * @param array      $context The schema generation context.
				 */
				$schema = apply_filters( 'seopress_pro_wc_schema_shipping_details', $schema, $product, $context );

				$shipping_details_cache[ $cache_key ] = $schema;
				wp_cache_set( $object_cache_key, $schema, 'seopress_pro', HOUR_IN_SECONDS );
			}
		}
		return $schema;
	}

	/**
	 * @since 7.4.0
	 *
	 * @param   array $zone     WC zone data
	 * @param   array $context
	 * @return  array  $schema   Array of DefinedRegion schemas
	 */
	public function get_woocommerce_shipping_destination_schema( $zone, $context ) {
		$regions   = array();
		$locations = $zone['zone_locations'] ?? array();

		foreach ( $locations as $location ) {
			if ( empty( $location->code ) ) {
				continue;
			}

			switch ( $location->type ) {
				case 'country':
					$regions[] = array( 'addressCountry' => $location->code );
					break;

				case 'state':
					// WooCommerce stores states as "COUNTRY:STATE" (e.g. "US:CA").
					$parts = explode( ':', $location->code );
					if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
						break;
					}
					$regions[] = array(
						'addressCountry' => $parts[0],
						'addressRegion'  => $parts[1],
					);
					break;

				case 'continent':
					// schema.org has no continent-level region: expand to the member countries
					// so a zone such as "Europe" stops emitting an empty destination.
					foreach ( $this->get_woocommerce_continent_countries( $location->code ) as $country_code ) {
						$regions[] = array( 'addressCountry' => $country_code );
					}
					break;

				case 'postcode':
					$regions[] = array( 'postalCode' => $location->code );
					break;
			}
		}

		// A country can be reached both directly and through its continent.
		$regions = array_values( array_unique( $regions, SORT_REGULAR ) );

		$schema = array();

		foreach ( $regions as $region ) {
			// Build the variables from scratch for each region: reusing the incoming context
			// leaked the previous location's addressCountry into the next DefinedRegion.
			$contextWithVariables              = $context;
			$contextWithVariables['variables'] = array_merge(
				isset( $context['variables'] ) ? $context['variables'] : array(),
				array(
					'addressCountry' => '',
					'addressRegion'  => '',
					'postalCode'     => '',
				),
				$region
			);

			$schema[] = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema( DefinedRegion::NAME, $contextWithVariables, array( 'remove_empty' => true ) );
		}

		return $schema;
	}

	/**
	 * @since 10.1.0
	 *
	 * Get the country codes belonging to a WooCommerce continent.
	 *
	 * @param   string $continent_code The WooCommerce continent code (e.g. "EU").
	 * @return  array                  Country codes.
	 */
	public function get_woocommerce_continent_countries( $continent_code ) {
		if ( ! function_exists( 'WC' ) || ! isset( WC()->countries ) || ! method_exists( WC()->countries, 'get_continents' ) ) {
			return array();
		}

		$continents = WC()->countries->get_continents();
		$countries  = isset( $continents[ $continent_code ]['countries'] ) ? (array) $continents[ $continent_code ]['countries'] : array();

		/**
		 * Filter the countries a continent-wide shipping zone expands to.
		 *
		 * A continent expands to every member country, which can noticeably grow the JSON-LD
		 * output on stores shipping worldwide. Use this filter to restrict the list.
		 *
		 * @since 10.1.0
		 *
		 * @param array  $countries      Country codes.
		 * @param string $continent_code The WooCommerce continent code.
		 */
		return apply_filters( 'seopress_pro_wc_schema_continent_countries', $countries, $continent_code );
	}

	/**
	 * @since 7.4.0
	 *
	 * @param   WC_Shipping_Method $method
	 * @param   WC_Product         $product
	 * @return  string              $cost
	 */
	public function get_woocommerce_shipping_amount( $method, $product ) {
		// Free Shipping methods have no cost; min_amount is the order threshold, not the price.
		if ( 'free_shipping' === $method->id ) {
			return 0;
		}

		$shipping_class_id = (int) $product->get_shipping_class_id();
		$instance          = $method->instance_settings;
		$cost              = isset( $instance['cost'] ) ? (float) $instance['cost'] : 0;
		if ( $shipping_class_id && isset( $instance['type'] ) && $instance['type'] === 'class' ) {
			$cost_key = 'class_cost_' . (int) $shipping_class_id;
			if ( ! empty( $instance[ $cost_key ] ) ) {
				$cost += (float) $instance[ $cost_key ];
			}
		}
		return $cost;
	}
}
