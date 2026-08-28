<?php
/**
 * Self-contained MCP endpoint for DoubleScale.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityGuard;
use DoubleScale\Core\Abilities\AbilityRegistrar;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Speaks MCP over HTTP with no third-party plugin involved.
 *
 * DoubleScale must not depend on another vendor's plugin staying installed,
 * activated, or API-compatible to keep its own integration working, so the
 * protocol lives here. The surface is deliberately small: we publish tools
 * only — no resources, no prompts — which is four methods plus a handshake.
 *
 * Authorisation is NOT re-implemented. Every call resolves to a WordPress user
 * and then runs the ability's own permission callback, so the module gate, the
 * role check, and record ownership all apply exactly as they do in the admin.
 */
final class Endpoint {

	public const NAMESPACE_ROUTE = 'doublescale';
	public const ROUTE           = 'mcp';

	/**
	 * MCP revision this endpoint implements.
	 */
	public const PROTOCOL_VERSION = '2024-11-05';

	/**
	 * Register the REST route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_ROUTE,
			'/' . self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle' ),
					// Auth happens inside handle() so failures come back as
					// JSON-RPC errors the client can read, not bare HTTP 401s.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Public endpoint URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return get_rest_url( null, self::NAMESPACE_ROUTE . '/' . self::ROUTE );
	}

	/**
	 * Handle one JSON-RPC request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$reply = static function ( ?array $payload, int $status = 200 ) use ( $request ) {
			return self::respond( $payload, $status, $request );
		};

		$body = json_decode( (string) $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			return $reply( JsonRpc::error( null, JsonRpc::PARSE_ERROR, 'Parse error' ), 400 );
		}

		$id     = $body['id'] ?? null;
		$method = isset( $body['method'] ) ? (string) $body['method'] : '';
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		if ( '' === $method ) {
			return $reply( JsonRpc::error( $id, JsonRpc::INVALID_REQUEST, 'Missing method' ), 400 );
		}

		// Notifications carry no id and expect no response body.
		$is_notification = ! array_key_exists( 'id', $body );

		$user_id = Authenticator::resolve( $request );
		if ( $user_id <= 0 ) {
			return $reply(
				JsonRpc::error( $id, JsonRpc::INVALID_REQUEST, 'Authentication required. Send an Authorization header with a DoubleScale MCP API key or a WordPress application password.' ),
				401
			);
		}

		// Everything downstream — capability checks, ownership scoping — reads
		// the current user, so establish it before dispatching.
		wp_set_current_user( $user_id );

		if ( 0 === strpos( $method, 'notifications/' ) || $is_notification ) {
			return $reply( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				return $reply( JsonRpc::result( $id, self::initialize() ) );

			case 'ping':
				return $reply( JsonRpc::result( $id, new \stdClass() ) );

			case 'tools/list':
				return $reply(
					JsonRpc::result(
						$id,
						array(
							'tools' => self::tools(),
							// Fingerprint of the published set. A client that
							// keeps this can tell its cached list went stale by
							// comparing against the header on any later
							// response, without refetching to find out.
							'_meta' => array( 'toolsVersion' => ToolListVersion::current() ),
						)
					)
				);

			case 'tools/call':
				return $reply( self::call_tool( $id, $params ) );

			// Declared in capabilities as absent, but a client may still ask.
			case 'resources/list':
				return $reply( JsonRpc::result( $id, array( 'resources' => array() ) ) );

			case 'prompts/list':
				return $reply( JsonRpc::result( $id, array( 'prompts' => array() ) ) );

			default:
				return $reply(
					JsonRpc::error( $id, JsonRpc::METHOD_NOT_FOUND, sprintf( 'Method not found: %s', $method ) ),
					404
				);
		}
	}

	/**
	 * Handshake payload.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	private static function initialize(): array {
		return array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				// The published set genuinely changes when a module is toggled,
				// so a client must be prepared to refetch. See ToolListVersion.
				'tools' => array( 'listChanged' => true ),
			),
			'serverInfo'      => array(
				'name'    => 'DoubleScale CRM',
				'version' => defined( 'DOUBLESCALE_VERSION' ) ? DOUBLESCALE_VERSION : '1.0.0',
			),
			'instructions'    => __( 'DoubleScale CRM tools for this site, including reads and writes. Call doublescale-get-context first: it reports which modules are active and which tools you can actually use. Some tools change records or email customers — read each tool\'s description and annotations before calling it. Tools for disabled modules are not published, and results are scoped to what the connecting user is allowed to see. This HTTP transport cannot push a tools/list refresh; send header X-DoubleScale-Tools-Version (the value from the last response) so a stale cache is flagged in _meta.toolsStale, then call tools/list again.', 'doublescale' ),
		);
	}

	/**
	 * Convert a WordPress ability name into a legal MCP tool name.
	 *
	 * The two namespaces disagree: WP core REQUIRES exactly one forward slash
	 * (`doublescale/get-context`), while MCP clients reject it — Claude Desktop
	 * drops such tools with "unsupported names" and the connector silently
	 * exposes nothing. Swapping the slash for a dash satisfies both.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ability_name Fully-qualified ability name.
	 * @return string
	 */
	public static function to_tool_name( string $ability_name ): string {
		return str_replace( '/', '-', $ability_name );
	}

