<?php
/**
 * A help-center article's own page ({@see ArticlePages}).
 *
 * The theme prints the page like any other: its title as the h1 and the body
 * written in the block editor. This adds what makes it a help article, above
 * and below the body and outside it, so the body keeps the theme's styling:
 *   - above: "Help Center › Category", the date it was last updated and,
 *     with two or more headings, "On this page" linking to each
 *   - below: "Was this article helpful?" and related articles
 *
 * Who may open the page: WordPress already answers 404 for a draft, and for
 * a private (portal or internal) page to anyone {@see ArticlePages::map_read_cap()}
 * does not let in. This checks again against the article itself, so a page
 * whose article was hidden a moment ago never shows. Whoever may edit the
 * page can always open it, to preview.
 *
 * A page only some readers may open is never indexed.
 *
 * @since 1.0.0
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\UserRoles\UserRoles;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticlePages;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleService;
use DoubleScale\Modules\KnowledgeBase\Services\KbAudience;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

/**
 * ArticlePageHandler class.
 */
final class ArticlePageHandler {

	/**
	 * Article of the page this request shows, if any.
	 *
	 * @var KbArticleModel|null
	 */
	private $article = null;

	/**
	 * Wire the page hooks.
	 */
	public function __construct() {
		add_action( 'wp', array( $this, 'resolve_request' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'the_content', array( $this, 'filter_content_in_loop' ), 20 );
		// Before blocks (9) and shortcodes (11) run.
		add_filter( 'the_content', array( $this, 'strip_shortcodes_of_limited_authors' ), 8 );
		add_filter( 'render_block_core/post-title', array( $this, 'hide_page_title' ), 10, 3 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		add_action( 'wp_head', array( $this, 'print_meta_description' ), 1 );

		// Yoast SEO and Rank Math: the article's excerpt when the page has no
		// description of its own.
		add_filter( 'wpseo_metadesc', array( $this, 'filter_seo_description' ) );
		add_filter( 'rank_math/frontend/description', array( $this, 'filter_seo_description' ) );
	}

	/**
	 * @return void
	 */
	public function resolve_request(): void {
		if ( is_admin() || ! is_singular( ArticlePages::POST_TYPE ) ) {
			return;
		}

		$post    = get_queried_object();
		$article = $post instanceof \WP_Post ? ArticlePages::article_for_post( (int) $post->ID ) : null;

		if ( ! $article || ! self::may_read( $article, (int) $post->ID ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$this->article = $article;
	}

	/**
	 * Stylesheet, and the script that sends the vote and counts the view.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->article ) {
			return;
		}

		HelpCenterParts::keep_out_of_page_caches();

		$version = DOUBLESCALE_VERSION;

		HelpCenterParts::enqueue_ui();

		wp_register_script( 'doublescale-help-center', false, array(), $version, true );
		wp_enqueue_script( 'doublescale-help-center' );
		wp_add_inline_script(
			'doublescale-help-center',
			'window.doublescaleHelpCenter = ' . wp_json_encode(
				array(
					'root'    => esc_url_raw( rest_url( 'doublescale/v1/kb/public/articles/' . (int) $this->article->id ) ),
					// Signed-in readers send their nonce, so a portal-only article
					// they can read also takes their vote. Visitors send none.
					'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'thanks'  => __( 'Thanks for your feedback!', 'doublescale' ),
					'missing' => __( 'What was missing?', 'doublescale' ),
					'more'    => __( 'Tell us more (optional)', 'doublescale' ),
					'send'    => __( 'Send', 'doublescale' ),
					'skip'    => __( 'Skip', 'doublescale' ),
					'reasons' => array(
						__( 'Hard to follow', 'doublescale' ),
						__( 'Steps don’t match what I see', 'doublescale' ),
						__( 'Out of date', 'doublescale' ),
						__( 'Not what I needed', 'doublescale' ),
					),
				)
			) . ';' . (string) file_get_contents( __DIR__ . '/help-center.js' ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		);
	}

	/**
	 * `the_content` of the page's own post in the main loop only: not an
	 * excerpt elsewhere, not a widget, not another post on the page.
	 *
	 * @param string $content Rendered body.
	 * @return string
	 */
	public function filter_content_in_loop( $content ) {
		if ( ! $this->article || ! in_the_loop() || ! is_main_query() || (int) get_the_ID() !== (int) $this->article->post_id ) {
			return $content;
		}

		return $this->filter_content( (string) $content );
	}

	/**
	 * Shortcodes run code of the site's plugins with the author's text as
	 * input. An article written by someone who only holds a Help Center role
	 * (no `unfiltered_html`, no editor rights) shows its shortcodes as nothing;
	 * administrators, editors and everyone else keep theirs.
	 *
	 * @param string $content Body before blocks and shortcodes run.
	 * @return string
	 */
	public function strip_shortcodes_of_limited_authors( $content ) {
		if ( ! $this->article || ! in_the_loop() || ! is_main_query() || (int) get_the_ID() !== (int) $this->article->post_id ) {
			return $content;
		}
		if ( ! self::is_limited_author( (int) $this->article->author_id ) ) {
			return $content;
		}

		return strip_shortcodes( (string) $content );
	}

	/**
	 * Does the user write articles through a Help Center role only: no
	 * `unfiltered_html`, no right to edit other people's posts?
	 *
	 * @param int $user_id Author.
	 * @return bool
	 */
	public static function is_limited_author( int $user_id ): bool {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user || ! array_intersect( array( UserRoles::HELP_CENTER_MANAGER, UserRoles::HELP_CENTER_AGENT ), (array) $user->roles ) ) {
			return false;
		}

		return ! user_can( $user, 'unfiltered_html' ) && ! user_can( $user, 'edit_others_posts' );
	}

	/**
	 * The body with the help-center parts around it.
	 *
	 * @param string $content Rendered body.
	 * @return string
	 */
	public function filter_content( string $content ): string {
		if ( ! $this->article ) {
			return $content;
		}

		$article  = $this->article;
		$category = $article->category;
		$audience = KbAudience::current();
		$tops     = HelpCenterParts::tree( KbPublicArticles::categories( $audience ) );

		$contents = self::table_of_contents( $content );
		$content  = $contents['html'];

		// The articles of its category, in order: the list on the side and
		// previous / next.
		$siblings = $category ? KbPublicArticles::list( $audience, '', (string) $category->slug, 1, 50 )['items'] : array();
		$index    = null;
		foreach ( $siblings as $i => $other ) {
			if ( (int) $other->id === (int) $article->id ) {
				$index = $i;
			}
		}
		$nav_articles = array_map(
			static fn( $other ) => array( (string) $other->title, KbPublicArticles::article_url( (string) $other->slug ), (int) $other->id === (int) $article->id ),
			$siblings
		);

		$trail = array();
		if ( $category ) {
			foreach ( $tops as $top ) {
				foreach ( $top['children'] as $child ) {
					if ( (int) $child['id'] === (int) $category->id ) {
						$trail[] = array( (string) $top['name'], (string) $top['url'] );
					}
				}
			}
			$trail[] = array( (string) $category->name, KbPublicArticles::category_url( (string) $category->slug ) );
		}

		// Head: the title (block themes leave theirs out here), the summary
		// the author wrote, reading time, date, copy link.
		$head  = '' !== KbPublicArticles::page_url() ? HelpCenterHandler::breadcrumbs( $trail ) : '';
		$head .= '<header class="ds-hc__article-head">';
		if ( $this->owns_title() ) {
			$head .= '<h1 class="ds-hc__article-title" dir="auto">' . esc_html( (string) $article->title ) . '</h1>';
		}
		if ( '' !== trim( (string) $article->excerpt ) ) {
			$head .= '<p class="ds-hc__lead" dir="auto">' . esc_html( trim( (string) $article->excerpt ) ) . '</p>';
		}
		$minutes = HelpCenterParts::read_minutes( $article );
		$head   .= '<div class="ds-hc__article-meta">'
			. '<span>' . HelpCenterParts::icon( 'clock', 16 )
			/* translators: %d: minutes it takes to read the article */
			. esc_html( sprintf( _n( '%d min read', '%d min read', $minutes, 'doublescale' ), $minutes ) ) . '</span>'
			. '<span>'
			/* translators: %s: date the article was last updated */
			. esc_html( sprintf( __( 'Updated %s', 'doublescale' ), mysql2date( (string) get_option( 'date_format' ), (string) $article->updated_at ) ) ) . '</span>'
			. '<button type="button" class="ds-hc__button ds-hc__button--small" data-ds-hc-copy="' . esc_attr( KbPublicArticles::article_url( (string) $article->slug ) ) . '">' . HelpCenterParts::icon( 'link', 15 ) . '<span>' . esc_html__( 'Copy link', 'doublescale' ) . '</span></button>'
			. '</div></header>';

		// Below the body: was it helpful, previous / next, related.
		$below  = '<section class="ds-hc__feedback" data-ds-hc-feedback aria-label="' . esc_attr__( 'Feedback', 'doublescale' ) . '">';
		$below .= '<span class="ds-hc__feedback-q">' . esc_html__( 'Was this article helpful?', 'doublescale' ) . '</span>';
		$below .= '<button type="button" class="ds-hc__button" data-helpful="1">' . HelpCenterParts::icon( 'up', 18 ) . esc_html__( 'Yes', 'doublescale' ) . '</button>';
		$below .= '<button type="button" class="ds-hc__button" data-helpful="0">' . HelpCenterParts::icon( 'down-v', 18 ) . esc_html__( 'No', 'doublescale' ) . '</button>';
		$below .= '</section>';

		if ( null !== $index ) {
			$prev = $siblings[ $index - 1 ] ?? null;
			$next = $siblings[ $index + 1 ] ?? null;
			if ( $prev || $next ) {
				$below .= '<nav class="ds-hc__pager" aria-label="' . esc_attr__( 'Previous and next articles', 'doublescale' ) . '">';
				if ( $prev ) {
					$below .= '<a class="ds-hc__pager-link" href="' . esc_url( KbPublicArticles::article_url( (string) $prev->slug ) ) . '" rel="prev"><span class="ds-hc__muted">' . esc_html__( '← Previous', 'doublescale' ) . '</span><span class="ds-hc__pager-title" dir="auto">' . esc_html( (string) $prev->title ) . '</span></a>';
				}
				if ( $next ) {
					$below .= '<a class="ds-hc__pager-link ds-hc__pager-link--next" href="' . esc_url( KbPublicArticles::article_url( (string) $next->slug ) ) . '" rel="next"><span class="ds-hc__muted">' . esc_html__( 'Next →', 'doublescale' ) . '</span><span class="ds-hc__pager-title" dir="auto">' . esc_html( (string) $next->title ) . '</span></a>';
				}
				$below .= '</nav>';
			}
		}

		$related = array_values(
			array_filter(
				$siblings,
				static fn( $other ) => (int) $other->id !== (int) $article->id
			)
		);
		if ( array() !== $related ) {
			$below .= '<section class="ds-hc__related"><h2 class="ds-hc__h3">' . esc_html__( 'Related articles', 'doublescale' ) . '</h2>';
			$below .= HelpCenterHandler::article_links( array_slice( $related, 0, 5 ) ) . '</section>';
		}

		// A way to get help: the first choice (Pro's floating chat opens from it).
		$ready   = true;
		$options = HelpCenterParts::help_options();
		$stuck   = '';
		foreach ( $options as $option ) {
			$card = HelpCenterParts::help_option( $option, $ready );
			if ( '' !== $card ) {
				$stuck = '<div class="ds-hc__stuck"><p class="ds-hc__stuck-title">' . esc_html__( 'Still stuck?', 'doublescale' ) . '</p>'
					. ( '' !== (string) ( $option['text'] ?? '' ) ? '<p class="ds-hc__stuck-text">' . ( ! empty( $option['online'] ) ? '<i class="ds-hc__dot" aria-hidden="true"></i>' : '' ) . esc_html( (string) $option['text'] ) . '</p>' : '' )
					. $card . '</div>';
				break;
			}
		}

		$toc = '' !== $contents['toc']
			? '<details class="ds-hc__toc" open data-ds-hc-fold="toc"><summary class="ds-hc__fold">' . HelpCenterParts::icon( 'list', 18 ) . esc_html__( 'On this page', 'doublescale' ) . '</summary>' . $contents['toc'] . '</details>'
			: '';

		return '<div class="ds-hc ds-hc--article alignwide"><div class="ds-hc__layout ds-hc__layout--article">'
			. HelpCenterParts::topic_nav( $tops, $category ? (string) $category->slug : '', $nav_articles )
			// Before the text in the page (screen readers meet "On this page"
			// first); the grid shows it on the right on wide pages.
			. ( '' !== $toc || '' !== $stuck ? '<aside class="ds-hc__aside">' . $toc . $stuck . '</aside>' : '' )
			. '<div class="ds-hc__main">' . $head
			. '<div class="ds-hc__prose">' . $content . '</div>'
			. $below
			. ( '' !== $stuck ? '<div class="ds-hc__stuck-inline">' . $stuck . '</div>' : '' )
			. '</div>'
			. '</div></div>';
	}

	/**
	 * Block themes print the page's title above the body; the article draws
	 * its own (inside its column), so that title is left out on its page.
	 *
	 * @param string         $html     Rendered title block.
	 * @param array          $block    Block.
	 * @param \WP_Block|null $instance Block instance (context: postId).
	 * @return string
	 */
	public function hide_page_title( $html, $block = array(), $instance = null ) {
		if ( ! $this->article ) {
			return $html;
		}
		$post_id = $instance instanceof \WP_Block && isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;

		return $post_id === (int) $this->article->post_id ? '' : $html;
	}

	/**
	 * The article prints its own title: block themes (theirs is left out).
	 *
	 * @return bool
	 */
	private function owns_title(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * "In this article": a link to each h2 and h3, when there are two or more.
	 * Every heading gets an address (`id`) to link to, from its own words so a
	 * shared link reads well (`#طريقة-الدفع`); an address the author set is
	 * kept, and two headings never share one.
	 *
	 * @param string $html Rendered body.
	 * @return array{html:string,toc:string}
	 */
	public static function table_of_contents( string $html ): array {
		$entries = array();
		$taken   = array();

		$html = (string) preg_replace_callback(
			'#<h([23])(\s[^>]*)?>(.*?)</h\1>#is',
			static function ( array $m ) use ( &$entries, &$taken ): string {
				$attrs = $m[2] ?? '';
				$text  = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $m[3] ) ) );
				if ( '' === $text ) {
					return $m[0];
				}

				if ( preg_match( '/\sid\s*=\s*(["\'])(.*?)\1/i', $attrs, $id_match ) && '' !== $id_match[2] ) {
					$id = html_entity_decode( $id_match[2], ENT_QUOTES, 'UTF-8' );
				} else {
					$base = rawurldecode( sanitize_title( $text ) );
					$base = '' !== $base ? $base : 'section';
					$id   = $base;
					for ( $n = 2; isset( $taken[ $id ] ); $n++ ) {
						$id = $base . '-' . $n;
					}
					$attrs .= ' id="' . esc_attr( $id ) . '"';
				}
				$taken[ $id ] = true;
				$entries[]    = array( (int) $m[1], $id, $text );

				return '<h' . $m[1] . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
			},
			$html
		);

		if ( count( $entries ) < 2 ) {
			return array(
				'html' => $html,
				'toc'  => '',
			);
		}

		// Sub-headings sit in a list under their heading, so the numbers run
		// 1, 2, 3; one before any heading stands on its own.
		$tree = array();
		foreach ( $entries as $entry ) {
			if ( 3 === $entry[0] && array() !== $tree ) {
				$tree[ count( $tree ) - 1 ]['children'][] = $entry;
			} else {
				$tree[] = array(
					'entry'    => $entry,
					'children' => array(),
				);
			}
		}

		$link  = static fn( array $entry ): string => '<a href="#' . esc_attr( $entry[1] ) . '" dir="auto">' . esc_html( $entry[2] ) . '</a>';
		$items = '';
		foreach ( $tree as $node ) {
			$sub = '';
			foreach ( $node['children'] as $child ) {
				$sub .= '<li>' . $link( $child ) . '</li>';
			}
			$items .= '<li>' . $link( $node['entry'] ) . ( '' !== $sub ? '<ol class="ds-hc__toc-sub">' . $sub . '</ol>' : '' ) . '</li>';
		}

		return array(
			'html' => $html,
			'toc'  => '<nav class="ds-hc__toc-nav" aria-label="' . esc_attr__( 'On this page', 'doublescale' ) . '"><ol>' . $items . '</ol></nav>',
		);
	}

	/**
	 * Portal and internal pages are never indexed, whoever reads them.
	 *
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! $this->article ) {
			return $robots;
		}

		if ( KbArticleModel::VISIBILITY_PUBLIC !== $this->article->visibility || KbArticleModel::STATUS_PUBLISHED !== $this->article->status ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * SEO plugin description: theirs when the page has one, else the excerpt.
	 *
	 * @param string $description Description.
	 * @return string
	 */
	public function filter_seo_description( $description ) {
		if ( ! $this->article || '' !== trim( (string) $description ) ) {
			return $description;
		}

		return ArticleService::display_excerpt( $this->article );
	}

	/**
	 * `<meta name="description">` when no SEO plugin prints one.
	 *
	 * @return void
	 */
	public function print_meta_description(): void {
		if ( ! $this->article || defined( 'WPSEO_VERSION' ) || class_exists( '\RankMath' ) ) {
			return;
		}

		$description = ArticleService::display_excerpt( $this->article );
		if ( '' === $description ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}

	/**
	 * @param KbArticleModel $article Article.
	 * @param int            $post_id Its page.
	 * @return bool
	 */
	private static function may_read( KbArticleModel $article, int $post_id ): bool {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return KbArticleModel::STATUS_PUBLISHED === $article->status
			&& in_array( (string) $article->visibility, KbAudience::current(), true );
	}
}
