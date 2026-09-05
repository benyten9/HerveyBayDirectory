<?php //phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * SEOPress PRO Product Schema.
 *
 * @package SEOPress PRO
 * @subpackage Schemas
 */

defined( 'ABSPATH' ) || exit( 'Please don&rsquo;t call the plugin directly. Thanks :)' );

/**
 * Automatic rich snippets products option.
 *
 * @param array $schema_datas The schema datas.
 * @return void
 */
function seopress_automatic_rich_snippets_products_option( $schema_datas ) {

	// If no data.
	if ( 0 != count( array_filter( $schema_datas ) ) ) {
		// Init.
		global $post;
		global $product;

		$products_name = $schema_datas['name'];
		if ( '' == $products_name ) {
			$products_name = the_title_attribute( 'echo=0' );
		}

		$products_description = $schema_datas['description'];
		if ( '' == $products_description ) {
			// Escaping before trimming can cut an entity in half, and the value
			// goes to JSON-LD rather than to HTML. wp_trim_words() strips tags.
			$products_description = wp_trim_words( get_the_excerpt(), 30 );
		}

		$seopress_thumbnail_size = apply_filters( 'seopress_schemas_post_thumbnail_size', 'large' );

		$products_img = $schema_datas['img'];
		if ( '' == $products_img && '' != get_the_post_thumbnail_url( get_the_ID(), $seopress_thumbnail_size ) ) {
			$products_img = get_the_post_thumbnail_url( get_the_ID(), $seopress_thumbnail_size );
		}

		$products_price = $schema_datas['price'];

		if ( isset( $product ) && '' == $products_price && method_exists( $product, 'get_price' ) && '' != $product->get_price() ) {
			$products_price = $product->get_price();
		}

		$products_price_valid_date = $schema_datas['price_valid_date'];

		// Only derive a date when none was mapped in the settings: the previous else branch
		// overwrote the user-defined value with the fallback. Google expects ISO 8601
		// (YYYY-MM-DD) for priceValidUntil, so never emit the US m-d-Y format here.
		if ( empty( $products_price_valid_date ) ) {
			$sale_to_date = ( isset( $product ) && method_exists( $product, 'get_date_on_sale_to' ) ) ? $product->get_date_on_sale_to() : null;

			// WooCommerce restores the regular price when a sale ends but keeps the sale end
			// date on the product, so this date is often already past. Google drops the
			// product snippet when priceValidUntil is in the past, hence the future check.
			if ( null !== $sale_to_date && $sale_to_date->getTimestamp() > time() ) {
				$products_price_valid_date = $sale_to_date->date( 'Y-m-d' );
			} else {
				$products_price_valid_date = gmdate( 'Y-12-31', time() + YEAR_IN_SECONDS );
			}
		} else {
			$products_price_valid_date = seopress_format_schema_date( $products_price_valid_date );
		}

		$products_sku = $schema_datas['sku'];
		if ( isset( $product ) && '' == $products_sku && method_exists( $product, 'get_sku' ) && '' != $product->get_sku() ) {
			$products_sku = $product->get_sku();
		}

		$products_global_ids = $schema_datas['global_ids'];

		if ( isset( $product ) && method_exists( $product, 'get_id' ) ) {
			$wc_barcode_type = get_post_meta( $product->get_id(), 'sp_wc_barcode_type_field', true );
			if ( '' !== $wc_barcode_type && 'none' !== $wc_barcode_type ) {
				$products_global_ids = $wc_barcode_type;
			} elseif ( empty( $products_global_ids ) || 'none' === $products_global_ids ) {
				$products_global_ids = 'gtin';
			}
		}

		$products_global_ids_value = $schema_datas['global_ids_value'];

		if ( isset( $product ) && method_exists( $product, 'get_id' ) ) {
			$wc_barcode = get_post_meta( $product->get_id(), 'sp_wc_barcode_field', true );
			if ( '' !== $wc_barcode ) {
				$products_global_ids_value = $wc_barcode;
			} elseif ( method_exists( $product, 'get_global_unique_id' ) ) {
				$wc_global_unique_id = $product->get_global_unique_id();
				if ( '' !== $wc_global_unique_id ) {
					$products_global_ids_value = $wc_global_unique_id;
				}
			}
		}

		$products_brand = $schema_datas['brand'];

		$products_currency = $schema_datas['currency'];
		if ( '' == $products_currency && function_exists( 'get_woocommerce_currency' ) && get_woocommerce_currency() ) {
			$products_currency = get_woocommerce_currency();
		} elseif ( '' == $products_currency && function_exists( 'edd_get_currency' ) && edd_get_currency() ) {
			$products_currency = edd_get_currency();
		} elseif ( '' == $products_currency ) {
			$products_currency = 'USD';
		}

		$products_condition = $schema_datas['condition'];
		if ( '' == $products_condition ) {
			$products_condition = seopress_check_ssl() . 'schema.org/NewCondition';
		}

		$products_availability = $schema_datas['availability'];

		// When no availability is forced in the settings, derive it from the product's real
		// stock status (used by the simple-product branch below; variable products compute
		// availability per variation). A product can be out of stock yet accept backorders,
		// so check is_on_backorder() before is_in_stock().
		if ( '' == $products_availability ) {
			$products_availability = seopress_check_ssl() . 'schema.org/InStock';

			if ( isset( $product ) && is_a( $product, 'WC_Product' ) ) {
				if ( method_exists( $product, 'is_on_backorder' ) && $product->is_on_backorder() ) {
					$products_availability = seopress_check_ssl() . 'schema.org/BackOrder';
				} elseif ( method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
					$products_availability = seopress_check_ssl() . 'schema.org/OutOfStock';
				}
			}
		}

		$json = array(
			'@context'    => seopress_check_ssl() . 'schema.org/',
			'@type'       => 'Product',
			'name'        => $products_name,
			'image'       => $products_img,
			'description' => $products_description,
			'sku'         => $products_sku,
		);

		/**
		 * Give the Product node a stable @id. A relative "#product" (default)
		 * lets a third-party emitter that outputs its own Product node with the
		 * same @id, e.g. a reviews plugin adding aggregateRating / review, merge
		 * into a single Product in the search engine's graph. Return an absolute
		 * value to change it, or an empty string to omit the @id entirely.
		 *
		 * Mirrors the same filter on the manual builder (src/JsonSchemas/Product.php)
		 * so a single filter covers both the manual and the automatic schema paths.
		 *
		 * @since 10.1.0
		 *
		 * @param string $id           Default '#product'.
		 * @param array  $schema_datas The schema data for the current product.
		 */
		$product_id = apply_filters( 'seopress_pro_wc_schema_product_id', '#product', $schema_datas );
		if ( '' !== $product_id && null !== $product_id ) {
			$json['@id'] = $product_id;
		}

		if ( '' != $products_global_ids && $products_global_ids != 'none' && '' != $products_global_ids_value ) {
			$json[ $products_global_ids ] = $products_global_ids_value;
		}

		// Brand.
		if ( '' != $products_brand ) {
			$json['brand'] = array(
				'@type' => 'Brand',
				'name'  => $products_brand,
			);
		}

		if ( isset( $product ) && true === comments_open( get_the_ID() ) ) { // If Reviews is true.
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

			// AggregateRating.
			if ( isset( $product ) && method_exists( $product, 'get_review_count' ) && $product->get_review_count() >= 1 ) {
				$json['aggregateRating'] = array(
					'@type'       => 'AggregateRating',
					'ratingValue' => $product->get_average_rating(),
					'reviewCount' => $product->get_review_count(),
				);
			}
		} elseif ( ! empty( $schema_datas['positive_notes'] ) || ! empty( $schema_datas['negative_notes'] ) ) {

			$json['review'] = array(
				'@type'  => 'Review',
				'author' => array(
					'@type' => 'Person',
					'name'  => get_the_author(),
				),

			);
			if ( ! empty( $schema_datas['positive_notes'] ) ) {
				$json['review']['positiveNotes'] = array(
					'@type'           => 'ItemList',
					'itemListElement' => array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => $schema_datas['positive_notes'],
					),
				);

			}

			if ( ! empty( $schema_datas['negative_notes'] ) ) {
				$json['review']['negativeNotes'] = array(
					'@type'           => 'ItemList',
					'itemListElement' => array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => $schema_datas['negative_notes'],
					),
				);

			}
		}

		// Variable product.
		if ( isset( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variable' ) ) {
			// Build one Offer per published, priced variation regardless of stock status, so
			// the schema always exposes every variant with its real availability
			// (InStock / OutOfStock) — matching Google's product variants guidance, where
			// examples list all variants with their respective availability. We do not use
			// get_available_variations() here: it drops out-of-stock variations when "Hide out
			// of stock items from the catalog" is enabled, which made the schema inconsistent
			// (a sold-out variant silently disappeared, yet two sold-out variants reappeared via
			// the empty-list fallback). variation_is_visible() keeps WooCommerce's own
			// published + has-a-price check, just without the stock filter. Only the keys the
			// loop below reads are built, so this is also lighter than get_available_variation().
			$variations = array();
			$children   = method_exists( $product, 'get_children' ) ? $product->get_children() : array();

			if ( ! empty( $children ) ) {
				// Prime caches to reduce queries, mirroring get_available_variations().
				_prime_post_caches( $children );
			}

			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child || ! $child->exists() || ! method_exists( $child, 'variation_is_visible' ) || ! $child->variation_is_visible() ) {
					continue;
				}
				$variations[] = array(
					'variation_id'        => $child_id,
					'is_in_stock'         => $child->is_in_stock(),
					'sku'                 => $child->get_sku(),
					'seopress_global_ids' => get_post_meta( $child_id, 'seopress_global_ids', true ),
					'seopress_barcode'    => get_post_meta( $child_id, 'seopress_barcode', true ),
				);
			}

			$i = 1;

			// Keep each variation next to the Offer it produced, so the ProductGroup
			// conversion below can reuse those offers as is instead of rebuilding them.
			$variation_offers = array();

			foreach ( $variations as $key => $value ) {
				$product_global_ids = $schema_datas['global_ids'];
				$product_barcode    = $schema_datas['global_ids_value'];
				$product_price      = $schema_datas['price'];
				$variation          = wc_get_product( $value['variation_id'] );

				if ( isset( $value['seopress_global_ids'] ) && ! empty( $value['seopress_global_ids'] ) ) {
					$product_global_ids = $value['seopress_global_ids'];
				} elseif ( empty( $product_global_ids ) || 'none' === $product_global_ids ) {
					$product_global_ids = 'gtin';
				}
				if ( isset( $value['seopress_barcode'] ) && ! empty( $value['seopress_barcode'] ) ) {
					$product_barcode = $value['seopress_barcode'];
				} elseif ( isset( $variation ) && method_exists( $variation, 'get_global_unique_id' ) && '' != $variation->get_global_unique_id() ) {
					$product_barcode = $variation->get_global_unique_id();
				}

				$variation_price_valid_date = '';
				$variation_sale_to_date     = ( isset( $variation ) && method_exists( $variation, 'get_date_on_sale_to' ) ) ? $variation->get_date_on_sale_to() : null;

				// Skip a sale end date that has already passed: WooCommerce keeps it on the
				// variation after the sale ends, and Google drops the snippet when
				// priceValidUntil is in the past.
				if ( null !== $variation_sale_to_date && $variation_sale_to_date->getTimestamp() > time() ) {
					$variation_price_valid_date = $variation_sale_to_date->date( 'Y-m-d' );
				} elseif ( ! empty( $schema_datas['price_valid_date'] ) ) {
					$variation_price_valid_date = seopress_format_schema_date( $schema_datas['price_valid_date'] );
				} else {
					$variation_price_valid_date = gmdate( 'Y-12-31', time() + YEAR_IN_SECONDS );
				}

				if ( ! empty( $product_global_ids ) && 'none' === $product_global_ids ) {
					if ( ! empty( $products_global_ids ) ) {
						$product_global_ids = $products_global_ids;
					} else {
						$product_global_ids = 'gtin';
					}
				}

				if ( empty( $product_barcode ) ) {
					$product_barcode = $products_global_ids_value;
				}

				// Map WooCommerce stock status to schema.org availability. is_in_stock() is
				// true for on-backorder products, and a variation can be out of stock yet
				// still allow backorders, so check is_on_backorder() first.
				if ( isset( $variation ) && method_exists( $variation, 'is_on_backorder' ) && $variation->is_on_backorder() ) {
					$availability = sprintf( '%s%s/BackOrder', seopress_check_ssl(), 'schema.org' );
				} elseif ( $value['is_in_stock'] ) {
					$availability = sprintf( '%s%s/InStock', seopress_check_ssl(), 'schema.org' );
				} else {
					$availability = sprintf( '%s%s/OutOfStock', seopress_check_ssl(), 'schema.org' );
				}

				$sku = $schema_datas['sku'];
				if ( empty( $sku ) || 'none' === $sku || $product->get_sku() === $sku ) {
					$sku = empty( $value['sku'] ) ? $product->get_sku() : $value['sku'];
				}

				$variation_price = $product_price;
				if ( isset( $variation ) && function_exists( 'wc_get_price_including_tax' ) && function_exists( 'wc_get_price_excluding_tax' ) ) {
					if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
						$variation_price = wc_get_price_including_tax( $variation );
					} else {
						$variation_price = wc_get_price_excluding_tax( $variation );
					}
				}

				// Fall back to the variation's declared price when no active price is
				// computable (e.g. a sold-out variation), so the offer never emits an
				// empty/false price that Google rejects.
				if ( isset( $variation ) && ! is_numeric( $variation_price ) ) {
					$variation_price = seopress_get_wc_product_best_effort_price( $variation );
				}

				$offer = array(
					'@type'           => 'Offer',
					'url'             => $variation->get_permalink(),
					'sku'             => $sku,
					'price'           => is_float( $variation_price ) ? number_format( $variation_price, 2, '.', '' ) : $variation_price,
					'priceCurrency'   => $products_currency,
					'itemCondition'   => $products_condition,
					'availability'    => $availability,
					'priceValidUntil' => $variation_price_valid_date,
				);

				// The variation price above follows the shop tax display setting, so the list
				// price must be converted the same way to stay comparable.
				$variation_list_price = seopress_get_wc_schema_list_price( $variation, true );
				if ( '' !== $variation_list_price ) {
					$variation_list_price_specification = seopress_get_list_price_specification( $variation_list_price, $products_currency );
					if ( ! empty( $variation_list_price_specification ) ) {
						$offer['priceSpecification'] = $variation_list_price_specification;
					}
				}

				$shipping_details = seopress_get_shipping_schema( $variation );
				if ( ! empty( $shipping_details ) ) {
					$offer['shippingDetails'] = $shipping_details;
				}

				$return_policy = seopress_get_offer_return_policy_schema();
				if ( ! empty( $return_policy ) ) {
					$offer['hasMerchantReturnPolicy'] = $return_policy;
				}

				if ( ! empty( $product_barcode ) ) {
					$offer[ $product_global_ids ] = $product_barcode;
				}

				$json['offers'][] = $offer;

				$variation_offers[] = array(
					'variation' => $variation,
					'offer'     => $offer,
				);

				++$i;
			}

			// No published, priced variation produced an Offer (e.g. variations without a
			// price). Google still requires offers even for sold-out products, so emit a
			// single Offer with OutOfStock availability and a best-effort price rather than
			// dropping the whole offers block.
			if ( empty( $json['offers'] ) && method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
				$json['offers'] = seopress_get_out_of_stock_offer_schema( $product, $products_currency, $products_condition, $products_price_valid_date );
			}

			// Opt-in: turn the Product into a ProductGroup carrying its variants.
			$json = seopress_maybe_convert_to_product_group( $json, $product, $variation_offers );
		} elseif ( '' != $products_price ) {
			$json['offers'] = array(
				'@type'           => 'Offer',
				'url'             => get_permalink(),
				'priceCurrency'   => $products_currency,
				'price'           => is_float( $products_price ) ? number_format( $products_price, 2, '.', '' ) : $products_price,
				'priceValidUntil' => $products_price_valid_date,
				'itemCondition'   => $products_condition,
				'availability'    => $products_availability,
			);

			// Expose the strikethrough regular price when the product is discounted. The offer
			// price here is the raw active price, so the list price is left raw too.
			if ( isset( $product ) ) {
				$list_price = seopress_get_wc_schema_list_price( $product );
				if ( '' !== $list_price ) {
					$list_price_specification = seopress_get_list_price_specification( $list_price, $products_currency );
					if ( ! empty( $list_price_specification ) ) {
						$json['offers']['priceSpecification'] = $list_price_specification;
					}
				}
			}

			$shipping_details = seopress_get_shipping_schema( $product );
			if ( ! empty( $shipping_details ) ) {
				$json['offers']['shippingDetails'] = $shipping_details;
			}

			$return_policy = seopress_get_offer_return_policy_schema();
			if ( ! empty( $return_policy ) ) {
				$json['offers']['hasMerchantReturnPolicy'] = $return_policy;
			}
		} elseif ( isset( $product ) && is_a( $product, 'WC_Product' ) && method_exists( $product, 'is_in_stock' ) && ! $product->is_in_stock() ) {
			// Simple product, out of stock, with no active price: keep offers so the
			// product stays valid for Google instead of triggering "Missing field offers".
			// Gated on is_in_stock() so in-stock products without a price of their own
			// (e.g. grouped products) are not mislabelled as OutOfStock.
			$json['offers'] = seopress_get_out_of_stock_offer_schema( $product, $products_currency, $products_condition, $products_price_valid_date );
		}

		$json = array_filter( $json );

		$json = apply_filters( 'seopress_schemas_auto_product_json', $json );

		$json = '<script type="application/ld+json">' . seopress_pro_json_ld_encode( $json ) . '</script>' . "\n";

		$json = apply_filters( 'seopress_schemas_auto_product_html', $json );

		echo $json;
	}
}

