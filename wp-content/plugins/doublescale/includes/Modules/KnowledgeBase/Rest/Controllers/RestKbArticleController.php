<?php
/**
 * REST controller for help-center articles (admin side).
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET    /kb/articles        List articles (paginated, searchable, filterable).
 *   POST   /kb/articles        Create an article.
 *   GET    /kb/articles/{id}   Read one, body included.
 *   PUT    /kb/articles/{id}   Partial update.
 *   DELETE /kb/articles/{id}   Delete.
 *
 * Staff only ({@see KbAccess}). Every article is visible here, drafts and
 * internal ones included; public and portal readers use their own routes,
 * which filter by status and visibility. Writers (Help Center Agents) create
 * articles and change only the ones they wrote; managers change any.
 *
 * Writes go through {@see ArticleService} so the search text, hash and change
 * hooks stay in step with the body.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleSearch;
use DoubleScale\Modules\KnowledgeBase\Services\ArticlePages;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleService;
use DoubleScale\Modules\KnowledgeBase\Services\KbAccess;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbArticleController class.
 */
class RestKbArticleController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'kb/articles';

	/**
	 * Columns the list may be sorted by.
	 *
	 * @var string[]
	 */
	private const SORTABLE = array( 'updated_at', 'created_at', 'title', 'position', 'views', 'published_at' );

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
					'args'                => $this->get_collection_params(),
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

		// Forget this user's backup of an article's unsaved changes (WordPress's
		// autosave of its page): "Dismiss" on the editor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/backup',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
				),
			)
		);
	}

	/**
	 * List filters.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'search'      => array(
				'description' => __( 'Match on title or article text.', 'doublescale' ),
				'type'        => 'string',
			),
			'status'      => array(
				'type' => 'string',
				'enum' => KbArticleModel::statuses(),
			),
			'visibility'  => array(
				'type' => 'string',
				'enum' => KbArticleModel::visibilities(),
			),
			'category_id' => array(
				'description' => __( 'Category id. 0 lists uncategorised articles.', 'doublescale' ),
				'type'        => 'integer',
				'minimum'     => 0,
			),
			'orderby'     => array(
				'type' => 'string',
				'enum' => self::SORTABLE,
			),
			'order'       => array(
				'type' => 'string',
				'enum' => array( 'asc', 'desc' ),
			),
			'per_page'    => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'page'        => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
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
		return KbAccess::can_write();
	}

	/**
	 * DELETE kb/articles/<id>/backup
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( $request ) {
		$article = KbArticleModel::find( (int) $request['id'] );
		if ( ! $article ) {
			return new WP_Error( 'not_found', __( 'Article not found.', 'doublescale' ), array( 'status' => 404 ) );
		}
		$page_id  = (int) ( $article->post_id ?? 0 );
		$autosave = $page_id ? wp_get_post_autosave( $page_id, get_current_user_id() ) : false;
		if ( $autosave ) {
			wp_delete_post_revision( $autosave->ID );
		}

		return new WP_REST_Response( array( 'deleted' => (bool) $autosave ), 200 );
	}

	/**
	 * Managers change any article; writers only the ones they wrote. A
	 * missing article passes here so the handler can answer 404.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return self::article_permission( $request );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return self::article_permission( $request );
	}

	/**
	 * Whether the current user may change the article in the route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	private static function article_permission( $request ) {
		if ( KbAccess::can_manage() ) {
			return true;
		}
		if ( ! KbAccess::can_write() ) {
			return false;
		}

		$article = KbArticleModel::find( (int) $request['id'] );
		if ( ! $article || KbAccess::can_edit( $article ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Only a Knowledge Base Manager can change an article someone else wrote.', 'doublescale' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	// ---------------------------------------------------------------------
	// Endpoints
	// ---------------------------------------------------------------------

	/**
	 * List articles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$query = KbArticleModel::query()->with( 'category' );

		$status = $request->get_param( 'status' );
		if ( is_string( $status ) && in_array( $status, KbArticleModel::statuses(), true ) ) {
			$query->where( 'status', $status );
		}

		$visibility = $request->get_param( 'visibility' );
		if ( is_string( $visibility ) && in_array( $visibility, KbArticleModel::visibilities(), true ) ) {
			$query->where( 'visibility', $visibility );
		}

		if ( $request->has_param( 'category_id' ) && null !== $request->get_param( 'category_id' ) ) {
			$category_id = (int) $request->get_param( 'category_id' );
			if ( 0 === $category_id ) {
				$query->whereNull( 'category_id' );
			} else {
				$query->where( 'category_id', $category_id );
			}
		}

		ArticleSearch::apply( $query, (string) $request->get_param( 'search' ) );

		$this->apply_sorting( $query, $request, self::SORTABLE, 'updated_at', 'desc' );

		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

		$paginator = $query->paginate( $per_page, array( '*' ), 'page', $page );

		$data = array();
		foreach ( $paginator->items() as $article ) {
			$data[] = self::shape( $article, false );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $paginator->total(),
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $paginator->total() / $per_page ) ),
					// Unfiltered total, so the page can tell "no articles yet"
					// (show the empty state) from "no match for this filter".
					'total_count'  => KbArticleModel::query()->count(),
				),
			),
			200
		);
	}

	/**
	 * Read one article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$article = KbArticleModel::with( 'category' )->find( (int) $request->get_param( 'id' ) );
		if ( ! $article ) {
			return self::not_found();
		}

		return new WP_REST_Response( self::shape( $article, true ), 200 );
	}

	/**
	 * Create an article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$body    = self::body( $request );
		$article = ArticlePages::with_text_as_written(
			$body,
			static fn() => ( new ArticleService() )->create( $body )
		);
		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$article->load( 'category' );

		return new WP_REST_Response( self::shape( $article, true ), 201 );
	}

	/**
	 * Update an article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$article = KbArticleModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $article ) {
			return self::not_found();
		}

		$body    = self::body( $request );
		$article = ArticlePages::with_text_as_written(
			$body,
			static fn() => ( new ArticleService() )->update( $article, $body )
		);
		if ( is_wp_error( $article ) ) {
			return $article;
		}

		$article->load( 'category' );

		return new WP_REST_Response( self::shape( $article, true ), 200 );
	}

	/**
	 * Delete an article.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		$article = KbArticleModel::find( (int) $request->get_param( 'id' ) );
		if ( ! $article ) {
			return self::not_found();
		}

		$id      = (int) $article->id;
		$deleted = ( new ArticleService() )->delete( $article );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			),
			200
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * JSON body, falling back to form params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : (array) $request->get_body_params();

		// Where an article sits among everyone's is arranging the help
		// center: managers only (the order route is theirs too).
		if ( ! KbAccess::can_manage() ) {
			unset( $params['position'] );
		}

		return $params;
	}

	/**
	 * Admin payload for one article.
	 *
	 * @param KbArticleModel $article      Article.
	 * @param bool           $with_content Include the HTML body.
	 * @return array<string, mixed>
	 */
	public static function shape( KbArticleModel $article, bool $with_content ): array {
		$category  = $article->category;
		$author_id = (int) $article->author_id;
		$author    = $author_id > 0 ? get_userdata( $author_id ) : false;

		$out = array(
			'id'                   => (int) $article->id,
			'title'                => (string) $article->title,
			'slug'                 => (string) $article->slug,
			'category_id'          => $article->category_id ? (int) $article->category_id : null,
			'category'             => $category ? array(
				'id'   => (int) $category->id,
				'name' => (string) $category->name,
			) : null,
			'excerpt'              => (string) $article->excerpt,
			'display_excerpt'      => ArticleService::display_excerpt( $article ),
			'status'               => (string) $article->status,
			'visibility'           => (string) $article->visibility,
			'include_in_assistant' => (bool) $article->include_in_assistant,
			'author'               => $author ? array(
				'id'   => $author_id,
				'name' => (string) $author->display_name,
			) : null,
			'position'             => (int) $article->position,
			'views'                => (int) $article->views,
			'helpful_yes'          => (int) $article->helpful_yes,
			'helpful_no'           => (int) $article->helpful_no,
			'published_at'         => $article->published_at ? (string) $article->published_at : null,
			'created_at'           => (string) $article->created_at,
			'updated_at'           => (string) $article->updated_at,
			// The article's own page: its address and the block editor.
			'page'                 => ArticlePages::describe( $article ),
			// Whether this person may change it (a writer: only their own).
			'can_edit'             => KbAccess::can_edit( $article ),
		);

		if ( $with_content ) {
			$out['content'] = (string) $article->content;
			// What the block editor on the article screen opens: the page's text
			// exactly as written (block markers, embeds), not the sanitised copy
			// search reads. Only for those who may change it.
			if ( KbAccess::can_edit( $article ) ) {
				$page                  = ArticlePages::page_for( $article );
				$out['editor_content'] = $page instanceof \WP_Post ? (string) $page->post_content : (string) $article->content;
			}
		}

		return $out;
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'not_found', __( 'Article not found.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
