<?php
/**
 * AI Provider
 *
 * Unified interface for AI provider communication. Extracted from
 * RestAiEmailBuilderController to support both the existing email builder
 * and the new AI Assistant with function calling.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Ai;


defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\MergeTags\MergeTagsManager;
use WP_Error;

/**
 * AiProvider class.
 *
 * @since 1.0.0
 */
class AiProvider {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Model identifier.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Api key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Base URL for custom providers.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Constructor.
	 *
	 * @param string $provider Provider name.
	 * @param string $model    Model identifier.
	 * @param string $api_key  Api key (decrypted).
	 * @param string $base_url Base URL for custom providers.
	 */
	public function __construct( string $provider, string $model, string $api_key, string $base_url = '' ) {
		$this->provider = $provider;
		$this->model    = $model;
		$this->api_key  = $api_key;
		$this->base_url = $base_url;
	}

	/**
	 * Factory: create an AiProvider from the current plugin settings.
	 *
	 * @return self
	 */
	public static function from_settings(): self {
		$ai = Settings::get( 'ai', array() );

		$provider = sanitize_text_field( $ai['provider'] ?? '' );
		$model    = sanitize_text_field( $ai['model'] ?? '' );
		$api_key  = Settings::decrypt_value( $ai['api_key'] ?? '' );
		$base_url = sanitize_url( $ai['base_url'] ?? '' );

		if ( empty( $model ) ) {
			$model = self::get_default_model( $provider );
		}

		return new self( $provider, $model, $api_key, $base_url );
	}

	/**
	 * Get the provider name.
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Get the model name.
	 *
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Simple single-turn call (backward-compatible convenience wrapper).
	 *
	 * Used by RestAiEmailBuilderController after refactoring.
	 *
	 * @param string $system  System prompt.
	 * @param string $user    User prompt.
	 * @param array  $options Optional settings (json_mode, max_tokens, timeout).
	 * @return string|WP_Error Response text or error.
	 */
	public function call( string $system, string $user, array $options = array() ) {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		return $this->chat( $messages, $options );
	}

	/**
	 * POST to a provider endpoint with retry/backoff on transient failures.
	 *
	 * Retries transport-level WP_Errors, HTTP 429 (rate limit), and HTTP 5xx
	 * (includes Anthropic's 529 overloaded_error) with exponential backoff and
	 * jitter, honoring the Retry-After header when present. The caller's
	 * `timeout` is treated as a total deadline so retries never extend the
	 * overall wait beyond what the caller budgeted.
	 *
	 * @param string $url    Endpoint URL.
	 * @param array  $args   wp_remote_post args (timeout, headers, body).
	 * @param string $method 'POST' or 'GET'.
	 * @return array|WP_Error
	 */
	private static function request_with_retry( string $url, array $args, string $method = 'POST' ) {
		$max_attempts = 3;
		$timeout      = isset( $args['timeout'] ) ? (int) $args['timeout'] : 120;
		$deadline     = microtime( true ) + $timeout;
		$response     = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining < 5 && null !== $response ) {
				break;
			}
			$args['timeout'] = (int) max( 5, min( $timeout, ceil( $remaining ) ) );