/**
 * Map the attributes of a variation to the schema.org properties Google supports
 * for product variants.
 *
 * Shared by the automatic and the manual product schemas.
 *
 * @since 10.1.0
 *
 * @param   WC_Product $variation The variation.
 * @return  array<string,string>  schema.org property => readable value.
 */
function seopress_get_wc_variant_attributes( $variation ) {
	if ( ! is_a( $variation, 'WC_Product' ) || ! method_exists( $variation, 'get_attributes' ) ) {
		return array();
	}

	/**
	 * Filter the WooCommerce attribute to schema.org property map. Keys are
	 * attribute slugs without their `attribute_` / `pa_` prefix.
	 *
	 * @since 10.1.0
	 *
	 * @param array      $map       Attribute slug => schema.org property.
	 * @param WC_Product $variation The variation.
	 */
	$supported = apply_filters(
		'seopress_pro_wc_product_group_attributes_map',
		array(
			'color'    => 'color',
			'colour'   => 'color',
			'size'     => 'size',
			'material' => 'material',
			'pattern'  => 'pattern',
		),
		$variation
	);

	$mapped = array();

	foreach ( $variation->get_attributes() as $taxonomy => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}

		$key = preg_replace( '/^(attribute_)?(pa_)?/', '', strtolower( $taxonomy ) );

		if ( ! isset( $supported[ $key ] ) ) {
			continue;
		}

		// Taxonomy based attributes store a term slug, so resolve the label.
		$label = $value;
		if ( taxonomy_exists( $taxonomy ) ) {
			$term = get_term_by( 'slug', $value, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$label = $term->name;
			}
		}

		$mapped[ $supported[ $key ] ] = $label;
	}

	return $mapped;
}

