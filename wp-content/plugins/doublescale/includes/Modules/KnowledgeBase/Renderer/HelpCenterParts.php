<?php
/**
 * Pieces the help center page and the article pages share: icons, the topic
 * list on the side, article rows, reading time, the short summary of an
 * article, the "Can't find what you need?" options and the chat panel.
 *
 * Everything is plain HTML inside `.ds-hc`, styled by help-center.css; the
 * site's theme keeps its header, footer and fonts.
 *
 * @since 1.9.11
 * @package DoubleScale\Modules\KnowledgeBase
 */

namespace DoubleScale\Modules\KnowledgeBase\Renderer;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\KnowledgeBase\Models\KbArticleModel;
use DoubleScale\Modules\KnowledgeBase\Services\ArticleSearch;
use DoubleScale\Modules\KnowledgeBase\Services\KbPublicArticles;

/**
 * HelpCenterParts class.
 */
final class HelpCenterParts {

	/**
	 * Words read per minute, for "4 min read".
	 */
	private const WORDS_PER_MINUTE = 220;

	/**
	 * Words in an article row's summary.
	 */
	private const SUMMARY_WORDS = 28;

	/**
	 * Accent when the business has no brand colour (Settings → Business).
	 */
	public const DEFAULT_ACCENT = \DoubleScale\Core\Settings\BrandColor::DEFAULT;

	/**
	 * Dark text on a light brand colour (as the client portal).
	 */
	private const INK = '#121826';

	/**
	 * Icon paths (24×24, stroke).
	 *
	 * @var array<string, string>
	 */
	private const ICONS = array(
		'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
		'doc'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
		'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h13v4H6a2 2 0 0 1-2-2zM9 7h6"/>',
		'rocket'   => '<path d="M5 15c-1.5 1.5-2 4-2 6 2 0 4.5-.5 6-2"/><path d="M9 15l-3-3c1-3 3.5-7 9-9 2 0 4 0 6 2-2 5.5-6 8-9 9z"/><circle cx="15" cy="9" r="1.5"/>',
		'users'    => '<path d="M16 20v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-1a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
		'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'layout'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
		'lifebuoy' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m5.6 5.6 3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/>',
		'card'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
		'plug'     => '<path d="M9 2v6M15 2v6M6 8h12v3a6 6 0 0 1-12 0zM12 17v5"/>',
		'cog'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		'chevron'  => '<path d="m9 6 6 6-6 6"/>',
		'back'     => '<path d="m15 18-6-6 6-6"/>',
		'down'     => '<path d="m6 9 6 6 6-6"/>',
		'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
		'chat'     => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
		'sparkle'  => '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.5L12 15l-1.8-4.5L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .7-.8 2-.7-2-2-.7 2-.8z"/>',
		'ticket'   => '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 5v12"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'link'     => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
		'up'       => '<path d="M7 10v11M3 10h4v11H3zM7 10l4-7a2 2 0 0 1 3 2l-1 5h6a2 2 0 0 1 2 2.3l-1.4 7A2 2 0 0 1 17.6 21H7"/>',
		'down-v'   => '<path d="M17 14V3M21 14h-4V3h4zM17 14l-4 7a2 2 0 0 1-3-2l1-5H5a2 2 0 0 1-2-2.3l1.4-7A2 2 0 0 1 6.4 3H17"/>',
		'menu'     => '<path d="M4 6h16M4 12h16M4 18h10"/>',
		'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'close'    => '<path d="M18 6 6 18M6 6l12 12"/>',
	);

