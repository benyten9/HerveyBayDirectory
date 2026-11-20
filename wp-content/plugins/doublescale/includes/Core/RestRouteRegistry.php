<?php
/**
 * Register only the REST routes a DoubleScale API call needs.
 *
 * Every module used to build all of its REST controllers on every REST
 * request: about 70 controllers and 1,500 routes, ~80 ms with a warm opcode
 * cache and more without, while each call uses one route.
 *
 * Now, when the request is a DoubleScale REST call (`/doublescale/v1/<base>/…`),
 * only the modules that own `<base>` register their controllers. Which module
 * owns which base is learned from a full registration and kept in one
 * autoloaded option; it is rebuilt whenever the set of controllers or the
 * plugin versions change. Everything else (the `/wp-json` index, batch
 * requests, other namespaces, unknown bases, PHP code that dispatches REST
 * requests itself) registers every route, as before.
 *
 * Two safety nets keep the result identical to full registration:
 * - before WordPress matches any REST request to a DoubleScale route that was
 *   left out, every remaining module registers (internal rest_do_request()
 *   calls, AI tools, …);
 * - if a DoubleScale request still ends with "no route", every remaining module
 *   registers and the request is dispatched again.
 *
 * Modules keep their original place in the `rest_api_init` order, so route
 * matching order is unchanged. Kill switch: DOUBLESCALE_EAGER_REGISTRIES, or
 * return false from `doublescale_lazy_rest_routes`.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core;

defined( 'ABSPATH' ) || exit;

final class RestRouteRegistry {

	/** Autoloaded: array{sig: string, bases: array<string, string[]>, always: string[]}. */
	const OPTION = 'doublescale_rest_route_map';

	/** @var array<string, string[]> Module class => controller classes, in boot order. */
	private static $groups = array();

	/** @var array<string, true> Modules whose controllers are registered this request. */
	private static $registered = array();

	/** @var string[]|null Modules this request needs (null = all). Decided once. */
	private static $needed;

	/** @var string|null "namespace/base" of the request served lazily. */
	private static $served_key;

	/** @var array<string, array<string, true>>|null Map being built: base key => modules. */
	private static $building;

	/** @var array<string, true> Modules owning a route whose base is a pattern. */
	private static $always = array();

	/** @var bool */
	private static $hooked = false;

	/** @var bool Whether $needed was decided for the current server. */
	private static $decided = false;

	/** @var int|null Object id of the REST server the state belongs to. */
	private static $server_id;

	/**
	 * Called from a module's boot(): register its controllers on rest_api_init.
	 *
	 * @param string   $module      Module class.
	 * @param string[] $controllers Controller classes.
	 */
	public static function add( string $module, array $controllers ): void {
		self::$groups[ $module ] = $controllers;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_filter( 'rest_pre_dispatch', array( __CLASS__, 'before_dispatch' ), -1000, 3 );
			add_filter( 'rest_post_dispatch', array( __CLASS__, 'after_dispatch' ), -1000, 3 );
			add_action( 'rest_api_init', array( __CLASS__, 'save_map' ), PHP_INT_MAX );
		}
		// One callback per module, added where the module boots: same order as before.
		add_action(
			'rest_api_init',
			static function () use ( $module ) {
				self::on_rest_api_init( $module );
			}
		);
	}

	/**
	 * @param string $module Module class.
	 */
	private static function on_rest_api_init( string $module ): void {
		// A new REST server (tests, a second dispatch context) starts with no routes.
		$server = $GLOBALS['wp_rest_server'] ?? null;
		$id     = is_object( $server ) ? spl_object_id( $server ) : null;
		if ( $id !== self::$server_id ) {
			self::$server_id  = $id;
			self::$registered = array();
			self::$decided    = false;
			self::$needed     = null;
			self::$served_key = null;
			self::$building   = null;
			self::$always     = array();
		}
		if ( ! self::$decided ) {
			self::$decided = true;
			self::decide();
		}
		if ( null === self::$needed || in_array( $module, self::$needed, true ) ) {
			self::register( $module );
		}
	}

	/**
	 * Decide which modules this request needs (once per request).
	 */
	private static function decide(): void {
		$map = self::map();
		$key = self::request_key();
		if ( $map && $key && isset( $map['bases'][ $key ] ) && self::enabled() ) {
			self::$needed     = array_values( array_unique( array_merge( $map['bases'][ $key ], $map['always'] ) ) );
			self::$served_key = $key;
			return;
		}
		// Full registration; learn the map while doing it when it is missing or stale.
		self::$needed   = null;
		self::$building = $map ? null : array();
	}

	/**
	 * Register one module's controllers (and note which bases they own while building the map).
	 *
	 * @param string $module Module class.
	 */
	private static function register( string $module ): void {
		if ( isset( self::$registered[ $module ] ) ) {
			return;
		}
		self::$registered[ $module ] = true;
		$before = null !== self::$building ? self::route_prints() : array();
		foreach ( self::$groups[ $module ] ?? array() as $class ) {
			( new $class() )->register_routes();
		}
		if ( null === self::$building ) {
			return;
		}
		// A module owns the routes it adds AND the routes it adds handlers to or
		// replaces (Pro's Inbox overrides Free's contacts/{id}/send-message): a
		// route already registered would otherwise be learned for its first
		// module only, and the overriding module would be left out of that base.
		$changed = array();
		foreach ( self::route_prints() as $route => $print ) {
			if ( ( $before[ $route ] ?? null ) !== $print ) {
				$changed[] = $route;
			}
		}
		foreach ( $changed as $route ) {
			if ( ! preg_match( '#^/([^/]+/v\d+)/([^/]*)#', $route, $m ) ) {
				continue;
			}
			if ( '' === $m[2] || preg_match( '/[()\[\]?*+\\\\]/', $m[2] ) ) {
				self::$always[ $module ] = true; // Base is a pattern: needed for every call in this namespace.
				continue;
			}
			self::$building[ $m[1] . '/' . $m[2] ][ $module ] = true;
		}
	}

	/**
	 * Register every module not registered yet, in boot order.
	 */
	private static function register_rest(): void {
		self::$needed = null;
		foreach ( array_keys( self::$groups ) as $module ) {
			self::register( $module );
		}
	}

	/**
	 * Before WordPress matches a request: if it targets a DoubleScale route that may be missing, register everything.
	 *
	 * @param mixed            $result  Short-circuit value (passed through).
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function before_dispatch( $result, $server, $request ) {
		if ( null !== self::$needed && self::key_for( (string) $request->get_route() ) !== self::$served_key ) {
			self::register_rest();
		}
		return $result;
	}

	/**
	 * Last resort: a lazily served request found no route → register everything and dispatch again.
	 *
	 * @param \WP_HTTP_Response $response Response.
	 * @param \WP_REST_Server   $server   Server.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_HTTP_Response
	 */
	public static function after_dispatch( $response, $server, $request ) {
		if ( null === self::$needed || ! $response instanceof \WP_HTTP_Response || 404 !== $response->get_status() ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || 'rest_no_route' !== ( $data['code'] ?? '' ) ) {
			return $response;
		}
		self::register_rest();
		return rest_ensure_response( $server->dispatch( $request ) );
	}

	/**
	 * Store the map learned during a full registration.
	 */
	public static function save_map(): void {
		if ( null === self::$building ) {
			return;
		}
		$bases = array();
		foreach ( self::$building as $key => $modules ) {
			$bases[ $key ] = array_keys( $modules );
		}
		self::$building = null;
		update_option(
			self::OPTION,
			array(
				'sig'    => self::signature(),
				'bases'  => $bases,
				'always' => array_keys( self::$always ),
			),
			true
		);
	}

	/**
	 * The stored map, when it matches the current controllers and versions.
	 *
	 * @return array|null
	 */
	private static function map(): ?array {
		$map = get_option( self::OPTION );
		return is_array( $map ) && ( $map['sig'] ?? '' ) === self::signature() && is_array( $map['bases'] ?? null ) && is_array( $map['always'] ?? null ) ? $map : null;
	}

	private static function signature(): string {
		return md5(
			( defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : '' ) . '|'
			. ( defined( 'DOUBLESCALE_PRO_VERSION' ) ? DOUBLESCALE_PRO_VERSION : '' ) . '|'
			. wp_json_encode( self::$groups )
		);
	}

	/**
	 * "namespace/base" of the main REST request, or null when it is not one we can serve lazily.
	 */
	private static function request_key(): ?string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST || 'OPTIONS' === $method ) {
			return null;
		}
		$route = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		return is_string( $route ) ? self::key_for( $route ) : null;
	}

	/**
	 * @param string $route REST route, e.g. /doublescale/v1/contacts/5.
	 */
	private static function key_for( string $route ): ?string {
		return preg_match( '#^/([^/]+/v\d+)/([^/]+)#', $route, $m ) ? $m[1] . '/' . $m[2] : null;
	}

	/**
	 * @return string[] Route patterns registered so far.
	 */
	private static function route_keys(): array {
		return array_keys( self::route_prints() );
	}

	/**
	 * Every registered route pattern with a fingerprint of its handlers, so a
	 * module that adds to or replaces an existing route is noticed too.
	 *
	 * @return array<string, string> Route pattern => handlers fingerprint.
	 */
	private static function route_prints(): array {
		$server    = rest_get_server();
		$endpoints = ( fn() => $this->endpoints )->call( $server );
		$prints    = array();
		foreach ( $endpoints as $route => $handlers ) {
			$ids = array();
			foreach ( (array) $handlers as $handler ) {
				$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
				if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
					$ids[] = ( is_object( $callback[0] ) ? get_class( $callback[0] ) . '#' . spl_object_id( $callback[0] ) : (string) $callback[0] ) . '::' . (string) $callback[1];
				} elseif ( is_object( $callback ) ) {
					$ids[] = get_class( $callback ) . '#' . spl_object_id( $callback );
				} else {
					$ids[] = is_string( $callback ) ? $callback : '';
				}
			}
			$prints[ $route ] = implode( '|', $ids );
		}
		return $prints;
	}

	private static function enabled(): bool {
		if ( defined( 'DOUBLESCALE_EAGER_REGISTRIES' ) && DOUBLESCALE_EAGER_REGISTRIES ) {
			return false;
		}
		return (bool) apply_filters( 'doublescale_lazy_rest_routes', true );
	}
}