/**
 * Turn a Product schema into a ProductGroup when the WooCommerce product has variations,
 * which is what Google expects for product variants.
 *
 * Opt-in: switching the @type changes the markup of every variable product on the site,
 * so it stays behind the WooCommerce setting.
 *
 * The Offers already built for each variation are reused as is, so the variants keep the
 * price, availability, condition, global ids and shipping details resolved above.
 *
 * @since 10.1.0
 *
 * @param   array      $json             The Product schema built so far.
 * @param   WC_Product $wc_product       The variable product.
 * @param   array      $variation_offers List of array( 'variation' => WC_Product, 'offer' => array ).
 * @return  array                        The Product schema, or its ProductGroup version.
 */
function seopress_maybe_convert_to_product_group( $json, $wc_product, $variation_offers ) {
	if ( '1' !== seopress_pro_get_service( 'OptionPro' )->getWCProductGroupEnable() ) {
		return $json;
	}

	if ( ! is_a( $wc_product, 'WC_Product' ) || empty( $variation_offers ) ) {
		return $json;
	}

	/**
	 * Maximum number of variants embedded in the ProductGroup. A product with hundreds
	 * of variations would otherwise produce a JSON-LD block far larger than search
	 * engines can make use of.
	 *
	 * @since 10.1.0
	 *
	 * @param int        $limit      Maximum number of variants.
	 * @param WC_Product $wc_product The variable product.
	 */
	$limit = (int) apply_filters( 'seopress_pro_wc_product_group_max_variants', 50, $wc_product );

	if ( $limit > 0 ) {
		$variation_offers = array_slice( $variation_offers, 0, $limit );
	}

	$variants  = array();
	$varies_by = array();

	foreach ( $variation_offers as $variation_offer ) {
		$variation = $variation_offer['variation'];

		if ( ! is_a( $variation, 'WC_Product' ) ) {
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

		foreach ( seopress_get_wc_variant_attributes( $variation ) as $property => $value ) {
			$variant[ $property ]   = $value;
			$varies_by[ $property ] = true;
		}

		$variant['offers'] = $variation_offer['offer'];

		$variants[] = $variant;
	}

	if ( empty( $variants ) ) {
		return $json;
	}

	$json['@type'] = 'ProductGroup';

	// productGroupID ties the variants together. The parent SKU is the natural
	// identifier; fall back to the product ID when no SKU is set.
	$group_id = $wc_product->get_sku();
	if ( empty( $group_id ) ) {
		$group_id = (string) $wc_product->get_id();
	}
	$json['productGroupID'] = $group_id;

	if ( ! empty( $varies_by ) ) {
		$json['variesBy'] = array_map(
			function ( $property ) {
				return 'https://schema.org/' . $property;
			},
			array_keys( $varies_by )
		);
	}

	// Those Offers now live under hasVariant: keeping them at the group level would
	// repeat the exact same nodes twice in the same schema.
	unset( $json['offers'] );

	$json['hasVariant'] = $variants;

	/**
	 * Filter the generated ProductGroup schema.
	 *
	 * @since 10.1.0
	 *
	 * @param array      $json       The ProductGroup schema.
	 * @param WC_Product $wc_product The variable product.
	 * @param array|null $context    The schema context, null for automatic schemas.
	 */
	return apply_filters( 'seopress_pro_wc_product_group_schema', $json, $wc_product, null );
}

/**
 * Resolve a declared price for a WooCommerce product, including the case where every
 * variation is out of stock and hidden from the catalog.
 *
 * WooCommerce's get_variation_regular_price('min') relies on get_visible_children(), so
 * it returns false (current() on an empty array) once all variations are hidden — even
 * though each variation still has its regular price stored. We therefore scan every variation
 * (visible or not) and return the lowest declared price, so the schema exposes the real
 * price instead of falling back to 0.
 *
 * @since 10.0.0
 *
 * @param   WC_Product $wc_product The WooCommerce product.
 * @return  string|float|int        A numeric price, or '' when none could be resolved.
 */
function seopress_get_wc_product_best_effort_price( $wc_product ) {
	if ( ! is_a( $wc_product, 'WC_Product' ) ) {
		return '';
	}

	$price = $wc_product->get_price();
	if ( is_numeric( $price ) ) {
		return $price;
	}

	$price = $wc_product->get_regular_price();
	if ( is_numeric( $price ) ) {
		return $price;
	}

	// Variable product whose variations are all hidden (out of stock): read the lowest
	// price declared across every variation, including the hidden ones.
	if ( method_exists( $wc_product, 'is_type' ) && $wc_product->is_type( 'variable' ) && method_exists( $wc_product, 'get_children' ) ) {
		$lowest = '';

		foreach ( $wc_product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			$variation_price = $variation->get_price();
			if ( ! is_numeric( $variation_price ) ) {
				$variation_price = $variation->get_regular_price();
			}

			if ( is_numeric( $variation_price ) && ( '' === $lowest || (float) $variation_price < (float) $lowest ) ) {
				$lowest = $variation_price;
			}
		}

		if ( is_numeric( $lowest ) ) {
			return $lowest;
		}
	}

	return '';
}

/**
 * Build a fallback Offer schema for an out-of-stock WooCommerce product.
 *
 * Google requires the `offers` field even when a product is sold out: it should be
 * kept with `availability: OutOfStock` rather than dropped. Used when a variable
 * product has every variation out of stock (so `get_available_variations()` is empty)
 * or when a simple product has no active price.
 *
 * @since 10.0.0
 *
 * @param   WC_Product $wc_product       The WooCommerce product.
 * @param   string     $currency         The currency code.
 * @param   string     $condition        The schema.org itemCondition URL.
 * @param   string     $price_valid_date The priceValidUntil date.
 * @return  array                        Offer schema.
 */
function seopress_get_out_of_stock_offer_schema( $wc_product, $currency, $condition, $price_valid_date ) {
	$price = seopress_get_wc_product_best_effort_price( $wc_product );

	// Last resort only: keep the offers block valid (a numeric price) when the product
	// genuinely has no price stored anywhere. Such a product can't be a merchant listing
	// anyway, but the Product snippet stays valid instead of breaking on "price": false.
	if ( ! is_numeric( $price ) ) {
		$price = '0';
	}

	// A product can be out of stock yet still accept backorders; reflect that as BackOrder
	// rather than OutOfStock.
	$availability = ( method_exists( $wc_product, 'is_on_backorder' ) && $wc_product->is_on_backorder() ) ? 'BackOrder' : 'OutOfStock';

	$offer = array(
		'@type'           => 'Offer',
		'url'             => get_permalink(),
		'priceCurrency'   => $currency,
		'price'           => is_float( $price ) ? number_format( $price, 2, '.', '' ) : $price,
		'priceValidUntil' => $price_valid_date,
		'itemCondition'   => $condition,
		'availability'    => seopress_check_ssl() . 'schema.org/' . $availability,
	);

	$shipping_details = seopress_get_shipping_schema( $wc_product );
	if ( ! empty( $shipping_details ) ) {
		$offer['shippingDetails'] = $shipping_details;
	}

	$return_policy = seopress_get_offer_return_policy_schema();
	if ( ! empty( $return_policy ) ) {
		$offer['hasMerchantReturnPolicy'] = $return_policy;
	}

	return $offer;
}

/**
 * Get the merchant return policy schema attached to product offers.
 *
 * The Organization schema — where the policy is normally declared — is only printed on
 * the front page, so a product page carried no return policy at all. Google supports
 * declaring it per offer, so the configured policy is reused here.
 *
 * The result is resolved once per request: it comes from global settings and never
 * varies per product.
 *
 * @since 10.1.0
 *
 * @return array MerchantReturnPolicy schema, or an empty array when not configured.
 */
function seopress_get_offer_return_policy_schema() {
	static $policy = null;

	if ( null !== $policy ) {
		return $policy;
	}

	$policy = array();

	if ( ! class_exists( '\SEOPressPro\Actions\Front\Schemas\MerchantReturnPolicy' ) ) {
		return $policy;
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
		return $policy;
	}

	$policy = \SEOPressPro\Actions\Front\Schemas\MerchantReturnPolicy::getPolicySchema();

	return $policy;
}

/**
 * Resolve the regular ("list") price of a discounted WooCommerce product.
 *
 * Google renders a strikethrough price in merchant listings when the Offer carries a
 * UnitPriceSpecification of type ListPrice alongside the sale price. Returns an empty
 * string when the product is not genuinely discounted, so the specification is omitted.
 *
 * @since 10.1.0
 *
 * @param   WC_Product $wc_product        The WooCommerce product.
 * @param   bool       $apply_tax_display Whether to convert the price according to the
 *                                        shop tax display setting, to match the offer price.
 * @return  string|float                  The list price, or '' when there is no discount.
 */
function seopress_get_wc_schema_list_price( $wc_product, $apply_tax_display = false ) {
	if ( ! is_a( $wc_product, 'WC_Product' ) || ! method_exists( $wc_product, 'is_on_sale' ) || ! $wc_product->is_on_sale() ) {
		return '';
	}

	$regular_price = $wc_product->get_regular_price();
	$active_price  = $wc_product->get_price();

	// Only advertise a list price when it is actually higher than what is charged.
	if ( ! is_numeric( $regular_price ) || ! is_numeric( $active_price ) || (float) $regular_price <= (float) $active_price ) {
		return '';
	}

	if ( $apply_tax_display && function_exists( 'wc_get_price_including_tax' ) && function_exists( 'wc_get_price_excluding_tax' ) ) {
		$args          = array( 'price' => $regular_price );
		$regular_price = ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) )
			? wc_get_price_including_tax( $wc_product, $args )
			: wc_get_price_excluding_tax( $wc_product, $args );
	}

	return is_numeric( $regular_price ) ? $regular_price : '';
}