	/**
	 * Words in a category's name that pick its icon, in this order.
	 *
	 * @var array<string, string[]>
	 */
	private const ICON_WORDS = array(
		'rocket'   => array( 'start', 'install', 'setup', 'set up', 'begin', 'basics', 'onboard', 'ابدأ', 'البداية' ),
		'card'     => array( 'bill', 'pay', 'invoice', 'price', 'plan', 'subscription', 'refund', 'دفع', 'فاتور', 'اشتراك' ),
		'layout'   => array( 'portal', 'template', 'design', 'page', 'بوابة', 'قوالب' ),
		'users'    => array( 'contact', 'compan', 'customer', 'client', 'team', 'user', 'member', 'عملاء', 'جهات' ),
		'mail'     => array( 'email', 'mail', 'campaign', 'newsletter', 'sequence', 'بريد', 'حمل' ),
		'lifebuoy' => array( 'help', 'support', 'ticket', 'chat', 'desk', 'دعم', 'تذاكر' ),
		'calendar' => array( 'book', 'calendar', 'appointment', 'schedule', 'event', 'حجز', 'موعد' ),
		'shield'   => array( 'security', 'privacy', 'gdpr', 'account', 'login', 'password', 'أمان', 'خصوصية' ),
		'plug'     => array( 'integrat', 'connect', 'api', 'webhook', 'zapier', 'تكامل', 'ربط' ),
		'cog'      => array( 'setting', 'config', 'admin', 'إعداد' ),
	);

	/**
	 * An inline SVG icon (decorative: hidden from screen readers).
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixels.
	 * @return string
	 */
	public static function icon( string $name, int $size = 20 ): string {
		$paths = self::ICONS[ $name ] ?? self::ICONS['doc'];

		return '<svg class="ds-hc__icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
	}

