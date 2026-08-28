<?php
/**
 * Read-only product catalog abilities.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Modules\Sales\Capabilities;
use DoubleScale\Pro\Modules\ProductCatalog\Models\ProductModel;

/**
 * The catalog has no owner column — products are shared reference data, so
 * there is no Gate 3 here. Its value is as a LOOKUP: an agent asked to reason
 * about pricing, or later to build invoice line items, needs to resolve a
 * product name to a rate first.
 */
final class ProductAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$permission = array( self::class, 'can_view_sales' );

		return array(
			'doublescale/list-products' => array(
				'module_slug'      => 'product_catalog',
				'label'            => __( 'List products', 'doublescale' ),
				'description'      => __( 'Saved products and services with their unit, rate, and tax. Use this to resolve a product name to its price before quoting a figure.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Match on product name.',
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_products' ),
			),

			'doublescale/get-product'   => array(
				'module_slug'      => 'product_catalog',
				'label'            => __( 'Get product', 'doublescale' ),
				'description'      => __( 'One product with its full description, unit, rate, and tax rate.', 'doublescale' ),
				'category'         => AbilityCategories::SALES,
				'permission'       => $permission,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Product id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'execute_callback' => array( self::class, 'get_product' ),
			),
		);
	}

	/**
	 * Gate 2 — the shared Sales view capability.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_view_sales(): bool {
		return Capabilities::current_user_can( 'doublescale_view_sales' );
	}

	/**
	 * List products.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_products( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = ProductModel::query();

		$search = isset( $input['search'] ) ? trim( (string) $input['search'] ) : '';
		if ( '' !== $search ) {
			$query->where( 'name', 'LIKE', '%' . $search . '%' );
		}

		$total = (int) $query->count();

		$rows = $query->orderBy( 'name' )
			->limit( $limit )
			->offset( $offset )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_product( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Get one product.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_product( array $input ) {
		$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
		if ( $id <= 0 ) {
			return AbilityResult::not_found( __( 'Provide a valid product id.', 'doublescale' ) );
		}

		$product = ProductModel::query()->where( 'id', $id )->first();
		if ( ! $product ) {
			return AbilityResult::not_found( __( 'No product found with that id.', 'doublescale' ) );
		}

		$data = self::shape_product( $product );

		$body                = AbilityResult::truncate( (string) ( $product->long_description ?? '' ) );
		$data['description'] = $body['text'];
		$data['truncated']   = $body['truncated'];

		return $data;
	}

	/**
	 * Shape a product row.
	 *
	 * @since 1.0.0
	 *
	 * @param object $product Product.
	 * @return array<string, mixed>
	 */
	private static function shape_product( $product ): array {
		return array(
			'id'   => (int) $product->id,
			'name' => $product->name,
			'unit' => $product->unit,
			'rate' => null !== $product->rate ? (float) $product->rate : null,
			'tax'  => null !== $product->tax ? (float) $product->tax : null,
		);
	}
}