/**
 * Build the ListPrice UnitPriceSpecification exposed next to a discounted Offer.
 *
 * @since 10.1.0
 *
 * @param   string|float $list_price The regular price.
 * @param   string       $currency   The currency code.
 * @return  array                    UnitPriceSpecification schema, or an empty array.
 */
function seopress_get_list_price_specification( $list_price, $currency ) {
	if ( ! is_numeric( $list_price ) ) {
		return array();
	}

	$specification = array(
		'@type'         => 'UnitPriceSpecification',
		'priceType'     => seopress_check_ssl() . 'schema.org/ListPrice',
		'price'         => number_format( (float) $list_price, 2, '.', '' ),
		'priceCurrency' => $currency,
	);

	/**
	 * Filter the ListPrice specification attached to an automatic Product schema offer.
	 *
	 * @since 10.1.0
	 *
	 * @param array        $specification UnitPriceSpecification schema.
	 * @param string|float $list_price    The regular price.
	 * @param string       $currency      The currency code.
	 */
	return apply_filters( 'seopress_schemas_auto_product_list_price_specification', $specification, $list_price, $currency );
}

/**
 * Normalize a date to the ISO 8601 format (YYYY-MM-DD) expected by Google for
 * priceValidUntil. Unparseable values are returned untouched so a custom string coming
 * from the settings is never silently destroyed.
 *
 * @since 10.1.0
 *
 * @param   string $date The date to format.
 * @return  string       The ISO 8601 date, or the original value.
 */
