<?php
/**
 * REST API: WooCommerce products for sales line-item insertion.
 *
 * Returns a value-copy shape matching the saved-product picker so the frontend
 * can insert name / description / rate onto a line without storing a live
 * product_id. Editing WooCommerce later must not alter existing documents.
 *
 * @package DoubleScale\Pro\Modules\Sales\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestWooProductController class.
 */
class RestWooProductController extends RestController {

	/**
	 * Skip expanding variable products with more children than this.
	 *
	 * @var int
	 */
	private const MAX_VARIATIONS = 50;

	/**
	 * @var string
	 */
	protected $rest_base = 'woocommerce-products';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		return Permissions::has_sales_rep_access();
	}

	/**
	 * Paginated WooCommerce product list. Shape ({data, total}) matches what
	 * InfiniteScrollSelect expects. Variable products are expanded into one
	 * row per variation; pagination is by parent product.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_REST_Response(
				array(
					'data'  => array(),
					'total' => 0,
				),
				200
			);
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;
		$search   = trim( (string) $request->get_param( 'search' ) );

		$args = array(
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'title',
			'order'    => 'ASC',
			'type'     => array( 'simple', 'variable', 'external', 'grouped' ),
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$result   = wc_get_products( $args );
		$products = is_object( $result ) && isset( $result->products ) ? $result->products : array();
		$total    = is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;

		$data = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				$rows = $this->expand_variations( $product );
				foreach ( $rows as $row ) {
					$data[] = $row;
				}
				continue;
			}

			$data[] = $this->prepare_product_for_response( $product );
		}

		return new WP_REST_Response(
			array(
				'data'  => $data,
				'total' => $total,
			),
			200
		);
	}

	/**
	 * Expand a variable product into one row per published variation.
	 * Oversized variables are skipped so one monster product cannot blow up a page.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return array<int, array<string, mixed>>
	 */
	private function expand_variations( $product ): array {
		$children = $product->get_children();
		if ( array() === $children || count( $children ) > self::MAX_VARIATIONS ) {
			return array();
		}

		$rows = array();
		foreach ( $children as $child_id ) {
			$variation = wc_get_product( (int) $child_id );
			if ( ! $variation instanceof \WC_Product || ! $variation->is_type( 'variation' ) ) {
				continue;
			}
			if ( 'publish' !== $variation->get_status() ) {
				continue;
			}

			$rows[] = $this->prepare_product_for_response( $variation, $product );
		}

		return $rows;
	}

	/**
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent  Parent, when $product is a variation.
	 * @return array<string, mixed>
	 */
	private function prepare_product_for_response( $product, $parent = null ): array {
		$rate = (float) wc_get_price_excluding_tax( $product );

		$long_description = $product->get_short_description();
		if ( '' === $long_description && $parent instanceof \WC_Product ) {
			$long_description = $parent->get_short_description();
		}

		return array(
			'id'               => (int) $product->get_id(),
			'name'             => (string) $product->get_formatted_name(),
			'long_description' => wp_strip_all_tags( (string) $long_description ),
			'unit'             => '',
			'group_name'       => $this->get_primary_category_name( $product, $parent ),
			'rate'             => $rate,
			'tax'              => array(),
		);
	}

	/**
	 * Primary product_cat name for grouping in the picker.
	 *
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent  Parent, when $product is a variation.
	 * @return string
	 */
	private function get_primary_category_name( $product, $parent = null ): string {
		$term_id = $product->is_type( 'variation' ) && $parent instanceof \WC_Product
			? $parent->get_id()
			: $product->get_id();

		$terms = get_the_terms( $term_id, 'product_cat' );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				return (string) $term->name;
			}
		}

		return (string) $terms[0]->name;
	}
}
