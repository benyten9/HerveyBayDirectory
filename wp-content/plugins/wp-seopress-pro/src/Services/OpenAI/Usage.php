<?php

namespace SEOPressPro\Services\OpenAI;

defined( 'ABSPATH' ) || exit;

class Usage {
	public const NAME_SERVICE                  = 'Usage';
	private const OPENAI_URL_USAGE             = 'https://api.openai.com/v1/usage';
	private const OPENAI_URL_MODELS            = 'https://api.openai.com/v1/models';
	private const OPENAI_URL_CHAT_COMPLETIONS  = 'https://api.openai.com/v1/chat/completions';
	private const OPENAI_URL_RESPONSES         = 'https://api.openai.com/v1/responses';
	private const DEEPSEEK_URL_BALANCE         = 'https://api.deepseek.com/user/balance';
	private const DEEPSEEK_URL_COMPLETIONS     = 'https://api.deepseek.com/beta/completions';
	private const GEMINI_URL_GENERATE_CONTENT  = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const MISTRAL_URL_CHAT_COMPLETIONS = 'https://api.mistral.ai/v1/chat/completions';
	private const CLAUDE_URL_MESSAGES          = 'https://api.anthropic.com/v1/messages';
	private const SEOPRESS_PROXY_URL          = 'https://api.seopress.org';

	private function getProviderEndpoints( $provider ) {
		$endpoints = array();

		// Sanitize provider parameter
		$provider = sanitize_text_field( strtolower( $provider ) );

		switch ( $provider ) {
			case 'openai':
				$endpoints['usage']            = self::OPENAI_URL_USAGE;
				$endpoints['models']           = self::OPENAI_URL_MODELS;
				$endpoints['chat_completions'] = self::OPENAI_URL_CHAT_COMPLETIONS;
				$endpoints['responses']        = self::OPENAI_URL_RESPONSES;
				break;
			case 'deepseek':
				$endpoints['balance']     = self::DEEPSEEK_URL_BALANCE;
				$endpoints['completions'] = self::DEEPSEEK_URL_COMPLETIONS;
				break;
			case 'gemini':
				$endpoints['generate_content'] = self::GEMINI_URL_GENERATE_CONTENT;
				break;
			case 'mistral':
				$endpoints['chat_completions'] = self::MISTRAL_URL_CHAT_COMPLETIONS;
				break;
			case 'claude':
				$endpoints['messages'] = self::CLAUDE_URL_MESSAGES;
				break;
			case 'seopress':
				$proxy_url              = defined( 'SEOPRESS_AI_PROXY_URL' ) ? SEOPRESS_AI_PROXY_URL : self::SEOPRESS_PROXY_URL;
				$endpoints['balance']   = $proxy_url . '/v1/balance';
				break;
			default:
				// Default to OpenAI for backward compatibility
				$endpoints['usage']            = self::OPENAI_URL_USAGE;
				$endpoints['chat_completions'] = self::OPENAI_URL_CHAT_COMPLETIONS;
				break;
		}

		return $endpoints;
	}

	private function getProviderName( $provider ) {
		// Sanitize provider parameter
		$provider = sanitize_text_field( strtolower( $provider ) );

		switch ( $provider ) {
			case 'openai':
				return 'OpenAI';
			case 'deepseek':
				return 'DeepSeek';
			case 'gemini':
				return 'Gemini';
			case 'mistral':
				return 'Mistral';
			case 'claude':
				return 'Claude';
			case 'seopress':
				return 'SEOPress';
			default:
				return ucfirst( $provider );
		}
	}

