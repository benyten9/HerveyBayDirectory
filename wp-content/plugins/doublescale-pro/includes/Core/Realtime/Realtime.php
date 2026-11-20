<?php
/**
 * Real-time push through the DoubleScale relay (wss://relay.doublescale.io).
 *
 * Pro only, and only while the licence is active: the relay checks the licence
 * with doublescale.io when the site registers and hands back a licence pass
 * (72h) that this site renews twice a day. Without a current pass the relay
 * refuses this site's events and browsers, so features fall back to polling.
 *
 * Use from PHP:
 *   Realtime::publish( 'staff', 'ticket.reply', array( 'ticket_id' => 12 ) );
 *   Realtime::client_config( array( 'chat.abc' ) ); // for a page's own channels
 * Use from the admin app: window.doublescaleRealtime.subscribe( channel, fn ).
 *
 * Channels are names inside this site only (the relay namespaces them per
 * site). Staff get `staff` and `user.<id>`; other channels are allowed through
 * the `doublescale_realtime_can_subscribe` filter.
 *
 * @since 1.5.11
 * @package DoubleScale\Pro\Core\Realtime
 */

namespace DoubleScale\Pro\Core\Realtime;

defined( 'ABSPATH' ) || exit;

/**
 * Realtime class.
 */
final class Realtime {

	/**
	 * Stored state: site_id, secret, until, ticket, chat, status, license_status, error, checked_at.
	 */
	public const OPTION = 'doublescale_pro_realtime';

	/**
	 * Background registration / licence pass renewal.
	 */
	public const CRON_HOOK = 'doublescale_realtime_refresh';

	/**
	 * Renew the licence pass when it ends within this many seconds.
	 */
	private const RENEW_BEFORE = 2 * DAY_IN_SECONDS;

	/**
	 * Browser channel tokens live this long (the relay allows at most 24h).
	 */
	public const TOKEN_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Wait this long after a failed attempt before trying again on its own.
	 */
	private const RETRY_AFTER = 10 * MINUTE_IN_SECONDS;

	/**
	 * Events waiting to be sent at the end of the request.
	 *
	 * @var array<int, array{channels: string[], event: string, data: mixed}>
	 */
	private static array $queue = array();

	/**
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::CRON_HOOK, array( self::class, 'refresh' ) );
		add_action( 'add_option_doublescale_license', array( self::class, 'on_license_change' ) );
		add_action( 'update_option_doublescale_license', array( self::class, 'on_license_change' ) );
		add_action( 'delete_option_doublescale_license', array( self::class, 'on_license_change' ) );
		add_action( 'rest_api_init', array( RestRealtimeController::class, 'register' ) );
		add_filter( 'doublescale_admin_config', array( self::class, 'admin_config' ) );
		add_action( 'shutdown', array( self::class, 'flush' ), 1 );
	}

	/**
	 * Twice a day: register when needed, renew the licence pass.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * The licence was activated, checked or removed: look again soon.
	 *
	 * @return void
	 */
	public static function on_license_change(): void {
		$state = self::state();
		if ( ! self::license_active() && ! empty( $state['ticket'] ) ) {
			// Stop using the pass at once; the relay stops accepting it when it lapses anyway.
			$state['ticket'] = '';
			$state['until']  = 0;
			$state['status'] = 'license_inactive';
			self::save( $state );
		}
		wp_schedule_single_event( time(), self::CRON_HOOK, array( true ) );
	}

	// ------------------------------------------------------------------ state

