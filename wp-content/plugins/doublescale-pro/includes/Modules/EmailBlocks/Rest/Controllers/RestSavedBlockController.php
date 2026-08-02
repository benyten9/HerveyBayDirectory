<?php
/**
 * REST API controller for saved email blocks.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Modules\EmailBlocks
 */

namespace DoubleScale\Pro\Modules\EmailBlocks\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Pro\Modules\EmailBlocks\Models\SavedBlockModel;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSavedBlockController class
 */
class RestSavedBlockController extends RestController {

	/**
	 * REST Base
	 *
	 * @var string
	 */
	protected $rest_base = 'saved-blocks';

	/**
	 * Valid block categories.
	 *
	 * @var string[]
	 */
	private const VALID_CATEGORIES = array(
		'header',
		'footer',
		'hero',
		'cta',
		'gallery',
		'custom',
	);

	/**
	 * Register the routes for the objects of the controller.
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
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

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
	 * Schema for saved blocks.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'saved_block',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'description' => __( 'Unique identifier for the object.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'       => array(
					'description' => __( 'Name of the saved block.', 'doublescale' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
				'category'   => array(
					'description' => __( 'Category of the saved block.', 'doublescale' ),
					'type'        => 'string',
					'enum'        => self::VALID_CATEGORIES,
				),
				'content'    => array(
					'description' => __( 'Versioned section content envelope.', 'doublescale' ),
					'type'        => 'object',
				),
				'thumbnail'  => array(
					'description' => __( 'Thumbnail URL of the saved block.', 'doublescale' ),
					'type'        => 'string',
					'arg_options' => array(
						'sanitize_callback' => 'esc_url_raw',
					),
				),
				'created_by' => array(
					'description' => __( 'User ID of the creator.', 'doublescale' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'created_at' => array(
					'description' => __( 'Creation time.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'updated_at' => array(
					'description' => __( 'Last update time.', 'doublescale' ),
					'type'        => 'string',
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Collection params.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'category' => array(
				'description'       => __( 'Filter by block category.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'   => array(
				'description'       => __( 'Search blocks by name.', 'doublescale' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'description' => __( 'Number of items per page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 50,
			),
			'page'     => array(
				'description' => __( 'Current page.', 'doublescale' ),
				'type'        => 'integer',
				'default'     => 1,
			),
		);
	}

	/**
	 * Get items
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		try {
			$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
			$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
			$search   = $request->get_param( 'search' );
			$category = $request->get_param( 'category' );

			$query = SavedBlockModel::where( 'created_by', get_current_user_id() );

			if ( $category ) {
				$query->where( 'category', $category );
			}

			if ( $search ) {
				$query->where( 'name', 'LIKE', '%' . $search . '%' );
			}

			$total  = $query->count();
			$blocks = $query->orderBy( 'updated_at', 'DESC' )
				->offset( ( $page - 1 ) * $per_page )
				->limit( $per_page )
				->get();

			return new WP_REST_Response(
				array(
					'blocks'   => $blocks,
					'total'    => (int) $total,
					'pages'    => (int) ceil( $total / $per_page ),
					'page'     => $page,
					'per_page' => $per_page,
				),
				200
			);
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Get item
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		try {
			$block = $this->find_owned_block( (int) $request->get_param( 'id' ) );

			if ( is_wp_error( $block ) ) {
				return $block;
			}

			return new WP_REST_Response( $block, 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Create item
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		try {
			$data = $this->prepare_block( $request );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$data['created_by'] = get_current_user_id();
			$block              = SavedBlockModel::create( $data );

			return new WP_REST_Response( $block, 201 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Update item
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		try {
			$block = $this->find_owned_block( (int) $request->get_param( 'id' ) );

			if ( is_wp_error( $block ) ) {
				return $block;
			}

			$data = $this->prepare_block( $request );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			$block->update( $data );

			return new WP_REST_Response( $block->fresh(), 200 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Delete item
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		try {
			$block = $this->find_owned_block( (int) $request->get_param( 'id' ) );

			if ( is_wp_error( $block ) ) {
				return $block;
			}

			$block->delete();

			return new WP_REST_Response( null, 204 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'error', $e->getMessage(), array( 'status' => 500 ) );
		}
	}

	/**
	 * Prepare block data from request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function prepare_block( $request ) {
		$name      = $request->get_param( 'name' );
		$category  = $request->get_param( 'category' );
		$content   = $request->get_param( 'content' );
		$thumbnail = $request->get_param( 'thumbnail' );

		$data = array();

		if ( null !== $name ) {
			$data['name'] = $name ?: __( 'Untitled Block', 'doublescale' );
		}

		if ( null !== $category ) {
			if ( ! in_array( $category, self::VALID_CATEGORIES, true ) ) {
				return new WP_Error(
					'invalid_category',
					__( 'Invalid block category.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			$data['category'] = $category;
		}

		if ( null !== $content ) {
			if ( ! is_array( $content ) || ! isset( $content['version'], $content['section'] ) ) {
				return new WP_Error(
					'invalid_content',
					__( 'Block content must be a versioned section envelope.', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			$data['content'] = $content;
		}

		if ( null !== $thumbnail ) {
			$data['thumbnail'] = $thumbnail;
		}

		return $data;
	}

	/**
	 * Find a block owned by the current user.
	 *
	 * @param int $id Block ID.
	 * @return SavedBlockModel|WP_Error
	 */
	private function find_owned_block( $id ) {
		$block = SavedBlockModel::where( 'id', $id )
			->where( 'created_by', get_current_user_id() )
			->first();

		if ( ! $block ) {
			return new WP_Error(
				'not_found',
				__( 'Saved block not found', 'doublescale' ),
				array( 'status' => 404 )
			);
		}

		return $block;
	}

	/**
	 * Require Pro add-on for write operations.
	 *
	 * @return true|WP_Error
	 */
	private function require_pro() {
		if ( ! function_exists( 'doublescale_is_pro_addon_active' ) || ! doublescale_is_pro_addon_active() ) {
			return new WP_Error(
				'pro_required',
				__( 'This feature requires DoubleScale Pro.', 'doublescale' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function get_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function create_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function delete_item_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}
}