function seopress_format_schema_date( $date ) {
	if ( ! is_string( $date ) || '' === trim( $date ) ) {
		return $date;
	}

	try {
		$parsed = new \DateTime( $date );
	} catch ( \Exception $e ) {
		return $date;
	}

	return $parsed->format( 'Y-m-d' );
}

/**
 * Expand a WooCommerce shipping zone's locations into schema.org DefinedRegion entries.
 *
 * WooCommerce zones can target countries, states, continents or postcodes, but only
 * countries and postcodes used to be mapped — so a continent-wide or state-wide zone
 * produced an empty shippingDestination.
 *
 * @since 10.1.0
 *
 * @param   array $zone The WooCommerce zone data.
 * @return  array       DefinedRegion schemas.
 */
function seopress_get_wc_zone_shipping_destinations( $zone ) {
	$destinations = array();
	$locations    = $zone['zone_locations'] ?? array();

	foreach ( $locations as $location ) {
		if ( empty( $location->code ) ) {
			continue;
		}

		switch ( $location->type ) {
			case 'country':
				$destinations[] = array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => $location->code,
				);
				break;

			case 'state':
				// WooCommerce stores states as "COUNTRY:STATE" (e.g. "US:CA").
				$parts = explode( ':', $location->code );
				if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
					break;
				}
				$destinations[] = array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => $parts[0],
					'addressRegion'  => $parts[1],
				);
				break;

			case 'continent':
				// schema.org has no continent-level region: expand to the member countries so
				// a zone such as "Europe" stops emitting an empty destination.
				foreach ( seopress_get_wc_continent_countries( $location->code ) as $country_code ) {
					$destinations[] = array(
						'@type'          => 'DefinedRegion',
						'addressCountry' => $country_code,
					);
				}
				break;

			case 'postcode':
				$destinations[] = array(
					'@type'      => 'DefinedRegion',
					'postalCode' => $location->code,
				);
				break;
		}
	}

	// A country can be reached both directly and through its continent.
	return array_values( array_unique( $destinations, SORT_REGULAR ) );
}

