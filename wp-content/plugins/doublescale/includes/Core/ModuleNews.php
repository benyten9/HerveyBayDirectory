<?php
/**
 * New modules, announced once to the site's administrators.
 *
 * A module that arrives in an update starts off on sites that already ran
 * DoubleScale, so nobody would notice it. Each entry here names the version
 * that brought it; sites that started on an older version see it on the
 * dashboard (Turn it on / Not now) and as "New" in Control Modules, until the
 * administrator has seen it. Fresh installs never see these: their first
 * version already has the module.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class ModuleNews {

	/** Version this site started on. Missing: the site predates this record. */
	public const FIRST_VERSION_OPTION = 'doublescale_first_version';

	/** Per administrator: module slugs already seen. */
	public const SEEN_META = 'doublescale_module_news_seen';

	/**
	 * Called when DoubleScale is activated: a site with no earlier version is
	 * new, and starts with nothing to announce. Re-activating an existing
	 * site keeps it older than every entry ('0').
	 */
	public static function stamp_first_version(): void {
		if ( false !== get_option( self::FIRST_VERSION_OPTION ) ) {
			return;
		}
		$fresh = false === get_option( 'doublescale_version' );
		add_option( self::FIRST_VERSION_OPTION, $fresh && defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : '0', '', false );
	}

	/**
	 * Every announcement: slug => since (free's version), title, text, the
	 * page to open, and what it is:
	 *   - kind 'module': a module to turn on (its parent comes with it)
	 *   - kind 'feature': part of modules listed in 'needs'; 'pro' when it
	 *     comes with DoubleScale Pro (free sites see what it does)
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function registry(): array {
		$items = array(
			'knowledge_base' => array(
				'since' => '1.9.11',
				'kind'  => 'module',
				'title' => __( 'Help Center', 'doublescale' ),
				'text'  => __( 'Publish help articles your customers can search, on your site and in the client portal. Group them into categories, and see which ones they read and find helpful.', 'doublescale' ),
				'path'  => 'help-center',
			),
			'live_chat'      => array(
				'since' => '1.9.11',
				'kind'  => 'feature',
				'pro'   => true,
				'needs' => array( 'support', 'help_assistant' ),
				'title' => __( 'Live chat', 'doublescale' ),
				'text'  => __( 'Chat with visitors on your site in real time, from a floating button, your help center or the client portal. Chats arrive in the Helpdesk inbox, so your team answers them where they answer email.', 'doublescale' ),
				'path'  => 'support/settings/live-chat',
			),
			'help_assistant' => array(
				'since' => '1.9.11',
				'kind'  => 'feature',
				'pro'   => true,
				'needs' => array( 'support', 'knowledge_base', 'help_assistant' ),
				'title' => __( 'AI assistant', 'doublescale' ),
				'text'  => __( 'Answers your customers from your help articles, day and night, and hands the chat to your team when it can’t help. See every conversation, and the questions it couldn’t answer.', 'doublescale' ),
				'path'  => 'help-center/assistant',
			),
		);

		/**
		 * Add or change announcements (Pro and add-ons).
		 *
		 * @param array $items slug => since, title, text, path.
		 */
		return (array) apply_filters( 'doublescale_module_news', $items );
	}

	/**
	 * What this administrator has not seen yet. Each item says whether the
	 * module is on already and which parent module it needs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id ): array {
		if ( $user_id < 1 || ! user_can( $user_id, 'manage_options' ) ) {
			return array();
		}

		$first   = (string) get_option( self::FIRST_VERSION_OPTION, '0' );
		$seen    = self::seen( $user_id );
		$parents = function_exists( 'doublescale_child_module_parent_map' ) ? doublescale_child_module_parent_map() : array();
		$has_pro = defined( 'DOUBLESCALE_PRO_VERSION' );
		$out     = array();

		foreach ( self::registry() as $slug => $item ) {
			$slug = (string) $slug;
			if ( in_array( $slug, $seen, true ) || ! is_array( $item ) ) {
				continue;
			}
			$since = (string) ( $item['since'] ?? '' );
			if ( '' === $since || version_compare( $first, $since, '>=' ) ) {
				continue;
			}

			$kind   = 'feature' === ( $item['kind'] ?? 'module' ) ? 'feature' : 'module';
			$locked = $kind === 'feature' && ! empty( $item['pro'] ) && ! $has_pro;

			if ( 'module' === $kind ) {
				if ( ! ModuleManager::getModule( $slug ) || ! ModuleManager::isToggleable( $slug ) ) {
					continue;
				}
				$needs = isset( $parents[ $slug ] ) ? array( (string) $parents[ $slug ], $slug ) : array( $slug );
			} else {
				// Modules this install has (Pro's are missing without Pro).
				$needs = array_values( array_filter( array_map( 'strval', (array) ( $item['needs'] ?? array() ) ), array( ModuleManager::class, 'getModule' ) ) );
			}

			// Effectively off (a parent may be the reason) vs. switched off:
			// the button switches on only what is switched off.
			$off    = array_values( array_filter( $needs, static fn( $need ) => ! ModuleManager::isEnabled( $need ) ) );
			$to_set = array_values( array_filter( $needs, static fn( $need ) => ! self::switched_on( $need ) ) );

			// A Pro feature on a free site opens the page about it, which
			// exists only while its modules are on: no switching modules on
			// to show an upgrade page.
			if ( $locked && $off ) {
				continue;
			}

			$out[] = array(
				'slug'    => $slug,
				'kind'    => $kind,
				'title'   => (string) ( $item['title'] ?? $slug ),
				'text'    => (string) ( $item['text'] ?? '' ),
				'path'    => (string) ( $item['path'] ?? '' ),
				'locked'  => $locked,
				'enabled' => 'module' === $kind ? ModuleManager::isEnabled( $slug ) : ! $off,
				// Turned on by the card's button, in this order.
				'turnOn'  => $to_set,
			);
		}

		return $out;
	}

	/** The module's own switch (saved, or its default), whatever its parent says. */
	private static function switched_on( string $slug ): bool {
		$stored = get_option( 'doublescale_enabled_modules', array() );
		if ( is_array( $stored ) && array_key_exists( $slug, $stored ) ) {
			return (bool) $stored[ $slug ];
		}
		return ModuleManager::is_enabled_by_default( $slug );
	}

	/**
	 * @return string[]
	 */
	public static function seen( int $user_id ): array {
		$seen = get_user_meta( $user_id, self::SEEN_META, true );
		return is_array( $seen ) ? array_values( array_map( 'strval', $seen ) ) : array();
	}

	/**
	 * @param string[] $slugs
	 */
	public static function mark_seen( int $user_id, array $slugs ): void {
		$known = array_keys( self::registry() );
		$slugs = array_values( array_intersect( array_map( 'sanitize_key', $slugs ), $known ) );
		if ( $user_id < 1 || ! $slugs ) {
			return;
		}
		update_user_meta( $user_id, self::SEEN_META, array_values( array_unique( array_merge( self::seen( $user_id ), $slugs ) ) ) );
	}
}
