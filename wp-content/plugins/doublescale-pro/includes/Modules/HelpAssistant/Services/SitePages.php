<?php
/**
 * The assistant learns from the site's own pages (Intercom website sync,
 * Chatway "pages the AI may use").
 *
 * Off until the owner turns it on (Help Assistant → Assistant). Pages by
 * default, other public types when chosen; a page can be left out one by one
 * and the newest MAX pages are learned (filter doublescale_help_site_pages_max).
 *
 * Only what any visitor may read ({@see self::open()}): published, no
 * password, a chosen public type, not left out, not one of the site's
 * DoubleScale or shop screens, not locked (DoubleScale Memberships, or any
 * plugin through doublescale_help_site_page_open). The same check runs again
 * at answer time (Retriever), so a page closed after it was learned is never
 * used, whatever closed it.
 *
 * Passages sit in kb_chunks with source 'page' and post_id (article_id 0):
 * the article queries join articles and never see them.
 *
 * @package DoubleScale\Pro\Modules\HelpAssistant
 */

namespace DoubleScale\Pro\Modules\HelpAssistant\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\KnowledgeBase\Services\TextNormalizer;
use DoubleScale\Pro\Modules\HelpAssistant\Migrations\KbChunksTable;

/**
 * SitePages class.
 */
final class SitePages {

	public const SOURCE = 'page';

	/**
	 * Pages learned at most (the newest), like Intercom's per-source limit.
	 */
	public const MAX = 500;

