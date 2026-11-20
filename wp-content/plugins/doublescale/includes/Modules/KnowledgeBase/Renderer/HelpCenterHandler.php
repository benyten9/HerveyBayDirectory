<?php
/**
 * Public help center page: `[doublescale_help_center]`.
 *
 * Rendered entirely on the server, so search engines read the articles and the
 * page works without JavaScript. One page, three views chosen by query string:
 *
 *   (none)            search box + categories with their first articles
 *   category/<slug>/  one category's articles (`?category=slug` without
 *                     pretty links; an old `?category=` link is sent to the
 *                     clean address with a 301 when the reader may see it)
 *   ?q=text           search results (a plain GET form; results also show
 *                     under the box while the reader types)
 *
 * Each article has a page of its own ({@see ArticlePageHandler}). A link from
 * before that (`?article=slug`) is sent there with a 301 when the reader may
 * read the article; otherwise it answers 404 with noindex, like a missing one,
 * so a search engine never learns a portal-only title.
 *
 * Only what {@see KbAudience} allows is shown (public; + portal articles for a
 * signed-in client or staff). Internal articles and drafts never are.
 *
 * Headings start at h2: the theme prints the page's own h1 ("Help Center").
 *
 * On a category, the page's title, canonical link and description are the
 * category's, through core and the two common SEO plugins (Yoast, Rank Math).
 *
 * The page carries its own small stylesheet inline (no build step).
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Models\KbCategoryModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticlePages;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleService;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleSearch;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

/**
 * HelpCenterHandler class.
 */
final class HelpCenterHandler {

	/**
	 * Articles listed per category on the home view.
	 */
	private const HOME_PER_CATEGORY = 5;

	/**
	 * Results per page on search and category views.
	 */
	private const PER_PAGE = 20;

	/**
	 * Category resolved for this request (category view), if any.
	 *
	 * @var KbCategoryModel|null
	 */
	private $category = null;

	/**
	 * Whether the current request is the help center page.
	 *
	 * @var bool
	 */
	private $on_page = false;