/**
 * Get the country codes belonging to a WooCommerce continent.
 *
 * @since 10.1.0
 *
 * @param   string $continent_code The WooCommerce continent code (e.g. "EU").
 * @return  array                  Country codes.
 */
function seopress_get_wc_continent_countries( $continent_code ) {
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
 * Get shipping schema for a WooCommerce product.
 *
 * @param   WC_Product $wc_product The WooCommerce product.
 * @return  array       $shipping_offers  Schema
 */
function seopress_get_shipping_schema( $wc_product ) {
	if ( ! $wc_product ) {
		return array();
	}

	/**
	 * Filter to disable WooCommerce shippingDetails schema generation.
	 *
	 * @since 9.5.0
	 *
	 * @param bool       $enabled    Whether shippingDetails should be generated.
	 * @param WC_Product $wc_product The current WooCommerce product.
	 */
	$enabled = apply_filters( 'seopress_pro_wc_schema_shipping_details_enabled', true, $wc_product );
	if ( ! $enabled ) {
		return array();
	}

	if ( ! method_exists( $wc_product, 'needs_shipping' ) ) {
		return array();
	}

	$needs_shipping = $wc_product->needs_shipping();
	if ( ! $needs_shipping ) {
		return array();
	}

	$shipping_class_id = (int) $wc_product->get_shipping_class_id();
	$currency          = get_woocommerce_currency();

	// Cache computed shipping offers by shipping class (request-level).
	static $shipping_offers_cache = array();
	$cache_key                    = sprintf( '%d|%s', $shipping_class_id, (string) $currency );
	if ( isset( $shipping_offers_cache[ $cache_key ] ) ) {
		return $shipping_offers_cache[ $cache_key ];
	}

	// Persist cache if an object cache is available.
	$object_cache_key = 'wc_shipping_details_' . md5( $cache_key );
	$cached           = wp_cache_get( $object_cache_key, 'seopress_pro' );
	if ( is_array( $cached ) ) {
		$shipping_offers_cache[ $cache_key ] = $cached;
		return $cached;
	}

	// Create an offer for each rate in each zone.
	$shipping_offers = array();

	// Cache zones (and their shipping methods) per request.
	static $zones_cache = null;
	if ( null === $zones_cache ) {
		$zones_cache = WC_Shipping_Zones::get_zones();
	}

	foreach ( $zones_cache as $zone ) {
		$zone_shipping_destination = seopress_get_wc_zone_shipping_destinations( $zone );

		// A zone whose locations cannot be expressed as a DefinedRegion used to emit
		// "shippingDestination": [], which Google rejects. Skip the zone entirely instead.
		if ( empty( $zone_shipping_destination ) ) {
			continue;
		}

		foreach ( $zone['shipping_methods'] as $method ) {
			// WC_Shipping_Zones::get_zones() returns every method, enabled or not. Publishing a
			// disabled rate advertises a shipping cost the customer can never be charged.
			if ( method_exists( $method, 'is_enabled' ) && ! $method->is_enabled() ) {
				continue;
			}

			$instance = $method->instance_settings;

			// Free Shipping methods have no cost; min_amount is the order threshold, not the price.
			if ( 'free_shipping' === $method->id ) {
				$cost = 0;
			} else {
				$cost = isset( $instance['cost'] ) ? (float) $instance['cost'] : 0;
			}
			if ( $shipping_class_id && isset( $instance['type'] ) && 'class' === $instance['type'] ) {
				$cost_key = 'class_cost_' . (int) $shipping_class_id;
				if ( ! empty( $instance[ $cost_key ] ) ) {
					$cost += (float) $instance[ $cost_key ];
				}
			}
			$shipping_offers[] = array(
				'@type'               => 'OfferShippingDetails',
				'shippingDestination' => $zone_shipping_destination,
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => $cost,
					'currency' => $currency,
				),
			);
		}
	}

	/**
	 * Filter the generated WooCommerce shippingDetails schema.
	 *
	 * @since 9.5.0
	 *
	 * @param array      $shipping_offers ShippingDetails schema array.
	 * @param WC_Product $wc_product      The current WooCommerce product.
	 */
	$shipping_offers = apply_filters( 'seopress_pro_wc_schema_shipping_details', $shipping_offers, $wc_product );

	$shipping_offers_cache[ $cache_key ] = $shipping_offers;
	wp_cache_set( $object_cache_key, $shipping_offers, 'seopress_pro', HOUR_IN_SECONDS );

	return $shipping_offers;
}
