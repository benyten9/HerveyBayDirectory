<?php

/**
 * REST Api: Taxonomy Controller Base
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Api
 */

namespace DoubleScale\Core\Abstracts;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\UserRoles\Permissions;

/**
 * Abstract Taxonomy Controller class
 *
 * Base class for Tag, List, and other taxonomy-like controllers
 */
abstract class RestTaxonomyController extends RestController {

	/**
	 * Columns lists and tags may be sorted by.
	 *
	 * Both live in the shared terms table; a child can narrow or extend this.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SORTABLE_COLUMNS = array( 'name', 'slug', 'status', 'created_at', 'updated_at' );

	/**
	 * REST Base - must be defined in child class
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base;

	/**
	 * Model class name - must be defined in child class
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $model_class;

	/**
	 * Singular name for error messages
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $singular_name;

	/**
	 * Plural name for error messages
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $plural_name;

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Collection endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => array(
						'ids' => array(
							/* translators: %s: Singular taxonomy name (e.g. Tag, Category). */
							'description' => sprintf( __( '%s IDs.', 'doublescale' ), $this->singular_name ),
							'type'        => 'array',
							'required'    => true,
						),
					),
				),
			)
		);

		// Single item endpoints
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Get collection parameters
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'keyword'    => array(
				'description' => __( 'Keyword to search.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'text-field',
			),
			'per_page'   => array(
				'description' => __( 'Number of items to fetch.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 10,
			),
			'page'       => array(
				'description' => __( 'Page number.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
			'ids'        => array(
				/* translators: %s: Plural taxonomy name in lowercase. */
				'description' => sprintf( __( 'IDs of %s to fetch.', 'doublescale' ), strtolower( $this->plural_name ) ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
			),
			'contact_id' => array(
				'description' => __( 'Contact ID.', 'doublescale' ),
				'type'        => 'integer',
			),
			'from'       => array(
				'description' => __( 'Start date for filtering (created_at >= from). Format: YYYY-MM-DD.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'to'         => array(
				'description' => __( 'End date for filtering (created_at <= to). Format: YYYY-MM-DD.', 'doublescale' ),
				'type'        => 'string',
				'format'      => 'date',
			),
			'campaign_type' => array(
				'description' => __( 'When set, each item includes eligible_contacts_count for that campaign channel.', 'doublescale' ),
				'type'        => 'string',
			),
		) + $this->get_sorting_collection_params( static::SORTABLE_COLUMNS );
	}

	/**
	 * Schema for the taxonomy item
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => strtolower( $this->singular_name ),
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'        => array(
					/* translators: %s: Singular taxonomy name in lowercase. */
					'description' => sprintf( __( 'Name of the %s.', 'doublescale' ), strtolower( $this->singular_name ) ),
					'type'        => 'string',
					'required'    => true,
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'slug'        => array(
					/* translators: %s: Singular taxonomy name in lowercase. */
					'description' => sprintf( __( 'An alphanumeric identifier for the %s.', 'doublescale' ), strtolower( $this->singular_name ) ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_title',
					),
				),
				'description' => array(
					/* translators: %s: Singular taxonomy name in lowercase. */
					'description' => sprintf( __( 'Description of the %s.', 'doublescale' ), strtolower( $this->singular_name ) ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'status'      => array(
					/* translators: %s: Singular taxonomy name in lowercase. */
					'description' => sprintf( __( 'Status of the %s.', 'doublescale' ), strtolower( $this->singular_name ) ),
					'type'        => 'string',
					'enum'        => array( 'active', 'inactive' ),
					'default'     => 'active',
				),
				'created_at'  => array(
					'type'        => 'string',
					'description' => __( 'Created at', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'updated_at'  => array(
					'type'        => 'string',
					'description' => __( 'Updated at', 'doublescale' ),
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Get a collection of items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		try {
			$per_page   = $request->get_param( 'per_page' ) ?: 10;
			$page       = $request->get_param( 'page' ) ?: 1;
			$keyword    = $request->get_param( 'keyword' ) ?? '';
			$ids        = $request->get_param( 'ids' ) ?? array();
			$contact_id = $request->get_param( 'contact_id' ) ?? '';
			$from       = $request->get_param( 'from' ) ?? null;
			$to         = $request->get_param( 'to' ) ?? null;
			$campaign_type = $request->get_param( 'campaign_type' ) ?? null;

			$model_class = $this->model_class;
			$query       = $model_class::query();
			// Total rows for this taxonomy type (unfiltered) — used by admin UI `total_count`.
			$total_count = (int) $model_class::query()->count();

			// Handle specific IDs query
			if ( ! empty( $ids ) ) {
				$items = $query->whereIn( 'id', $ids )
					->orderBy( 'created_at', 'desc' )
					->paginate( $per_page, array( '*' ), 'page', $page );

				foreach ( $items->items() as $item ) {
					$this->attach_contacts_counts( $item, $campaign_type );
				}

				return new WP_REST_Response(
					$items->toArray() + array( 'total_count' => $total_count ),
					200
				);
			}

			// Apply keyword search
			if ( '' !== $keyword ) {
				$query->where(
					function ( $q ) use ( $keyword ) {
						$q->where( 'name', 'LIKE', '%' . $keyword . '%' )
							->orWhere( 'description', 'LIKE', '%' . $keyword . '%' );
					}
				);
			}

			// Filter by contact (exclude items already assigned to contact)
			if ( '' !== $contact_id ) {
				$query->whereDoesntHave(
					'contacts',
					function ( $q ) use ( $contact_id ) {
						$q->where( 'contact_id', $contact_id );
					}
				);
			}

			// Apply date range filters
			if ( $from ) {
				$query->where( 'created_at', '>=', $from );
			}
			if ( $to ) {
				$query->where( 'created_at', '<=', $to );
			}

			// Execute query with pagination
			$this->apply_sorting( $query, $request, static::SORTABLE_COLUMNS );

			$items = $query->paginate( $per_page, array( '*' ), 'page', $page );

			// Ensure contacts_count is calculated for each item
			foreach ( $items->items() as $item ) {
				$this->attach_contacts_counts( $item, $campaign_type );
			}

			return new WP_REST_Response(
				$items->toArray() + array( 'total_count' => $total_count ),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_read',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Create one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		global $wpdb;

		try {
			$item_data   = $this->prepare_item_for_database( $request );
			$model_class = $this->model_class;

			// Unified terms table requires `type`; set explicitly so inserts succeed even if
			// eloquent `creating` listeners do not run (e.g. TaxonomyModel::save() re-registration path).
			if ( is_subclass_of( $model_class, TaxonomyModel::class, true ) ) {
				$item_data['type'] = $model_class::type_value();
			}

			if ( property_exists( $wpdb, 'last_error' ) ) {
				$wpdb->last_error = '';
			}

			$item = $model_class::create( $item_data );

			$item_id    = (int) ( $item->id ?? 0 );
			$db_error   = property_exists( $wpdb, 'last_error' ) ? (string) $wpdb->last_error : '';
			$insert_ok  = $item_id > 0 && '' === $db_error;

			if ( ! $insert_ok ) {
				$this->log_taxonomy_create_failure(
					$model_class,
					$item_data,
					$item_id,
					$db_error
				);

				return new WP_Error(
					'rest_' . $this->rest_base . '_cannot_create',
					/* translators: %s: Singular taxonomy name. */
					sprintf( __( 'Failed to create %s. Please try again or contact support.', 'doublescale' ), strtolower( $this->singular_name ) ),
					array( 'status' => 500 )
				);
			}

			return new WP_REST_Response( $item, 201 );
		} catch ( \Exception $e ) {
			$this->log_taxonomy_create_failure(
				$this->model_class,
				isset( $item_data ) ? $item_data : array(),
				0,
				$e->getMessage()
			);

			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_create',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Log diagnostic context when taxonomy create returns id=0 or the DB reports an error.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $model_class Model class name.
	 * @param array<string, mixed> $item_data   Payload sent to create().
	 * @param int                  $item_id     Inserted ID (0 when failed).
	 * @param string               $db_error    wpdb or exception message.
	 *
	 * @return void
	 */
	protected function log_taxonomy_create_failure( $model_class, array $item_data, $item_id, $db_error ) {
		if ( ! function_exists( 'doublescale_get_logger' ) ) {
			return;
		}

		global $wpdb;

		$table   = $wpdb->prefix . 'doublescale_terms';
		$columns = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		if ( is_array( $column_rows ) ) {
			foreach ( $column_rows as $column ) {
				if ( isset( $column['Field'] ) ) {
					$columns[] = array(
						'field' => $column['Field'],
						'type'  => $column['Type'] ?? '',
						'extra' => $column['Extra'] ?? '',
					);
				}
			}
		}

		$type = is_subclass_of( $model_class, TaxonomyModel::class, true )
			? $model_class::type_value()
			: '';

		doublescale_get_logger()->error(
			'Taxonomy create failed (id=0 or DB error).',
			array(
				'source'    => 'rest-taxonomy-controller',
				'rest_base' => $this->rest_base,
				'type'      => $type,
				'model'     => $model_class,
				'payload'   => $item_data,
				'item_id'   => $item_id,
				'db_error'  => $db_error,
				'columns'   => $columns,
			)
		);
	}

	/**
	 * Get one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		try {
			$model_class = $this->model_class;
			$item        = $model_class::find( $request->get_param( 'id' ) );

			if ( ! $item ) {
				return new WP_Error(
					'rest_' . $this->rest_base . '_not_found',
					/* translators: %s: Singular taxonomy name. */
					sprintf( __( '%s not found.', 'doublescale' ), $this->singular_name ),
					array( 'status' => 404 )
				);
			}

			// Ensure contacts_count is calculated
			$campaign_type = $request->get_param( 'campaign_type' ) ?? null;
			$this->attach_contacts_counts( $item, $campaign_type );

			return new WP_REST_Response( $item, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_read',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Update one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		try {
			$item_id     = $request->get_param( 'id' );
			$model_class = $this->model_class;
			$item        = $model_class::find( $item_id );

			if ( ! $item ) {
				return new WP_Error(
					'rest_' . $this->rest_base . '_not_found',
					/* translators: %s: Singular taxonomy name. */
					sprintf( __( '%s not found.', 'doublescale' ), $this->singular_name ),
					array( 'status' => 404 )
				);
			}

			$item_data = $this->prepare_item_for_database( $request );
			$item->update( $item_data );

			return new WP_REST_Response( $item, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_update',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete one item from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		try {
			$item_id     = $request->get_param( 'id' );
			$model_class = $this->model_class;
			$item        = $model_class::find( $item_id );

			if ( ! $item ) {
				return new WP_Error(
					'rest_' . $this->rest_base . '_not_found',
					/* translators: %s: Singular taxonomy name. */
					sprintf( __( '%s not found.', 'doublescale' ), $this->singular_name ),
					array( 'status' => 404 )
				);
			}

			$item->delete();

			return new WP_REST_Response( $item, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_delete',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Delete multiple items from the collection
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_items( $request ) {
		try {
			$ids = $request->get_param( 'ids' );

			if ( empty( $ids ) ) {
				return new WP_Error(
					'rest_' . $this->rest_base . '_missing_ids',
					/* translators: %s: Plural taxonomy name in lowercase. */
					sprintf( __( 'No %s IDs provided.', 'doublescale' ), strtolower( $this->plural_name ) ),
					array( 'status' => 400 )
				);
			}

			$model_class = $this->model_class;
			$items       = $model_class::whereIn( 'id', $ids )->get();

			if ( $items->isEmpty() ) {
				return new WP_Error(
					'rest_' . $this->rest_base . '_not_found',
					/* translators: %s: Plural taxonomy name. */
					sprintf( __( '%s not found.', 'doublescale' ), $this->plural_name ),
					array( 'status' => 404 )
				);
			}

			$model_class::whereIn( 'id', $ids )->delete();

			return new WP_REST_Response( $items, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'rest_' . $this->rest_base . '_cannot_delete',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Prepare item data for database
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return array Item data ready for database insertion/update.
	 */
	protected function prepare_item_for_database( $request ) {
		$item = array(
			'name'        => $request->get_param( 'name' ),
			'slug'        => $request->get_param( 'slug' ),
			'description' => $request->get_param( 'description' ),
			'status'      => $request->get_param( 'status' ),
		);

		// Remove empty values
		return array_filter(
			$item,
			function ( $value ) {
				return ! is_null( $value ) && '' !== $value;
			}
		);
	}

	/**
	 * Check if a given request has access to get items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::can_read_taxonomy_terms();
	}

	/**
	 * Check if a given request has access to create items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to get a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::can_read_taxonomy_terms();
	}

	/**
	 * Check if a given request has access to update a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete a specific item
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Check if a given request has access to delete multiple items
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 *
	 * @return bool Permission check result.
	 */
	public function delete_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Attach contacts_count and optional eligible_contacts_count to a taxonomy item.
	 *
	 * @since 1.0.0
	 *
	 * @param object      $item Taxonomy model instance.
	 * @param string|null $campaign_type Campaign channel when eligible count is needed.
	 * @return void
	 */
	protected function attach_contacts_counts( $item, $campaign_type = null ) {
		if ( ! isset( $item->contacts_count ) || is_null( $item->contacts_count ) ) {
			$item->contacts_count = $item->contacts()->distinct()->count();
		}

		if ( $campaign_type && method_exists( $item, 'get_eligible_contacts_count' ) ) {
			$item->eligible_contacts_count = $item->get_eligible_contacts_count( $campaign_type );
		}
	}
}
