<?php
/**
 * Every help-center article's own page.
 *
 * An article row is what the help center, the portal and Pro's assistant
 * read: title, body, search words, who may read it. What a row cannot give an
 * article is a place on the site: an address of its own, a body written in
 * the block editor, revisions, a menu entry. A WordPress post can, so each
 * article is paired with a post of its own type, linked by
 * `kb_articles.post_id` (the way Memberships pairs plans with plan pages).
 *
 * The two stay in step both ways, each save guarded so it does not echo back:
 *   - saving the article (DoubleScale screen, REST) writes title, slug,
 *     status and a changed body to the page
 *   - saving the page (block editor) writes title, body, slug and
 *     published/draft to the article through {@see ArticleService}, so search
 *     words are rebuilt and `doublescale_kb_article_saved` tells the
 *     assistant to read it again
 *
 * Who may read an article is set in DoubleScale only. The page follows it: a
 * public article is published, a portal or internal one is private. Private
 * keeps it out of WordPress search, feeds, sitemaps (core, Yoast, Rank Math)
 * and the core REST routes with no code of ours; a portal client is let in to
 * portal pages by {@see self::map_read_cap()}.
 *
 * Pages are made from articles only: the type refuses "Add New".
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use WP_Post;

/**
 * ArticlePages service.
 */
final class ArticlePages {

	/**
	 * Post type of article pages (20 characters at most: WordPress's limit).
	 */
	public const POST_TYPE = 'ds_kb_article';

	/**
	 * Core REST base the block editor saves through.
	 */
	public const REST_BASE = 'kb-articles';

	/**
	 * Address base the rewrite rules were last flushed for.
	 */
	private const REWRITE_OPTION = 'doublescale_kb_pages_rewrite';

	/**
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * True while one side is writing the other, so the write does not echo back.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * The text as written on the article screen, for the page, while one save
	 * runs ({@see self::with_text_as_written()}).
	 *
	 * @var string|null
	 */
	private static $text_as_written = null;

	/**
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( did_action( 'init' ) ) {
			self::register_post_type();
			self::register_template();
		} else {
			add_action( 'init', array( self::class, 'register_post_type' ) );
			add_action( 'init', array( self::class, 'register_template' ) );
		}
		add_action( 'init', array( self::class, 'maybe_flush_rewrite' ), 99 );
		// No arguments: admin_init passes an empty string, not a limit.
		add_action( 'admin_init', array( self::class, 'backfill' ), 10, 0 );

		add_action( 'doublescale_kb_article_saved', array( self::class, 'on_article_saved' ), 5, 2 );
		add_action( 'doublescale_kb_article_deleted', array( self::class, 'on_article_deleted' ), 5, 2 );

		add_filter( 'wp_insert_post_data', array( self::class, 'filter_post_data' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( self::class, 'on_post_saved' ), 10, 2 );
		add_action( 'deleted_post', array( self::class, 'on_post_deleted' ), 10, 2 );

		add_filter( 'user_has_cap', array( self::class, 'grant_caps' ), 10, 4 );
		add_filter( 'map_meta_cap', array( self::class, 'map_read_cap' ), 10, 4 );
		add_filter( 'map_meta_cap', array( self::class, 'map_author_caps' ), 10, 4 );
		add_filter( 'private_title_format', array( self::class, 'filter_private_title' ), 10, 2 );
		// Public articles are listed by our own sitemap provider, with their
		// last change; core would list them a second time.
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'filter_sitemap_post_types' ) );
	}

	/**
	 * The first part of an article's address: the help center page's own
	 * (`/help-center/how-to-pay/`), so articles sit under the page that lists
	 * them and never take an address of their own. `help` while there is no
	 * published help center page.
	 *
	 * @return string
	 */
	public static function base(): string {
		$page_id = self::help_page_id();
		$base    = $page_id > 0 ? (string) get_page_uri( $page_id ) : '';

		/**
		 * Filter the address base of help-center article pages.
		 *
		 * @param string $base The help center page's address, else `help`.
		 */
		$base = trim( (string) apply_filters( 'doublescale_kb_article_base', '' !== $base ? $base : 'help' ), '/' );

		return '' !== $base ? $base : 'help';
	}

