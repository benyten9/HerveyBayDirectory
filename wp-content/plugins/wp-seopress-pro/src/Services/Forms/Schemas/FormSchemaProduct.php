<?php

namespace SEOPressPro\Services\Forms\Schemas;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Core\FormApi;
use SEOPressPro\Helpers\Schemas\Currencies;

class FormSchemaProduct extends FormApi {
	protected function getTypeByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_product_description':
				return 'textarea';
			case '_seopress_pro_rich_snippets_product_img':
				return 'upload';
			case '_seopress_pro_rich_snippets_product_price_valid_date':
				return 'date';
			case '_seopress_pro_rich_snippets_product_global_ids':
			case '_seopress_pro_rich_snippets_product_brand':
			case '_seopress_pro_rich_snippets_product_price_currency':
			case '_seopress_pro_rich_snippets_product_condition':
			case '_seopress_pro_rich_snippets_product_availability':
			case '_seopress_pro_rich_snippets_product_energy_consumption':
				return 'select';
			case '_seopress_pro_rich_snippets_product_name':
			case '_seopress_pro_rich_snippets_product_price':
			case '_seopress_pro_rich_snippets_product_sku':
			case '_seopress_pro_rich_snippets_product_global_ids_value':
				return 'input';
			case '_seopress_pro_rich_snippets_product_positive_notes':
				return 'repeater_positive_notes';
			case '_seopress_pro_rich_snippets_product_negative_notes':
				return 'repeater_negative_notes';
		}
	}

	protected function getLabelByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_product_name':
				return __( 'Product name', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_description':
				return __( 'Product description', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_img':
				return __( 'Thumbnail', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price':
				return __( 'Product price', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price_valid_date':
				return __( 'Product price valid until', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_sku':
				return __( 'Product SKU', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_brand':
				return __( 'Product Brand', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_global_ids':
				return __( 'Product Global Identifiers type', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_global_ids_value':
				return __( 'Product Global Identifier value', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price_currency':
				return __( 'Product currency', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_condition':
				return __( 'Product Condition', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_availability':
				return __( 'Product Availability', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_positive_notes':
				return __( 'Positive notes', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_negative_notes':
				return __( 'Negative notes', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_energy_consumption':
				return __( 'Energy consumption', 'wp-seopress-pro' );
		}
	}

	protected function getPlaceholderByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_product_name':
				return __( 'The name of your product', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_description':
				return __( 'The description of the product', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price':
				return __( 'e.g. 30', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price_valid_date':
				return __( 'e.g. YYYY-MM-DD', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_sku':
				return __( 'e.g. 0446310786', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_global_ids_value':
				return __( 'e.g. 925872', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_positive_notes':
				return __( 'Enter your positive notes', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_negative_notes':
				return __( 'Enter your negative notes', 'wp-seopress-pro' );

		}
	}

	protected function getDescriptionByField( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_product_name':
				return __( 'Default: product title', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_description':
				return __( 'Default: product excerpt', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_img':
				return __( 'Pictures clearly showing the product, e.g. against a white background, are preferred', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price':
				return __( 'Default: active product price', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_price_valid_date':
				return __( 'Default: sale price dates To field', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_sku':
				return __( 'Default: product SKU', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_condition':
				return __( 'Default: new', 'wp-seopress-pro' );
			case '_seopress_pro_rich_snippets_product_availability':
				return '';
		}
	}

	protected function getOptions( $field ) {
		switch ( $field ) {
			case '_seopress_pro_rich_snippets_product_global_ids':
				return array(
					array(
						'value' => 'none',
						'label' => __( 'Select a global identifier', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'gtin8',
						'label' => __( 'gtin8 (ean8)', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'gtin12',
						'label' => __( 'gtin12 (ean12)', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'gtin13',
						'label' => __( 'gtin13 (ean13)', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'gtin14',
						'label' => __( 'gtin14 (ean14)', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'mpn',
						'label' => __( 'mpn', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'isbn',
						'label' => __( 'isbn', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_product_brand':
				$data = array(
					array(
						'value' => 'none',
						'label' => __( 'Select a taxonomy', 'wp-seopress-pro' ),
					),
				);

				$serviceWpData = seopress_get_service( 'WordPressData' );
				if ( ! $serviceWpData || ! \method_exists( $serviceWpData, 'getTaxonomies' ) ) {
					return $data;
				}

				$taxonomies = $serviceWpData->getTaxonomies();
				if ( empty( $taxonomies ) ) {
					return $data;
				}
				foreach ( $taxonomies as $key => $value ) {
					$data[] = array(
						'value' => $key,
						'label' => $key,
					);
				}

				return $data;
			case '_seopress_pro_rich_snippets_product_price_currency':
				return Currencies::getOptions();
			case '_seopress_pro_rich_snippets_product_condition':
				return array(
					array(
						'value' => 'NewCondition',
						'label' => __( 'New', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'UsedCondition',
						'label' => __( 'Used', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'DamagedCondition',
						'label' => __( 'Damaged', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'RefurbishedCondition',
						'label' => __( 'Refurbished', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_product_availability':
				return array(
					array(
						'value' => 'InStock',
						'label' => __( 'In Stock', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'InStoreOnly',
						'label' => __( 'In Store Only', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'OnlineOnly',
						'label' => __( 'Online Only', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'LimitedAvailability',
						'label' => __( 'Limited Availability', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'SoldOut',
						'label' => __( 'Sold Out', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'OutOfStock',
						'label' => __( 'Out Of Stock', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'Discontinued',
						'label' => __( 'Discontinued', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'PreOrder',
						'label' => __( 'Pre Order', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'PreSale',
						'label' => __( 'Pre Sale', 'wp-seopress-pro' ),
					),
				);
			case '_seopress_pro_rich_snippets_product_energy_consumption':
				return array(
					array(
						'value' => 'none',
						'label' => __( 'Select an Energy Consumption', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryA',
						'label' => __( 'A', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryA1Plus',
						'label' => __( 'A+', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryA2Plus',
						'label' => __( 'A++', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryA3Plus',
						'label' => __( 'A+++', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryB',
						'label' => __( 'B', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryC',
						'label' => __( 'C', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryD',
						'label' => __( 'D', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryE',
						'label' => __( 'E', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryF',
						'label' => __( 'F', 'wp-seopress-pro' ),
					),
					array(
						'value' => 'https://schema.org/EUEnergyEfficiencyCategoryG',
						'label' => __( 'G', 'wp-seopress-pro' ),
					),
				);
		}
	}

	protected function getDetails( $postId = null ) {
		$details = array(
			array(
				'key' => '_seopress_pro_rich_snippets_product_name',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_description',
				'class' => 'seopress-textarea-high-size',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_product_img',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_product_price',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_product_price_valid_date',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_product_sku',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_global_ids',
				'value' => 'none',
			),
			array(
				'key' => '_seopress_pro_rich_snippets_product_global_ids_value',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_brand',
				'value' => 'none',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_price_currency',
				'value' => 'none',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_condition',
				'value' => 'NewCondition',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_availability',
				'value' => 'InStock',
			),
			array(
				'key'   => '_seopress_pro_rich_snippets_product_energy_consumption',
				'value' => 'none',
			),

		);

		// if($postId && get_post_type( $postId) !== 'product' && is_plugin_active('woocommerce/woocommerce.php') ) {
			$details[] = array(
				'key' => '_seopress_pro_rich_snippets_product_positive_notes',
			);
			$details[] = array(
				'key' => '_seopress_pro_rich_snippets_product_negative_notes',
			);
			// }

			return $details;
	}
}