	/**
	 * Check if the provider uses chat completions format (OpenAI) or completions format (DeepSeek)
	 *
	 * @param string $provider The AI provider (openai, deepseek, gemini, etc.)
	 * @return bool True if using chat completions format, false if using completions format
	 */
	private function isChatCompletionsProvider( $provider ) {
		$provider = sanitize_text_field( strtolower( $provider ) );

		switch ( $provider ) {
			case 'openai':
				return true;
			case 'deepseek':
				return false;
			case 'gemini':
				return false; // Gemini uses its own generateContent format
			case 'mistral':
				return true; // Mistral uses OpenAI-compatible chat completions format
			case 'claude':
				return false; // Claude uses its own messages format
			case 'seopress':
				return true; // Stripe AI Gateway uses OpenAI-compatible format
			default:
				return true; // Default to chat completions for backward compatibility
		}
	}

	/**
	 * Check if the provider is Claude (uses Anthropic API format)
	 *
	 * @param string $provider The AI provider (openai, deepseek, claude, etc.)
	 * @return bool True if provider is Claude
	 */
	private function isClaudeProvider( $provider ) {
		return sanitize_text_field( strtolower( $provider ) ) === 'claude';
	}

	/**
	 * Check if the provider is Gemini (uses unique API format)
	 *
	 * @param string $provider The AI provider (openai, deepseek, gemini, etc.)
	 * @return bool True if provider is Gemini
	 */
	private function isGeminiProvider( $provider ) {
		return sanitize_text_field( strtolower( $provider ) ) === 'gemini';
	}

	public function getLicenseKey( $provider ) {
		$options = get_option( 'seopress_pro_option_name' );

		$api_key = '';

		// SEOPress Credits uses a dedicated constant name.
		if ( 'seopress' === strtolower( $provider ) ) {
			if ( defined( 'SEOPRESS_CREDITS_KEY' ) && ! empty( constant( 'SEOPRESS_CREDITS_KEY' ) ) && is_string( constant( 'SEOPRESS_CREDITS_KEY' ) ) ) {
				return constant( 'SEOPRESS_CREDITS_KEY' );
			}
			return isset( $options['seopress_ai_seopress_api_key'] ) ? $options['seopress_ai_seopress_api_key'] : '';
		}

		// 1. Check for provider-specific constants first.
		$constant_name = 'SEOPRESS_' . strtoupper( $provider ) . '_KEY';
		if ( defined( $constant_name ) && ! empty( constant( $constant_name ) ) && is_string( constant( $constant_name ) ) ) {
			$api_key = constant( $constant_name );
		} else {
			// 2. Check SEOPress settings.
			$api_key = isset( $options[ 'seopress_ai_' . $provider . '_api_key' ] ) ? $options[ 'seopress_ai_' . $provider . '_api_key' ] : '';
		}

		// Treat the delete marker as empty.
		if ( '__DELETE__' === $api_key ) {
			$api_key = '';
		}

		// 3. Fall back to WordPress connector key (WP 7.0+).
		if ( empty( $api_key ) ) {
			$api_key = $this->getConnectorApiKey( $provider );
		}

		return $api_key;
	}

	/**
	 * Map SEOPress provider ID to WordPress connector ID.
	 *
	 * @param string $provider The SEOPress provider ID.
	 * @return string|null The WP connector ID, or null if no connector exists.
	 */
	private function getConnectorId( $provider ) {
		$map = array(
			'openai'  => 'openai',
			'gemini'  => 'google',
			'claude'  => 'anthropic',
		);

		return isset( $map[ $provider ] ) ? $map[ $provider ] : null;
	}

	/**
	 * Get API key from WordPress connector system (WP 7.0+).
	 *
	 * @param string $provider The SEOPress provider ID.
	 * @return string The API key, or empty string if unavailable.
	 */
	private function getConnectorApiKey( $provider ) {
		$connector_id = $this->getConnectorId( $provider );

		if ( null === $connector_id || ! function_exists( 'wp_get_connector' ) ) {
			return '';
		}

		$connector = wp_get_connector( $connector_id );

		if ( null === $connector ) {
			return '';
		}

		$auth = isset( $connector['authentication'] ) ? $connector['authentication'] : array();

		if ( 'api_key' !== ( isset( $auth['method'] ) ? $auth['method'] : '' ) || empty( $auth['setting_name'] ) ) {
			return '';
		}

		return get_option( $auth['setting_name'], '' );
	}