	/**
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'                     => __( 'Help articles', 'doublescale' ),
					'singular_name'            => __( 'Help article', 'doublescale' ),
					'all_items'                => __( 'Help articles', 'doublescale' ),
					'edit_item'                => __( 'Edit help article', 'doublescale' ),
					'view_item'                => __( 'View help article', 'doublescale' ),
					'search_items'             => __( 'Search help articles', 'doublescale' ),
					'not_found'                => __( 'No help articles found.', 'doublescale' ),
					'not_found_in_trash'       => __( 'No help articles found in Trash.', 'doublescale' ),
					'item_updated'             => __( 'Help article updated.', 'doublescale' ),
					'item_published'           => __( 'Help article published.', 'doublescale' ),
					'item_published_privately' => __( 'Help article saved. Only the readers you chose in DoubleScale can open it.', 'doublescale' ),
				),
				'public'                => true,
				'show_ui'               => true,
				// Reached from Support → Help Center, which owns the article.
				'show_in_menu'          => false,
				'show_in_admin_bar'     => false,
				'show_in_nav_menus'     => true,
				// The block editor needs the type in REST; visitors read
				// articles through the help center routes instead.
				'show_in_rest'          => true,
				'rest_base'             => self::REST_BASE,
				'rest_controller_class' => ArticlePagesRestController::class,
				'hierarchical'          => false,
				// The help center page lists every article already.
				'has_archive'           => false,
				'rewrite'               => array(
					'slug'       => self::base(),
					'with_front' => false,
					'feeds'      => false,
					'pages'      => false,
				),
				'supports'              => array( 'title', 'editor', 'revisions' ),
				'capability_type'       => array( 'ds_kb_article', 'ds_kb_articles' ),
				'map_meta_cap'          => true,
				'capabilities'          => array(
					// A page with no article behind it would be read by nobody.
					'create_posts' => 'do_not_allow',
				),
				'delete_with_user'      => false,
			)
		);

		self::register_category_rule();
	}

	/**
	 * `/help-center/category/<slug>/` shows that category on the help center
	 * page ("category" is {@see KbAddresses::category_segment()}). Added on
	 * top: the article type's own attachment rule would read
	 * the two segments as an attachment of an article named "category".
	 *
	 * @return void
	 */
	public static function register_category_rule(): void {
		add_rewrite_tag( '%' . KbPublicArticles::CATEGORY_VAR . '%', '([^/]+)' );

		$page_id = self::help_page_id();
		if ( $page_id <= 0 ) {
			return;
		}

		// On "/index.php/…" permalinks every rule starts with `index.php/`
		// (WP_Rewrite::$root); without it the category address is not found.
		global $wp_rewrite;
		$root = $wp_rewrite instanceof \WP_Rewrite ? (string) $wp_rewrite->root : '';

		add_rewrite_rule(
			'^' . preg_quote( $root . (string) get_page_uri( $page_id ), '#' ) . '/' . preg_quote( KbAddresses::category_segment(), '#' ) . '/([^/]+)/?$',
			'index.php?page_id=' . $page_id . '&' . KbPublicArticles::CATEGORY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Block themes show an article with a template of its own: the title and
	 * the body (with the help-center parts {@see \DoubleScale\Modules\KnowledgeBase\Renderer\ArticlePageHandler}
	 * adds), none of a blog post's author line, tags, comments or "More
	 * posts". The site owner can change it in the Site Editor; a theme's own
	 * `single-ds_kb_article` template wins over it. Classic themes keep their
	 * single post template.
	 *
	 * @return void
	 */
	public static function register_template(): void {
		// Block themes only: a classic theme has no header / footer template
		// parts, and WordPress would still use this template there (a page
		// with no site header, footer or title). Classic themes keep their
		// own single template, which prints the title.
		if ( ! function_exists( 'register_block_template' ) || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}

		$name = 'doublescale//single-' . self::POST_TYPE;
		if ( class_exists( '\WP_Block_Templates_Registry' ) && \WP_Block_Templates_Registry::get_instance()->is_registered( $name ) ) {
			return;
		}

		register_block_template(
			$name,
			array(
				'title'       => __( 'Help article', 'doublescale' ),
				'description' => __( 'Shows one knowledge base article: its title and text, without the author line, tags or comments of a blog post.', 'doublescale' ),
				'post_types'  => array( self::POST_TYPE ),
				'content'     => '<!-- wp:template-part {"slug":"header"} /-->

<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--60)"><!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)"><!-- wp:post-title {"level":1} /-->

<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /--></div>
<!-- /wp:group --></main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer"} /-->',
			)
		);
	}

	/**
	 * Rebuild the rewrite rules once the type is new or its base changed (the
	 * help center page was made, moved or renamed), so its addresses answer
	 * without a visit to Settings → Permalinks.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite(): void {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$base = self::base();
		// The category rule follows the help center page and its word.
		$key = $base . '|' . self::help_page_id() . '|' . KbAddresses::category_segment();
		// Done for this base, and the saved rules still have the pages: a
		// rebuild made while the type was not there (the help center off a
		// moment, another plugin, Settings -> Permalinks) drops them, and the
		// flag alone would leave every article address "page not found".
		if ( get_option( self::REWRITE_OPTION ) === $key && self::rules_have_pages() ) {
			return;
		}

		if ( self::rewrite_base() !== $base ) {
			// The base changed since the type was registered on this request.
			self::register_post_type();
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, $key );
	}

	/**
	 * Whether the saved rewrite rules lead to article pages. Plain permalinks
	 * keep no rules at all: nothing to rebuild then.
	 *
	 * @return bool
	 */
	private static function rules_have_pages(): bool {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! $rules ) {
			return '' === (string) get_option( 'permalink_structure' );
		}
		foreach ( $rules as $query ) {
			if ( false !== strpos( (string) $query, self::POST_TYPE . '=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Give every article without a page its page: articles written before
	 * pages existed, and one whose page was deleted outside DoubleScale.
	 *
	 * @param int $limit Most to make on one request.
	 * @return int Pages made.
	 */
	public static function backfill( int $limit = 100 ): int {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 0;
		}

		try {
			$rows = KbArticleModel::query()->whereNull( 'post_id' )->orderBy( 'id', 'asc' )->limit( max( 1, $limit ) )->get();
		} catch ( \Throwable $e ) {
			// Tables absent or not yet upgraded.
			return 0;
		}

		$made = 0;
		foreach ( $rows as $article ) {
			if ( self::ensure_page( $article ) > 0 ) {
				++$made;
			}
		}

		return $made;
	}

	/**
	 * The article's page, made when it has none.
	 *
	 * @param KbArticleModel $article Article.
	 * @return int Post id, 0 when WordPress refused it.
	 */
	public static function ensure_page( KbArticleModel $article ): int {
		$page = self::page_for( $article );
		if ( $page instanceof WP_Post ) {
			return (int) $page->ID;
		}

		self::$syncing = true;
		try {
			// Slashed: wp_insert_post() unslashes, and block attributes keep
			// escapes (`\u003c`) that must survive.
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => self::POST_TYPE,
						'post_title'     => (string) $article->title,
						'post_name'      => (string) $article->slug,
						'post_status'    => self::post_status_for( $article ),
						'post_content'   => self::$text_as_written ?? (string) $article->content,
						'post_author'    => (int) $article->author_id ?: get_current_user_id(),
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				),
				true
			);
		} finally {
			self::$syncing = false;
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		self::store_link( $article, array( 'post_id' => (int) $post_id ) );
		self::adopt_post_slug( $article, get_post( (int) $post_id ) );

		return (int) $post_id;
	}

	/**
	 * Run one article save from the article screen so its page keeps the text
	 * as written. The article row always holds a sanitised copy (search and
	 * the assistant read it); the page is what readers see, and someone allowed
	 * to post any markup (an administrator embedding a video) must not lose it
	 * to that copy. WordPress itself still strips the markup for anyone else
	 * when the page is written.
	 *
	 * @template T
	 * @param array<string, mixed> $body Request body.
	 * @param callable(): T        $save The save.
	 * @return T
	 */
	public static function with_text_as_written( array $body, callable $save ) {
		self::$text_as_written = array_key_exists( 'content', $body ) && is_string( $body['content'] ) ? $body['content'] : null;

		try {
			return $save();
		} finally {
			self::$text_as_written = null;
		}
	}

	/**
	 * Article → page, after every save through {@see ArticleService}.
	 *
	 * @param KbArticleModel            $article Article after the save.
	 * @param array<string, mixed>|null $before  Its columns before, null on create.
	 * @return void
	 */
	public static function on_article_saved( $article, $before = null ): void {
		if ( self::$syncing || ! $article instanceof KbArticleModel || ! post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$page = self::page_for( $article );
		if ( ! $page instanceof WP_Post ) {
			self::ensure_page( $article );
			return;
		}

		$update = array();
		if ( $page->post_title !== (string) $article->title ) {
			$update['post_title'] = (string) $article->title;
		}
		$status = self::post_status_for( $article );
		// A trashed page stays in the Trash while its article is a draft.
		if ( $page->post_status !== $status && ! ( 'trash' === $page->post_status && 'draft' === $status ) ) {
			$update['post_status'] = $status;
		}
		if ( 'trash' !== $page->post_status && $page->post_name !== (string) $article->slug ) {
			$update['post_name'] = (string) $article->slug;
		}
		// Only a body this save changed: the page may hold markup the article's
		// sanitised copy dropped, and must not lose it to an unrelated save.
		$old_content = is_array( $before ) ? (string) ( $before['content'] ?? '' ) : null;
		if ( null !== self::$text_as_written ) {
			if ( self::$text_as_written !== $page->post_content ) {
				$update['post_content'] = self::$text_as_written;
			}
		} elseif ( null !== $old_content && $old_content !== (string) $article->content ) {
			$update['post_content'] = (string) $article->content;
		}

		if ( array() === $update ) {
			return;
		}

		$update['ID'] = (int) $page->ID;
		self::$syncing = true;
		try {
			wp_update_post( wp_slash( $update ) );
		} finally {
			self::$syncing = false;
		}

		self::adopt_post_slug( $article, get_post( (int) $page->ID ) );
	}

	/**
	 * Deleting an article deletes its page for good: deleting it in
	 * DoubleScale already deleted the body for good, before pages.
	 *
	 * @param int                  $id       Article id.
	 * @param array<string, mixed> $snapshot Its columns before the delete.
	 * @return void
	 */
	public static function on_article_deleted( $id, $snapshot = array() ): void {
		unset( $id );
		$post_id = is_array( $snapshot ) ? (int) ( $snapshot['post_id'] ?? 0 ) : 0;
		if ( self::$syncing || $post_id <= 0 ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		self::$syncing = true;
		try {
			wp_delete_post( $post_id, true );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Before a page is written: published and private both mean "published",
	 * and which of the two it is comes from who may read the article, set in
	 * DoubleScale. So the block editor's own Public / Private choice cannot
	 * open a portal article to everyone, and its answer shows the real status.
	 *
	 * @param array<string, mixed> $data    Post columns about to be written.
	 * @param array<string, mixed> $postarr What the caller passed.
	 * @return array<string, mixed>
	 */
	public static function filter_post_data( $data, $postarr ) {
		if ( self::$syncing || ! is_array( $data ) || self::POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		if ( ! in_array( $data['post_status'] ?? '', array( 'publish', 'private' ), true ) ) {
			return $data;
		}

		$article = self::article_for_post( (int) ( $postarr['ID'] ?? 0 ) );
		if ( $article instanceof KbArticleModel ) {
			$data['post_status'] = KbArticleModel::VISIBILITY_PUBLIC === $article->visibility ? 'publish' : 'private';
			// A private page has no password.
			if ( 'private' === $data['post_status'] ) {
				$data['post_password'] = '';
			}
		}

		return $data;
	}

	/**
	 * Page → article, after every save of the page (block editor, Quick Edit,
	 * Trash).
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function on_post_saved( $post_id, $post = null ): void {
		if ( self::$syncing || ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$article = self::article_for_post( (int) $post_id );
		if ( ! $article instanceof KbArticleModel ) {
			return;
		}

		$data  = array();
		$title = trim( (string) $post->post_title );
		if ( '' !== $title && $title !== (string) $article->title ) {
			$data['title'] = $title;
		}
		if ( (string) $post->post_content !== (string) $article->content ) {
			$data['content'] = (string) $post->post_content;
		}
		$status = in_array( $post->post_status, array( 'publish', 'private' ), true ) ? KbArticleModel::STATUS_PUBLISHED : KbArticleModel::STATUS_DRAFT;
		if ( $status !== (string) $article->status ) {
			$data['status'] = $status;
		}
		// A trashed page's slug carries `__trashed`; it is not the article's.
		if ( 'trash' !== $post->post_status && '' !== (string) $post->post_name && $post->post_name !== (string) $article->slug ) {
			$data['slug'] = (string) $post->post_name;
		}

		self::$syncing = true;
		try {
			$saved = array() !== $data ? ( new ArticleService() )->update( $article, $data ) : $article;
			if ( ! $saved instanceof KbArticleModel ) {
				return;
			}

			$fix = array();
			// The article's slug is unique among articles; when it had to
			// differ from the one typed, the page takes the article's.
			if ( isset( $data['slug'] ) && (string) $saved->slug !== (string) $post->post_name ) {
				$fix['post_name'] = (string) $saved->slug;
			}
			// A scheduled page is published by WordPress without
			// wp_insert_post(), so {@see self::filter_post_data()} never saw it.
			$want = self::post_status_for( $saved );
			if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) && $post->post_status !== $want ) {
				$fix['post_status'] = $want;
			}

			if ( array() !== $fix ) {
				$fix['ID'] = (int) $post_id;
				wp_update_post( $fix );
			}
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * A page deleted for good (emptied from the Trash) deletes its article.
	 *
	 * @param int          $post_id Deleted post.
	 * @param WP_Post|null $post    Its last state.
	 * @return void
	 */
	public static function on_post_deleted( $post_id, $post = null ): void {
		if ( self::$syncing || ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		$article = self::article_for_post( (int) $post_id );
		if ( ! $article instanceof KbArticleModel ) {
			return;
		}

		self::$syncing = true;
		try {
			( new ArticleService() )->delete( $article );
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Whoever manages articles in DoubleScale writes their pages, whatever
	 * WordPress role they hold (a Support Manager has no page capabilities).
	 * A writer (Help Center Agent) gets the type's capabilities for their own
	 * pages only: never "others'" ones, and {@see self::map_author_caps()}
	 * decides each page by who wrote its article.
	 *
	 * @param array<string, bool> $allcaps Capabilities the user has.
	 * @param string[]            $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Original check.
	 * @param \WP_User|null       $user    User.
	 * @return array<string, bool>
	 */
	public static function grant_caps( $allcaps, $caps, $args, $user = null ) {
		static $checking = false;

		if ( $checking || ! is_array( $allcaps ) || ! is_array( $caps ) || ! $user instanceof \WP_User ) {
			return $allcaps;
		}

		$ours = array_values( array_intersect( $caps, self::primitive_caps() ) );
		if ( array() === $ours ) {
			return $allcaps;
		}

		$checking = true;
		try {
			$manager = KbAccess::can_manage( (int) $user->ID );
			$writer  = ! $manager && KbAccess::can_write( (int) $user->ID );
		} finally {
			$checking = false;
		}

		if ( $manager ) {
			foreach ( $ours as $cap ) {
				$allcaps[ $cap ] = true;
			}
		} elseif ( $writer ) {
			$own = self::own_primitive_caps();
			foreach ( $ours as $cap ) {
				if ( in_array( $cap, $own, true ) ) {
					$allcaps[ $cap ] = true;
				}
			}
		}

		return $allcaps;
	}

	/**
	 * A writer changes only the pages of the articles they wrote. Decided by
	 * the article's author (`kb_articles.author_id`), not the page's: the
	 * article is what DoubleScale owns, and a page made for an older article
	 * may carry whoever was signed in at the time. Managers keep WordPress's
	 * own answer (they hold every page capability).
	 *
	 * Covers the block editor's saves, its autosaves (they check the page),
	 * the Trash and deleting for good.
	 *
	 * @param string[]          $caps    Primitive capabilities required.
	 * @param string            $cap     Capability being checked.
	 * @param int               $user_id User id.
	 * @param array<int, mixed> $args    Extra arguments (post id first).
	 * @return string[]
	 */
	public static function map_author_caps( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		// A revision or backup (autosave) is edited as its page, as in core
		// (core never lets anyone delete one).
		if ( 'edit_post' === $cap && $post instanceof WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( (int) $post->post_parent );
		}
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return $caps;
		}

		$user_id = (int) $user_id;
		if ( $user_id <= 0 || KbAccess::can_manage( $user_id ) || ! KbAccess::can_write( $user_id ) ) {
			return $caps;
		}

		$article = self::article_for_post( (int) $post->ID );
		if ( ! $article instanceof KbArticleModel || ! KbAccess::can_edit( $article, $user_id ) ) {
			return array( 'do_not_allow' );
		}

		$type = get_post_type_object( self::POST_TYPE );
		if ( ! $type ) {
			return array( 'do_not_allow' );
		}

		return array( 'edit_post' === $cap ? (string) $type->cap->edit_posts : (string) $type->cap->delete_posts );
	}

	/**
	 * A portal client may open a published portal article's page, though it
	 * is private. Internal pages stay with whoever manages articles.
	 *
	 * @param string[]          $caps    Primitive capabilities required.
	 * @param string            $cap     Capability being checked.
	 * @param int               $user_id User id.
	 * @param array<int, mixed> $args    Extra arguments (post id first).
	 * @return string[]
	 */
	public static function map_read_cap( $caps, $cap, $user_id, $args ) {
		if ( 'read_post' !== $cap || empty( $args[0] ) ) {
			return $caps;
		}

		$post = get_post( (int) $args[0] );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || 'private' !== $post->post_status ) {
			return $caps;
		}

		$article = self::article_for_post( (int) $post->ID );
		if (
			$article instanceof KbArticleModel
			&& KbArticleModel::STATUS_PUBLISHED === $article->status
			&& KbArticleModel::VISIBILITY_PORTAL === $article->visibility
			&& (int) $user_id === get_current_user_id()
			&& in_array( KbArticleModel::VISIBILITY_PORTAL, KbAudience::current(), true )
		) {
			return array( 'read' );
		}

		return $caps;
	}

	/**
	 * No "Private:" before a portal article's title: its readers see it as
	 * an ordinary page.
	 *
	 * @param string       $format Title format.
	 * @param WP_Post|null $post   Post.
	 * @return string
	 */
	public static function filter_private_title( $format, $post = null ) {
		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type ? '%s' : $format;
	}

	/**
	 * @param array<string, \WP_Post_Type> $post_types Post types core lists.
	 * @return array<string, \WP_Post_Type>
	 */
	public static function filter_sitemap_post_types( $post_types ) {
		if ( is_array( $post_types ) ) {
			unset( $post_types[ self::POST_TYPE ] );
		}

		return $post_types;
	}

	/**
	 * The page status an article calls for.
	 *
	 * @param KbArticleModel $article Article.
	 * @return string
	 */
	public static function post_status_for( KbArticleModel $article ): string {
		if ( KbArticleModel::STATUS_PUBLISHED !== $article->status ) {
			return 'draft';
		}

		return KbArticleModel::VISIBILITY_PUBLIC === $article->visibility ? 'publish' : 'private';
	}

	/**
	 * Address of an article page by slug, without loading the post: page and
	 * article share one slug. '' while the type is not registered.
	 *
	 * @param string $slug Article slug (already URL-safe).
	 * @return string
	 */
	public static function url_for_slug( string $slug ): string {
		global $wp_rewrite;

		if ( '' === $slug || ! post_type_exists( self::POST_TYPE ) ) {
			return '';
		}

		$struct = $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->get_extra_permastruct( self::POST_TYPE ) : false;
		if ( $struct ) {
			return home_url( user_trailingslashit( str_replace( '%' . self::POST_TYPE . '%', $slug, $struct ) ) );
		}

		return add_query_arg( self::POST_TYPE, $slug, home_url( '/' ) );
	}

	/**
	 * The page as the article screen shows it, or null when it has none.
	 *
	 * @param KbArticleModel $article Article.
	 * @return array{id:int,status:string,url:string,edit_url:string}|null
	 */
	public static function describe( KbArticleModel $article ): ?array {
		$page = self::page_for( $article );
		if ( ! $page instanceof WP_Post ) {
			return null;
		}

		return array(
			'id'       => (int) $page->ID,
			'status'   => (string) $page->post_status,
			'url'      => (string) get_permalink( $page ),
			'edit_url' => admin_url( 'post.php?post=' . (int) $page->ID . '&action=edit' ),
			// When it was last saved (UTC): a newer backup of unsaved changes is offered back.
			'modified' => (string) get_post_modified_time( 'c', true, $page ),
		);
	}

	/**
	 * The article a page belongs to.
	 *
	 * @param int $post_id Post id.
	 * @return KbArticleModel|null
	 */
	public static function article_for_post( int $post_id ): ?KbArticleModel {
		if ( $post_id <= 0 ) {
			return null;
		}

		try {
			$article = KbArticleModel::query()->where( 'post_id', $post_id )->first();
		} catch ( \Throwable $e ) {
			return null;
		}

		return $article instanceof KbArticleModel ? $article : null;
	}

	/**
	 * @param KbArticleModel $article Article.
	 * @return WP_Post|null
	 */
	public static function page_for( KbArticleModel $article ): ?WP_Post {
		$post_id = (int) $article->post_id;
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );

		return $post instanceof WP_Post && self::POST_TYPE === $post->post_type ? $post : null;
	}

	/**
	 * WordPress may refuse a slug (one it keeps for itself, like `feed`) and
	 * give the page another; the article takes the page's, so links agree.
	 *
	 * @param KbArticleModel $article Article.
	 * @param WP_Post|null   $post    Its page after the write.
	 * @return void
	 */
	private static function adopt_post_slug( KbArticleModel $article, $post ): void {
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || '' === (string) $post->post_name || $post->post_name === (string) $article->slug ) {
			return;
		}

		if ( KbArticleModel::query()->where( 'slug', $post->post_name )->where( 'id', '!=', (int) $article->id )->exists() ) {
			return;
		}

		self::store_link( $article, array( 'slug' => (string) $post->post_name ) );
	}

	/**
	 * Write columns straight to the row: no save hooks, so nothing re-indexes
	 * for a link or a slug.
	 *
	 * @param KbArticleModel       $article Article.
	 * @param array<string, mixed> $columns Columns.
	 * @return void
	 */
	private static function store_link( KbArticleModel $article, array $columns ): void {
		KbArticleModel::query()->where( 'id', (int) $article->id )->update( $columns );
		$article->setRawAttributes( array_merge( $article->getAttributes(), $columns ), true );
	}

	/**
	 * The type's own capabilities, the ones {@see self::grant_caps()} hands out.
	 *
	 * @return string[]
	 */
	private static function primitive_caps(): array {
		$type = get_post_type_object( self::POST_TYPE );
		if ( ! $type ) {
			return array();
		}

		$caps = (array) $type->cap;
		unset( $caps['read'], $caps['create_posts'], $caps['edit_post'], $caps['read_post'], $caps['delete_post'] );

		return array_values( array_unique( array_filter( $caps, static fn( $cap ) => 'do_not_allow' !== $cap ) ) );
	}

	/**
	 * The type's capabilities a writer holds: writing and publishing pages,
	 * never anyone else's (no `edit_others_*`, `delete_others_*`, and no
	 * reading others' private pages).
	 *
	 * @return string[]
	 */
	private static function own_primitive_caps(): array {
		$type = get_post_type_object( self::POST_TYPE );
		if ( ! $type ) {
			return array();
		}

		$cap = $type->cap;

		return array_values(
			array_filter(
				array(
					(string) ( $cap->edit_posts ?? '' ),
					(string) ( $cap->edit_published_posts ?? '' ),
					(string) ( $cap->edit_private_posts ?? '' ),
					(string) ( $cap->publish_posts ?? '' ),
					(string) ( $cap->delete_posts ?? '' ),
					(string) ( $cap->delete_published_posts ?? '' ),
					(string) ( $cap->delete_private_posts ?? '' ),
				),
				static fn( $one ) => '' !== $one && 'do_not_allow' !== $one
			)
		);
	}

	/**
	 * The published help center page, 0 when there is none.
	 *
	 * @return int
	 */
	private static function help_page_id(): int {
		$page_id = ShortcodePageProvisioner::resolve_page_id( KbPublicArticles::SHORTCODE );

		return $page_id > 0 && 'publish' === get_post_status( $page_id ) ? $page_id : 0;
	}

	/**
	 * The base the type is registered with on this request.
	 *
	 * @return string
	 */
	private static function rewrite_base(): string {
		$type = get_post_type_object( self::POST_TYPE );

		return $type && is_array( $type->rewrite ) ? (string) ( $type->rewrite['slug'] ?? '' ) : '';
	}
}
