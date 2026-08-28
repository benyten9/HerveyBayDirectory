<?php
/**
 * REST API: Products (saved line-item templates).
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\ProductCatalog\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Sales\Models\TaxModel;
use DoubleScale\Pro\Modules\ProductCatalog\Models\ProductGroupModel;
use DoubleScale\Pro\Modules\ProductCatalog\Models\ProductModel;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestProductController class.
 */
class RestProductController extends RestController {

	/**
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Columns the collection may be sorted by.
	 *
	 * @var string[]
	 */
	private const SORTABLE_COLUMNS = array( 'name', 'rate', 'created_at', 'updated_at' );

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
					'args'                => array_merge(
						array(
							'search'   => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'group_id' => array(
								'type'        => 'integer',
								'description' => __( 'Filter by product group.', 'doublescale' ),
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
						$this->get_sorting_collection_params( self::SORTABLE_COLUMNS )
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
					'args'                => array(
						'name' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
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
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		unset( $request );

		return Permissions::has_sales_manager_access();
	}

	/**
	 * Paginated product list. The response shape ({data, total}) matches what
	 * InfiniteScrollSelect expects by default.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$page     = max( 1, (int) $request->get_param( 'page' ) );
			$per_page = (int) $request->get_param( 'per_page' );
			$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;
			$search   = trim( (string) $request->get_param( 'search' ) );

			$query = ProductModel::query()->with( 'group' );

			$group_id = $request->get_param( 'group_id' );
			if ( null !== $group_id && '' !== $group_id ) {
				$query->where( 'group_id', (int) $group_id );
			}

			if ( '' !== $search ) {
				$like = '%' . $this->escape_like( $search ) . '%';
				$query->where(
					function ( $sub ) use ( $like ) {
						$sub->where( 'name', 'LIKE', $like )
							->orWhere( 'long_description', 'LIKE', $like );
					}
				);
			}

			$total = (int) $query->count();

			$orderby = (string) $request->get_param( 'orderby' );

			if ( '' === $orderby ) {
				// Default ordering groups rows together so the picker can render
				// one heading per group. Ungrouped products sort last (group_id
				// NULL) rather than leading with a headless block.
				$query->orderByRaw( 'CASE WHEN group_id IS NULL THEN 1 ELSE 0 END ASC' )
					->orderByRaw( '(SELECT name FROM ' . $this->groups_table() . ' g WHERE g.id = group_id) ASC' )
					->orderBy( 'name', 'asc' );
			} else {
				$query = $this->apply_sorting( $query, $request, self::SORTABLE_COLUMNS, 'name', 'asc' );
			}

			$products = $query->skip( ( $page - 1 ) * $per_page )->take( $per_page )->get();

			$data = array();
			foreach ( $products as $product ) {
				$data[] = $this->prepare_product_for_response( $product );
			}

			return new WP_REST_Response(
				array(
					'data'  => $data,
					'total' => $total,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$product = ProductModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $product ) {
				return new WP_Error( 'not_found', __( 'Product not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			return new WP_REST_Response( $this->prepare_product_for_response( $product ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		try {
			$payload = $this->sanitize_product_payload( $request, true );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			$product = ProductModel::create( $payload );

			return new WP_REST_Response( $this->prepare_product_for_response( $product ), 201 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		try {
			$product = ProductModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $product ) {
				return new WP_Error( 'not_found', __( 'Product not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$payload = $this->sanitize_product_payload( $request, false );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			if ( array() !== $payload ) {
				$product->fill( $payload );
				$product->save();
			}

			return new WP_REST_Response( $this->prepare_product_for_response( $product ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		try {
			$product = ProductModel::find( (int) $request->get_param( 'id' ) );
			if ( ! $product ) {
				return new WP_Error( 'not_found', __( 'Product not found.', 'doublescale' ), array( 'status' => 404 ) );
			}

			$product->delete();

			return new WP_REST_Response( array( 'deleted' => true ), 200 );
		} catch ( Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Sanitize an incoming payload. Only keys present on the request are
	 * returned, so a PATCH never blanks fields it did not mention.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether this is a create.
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize_product_payload( $request, bool $creating ) {
		$params  = $request->get_params();
		$payload = array();

		if ( $creating || array_key_exists( 'name', $params ) ) {
			$name = sanitize_text_field( (string) ( $params['name'] ?? '' ) );
			if ( '' === $name ) {
				return new WP_Error(
					'doublescale_product_invalid_name',
					__( 'Product name is required.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			$payload['name'] = $name;
		}

		if ( array_key_exists( 'long_description', $params ) ) {
			$payload['long_description'] = sanitize_textarea_field( (string) $params['long_description'] );
		}

		if ( array_key_exists( 'unit', $params ) ) {
			$payload['unit'] = sanitize_text_field( (string) $params['unit'] );
		}

		if ( array_key_exists( 'group_id', $params ) ) {
			$group_id = $params['group_id'];
			if ( null === $group_id || '' === $group_id || 0 === (int) $group_id ) {
				$payload['group_id'] = null;
			} else {
				$group_id = (int) $group_id;
				if ( ! ProductGroupModel::find( $group_id ) ) {
					return new WP_Error(
						'doublescale_product_invalid_group',
						__( 'The selected product group does not exist.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}
				$payload['group_id'] = $group_id;
			}
		}

		if ( $creating || array_key_exists( 'rate', $params ) ) {
			$payload['rate'] = max( 0, (float) ( $params['rate'] ?? 0 ) );
		}

		if ( array_key_exists( 'tax', $params ) ) {
			$payload['tax'] = $this->resolve_taxes( $params['tax'] );
		}

		return $payload;
	}

	/**
	 * Re-resolve client-supplied taxes against the sales taxes table so stored
	 * snapshots always carry a real name/rate. Unknown ids are dropped.
	 *
	 * @param mixed $raw Raw tax input.
	 * @return array<int, array<string, mixed>>
	 */
	private function resolve_taxes( $raw ): array {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				$ids[] = (int) $entry['id'];
			} elseif ( is_numeric( $entry ) ) {
				$ids[] = (int) $entry;
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$taxes = TaxModel::query()->whereIn( 'id', $ids )->get();

		$resolved = array();
		foreach ( $taxes as $tax ) {
			$resolved[] = array(
				'id'   => (int) $tax->id,
				'name' => (string) $tax->name,
				'rate' => (float) $tax->rate,
			);
		}

		return $resolved;
	}

	/**
	 * @param ProductModel $product Product.
	 * @return array<string, mixed>
	 */
	private function prepare_product_for_response( $product ): array {
		$tax = $product->tax;

		// Lists eager-load `group` (avoiding N+1); single-item routes don't, so
		// fall back to a lazy read there rather than returning a blank name.
		$group = null;
		if ( $product->relationLoaded( 'group' ) ) {
			$group = $product->group;
		} elseif ( null !== $product->group_id ) {
			$group = ProductGroupModel::find( (int) $product->group_id );
		}

		return array(
			'id'               => (int) $product->id,
			'name'             => (string) $product->name,
			'long_description' => (string) ( $product->long_description ?? '' ),
			'unit'             => (string) ( $product->unit ?? '' ),
			'group_id'         => null !== $product->group_id ? (int) $product->group_id : null,
			'group_name'       => $group ? (string) $group->name : '',
			'rate'             => (float) $product->rate,
			'tax'              => is_array( $tax ) ? array_values( $tax ) : array(),
			'created_at'       => (string) ( $product->created_at ?? '' ),
			'updated_at'       => (string) ( $product->updated_at ?? '' ),
		);
	}

	/**
	 * Fully-qualified product groups table name for raw ordering.
	 *
	 * @return string
	 */
	private function groups_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'doublescale_product_groups';
	}

	/**
	 * Escape LIKE wildcards in a search term.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function escape_like( string $value ): string {
		global $wpdb;

		return $wpdb->esc_like( $value );
	}
}