			$response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );

			if ( ! self::is_retryable_response( $response ) || $attempt >= $max_attempts ) {
				return $response;
			}

			$delay = self::get_retry_delay( $response, $attempt );
			if ( microtime( true ) + $delay >= $deadline ) {
				return $response;
			}
			usleep( (int) ( $delay * 1000000 ) );
		}

		return $response;
	}

	/**
	 * Whether a response warrants a retry (transport error, 429, or 5xx).
	 *
	 * @param array|WP_Error $response wp_remote_* response.
	 * @return bool
	 */
	private static function is_retryable_response( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return 429 === $code || $code >= 500;
	}

	/**
	 * Backoff delay for the next retry: Retry-After header when sane,
	 * otherwise exponential (1s, 2s, ...) plus up to 1s of jitter.
	 *
	 * @param array|WP_Error $response Last response.
	 * @param int            $attempt  1-based attempt number just completed.
	 * @return float Seconds to sleep.
	 */
	private static function get_retry_delay( $response, int $attempt ): float {
		if ( ! is_wp_error( $response ) ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			if ( is_numeric( $retry_after ) && (float) $retry_after > 0 && (float) $retry_after <= 30 ) {
				return (float) $retry_after;
			}
		}

		return pow( 2, $attempt - 1 ) + ( wp_rand( 0, 1000 ) / 1000 );
	}

	/**
	 * Multi-turn chat method. Used by the AI Assistant orchestration loop.
	 *
	 * @param array $messages Full message history (system/user/assistant/tool messages).
	 * @param array $options  {
	 *     @type array  $tools       Tool definitions (provider-specific format).
	 *     @type string $tool_choice 'auto' | 'none' | specific tool name.
	 *     @type bool   $json_mode   Request JSON response format.
	 *     @type int    $max_tokens  Max tokens for response.
	 *     @type int    $timeout     Request timeout in seconds.
	 * }
	 * @return array|string|WP_Error Full response array (when tools present) or text string.
	 */
	public function chat( array $messages, array $options = array() ) {
		$json_mode   = $options['json_mode'] ?? false;
		$max_tokens  = $options['max_tokens'] ?? 4096;
		$timeout     = $options['timeout'] ?? 120;
		$tools       = $options['tools'] ?? array();
		$tool_choice = $options['tool_choice'] ?? 'auto';

		switch ( $this->provider ) {
			case 'openai':
				return $this->call_openai( $messages, $json_mode, $max_tokens, $timeout, $tools, $tool_choice );
			case 'anthropic':
				return $this->call_anthropic( $messages, $max_tokens, $timeout, $tools, $tool_choice );
			case 'gemini':
				return $this->call_gemini( $messages, $max_tokens, $timeout, $tools, $tool_choice );
			case 'custom':
				if ( empty( $this->base_url ) ) {
					return new WP_Error(
						'missing_base_url',
						__( 'A Base URL is required for custom/compatible providers.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}
				return $this->call_openai_compatible( $messages, $json_mode, $max_tokens, $timeout, $tools, $tool_choice );
			default:
				return new WP_Error(
					'invalid_provider',
					__( 'Invalid AI provider configured.', 'doublescale' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Call OpenAI Api.
	 *
	 * @param array  $messages   Message history.
	 * @param bool   $json_mode  Whether to request JSON response format.
	 * @param int    $max_tokens Max tokens.
	 * @param int    $timeout    Request timeout.
	 * @param array  $tools      Tool definitions.
	 * @param string $tool_choice Tool choice mode.
	 * @return array|string|WP_Error
	 */
	private function call_openai( array $messages, bool $json_mode = false, int $max_tokens = 4096, int $timeout = 120, array $tools = array(), string $tool_choice = 'auto' ) {
		$body = $this->build_openai_body( $messages, $json_mode, $max_tokens, $tools, $tool_choice );

		$response = self::request_with_retry(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf( __( 'AI request failed: %s', 'doublescale' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = $body['error']['message'] ?? __( 'Unknown error from OpenAI', 'doublescale' );
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf( __( 'OpenAI Api error: %s', 'doublescale' ), $error_msg ),
				array( 'status' => $code )
			);
		}

		// When tools are present, return the full response for tool call inspection.
		if ( ! empty( $tools ) ) {
			return $body;
		}

		return $body['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * Call any OpenAI-compatible Api (OpenRouter, Ollama, LM Studio, Groq, etc.).
	 *
	 * @param array  $messages   Message history.
	 * @param bool   $json_mode  Whether to request JSON response format.
	 * @param int    $max_tokens Max tokens.
	 * @param int    $timeout    Request timeout.
	 * @param array  $tools      Tool definitions.
	 * @param string $tool_choice Tool choice mode.
	 * @return array|string|WP_Error
	 */
	private function call_openai_compatible( array $messages, bool $json_mode = false, int $max_tokens = 4096, int $timeout = 120, array $tools = array(), string $tool_choice = 'auto' ) {
		$base_url = rtrim( $this->base_url, '/' );
		$endpoint = $base_url . '/chat/completions';
		$headers  = $this->build_openai_compatible_headers();

		$body = array(
			'model'      => $this->model,
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
		);

		if ( $json_mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
			if ( 'none' === $tool_choice ) {
				$body['tool_choice'] = 'none';
			}
		}

		$response = self::request_with_retry(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf( __( 'AI request failed: %s', 'doublescale' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code   = wp_remote_retrieve_response_code( $response );
		$parsed = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = $parsed['error']['message'] ?? __( 'Unknown error from AI provider', 'doublescale' );
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf( __( 'AI provider error: %s', 'doublescale' ), $error_msg ),
				array( 'status' => $code )
			);
		}

		if ( ! empty( $tools ) ) {
			return $parsed;
		}

		return $parsed['choices'][0]['message']['content'] ?? '';
	}

	/**
	 * Call Anthropic (Claude) Api.
	 *
	 * @param array  $messages   Message history.
	 * @param int    $max_tokens Max tokens.
	 * @param int    $timeout    Request timeout.
	 * @param array  $tools      Tool definitions.
	 * @param string $tool_choice Tool choice mode.
	 * @return array|string|WP_Error
	 */
	private function call_anthropic( array $messages, int $max_tokens = 4096, int $timeout = 120, array $tools = array(), string $tool_choice = 'auto' ) {
		$request_body = $this->build_anthropic_body( $messages, $max_tokens, $tools, $tool_choice );

		$response = self::request_with_retry(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf( __( 'AI request failed: %s', 'doublescale' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = $body['error']['message'] ?? __( 'Unknown error from Anthropic', 'doublescale' );
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf( __( 'Anthropic Api error: %s', 'doublescale' ), $error_msg ),
				array( 'status' => $code )
			);
		}

		if ( ! empty( $tools ) ) {
			return $body;
		}

		return $body['content'][0]['text'] ?? '';
	}

	/**
	 * Call Google Gemini Api.
	 *
	 * @param array  $messages   Message history.
	 * @param int    $max_tokens Max tokens.
	 * @param int    $timeout    Request timeout.
	 * @param array  $tools      Tool definitions.
	 * @param string $tool_choice Tool choice mode.
	 * @return array|string|WP_Error
	 */
	private function call_gemini( array $messages, int $max_tokens = 4096, int $timeout = 120, array $tools = array(), string $tool_choice = 'auto' ) {
		// API key goes in the x-goog-api-key header (not the query string) so
		// it never lands in server access logs or proxy logs.
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':generateContent';

		$request_body = $this->build_gemini_body( $messages, $max_tokens, $tools, $tool_choice );

		$response = self::request_with_retry(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ai_request_failed',
				/* translators: %s: HTTP error message */
				sprintf( __( 'AI request failed: %s', 'doublescale' ), $response->get_error_message() ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$error_msg = $body['error']['message'] ?? __( 'Unknown error from Gemini', 'doublescale' );
			return new WP_Error(
				'ai_api_error',
				/* translators: %s: error message returned by the AI provider */
				sprintf( __( 'Gemini Api error: %s', 'doublescale' ), $error_msg ),
				array( 'status' => $code )
			);
		}

		if ( ! empty( $tools ) ) {
			return $body;
		}

		return $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
	}

	/**
	 * Build the OpenAI chat/completions request body.
	 *
	 * @param array  $messages    Message history.
	 * @param bool   $json_mode   Whether to request JSON response format.
	 * @param int    $max_tokens  Max tokens.
	 * @param array  $tools       Tool definitions.
	 * @param string $tool_choice Tool choice mode.
	 * @return array
	 */
	private function build_openai_body( array $messages, bool $json_mode, int $max_tokens, array $tools, string $tool_choice ): array {
		// Reasoning models (gpt-5, o1, o3) use max_completion_tokens which covers
		// both internal reasoning AND visible output. Non-reasoning models use
		// max_tokens which only counts output.
		$is_reasoning = (bool) preg_match( '/^(gpt-5|o[1-9])/', $this->model );
		$token_key    = $is_reasoning ? 'max_completion_tokens' : 'max_tokens';
		$token_budget = $is_reasoning ? max( $max_tokens * 4, 32768 ) : $max_tokens;

		$body = array(
			'model'    => $this->model,
			'messages' => $messages,
			$token_key => $token_budget,
		);

		if ( $json_mode ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
			if ( 'none' === $tool_choice ) {
				$body['tool_choice'] = 'none';
			}
		}

		return $body;
	}

	/**
	 * Headers for OpenAI-compatible providers (OpenRouter, Ollama, Groq, ...).
	 *
	 * @return array<string, string>
	 */
	private function build_openai_compatible_headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $this->api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}

		// OpenRouter requires HTTP-Referer and X-Title headers.
		if ( strpos( $this->base_url, 'openrouter.ai' ) !== false ) {
			$headers['HTTP-Referer'] = home_url();
			$headers['X-Title']      = get_bloginfo( 'name' );
		}

		return $headers;
	}

	/**
	 * Build the Anthropic Messages request body (system block extraction,
	 * OpenAI-shape → Anthropic-shape message conversion, prompt caching).
	 *
	 * @param array  $messages    Message history (OpenAI shape).
	 * @param int    $max_tokens  Max tokens.
	 * @param array  $tools       Tool definitions (Anthropic shape).
	 * @param string $tool_choice Tool choice mode.
	 * @return array
	 */
	private function build_anthropic_body( array $messages, int $max_tokens, array $tools, string $tool_choice ): array {
		// Anthropic separates system from messages. Extract system messages.
		$system_parts   = array();
		$anthropic_msgs = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === $msg['role'] ) {
				$system_parts[] = $msg['content'];
			} elseif ( 'tool' === $msg['role'] ) {
				// Anthropic uses tool_result content blocks inside the user turn.
				$anthropic_msgs[] = array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => $msg['tool_call_id'] ?? '',
							'content'     => is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] ),
						),
					),
				);
			} elseif ( 'assistant' === $msg['role'] && isset( $msg['tool_calls'] ) ) {
				// Convert OpenAI-style tool_calls to Anthropic content blocks.
				$content_blocks = array();
				if ( ! empty( $msg['content'] ) ) {
					$content_blocks[] = array(
						'type' => 'text',
						'text' => $msg['content'],
					);
				}
				foreach ( $msg['tool_calls'] as $tc ) {
					$content_blocks[] = array(
						'type'  => 'tool_use',
						'id'    => $tc['id'],
						'name'  => $tc['function']['name'],
						'input' => json_decode( $tc['function']['arguments'], true ) ?? array(),
					);
				}
				$anthropic_msgs[] = array(
					'role'    => 'assistant',
					'content' => $content_blocks,
				);
			} else {
				$anthropic_msgs[] = array(
					'role'    => $msg['role'],
					'content' => $msg['content'],
				);
			}
		}

		$request_body = array(
			'model'      => $this->model,
			'max_tokens' => $max_tokens,
			'messages'   => $anthropic_msgs,
		);

		// Prompt caching: the FIRST system message is treated as the stable
		// prefix (role/business instructions) and marked with cache_control so
		// Anthropic caches tools + that block. Later system messages (per-turn
		// docs / page context) stay uncached. See AI_Context_Builder, which
		// splits the prompt into static + dynamic system messages for this.
		$system_blocks = array();
		foreach ( $system_parts as $i => $part ) {
			$block = array(
				'type' => 'text',
				'text' => $part,
			);
			if ( 0 === $i ) {
				$block['cache_control'] = array( 'type' => 'ephemeral' );
			}
			$system_blocks[] = $block;
		}
		if ( ! empty( $system_blocks ) ) {
			$request_body['system'] = $system_blocks;
		}

		if ( ! empty( $tools ) ) {
			$request_body['tools'] = $tools;

			// Cache the (stable) tool definitions independently of the system
			// prompt by marking the last tool as a cache breakpoint.
			$last_tool = count( $request_body['tools'] ) - 1;
			if ( is_array( $request_body['tools'][ $last_tool ] ) ) {
				$request_body['tools'][ $last_tool ]['cache_control'] = array( 'type' => 'ephemeral' );
			}

			if ( 'none' === $tool_choice ) {
				$request_body['tool_choice'] = array( 'type' => 'none' );
			} else {
				$request_body['tool_choice'] = array( 'type' => 'auto' );
			}
		}

		return $request_body;
	}

	/**
	 * Build the Gemini generateContent request body.
	 *
	 * @param array  $messages    Message history (OpenAI shape).
	 * @param int    $max_tokens  Max tokens.
	 * @param array  $tools       Tool definitions (Gemini shape).
	 * @param string $tool_choice Tool choice mode.
	 * @return array
	 */
	private function build_gemini_body( array $messages, int $max_tokens, array $tools, string $tool_choice ): array {
		$system_instruction = '';
		$contents           = array();

		foreach ( $messages as $msg ) {
			if ( 'system' === $msg['role'] ) {
				$system_instruction .= $msg['content'] . "\n";
			} elseif ( 'assistant' === $msg['role'] ) {
				if ( isset( $msg['tool_calls'] ) ) {
					$parts = array();
					foreach ( $msg['tool_calls'] as $tc ) {
						$parts[] = array(
							'functionCall' => array(
								'name' => $tc['function']['name'],
								'args' => json_decode( $tc['function']['arguments'], true ) ?? array(),
							),
						);
					}
					$contents[] = array(
						'role'  => 'model',
						'parts' => $parts,
					);
				} else {
					$contents[] = array(
						'role'  => 'model',
						'parts' => array( array( 'text' => $msg['content'] ) ),
					);
				}
			} elseif ( 'tool' === $msg['role'] ) {
				$contents[] = array(
					'role'  => 'function',
					'parts' => array(
						array(
							'functionResponse' => array(
								'name'     => $msg['name'] ?? '',
								'response' => json_decode( $msg['content'], true ) ?? array( 'result' => $msg['content'] ),
							),
						),
					),
				);
			} else {
				$contents[] = array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $msg['content'] ) ),
				);
			}
		}

		$request_body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => $max_tokens,
			),
		);

		if ( ! empty( trim( $system_instruction ) ) ) {
			$request_body['system_instruction'] = array(
				'parts' => array( array( 'text' => trim( $system_instruction ) ) ),
			);
		}

		if ( ! empty( $tools ) ) {
			$request_body['tools'] = $tools;
			if ( 'none' === $tool_choice ) {
				$request_body['tool_config'] = array(
					'function_calling_config' => array( 'mode' => 'NONE' ),
				);
			} else {
				$request_body['tool_config'] = array(
					'function_calling_config' => array( 'mode' => 'AUTO' ),
				);
			}
		}

		return $request_body;
	}

	/**
	 * Streaming variant of {@see chat()}. Emits text deltas through $on_delta
	 * as they arrive from the provider and returns the SAME final shape as
	 * chat() (full response array when tools are present, plain text string
	 * otherwise), so Tool_Adapter parsing works unchanged on the result.
	 *
	 * Falls back to the blocking chat() when cURL is unavailable (the
	 * wp_remote_* stack buffers the whole response and cannot stream).
	 *
	 * @param array    $messages Full message history (system/user/assistant/tool).
	 * @param array    $options  Same options as chat().
	 * @param callable $on_delta function( string $text_chunk ): void.
	 * @return array|string|WP_Error
	 */
	public function chat_stream( array $messages, array $options, callable $on_delta ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return $this->chat( $messages, $options );
		}

		$json_mode   = $options['json_mode'] ?? false;
		$max_tokens  = $options['max_tokens'] ?? 4096;
		$timeout     = $options['timeout'] ?? 120;
		$tools       = $options['tools'] ?? array();
		$tool_choice = $options['tool_choice'] ?? 'auto';

		switch ( $this->provider ) {
			case 'openai':
				return $this->stream_openai_compatible(
					'https://api.openai.com/v1/chat/completions',
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->api_key,
					),
					$this->build_openai_body( $messages, $json_mode, $max_tokens, $tools, $tool_choice ),
					$timeout,
					$on_delta,
					! empty( $tools )
				);
			case 'custom':
				if ( empty( $this->base_url ) ) {
					return new WP_Error(
						'missing_base_url',
						__( 'A Base URL is required for custom/compatible providers.', 'doublescale' ),
						array( 'status' => 400 )
					);
				}
				$body = array(
					'model'      => $this->model,
					'messages'   => $messages,
					'max_tokens' => $max_tokens,
				);
				if ( $json_mode ) {
					$body['response_format'] = array( 'type' => 'json_object' );
				}
				if ( ! empty( $tools ) ) {
					$body['tools'] = $tools;
					if ( 'none' === $tool_choice ) {
						$body['tool_choice'] = 'none';
					}
				}
				return $this->stream_openai_compatible(
					rtrim( $this->base_url, '/' ) . '/chat/completions',
					$this->build_openai_compatible_headers(),
					$body,
					$timeout,
					$on_delta,
					! empty( $tools )
				);
			case 'anthropic':
				return $this->stream_anthropic( $messages, $max_tokens, $timeout, $tools, $tool_choice, $on_delta );
			case 'gemini':
				return $this->stream_gemini( $messages, $max_tokens, $timeout, $tools, $tool_choice, $on_delta );
			default:
				return new WP_Error(
					'invalid_provider',
					__( 'Invalid AI provider configured.', 'doublescale' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Stream an OpenAI-compatible chat/completions SSE response.
	 *
	 * @param string   $url        Endpoint URL.
	 * @param array    $headers    Request headers (key => value).
	 * @param array    $body       Request body (without `stream`).
	 * @param int      $timeout    Timeout in seconds.
	 * @param callable $on_delta   Text delta callback.
	 * @param bool     $with_tools Whether tools were requested (controls return shape).
	 * @return array|string|WP_Error
	 */
	private function stream_openai_compatible( string $url, array $headers, array $body, int $timeout, callable $on_delta, bool $with_tools ) {
		$body['stream'] = true;

		$state = array(
			'content'    => '',
			'tool_calls' => array(),
			'finish'     => null,
		);

		$on_line = function ( string $payload ) use ( &$state, $on_delta ) {
			if ( '[DONE]' === $payload ) {
				return;
			}
			$json  = json_decode( $payload, true );
			$delta = $json['choices'][0]['delta'] ?? array();

			if ( isset( $delta['content'] ) && is_string( $delta['content'] ) && '' !== $delta['content'] ) {
				$state['content'] .= $delta['content'];
				$on_delta( $delta['content'] );
			}

			foreach ( $delta['tool_calls'] ?? array() as $tc ) {
				$idx = (int) ( $tc['index'] ?? 0 );
				if ( ! isset( $state['tool_calls'][ $idx ] ) ) {
					$state['tool_calls'][ $idx ] = array(
						'id'       => '',
						'type'     => 'function',
						'function' => array(
							'name'      => '',
							'arguments' => '',
						),
					);
				}
				if ( ! empty( $tc['id'] ) ) {
					$state['tool_calls'][ $idx ]['id'] = $tc['id'];
				}
				if ( ! empty( $tc['function']['name'] ) ) {
					$state['tool_calls'][ $idx ]['function']['name'] .= $tc['function']['name'];
				}
				if ( isset( $tc['function']['arguments'] ) ) {
					$state['tool_calls'][ $idx ]['function']['arguments'] .= $tc['function']['arguments'];
				}
			}

			if ( ! empty( $json['choices'][0]['finish_reason'] ) ) {
				$state['finish'] = $json['choices'][0]['finish_reason'];
			}
		};

		$result = $this->curl_stream_sse( $url, $headers, $body, $timeout, $on_line );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$message = array(
			'role'    => 'assistant',
			'content' => $state['content'],
		);
		if ( ! empty( $state['tool_calls'] ) ) {
			ksort( $state['tool_calls'] );
			$message['tool_calls'] = array_values( $state['tool_calls'] );
		}

		$response = array(
			'choices' => array(
				array(
					'message'       => $message,
					'finish_reason' => $state['finish'],
				),
			),
		);

		return $with_tools ? $response : $state['content'];
	}

	/**
	 * Stream an Anthropic Messages SSE response.
	 *
	 * @param array    $messages    Message history (OpenAI shape).
	 * @param int      $max_tokens  Max tokens.
	 * @param int      $timeout     Timeout in seconds.
	 * @param array    $tools       Tool definitions (Anthropic shape).
	 * @param string   $tool_choice Tool choice mode.
	 * @param callable $on_delta    Text delta callback.
	 * @return array|string|WP_Error
	 */
	private function stream_anthropic( array $messages, int $max_tokens, int $timeout, array $tools, string $tool_choice, callable $on_delta ) {
		$body           = $this->build_anthropic_body( $messages, $max_tokens, $tools, $tool_choice );
		$body['stream'] = true;

		$state = array(
			'blocks'      => array(),
			'json_parts'  => array(),
			'stop_reason' => null,
		);

		$on_line = function ( string $payload ) use ( &$state, $on_delta ) {
			$json = json_decode( $payload, true );
			if ( ! is_array( $json ) ) {
				return;
			}

			switch ( $json['type'] ?? '' ) {
				case 'content_block_start':
					$idx   = (int) ( $json['index'] ?? 0 );
					$block = $json['content_block'] ?? array();
					if ( 'tool_use' === ( $block['type'] ?? '' ) ) {
						$state['blocks'][ $idx ]     = array(
							'type'  => 'tool_use',
							'id'    => $block['id'] ?? '',
							'name'  => $block['name'] ?? '',
							'input' => array(),
						);
						$state['json_parts'][ $idx ] = '';
					} else {
						$state['blocks'][ $idx ] = array(
							'type' => 'text',
							'text' => $block['text'] ?? '',
						);
					}
					break;

				case 'content_block_delta':
					$idx   = (int) ( $json['index'] ?? 0 );
					$delta = $json['delta'] ?? array();
					if ( 'text_delta' === ( $delta['type'] ?? '' ) && isset( $state['blocks'][ $idx ] ) ) {
						$text = (string) ( $delta['text'] ?? '' );
						if ( '' !== $text ) {
							$state['blocks'][ $idx ]['text'] = ( $state['blocks'][ $idx ]['text'] ?? '' ) . $text;
							$on_delta( $text );
						}
					} elseif ( 'input_json_delta' === ( $delta['type'] ?? '' ) && isset( $state['json_parts'][ $idx ] ) ) {
						$state['json_parts'][ $idx ] .= (string) ( $delta['partial_json'] ?? '' );
					}
					break;

				case 'message_delta':
					if ( ! empty( $json['delta']['stop_reason'] ) ) {
						$state['stop_reason'] = $json['delta']['stop_reason'];
					}
					break;

				case 'error':
					// Mid-stream error event (e.g. overloaded). Recorded as stop reason.
					$state['stop_reason'] = 'error';
					break;
			}
		};

		$result = $this->curl_stream_sse(
			'https://api.anthropic.com/v1/messages',
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
			),
			$body,
			$timeout,
			$on_line
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ksort( $state['blocks'] );
		foreach ( $state['json_parts'] as $idx => $partial_json ) {
			if ( isset( $state['blocks'][ $idx ] ) ) {
				$state['blocks'][ $idx ]['input'] = json_decode( $partial_json, true ) ?? array();
			}
		}

		$response = array(
			'content'     => array_values( $state['blocks'] ),
			'stop_reason' => $state['stop_reason'],
		);

		if ( ! empty( $tools ) ) {
			return $response;
		}

		foreach ( $response['content'] as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				return $block['text'] ?? '';
			}
		}

		return '';
	}

	/**
	 * Stream a Gemini streamGenerateContent SSE response.
	 *
	 * @param array    $messages    Message history (OpenAI shape).
	 * @param int      $max_tokens  Max tokens.
	 * @param int      $timeout     Timeout in seconds.
	 * @param array    $tools       Tool definitions (Gemini shape).
	 * @param string   $tool_choice Tool choice mode.
	 * @param callable $on_delta    Text delta callback.
	 * @return array|string|WP_Error
	 */
	private function stream_gemini( array $messages, int $max_tokens, int $timeout, array $tools, string $tool_choice, callable $on_delta ) {
		$url  = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->model ) . ':streamGenerateContent?alt=sse';
		$body = $this->build_gemini_body( $messages, $max_tokens, $tools, $tool_choice );

		$state = array(
			'text'           => '',
			'function_calls' => array(),
		);

		$on_line = function ( string $payload ) use ( &$state, $on_delta ) {
			$json  = json_decode( $payload, true );
			$parts = $json['candidates'][0]['content']['parts'] ?? array();

			foreach ( $parts as $part ) {
				if ( isset( $part['text'] ) && '' !== $part['text'] ) {
					$state['text'] .= $part['text'];
					$on_delta( $part['text'] );
				}
				if ( isset( $part['functionCall'] ) ) {
					$state['function_calls'][] = array( 'functionCall' => $part['functionCall'] );
				}
			}
		};

		$result = $this->curl_stream_sse(
			$url,
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $this->api_key,
			),
			$body,
			$timeout,
			$on_line
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $tools ) ) {
			return $state['text'];
		}

		$parts = array();
		if ( '' !== $state['text'] ) {
			$parts[] = array( 'text' => $state['text'] );
		}
		$parts = array_merge( $parts, $state['function_calls'] );

		return array(
			'candidates' => array(
				array(
					'content' => array(
						'role'  => 'model',
						'parts' => $parts,
					),
				),
			),
		);
	}

	/**
	 * Low-level SSE POST over cURL. Calls $on_data with the payload of every
	 * `data:` SSE line as it arrives. Non-200 responses are buffered and
	 * returned as a WP_Error with the provider's error message.
	 *
	 * Retries (transport failure / 429 / 5xx) only happen when NOTHING has
	 * been emitted yet — once deltas have reached the caller the stream is
	 * committed and a mid-stream failure surfaces as an error.
	 *
	 * @param string   $url     Endpoint URL.
	 * @param array    $headers Request headers (key => value).
	 * @param array    $body    JSON-serializable request body.
	 * @param int      $timeout Timeout in seconds.
	 * @param callable $on_data SSE data-payload callback.
	 * @return true|WP_Error
	 */
	private function curl_stream_sse( string $url, array $headers, array $body, int $timeout, callable $on_data ) {
		$header_lines = array( 'Accept: text/event-stream' );
		foreach ( $headers as $key => $value ) {
			$header_lines[] = $key . ': ' . $value;
		}

		$max_attempts = 3;
		$deadline     = microtime( true ) + $timeout;
		$last_error   = null;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining < 5 && $attempt > 1 ) {
				break;
			}

			$emitted     = false;
			$line_buffer = '';
			$error_body  = '';
			$status      = 0;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- wp_remote_* buffers the full response; raw cURL is required for token streaming.
			$ch = curl_init( $url );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- see above.
			curl_setopt_array(
				$ch,
				array(
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
					CURLOPT_HTTPHEADER     => $header_lines,
					CURLOPT_TIMEOUT        => (int) max( 5, min( $timeout, ceil( $remaining ) ) ),
					CURLOPT_CONNECTTIMEOUT => 15,
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_WRITEFUNCTION  => function ( $handle, $data ) use ( &$line_buffer, &$error_body, &$status, &$emitted, $on_data ) {
						if ( 0 === $status ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- streaming context.
							$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
						}
						if ( 200 !== $status ) {
							$error_body .= $data;
							return strlen( $data );
						}

						$line_buffer .= $data;
						$pos          = strpos( $line_buffer, "\n" );
						while ( false !== $pos ) {
							$line        = rtrim( substr( $line_buffer, 0, $pos ), "\r" );
							$line_buffer = substr( $line_buffer, $pos + 1 );
							if ( 0 === strpos( $line, 'data:' ) ) {
								$payload = trim( substr( $line, 5 ) );
								if ( '' !== $payload ) {
									$emitted = true;
									$on_data( $payload );
								}
							}
							$pos = strpos( $line_buffer, "\n" );
						}

						return strlen( $data );
					},
				)
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- see curl_init above.
			curl_exec( $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- see curl_init above.
			$errno = curl_errno( $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- see curl_init above.
			$errmsg = curl_error( $ch );
			if ( 0 === $status ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- see curl_init above.
				$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- see curl_init above.
			curl_close( $ch );

			// Flush any trailing line that lacked a final newline.
			if ( 200 === $status && '' !== trim( $line_buffer ) ) {
				$line = trim( $line_buffer );
				if ( 0 === strpos( $line, 'data:' ) ) {
					$payload = trim( substr( $line, 5 ) );
					if ( '' !== $payload ) {
						$on_data( $payload );
					}
				}
			}

			if ( 200 === $status && ! $errno ) {
				return true;
			}

			// Build the error for this attempt.
			if ( $errno ) {
				$last_error = new WP_Error(
					'ai_request_failed',
					/* translators: %s: HTTP error message */
					sprintf( __( 'AI request failed: %s', 'doublescale' ), $errmsg ),
					array( 'status' => 502 )
				);
			} else {
				$parsed     = json_decode( $error_body, true );
				$error_msg  = $parsed['error']['message'] ?? $parsed[0]['error']['message'] ?? __( 'Unknown error from AI provider', 'doublescale' );
				$last_error = new WP_Error(
					'ai_api_error',
					/* translators: %s: error message returned by the AI provider */
					sprintf( __( 'AI provider error: %s', 'doublescale' ), $error_msg ),
					array( 'status' => $status )
				);
			}

			// Never retry once data reached the caller, on non-retryable
			// statuses, or when out of attempts/budget.
			$retryable = $errno || 429 === $status || $status >= 500;
			if ( $emitted || ! $retryable || $attempt >= $max_attempts ) {
				return $last_error;
			}

			$delay = pow( 2, $attempt - 1 ) + ( wp_rand( 0, 1000 ) / 1000 );
			if ( microtime( true ) + $delay >= $deadline ) {
				return $last_error;
			}
			usleep( (int) ( $delay * 1000000 ) );
		}

		return $last_error ?? new WP_Error( 'ai_request_failed', __( 'AI request failed.', 'doublescale' ), array( 'status' => 502 ) );
	}

	/**
	 * Fetch available models from the provider's Api.
	 *
	 * @param string $provider Provider name (use override instead of $this->provider for settings preview).
	 * @param string $api_key  Api key (use override for settings preview).
	 * @param string $base_url Base URL override.
	 * @return array|WP_Error List of { value, label } model objects.
	 */
	public static function fetch_models( string $provider, string $api_key, string $base_url = '' ) {
		switch ( $provider ) {
			case 'openai':
				return self::fetch_openai_models( $api_key );
			case 'anthropic':
				return self::fetch_anthropic_models( $api_key );
			case 'gemini':
				return self::fetch_gemini_models( $api_key );
			case 'custom':
				return self::fetch_custom_models( $api_key, $base_url );
			default:
				return new WP_Error( 'invalid_provider', __( 'Invalid provider.', 'doublescale' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Fetch available GPT models from OpenAI.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error
	 */
	private static function fetch_openai_models( string $api_key ) {
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'openai_error',
				$body['error']['message'] ?? __( 'Failed to fetch OpenAI models.', 'doublescale' ),
				array( 'status' => $code )
			);
		}

		$models = array();
		foreach ( $body['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( ! preg_match( '/^gpt-(4o(-mini)?|4\.1(-mini|-nano)?|5(\.\d+)?(-mini)?)$/', $id ) ) {
				continue;
			}
			$models[] = array(
				'value' => $id,
				'label' => $id,
			);
		}

		usort(
			$models,
			function ( $a, $b ) {
				return strcmp( $b['value'], $a['value'] );
			}
		);

		return $models;
	}

	/**
	 * Return Anthropic Claude models.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error
	 */
	private static function fetch_anthropic_models( string $api_key ) {
		$response = wp_remote_get(
			'https://api.anthropic.com/v1/models',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'anthropic-beta'    => 'models-list-2025-02-19',
				),
			)
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$body   = json_decode( wp_remote_retrieve_body( $response ), true );
			$models = array();
			foreach ( $body['data'] ?? array() as $model ) {
				$id = $model['id'] ?? '';
				if ( strpos( $id, 'claude' ) === 0 ) {
					$models[] = array(
						'value' => $id,
						'label' => $model['display_name'] ?? $id,
					);
				}
			}
			if ( ! empty( $models ) ) {
				return $models;
			}
		}

		// Fallback to well-known current models.
		return array(
			array(
				'value' => 'claude-opus-4-7',
				'label' => 'Claude Opus 4.7',
			),
			array(
				'value' => 'claude-opus-4-6',
				'label' => 'Claude Opus 4.6',
			),
			array(
				'value' => 'claude-sonnet-4-6',
				'label' => 'Claude Sonnet 4.6',
			),
			array(
				'value' => 'claude-haiku-4-5-20251001',
				'label' => 'Claude Haiku 4.5',
			),
		);
	}

	/**
	 * Fetch available Gemini models from Google.
	 *
	 * @param string $api_key Api key.
	 * @return array|WP_Error
	 */
	private static function fetch_gemini_models( string $api_key ) {
		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models',
			array(
				'timeout' => 15,
				'headers' => array( 'x-goog-api-key' => $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'fetch_failed', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'gemini_error',
				$body['error']['message'] ?? __( 'Failed to fetch Gemini models.', 'doublescale' ),
				array( 'status' => $code )
			);
		}

		$models = array();
		foreach ( $body['models'] ?? array() as $model ) {
			if ( ! in_array( 'generateContent', $model['supportedGenerationMethods'] ?? array(), true ) ) {
				continue;
			}

			$id = str_replace( 'models/', '', $model['name'] ?? '' );

			if ( preg_match( '/tts|image|robotics|computer.use|research|banana|nano/', $id ) ) {
				continue;
			}
			if ( strpos( $id, 'gemma' ) === 0 ) {
				continue;
			}

			$models[] = array(
				'value' => $id,
				'label' => $model['displayName'] ?? $id,
			);
		}

		return $models;
	}

	/**
	 * Fetch models from any OpenAI-compatible /v1/models endpoint.
	 *
	 * @param string $api_key  Api key.
	 * @param string $base_url Base URL.
	 * @return array|WP_Error
	 */
	private static function fetch_custom_models( string $api_key, string $base_url ) {
		$base_url = rtrim( $base_url, '/' );
		$endpoint = $base_url . '/models';

		$headers = array();
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout' => 15,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: %s: base URL of the AI provider */
				sprintf( __( 'Could not reach %s. Is the server running?', 'doublescale' ), $base_url ),
				array( 'status' => 502 )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'provider_error',
				$body['error']['message'] ?? __( 'Failed to fetch models from the provider.', 'doublescale' ),
				array( 'status' => $code )
			);
		}

		$models = array();
		foreach ( $body['data'] ?? array() as $model ) {
			$id = $model['id'] ?? '';
			if ( empty( $id ) ) {
				continue;
			}
			$models[] = array(
				'value' => $id,
				'label' => $model['name'] ?? $id,
			);
		}

		usort(
			$models,
			function ( $a, $b ) {
				return strcmp( $a['value'], $b['value'] );
			}
		);

		return $models;
	}

	/**
	 * Get default model for a provider.
	 *
	 * @param string $provider Provider name.
	 * @return string
	 */
	public static function get_default_model( string $provider ): string {
		$defaults = array(
			'openai'    => 'gpt-5-mini',
			'anthropic' => 'claude-haiku-4-5-20251001',
			'gemini'    => 'gemini-3.1-flash-lite',
		);

		return $defaults[ $provider ] ?? 'gpt-5-mini';
	}

	/**
	 * Build merge tag instructions for AI prompts.
	 *
	 * @return string
	 */
	public static function get_merge_tags_for_prompt(): string {
		$manager = MergeTagsManager::instance();
		$groups  = $manager->get_groups();

		$allowed_groups = array( 'contact', 'general' );

		$lines = array();
		foreach ( $allowed_groups as $group_key ) {
			if ( ! isset( $groups[ $group_key ] ) ) {
				continue;
			}
			$group = $groups[ $group_key ];
			if ( ! empty( $group['is_disabled'] ) ) {
				continue;
			}
			if ( empty( $group['mergeTags'] ) ) {
				continue;
			}
			foreach ( $group['mergeTags'] as $tag ) {
				if ( ! empty( $tag['required_triggers'] ) ) {
					continue;
				}
				$lines[] = "  {$tag['value']} - {$tag['name']}";
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "\n\nAvailable merge tags for personalization (use exactly as shown):\n" . implode( "\n", $lines );
	}
}