	/**
	 * @return string
	 */
	public static function relay_url(): string {
		$url = defined( 'DOUBLESCALE_REALTIME_RELAY' ) ? (string) constant( 'DOUBLESCALE_REALTIME_RELAY' ) : 'https://relay.doublescale.io';
		return untrailingslashit( (string) apply_filters( 'doublescale_realtime_relay_url', $url ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function license(): array {
		$license = get_option( 'doublescale_license' );
		return is_array( $license ) ? $license : array();
	}

	/**
	 * The licence this site holds is active (as last checked with doublescale.io),
	 * or this is a demo site (try.doublescale.io and its sandboxes), where
	 * visitors try live chat without a licence. The relay grants a demo site
	 * its pass only when the request really comes from the demo server.
	 *
	 * @return bool
	 */
	public static function license_active(): bool {
		$license = self::license();
		return ( 'valid' === ( $license['status'] ?? '' ) && ! empty( $license['key'] ) ) || self::demo_site();
	}

	/**
	 * This site is DoubleScale's demo (try.doublescale.io) or one of its
	 * sandboxes (a sub-path or a subdomain of it).
	 *
	 * @return bool
	 */
	public static function demo_site(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$demo = 'try.doublescale.io';
		$is   = '' !== $host && ( $demo === $host || str_ends_with( $host, '.' . $demo ) );

		/**
		 * Whether this site is a DoubleScale demo (live chat without a licence).
		 *
		 * @param bool   $is   The site's host is try.doublescale.io or below it.
		 * @param string $host The site's host.
		 */
		return (bool) apply_filters( 'doublescale_demo_site', $is, $host );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Site owners (or tests) can switch real-time off.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'doublescale_realtime_enabled', true );
	}

	/**
	 * Ready to send events and accept browsers right now.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! self::enabled() || ! self::license_active() ) {
			return false;
		}
		$state = self::state();
		return ! empty( $state['site_id'] ) && ! empty( $state['secret'] ) && ! empty( $state['ticket'] )
			&& (int) ( $state['until'] ?? 0 ) > time() + MINUTE_IN_SECONDS;
	}

	/**
	 * One word for the admin app:
	 * ready | license_inactive | pending | error | disabled.
	 *
	 * @return string
	 */
	public static function status(): string {
		if ( ! self::enabled() ) {
			return 'disabled';
		}
		if ( ! self::license_active() ) {
			return 'license_inactive';
		}
		if ( self::available() ) {
			return 'ready';
		}
		$status = (string) ( self::state()['status'] ?? '' );
		return in_array( $status, array( 'license_inactive', 'error' ), true ) ? $status : 'pending';
	}

	// --------------------------------------------------- register and renew

	/**
	 * Register this site with the relay, or renew its licence pass when due.
	 * Makes HTTP requests: call from cron, REST or CLI, not on page render.
	 *
	 * @param bool $force Renew now even if the pass is not due, and skip the retry wait.
	 * @return string The status after the attempt.
	 */
	public static function refresh( $force = false ): string {
		if ( ! self::enabled() ) {
			return 'disabled';
		}
		$state = self::state();

		if ( ! self::license_active() ) {
			if ( ( $state['status'] ?? '' ) !== 'license_inactive' || ! empty( $state['ticket'] ) ) {
				$state['ticket'] = '';
				$state['until']  = 0;
				$state['status'] = 'license_inactive';
				self::save( $state );
			}
			return 'license_inactive';
		}

		$failed_at = (int) ( $state['failed_at'] ?? 0 );
		if ( ! $force && $failed_at && time() - $failed_at < self::RETRY_AFTER ) {
			return self::status();
		}

		// A copy of another site (a staging clone, a demo sandbox) carries that
		// site's relay identity: register under its own, or the two would share
		// channels.
		if ( ! empty( $state['site_id'] ) && isset( $state['site_url'] ) && home_url() !== $state['site_url'] ) {
			$state = array();
		}

		if ( empty( $state['site_id'] ) || empty( $state['secret'] ) ) {
			$state = self::register_site( $state );
		} elseif ( $force || empty( $state['ticket'] ) || (int) ( $state['until'] ?? 0 ) - time() < self::RENEW_BEFORE ) {
			$state = self::renew( $state );
		}

		self::save( $state );
		return self::status();
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function register_site( array $state ): array {
		[ $code, $body ] = self::post(
			'/v1/register',
			array(
				'site_url'       => home_url(),
				'license'        => (string) ( self::license()['key'] ?? '' ),
				'plugin_version' => defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '',
			)
		);

		if ( 201 === $code && ! empty( $body['site_id'] ) && ! empty( $body['secret'] ) ) {
			return self::with_pass(
				array(
					'site_id'  => (string) $body['site_id'],
					'secret'   => (string) $body['secret'],
					'site_url' => home_url(),
				),
				$body
			);
		}
		return self::with_failure( $state, $code, $body );
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function renew( array $state ): array {
		[ $code, $body ] = self::post(
			'/v1/license',
			array(
				'site_url' => home_url(),
				'license'  => (string) ( self::license()['key'] ?? '' ),
			),
			$state
		);

		if ( 200 === $code && ! empty( $body['license_ticket'] ) ) {
			return self::with_pass( $state, $body );
		}
		if ( 401 === $code ) {
			// The relay no longer knows this site (its keys were replaced): start over once.
			return self::register_site( array() );
		}
		return self::with_failure( $state, $code, $body );
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @param array<string, mixed> $body  Relay reply with license_until + license_ticket.
	 * @return array<string, mixed>
	 */
	private static function with_pass( array $state, array $body ): array {
		$state['until']          = (int) $body['license_until'];
		$state['ticket']         = (string) $body['license_ticket'];
		// The pass includes live chat (any licence plan): the relay signed it so.
		$state['chat']           = ! empty( $body['license_chat'] );
		$state['plan']           = isset( $body['plan'] ) ? (string) $body['plan'] : null;
		$state['status']         = 'ready';
		$state['license_status'] = 'valid';
		$state['error']          = '';
		$state['failed_at']      = 0;
		$state['checked_at']     = time();
		return $state;
	}

	/**
	 * @param array<string, mixed> $state State.
	 * @param int                  $code  HTTP status (0 = no answer).
	 * @param array<string, mixed> $body  Relay reply.
	 * @return array<string, mixed>
	 */
	private static function with_failure( array $state, int $code, array $body ): array {
		$state['checked_at'] = time();
		$state['failed_at']  = time();
		if ( 402 === $code ) {
			// doublescale.io says the licence is not active here: stop now.
			$state['status']         = 'license_inactive';
			$state['license_status'] = (string) ( $body['license_status'] ?? 'inactive' );
			$state['ticket']         = '';
			$state['until']          = 0;
		} else {
			// Relay or doublescale.io unreachable: keep the current pass until it lapses.
			$state['status'] = 'error';
			$state['error']  = $code ? (string) ( $body['error'] ?? 'http_' . $code ) : (string) ( $body['error'] ?? 'unreachable' );
		}
		return $state;
	}

	/**
	 * POST JSON to the relay; signed with the site secret when $state has one.
	 *
	 * @param string                    $path    Path.
	 * @param array<string, mixed>      $payload Body.
	 * @param array<string, mixed>|null $state   Signs the request when given.
	 * @param array<string, string>     $headers Extra headers.
	 * @param float                     $timeout Seconds.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private static function post( string $path, array $payload, ?array $state = null, array $headers = array(), float $timeout = 15 ): array {
		$body    = (string) wp_json_encode( $payload );
		$headers = array( 'Content-Type' => 'application/json' ) + $headers;
		if ( $state ) {
			$ts                        = (string) time();
			$headers['X-DS-Site']      = (string) $state['site_id'];
			$headers['X-DS-Timestamp'] = $ts;
			$headers['X-DS-Signature'] = hash_hmac( 'sha256', $ts . "\n" . $body, (string) $state['secret'] );
		}

		$response = wp_remote_post(
			self::relay_url() . $path,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 0, array( 'error' => $response->get_error_code() ) );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $decoded ) ? $decoded : array() );
	}

	// ---------------------------------------------------------------- publish

	/**
	 * Push an event to every browser subscribed to the channel(s). Sent at the
	 * end of the request, so callers never wait on the relay. No-op (false)
	 * when real-time is not available; callers' polling still covers it.
	 *
	 * @param string|string[] $channels Channel name(s), e.g. 'staff', 'user.5'.
	 * @param string          $event    Event name, e.g. 'ticket.reply'.
	 * @param mixed           $data     JSON-encodable payload (keep it small: ids, not records).
	 * @return bool
	 */
	public static function publish( $channels, string $event, $data = null ): bool {
		if ( ! self::available() ) {
			return false;
		}
		$channels = array_values( array_unique( array_map( 'strval', (array) $channels ) ) );
		if ( ! $channels ) {
			return false;
		}
		self::$queue[] = array(
			'channels' => $channels,
			'event'    => $event,
			'data'     => $data,
		);
		return true;
	}

	/**
	 * Send queued events (shutdown).
	 *
	 * @return void
	 */
	public static function flush(): void {
		if ( ! self::$queue ) {
			return;
		}
		$queue       = self::$queue;
		self::$queue = array();
		$state       = self::state();
		if ( empty( $state['ticket'] ) ) {
			return;
		}
		foreach ( $queue as $item ) {
			[ $code ] = self::post(
				'/v1/publish',
				$item,
				$state,
				array(
					'X-DS-License-Until' => (string) $state['until'],
					'X-DS-License'       => (string) $state['ticket'],
					'X-DS-License-Chat'  => empty( $state['chat'] ) ? '0' : '1',
				),
				3
			);
			if ( 402 === $code ) {
				// Pass refused: check the licence in the background, stop sending now.
				wp_schedule_single_event( time(), self::CRON_HOOK, array( true ) );
				return;
			}
		}
	}

	// ------------------------------------------------------------- browsers

	/**
	 * A signed token that lets a browser subscribe to one channel.
	 *
	 * @param string $channel Channel.
	 * @param int    $ttl     Seconds.
	 * @return array{channel: string, expires: int, auth: string}|null
	 */
	public static function channel_token( string $channel, int $ttl = self::TOKEN_TTL ): ?array {
		if ( ! self::available() || ! preg_match( '/^[A-Za-z0-9_\-.:=@]{1,100}$/', $channel ) ) {
			return null;
		}
		$state   = self::state();
		$expires = min( time() + min( $ttl, DAY_IN_SECONDS - MINUTE_IN_SECONDS ), (int) $state['until'] );
		return array(
			'channel' => $channel,
			'expires' => $expires,
			'auth'    => hash_hmac( 'sha256', $state['site_id'] . ':' . $channel . ':' . $expires, (string) $state['secret'] ),
		);
	}

	/**
	 * Everything a browser needs to connect and subscribe to $channels.
	 * Callers must have checked that the viewer may read those channels.
	 *
	 * @param string[] $channels Channels.
	 * @return array<string, mixed>|null Null when real-time is not available.
	 */
	public static function client_config( array $channels ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		$state  = self::state();
		$tokens = array();
		foreach ( array_unique( $channels ) as $channel ) {
			$token = self::channel_token( (string) $channel );
			if ( $token ) {
				$tokens[] = $token;
			}
		}
		$ws_base = (string) apply_filters( 'doublescale_realtime_ws_url', preg_replace( '#^http#', 'ws', self::relay_url() ) . '/ws' );
		$ws      = $ws_base . '?' . http_build_query(
			array(
				'site' => $state['site_id'],
				'lu'   => $state['until'],
				'lt'   => $state['ticket'],
				// A pass that includes live chat (it is part of what the relay signed).
				'lc'   => empty( $state['chat'] ) ? '0' : '1',
			)
		);
		return array(
			'url'      => $ws,
			'channels' => $tokens,
			'expires'  => $tokens ? min( array_column( $tokens, 'expires' ) ) : (int) $state['until'],
			'tokenUrl' => rest_url( 'doublescale/v1/realtime/config' ),
		);
	}

	/**
	 * Channels every staff member gets.
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	public static function staff_channels( int $user_id ): array {
		return (array) apply_filters( 'doublescale_realtime_staff_channels', array( 'staff', 'user.' . $user_id ), $user_id );
	}

	/**
	 * May this user read this channel?
	 *
	 * @param string $channel Channel.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function can_subscribe( string $channel, int $user_id ): bool {
		$allowed = $user_id > 0
			&& user_can( $user_id, 'doublescale_access' )
			&& in_array( $channel, self::staff_channels( $user_id ), true );
		return (bool) apply_filters( 'doublescale_realtime_can_subscribe', $allowed, $channel, $user_id );
	}

	/**
	 * Admin app: status for the dashboard warning, and the staff connection.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array<string, mixed>
	 */
	public static function admin_config( $config ) {
		$config   = is_array( $config ) ? $config : array();
		$user_id  = get_current_user_id();
		$status   = self::status();
		$license  = self::license();
		$relay_ls = (string) ( self::state()['license_status'] ?? '' );

		if ( 'pending' === $status && ! wp_next_scheduled( self::CRON_HOOK, array( true ) ) ) {
			// Never registered yet: do it in the background, not during this page load.
			wp_schedule_single_event( time(), self::CRON_HOOK, array( true ) );
		}

		$config['realtime'] = array(
			'status'        => $status,
			// "valid" locally but the relay was told otherwise (e.g. not activated on this URL).
			'licenseStatus' => 'license_inactive' === $status && self::license_active() ? $relay_ls : (string) ( $license['status'] ?? '' ),
			'hasLicense'    => ! empty( $license['key'] ),
			'client'        => $user_id && user_can( $user_id, 'doublescale_access' ) ? self::client_config( self::staff_channels( $user_id ) ) : null,
		);
		return $config;
	}
}
