<?php
/**
 * Arranging the help center from the Articles screen (drag and drop):
 *
 *   POST kb/categories/order  { items: [{ id, parent_id }] }
 *        Every category, in the order shown. Two levels: a parent must be a
 *        top-level category, and a category with sub-categories stays on top.
 *        Positions are written as one sequence in tree order, so the public
 *        help center (which lists categories by position) matches the tree.
 *
 *   POST kb/articles/order    { category_id, ids: [] }
 *        The articles of one category (0/null = uncategorised), in order.
 *        Articles dragged in from another category move into it.
 *
 *   POST kb/articles/bulk     { ids: [], category_id?, status? }
 *        Move and/or publish/unpublish several articles at once.
 *
 * Writes go through ArticleService, so a moved or published article's page,
 * the assistant's index and its answer cache follow as on a normal save.
 * Only rows that actually change are saved.
 *
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleService;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbOrderController class.
 */
class RestKbOrderController extends RestController {

	/**
	 * Most articles one request may reorder or change.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/kb/categories/order',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'order_categories' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/kb/articles/order',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'order_articles' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/kb/articles/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_articles' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Arranging is writing: the helpdesk settings tier only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function manage_permissions_check( $request ) {
		return KbAccess::can_manage();
	}

	/**
	 * POST kb/categories/order
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function order_categories( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) || array() === $items ) {
			return self::invalid( __( 'Send every category in the new order.', 'doublescale' ) );
		}

		$existing = array();
		foreach ( KbCategoryModel::query()->get() as $category ) {
			$existing[ (int) $category->id ] = $category;
		}

		$plan = array();
		foreach ( array_values( $items ) as $item ) {
			$id     = (int) ( is_array( $item ) ? ( $item['id'] ?? 0 ) : 0 );
			$parent = (int) ( is_array( $item ) ? ( $item['parent_id'] ?? 0 ) : 0 );
			if ( ! isset( $existing[ $id ] ) || array_key_exists( $id, $plan ) ) {
				return self::invalid( __( 'The list has a category that does not exist or appears twice. Reload the page and try again.', 'doublescale' ) );
			}
			$plan[ $id ] = $parent > 0 ? $parent : null;
		}
		if ( count( $plan ) !== count( $existing ) ) {
			return self::invalid( __( 'Some categories are missing from the list. Reload the page and try again.', 'doublescale' ) );
		}

		foreach ( $plan as $id => $parent ) {
			if ( null === $parent ) {
				continue;
			}
			if ( $parent === $id || ! array_key_exists( $parent, $plan ) ) {
				return self::invalid( __( 'A category can only sit inside another category from the list.', 'doublescale' ) );
			}
			if ( null !== $plan[ $parent ] ) {
				return self::invalid( __( 'Sub-categories go one level deep: a sub-category cannot hold another one.', 'doublescale' ) );
			}
		}

		// One sequence in tree order: each top-level category, then its children.
		$ordered = array();
		foreach ( $plan as $id => $parent ) {
			if ( null !== $parent ) {
				continue;
			}
			$ordered[] = $id;
			foreach ( $plan as $child => $child_parent ) {
				if ( $child_parent === $id ) {
					$ordered[] = $child;
				}
			}
		}

		foreach ( $ordered as $position => $id ) {
			$category = $existing[ $id ];
			$parent   = $plan[ $id ];
			if ( (int) $category->position === $position && ( $category->parent_id ? (int) $category->parent_id : null ) === $parent ) {
				continue;
			}
			$category->position  = $position;
			$category->parent_id = $parent;
			$category->save();
		}

		$out = array();
		foreach ( KbCategoryModel::query()->withCount( 'articles' )->orderBy( 'position', 'asc' )->orderBy( 'name', 'asc' )->get() as $category ) {
			$out[] = RestKbCategoryController::shape( $category );
		}

		return new WP_REST_Response( array( 'data' => $out ), 200 );
	}

	/**
	 * POST kb/articles/order
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function order_articles( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$category_id = self::category_param( $request->get_param( 'category_id' ) );
		if ( is_wp_error( $category_id ) ) {
			return $category_id;
		}

		$ids = self::ids_param( $request->get_param( 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$articles = self::articles_by_id( $ids );
		if ( is_wp_error( $articles ) ) {
			return $articles;
		}

		$service = new ArticleService();
		foreach ( $ids as $position => $id ) {
			$article = $articles[ $id ];
			$changes = array();
			if ( (int) $article->position !== $position ) {
				$changes['position'] = $position;
			}
			$current = $article->category_id ? (int) $article->category_id : null;
			if ( $current !== $category_id ) {
				$changes['category_id'] = $category_id;
			}
			if ( array() === $changes ) {
				continue;
			}
			$saved = $service->update( $article, $changes );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return new WP_REST_Response(
			array(
				'category_id' => $category_id,
				'ids'         => $ids,
			),
			200
		);
	}

	/**
	 * POST kb/articles/bulk
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bulk_articles( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$ids = self::ids_param( $request->get_param( 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$changes = array();
		if ( $request->has_param( 'category_id' ) ) {
			$category_id = self::category_param( $request->get_param( 'category_id' ) );
			if ( is_wp_error( $category_id ) ) {
				return $category_id;
			}
			$changes['category_id'] = $category_id;
		}
		if ( $request->has_param( 'status' ) ) {
			$status = (string) $request->get_param( 'status' );
			if ( ! in_array( $status, KbArticleModel::statuses(), true ) ) {
				return self::invalid( __( 'That status is not valid.', 'doublescale' ) );
			}
			$changes['status'] = $status;
		}
		if ( array() === $changes ) {
			return self::invalid( __( 'Choose a category or a status to apply.', 'doublescale' ) );
		}

		$articles = self::articles_by_id( $ids );
		if ( is_wp_error( $articles ) ) {
			return $articles;
		}

		// A moved article goes to the end of its new category.
		$next_position = null;
		if ( array_key_exists( 'category_id', $changes ) ) {
			$query = KbArticleModel::query();
			if ( null === $changes['category_id'] ) {
				$query->whereNull( 'category_id' );
			} else {
				$query->where( 'category_id', $changes['category_id'] );
			}
			$next_position = (int) $query->max( 'position' ) + 1;
		}

		$service = new ArticleService();
		$updated = array();
		foreach ( $ids as $id ) {
			$article = $articles[ $id ];
			$apply   = array();
			if ( array_key_exists( 'status', $changes ) && $article->status !== $changes['status'] ) {
				$apply['status'] = $changes['status'];
			}
			$current = $article->category_id ? (int) $article->category_id : null;
			if ( array_key_exists( 'category_id', $changes ) && $current !== $changes['category_id'] ) {
				$apply['category_id'] = $changes['category_id'];
				$apply['position']    = $next_position++;
			}
			if ( array() === $apply ) {
				continue;
			}
			$saved = $service->update( $article, $apply );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$updated[] = $id;
		}

		return new WP_REST_Response( array( 'updated' => $updated ), 200 );
	}

	/**
	 * Category id from a request: null for uncategorised (0, null or missing).
	 *
	 * @param mixed $raw Raw value.
	 * @return int|null|WP_Error
	 */
	private static function category_param( $raw ) {
		$id = (int) $raw;
		if ( $id <= 0 ) {
			return null;
		}
		if ( ! KbCategoryModel::find( $id ) ) {
			return self::invalid( __( 'That category does not exist any more. Reload the page and try again.', 'doublescale' ) );
		}
		return $id;
	}

