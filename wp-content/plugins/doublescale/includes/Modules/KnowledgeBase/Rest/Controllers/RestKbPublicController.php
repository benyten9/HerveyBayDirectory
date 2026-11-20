<?php
/**
 * Public REST routes for reading help-center articles.
 *
 * Routes registered (namespace `doublescale/v1`):
 *
 *   GET  /kb/public/categories            Categories with readable articles.
 *   GET  /kb/public/articles              Readable articles (search, category, page).
 *   GET  /kb/public/articles/{slug}       One readable article, body included.
 *   POST /kb/public/articles/{id}/feedback  "Was this helpful?" — once per visitor a day.
 *   POST /kb/public/articles/{id}/view      Count a view — once per visitor an hour.
 *
 * Open to everyone: what a request may read is decided here, per request, by
 * {@see KbAudience} (public; + portal for a signed-in client or staff) and
 * {@see KbPublicArticles} (published only). Internal articles and drafts are
 * never returned, whoever asks. A request without a REST nonce is treated by
 * WordPress as logged out, so a cached public page always reads as a visitor.
 *
 * Every route counts against a per-visitor limit; the two writes also dedupe
 * per visitor, so the helpful counts and view counts cannot be pumped. The
 * visitor is {@see ClientIp::bucket()} (the proxy's header when the owner
 * trusts one) and the counters are {@see RateLimiter}'s atomic ones.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Security\ClientIp;
use DoubleScale\Core\Security\RateLimiter;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbFeedbackModel;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;
use DoubleScale\Modules\Portal\Services\PortalIdentity;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestKbPublicController class.
 */
class RestKbPublicController extends RestController {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'kb/public';

	/**
	 * Requests per visitor per minute, all routes together.
	 */
	private const RATE_LIMIT = 120;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_categories' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/articles',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'search'   => array( 'type' => 'string' ),
						'category' => array(
							'description' => __( 'Category slug.', 'doublescale' ),
							'type'        => 'string',
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
							'maximum' => 50,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/articles/(?P<slug>[^/]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/articles/(?P<id>[\d]+)/feedback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_feedback' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'helpful' => array(
							'type'     => 'boolean',
							'required' => true,
						),
						// With a "No": what was missing. Sent with the vote or
						// after it; the vote counts once either way.
						'comment' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/articles/(?P<id>[\d]+)/view',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'post_view' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Categories holding at least one article this reader may read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_categories( $request ) {
		$blocked = $this->guard();
		if ( $blocked ) {
			return $blocked;
		}

		return new WP_REST_Response( array( 'data' => KbPublicArticles::categories( KbAudience::current() ) ), 200 );
	}

	/**
	 * Readable articles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$blocked = $this->guard();
		if ( $blocked ) {
			return $blocked;
		}

		$per_page = (int) $request->get_param( 'per_page' ) ?: 20;
		$page     = (int) $request->get_param( 'page' ) ?: 1;

		$result = KbPublicArticles::list(
			KbAudience::current(),
			(string) $request->get_param( 'search' ),
			(string) $request->get_param( 'category' ),
			$page,
			$per_page
		);

		$data = array();
		foreach ( $result['items'] as $article ) {
			$data[] = KbPublicArticles::shape( $article, false );
		}

		return new WP_REST_Response(
			array(
				'data' => $data,
				'meta' => array(
					'total'        => $result['total'],
					'per_page'     => $per_page,
					'current_page' => $page,
					'last_page'    => max( 1, (int) ceil( $result['total'] / max( 1, $per_page ) ) ),
				),
			),
			200
		);
	}

	/**
	 * One readable article by slug.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$blocked = $this->guard();
		if ( $blocked ) {
			return $blocked;
		}

		$article = KbPublicArticles::find_by_slug( rawurldecode( (string) $request->get_param( 'slug' ) ), KbAudience::current() );
		if ( ! $article ) {
			return self::not_found();
		}

		return new WP_REST_Response( KbPublicArticles::shape( $article, true ), 200 );
	}

	/**
	 * Record "was this helpful?" once per visitor per article per day.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_feedback( $request ) {
		$blocked = $this->guard();
		if ( $blocked ) {
			return $blocked;
		}

		$id      = (int) $request->get_param( 'id' );
		$article = KbPublicArticles::find( $id, KbAudience::current() );
		if ( ! $article ) {
			return self::not_found();
		}

		$helpful = rest_sanitize_boolean( $request->get_param( 'helpful' ) );
		$counted = $this->once( 'fb', $id, DAY_IN_SECONDS );
		if ( $counted ) {
			$this->increment( $id, $helpful ? 'helpful_yes' : 'helpful_no' );
		}

		$reason = $helpful ? '' : self::reason( (string) $request->get_param( 'comment' ) );
		$saved  = '' !== $reason && $this->once( 'fbr', $id, DAY_IN_SECONDS ) && $this->save_reason( $article, $reason );

		return new WP_REST_Response(
			array(
				'recorded'     => $counted,
				'reason_saved' => $saved,
			),
			200
		);
	}

	/**
	 * Count a view once per visitor per article per hour.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_view( $request ) {
		$blocked = $this->guard();
		if ( $blocked ) {
			return $blocked;
		}

		$id      = (int) $request->get_param( 'id' );
		$article = KbPublicArticles::find( $id, KbAudience::current() );
		if ( ! $article ) {
			return self::not_found();
		}

		$counted = $this->once( 'view', $id, HOUR_IN_SECONDS );
		if ( $counted ) {
			$this->increment( $id, 'views' );
		}

		return new WP_REST_Response( array( 'recorded' => $counted ), 200 );
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Module gate + per-visitor rate limit, shared by every route.
	 *
	 * @return WP_Error|null
	 */
	private function guard(): ?WP_Error {
		$disabled = $this->require_module( 'knowledge_base' );
		if ( $disabled ) {
			return $disabled;
		}

		nocache_headers();

		if ( RateLimiter::hit( self::limit_key(), MINUTE_IN_SECONDS ) > self::RATE_LIMIT ) {
			return new WP_Error(
				'kb_rate_limited',
				__( 'Too many requests. Please wait a minute and try again.', 'doublescale' ),
				array( 'status' => 429 )
			);
		}

		return null;
	}