	/**
	 * Wire the shortcode and the page hooks.
	 */
	public function __construct() {
		add_shortcode( KbPublicArticles::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp', array( $this, 'resolve_request' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'render_block_core/post-title', array( $this, 'hide_page_title' ), 10, 3 );
		// Classic themes print the page title themselves (the_title).
		add_filter( 'the_title', array( $this, 'hide_classic_page_title' ), 10, 2 );

		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ) );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ) );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_action( 'wp_head', array( $this, 'print_meta_description' ), 1 );

		// Yoast SEO and Rank Math print their own title, description and canonical.
		add_filter( 'wpseo_title', array( $this, 'filter_seo_title' ) );
		add_filter( 'wpseo_metadesc', array( $this, 'filter_seo_description' ) );
		add_filter( 'wpseo_canonical', array( $this, 'filter_canonical' ) );
		add_filter( 'rank_math/frontend/title', array( $this, 'filter_seo_title' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_seo_description' ) );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_canonical' ) );
	}

	// ---------------------------------------------------------------------
	// Request
	// ---------------------------------------------------------------------

	/**
	 * Work out, once per request, whether this is the help center page and
	 * which article or category it shows.
	 *
	 * @return void
	 */
	public function resolve_request(): void {
		if ( is_admin() || ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! has_shortcode( (string) $post->post_content, KbPublicArticles::SHORTCODE ) ) {
			return;
		}

		$this->on_page = true;

		$slug = self::query_arg( 'article' );
		if ( '' !== $slug ) {
			$target = self::legacy_article_target( $slug );
			if ( '' !== $target && wp_safe_redirect( $target, 301 ) ) {
				exit;
			}
			status_header( 404 );
			return;
		}

		$legacy = self::query_arg( 'category' );
		if ( '' !== $legacy && '' === (string) get_query_var( KbPublicArticles::CATEGORY_VAR ) ) {
			$target = self::legacy_category_target( $legacy );
			if ( '' !== $target && wp_safe_redirect( $target, 301 ) ) {
				exit;
			}
		}

		$category = self::category_arg();
		if ( '' !== $category ) {
			// Only a category holding something this reader may read exists for
			// them: otherwise its name would leak, like a hidden article's title.
			$slug    = sanitize_title( $category );
			$visible = array_column( KbPublicArticles::categories( KbAudience::current() ), 'slug' );
			$found   = in_array( $slug, $visible, true ) ? KbCategoryModel::query()->where( 'slug', $slug )->first() : null;
			if ( $found instanceof KbCategoryModel ) {
				$this->category = $found;
			} else {
				status_header( 404 );
			}
		}
	}

	/**
	 * Where an old `?article=slug` link now lives: the article's page, when
	 * this reader may read the article. '' otherwise, so a hidden article
	 * still reads exactly like a missing one.
	 *
	 * @param string $slug Article slug from the old link.
	 * @return string
	 */
	public static function legacy_article_target( string $slug ): string {
		$article = KbPublicArticles::find_by_slug( $slug, KbAudience::current() );
		if ( ! $article || (int) $article->post_id <= 0 ) {
			return '';
		}

		$post = get_post( (int) $article->post_id );

		return $post instanceof \WP_Post && ArticlePages::POST_TYPE === $post->post_type ? (string) get_permalink( $post ) : '';
	}

	/**
	 * Where an old `?category=slug` link now lives: the category's clean
	 * address, when this reader may see the category and pretty links are on.
	 * '' otherwise (a hidden category stays missing; plain links keep the
	 * query, so there is nothing to send them to).
	 *
	 * @param string $slug Category slug from the old link.
	 * @return string
	 */
	public static function legacy_category_target( string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || '' === (string) get_option( 'permalink_structure' ) ) {
			return '';
		}

		$visible = array_column( KbPublicArticles::categories( KbAudience::current() ), 'slug' );

		return in_array( $slug, $visible, true ) ? KbPublicArticles::category_url( $slug ) : '';
	}

	/**
	 * The stylesheet and the script (search panel, chat panel), only on the
	 * help center page.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->on_page ) {
			return;
		}

		HelpCenterParts::enqueue_ui();
	}

	/**
	 * The category this request asks for: the clean address, else the old
	 * `?category=` (plain links, or a link to a category the reader cannot see).
	 *
	 * @return string
	 */
	private static function category_arg(): string {
		// Taken from the address as sent, so an Arabic slug is still
		// percent-encoded; sanitize_text_field() would strip every escape.
		$var = trim( sanitize_text_field( rawurldecode( (string) get_query_var( KbPublicArticles::CATEGORY_VAR ) ) ) );

		return '' !== $var ? $var : self::query_arg( 'category' );
	}

	// ---------------------------------------------------------------------
	// SEO
	// ---------------------------------------------------------------------

	/**
	 * Core `<title>`: "Category – Page".
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public function filter_title_parts( $parts ) {
		$title = $this->view_title();
		if ( null !== $title && is_array( $parts ) ) {
			$parts['title'] = $title;
		}

		return $parts;
	}

	/**
	 * SEO plugin title.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public function filter_seo_title( $title ) {
		$view = $this->view_title();

		return null === $view ? $title : $view . ' - ' . get_bloginfo( 'name' );
	}

	/**
	 * SEO plugin description.
	 *
	 * @param string $description Description.
	 * @return string
	 */
	public function filter_seo_description( $description ) {
		$view = $this->view_description();

		return null === $view ? $description : $view;
	}

	/**
	 * Canonical link of the category view.
	 *
	 * @param string $url Canonical URL.
	 * @return string
	 */
	public function filter_canonical( $url ) {
		if ( $this->category ) {
			return KbPublicArticles::category_url( (string) $this->category->slug ) ?: $url;
		}

		return $url;
	}

	/**
	 * Keep an old article link that leads nowhere, a missing category and
	 * search results out of search engines.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function filter_robots( $robots ) {
		if ( ! $this->on_page || ! is_array( $robots ) ) {
			return $robots;
		}

		$missing = '' !== self::query_arg( 'article' )
			|| ( '' !== self::category_arg() && ! $this->category );
		if ( $missing || '' !== self::query_arg( 'q' ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * `<meta name="description">` when no SEO plugin prints one.
	 *
	 * @return void
	 */
	public function print_meta_description(): void {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( '\RankMath' ) ) {
			return;
		}

		$description = $this->view_description();
		if ( null === $description || '' === $description ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	/**
	 * @return string|null
	 */
	private function view_title(): ?string {
		if ( $this->category ) {
			return (string) $this->category->name;
		}

		return null;
	}

	/**
	 * @return string|null
	 */
	private function view_description(): ?string {
		if ( $this->category && '' !== trim( (string) $this->category->description ) ) {
			return (string) $this->category->description;
		}

		return null;
	}

	// ---------------------------------------------------------------------
	// Rendering
	// ---------------------------------------------------------------------

	/**
	 * Block themes print the page's title above the shortcode; the help
	 * center draws its own heading, so that title is left out here (only on
	 * this page, only the page's own title). Classic themes keep theirs.
	 *
	 * @param string         $html     Rendered title block.
	 * @param array          $block    Block.
	 * @param \WP_Block|null $instance Block instance (context: postId).
	 * @return string
	 */
	public function hide_page_title( $html, $block = array(), $instance = null ) {
		if ( ! $this->on_page ) {
			return $html;
		}
		$post_id = $instance instanceof \WP_Block && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;

		return $post_id === (int) get_queried_object_id() ? '' : $html;
	}

	/**
	 * The page's main heading tag: h1 where the theme's title is left out
	 * (block themes), h2 under a classic theme's own h1.
	 *
	 * @return string
	 */
	private function main_heading(): string {
		// On the help center page the theme's title is left out (block and
		// classic themes): "How can we help?" or the topic is the page's h1.
		return $this->on_page ? 'h1' : 'h2';
	}

	/**
	 * Classic themes: the help center page's own title ("Help Center") is
	 * left out, so it doesn't sit above "How can we help?". Only the page's
	 * page itself while the body is drawn (themes print it before the loop
	 * too); menus and the browser tab keep it.
	 *
	 * @param string $title   Title.
	 * @param int    $post_id Post.
	 * @return string
	 */
	public function hide_classic_page_title( $title, $post_id = 0 ) {
		// Themes print the page title before the loop too (a page.php h1 calling the_title()),
		// so match the page itself, once the body is being drawn, not the loop.
		if ( ! $this->on_page || is_admin() || ! did_action( 'wp_head' ) || doing_filter( 'wp_nav_menu_objects' ) ) {
			return $title;
		}
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return $title;
		}

		return (int) $post_id === (int) get_queried_object_id() ? '' : $title;
	}

	/**
	 * Shortcode output.
	 *
	 * @param array|string $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render_shortcode( $atts = array() ): string {
		if ( ! doublescale_is_module_active( 'knowledge_base' ) ) {
			return '';
		}

		// An old article link that was not sent to the article's page (hidden,
		// gone, or a builder preview without our `wp` pass).
		$view = 'home';
		if ( '' !== self::query_arg( 'article' ) ) {
			$view = 'article';
		} elseif ( '' !== self::query_arg( 'q' ) ) {
			$view = 'search';
		} elseif ( '' !== self::category_arg() ) {
			$view = 'category';
		}

		// Pro's chat is the site's floating chat; the help center's "Chat with
		// our team" / "Ask the assistant" buttons open it.
		$ready = true;

		switch ( $view ) {
			case 'article':
				$body = $this->render_missing();
				break;
			case 'search':
				$body = $this->render_search( self::query_arg( 'q' ), $ready );
				break;
			case 'category':
				$body = $this->render_category( $ready );
				break;
			default:
				$body = $this->render_home( $ready );
		}

		return '<div class="ds-hc ds-hc--' . esc_attr( $view ) . ' alignwide">' . $body . '</div>';
	}

	/**
	 * Home: the search, the topics, the most read articles and how to get help.
	 *
	 * @param bool $chat_ready The chat is on this page.
	 * @return string
	 */
	private function render_home( bool $chat_ready ): string {
		$audience      = KbAudience::current();
		$tops          = HelpCenterParts::tree( KbPublicArticles::categories( $audience ) );
		$uncategorised = KbPublicArticles::uncategorised( $audience );
		$total         = array_sum( array_map( static fn( $t ) => (int) $t['total'], $tops ) ) + count( $uncategorised );
		$popular       = KbPublicArticles::popular( $audience, 6 );
		$tag           = $this->main_heading();

		$out  = '<section class="ds-hc__hero">';
		if ( 'h1' === $tag ) {
			$out .= '<p class="ds-hc__eyebrow">' . esc_html( (string) get_the_title( (int) get_queried_object_id() ) ?: __( 'Help Center', 'doublescale' ) ) . '</p>';
		}
		$out .= '<' . $tag . ' class="ds-hc__hero-title ds-hc__title">' . esc_html__( 'How can we help?', 'doublescale' ) . '</' . $tag . '>';
		if ( $total > 0 ) {
			$out .= '<p class="ds-hc__hero-text">' . esc_html(
				sprintf(
					/* translators: %d: number of articles in the help center */
					_n( 'Search %d article, or pick a topic below.', 'Search %d articles, or pick a topic below.', $total, 'doublescale' ),
					$total
				)
			) . '</p>';
		}
		$out .= HelpCenterParts::search_form( '', 'hero' );

		$chips = array_slice( $popular, 0, 4 );
		if ( array() !== $chips ) {
			$out .= '<div class="ds-hc__chips"><span class="ds-hc__chips-label">' . esc_html__( 'Popular:', 'doublescale' ) . '</span>';
			foreach ( $chips as $article ) {
				$out .= '<a class="ds-hc__chip" href="' . esc_url( KbPublicArticles::article_url( (string) $article->slug ) ) . '" dir="auto">' . esc_html( (string) $article->title ) . '</a>';
			}
			$out .= '</div>';
		}
		$out .= '</section>';

		if ( 0 === $total ) {
			return $out . '<div class="ds-hc__body"><p class="ds-hc__empty">' . esc_html__( 'No articles yet. Please check back soon.', 'doublescale' ) . '</p>' . HelpCenterParts::help_band( $chat_ready ) . '</div>';
		}

		$out .= '<div class="ds-hc__body">';

		// Topics.
		$out .= '<section class="ds-hc__section" aria-labelledby="ds-hc-topics">';
		$out .= '<div class="ds-hc__section-head"><h2 class="ds-hc__h2" id="ds-hc-topics">' . esc_html__( 'Browse by topic', 'doublescale' ) . '</h2>';
		if ( count( $tops ) > 1 ) {
			$out .= '<span class="ds-hc__muted">' . esc_html(
				sprintf(
					/* translators: 1: number of topics, 2: number of articles */
					__( '%1$s · %2$s', 'doublescale' ),
					/* translators: %d: number of topics */
					sprintf( _n( '%d topic', '%d topics', count( $tops ), 'doublescale' ), count( $tops ) ),
					/* translators: %d: number of articles */
					sprintf( _n( '%d article', '%d articles', $total, 'doublescale' ), $total )
				)
			) . '</span>';
		}
		$out .= '</div><div class="ds-hc__cards">';
		foreach ( $tops as $top ) {
			$out .= $this->topic_card( $top, $audience );
		}
		if ( array() !== $uncategorised ) {
			$out .= '<article class="ds-hc__card">'
				. '<div class="ds-hc__card-head"><span class="ds-hc__tile">' . HelpCenterParts::icon( 'doc', 22 ) . '</span>'
				. '<div class="ds-hc__card-intro"><h3 class="ds-hc__card-title">' . esc_html( array() === $tops ? __( 'Articles', 'doublescale' ) : __( 'More articles', 'doublescale' ) ) . '</h3></div></div>'
				. self::article_links( array_slice( $uncategorised, 0, 6 ) )
				. '</article>';
		}
		$out .= '</div></section>';

		// Most read: only once readers opened a few.
		if ( count( $popular ) >= 3 ) {
			$out .= '<section class="ds-hc__section" aria-labelledby="ds-hc-popular"><h2 class="ds-hc__h2" id="ds-hc-popular">' . esc_html__( 'Most read', 'doublescale' ) . '</h2><ol class="ds-hc__popular">';
			foreach ( $popular as $article ) {
				$out .= '<li><a href="' . esc_url( KbPublicArticles::article_url( (string) $article->slug ) ) . '">'
					. '<span class="ds-hc__popular-body"><span class="ds-hc__popular-title" dir="auto">' . esc_html( (string) $article->title ) . '</span>'
					. '<span class="ds-hc__row-meta">' . ( $article->category ? esc_html( (string) $article->category->name ) . ' · ' : '' ) . esc_html(
						sprintf(
							/* translators: %d: minutes it takes to read the article */
							_n( '%d min read', '%d min read', HelpCenterParts::read_minutes( $article ), 'doublescale' ),
							HelpCenterParts::read_minutes( $article )
						)
					) . '</span></span>'
					. HelpCenterParts::icon( 'chevron', 18 ) . '</a></li>';
			}
			$out .= '</ol></section>';
		}

		$out .= HelpCenterParts::help_band( $chat_ready );

		return $out . '</div>';
	}

	/**
	 * One topic on the home page: icon, name, description, its first
	 * articles and how many there are.
	 *
	 * @param array<string, mixed> $top      Top-level category with children and total.
	 * @param string[]             $audience Readable visibilities.
	 * @return string
	 */
	private function topic_card( array $top, array $audience ): string {
		$list  = KbPublicArticles::list( $audience, '', (string) $top['slug'], 1, self::HOME_PER_CATEGORY )['items'];
		$items = array_slice( $list, 0, 3 );
		// A parent with no article of its own shows its sub-categories' first ones.
		foreach ( $top['children'] as $child ) {
			if ( count( $items ) >= 3 ) {
				break;
			}
			$items = array_merge( $items, array_slice( KbPublicArticles::list( $audience, '', (string) $child['slug'], 1, 3 )['items'], 0, 3 - count( $items ) ) );
		}

		/* translators: %d: number of articles */
		$count = sprintf( _n( '%d article', '%d articles', (int) $top['total'], 'doublescale' ), (int) $top['total'] );
		if ( array() !== $top['children'] ) {
			/* translators: %d: number of sub-topics */
			$count .= ' · ' . sprintf( _n( '%d sub-topic', '%d sub-topics', count( $top['children'] ), 'doublescale' ), count( $top['children'] ) );
		}

		return '<article class="ds-hc__card">'
			. '<div class="ds-hc__card-head"><span class="ds-hc__tile">' . HelpCenterParts::icon( HelpCenterParts::category_icon( $top ), 22 ) . '</span>'
			. '<div class="ds-hc__card-intro"><h3 class="ds-hc__card-title"><a href="' . esc_url( (string) $top['url'] ) . '" dir="auto">' . esc_html( (string) $top['name'] ) . '</a></h3>'
			. ( '' !== (string) $top['description'] ? '<p class="ds-hc__card-text" dir="auto">' . esc_html( (string) $top['description'] ) . '</p>' : '' )
			. '</div></div>'
			. self::article_links( $items )
			. '<div class="ds-hc__card-foot"><span class="ds-hc__muted">' . esc_html( $count ) . '</span>'
			. '<a class="ds-hc__more" href="' . esc_url( (string) $top['url'] ) . '">' . esc_html__( 'See all', 'doublescale' ) . HelpCenterParts::icon( 'arrow', 16 ) . '</a></div>'
			. '</article>';
	}

	/**
	 * The topics on the side and the view beside them.
	 *
	 * @param string $current Open category slug.
	 * @param string $main    Main column.
	 * @param array<int, array<string, mixed>>|null $tops Topic tree (computed when null).
	 * @return string
	 */
	private function with_side( string $current, string $main, ?array $tops = null ): string {
		$tops = $tops ?? HelpCenterParts::tree( KbPublicArticles::categories( KbAudience::current() ) );

		return '<div class="ds-hc__layout">' . HelpCenterParts::topic_nav( $tops, $current ) . '<div class="ds-hc__main">' . $main . '</div></div>';
	}

	/**
	 * One category: its articles, then each sub-category's.
	 *
	 * @param bool $chat_ready The chat is on this page.
	 * @return string
	 */
	private function render_category( bool $chat_ready ): string {
		$audience = KbAudience::current();
		$tops     = HelpCenterParts::tree( KbPublicArticles::categories( $audience ) );

		if ( ! $this->category ) {
			return $this->with_side( '', self::breadcrumbs( array() ) . '<' . $this->main_heading() . ' class="ds-hc__page-title ds-hc__title">' . esc_html__( 'This topic is not available', 'doublescale' ) . '</' . $this->main_heading() . '>' . HelpCenterParts::help_band( $chat_ready ), $tops );
		}

		$slug = (string) $this->category->slug;
		$row  = null;
		$top  = null;
		foreach ( $tops as $candidate ) {
			if ( (string) $candidate['slug'] === $slug ) {
				$row = $candidate;
				$top = $candidate;
			}
			foreach ( $candidate['children'] as $child ) {
				if ( (string) $child['slug'] === $slug ) {
					$row = $child + array( 'children' => array(), 'total' => (int) $child['articles_count'] );
					$top = $candidate;
				}
			}
		}
		$row = $row ?? array(
			'name'           => (string) $this->category->name,
			'description'    => (string) $this->category->description,
			'icon'           => (string) $this->category->icon,
			'children'       => array(),
			'total'          => 0,
			'articles_count' => 0,
		);

		$trail = array();
		if ( $top && (string) $top['slug'] !== $slug ) {
			$trail[] = array( (string) $top['name'], (string) $top['url'] );
		}

		$list  = KbPublicArticles::list( $audience, '', $slug, self::page_arg(), self::PER_PAGE );
		/* translators: %d: number of articles */
		$count = sprintf( _n( '%d article', '%d articles', (int) $row['total'], 'doublescale' ), (int) $row['total'] );
		if ( array() !== $row['children'] ) {
			/* translators: %d: number of sub-topics */
			$count .= ' · ' . sprintf( _n( '%d sub-topic', '%d sub-topics', count( $row['children'] ), 'doublescale' ), count( $row['children'] ) );
		}
		$tag = $this->main_heading();

		$main  = self::breadcrumbs( $trail, (string) $this->category->name );
		$main .= '<header class="ds-hc__head"><span class="ds-hc__tile ds-hc__tile--lg">' . HelpCenterParts::icon( HelpCenterParts::category_icon( $row ), 26 ) . '</span><div class="ds-hc__head-body">'
			. '<' . $tag . ' class="ds-hc__page-title ds-hc__title" dir="auto">' . esc_html( (string) $this->category->name ) . '</' . $tag . '>'
			. ( '' !== trim( (string) $this->category->description ) ? '<p class="ds-hc__lead" dir="auto">' . esc_html( (string) $this->category->description ) . '</p>' : '' )
			. '<p class="ds-hc__muted">' . esc_html( $count ) . '</p></div></header>';

		if ( array() !== $list['items'] ) {
			$main .= HelpCenterParts::article_rows( $list['items'] ) . $this->pagination( $list['total'] );
		} elseif ( array() === $row['children'] ) {
			$main .= '<p class="ds-hc__empty">' . esc_html__( 'No articles in this topic yet.', 'doublescale' ) . '</p>';
		}

		foreach ( $row['children'] as $child ) {
			$sub = KbPublicArticles::list( $audience, '', (string) $child['slug'], 1, 10 );
			if ( array() === $sub['items'] ) {
				continue;
			}
			$main .= '<section class="ds-hc__sub" aria-labelledby="ds-hc-sub-' . (int) $child['id'] . '">'
				. '<h2 class="ds-hc__h2" id="ds-hc-sub-' . (int) $child['id'] . '"><a href="' . esc_url( (string) $child['url'] ) . '" dir="auto">' . esc_html( (string) $child['name'] ) . '</a> <span class="ds-hc__muted">'
				/* translators: %d: number of articles */
				. esc_html( sprintf( _n( '%d article', '%d articles', (int) $sub['total'], 'doublescale' ), (int) $sub['total'] ) ) . '</span></h2>'
				. HelpCenterParts::article_rows( $sub['items'] )
				. ( $sub['total'] > 10 ? '<a class="ds-hc__more" href="' . esc_url( (string) $child['url'] ) . '">' . esc_html__( 'See all', 'doublescale' ) . HelpCenterParts::icon( 'arrow', 16 ) . '</a>' : '' )
				. '</section>';
		}

		$main .= HelpCenterParts::help_band( $chat_ready );

		return $this->with_side( $slug, $main, $tops );
	}

	/**
	 * Search results (Enter in the box, or without JavaScript).
	 *
	 * @param string $search     Search text.
	 * @param bool   $chat_ready The chat is on this page.
	 * @return string
	 */
	private function render_search( string $search, bool $chat_ready ): string {
		$list = KbPublicArticles::list( KbAudience::current(), $search, '', self::page_arg(), self::PER_PAGE );
		$tag  = $this->main_heading();

		$main  = self::breadcrumbs( array(), __( 'Search', 'doublescale' ) );
		$main .= '<header class="ds-hc__head ds-hc__head--plain"><div class="ds-hc__head-body"><' . $tag . ' class="ds-hc__page-title ds-hc__title" dir="auto">' . esc_html(
			sprintf(
				/* translators: %s: what the visitor searched for */
				__( 'Results for “%s”', 'doublescale' ),
				$search
			)
		) . '</' . $tag . '>';
		$main .= '<p class="ds-hc__muted">'
			/* translators: %d: number of articles */
			. esc_html( sprintf( _n( '%d article', '%d articles', (int) $list['total'], 'doublescale' ), (int) $list['total'] ) ) . '</p></div></header>';

		if ( array() === $list['items'] ) {
			$main .= '<p class="ds-hc__empty">' . esc_html__( 'No articles match your search. Try other words.', 'doublescale' ) . '</p>';
		} else {
			$main .= HelpCenterParts::article_rows( $list['items'], $search ) . $this->pagination( $list['total'] );
		}

		return $this->with_side( '', $main . HelpCenterParts::help_band( $chat_ready ) );
	}

	/**
	 * Article not found or not readable by this visitor.
	 *
	 * @return string
	 */
	private function render_missing(): string {
		$tag = $this->main_heading();

		return $this->with_side(
			'',
			self::breadcrumbs( array() )
			. '<' . $tag . ' class="ds-hc__page-title ds-hc__title">' . esc_html__( 'Article not found', 'doublescale' ) . '</' . $tag . '>'
			. '<p class="ds-hc__empty">' . esc_html__( 'This article does not exist or is not available to you.', 'doublescale' ) . '</p>'
		);
	}

	/**
	 * Help Center / … trail. The last item (the page itself) is plain text.
	 *
	 * @param array<int, array{0: string, 1: string}> $trail   Crumbs between (label, url).
	 * @param string                                  $current The page itself ('' for none).
	 * @return string
	 */
	public static function breadcrumbs( array $trail, string $current = '' ): string {
		$home  = KbPublicArticles::page_url() ?: get_permalink();
		$items = '<li><a href="' . esc_url( (string) $home ) . '">' . esc_html__( 'Help Center', 'doublescale' ) . '</a></li>';
		foreach ( $trail as $crumb ) {
			$items .= '<li><a href="' . esc_url( $crumb[1] ) . '" dir="auto">' . esc_html( $crumb[0] ) . '</a></li>';
		}
		if ( '' !== $current ) {
			$items .= '<li><span aria-current="page" dir="auto">' . esc_html( $current ) . '</span></li>';
		}

		return '<nav class="ds-hc__crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'doublescale' ) . '"><ol>' . $items . '</ol></nav>';
	}

	/**
	 * Compact title links (topic cards, related articles).
	 *
	 * @param KbArticleModel[] $articles Articles.
	 * @return string
	 */
	public static function article_links( array $articles ): string {
		$out = '<ul class="ds-hc__links">';
		foreach ( $articles as $article ) {
			$out .= '<li><a href="' . esc_url( KbPublicArticles::article_url( (string) $article->slug ) ) . '">' . HelpCenterParts::icon( 'doc', 16 ) . '<span dir="auto">' . esc_html( (string) $article->title ) . '</span></a></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Previous / next links when results span pages.
	 *
	 * @param int $total Total results.
	 * @return string
	 */
	private function pagination( int $total ): string {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return '';
		}

		$page = self::page_arg();
		$out  = '<nav class="ds-hc__pages" aria-label="' . esc_attr__( 'Pages', 'doublescale' ) . '">';
		if ( $page > 1 ) {
			$out .= '<a class="ds-hc__button" href="' . esc_url( add_query_arg( 'hc_page', $page - 1 ) ) . '">' . esc_html__( 'Previous', 'doublescale' ) . '</a>';
		}
		$out .= '<span class="ds-hc__muted">' . esc_html(
			sprintf(
				/* translators: 1: current page, 2: number of pages */
				__( 'Page %1$d of %2$d', 'doublescale' ),
				$page,
				$pages
			)
		) . '</span>';
		if ( $page < $pages ) {
			$out .= '<a class="ds-hc__button" href="' . esc_url( add_query_arg( 'hc_page', $page + 1 ) ) . '">' . esc_html__( 'Next', 'doublescale' ) . '</a>';
		}

		return $out . '</nav>';
	}

	/**
	 * A query-string value, unslashed and trimmed.
	 *
	 * @param string $key Parameter.
	 * @return string
	 */
	private static function query_arg( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public page view.
		$value = isset( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) ) : '';

		return 'q' === $key ? ArticleSearch::limit( $value ) : $value;
	}

	/**
	 * @return int
	 */
	private static function page_arg(): int {
		return max( 1, (int) self::query_arg( 'hc_page' ) );
	}
}