	/**
	 * A category's icon: the one it was given, else one its name suggests.
	 *
	 * @param array<string, mixed> $category Category row (name, icon).
	 * @return string Icon name.
	 */
	public static function category_icon( array $category ): string {
		$given = sanitize_key( (string) ( $category['icon'] ?? '' ) );
		if ( '' !== $given && isset( self::ICONS[ $given ] ) ) {
			return $given;
		}

		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $category['name'] ?? '' ) ) : strtolower( (string) ( $category['name'] ?? '' ) );
		foreach ( self::ICON_WORDS as $icon => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $name, $word ) ) {
					return $icon;
				}
			}
		}

		return 'book';
	}

	/**
	 * Top-level categories with their sub-categories and the article counts
	 * of both together (`total`).
	 *
	 * @param array<int, array<string, mixed>> $categories Rows from {@see KbPublicArticles::categories()}.
	 * @return array<int, array<string, mixed>> Tops, each with `children` and `total`.
	 */
	public static function tree( array $categories ): array {
		$by_id = array();
		foreach ( $categories as $category ) {
			$by_id[ (int) $category['id'] ] = $category;
		}

		$tops = array();
		$kids = array();
		foreach ( $categories as $category ) {
			$parent = (int) ( $category['parent_id'] ?? 0 );
			// A parent nobody may read here (or a deeper chain) shows on top.
			if ( $parent && isset( $by_id[ $parent ] ) && empty( $by_id[ $parent ]['parent_id'] ) ) {
				$kids[ $parent ][] = $category;
			} else {
				$tops[] = $category;
			}
		}

		foreach ( $tops as &$top ) {
			$top['children'] = $kids[ (int) $top['id'] ] ?? array();
			$top['total']    = (int) $top['articles_count'] + array_sum( array_map( static fn( $c ) => (int) $c['articles_count'], $top['children'] ) );
		}
		unset( $top );

		return $tops;
	}

	/**
	 * The words of an article's body (or its summary), for reading time.
	 *
	 * @param KbArticleModel $article Article.
	 * @return int Minutes, at least 1.
	 */
	public static function read_minutes( KbArticleModel $article ): int {
		$text  = wp_strip_all_tags( (string) preg_replace( '/<[^>]*>/u', ' ', (string) $article->content ) );
		$words = count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

		return max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );
	}

	/**
	 * One line about an article: its own summary, else its first paragraph
	 * (never headings or lists run into the text).
	 *
	 * @param KbArticleModel $article Article.
	 * @return string Plain text.
	 */
	public static function summary( KbArticleModel $article ): string {
		$own = trim( (string) $article->excerpt );
		if ( '' !== $own ) {
			return wp_trim_words( $own, self::SUMMARY_WORDS, '…' );
		}

		$content = (string) $article->content;
		if ( preg_match_all( '#<p[^>]*>(.*?)</p>#is', $content, $m ) ) {
			foreach ( $m[1] as $paragraph ) {
				$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $paragraph ) ) );
				if ( '' !== $text ) {
					return wp_trim_words( $text, self::SUMMARY_WORDS, '…' );
				}
			}
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) preg_replace( '/<[^>]*>/u', ' ', $content ) ) ) );

		return wp_trim_words( $text, self::SUMMARY_WORDS, '…' );
	}

	/**
	 * "4 min read · Updated Nov 16, 2026".
	 *
	 * @param KbArticleModel $article Article.
	 * @return string Escaped HTML.
	 */
	public static function meta( KbArticleModel $article ): string {
		$minutes = self::read_minutes( $article );

		return esc_html(
			sprintf(
				/* translators: %d: minutes it takes to read the article */
				_n( '%d min read', '%d min read', $minutes, 'doublescale' ),
				$minutes
			)
		) . ' · ' . esc_html(
			sprintf(
				/* translators: %s: date the article was last updated */
				__( 'Updated %s', 'doublescale' ),
				mysql2date( (string) get_option( 'date_format' ), (string) $article->updated_at )
			)
		);
	}

	/**
	 * Article rows (title, summary, reading time), in a bordered list.
	 *
	 * @param KbArticleModel[] $articles Articles.
	 * @param string           $mark     Words to highlight (search), or ''.
	 * @return string
	 */
	public static function article_rows( array $articles, string $mark = '' ): string {
		$out = '<ul class="ds-hc__rows">';
		foreach ( $articles as $article ) {
			$title   = esc_html( (string) $article->title );
			$summary = esc_html( self::summary( $article ) );
			if ( '' !== $mark ) {
				$title   = self::highlight( $title, $mark );
				$summary = self::highlight( $summary, $mark );
			}
			$category = $article->category;

			$out .= '<li><a class="ds-hc__row" href="' . esc_url( KbPublicArticles::article_url( (string) $article->slug ) ) . '">'
				. '<span class="ds-hc__row-icon">' . self::icon( 'doc' ) . '</span>'
				. '<span class="ds-hc__row-body">'
				. '<span class="ds-hc__row-title" dir="auto">' . $title . '</span>'
				. ( '' !== $summary ? '<span class="ds-hc__row-text" dir="auto">' . $summary . '</span>' : '' )
				. '<span class="ds-hc__row-meta">' . ( '' !== $mark && $category ? esc_html( (string) $category->name ) . ' · ' : '' ) . self::meta( $article ) . '</span>'
				. '</span>'
				. '<span class="ds-hc__row-go">' . self::icon( 'chevron', 18 ) . '</span>'
				. '</a></li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Wrap each searched word in <mark> (already escaped text).
	 *
	 * @param string $html Escaped text.
	 * @param string $q    Search words.
	 * @return string
	 */
	public static function highlight( string $html, string $q ): string {
		$words = array_filter( preg_split( '/\s+/u', ArticleSearch::limit( $q ) ) ?: array(), static fn( $w ) => mb_strlen( $w ) > 1 );
		if ( array() === $words ) {
			return $html;
		}
		// A word never matches inside an entity (&amp; &#8217;): "amp" must not
		// split "&amp;".
		$pattern = '/&[A-Za-z0-9#]+;(*SKIP)(*FAIL)|(' . implode( '|', array_map( static fn( $w ) => preg_quote( esc_html( $w ), '/' ), $words ) ) . ')/iu';

		return (string) preg_replace( $pattern, '<mark>$1</mark>', $html );
	}

	/**
	 * The search box: a real form (works without JavaScript); the script
	 * opens the search panel from it.
	 *
	 * @param string $value Current search.
	 * @param string $size  'hero' or 'side'.
	 * @return string
	 */
	public static function search_form( string $value, string $size = 'hero' ): string {
		$action = KbPublicArticles::page_url() ?: get_permalink();
		$id     = 'ds-hc-q-' . $size;

		return '<form class="ds-hc__search ds-hc__search--' . esc_attr( $size ) . '" role="search" method="get" action="' . esc_url( (string) $action ) . '">'
			. '<label class="ds-hc__sr" for="' . esc_attr( $id ) . '">' . esc_html__( 'Search the knowledge base', 'doublescale' ) . '</label>'
			. self::icon( 'search', 'hero' === $size ? 20 : 16 )
			. '<input id="' . esc_attr( $id ) . '" type="search" name="q" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( 'hero' === $size ? __( 'Search for answers…', 'doublescale' ) : __( 'Search…', 'doublescale' ) ) . '" autocomplete="off" data-ds-hc-search />'
			. '<kbd class="ds-hc__kbd" aria-hidden="true">⌘K</kbd>'
			. '<button type="submit" class="ds-hc__sr">' . esc_html__( 'Search', 'doublescale' ) . '</button>'
			. '</form>';
	}

	/**
	 * The topic list beside a category or an article. On phones it folds
	 * into a "Topics" button (the script closes it there).
	 *
	 * @param array<int, array<string, mixed>> $tops        From {@see self::tree()}.
	 * @param string                           $current     Slug of the open category ('' for none).
	 * @param array<int, array{0:string,1:string,2:bool}> $articles Articles listed under the open category (title, url, current).
	 * @return string
	 */
	public static function topic_nav( array $tops, string $current, array $articles = array() ): string {
		$home = KbPublicArticles::page_url() ?: get_permalink();

		$items = '<li><a class="ds-hc__nav-back" href="' . esc_url( (string) $home ) . '">' . self::icon( 'back', 16 ) . esc_html__( 'All topics', 'doublescale' ) . '</a></li>';
		foreach ( $tops as $top ) {
			$open      = (string) $top['slug'] === $current || in_array( $current, array_map( static fn( $c ) => (string) $c['slug'], $top['children'] ), true );
			$is_active = (string) $top['slug'] === $current && array() === $articles;
			$items    .= '<li' . ( $open ? ' class="is-open"' : '' ) . '>'
				. '<a class="ds-hc__nav-link' . ( $is_active ? ' is-current' : '' ) . '" href="' . esc_url( (string) $top['url'] ) . '"' . ( $is_active ? ' aria-current="page"' : '' ) . '>'
				. '<span dir="auto">' . esc_html( (string) $top['name'] ) . '</span><span class="ds-hc__count">' . (int) $top['total'] . '</span></a>';

			if ( $open && ( array() !== $top['children'] || array() !== $articles ) ) {
				$items .= '<ul class="ds-hc__nav-sub">';
				foreach ( $articles as $article ) {
					$items .= '<li><a class="ds-hc__nav-link' . ( $article[2] ? ' is-current' : '' ) . '" href="' . esc_url( $article[1] ) . '"' . ( $article[2] ? ' aria-current="page"' : '' ) . '><span dir="auto">' . esc_html( $article[0] ) . '</span></a></li>';
				}
				foreach ( $top['children'] as $child ) {
					$child_active = (string) $child['slug'] === $current;
					$items       .= '<li><a class="ds-hc__nav-link' . ( $child_active ? ' is-current' : '' ) . '" href="' . esc_url( (string) $child['url'] ) . '"' . ( $child_active ? ' aria-current="page"' : '' ) . '><span dir="auto">' . esc_html( (string) $child['name'] ) . '</span><span class="ds-hc__count">' . (int) $child['articles_count'] . '</span></a></li>';
				}
				$items .= '</ul>';
			}
			$items .= '</li>';
		}

		return '<details class="ds-hc__side" open data-ds-hc-fold>'
			. '<summary class="ds-hc__fold">' . self::icon( 'menu', 18 ) . esc_html__( 'Topics', 'doublescale' ) . '</summary>'
			. '<div class="ds-hc__side-body">'
			. self::search_form( '', 'side' )
			. '<nav class="ds-hc__nav" aria-label="' . esc_attr__( 'Help topics', 'doublescale' ) . '"><ul>' . $items . '</ul></nav>'
			. '</div></details>';
	}

	/**
	 * "Can't find what you need?" choices. Free offers a ticket when a
	 * support page exists; Pro adds the AI assistant and live chat.
	 *
	 * Each option: key, title, text, icon, style (primary|soft|plain) and
	 * either href or action ('chat' opens Pro's floating chat).
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function help_options(): array {
		$options = array();

		$support_page = class_exists( ShortcodePageProvisioner::class ) ? ShortcodePageProvisioner::resolve_page_id( 'doublescale_support_portal' ) : 0;
		$support_on   = function_exists( 'doublescale_is_module_active' ) && doublescale_is_module_active( 'support' );
		if ( $support_on && self::ticket_form() ) {
			// No account needed: the form opens here (Helpdesk → Settings →
			// Help center tickets).
			$options[] = array(
				'key'    => 'ticket',
				'title'  => __( 'Open a ticket', 'doublescale' ),
				'text'   => __( 'Send us your question, we reply by email', 'doublescale' ),
				'icon'   => 'ticket',
				'style'  => 'plain',
				'action' => 'ticket',
			);
		} elseif ( $support_on ) {
			// Signed-in tickets: the client portal's Tickets tab when the
			// portal is on, else the support portal page.
			$href = self::portal_tickets_url();
			if ( '' === $href && $support_page > 0 ) {
				$href = (string) get_permalink( $support_page );
			}
			if ( '' !== $href ) {
				$options[] = array(
					'key'   => 'ticket',
					'title' => __( 'Open a ticket', 'doublescale' ),
					'text'  => __( 'We reply by email and you can follow it here', 'doublescale' ),
					'icon'  => 'ticket',
					'style' => 'plain',
					'href'  => $href,
				);
			}
		}

		/**
		 * The "Can't find what you need?" choices on the help center.
		 *
		 * @param array<int, array<string, string>> $options key, title, text, icon, style, href|action.
		 */
		$options = (array) apply_filters( 'doublescale_help_center_help_options', $options );

		return array_values(
			array_filter(
				$options,
				static fn( $o ) => is_array( $o ) && '' !== (string) ( $o['title'] ?? '' ) && ( '' !== (string) ( $o['href'] ?? '' ) || in_array( $o['action'] ?? '', array( 'chat', 'ticket' ), true ) )
			)
		);
	}

	/**
	 * The client portal's Tickets tab, or '' while the Client Portal module is
	 * off or has no page. Visitors who aren't signed in see the portal's
	 * sign-in there first.
	 *
	 * @return string
	 */
	public static function portal_tickets_url(): string {
		$portal = '\\DoubleScale\\Modules\\Portal\\Services\\PortalUrl';
		if ( ! function_exists( 'doublescale_is_module_active' ) || ! doublescale_is_module_active( 'portal' ) || ! class_exists( $portal ) ) {
			return '';
		}

		return (string) $portal::get_route_url( 'tickets' );
	}

	/**
	 * Visitors who aren't signed in open a ticket with the help center's own
	 * form ({@see \DoubleScale\Modules\Support\Services\PublicTickets}).
	 * Signed-in customers keep the support portal.
	 *
	 * @return bool
	 */
	public static function ticket_form(): bool {
		$class = '\\DoubleScale\\Modules\\Support\\Services\\PublicTickets';

		return ! is_user_logged_in() && class_exists( $class ) && $class::open_to_visitors();
	}

	/**
	 * What the ticket form needs: where it posts, the trap field's name, and
	 * its words.
	 *
	 * @return array<string, mixed>
	 */
	private static function ticket_config(): array {
		$class = '\\DoubleScale\\Modules\\Support\\Services\\PublicTickets';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a message.
		$expired = isset( $_GET[ $class::EXPIRED_ARG ] );

		return array(
			'url'     => esc_url_raw( rest_url( 'doublescale/v1/support/public/tickets' ) ),
			'trap'    => $class::TRAP_FIELD,
			'elapsed' => $class::ELAPSED_FIELD,
			'expired' => $expired,
			'i18n'    => array(
				'title'      => __( 'Open a ticket', 'doublescale' ),
				'intro'      => __( 'Tell us what you need and we’ll reply by email. No account needed.', 'doublescale' ),
				'name'       => __( 'Your name', 'doublescale' ),
				'email'      => __( 'Email', 'doublescale' ),
				'subject'    => __( 'Subject', 'doublescale' ),
				'message'    => __( 'How can we help?', 'doublescale' ),
				'trap'       => __( 'Leave this empty', 'doublescale' ),
				'send'       => __( 'Send', 'doublescale' ),
				'sending'    => __( 'Sending…', 'doublescale' ),
				'cancel'     => __( 'Cancel', 'doublescale' ),
				'close'      => __( 'Close', 'doublescale' ),
				'sent'       => __( 'We’ve got your request', 'doublescale' ),
				/* translators: %s: email address */
				'sentText'   => __( 'We’ll reply to %s. Our email has the link to follow your ticket.', 'doublescale' ),
				'check'      => __( 'Check your email', 'doublescale' ),
				/* translators: %s: email address */
				'checkText'  => __( 'We sent a link to %s. Open it to send us your request; it works for 48 hours.', 'doublescale' ),
				'expiredMsg' => __( 'That link has expired or was already used. Please send your request again.', 'doublescale' ),
				'error'      => __( 'Your request could not be sent. Please try again.', 'doublescale' ),
			),
		);
	}

	/**
	 * One help option as a link or a button.
	 *
	 * @param array<string, string> $option Option.
	 * @param bool                  $chat_ready Pro's chat is on this page.
	 * @return string
	 */
	public static function help_option( array $option, bool $chat_ready ): string {
		$style = in_array( $option['style'] ?? '', array( 'primary', 'soft', 'plain' ), true ) ? $option['style'] : 'plain';
		$inner = '<span class="ds-hc__help-icon ds-hc__help-icon--' . esc_attr( $style ) . '">' . self::icon( (string) ( $option['icon'] ?? 'chat' ) ) . '</span>'
			. '<span class="ds-hc__help-body"><span class="ds-hc__help-title">' . esc_html( (string) $option['title'] ) . '</span>'
			. ( '' !== (string) ( $option['text'] ?? '' ) ? '<span class="ds-hc__help-text">' . ( ! empty( $option['online'] ) ? '<i class="ds-hc__dot" aria-hidden="true"></i>' : '' ) . esc_html( (string) $option['text'] ) . '</span>' : '' )
			. '</span>';

		if ( 'chat' === ( $option['action'] ?? '' ) ) {
			if ( ! $chat_ready ) {
				return '';
			}
			return '<button type="button" class="ds-hc__help" data-ds-hc-open-chat>' . $inner . '</button>';
		}
		if ( 'ticket' === ( $option['action'] ?? '' ) ) {
			return '<button type="button" class="ds-hc__help" data-ds-hc-open-ticket>' . $inner . '</button>';
		}

		return '<a class="ds-hc__help" href="' . esc_url( (string) $option['href'] ) . '">' . $inner . '</a>';
	}

	/**
	 * The "Can't find what you need?" section (empty without options).
	 *
	 * @param bool $chat_ready Pro's chat is on this page.
	 * @return string
	 */
	public static function help_band( bool $chat_ready ): string {
		$cards = '';
		foreach ( self::help_options() as $option ) {
			$cards .= self::help_option( $option, $chat_ready );
		}
		if ( '' === $cards ) {
			return '';
		}

		return '<section class="ds-hc__band" aria-labelledby="ds-hc-band-title">'
			. '<div class="ds-hc__band-head"><h2 class="ds-hc__h2" id="ds-hc-band-title">' . esc_html__( 'Can’t find what you need?', 'doublescale' ) . '</h2>'
			. '<p class="ds-hc__muted">' . esc_html__( 'We’re here to help.', 'doublescale' ) . '</p></div>'
			. '<div class="ds-hc__help-grid">' . $cards . '</div></section>';
	}

	/**
	 * A signed-in reader's page prints their REST nonce: no page cache may
	 * keep it for the next visitor.
	 *
	 * @return void
	 */
	public static function keep_out_of_page_caches(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/**
	 * The stylesheet and the shared script (search panel, chat panel, folds,
	 * copy link, "On this page" following the scroll). Once per page.
	 *
	 * @return void
	 */
	public static function enqueue_ui(): void {
		if ( wp_script_is( 'doublescale-help-center-ui', 'enqueued' ) ) {
			return;
		}
		$version = defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : false;

		wp_register_style( 'doublescale-help-center', false, array(), $version );
		wp_enqueue_style( 'doublescale-help-center' );
		wp_add_inline_style( 'doublescale-help-center', (string) file_get_contents( __DIR__ . '/help-center.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		// The brand colour, after the stylesheet so it wins.
		wp_add_inline_style( 'doublescale-help-center', self::accent_css() );

		$topics = array();
		foreach ( self::tree( KbPublicArticles::categories( \DoubleScale\Modules\KnowledgeBase\Services\KbAudience::current() ) ) as $top ) {
			$topics[] = array(
				'name'  => (string) $top['name'],
				'url'   => (string) $top['url'],
				'count' => (int) $top['total'],
			);
			foreach ( $top['children'] as $child ) {
				$topics[] = array(
					'name'  => (string) $child['name'],
					'url'   => (string) $child['url'],
					'count' => (int) $child['articles_count'],
				);
			}
		}
		// Pro's assistant answers here: the search offers "Ask the assistant".
		$ask = false;
		foreach ( self::help_options() as $option ) {
			$ask = $ask || 'assistant' === ( $option['key'] ?? '' );
		}

		self::keep_out_of_page_caches();

		wp_register_script( 'doublescale-help-center-ui', false, array(), $version, true );
		wp_enqueue_script( 'doublescale-help-center-ui' );
		wp_add_inline_script(
			'doublescale-help-center-ui',
			'window.doublescaleHelpUi = ' . wp_json_encode(
				array(
					'root'     => esc_url_raw( rest_url( 'doublescale/v1/kb/public/articles' ) ),
					// Signed-in readers send their nonce, so a client also finds
					// portal articles. Visitors send none.
					'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'page'     => KbPublicArticles::page_url(),
					'topics'   => $topics,
					'ask'      => $ask,
					// The ticket form for visitors with no account (or false).
					'ticket'   => self::ticket_form() ? self::ticket_config() : false,
					'i18n'     => array(
						'label'    => __( 'Search the knowledge base', 'doublescale' ),
						'articles' => __( 'Articles', 'doublescale' ),
						'topics'   => __( 'Topics', 'doublescale' ),
						'none'     => __( 'No articles match your search. Try other words.', 'doublescale' ),
						/* translators: %s: what the visitor searched for */
						'all'      => __( 'See all results for “%s”', 'doublescale' ),
						/* translators: %s: what the visitor searched for */
						'ask'      => __( 'Ask the assistant about “%s”', 'doublescale' ),
						'askText'  => __( 'A short answer from these articles, with links to the steps.', 'doublescale' ),
						'move'     => __( 'to move', 'doublescale' ),
						'open'     => __( 'to open', 'doublescale' ),
						'close'    => __( 'Close', 'doublescale' ),
						'copied'   => __( 'Link copied', 'doublescale' ),
						/* translators: %d: number of articles found */
						'found'    => __( 'Articles found: %d', 'doublescale' ),
						/* translators: %d: number of articles in a topic */
						'count'    => __( '%d articles', 'doublescale' ),
					),
				), JSON_HEX_TAG | JSON_HEX_AMP
			) . ';' . (string) file_get_contents( __DIR__ . '/help-center-ui.js' ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled file.
		);
	}

	/**
	 * The help center's accent: the business's brand colour (Settings →
	 * Business), like the client portal.
	 *
	 * @return string `#RRGGBB`.
	 */
	public static function accent(): string {
		/**
		 * The help center's accent colour (links, buttons, the current item).
		 *
		 * @param string $color The brand colour from Settings → Business.
		 */
		$color = \DoubleScale\Core\Settings\BrandColor::sanitize( apply_filters( 'doublescale_help_center_accent', \DoubleScale\Core\Settings\BrandColor::get() ) );

		return $color ?? \DoubleScale\Core\Settings\BrandColor::get();
	}

	/**
	 * The accent's colours for the stylesheet, on the help center and its
	 * search panel: the accent, its soft tint, the text on it (white, or
	 * dark on a light brand), the accent as text on white, darkened until it
	 * reads (contrast 4.5), so any brand colour stays readable, and a faint
	 * tint for the search area's background.
	 *
	 * @return string CSS.
	 */
	public static function accent_css(): string {
		$accent  = self::accent();
		$rgb     = self::rgb( $accent );
		$on      = self::contrast( $accent, '#FFFFFF' ) >= 3 ? '#FFFFFF' : self::INK;
		$as_text = $accent;
		$step    = 0.05;
		while ( $step <= 1 && self::contrast( $as_text, '#FFFFFF' ) < 4.5 ) {
			$as_text = self::mix( $accent, '#000000', $step );
			$step   += 0.05;
		}

		return sprintf(
			'.ds-hc,.ds-hc-palette{--ds-hc-accent:%1$s;--ds-hc-accent-soft:rgba(%2$d,%3$d,%4$d,0.1);--ds-hc-on-accent:%5$s;--ds-hc-accent-text:%6$s;--ds-hc-surface:%7$s;}',
			$accent,
			$rgb[0],
			$rgb[1],
			$rgb[2],
			$on,
			$as_text,
			// The search area's background: a faint tint of the brand.
			self::mix( $accent, '#FFFFFF', 0.96 )
		);
	}

	/**
	 * @param string $hex `#RRGGBB`.
	 * @return int[] r, g, b.
	 */
	private static function rgb( string $hex ): array {
		return array( (int) hexdec( substr( $hex, 1, 2 ) ), (int) hexdec( substr( $hex, 3, 2 ) ), (int) hexdec( substr( $hex, 5, 2 ) ) );
	}

	/**
	 * Blend $a towards $b by $t (0 = a, 1 = b).
	 *
	 * @param string $a `#RRGGBB`.
	 * @param string $b `#RRGGBB`.
	 * @param float  $t Amount.
	 * @return string
	 */
	private static function mix( string $a, string $b, float $t ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		$o = '#';
		foreach ( array( 0, 1, 2 ) as $i ) {
			$o .= sprintf( '%02X', (int) round( max( 0, min( 255, $x[ $i ] + ( $y[ $i ] - $x[ $i ] ) * $t ) ) ) );
		}

		return $o;
	}

	/**
	 * WCAG contrast ratio (1–21).
	 *
	 * @param string $a `#RRGGBB`.
	 * @param string $b `#RRGGBB`.
	 * @return float
	 */
	private static function contrast( string $a, string $b ): float {
		$lum = static function ( string $hex ): float {
			$c = array_map(
				static function ( $v ) {
					$v = $v / 255;
					return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
				},
				self::rgb( $hex )
			);
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$l   = array( $lum( $a ), $lum( $b ) );

		return ( max( $l ) + 0.05 ) / ( min( $l ) + 0.05 );
	}
}