	/**
	 * True the first time this visitor does $what to $id within $ttl.
	 *
	 * @param string $what Action key.
	 * @param int    $id   Article id.
	 * @param int    $ttl  Window in seconds.
	 * @return bool
	 */
	private function once( string $what, int $id, int $ttl ): bool {
		return RateLimiter::once( self::once_key( $what, $id ), $ttl );
	}

	/**
	 * A reader's reason as kept: plain text, single spaces kept as written,
	 * at most {@see KbFeedbackModel::MAX_LENGTH} characters.
	 *
	 * @param string $raw What was sent.
	 * @return string
	 */
	private static function reason( string $raw ): string {
		$text = trim( sanitize_textarea_field( wp_strip_all_tags( $raw ) ) );

		return mb_substr( $text, 0, KbFeedbackModel::MAX_LENGTH, 'UTF-8' );
	}

	/**
	 * Keep one reason, with who wrote it when they were signed in.
	 *
	 * @param KbArticleModel $article Article.
	 * @param string         $reason  Cleaned reason.
	 * @return bool
	 */
	private function save_reason( KbArticleModel $article, string $reason ): bool {
		$contact = class_exists( PortalIdentity::class ) && is_user_logged_in() ? PortalIdentity::current_contact() : null;

		try {
			$row = KbFeedbackModel::query()->create(
				array(
					'article_id' => (int) $article->id,
					'helpful'    => false,
					'comment'    => $reason,
					'user_id'    => get_current_user_id() ?: null,
					'contact_id' => $contact ? (int) $contact->id : null,
				)
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		/**
		 * A reader said what an article was missing.
		 *
		 * @param KbArticleModel $article Article.
		 * @param string         $reason  What they wrote.
		 */
		do_action( 'doublescale_kb_feedback_reason', $article, $reason );

		return ! empty( $row->id );
	}

	/**
	 * Atomic +1 on a counter column (no read-modify-write race).
	 *
	 * @param int    $id     Article id.
	 * @param string $column One of views, helpful_yes, helpful_no.
	 * @return void
	 */
	private function increment( int $id, string $column ): void {
		if ( ! in_array( $column, array( 'views', 'helpful_yes', 'helpful_no' ), true ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'doublescale_kb_articles';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- column is whitelisted above; table is ours.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = {$column} + 1, updated_at = updated_at WHERE id = %d", $id ) );
	}

	/**
	 * Counter key of the per-visitor request limit.
	 *
	 * @return string
	 */
	public static function limit_key(): string {
		return 'kb_pub|' . ClientIp::bucket();
	}

	/**
	 * Counter key of one visitor's vote or view on one article.
	 *
	 * @param string $what fb (vote), fbr (reason) or view.
	 * @param int    $id   Article id.
	 * @return string
	 */
	public static function once_key( string $what, int $id ): string {
		return 'kb_' . $what . '|' . ClientIp::bucket() . '|' . $id;
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'not_found', __( 'Article not found.', 'doublescale' ), array( 'status' => 404 ) );
	}
}