	/**
	 * A non-empty list of distinct article ids.
	 *
	 * @param mixed $raw Raw value.
	 * @return int[]|WP_Error
	 */
	private static function ids_param( $raw ) {
		if ( ! is_array( $raw ) || array() === $raw ) {
			return self::invalid( __( 'Choose at least one article.', 'doublescale' ) );
		}
		if ( count( $raw ) > self::MAX_ITEMS ) {
			/* translators: %d: most articles at once */
			return self::invalid( sprintf( __( 'Change at most %d articles at a time.', 'doublescale' ), self::MAX_ITEMS ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $raw ) ) ) );
		if ( count( $ids ) !== count( $raw ) ) {
			return self::invalid( __( 'The list has an article that appears twice or is not valid.', 'doublescale' ) );
		}
		return $ids;
	}

	/**
	 * Articles keyed by id; every id must exist.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int, KbArticleModel>|WP_Error
	 */
	private static function articles_by_id( array $ids ) {
		$found = array();
		foreach ( KbArticleModel::query()->whereIn( 'id', $ids )->get() as $article ) {
			$found[ (int) $article->id ] = $article;
		}
		if ( count( $found ) !== count( $ids ) ) {
			return self::invalid( __( 'Some of these articles were deleted. Reload the page and try again.', 'doublescale' ) );
		}
		return $found;
	}

	/**
	 * A 400 with a message the screen can show.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'kb_invalid_order', $message, array( 'status' => 400 ) );
	}
}
