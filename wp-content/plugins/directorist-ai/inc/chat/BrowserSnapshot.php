<?php
/** Non-authorizing fences for the visitor's display-only browser snapshot. */
namespace DirectoristAIPro\Chat;

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\GuestSessions;

final class BrowserSnapshot {

	private const REVISION = 'directorist_ai_chat_browser_revision';
	private static array $dirty = array();

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'sync_epoch' ), 1 );
		add_action( 'shutdown', array( self::class, 'flush_all' ) );
		add_action( 'clear_auth_cookie', array( self::class, 'forget_epoch' ) );
		add_action( 'wp_logout', array( self::class, 'forget_epoch' ) );
		add_action( 'set_logged_in_cookie', static function ( $cookie, $expire, $expiration, $user_id, $scheme, $token ): void {
			self::write_epoch( self::session_stamp( 'user:' . $user_id, $token ), (int) $expire );
		}, 10, 6 );
		add_action( 'save_post', array( self::class, 'post_changed' ) );
		add_action( 'before_delete_post', array( self::class, 'post_changed' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'post_meta_changed' ), 10, 3 );
		}
		foreach ( array( 'created_term', 'edited_term', 'delete_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'set_object_terms' ) as $hook ) {
			add_action( $hook, array( self::class, 'invalidate' ) );
		}
		add_action( 'updated_option', array( self::class, 'option_changed' ) );
		add_action( 'added_option', array( self::class, 'option_changed' ) );
		add_action( 'deleted_option', array( self::class, 'option_changed' ) );
	}

	/** A readable, non-authorizing browser epoch fences stale/shared cached HTML. */
	public static function sync_epoch(): void {
		$identity = self::current();
		if ( null !== $identity ) { self::write_epoch( $identity['session'], (int) ( $identity['expires'] / 1000 ) ); }
		elseif ( isset( $_COOKIE[ self::cookie_name() ] ) ) { self::forget_epoch(); }
	}

	public static function forget_epoch(): void { self::write_epoch( '', time() - HOUR_IN_SECONDS ); }

	public static function remember_guest( Actor $actor, string $token, bool $remember ): void {
		self::write_epoch( self::session_stamp( $actor->owner()->reference(), $token ), $remember ? $actor->authorization_expires_at() : 0 );
	}

	private static function cookie_name(): string { return 'directorist_ai_browser_' . get_current_blog_id(); }

	private static function write_epoch( string $value, int $expires ): void {
		$name = self::cookie_name();
		if ( headers_sent() || ( isset( $_COOKIE[ $name ] ) && hash_equals( (string) $_COOKIE[ $name ], $value ) ) ) { return; }
		setcookie( $name, $value, array( 'expires' => $expires, 'path' => wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Strict' ) );
		if ( '' === $value ) { unset( $_COOKIE[ $name ] ); } else { $_COOKIE[ $name ] = $value; }
	}

	private static function session_stamp( string $owner, string $credential ): string {
		return hash_hmac( 'sha256', 'visitor-browser|' . get_current_blog_id() . '|' . $owner . '|' . $credential, wp_salt( 'auth' ) );
	}

	public static function post_changed( int $post_id ): void {
		$type = get_post_type( $post_id );
		if ( in_array( $type, array( 'at_biz_dir', 'atbdp_listing_types', 'attachment' ), true ) ) { self::invalidate(); }
	}

	public static function post_meta_changed( $meta_id, int $post_id, string $key ): void {
		// Native visit tracking and editor locks do not revoke a saved conversation.
		if ( in_array( $key, array( '_atbdp_post_views_count', '_edit_lock', '_edit_last' ), true ) ) { return; }
		self::post_changed( $post_id );
	}

	public static function option_changed( string $option ): void {
		if ( self::REVISION === $option ) { return; }
		if ( in_array( $option, array( 'active_plugins', 'timezone_string', 'gmt_offset', 'WPLANG', 'blog_public', 'wpml_sitepress_settings' ), true )
			|| in_array( $option, array( 'directorist_ai_pro_operations', 'directorist_ai_pro_appearance', 'directorist_ai_pro_emergency_stop', 'directorist_ai_pro_global_launcher', 'directorist_ai_pro_readiness' ), true )
			|| str_starts_with( $option, 'directorist_ai_pro_ready_' )
			|| str_starts_with( $option, 'atbdp' ) || ( str_starts_with( $option, 'directorist_' ) && ! str_starts_with( $option, 'directorist_ai_' ) ) ) { self::invalidate(); }
	}

	/** A tiny native generation fence; no schema/catalog/model work on page load. */
	public static function invalidate(): void {
		self::$dirty[ get_current_blog_id() ] = true;
	}

	/** Coalesce a native listing/field save into one generation write. */
	public static function flush(): void {
		$site = get_current_blog_id();
		if ( empty( self::$dirty[ $site ] ) ) { return; }
		unset( self::$dirty[ $site ] );
		update_option( self::REVISION, bin2hex( random_bytes( 16 ) ), false );
	}

	public static function flush_all(): void {
		foreach ( array_keys( self::$dirty ) as $site ) {
			$switched = (int) $site !== get_current_blog_id();
			if ( $switched ) { switch_to_blog( (int) $site ); }
			self::flush();
			if ( $switched ) { restore_current_blog(); }
		}
	}

	/** Never issues an identity or exposes a credential. */
	public static function current(): ?array {
		try {
			if ( is_user_logged_in() ) { return self::for_actor( Actor::user( get_current_user_id() ) ); }
			$token = isset( $_COOKIE['directorist_ai_guest'] ) && is_string( $_COOKIE['directorist_ai_guest'] ) ? $_COOKIE['directorist_ai_guest'] : '';
			return self::for_actor( ( new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( $token ), $token );
		} catch ( \Throwable $error ) { return null; }
	}

	/** The stamp can only choose a browser display cache, never authorize a REST call. */
	public static function for_actor( Actor $actor, string $guest_token = '' ): ?array {
		$credential = $actor->is_guest() ? $guest_token : wp_get_session_token();
		if ( '' === $credential ) { return null; }
		$expiry = time() + DAY_IN_SECONDS;
		if ( $actor->is_guest() ) {
			$parts = explode( '.', $credential );
			$verified = ( new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( $credential );
			if ( $verified->owner()->reference() !== $actor->owner()->reference() ) { return null; }
			$expiry = min( $expiry, (int) ( $parts[1] ?? 0 ) );
		} else {
			$session = \WP_Session_Tokens::get_instance( $actor->user_id() )->get( $credential );
			if ( ! is_array( $session ) || empty( $session['expiration'] ) || $session['expiration'] <= time() ) { return null; }
			$expiry = min( $expiry, (int) $session['expiration'] );
		}
		self::flush();
		return array(
			'owner'    => hash_hmac( 'sha256', 'visitor-ui|' . $actor->owner()->reference(), wp_salt( 'auth' ) ),
			'session'  => self::session_stamp( $actor->owner()->reference(), $credential ),
			'site'     => hash( 'sha256', get_current_blog_id() . '|' . home_url( '/' ) ),
			'revision' => hash( 'sha256', (string) get_option( self::REVISION, 'initial' ) ),
			'expires'  => $expiry * 1000,
			'issued'   => (int) floor( microtime( true ) * 1000 ),
			'cookie'   => self::cookie_name(),
		);
	}
}