	/**
	 * Reverse {@see to_tool_name()} — resolve a tool name back to its ability.
	 *
	 * Only the FIRST dash is restored, because it is the namespace separator;
	 * every later dash belongs to the ability's own name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tool_name Tool name as sent by the client.
	 * @return string
	 */
	public static function to_ability_name( string $tool_name ): string {
		// Already a slash-qualified ability name — accept it as-is so a client
		// that read the name from the WP REST API still works.
		if ( false !== strpos( $tool_name, '/' ) ) {
			return $tool_name;
		}

		$prefix = rtrim( AbilityRegistrar::NAMESPACE_PREFIX, '/' );
		if ( 0 !== strpos( $tool_name, $prefix . '-' ) ) {
			return $tool_name;
		}

		return $prefix . '/' . substr( $tool_name, strlen( $prefix ) + 1 );
	}

	/**
	 * Published tools, in MCP's tool descriptor shape.
	 *
	 * Reads the live ability registry and filters by the connecting user's own
	 * permissions, so a client never sees a tool it would be refused.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function tools(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$tools = array();

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}

			$name = (string) $ability->get_name();
			if ( 0 !== strpos( $name, AbilityRegistrar::NAMESPACE_PREFIX ) ) {
				continue;
			}

			if ( method_exists( $ability, 'check_permissions' ) && true !== $ability->check_permissions() ) {
				continue;
			}

			$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : array();
			if ( empty( $schema ) || ! is_array( $schema ) ) {
				$schema = array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				);
			}

			$descriptor = array(
				// Dashed form — a slash here makes clients drop the tool.
				'name'        => self::to_tool_name( $name ),
				'description' => method_exists( $ability, 'get_description' ) ? $ability->get_description() : '',
				'inputSchema' => $schema,
			);

			$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
			if ( ! empty( $meta['annotations'] ) && is_array( $meta['annotations'] ) ) {
				$descriptor['annotations'] = self::annotations_for_mcp(
					$meta['annotations'],
					method_exists( $ability, 'get_label' ) ? (string) $ability->get_label() : $name
				);
			}

			if ( method_exists( $ability, 'get_output_schema' ) ) {
				$output = $ability->get_output_schema();
				if ( is_array( $output ) && array() !== $output ) {
					$descriptor['outputSchema'] = $output;
				}
			}

			$tools[] = $descriptor;
		}

		return $tools;
	}

	/**
	 * Execute one tool.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed                $id     Request id.
	 * @param array<string, mixed> $params Call params.
	 * @return array<string, mixed>
	 */
	private static function call_tool( $id, array $params ): array {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		if ( '' === $name ) {
			return JsonRpc::error( $id, JsonRpc::INVALID_PARAMS, 'Missing tool name' );
		}

		// Clients call the dashed name we advertised; the registry is keyed by
		// the slash-qualified ability name.
		$name = self::to_ability_name( $name );

		// Only our own abilities are callable here — this endpoint must not
		// become a generic bridge to every ability on the site.
		if ( 0 !== strpos( $name, AbilityRegistrar::NAMESPACE_PREFIX ) ) {
			return JsonRpc::error( $id, JsonRpc::METHOD_NOT_FOUND, sprintf( 'Unknown tool: %s', $name ) );
		}

		if ( ! function_exists( 'wp_get_ability' ) ) {
			return JsonRpc::error( $id, JsonRpc::INTERNAL_ERROR, 'Abilities API unavailable' );
		}

		$ability = wp_get_ability( $name );
		if ( ! is_object( $ability ) ) {
			// tools/list omits disabled-module tools, but clients often cache the
			// previous list — refuse with a clear "module off" tool error instead
			// of a protocol-level Unknown tool that agents gloss over as opaque.
			$owner = AbilityRegistrar::find_owner( $name );
			if ( null !== $owner && ! AbilityGuard::module_active( $owner['module_slug'] ) ) {
				return JsonRpc::result(
					$id,
					JsonRpc::tool_result(
						self::tool_error_payload(
							AbilityGuard::inactive_module_error(
								$name,
								$owner['module_slug'],
								$owner['module_label']
							)
						),
						true
					)
				);
			}

			return JsonRpc::error( $id, JsonRpc::METHOD_NOT_FOUND, sprintf( 'Unknown tool: %s', $name ) );
		}

		$permitted = method_exists( $ability, 'check_permissions' ) ? $ability->check_permissions() : true;
		if ( true !== $permitted ) {
			// A tool-level refusal is a normal outcome, not a protocol fault:
			// returning it as tool content lets the agent read the reason and
			// choose another tool instead of treating the session as broken.
			$payload = is_wp_error( $permitted )
				? self::tool_error_payload( $permitted )
				: array( 'error' => __( 'You do not have permission to use this tool.', 'doublescale' ) );

			return JsonRpc::result( $id, JsonRpc::tool_result( $payload, true ) );
		}

		$result = $ability->execute( $args );

		if ( is_wp_error( $result ) ) {
			return JsonRpc::result(
				$id,
				JsonRpc::tool_result( self::tool_error_payload( $result ), true )
			);
		}

		return JsonRpc::result( $id, JsonRpc::tool_result( $result ) );
	}

