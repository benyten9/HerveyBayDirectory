<?php
/**
 * JSON-RPC 2.0 envelope helpers for the MCP endpoint.
 *
 * @package DoubleScale\Core
 */

namespace DoubleScale\Core\Abilities\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the response shapes the MCP wire format requires.
 *
 * Kept separate from the request handler so the error codes — which are fixed
 * by the JSON-RPC spec and easy to get subtly wrong — live in one place.
 */
final class JsonRpc {

	public const PARSE_ERROR      = -32700;
	public const INVALID_REQUEST  = -32600;
	public const METHOD_NOT_FOUND = -32601;
	public const INVALID_PARAMS   = -32602;
	public const INTERNAL_ERROR   = -32603;

	/**
	 * Successful result envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $id     Request id (echoed back).
	 * @param mixed $result Result payload.
	 * @return array<string, mixed>
	 */
	public static function result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Error envelope.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed      $id      Request id.
	 * @param int        $code    JSON-RPC error code.
	 * @param string     $message Human-readable message.
	 * @param mixed|null $data    Optional structured detail.
	 * @return array<string, mixed>
	 */
	public static function error( $id, int $code, string $message, $data = null ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * A tools/call result payload.
	 *
	 * MCP wraps tool output in a content array. Structured data goes out as a
	 * JSON text block, which every client can render, plus `structuredContent`
	 * for clients that parse it natively.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value   Tool return value.
	 * @param bool  $is_error Whether the tool reported a failure.
	 * @return array<string, mixed>
	 */
	public static function tool_result( $value, bool $is_error = false ): array {
		$text = is_string( $value )
			? $value
			: (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$payload = array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => $is_error,
		);

		if ( ! $is_error && ! is_string( $value ) && ( is_array( $value ) || is_object( $value ) ) ) {
			$payload['structuredContent'] = $value;
		}

		return $payload;
	}
}