	public function checkLicenseKeyExists( $provider ) {
		$api_key       = $this->getLicenseKey( $provider );
		$provider_name = $this->getProviderName( $provider );

		// Check for empty keys
		if ( empty( $api_key ) ) {
			$data = array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %s: provider name */
					__( 'Your %s API key has not been entered. Please enter your API key.', 'wp-seopress-pro' ),
					$provider_name
				),
			);

			return $data;
		}

		// Check for common placeholder values
		$placeholder_values = array(
			'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'xxxxxxxx',
			'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
			'sk-xxxxxxxx',
		);

		if ( in_array( $api_key, $placeholder_values ) ) {
			$data = array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %s: provider name */
					__( 'Your %1$s API key appears to be a placeholder. Please enter your actual API key from %2$s website.', 'wp-seopress-pro' ),
					$provider_name,
					$provider_name
				),
			);

			return $data;
		}

		$endpoints = $this->getProviderEndpoints( $provider );

		// SEOPress Credits: validate token against proxy balance endpoint.
		if ( 'seopress' === strtolower( $provider ) ) {
			$response = wp_remote_get(
				$endpoints['balance'],
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'code'    => 'error',
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to connect to SEOPress API: %s', 'wp-seopress-pro' ),
						$response->get_error_message()
					),
				);
			}

			$httpCode = wp_remote_retrieve_response_code( $response );
			$body     = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 === $httpCode && isset( $body['status'] ) && 'active' === $body['status'] ) {
				$credits = isset( $body['credits_remaining'] ) ? number_format_i18n( $body['credits_remaining'] ) : '?';
				return array(
					'code'    => 'success',
					'message' => sprintf(
						/* translators: %s: remaining credits */
						__( 'Your SEOPress Credits token is valid. Credits remaining: %s', 'wp-seopress-pro' ),
						'<strong>' . $credits . '</strong>'
					),
				);
			}

			return array(
				'code'    => 'error',
				'message' => __( 'Your SEOPress Credits token is invalid or your subscription is not active.', 'wp-seopress-pro' ),
			);
		}

		// Use different endpoints and auth methods for different providers
		if ( strtolower( $provider ) === 'openai' ) {
			// /v1/models is the canonical "is this key valid" probe; /v1/usage now requires an admin key and 401s every regular key.
			$response = wp_remote_get(
				$endpoints['models'],
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Referer'       => trailingslashit( home_url() ),
					),
					'timeout' => 30,
				)
			);
		} elseif ( strtolower( $provider ) === 'mistral' ) {
			// Mistral: Use a simple chat completions request to validate the key.
			$url      = $endpoints['chat_completions'];
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
						'Referer'       => trailingslashit( home_url() ),
					),
					'body'    => wp_json_encode(
						array(
							'model'      => $this->getDefaultModel( $provider ),
							'messages'   => array(
								array(
									'role'    => 'user',
									'content' => 'Hello',
								),
							),
							'max_tokens' => 10,
						)
					),
					'timeout' => 30,
				)
			);
		} elseif ( $this->isGeminiProvider( $provider ) ) {
			// Gemini: Use a simple generateContent request to validate the key,
			// probing the model the user actually selected rather than a
			// hardcoded default. Resolve it through Completions::getAIModel()
			// (same as checkLicenseKeyExpiration) so the saved selection is
			// used and any stale value falls back to a currently valid default.
			// A hardcoded probe model made the whole test fail whenever that one
			// model was unavailable to the key (e.g. gemini-2.5-flash, which
			// Google now refuses for newly created keys), even though the
			// selected model worked.
			$model               = $this->getDefaultModel( $provider );
			$completions_service = seopress_pro_get_service( 'Completions' );
			if ( null !== $completions_service ) {
				$model = $completions_service->getAIModel( $provider );
			}
			$url = $endpoints['generate_content'] . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );

			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
						'Referer'      => trailingslashit( home_url() ),
					),
					'body'    => wp_json_encode(
						array(
							'contents' => array(
								array(
									'role'  => 'user',
									'parts' => array(
										array( 'text' => 'Hello' ),
									),
								),
							),
							'generationConfig' => array(
								'maxOutputTokens' => 10,
							),
						)
					),
					'timeout' => 30,
				)
			);

			// For Gemini, provide more detailed error info.
			if ( ! is_wp_error( $response ) ) {
				$httpCode = wp_remote_retrieve_response_code( $response );
				if ( 200 !== $httpCode ) {
					$body          = wp_remote_retrieve_body( $response );
					$body_decoded  = json_decode( $body, true );
					$error_message = isset( $body_decoded['error']['message'] ) ? $body_decoded['error']['message'] : '';

					// 429 means rate limited - the key IS valid, just quota exceeded.
					if ( 429 === $httpCode ) {
						return array(
							'code'    => 'success',
							'message' => sprintf(
								/* translators: %s: provider name */
								__( 'Your %s API key is valid but you have exceeded your quota. Please check your billing settings at Google AI Studio or wait for your quota to reset.', 'wp-seopress-pro' ),
								$provider_name
							),
						);
					}

					// 404 means the probed model is not available to this key,
					// which is a model problem, not an authentication one. Point
					// at the model so the message does not wrongly blame the key.
					if ( 404 === $httpCode ) {
						return array(
							'code'    => 'error',
							'message' => sprintf(
								/* translators: %1$s: provider name, %2$s: model identifier, %3$s: error details */
								__( 'Your %1$s API key is valid, but the selected model (%2$s) is not available for it. Error 404: %3$s. Please select a different model.', 'wp-seopress-pro' ),
								$provider_name,
								esc_html( $model ),
								esc_html( $error_message )
							),
						);
					}

					return array(
						'code'    => 'error',
						'message' => sprintf(
							/* translators: %1$s: provider name, %2$s: error code, %3$s: error details */
							__( 'Your %1$s API key is invalid or has expired. Error %2$s: %3$s', 'wp-seopress-pro' ),
							$provider_name,
							esc_html( $httpCode ),
							esc_html( $error_message )
						),
					);
				}
			}
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			// Claude: Use a simple messages request to validate the key.
			$url      = $endpoints['messages'];
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array(
						'x-api-key'         => $api_key,
						'anthropic-version' => '2023-06-01',
						'Content-Type'      => 'application/json',
						'Referer'           => trailingslashit( home_url() ),
					),
					'body'    => wp_json_encode(
						array(
							'model'      => $this->getDefaultModel( $provider ),
							'max_tokens' => 10,
							'messages'   => array(
								array(
									'role'    => 'user',
									'content' => 'Hello',
								),
							),
						)
					),
					'timeout' => 30,
				)
			);
		} else {
			// For DeepSeek and other providers, use balance endpoint
			$url      = isset( $endpoints['balance'] ) ? $endpoints['balance'] : $endpoints['usage'];
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Referer'       => trailingslashit( home_url() ),
					),
					'timeout' => 30,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %1$s: provider name, %2$s: error message */
					__( 'Failed to connect to %1$s API: %2$s', 'wp-seopress-pro' ),
					$provider_name,
					$response->get_error_message()
				),
			);
		}

		$httpCode = wp_remote_retrieve_response_code( $response );

		if ( $httpCode === 200 ) {
			return array(
				'code'    => 'success',
				'message' => sprintf(
					/* translators: %s: provider name */
					__( 'Your %s API key is valid.', 'wp-seopress-pro' ),
					$provider_name
				),
			);
		} else {
			return array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %1$s: provider name, %2$s: error code */
					__( 'Your %1$s API key is invalid or has expired. Error: %2$s', 'wp-seopress-pro' ),
					$provider_name,
					esc_html( $httpCode )
				),
			);
		}
	}

	public function checkLicenseKeyExpiration( $provider ) {
		$api_key       = $this->getLicenseKey( $provider );
		$provider_name = $this->getProviderName( $provider );

		// SEOPress Credits: reuse balance check (no separate model validation needed).
		if ( 'seopress' === strtolower( $provider ) ) {
			return $this->checkLicenseKeyExists( $provider );
		}

		$options    = get_option( 'seopress_pro_option_name' );
		$model_name = isset( $options[ 'seopress_ai_' . $provider . '_model' ] ) ? $options[ 'seopress_ai_' . $provider . '_model' ] : $this->getDefaultModel( $provider );
		// Resolve the model through Completions::getAIModel() so the
		// stale-value guard kicks in here too. Without this, a customer with
		// an obsolete model in their DB (e.g. "gpt-5-chat-latest") would still
		// see the test connection fail even after the server-side guard
		// protects normal generation requests.
		$completions_service = seopress_pro_get_service( 'Completions' );
		if ( null !== $completions_service ) {
			$model_name = $completions_service->getAIModel( $provider );
		} else {
			$options    = get_option( 'seopress_pro_option_name' );
			$model_name = isset( $options[ 'seopress_ai_' . $provider . '_model' ] ) ? $options[ 'seopress_ai_' . $provider . '_model' ] : $this->getDefaultModel( $provider );
		}

		$endpoints = $this->getProviderEndpoints( $provider );

		// Handle Gemini differently - it uses query param auth and different format
		if ( $this->isGeminiProvider( $provider ) ) {
			$url  = $endpoints['generate_content'] . $model_name . ':generateContent?key=' . $api_key;
			$body = array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => 'test' ),
						),
					),
				),
				'generationConfig' => array(
					'maxOutputTokens' => 5,
				),
			);
			$args = array(
				'body'        => wp_json_encode( $body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Referer'      => trailingslashit( home_url() ),
				),
			);
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			// Claude uses x-api-key header and different format
			$url  = $endpoints['messages'];
			$body = array(
				'model'      => $model_name,
				'max_tokens' => 10,
				'messages'   => array(
					array(
						'role'    => 'user',
						'content' => 'test',
					),
				),
			);
			$args = array(
				'body'        => wp_json_encode( $body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
					'Referer'           => trailingslashit( home_url() ),
				),
			);
		} else {
			// Check if this is a GPT-5 model
			$is_gpt5_model = strpos( $model_name, 'gpt-5' ) !== false;

			// Use the appropriate endpoint based on provider and model
			if ( $is_gpt5_model && isset( $endpoints['responses'] ) ) {
				// GPT-5 uses Responses API (only for native OpenAI; gateway-based providers use chat/completions).
				$url = $endpoints['responses'];
			} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
				$url = $endpoints['chat_completions'];
			} else {
				$url = $endpoints['completions'];
			}

			// Build request body based on provider format
			if ( $is_gpt5_model ) {
				// GPT-5 uses Responses API with different parameters
				// Need more tokens as reasoning uses many tokens
				$body = array(
					'model'             => $model_name,
					'input'             => 'test',
					'max_output_tokens' => 500,
					'reasoning'         => array(
						'effort' => 'medium',
					),
				);
			} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
				// OpenAI Chat Completions format
				$body = array(
					'model'       => $model_name,
					'temperature' => 1,
					'max_tokens'  => 10,
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => 'test',
						),
					),
				);
			} else {
				// DeepSeek completions format
				$body = array(
					'model'       => $model_name,
					'temperature' => 1,
					'max_tokens'  => 10,
					'prompt'      => 'test',
				);
			}

			$args = array(
				'body'        => wp_json_encode( $body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Referer'       => trailingslashit( home_url() ),
				),
			);
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %1$s: provider name, %2$s: error message */
					__( 'Failed to connect to %1$s API: %2$s', 'wp-seopress-pro' ),
					$provider_name,
					$response->get_error_message()
				),
			);
		}

		$httpCode = wp_remote_retrieve_response_code( $response );

		// Get response body for detailed error analysis
		$response_body = wp_remote_retrieve_body( $response );
		$error_data    = json_decode( $response_body, true );

		if ( $httpCode === 200 ) {
			return array(
				'code'    => 'success',
				'message' => sprintf(
					/* translators: %1$s: provider name, %2$s: model name */
					__( 'Your %1$s API key is valid and the model %2$s is available.', 'wp-seopress-pro' ),
					$provider_name,
					'<strong>' . esc_html( $model_name ) . '</strong>'
				),
			);
		} else {
			$error_message = '';

			// Check if the error is related to model access
			if ( isset( $error_data['error']['message'] ) ) {
				$error_message = $error_data['error']['message'];
			}

			// Check for common model access issues
			if ( strpos( $error_message, 'model' ) !== false || strpos( $error_message, 'does not exist' ) !== false || $httpCode === 404 ) {
				return array(
					'code'    => 'error',
					'message' => sprintf(
						/* translators: %1$s: provider name, %2$s: model name, %3$s: error code */
						__( 'Your %1$s API key is valid, but the model %2$s is not available for your account. Error: %3$s. Please select a different model or upgrade your account to access this model.', 'wp-seopress-pro' ),
						$provider_name,
						'<strong>' . esc_html( $model_name ) . '</strong>',
						esc_html( $httpCode )
					),
				);
			}

			return array(
				'code'    => 'error',
				'message' => sprintf(
					/* translators: %1$s: provider name, %2$s: error code, %3$s: usage url, %4$s: provider name */
					__( 'Your %1$s API key is invalid or has expired. Error: %2$s. Go to your <a href="%3$s" target="_blank">%4$s Usage page</a> to check this.', 'wp-seopress-pro' ),
					$provider_name,
					esc_html( $httpCode ),
					$this->getProviderUsageUrl( $provider ),
					$provider_name
				),
			);
		}
	}

	/**
	 * Get the usage/balance URL for the provider
	 *
	 * @param string $provider The AI provider
	 * @return string The usage URL
	 */
	private function getProviderUsageUrl( $provider ) {
		$provider = sanitize_text_field( strtolower( $provider ) );

		$docs_links     = function_exists( 'seopress_get_docs_links' ) ? seopress_get_docs_links() : array();
		$provider_links = isset( $docs_links['ai']['providers'] ) ? $docs_links['ai']['providers'] : array();

		switch ( $provider ) {
			case 'openai':
			case 'deepseek':
			case 'mistral':
				return isset( $provider_links[ $provider ]['usage'] ) ? $provider_links[ $provider ]['usage'] : '#';
			case 'gemini':
				return isset( $provider_links['gemini']['api_keys'] ) ? $provider_links['gemini']['api_keys'] : '#';
			case 'claude':
				return isset( $provider_links['claude']['billing'] ) ? $provider_links['claude']['billing'] : '#';
			case 'seopress':
				return isset( $docs_links['license']['account'] ) ? $docs_links['license']['account'] : '#';
			default:
				return '#';
		}
	}

	private function getDefaultModel( $provider ) {
		$provider = sanitize_text_field( strtolower( $provider ) );

		switch ( $provider ) {
			case 'openai':
				return 'gpt-5.6-terra';
			case 'deepseek':
				return 'deepseek-v4-flash';
			case 'gemini':
				return 'gemini-3-flash-preview';
			case 'mistral':
				return 'mistral-small-latest';
			case 'claude':
				return 'claude-sonnet-4-6';
			case 'seopress':
				return 'openai/gpt-4.1';
			default:
				return 'gpt-5.6-terra';
		}
	}
}
