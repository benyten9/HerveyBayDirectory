<?php
/**
 * REST controller for help-center categories (admin side).
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET    /kb/categories        Every category, ordered, with article counts.
 *   POST   /kb/categories        Create a category.
 *   GET    /kb/categories/{id}   Read one.
 *   PUT    /kb/categories/{id}   Partial update.
 *   DELETE /kb/categories/{id}   Delete; its articles become uncategorised.
 *
 * Categories are few, so the list is not paginated.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbCategoryController class.
 */
class RestKbCategoryController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'kb/categories';

	/**
	 * Register routes.
	 *
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
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
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
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Permissions
	// ---------------------------------------------------------------------

	public function get_items_permissions_check( $request ) {
		return KbAccess::can_read();
	}

	public function get_item_permissions_check( $request ) {
		return KbAccess::can_read();
	}

	public function create_item_permissions_check( $request ) {
		return KbAccess::can_manage();
	}

	public function update_item_permissions_check( $request ) {
		return KbAccess::can_manage();
	}

	public function delete_item_permissions_check( $request ) {
		return KbAccess::can_manage();
	}

	// ---------------------------------------------------------------------
	// Endpoints
	// ---------------------------------------------------------------------

	/**
	 * Every category with its article count.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$categories = KbCategoryModel::query()
			->withCount( 'articles' )
			->orderBy( 'position', 'asc' )
			->orderBy( 'name', 'asc' )
			->get();

		$data = array();
		foreach ( $categories as $category ) {
			$data[] = self::shape( $category );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'uncategorised_count' => KbArticleModel::query()->whereNull( 'category_id' )->count(),
				),
			),
			200
		);
	}

	/**
	 * Read one category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$category = KbCategoryModel::withCount( 'articles' )->find( (int) $request->get_param( 'id' ) );
		if ( ! $category ) {
			return self::not_found();
		}

		return new WP_REST_Response( self::shape( $category ), 200 );
	}

	/**
	 * Create a category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$category = new KbCategoryModel();
		$saved    = $this->fill_and_save( $category, self::body( $request ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$category->articles_count = 0;

		return new WP_REST_Response( self::shape( $category ), 201 );
	}

	/**
	 * Update a category.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$category = KbCategoryModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $category ) {
			return self::not_found();
		}

		$saved = $this->fill_and_save( $category, self::body( $request ), false );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$category->articles_count = $category->articles()->count();

		return new WP_REST_Response( self::shape( $category ), 200 );
	}

	/**
	 * Delete a category. Its articles stay, uncategorised.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$category = KbCategoryModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $category ) {
			return self::not_found();
		}

		$id = (int) $category->id;

		try {
			$moved = KbArticleModel::query()->where( 'category_id', $id )->update( array( 'category_id' => null ) );
			KbCategoryModel::query()->where( 'parent_id', $id )->update( array( 'parent_id' => null ) );
			$category->delete();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kb_category_delete_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'deleted'        => true,
				'id'             => $id,
				'articles_moved' => (int) $moved,
			),
			200
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Validate, copy and save the writable fields.
	 *
	 * @param KbCategoryModel      $category Target.
	 * @param array<string, mixed> $data     Request body.
	 * @param bool                 $creating Name is required on create.
	 * @return true|WP_Error
	 */
	private function fill_and_save( KbCategoryModel $category, array $data, bool $creating ) {
		if ( $creating || array_key_exists( 'name', $data ) ) {
			$name = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
			if ( '' === $name ) {
				return new WP_Error( 'kb_invalid_name', __( 'The category needs a name.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$category->name = mb_substr( $name, 0, 191, 'UTF-8' );
		}

		if ( array_key_exists( 'description', $data ) ) {
			$category->description = trim( sanitize_textarea_field( (string) $data['description'] ) );
		}

		if ( array_key_exists( 'position', $data ) ) {
			$category->position = (int) $data['position'];
		}

		if ( array_key_exists( 'icon', $data ) ) {
			$icon           = sanitize_key( (string) $data['icon'] );
			$category->icon = '' !== $icon ? $icon : null;
		}

		if ( array_key_exists( 'parent_id', $data ) ) {
			$parent_id = (int) $data['parent_id'];
			if ( $parent_id > 0 && ( $parent_id === (int) $category->id || ! KbCategoryModel::find( $parent_id ) ) ) {
				return new WP_Error( 'kb_invalid_parent_id', __( 'That parent category is not valid.', 'doublescale' ), array( 'status' => 400 ) );
			}
			$category->parent_id = $parent_id > 0 ? $parent_id : null;
		}

		if ( array_key_exists( 'slug', $data ) && '' !== trim( (string) $data['slug'] ) ) {
			$category->slug = $this->unique_slug( (string) $data['slug'], (int) $category->id );
		} elseif ( empty( $category->slug ) ) {
			$category->slug = $this->unique_slug( (string) $category->name, (int) $category->id );
		}

		try {
			$category->save();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'kb_category_save_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		if ( empty( $category->id ) ) {
			return new WP_Error( 'kb_category_save_failed', __( 'The category could not be saved.', 'doublescale' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * A slug no other category uses.
	 *
	 * @param string $source     Name or requested slug.
	 * @param int    $exclude_id Category being saved.
	 * @return string
	 */
	private function unique_slug( string $source, int $exclude_id ): string {
		// sanitize_title() percent-encodes non-Latin text (six characters per
		// Arabic letter), so shorten the SOURCE a letter at a time: cutting the
		// encoded slug could split a letter and make the link unreachable.
		$base = sanitize_title( $source );
		while ( strlen( $base ) > 180 && '' !== $source ) {
			$source = mb_substr( $source, 0, -1, 'UTF-8' );
			$base   = sanitize_title( $source );
		}
		$base = rtrim( $base, '-' );
		if ( '' === $base ) {
			$base = 'category';
		}

		$candidate = $base;
		$suffix    = 2;
		while ( KbCategoryModel::query()->where( 'slug', $candidate )->where( 'id', '!=', $exclude_id )->exists() ) {
			$candidate = $base . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * JSON body, falling back to form params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : (array) $request->get_body_params();
	}

	/**
	 * Admin payload for one category.
	 *
	 * @param KbCategoryModel $category Category.
	 * @return array<string, mixed>
	 */
	public static function shape( KbCategoryModel $category ): array {
		return array(
			'id'             => (int) $category->id,
			'name'           => (string) $category->name,
			'slug'           => (string) $category->slug,
			'description'    => (string) $category->description,
			'parent_id'      => $category->parent_id ? (int) $category->parent_id : null,
			'position'       => (int) $category->position,
			'icon'           => $category->icon ? (string) $category->icon : null,
			'articles_count' => (int) $category->articles_count,
		);
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'not_found', __( 'Category not found.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