	/**
	 * Shape a WP_Error as tool content the agent can read and act on.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Error $error Error.
	 * @return array<string, mixed>
	 */
	private static function tool_error_payload( $error ): array {
		$payload = array(
			'error' => $error->get_error_message(),
			'code'  => $error->get_error_code(),
		);

		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['module'] ) && is_string( $data['module'] ) ) {
			$payload['module'] = $data['module'];
		}

		return $payload;
	}

	/**
	 * MCP annotation block from a WordPress ability's internal keys.
	 *
	 * `readonly` defaults to true here the same way the registrar does, so a
	 * missing key cannot be published as a write. Optional hints are omitted
	 * when the definition never set them — MCP treats absence as unknown.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $annotations Definition annotations.
	 * @param string               $title       Human label.
	 * @return array<string, mixed>
	 */
	public static function annotations_for_mcp( array $annotations, string $title ): array {
		$out = array(
			'title'           => $title,
			'readOnlyHint'    => (bool) ( $annotations['readonly'] ?? true ),
			'destructiveHint' => (bool) ( $annotations['destructive'] ?? false ),
		);

		if ( array_key_exists( 'idempotent', $annotations ) ) {
			$out['idempotentHint'] = (bool) $annotations['idempotent'];
		}

		if ( array_key_exists( 'openWorldHint', $annotations ) ) {
			$out['openWorldHint'] = (bool) $annotations['openWorldHint'];
		}

		return $out;
	}

	/**
	 * Stamp tool-list freshness onto a JSON-RPC result.
	 *
	 * HTTP cannot push `notifications/tools/list_changed`. The client sends
	 * the version it last saw; we tell it whether that view is still current.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $result  JSON-RPC result object.
	 * @param WP_REST_Request      $request Incoming request.
	 * @return array<string, mixed>
	 */
	public static function stamp_tools_meta( array $result, WP_REST_Request $request ): array {
		$presented = trim( (string) $request->get_header( ToolListVersion::REQUEST_HEADER ) );
		if ( '' === $presented ) {
			$presented = trim( (string) $request->get_header( ToolListVersion::RESPONSE_HEADER ) );
		}

		$meta = isset( $result['_meta'] ) && is_array( $result['_meta'] )
			? $result['_meta']
			: array();

		$stale = ToolListVersion::is_stale( $presented );

		$meta['toolsVersion'] = ToolListVersion::current();
		$meta['toolsStale']   = $stale;
		if ( $stale ) {
			$meta['toolsRefresh'] = 'Call tools/list; the published tool set has changed since the version you sent.';
		}

		$result['_meta'] = $meta;

		return $result;
	}

	/**
	 * Wrap a payload in a REST response with no-store headers.
	 *
	 * MCP responses are per-user and must never be served from an edge cache
	 * to a differently-authenticated caller.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed>|null $payload Response body.
	 * @param int                       $status  HTTP status.
	 * @param WP_REST_Request|null      $request Incoming request, for staleness.
	 * @return WP_REST_Response
	 */
	private static function respond( ?array $payload, int $status = 200, ?WP_REST_Request $request = null ): WP_REST_Response {
		if ( is_array( $payload ) && isset( $payload['result'] ) && is_array( $payload['result'] ) && $request instanceof WP_REST_Request ) {
			$payload['result'] = self::stamp_tools_meta( $payload['result'], $request );
		}

		$response = new WP_REST_Response( $payload, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );

		// Stamped on every response so a client can tell, without asking, that
		// the tool set it cached at connect time is no longer current.
		$response->header( ToolListVersion::RESPONSE_HEADER, ToolListVersion::current() );

		if ( $request instanceof WP_REST_Request ) {
			$presented = trim( (string) $request->get_header( ToolListVersion::REQUEST_HEADER ) );
			if ( '' === $presented ) {
				$presented = trim( (string) $request->get_header( ToolListVersion::RESPONSE_HEADER ) );
			}
			if ( ToolListVersion::is_stale( $presented ) ) {
				$response->header( 'X-DoubleScale-Tools-Stale', '1' );
			}
		}

		return $response;
	}
}