	/**
	 * Background: learn every page (after the owner turns it on or changes it).
	 */
	public const BACKFILL_HOOK = 'doublescale_help_site_pages_backfill';

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'on_saved' ), 20, 2 );
		add_action( 'deleted_post', array( self::class, 'forget' ), 10, 1 );
		add_action( 'trashed_post', array( self::class, 'forget' ), 10, 1 );
		add_action( 'update_option_' . HelpSettings::OPTION, array( self::class, 'on_settings_changed' ), 10, 2 );
		add_action( self::BACKFILL_HOOK, array( self::class, 'backfill' ) );
		// A page added to (or taken out of) a menu may now be reached, or not.
		add_action( 'wp_update_nav_menu', array( self::class, 'queue_backfill' ) );
	}

	/**
	 * @return array{enabled: bool, post_types: string[], excluded: int[]}
	 */
	public static function settings(): array {
		$settings = HelpSettings::get();

		return $settings['site_pages'];
	}

	/**
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::settings()['enabled'];
	}

	/**
	 * Any visitor may read it now, and the assistant may use it.
	 *
	 * @param int $post_id Post.
	 * @return bool
	 */
	public static function open( int $post_id, bool $when_learning = false ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post && null === self::reason( $post, $when_learning );
	}

	/**
	 * Why the assistant may not use this page (null: it may). Shown to the
	 * owner beside each page.
	 *
	 *   off, not_published, password, type, turned_off, screen, store, author,
	 *   search_engines, hidden_product, members_only, not_linked
	 *
	 * Only not_linked can be lifted by the owner (settings included).
	 *
	 * @param \WP_Post $post          Post.
	 * @param bool     $when_learning Also ask whether the site links to it (reads the whole site).
	 * @return string|null
	 */
	public static function reason( \WP_Post $post, bool $when_learning = false ): ?string {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return 'off';
		}
		if ( 'publish' !== $post->post_status ) {
			return 'not_published';
		}
		if ( '' !== (string) $post->post_password ) {
			return 'password';
		}
		if ( ! in_array( $post->post_type, $settings['post_types'], true ) || ! is_post_type_viewable( $post->post_type ) ) {
			return 'type';
		}
		if ( in_array( (int) $post->ID, $settings['excluded'], true ) ) {
			return 'turned_off';
		}
		$screen = self::screen( $post );
		if ( null !== $screen ) {
			return $screen;
		}
		if ( ! self::trusted_author( $post ) ) {
			return 'author';
		}
		$unlisted = self::unlisted( $post );
		if ( null !== $unlisted ) {
			return $unlisted;
		}

		/**
		 * Whether a visitor who is not signed in may read this page (a
		 * membership or paywall plugin says no for a locked page). Asked when
		 * the page is learned and again at every answer.
		 *
		 * @param bool $open    Open to anyone.
		 * @param int  $post_id Post.
		 */
		if ( ! (bool) apply_filters( 'doublescale_help_site_page_open', ! self::locked_by_memberships( $post ) && ! self::locked_by_other_plugins( $post ), (int) $post->ID ) ) {
			return 'members_only';
		}
		// Published but reached from nowhere on the site (a "thank you for
		// buying" page with the download link) - unless the owner turned it on.
		if ( $when_learning && ! in_array( (int) $post->ID, $settings['included'], true ) && ! self::linked( $post ) ) {
			return 'not_linked';
		}

		return null;
	}

	/**
	 * One of the site's DoubleScale screens (portal, help center, invoice,
	 * booking…): 'screen'; a store's cart, checkout or account page: 'store'.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	private static function screen( \WP_Post $post ): ?string {
		$content = (string) $post->post_content;
		if ( false !== stripos( $content, '[doublescale' ) ) {
			return 'screen';
		}
		// Any store's blocks or shortcodes, or a page a store keeps.
		if ( preg_match( '#\[(?:woocommerce_|surecart|sc_|fluent_cart|edd_|purchase_history|download_checkout)|<!--\s*wp:(?:woocommerce|surecart|fluent-cart|edd)/#i', $content ) ) {
			return 'store';
		}

		return in_array( (int) $post->ID, self::store_page_ids(), true ) ? 'store' : null;
	}

	/**
	 * The pages stores keep for carts, checkouts and accounts, read from their
	 * settings: known even while the store plugin is off (its pages stay
	 * published).
	 *
	 * @return int[]
	 */
	private static function store_page_ids(): array {
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount', 'terms' ) as $woo ) {
			$ids[] = (int) get_option( 'woocommerce_' . $woo . '_page_id' );
		}
		foreach ( array( 'checkout', 'dashboard', 'order_confirmation', 'cart' ) as $surecart ) {
			$ids[] = (int) get_option( 'surecart_' . $surecart . '_page_id' );
		}
		$fluent = get_option( 'fluent_cart_store_settings' );
		if ( is_array( $fluent ) ) {
			foreach ( array( 'checkout_page_id', 'custom_payment_page_id', 'registration_page_id', 'login_page_id', 'cart_page_id', 'receipt_page_id', 'order_review_page_id', 'customer_profile_page_id' ) as $key ) {
				$ids[] = (int) ( $fluent[ $key ] ?? 0 );
			}
		}
		$edd = get_option( 'edd_settings' );
		if ( is_array( $edd ) ) {
			foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page', 'confirmation_page' ) as $key ) {
				$ids[] = (int) ( $edd[ $key ] ?? 0 );
			}
		}

		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * Written by someone who may edit everyone's content (an editor or
	 * higher): a writer who can publish their own posts does not speak for
	 * the site, and such accounts are the easier ones to take over.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function trusted_author( \WP_Post $post ): bool {
		$author = (int) $post->post_author;

		return $author > 0 && user_can( $author, 'edit_others_posts' ) && user_can( $author, 'edit_others_pages' );
	}

	/**
	 * Kept away from search engines (Yoast, Rank Math, SEOPress, All in One
	 * SEO) or hidden from the shop's catalog or search (WooCommerce).
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null search_engines or hidden_product.
	 */
	private static function unlisted( \WP_Post $post ): ?string {
		$id = (int) $post->ID;
		if ( '1' === (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true )
			|| 'yes' === (string) get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return 'search_engines';
		}
		$rank = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rank ) && in_array( 'noindex', $rank, true ) ) {
			return 'search_engines';
		}
		if ( function_exists( 'aioseo' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'aioseo_posts';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT robots_noindex FROM {$table} WHERE post_id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return 'search_engines';
			}
		}
		if ( 'product' === $post->post_type && taxonomy_exists( 'product_visibility' ) ) {
			$hidden = wp_get_object_terms( $id, 'product_visibility', array( 'fields' => 'slugs' ) );
			if ( is_array( $hidden ) && array_intersect( $hidden, array( 'exclude-from-catalog', 'exclude-from-search' ) ) ) {
				return 'hidden_product';
			}
		}

		return null;
	}

	/**
	 * A page something on the site leads to: the front page or blog page, a
	 * menu item, or a link in published content (pages, posts, block theme
	 * headers and footers, navigation menus, widgets, Elementor layouts).
	 * Posts and products are listed by the blog and the shop. Asked when a
	 * page is learned (a menu change learns the pages again), not at every
	 * answer: it reads the whole site.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function linked( \WP_Post $post ): bool {
		if ( 'page' !== $post->post_type ) {
			return true;
		}
		$id = (int) $post->ID;
		if ( in_array( $id, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
			return true;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery
		$in_menu = (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->postmeta} t ON t.post_id = m.post_id AND t.meta_key = '_menu_item_object'
				WHERE m.meta_key = '_menu_item_object_id' AND m.meta_value = %s AND t.meta_value = %s LIMIT 1",
				(string) $id,
				$post->post_type
			)
		);
		$linked = $in_menu;
		if ( ! $linked ) {
			$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
			$needles = array_filter( array( '' !== trim( $path, '/' ) ? untrailingslashit( $path ) : '', 'page_id=' . $id, '"id":' . $id ) );
			foreach ( $needles as $needle ) {
				$like   = '%' . $wpdb->esc_like( $needle ) . '%';
				$linked = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE ID <> %d AND post_status = 'publish' AND post_content LIKE %s LIMIT 1", $id, $like ) )
					|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.post_id <> %d AND p.post_status = 'publish' AND m.meta_key = '_elementor_data' AND m.meta_value LIKE %s LIMIT 1", $id, $like ) )
					|| (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%%' AND option_value LIKE %s LIMIT 1", $like ) );
				if ( $linked ) {
					break;
				}
			}
		}
		// phpcs:enable

		return $linked;
	}

	/**
	 * Locked by another membership plugin for a visitor who is not signed in
	 * (Paid Memberships Pro, MemberPress, Restrict Content Pro, WooCommerce
	 * Memberships, Members). Cannot tell: locked.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function locked_by_other_plugins( \WP_Post $post ): bool {
		$id = (int) $post->ID;
		try {
			if ( function_exists( 'pmpro_has_membership_access' ) ) {
				$access = pmpro_has_membership_access( $id, 0 );
				if ( ! ( is_array( $access ) ? $access[0] : $access ) ) {
					return true;
				}
			}
			if ( class_exists( '\MeprRule' ) && method_exists( '\MeprRule', 'is_locked' ) && \MeprRule::is_locked( $post ) ) {
				return true;
			}
			if ( function_exists( 'rcp_is_restricted_content' ) && rcp_is_restricted_content( $id ) ) {
				return true;
			}
			if ( function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted( $id ) ) {
				return true;
			}
			if ( function_exists( 'members_can_user_view_post' ) && ! members_can_user_view_post( 0, $id ) ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			return true;
		}

		return false;
	}

	/**
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function locked_by_memberships( \WP_Post $post ): bool {
		$gate = '\DoubleScale\Memberships\Services\AccessGate';
		if ( ! class_exists( $gate ) || ! method_exists( $gate, 'is_locked' ) ) {
			return false;
		}
		try {
			return (bool) $gate::is_locked( $post, new \WP_User( 0 ) );
		} catch ( \Throwable $e ) {
			// Cannot tell: never risk teaching a paid page.
			return true;
		}
	}

	/**
	 * The page's text as HTML (blocks rendered, shortcodes left out: they may
	 * be slow or do things; page builders that keep text elsewhere are not read).
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function html_of( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$html = function_exists( 'do_blocks' ) ? do_blocks( (string) $post->post_content ) : (string) $post->post_content;

		return self::without_orders( self::without_hidden( strip_shortcodes( $html ) ) );
	}

	/**
	 * Text a visitor never sees is left out: the model would read it. Hidden
	 * by style (display none, visibility hidden, no size, no opacity), by the
	 * hidden attribute or aria-hidden.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function without_hidden( string $html ): string {
		if ( '' === trim( $html ) || ! class_exists( '\\DOMDocument' ) ) {
			return $html;
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"?><div id="ds-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xpath  = new \DOMXPath( $doc );
		$hidden = array();
		foreach ( $xpath->query( '//*[@hidden or @aria-hidden="true" or @style]' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$style = strtolower( (string) preg_replace( '/\s+/', '', $node->getAttribute( 'style' ) ) );
			if ( $node->hasAttribute( 'hidden' ) || 'true' === strtolower( $node->getAttribute( 'aria-hidden' ) )
				|| preg_match( '/display:none|visibility:hidden|visibility:collapse|font-size:0(?![.\d]*[1-9])|opacity:0(?![.\d]*[1-9])|(?:^|;)(?:height|max-height):0(?:px)?(?:;|$)/', $style ) ) {
				$hidden[] = $node;
			}
		}
		foreach ( $hidden as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
		$root = $doc->getElementById( 'ds-root' );
		if ( ! $root ) {
			return $html;
		}
		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Words written for the assistant rather than the visitor ("ignore the
	 * previous instructions", "note to the AI", "you are an AI") are
	 * left out: a page is written by more people than help articles, and an
	 * editor's account can be taken over. Each paragraph, list item, heading
	 * or cell holding such words is dropped whole, then any such sentence
	 * left in running text.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function without_orders( string $html ): string {
		$orders = '(?:ignore|disregard|forget)\s+(?:all\s+|any\s+)?(?:the\s+)?(?:previous|prior|above|earlier|your|other)\s+(?:instructions|rules|prompts?|guidelines)'
			. '|(?:system|admin|important|special)\s+(?:note|message|prompt|instructions?)\s+(?:to|for)\s+(?:the\s+)?(?:assistant|ai|bot|chatbot|model|llm)'
			. '|(?:note|message|instructions?|prompt)\s+(?:to|for)\s+(?:the\s+)?(?:assistant|ai|bot|chatbot|model|llm)'
			. '|(?:you\s+are|as)\s+(?:an?\s+)?(?:ai|assistant|chatbot|language\s+model|llm)\b'
			. '|(?:(?<=^)|(?<=[>.!?:])|(?<=[>.!?:]\s))(?:hey\s+|dear\s+|ok\s+)?(?:the\s+)?(?:assistant|ai|chatbot|bot|model|llm|gpt|claude|gemini)\s*[,:]'
			. '|from\s+now\s+on,?\s+(?:you|the\s+(?:assistant|ai|bot|chatbot))\s+(?:must|should|will|have\s+to|are\s+to)'
			. '|يا\s*(?:مساعد|بوت|روبوت|ذكاء)'
			. '|تجاهل\s+(?:كل\s+|جميع\s+)?(?:التعليمات|الأوامر|القواعد)'
			. '|(?:ملاحظة|تعليمات|رسالة)\s+(?:لل|إلى\s+ال|الى\s+ال)(?:مساعد|ذكاء|بوت|روبوت)';
		$html = (string) preg_replace( '#<(p|li|h[1-6]|td|th|blockquote|dd|dt|figcaption)\b[^>]*>(?:(?!</\1>).)*(?:' . $orders . ')(?:(?!</\1>).)*</\1>#isu', '', $html );

		return (string) preg_replace( '#[^.!?؟\n<>]*(?:' . $orders . ')[^.!?؟\n<>]*[.!?؟]?#iu', '', $html );
	}

	/**
	 * @param int $post_id Post.
	 * @return string Plain text.
	 */
	public static function text_of( int $post_id ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( self::html_of( $post_id ) ) ) );
	}

	/**
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_saved( $post_id, $post ): void {
		if ( wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! self::enabled() ) {
			return;
		}
		// The passages now; the fingerprints (a network call) in the background.
		self::index( (int) $post_id, false );
		// Pages it links to may now be reached from somewhere: learn the ones
		// not learned yet.
		if ( 'publish' === $post->post_status ) {
			foreach ( self::linked_pages( (string) $post->post_content ) as $page_id ) {
				if ( $page_id !== (int) $post_id && 0 === self::count_passages( $page_id ) ) {
					self::index( $page_id, false );
				}
			}
		}
		Embeddings::maybe_queue_backfill( true );
	}

	/**
	 * Pages of this site that $html links to (50 at most).
	 *
	 * @param string $html Content.
	 * @return int[]
	 */
	private static function linked_pages( string $html ): array {
		if ( ! preg_match_all( '#href=["\']([^"\'\#]+)#i', $html, $m ) ) {
			return array();
		}
		$ids = array();
		foreach ( array_slice( array_unique( $m[1] ), 0, 50 ) as $url ) {
			$id = url_to_postid( $url );
			if ( $id > 0 && 'page' === get_post_type( $id ) ) {
				$ids[] = (int) $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Learn one page again (or forget it when it may no longer be used).
	 *
	 * @param int  $post_id Post.
	 * @param bool $embed   Fingerprint the passages too (a network call).
	 * @return int Passages stored.
	 */
	public static function index( int $post_id, bool $embed = true ): int {
		if ( ! self::open( $post_id, true ) ) {
			self::forget( $post_id );
			return 0;
		}
		KbChunksTable::ensure();
		$title    = (string) get_the_title( $post_id );
		$html     = self::html_of( $post_id );
		$hash     = md5( $title . "\n" . $html );
		if ( $hash === self::stored_hash( $post_id ) ) {
			if ( $embed ) {
				Embeddings::index_page( $post_id );
			}
			return self::count_passages( $post_id );
		}

		$passages = Chunker::split( $html );
		if ( array() === $passages ) {
			self::forget( $post_id );
			return 0;
		}

		global $wpdb;
		self::forget( $post_id );
		foreach ( $passages as $index => $passage ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				Indexer::table(),
				array(
					'article_id'   => 0,
					'source'       => self::SOURCE,
					'post_id'      => $post_id,
					'chunk_index'  => $index,
					'heading'      => mb_substr( $passage['heading'], 0, 255, 'UTF-8' ),
					'content'      => $passage['text'],
					'search_text'  => TextNormalizer::search_text( $title . ' ' . $passage['heading'] . ' ' . $passage['text'] ),
					'word_count'   => count( preg_split( '/\s+/u', $passage['text'], -1, PREG_SPLIT_NO_EMPTY ) ?: array() ),
					'content_hash' => $hash,
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
			);
		}
		AnswerCache::flush();
		if ( $embed ) {
			Embeddings::index_page( $post_id );
		}

		return count( $passages );
	}

	/**
	 * Forget a page's passages.
	 *
	 * @param int $post_id Post.
	 * @return void
	 */
	public static function forget( $post_id ): void {
		global $wpdb;
		$table = Indexer::table();
		if ( ! self::table_ready() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$gone = $wpdb->delete(
			$table,
			array(
				'source'  => self::SOURCE,
				'post_id' => (int) $post_id,
			),
			array( '%s', '%d' )
		);
		if ( $gone ) {
			AnswerCache::flush();
		}
	}

	/**
	 * The owner turned it on or off, chose types, left a page out.
	 *
	 * @param mixed $before Settings before.
	 * @param mixed $after  Settings after.
	 * @return void
	 */
	public static function on_settings_changed( $before, $after ): void {
		$old = is_array( $before ) && isset( $before['site_pages'] ) && is_array( $before['site_pages'] ) ? $before['site_pages'] : array();
		$new = is_array( $after ) && isset( $after['site_pages'] ) && is_array( $after['site_pages'] ) ? $after['site_pages'] : array();
		if ( $old == $new ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same settings, any key order.
			return;
		}
		if ( empty( $new['enabled'] ) ) {
			self::forget_all();
			return;
		}
		// Left out, or no longer a chosen type: forgotten at once.
		foreach ( self::learned_ids() as $post_id ) {
			if ( ! self::open( $post_id ) ) {
				self::forget( $post_id );
			}
		}
		// Pages the owner just turned on: learned now, so the list shows them.
		foreach ( array_diff( (array) ( $new['included'] ?? array() ), (array) ( $old['included'] ?? array() ) ) as $post_id ) {
			self::index( (int) $post_id, false );
		}
		// The rest (and newly allowed pages) in the background.
		self::queue_backfill();
	}

	/**
	 * Learn the pages again in the background (when it is on).
	 *
	 * @return void
	 */
	public static function queue_backfill(): void {
		if ( ! self::enabled() ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::BACKFILL_HOOK, array(), Indexer::GROUP ) ) {
				as_enqueue_async_action( self::BACKFILL_HOOK, array(), Indexer::GROUP );
			}
		} else {
			self::backfill();
		}
	}

	/**
	 * Learn the newest allowed pages, up to MAX; forget the others.
	 *
	 * @return int Pages learned.
	 */
	public static function backfill(): int {
		if ( ! self::enabled() ) {
			return 0;
		}
		$ids = self::candidates();
		foreach ( array_diff( self::learned_ids(), $ids ) as $post_id ) {
			self::forget( $post_id );
		}
		$learned = 0;
		foreach ( $ids as $post_id ) {
			if ( self::index( $post_id, false ) > 0 ) {
				++$learned;
			}
		}
		Embeddings::maybe_queue_backfill( true );

		return $learned;
	}

	/**
	 * The newest pages that may be learned, up to the limit.
	 *
	 * @return int[]
	 */
	public static function candidates(): array {
		$settings = self::settings();
		$max      = max( 1, (int) apply_filters( 'doublescale_help_site_pages_max', self::MAX ) );
		$ids      = get_posts(
			array(
				'post_type'        => $settings['post_types'],
				'post_status'      => 'publish',
				'has_password'     => false,
				'post__not_in'     => $settings['excluded'],
				'orderby'          => 'date',
				'order'            => 'DESC',
				'posts_per_page'   => $max * 2,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
		$out = array();
		foreach ( (array) $ids as $post_id ) {
			if ( self::open( (int) $post_id, true ) ) {
				$out[] = (int) $post_id;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * For the owner: the pages the assistant may learn, whether it uses each.
	 *
	 * A page of the list (newest first), so the screen loads it as it scrolls.
	 *
	 * @param int $page     Page of the list.
	 * @param int $per_page Rows.
	 * @return array{pages: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	public static function overview( int $page = 1, int $per_page = 20 ): array {
		$settings = self::settings();
		$learned  = array_flip( self::learned_ids() );
		$per_page = max( 1, min( 100, $per_page ) );
		$query    = new \WP_Query(
			array(
				'post_type'        => $settings['post_types'],
				'post_status'      => 'publish',
				'orderby'          => 'date',
				'order'            => 'DESC',
				'posts_per_page'   => $per_page,
				'paged'            => max( 1, $page ),
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$ids = $query->posts;
		$out = array();
		foreach ( (array) $ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$used = isset( $learned[ (int) $post_id ] );
			// A learned page was linked when learned: no need to read the site again.
			$reason = $used ? null : self::reason( $post, true );
			$out[]  = array(
				'id'          => (int) $post_id,
				'title'       => (string) get_the_title( $post ),
				'url'         => (string) get_permalink( $post ),
				'used'        => $used,
				'reason'      => $reason,
				'excluded'    => in_array( (int) $post_id, $settings['excluded'], true ),
				'included'    => in_array( (int) $post_id, $settings['included'], true ),
				// Only "nothing links to it" is the owner's to lift.
				'can_turn_on' => 'not_linked' === $reason,
			);
		}

		return array(
			'pages'       => $out,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * @param int|null $post_id One page, or every page.
	 * @return int Passages learned.
	 */
	public static function count_passages( ?int $post_id = null ): int {
		if ( ! self::table_ready() ) {
			return 0;
		}
		global $wpdb;
		$table = Indexer::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $post_id
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", self::SOURCE ) )
			: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND post_id = %d", self::SOURCE, $post_id ) );
		// phpcs:enable
	}

	/**
	 * @return int[] Pages the assistant uses now (the screen's count).
	 */
	public static function learned_post_ids(): array {
		return self::learned_ids();
	}

	/**
	 * @return int[] Pages with passages.
	 */
	private static function learned_ids(): array {
		if ( ! self::table_ready() ) {
			return array();
		}
		global $wpdb;
		$table = Indexer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_id FROM {$table} WHERE source = %s", self::SOURCE ) ) );
	}

	/**
	 * @return void
	 */
	private static function forget_all(): void {
		if ( ! self::table_ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( Indexer::table(), array( 'source' => self::SOURCE ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		AnswerCache::flush();
	}

	/**
	 * @param int $post_id Post.
	 * @return string
	 */
	private static function stored_hash( int $post_id ): string {
		global $wpdb;
		$table = Indexer::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM {$table} WHERE source = %s AND post_id = %d LIMIT 1", self::SOURCE, $post_id ) );
	}

	/**
	 * @return bool
	 */
	private static function table_ready(): bool {
		global $wpdb;
		$table = Indexer::table();

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
