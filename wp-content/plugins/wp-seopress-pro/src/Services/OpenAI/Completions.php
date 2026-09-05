<?php

namespace SEOPressPro\Services\OpenAI;

defined( 'ABSPATH' ) || exit;

/**
 * Completions service.
 */
class Completions {
	public const NAME_SERVICE                   = 'Completions';

	/**
	 * Transient holding the last provider failure shown in the AI settings tab.
	 *
	 * @since 10.2.0
	 *
	 * @var string
	 */
	public const ERROR_LOG_TRANSIENT            = 'seopress_pro_ai_logs';

	private const OPENAI_URL_CHAT_COMPLETIONS   = 'https://api.openai.com/v1/chat/completions';
	private const OPENAI_URL_RESPONSES          = 'https://api.openai.com/v1/responses';
	private const DEEPSEEK_URL_CHAT_COMPLETIONS = 'https://api.deepseek.com/v1/chat/completions';
	private const GEMINI_URL_GENERATE_CONTENT   = 'https://generativelanguage.googleapis.com/v1beta/models/';
	private const MISTRAL_URL_CHAT_COMPLETIONS  = 'https://api.mistral.ai/v1/chat/completions';
	private const CLAUDE_URL_MESSAGES           = 'https://api.anthropic.com/v1/messages';
	private const SEOPRESS_PROXY_URL            = 'https://api.seopress.org';

	/**
	 * Get the provider endpoints.
	 *
	 * @param string $provider The provider name.
	 * @return array The provider endpoints.
	 */
	private function getProviderEndpoints( $provider = 'openai' ) {
		$endpoints = array();

		// Sanitize provider parameter.
		$provider = sanitize_text_field( strtolower( $provider ) );

		switch ( $provider ) {
			case 'openai':
				$endpoints['chat_completions'] = self::OPENAI_URL_CHAT_COMPLETIONS;
				$endpoints['responses']        = self::OPENAI_URL_RESPONSES;
				break;
			case 'deepseek':
				// DeepSeek V4 uses the OpenAI-compatible chat completions endpoint (required for vision/multimodal).
				$endpoints['chat_completions'] = self::DEEPSEEK_URL_CHAT_COMPLETIONS;
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
				$proxy_url                     = defined( 'SEOPRESS_AI_PROXY_URL' ) ? SEOPRESS_AI_PROXY_URL : self::SEOPRESS_PROXY_URL;
				$endpoints['chat_completions'] = $proxy_url . '/v1/chat/completions';
				break;
			default:
				// Default to OpenAI for backward compatibility.
				$endpoints['chat_completions'] = self::OPENAI_URL_CHAT_COMPLETIONS;
				break;
		}

		return $endpoints;
	}

	/**
	 * Get the provider name.
	 *
	 * @param string $provider The provider name.
	 * @return string The provider name.
	 */
	private function getProviderName( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
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
	 * Get the default model.
	 *
	 * @param string $provider The provider name.
	 * @return string The default model.
	 */
	private function getDefaultModel( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
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
				return 'openai/gpt-5.1';
			default:
				return 'gpt-5.6-terra';
		}
	}

	/**
	 * Get the list of supported models for a given provider.
	 *
	 * Used to detect stale model values saved in the options from previous
	 * SEOPress versions (e.g. "gpt-4" after the OpenAI dropdown was reduced
	 * to "gpt-5.6-sol"). This list must stay in sync with the React
	 * dropdown options in app/react/admin/settings/tabs/pro/AITab.jsx.
	 *
	 * Dropping a model from this list is what migrates the sites that still
	 * have it saved: getModel() falls back to the default as soon as the
	 * stored value is no longer listed, so a provider retiring a model does
	 * not require the user to touch their settings. Keeping a retired model
	 * here would leave every one of those installs sending requests that the
	 * API answers with a 404.
	 *
	 * @param string $provider The provider name.
	 * @return array List of supported model identifiers.
	 */
	private function getSupportedModels( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
			case 'openai':
				// Terra first: it is the default. Sol is the frontier model
				// and costs 30$/MTok of output against Terra's 12$ and Luna's
				// 1.20$, which matters on a feature whose whole point is
				// generating a title and a description for every post on the
				// site. Sol stays available for anyone who wants it.
				return array( 'gpt-5.6-terra', 'gpt-5.6-sol', 'gpt-5.6-luna' );
			case 'deepseek':
				return array( 'deepseek-v4-flash', 'deepseek-v4-pro' );
			case 'gemini':
				return array( 'gemini-3-flash-preview', 'gemini-3.1-pro-preview', 'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemini-2.5-pro' );
			case 'mistral':
				return array(
					'mistral-small-latest',
					'mistral-large-latest',
					'mistral-medium-latest',
					'magistral-small-latest',
					'magistral-medium-latest',
				);
			case 'claude':
				return array( 'claude-sonnet-4-6', 'claude-haiku-4-5' );
			default:
				return array();
		}
	}

	/**
	 * Turn a non-200 provider response into something a human can read.
	 *
	 * The body is not always the provider's JSON. When the API is down, the
	 * answer is an HTML error page from its CDN (Cloudflare 520 and friends),
	 * and dumping it raw used to fill the editor notice with a full page of
	 * markup. The full body still goes to the AI log transient for debugging;
	 * the notice only ever gets one readable sentence.
	 *
	 * @param int    $response_code HTTP response code.
	 * @param string $response_body Raw response body.
	 *
	 * @return string Short, plain-text details for the error notice.
	 */
	private function formatApiErrorDetails( $response_code, $response_body ) {
		// The provider's own error message, when the body is its JSON.
		$error_data = json_decode( (string) $response_body, true );
		if ( isset( $error_data['error']['message'] ) && is_string( $error_data['error']['message'] ) && '' !== $error_data['error']['message'] ) {
			return $error_data['error']['message'];
		}

		// An HTML body means the request never reached the API itself: CDN
		// error page, maintenance page, proxy. Its <title> is the only line
		// worth showing ("api.openai.com | 520: Web server is returning an
		// unknown error"); the rest is markup.
		if ( false !== stripos( (string) $response_body, '<html' ) ) {
			if ( preg_match( '/<title>(.*?)<\/title>/is', (string) $response_body, $m ) ) {
				$title = trim( wp_strip_all_tags( $m[1] ) );
				if ( '' !== $title ) {
					return $title;
				}
			}

			return (int) $response_code >= 500
				? __( 'The AI provider is temporarily unavailable. Please try again in a few minutes.', 'wp-seopress-pro' )
				: __( 'The AI provider returned an unexpected response.', 'wp-seopress-pro' );
		}

		// Plain-text or unknown body: keep it, but never more than one line.
		$details = trim( sanitize_text_field( (string) $response_body ) );
		if ( '' === $details ) {
			return (int) $response_code >= 500
				? __( 'The AI provider is temporarily unavailable. Please try again in a few minutes.', 'wp-seopress-pro' )
				: __( 'The AI provider returned an empty response.', 'wp-seopress-pro' );
		}

		if ( strlen( $details ) > 200 ) {
			$details = substr( $details, 0, 200 ) . '…';
		}

		return $details;
	}

	/**
	 * Check if the provider uses chat completions format (OpenAI) or completions format (DeepSeek)
	 *
	 * @param string $provider The AI provider (openai, deepseek, etc.).
	 * @return bool True if using chat completions format, false if using completions format
	 */
	private function isChatCompletionsProvider( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
			case 'openai':
				return true;
			case 'deepseek':
				return true; // DeepSeek V4 uses the OpenAI-compatible chat completions format.
			case 'gemini':
				return false; // Gemini uses its own generateContent format.
			case 'mistral':
				return true; // Mistral uses OpenAI-compatible chat completions format.
			case 'claude':
				return false; // Claude uses its own messages format.
			case 'seopress':
				return true; // Stripe AI Gateway uses OpenAI-compatible format for all providers.
			default:
				return true; // Default to chat completions for backward compatibility.
		}
	}

	/**
	 * Check if the provider is Claude (uses Anthropic API format)
	 *
	 * @param string $provider The AI provider (openai, deepseek, claude, etc.).
	 * @return bool True if provider is Claude
	 */
	private function isClaudeProvider( $provider = 'openai' ) {
		return strtolower( $provider ) === 'claude';
	}

	/**
	 * Check if the provider is Gemini (uses unique API format)
	 *
	 * @param string $provider The AI provider (openai, deepseek, gemini, etc.).
	 * @return bool True if provider is Gemini
	 */
	private function isGeminiProvider( $provider = 'openai' ) {
		return strtolower( $provider ) === 'gemini';
	}

	/**
	 * Check if the provider supports multimodal content (images)
	 *
	 * @param string $provider The AI provider (openai, deepseek, gemini, etc.).
	 * @return bool True if supports multimodal content, false otherwise
	 */
	private function supportsMultimodal( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
			case 'openai':
				return true;
			case 'deepseek':
				// DeepSeek's API rejects image_url content (400 "unknown variant image_url");
				// vision is only available in the DeepSeek chat interface, not via the API.
				return false;
			case 'gemini':
				return true; // Gemini supports multimodal content.
			case 'mistral':
				// Only multimodal models support images: Pixtral, Mistral Medium, Magistral.
				$model = $this->getAIModel( $provider );
				return strpos( $model, 'pixtral' ) === 0
					|| strpos( $model, 'mistral-medium' ) === 0
					|| strpos( $model, 'magistral' ) === 0;
			case 'claude':
				return true; // Claude supports vision/multimodal content.
			case 'seopress':
				return true; // Stripe AI Gateway supports multimodal via all credit providers.
			default:
				return true; // Default to supporting multimodal for backward compatibility.
		}
	}

	/**
	 * Check if the provider supports response_format parameter
	 *
	 * @param string $provider The AI provider (openai, deepseek, gemini, etc.).
	 * @return bool True if supports response_format, false otherwise
	 */
	private function supportsResponseFormat( $provider = 'openai' ) {
		switch ( strtolower( $provider ) ) {
			case 'openai':
				return true;
			case 'deepseek':
				return false; // DeepSeek uses prompt-based JSON instructions (json_object mode requires "json" in the prompt).
			case 'gemini':
				return false; // Gemini uses its own JSON handling via prompt instructions.
			case 'mistral':
				return true; // Mistral supports response_format for JSON mode.
			case 'claude':
				return false; // Claude uses prompt instructions for JSON, not response_format.
			case 'seopress':
				return true; // Stripe AI Gateway supports response_format via OpenAI-compatible format.
			default:
				return true; // Default to supporting response_format for backward compatibility.
		}
	}

	/**
	 * Build the request body based on the provider's API format
	 *
	 * @param array  $body The base body parameters.
	 * @param string $provider The AI provider.
	 * @return array The formatted request body
	 */
	private function buildRequestBody( $body, $provider = 'openai' ) {
		if ( $this->isChatCompletionsProvider( $provider ) ) {
			// OpenAI format - use messages array.
			return $body;
		} elseif ( $this->isGeminiProvider( $provider ) ) {
			// Gemini format - use contents array with parts.
			return $this->buildGeminiRequestBody( $body );
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			// Claude format - uses messages but with different image handling.
			return $this->buildClaudeRequestBody( $body );
		} else {
			// DeepSeek completions format - convert messages to prompt.
			$completions_body = array(
				'model'       => $body['model'],
				'temperature' => $body['temperature'],
				'max_tokens'  => $body['max_tokens'],
			);

			// Convert messages array to a single prompt string.
			$prompt                 = '';
			$has_multimodal_content = false;

			foreach ( $body['messages'] as $message ) {
				if ( 'user' === $message['role'] ) {
					// Handle different content formats.
					if ( is_string( $message['content'] ) ) {
						$prompt .= $message['content'] . "\n\n";
					} elseif ( is_array( $message['content'] ) ) {
						// Handle multimodal content (text + image).
						foreach ( $message['content'] as $content_item ) {
							if ( 'text' === $content_item['type'] ) {
								$prompt .= $content_item['text'] . "\n\n";
							} elseif ( 'image_url' === $content_item['type'] ) {
								$has_multimodal_content = true;
								// For DeepSeek completions, we can't include images directly
								// Add a note about the image in the prompt.
								$prompt .= '[Image: ' . $content_item['image_url']['url'] . "]\n\n";
							}
						}
					}
				}
			}

			$completions_body['prompt'] = trim( $prompt );

			// Remove response_format for DeepSeek completions (not supported).
			if ( isset( $body['response_format'] ) && ! $this->supportsResponseFormat( $provider ) ) {
				unset( $completions_body['response_format'] );
			}

			// Add warning about multimodal content if present.
			if ( $has_multimodal_content && ! $this->supportsMultimodal( $provider ) ) {
				$completions_body['prompt'] = "Note: This request contains image content which cannot be processed by this provider. The image URL has been included as text for reference.\n\n" . $completions_body['prompt'];
			}

			return $completions_body;
		}
	}

	/**
	 * Build the request body for Gemini API format.
	 *
	 * @param array $body The base body parameters in OpenAI format.
	 * @return array The formatted request body for Gemini
	 */
	private function buildGeminiRequestBody( $body ) {
		$parts = array();

		// Convert messages array to Gemini parts format.
		foreach ( $body['messages'] as $message ) {
			if ( 'user' === $message['role'] ) {
				// Handle different content formats.
				if ( is_string( $message['content'] ) ) {
					$parts[] = array( 'text' => $message['content'] );
				} elseif ( is_array( $message['content'] ) ) {
					// Handle multimodal content (text + image).
					foreach ( $message['content'] as $content_item ) {
						if ( 'text' === $content_item['type'] ) {
							$parts[] = array( 'text' => $content_item['text'] );
						} elseif ( 'image_url' === $content_item['type'] ) {
							// For Gemini, we need to fetch the image and convert to base64.
							$image_url  = $content_item['image_url']['url'];
							$image_data = $this->fetchImageAsBase64( $image_url );
							if ( $image_data ) {
								$parts[] = array(
									'inline_data' => array(
										'mime_type' => $image_data['mime_type'],
										'data'      => $image_data['data'],
									),
								);
							}
						}
					}
				}
			}
		}

		$max_tokens = isset( $body['max_tokens'] ) ? $body['max_tokens'] : 220;

		// Gemini 2.5 Flash is a thinking model that uses tokens for internal reasoning.
		// Ensure enough tokens for both thinking and output.
		if ( $max_tokens < 2048 ) {
			$max_tokens = 2048;
		}

		$gemini_body = array(
			'contents'         => array(
				array(
					'parts' => $parts,
				),
			),
			'generationConfig' => array(
				'temperature'      => isset( $body['temperature'] ) ? $body['temperature'] : 1,
				'maxOutputTokens'  => $max_tokens,
				'responseMimeType' => 'application/json',
			),
		);

		return $gemini_body;
	}

	/**
	 * Build the request body for Claude API format
	 *
	 * @param array $body The base body parameters in OpenAI format.
	 * @return array The formatted request body for Claude
	 */
	private function buildClaudeRequestBody( $body ) {
		$messages = array();

		// Convert messages array to Claude format.
		foreach ( $body['messages'] as $message ) {
			if ( 'user' === $message['role'] ) {
				// Handle different content formats.
				if ( is_string( $message['content'] ) ) {
					$messages[] = array(
						'role'    => 'user',
						'content' => $message['content'],
					);
				} elseif ( is_array( $message['content'] ) ) {
					// Handle multimodal content (text + image).
					$content_parts = array();
					foreach ( $message['content'] as $content_item ) {
						if ( 'text' === $content_item['type'] ) {
							$content_parts[] = array(
								'type' => 'text',
								'text' => $content_item['text'],
							);
						} elseif ( 'image_url' === $content_item['type'] ) {
							// For Claude, we need to fetch the image and convert to base64.
							$image_url  = $content_item['image_url']['url'];
							$image_data = $this->fetchImageAsBase64( $image_url );
							if ( $image_data ) {
								$content_parts[] = array(
									'type'   => 'image',
									'source' => array(
										'type'       => 'base64',
										'media_type' => $image_data['mime_type'],
										'data'       => $image_data['data'],
									),
								);
							}
						}
					}
					$messages[] = array(
						'role'    => 'user',
						'content' => $content_parts,
					);
				}
			}
		}

		$claude_body = array(
			'model'      => $body['model'],
			'max_tokens' => isset( $body['max_tokens'] ) ? $body['max_tokens'] : 220,
			'messages'   => $messages,
		);

		// Add temperature if set (Claude supports 0-1 range).
		if ( isset( $body['temperature'] ) ) {
			$claude_body['temperature'] = min( 1, max( 0, $body['temperature'] ) );
		}

		return $claude_body;
	}

	/**
	 * Build the request body for GPT-5 Responses API format.
	 *
	 * GPT-5 models use the Responses API with different parameters:
	 * - 'input' instead of 'messages'
	 * - 'max_output_tokens' instead of 'max_tokens'
	 * - 'reasoning.effort' for controlling reasoning depth
	 * - 'text.format' for JSON output
	 *
	 * @param array  $body The base body parameters in Chat Completions format.
	 * @param string $json_schema Optional JSON schema for structured output.
	 * @return array The formatted request body for GPT-5 Responses API.
	 */
	private function buildGpt5RequestBody( $body, $json_schema = null ) {
		// Convert messages array to input string.
		$input = '';
		foreach ( $body['messages'] as $message ) {
			if ( 'user' === $message['role'] ) {
				if ( is_string( $message['content'] ) ) {
					$input .= $message['content'] . "\n\n";
				} elseif ( is_array( $message['content'] ) ) {
					foreach ( $message['content'] as $content_item ) {
						if ( 'text' === $content_item['type'] ) {
							$input .= $content_item['text'] . "\n\n";
						}
					}
				}
			}
		}

		$gpt5_body = array(
			'model' => $body['model'],
			'input' => trim( $input ),
		);

		// GPT-5 uses max_output_tokens instead of max_tokens/max_completion_tokens
		// GPT-5 with reasoning uses many tokens for internal reasoning, so we need more tokens
		// Reasoning tokens + actual output tokens both count against max_output_tokens.
		$gpt5_body['max_output_tokens'] = 2000;

		// Reasoning effort kept at 'medium', the setting the metabox prompts
		// were tuned against; 'high' spends most of max_output_tokens on
		// internal reasoning and leaves the answer truncated.
		$gpt5_body['reasoning'] = array(
			'effort' => 'medium',
		);

		// Carry the JSON constraint the caller asked for. The Responses API
		// spells it `text.format` where chat completions use `response_format`,
		// and rebuilding the body from scratch used to drop it silently: the
		// prompt was then written in its lighter form, on the assumption the
		// parameter would do the work, and nothing enforced anything.
		if ( isset( $body['response_format']['type'] ) ) {
			$gpt5_body['text'] = array(
				'format' => array( 'type' => $body['response_format']['type'] ),
			);
		}

		return $gpt5_body;
	}

	/**
	 * Parse the response from GPT-5 Responses API.
	 *
	 * Converts GPT-5 Responses API format to the standard OpenAI Chat Completions format
	 * for consistent handling across the codebase.
	 *
	 * @param object $response_data The raw response data from GPT-5 Responses API.
	 * @return object The response converted to Chat Completions format.
	 */
	private function parseGpt5Response( $response_data ) {
		$converted_response                      = new \stdClass();
		$converted_response->choices             = array();
		$converted_response->choices[0]          = new \stdClass();
		$converted_response->choices[0]->message = new \stdClass();

		// GPT-5 Responses API returns output array with different item types
		// Structure: output[] -> items with type "reasoning" or "message"
		// Message items have: content[] -> items with type "output_text" and "text" property.
		$raw_content = '';
		if ( isset( $response_data->output ) && is_array( $response_data->output ) ) {
			foreach ( $response_data->output as $output_item ) {
				// Look for message type items (skip reasoning items).
				if ( isset( $output_item->type ) && 'message' === $output_item->type ) {
					if ( isset( $output_item->content ) && is_array( $output_item->content ) ) {
						foreach ( $output_item->content as $content_item ) {
							if ( isset( $content_item->type ) && 'output_text' === $content_item->type && isset( $content_item->text ) ) {
								$raw_content .= $content_item->text;
							}
						}
					}
				}
			}
		}

		// Clean the content - remove markdown code blocks if present.
		$cleaned_content = $raw_content;

		// Remove ```json ... ``` or ``` ... ``` markdown wrappers.
		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)\s*```/', $cleaned_content, $matches ) ) {
			$cleaned_content = trim( $matches[1] );
		}

		// Also try to extract JSON object if present.
		if ( preg_match( '/\{[\s\S]*\}/', $cleaned_content, $matches ) ) {
			$cleaned_content = $matches[0];
		}

		$converted_response->choices[0]->message->content = $cleaned_content;
		$converted_response->choices[0]->finish_reason    = isset( $response_data->status ) ? $response_data->status : 'completed';
		$converted_response->choices[0]->index            = 0;

		// Copy other response properties.
		$converted_response->id      = isset( $response_data->id ) ? $response_data->id : 'gpt5';
		$converted_response->created = time();
		$converted_response->model   = isset( $response_data->model ) ? $response_data->model : 'gpt-5';
		$converted_response->object  = 'chat.completion';

		if ( isset( $response_data->usage ) ) {
			$converted_response->usage                    = new \stdClass();
			$converted_response->usage->prompt_tokens     = isset( $response_data->usage->input_tokens ) ? $response_data->usage->input_tokens : 0;
			$converted_response->usage->completion_tokens = isset( $response_data->usage->output_tokens ) ? $response_data->usage->output_tokens : 0;
			$converted_response->usage->total_tokens      = $converted_response->usage->prompt_tokens + $converted_response->usage->completion_tokens;
		}

		return $converted_response;
	}

	/**
	 * Fetch an image from URL and convert to base64 for the multimodal APIs.
	 *
	 * Public because it is part of this service's surface: the AI Assistant
	 * builds its Claude and Gemini payloads in
	 * `ChatCompletions::prepare_multimodal_messages()` and calls this from
	 * there. That is a different class with no inheritance link, so declaring
	 * this private made every image-carrying chat request raise a PHP `Error`
	 * rather than send anything.
	 *
	 * @param string $url           The image URL.
	 * @param int    $attachment_id Attachment ID when known, so the file can be
	 *                              read from disk instead of over HTTP.
	 * @return array|false Array with 'mime_type' and 'data' keys, or false on failure
	 */
	public function fetchImageAsBase64( $url, $attachment_id = 0 ) {
		$url = is_string( $url ) ? $url : '';

		/*
		 * Read the file from disk when we know which attachment this is.
		 *
		 * The previous behaviour was to ask the site for its own image over
		 * HTTP, which is a loopback request and fails for reasons that have
		 * nothing to do with the image: a host that blocks loopback, a WAF or
		 * CDN that challenges a request with no browser User-Agent, or a URL
		 * that is not absolute in the first place.
		 *
		 * That last one is what a customer hit: a site where
		 * wp_get_attachment_image_src() returns a root-relative path, so
		 * wp_remote_get() answered "A valid URL was not provided." and the only
		 * thing the user saw was "Could not fetch the image".
		 *
		 * The file is on the same server in every one of those cases, so
		 * reading it directly removes the whole class of failure. Offloaded
		 * media (S3 and friends) has no local file, and falls through to the
		 * HTTP path below.
		 */
		if ( $attachment_id ) {
			$path = get_attached_file( $attachment_id );

			if ( $path && is_readable( $path ) ) {
				$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.

				if ( false !== $contents && '' !== $contents ) {
					$mime_type = get_post_mime_type( $attachment_id );

					return array(
						'mime_type' => $mime_type ? $mime_type : 'image/jpeg',
						'data'      => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API expects base64.
					);
				}
			}
		}

		// Handle data URIs directly (already base64-encoded).
		if ( strpos( $url, 'data:' ) === 0 ) {
			if ( preg_match( '/^data:(image\/[^;]+);base64,(.+)$/', $url, $matches ) ) {
				return array(
					'mime_type' => $matches[1],
					'data'      => $matches[2],
				);
			}
			return false;
		}

		// A root-relative or protocol-relative URL is never a valid argument to
		// wp_remote_get(); it answers "A valid URL was not provided." Sites do
		// produce them, through a CDN plugin or an upload_url_path that was set
		// relative, so resolve rather than fail.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		if ( '' === $url ) {
			return false;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$image_body   = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Determine mime type.
		$mime_type = 'image/jpeg'; // Default.
		if ( strpos( $content_type, 'image/png' ) !== false ) {
			$mime_type = 'image/png';
		} elseif ( strpos( $content_type, 'image/gif' ) !== false ) {
			$mime_type = 'image/gif';
		} elseif ( strpos( $content_type, 'image/webp' ) !== false ) {
			$mime_type = 'image/webp';
		}

		return array(
			'mime_type' => $mime_type,
			'data'      => base64_encode( $image_body ),
		);
	}

	/**
	 * Clean response content by removing markdown code blocks and extracting JSON
	 *
	 * @param string $content The raw content from the AI response.
	 * @return string The cleaned content
	 */
	private function cleanResponseContent( $content ) {
		// Strip markdown code fences (e.g., ```json ... ```).
		$content = preg_replace( '/^```(?:json)?\s*/i', '', trim( $content ) );
		$content = preg_replace( '/\s*```\s*$/', '', $content );

		// Extract content between curly braces { and }.
		if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			$content = $matches[0];
		}

		// Remove any leading/trailing whitespace.
		$content = trim( $content );

		return $content;
	}

	/**
	 * Parse the response based on the provider's API format.
	 *
	 * @param object $response_data The raw response data.
	 * @param string $provider The AI provider.
	 * @return object The parsed response
	 */
	private function parseResponse( $response_data, $provider = 'openai' ) {
		if ( $this->isChatCompletionsProvider( $provider ) ) {
			// OpenAI format - response has choices[0].message.content.
			return $response_data;
		} elseif ( $this->isGeminiProvider( $provider ) ) {
			// Gemini format - response has candidates[0].content.parts[0].text
			// Convert to OpenAI format for consistency.
			$converted_response                      = new \stdClass();
			$converted_response->choices             = array();
			$converted_response->choices[0]          = new \stdClass();
			$converted_response->choices[0]->message = new \stdClass();

			// Extract content from Gemini's response structure.
			$raw_content = '';
			if ( isset( $response_data->candidates[0]->content->parts[0]->text ) ) {
				$raw_content = $response_data->candidates[0]->content->parts[0]->text;
			}
			$converted_response->choices[0]->message->content = $this->cleanResponseContent( $raw_content );

			// Map Gemini finish reason to OpenAI format.
			$converted_response->choices[0]->finish_reason = isset( $response_data->candidates[0]->finishReason ) ? strtolower( $response_data->candidates[0]->finishReason ) : 'stop';
			$converted_response->choices[0]->index         = 0;

			// Copy other response properties (Gemini uses different property names).
			$converted_response->id      = isset( $response_data->modelVersion ) ? $response_data->modelVersion : 'gemini';
			$converted_response->created = time();
			$converted_response->model   = isset( $response_data->modelVersion ) ? $response_data->modelVersion : 'gemini';
			$converted_response->object  = 'chat.completion';

			if ( isset( $response_data->usageMetadata ) ) {
				$converted_response->usage                       = new \stdClass();
				$converted_response->usage->prompt_tokens        = isset( $response_data->usageMetadata->promptTokenCount ) ? $response_data->usageMetadata->promptTokenCount : 0;
				$converted_response->usage->completion_tokens    = isset( $response_data->usageMetadata->candidatesTokenCount ) ? $response_data->usageMetadata->candidatesTokenCount : 0;
				$converted_response->usage->total_tokens         = isset( $response_data->usageMetadata->totalTokenCount ) ? $response_data->usageMetadata->totalTokenCount : 0;
			}

			return $converted_response;
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			// Claude format - response has content[0].text
			// Convert to OpenAI format for consistency.
			$converted_response                      = new \stdClass();
			$converted_response->choices             = array();
			$converted_response->choices[0]          = new \stdClass();
			$converted_response->choices[0]->message = new \stdClass();

			// Extract content from Claude's response structure.
			$raw_content = '';
			if ( isset( $response_data->content[0]->text ) ) {
				$raw_content = $response_data->content[0]->text;
			}
			$converted_response->choices[0]->message->content = $this->cleanResponseContent( $raw_content );

			// Map Claude stop_reason to OpenAI finish_reason format.
			$converted_response->choices[0]->finish_reason = isset( $response_data->stop_reason ) ? $response_data->stop_reason : 'end_turn';
			$converted_response->choices[0]->index         = 0;

			// Copy other response properties.
			$converted_response->id      = isset( $response_data->id ) ? $response_data->id : 'claude';
			$converted_response->created = time();
			$converted_response->model   = isset( $response_data->model ) ? $response_data->model : 'claude';
			$converted_response->object  = 'chat.completion';

			if ( isset( $response_data->usage ) ) {
				$converted_response->usage                    = new \stdClass();
				$converted_response->usage->prompt_tokens     = isset( $response_data->usage->input_tokens ) ? $response_data->usage->input_tokens : 0;
				$converted_response->usage->completion_tokens = isset( $response_data->usage->output_tokens ) ? $response_data->usage->output_tokens : 0;
				$converted_response->usage->total_tokens      = $converted_response->usage->prompt_tokens + $converted_response->usage->completion_tokens;
			}

			return $converted_response;
		} else {
			// DeepSeek completions format - response has choices[0].text
			// Convert to OpenAI format for consistency.
			$converted_response                      = new \stdClass();
			$converted_response->choices             = array();
			$converted_response->choices[0]          = new \stdClass();
			$converted_response->choices[0]->message = new \stdClass();

			// Clean the content to remove markdown code blocks.
			$raw_content                                      = $response_data->choices[0]->text;
			$converted_response->choices[0]->message->content = $this->cleanResponseContent( $raw_content );

			$converted_response->choices[0]->finish_reason = $response_data->choices[0]->finish_reason;
			$converted_response->choices[0]->index         = $response_data->choices[0]->index;

			// Copy other response properties.
			$converted_response->id      = $response_data->id;
			$converted_response->created = $response_data->created;
			$converted_response->model   = $response_data->model;
			$converted_response->object  = $response_data->object;

			if ( isset( $response_data->usage ) ) {
				$converted_response->usage = $response_data->usage;
			}

			return $converted_response;
		}
	}

	/**
	 * Get AI model from the SEOPress options based on provider.
	 *
	 * @param string $provider The AI provider (openai, deepseek, etc.).
	 * @return string $model the AI model name
	 */
	public function getAIModel( $provider = null ) {
		// If no provider specified, get from user settings.
		if ( null === $provider ) {
			$option_service = seopress_pro_get_service( 'OptionPro' );
			$provider       = $option_service->getAIProvider();
			// Fallback to openai if no provider is set.
			if ( empty( $provider ) ) {
				$provider = 'openai';
			}
		}

		// SEOPress Credits always uses the default model (no user selection).
		// Developers can override via the `seopress_ai_credits_model` filter.
		if ( 'seopress' === strtolower( $provider ) ) {
			return apply_filters( 'seopress_ai_credits_model', $this->getDefaultModel( $provider ) );
		}

		$options = get_option( 'seopress_pro_option_name' );
		$model   = isset( $options[ 'seopress_ai_' . $provider . '_model' ] ) ? $options[ 'seopress_ai_' . $provider . '_model' ] : $this->getDefaultModel( $provider );

		// Guard against stale values saved by previous SEOPress versions
		// (e.g. "gpt-4" after the OpenAI dropdown was reduced to "gpt-5.6-sol").
		// Without this, requests would still be sent with the obsolete model and fail.
		$supported = $this->getSupportedModels( $provider );
		if ( ! empty( $supported ) && ! in_array( $model, $supported, true ) ) {
			$model = $this->getDefaultModel( $provider );
		}

		return $model;
	}

	/**
	 * Get the appropriate API key for the provider.
	 *
	 * @param string $provider The AI provider (openai, deepseek, etc.).
	 * @return string $api_key The API key for the provider
	 */
	private function getProviderApiKey( $provider = null ) {
		// If no provider specified, get from user settings.
		if ( null === $provider ) {
			$option_service = seopress_pro_get_service( 'OptionPro' );
			$provider       = $option_service->getAIProvider();
			// Fallback to openai if no provider is set.
			if ( empty( $provider ) ) {
				$provider = 'openai';
			}
		}

		$usage_service = seopress_pro_get_service( 'Usage' );
		return $usage_service->getLicenseKey( $provider );
	}

	/**
	 * Get the current AI provider from user settings.
	 *
	 * @return string The AI provider (openai, deepseek, etc.)
	 */
	private function getCurrentProvider() {
		$option_service = seopress_pro_get_service( 'OptionPro' );
		$provider       = $option_service->getAIProvider();
		// Fallback to openai if no provider is set.
		return ! empty( $provider ) ? $provider : 'openai';
	}

	/**
	 * Record the last provider failure for the AI settings panel.
	 *
	 * @since 10.2.0
	 *
	 * @param array $error_log Provider, response code, bodies and timestamp.
	 *
	 * @return void
	 */
	public function logApiError( $error_log ) {
		set_transient( self::ERROR_LOG_TRANSIENT, wp_json_encode( $error_log ), 30 * DAY_IN_SECONDS );
	}

	/**
	 * Drop the recorded failure once a call has succeeded.
	 *
	 * The entry was written with a thirty day lifetime and nothing ever
	 * invalidated it, so the panel kept reporting a failure that had been fixed
	 * days or weeks earlier, naming a provider the site had since stopped
	 * using. Users read it as live and concluded the plugin was calling the
	 * wrong provider; one reported an entry still on screen twenty-three days
	 * later while every call in between had gone elsewhere.
	 *
	 * The retention is a sensible upper bound for a genuinely persistent
	 * failure. What was missing is anything that ends it.
	 *
	 * @since 10.2.0
	 *
	 * @return void
	 */
	public function clearApiErrorLog() {
		delete_transient( self::ERROR_LOG_TRANSIENT );
	}

	/**
	 * Record a request that never reached the provider.
	 *
	 * The panel only ever recorded errors the provider answered with. When the
	 * request never completes at all, the message was kept for the screen and
	 * nothing was written, so the log stayed empty while generation was
	 * actively failing.
	 *
	 * That is the worst case to hide: a timeout, a DNS failure, a TLS error, an
	 * outbound connection blocked by the host, or WP_HTTP_BLOCK_EXTERNAL set on
	 * the install. Those are exactly the situations where the user has no other
	 * clue, and where the message names the cause outright.
	 *
	 * `response_code` is 0, which reads as "the request never got an answer"
	 * and keeps the existing panel layout working unchanged.
	 *
	 * @since 10.2.0
	 *
	 * @param \WP_Error $error    The transport failure.
	 * @param string    $provider Provider the request was going to.
	 *
	 * @return void
	 */
	public function logTransportError( $error, $provider ) {
		$this->logApiError(
			array(
				'provider'      => $provider,
				'response_code' => 0,
				'error_code'    => $error->get_error_code(),
				'response_body' => $error->get_error_message(),
				'request_body'  => '',
				'timestamp'     => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Turn a locale into a readable language name for the prompt.
	 *
	 * `locale_get_display_name()` comes from ext-intl, which nothing here requires:
	 * not composer.json, not the readme, not WordPress core. It is common but far
	 * from universal on shared hosting, and calling it unguarded killed every
	 * generation with a fatal before any request went out.
	 *
	 * The raw locale is a perfectly usable fallback, `fr_FR` reads as well as
	 * `French (France)` in a prompt, so a missing extension degrades here rather
	 * than becoming an install requirement.
	 *
	 * @since 10.2.0
	 *
	 * @param string $language The locale, e.g. `fr_FR`.
	 *
	 * @return string The display name when intl can resolve it, the locale otherwise.
	 */
	private function getLanguageDisplayName( $language ) {
		if ( ! function_exists( 'locale_get_display_name' ) ) {
			return $language;
		}

		$display_name = locale_get_display_name( $language, 'en' );

		return $display_name ? esc_html( $display_name ) : $language;
	}

	/**
	 * Get OpenAI model from the SEOPress options (backward compatibility).
	 *
	 * @return string $model the OpenAI model name.
	 */
	public function getOpenAIModel() {
		return $this->getAIModel( 'openai' );
	}

	/**
	 * Whether a generation the API answered with a 200 actually produced something.
	 *
	 * A provider can answer 200 with nothing usable in it: no JSON at all, or JSON
	 * without the keys the prompt asked for. Every requested value then comes back
	 * empty while the transport reports a success, and the caller has nothing to
	 * save and nothing to tell the user.
	 *
	 * @since 10.2.0
	 *
	 * @param array $values The generated values for the requested fields.
	 *
	 * @return bool
	 */
	private function hasGeneratedContent( $values ) {
		foreach ( $values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The output token budget for a generation request.
	 *
	 * Reasoning models bill their chain-of-thought from the same budget as the
	 * answer. DeepSeek V4 spends the whole historical 220 on reasoning and gets
	 * cut off (`finish_reason: length`) before writing a single output token,
	 * so every generation comes back empty. A captured response showed
	 * `completion_tokens: 220` with `reasoning_tokens: 220`. DeepSeek documents
	 * 4K as the recommended response budget for its reasoning models, on top of
	 * the chain-of-thought.
	 *
	 * The Gemini body builder has raised its own floor to 2048 for the same
	 * reason since 10.1; this puts the decision in one place instead of a new
	 * patch per call site each time a provider starts reasoning. Matched on the
	 * model name as well as the provider so a gateway routing a DeepSeek model
	 * gets the same budget.
	 *
	 * @since 10.2.0
	 *
	 * @param string $provider   The AI provider (openai, deepseek, gemini...).
	 * @param string $model_name The resolved model name.
	 * @param int    $floor      The budget the call site would use on its own.
	 *
	 * @return int
	 */
	private function getMaxOutputTokens( $provider, $model_name, $floor = 220 ) {
		$max_tokens = (int) $floor;

		if ( 'deepseek' === strtolower( (string) $provider ) || 0 === strpos( (string) $model_name, 'deepseek' ) ) {
			$max_tokens = max( $max_tokens, 4096 );
		}

		return (int) apply_filters( 'seopress_ai_max_output_tokens', $max_tokens, $provider, $model_name, (int) $floor );
	}

	/**
	 * The message to report when a 200 response carried none of the requested values.
	 *
	 * A truncated generation is the one case where "try again" is wrong advice:
	 * the model spent its whole token budget before writing the answer, so the
	 * same request fails the same way every time. Name the cause and log it,
	 * so the settings panel shows what actually happened instead of a success.
	 *
	 * @since 10.2.0
	 *
	 * @param object $data         The parsed response, in OpenAI shape.
	 * @param string $provider     The AI provider.
	 * @param array  $request_body The request body, for the error log.
	 *
	 * @return string
	 */
	private function describeEmptyResponse( $data, $provider, $request_body ) {
		$finish_reason = isset( $data->choices[0]->finish_reason ) ? (string) $data->choices[0]->finish_reason : '';

		// OpenAI, DeepSeek and Mistral report a truncation as `length`;
		// parseResponse() passes Claude's `max_tokens` and Gemini's
		// `MAX_TOKENS` through as `max_tokens`.
		if ( in_array( $finish_reason, array( 'length', 'max_tokens' ), true ) ) {
			$this->logApiError(
				array(
					'provider'      => $provider,
					'response_code' => 200,
					'response_body' => wp_json_encode( $data ),
					'request_body'  => $request_body,
					'timestamp'     => current_time( 'mysql' ),
				)
			);

			return sprintf(
				/* translators: %s: provider name */
				__( 'The %s model spent its whole token budget before writing an answer (finish_reason: length). Retrying will not help; raise the budget with the seopress_ai_max_output_tokens filter or pick a model that reasons less.', 'wp-seopress-pro' ),
				$this->getProviderName( $provider )
			);
		}

		return __( 'The AI returned an empty response. Please try again.', 'wp-seopress-pro' );
	}

	/**
	 * Decode a JSON answer a model wrapped in something else.
	 *
	 * We ask for a JSON object and then read it with a strict json_decode(),
	 * which fails on anything around it. Models routinely fence their answer in
	 * a ```json block, or introduce it with a sentence, and the longer the
	 * answer the likelier that becomes: asking for three fields fails where
	 * asking for one succeeds, on the same model and the same image.
	 *
	 * Only OpenAI and Mistral can be told to answer in JSON at the API level.
	 * Claude, Gemini and DeepSeek are asked in the prompt and nothing enforces
	 * it, so this is the only thing standing between them and a silent failure.
	 *
	 * @since 10.2.0
	 *
	 * @param mixed $raw The model answer.
	 *
	 * @return array|null Decoded object, or null when there is no JSON in it.
	 */
	private function decodeJsonPayload( $raw ) {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		$candidate = trim( $raw );

		// ```json { … } ``` or a bare ``` fence.
		if ( 0 === strpos( $candidate, '```' ) ) {
			$candidate = preg_replace( '/^```[a-zA-Z]*\s*/', '', $candidate );
			$candidate = preg_replace( '/\s*```$/', '', (string) $candidate );

			$decoded = json_decode( (string) $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// A sentence before or after the object: keep the outermost braces.
		$start = strpos( $candidate, '{' );
		$end   = strrpos( $candidate, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}

		$decoded = json_decode( substr( $candidate, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generate titles and descriptions for a post.
	 *
	 * This function generates titles and descriptions based on the provided parameters.
	 *
	 * @param int    $post_id   The ID of the post for which to generate titles and descriptions.
	 * @param string $meta      title|desc (optional).
	 * @param string $language  The language for generating titles and descriptions (default is 'en_US').
	 * @param bool   $autosave  Whether this is an autosave operation, useful for bulk actions (default is false).
	 * @param string $provider  The AI provider to use (default is null, uses user's saved preference).
	 * @param string $nonce     Security nonce for admin requests (optional).
	 *
	 * @return array $data The answers from AI with success/title/desc
	 */
	public function generateTitlesDesc(
		$post_id,
		$meta = '',
		$language = 'en_US',
		$autosave = false,
		$provider = null,
		$nonce = null
	) {
		// Validate post_id.
		$post_id = absint( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid post ID provided.', 'wp-seopress-pro' ),
				'title'   => '',
				'desc'    => '',
			);
		}

		// Verify nonce if provided (for admin requests).
		if ( null !== $nonce && ! wp_verify_nonce( $nonce, 'seopress_ai_generate_' . $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Security check failed.', 'wp-seopress-pro' ),
				'title'   => '',
				'desc'    => '',
			);
		}

		// If no provider specified, get from user settings.
		if ( null === $provider ) {
			$provider = $this->getCurrentProvider();
		}

		// Init.
		$title       = '';
		$description = '';
		$message     = '';
		if ( empty( $language ) ) {
			$language = get_locale();
		}

		$content = get_post_field( 'post_content', $post_id );
		$content = esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $content ) ) ) ) );

		// Compatibility with current page and theme builders.
		$theme = wp_get_theme();

		// Divi.
		if ( 'Divi' == $theme->template || 'Divi' == $theme->parent_theme ) {
			$regex   = '/\[(\[?)(et_pb_[^\s\]]+)(?:(\s)[^\]]+)?\]?(?:(.+?)\[\/\2\])?|\[\/(et_pb_[^\s\]]+)?\]/';
			$content = preg_replace( $regex, '', $content );
		}

		// Bricks compatibility.
		if ( defined( 'BRICKS_DB_EDITOR_MODE' ) && ( 'bricks' == $theme->template || 'Bricks' == $theme->parent_theme ) ) {
			$page_sections = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
			$editor_mode   = get_post_meta( $post_id, BRICKS_DB_EDITOR_MODE, true );

			if ( is_array( $page_sections ) && 'WordPress' !== $editor_mode ) {
				$content = \Bricks\Frontend::render_data( $page_sections );
			}
		}

		// Limit post content sent to 500 words (higher value will return a 400 error).
		$content = wp_trim_words( $content, 500 );

		// If no post_content use the permalink.
		if ( empty( $content ) ) {
			$content = get_permalink( $post_id );
		}

		$model_name = $this->getAIModel( $provider );
		$body       = array(
			'model'       => $model_name,
			'temperature' => 1,
		);

		// GPT-5 models use max_completion_tokens instead of max_tokens.
		$is_gpt5_model = strpos( $model_name, 'gpt-5' ) !== false;
		$max_tokens    = $this->getMaxOutputTokens( $provider, $model_name );
		if ( $is_gpt5_model ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens'] = $max_tokens;
		}

		// Add response_format only if supported by the provider.
		if ( $this->supportsResponseFormat( $provider ) ) {
			$body['response_format'] = array(
				'type' => 'json_object',
			);
		}

		$body['messages'] = array();

		// Per-post language override. The React metabox derives `$language`
		// from the editor / site locale and passes it in regardless of which
		// translation we are editing, so on multilingual sites we must look
		// up the locale of this specific post. Apply additively: only
		// override `$language` when WPML or Polylang actually resolves a
		// per-post locale, so the caller's value (and sites without a
		// translation plugin) keep working as before. Runs for every entry
		// point, not just bulk actions, so the metabox UI gets the same
		// language resolution as a bulk job.
		if ( $post_id ) {
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$wpml_details = apply_filters( 'wpml_post_language_details', null, $post_id );
				if ( ! empty( $wpml_details['locale'] ) ) {
					$language = $wpml_details['locale'];
				}
			}

			if ( function_exists( 'pll_get_post_language' ) ) {
				$pll_locale = pll_get_post_language( $post_id, 'locale' );
				if ( ! empty( $pll_locale ) ) {
					$language = $pll_locale;
				}
			}
		}

		// Convert language code to readable name.
		$language = $this->getLanguageDisplayName( $language );

		// Get target keywords.
		$target_keywords = ! empty( get_post_meta( $post_id, '_seopress_analysis_target_kw', true ) ) ? get_post_meta( $post_id, '_seopress_analysis_target_kw', true ) : null;

		// Prompt for meta title.
		$prompt_title = sprintf(
			/* translators: 1: language, 2: target keywords, 3: content */
			__( 'Generate, in this language %1$s, an engaging SEO title metadata in one sentence of sixty characters maximum, with at least one of these keywords in the prompt response: "%2$s", based on this content: %3$s.', 'wp-seopress-pro' ),
			esc_attr( $language ),
			esc_html( $target_keywords ),
			esc_html( $content )
		);

		$msg = apply_filters( 'seopress_ai_' . $provider . '_meta_title', $prompt_title, $post_id );

		if ( empty( $meta ) || 'title' === $meta ) {
			$body['messages'][] = array(
				'role'    => 'user',
				'content' => $msg,
			);
		}

		// Prompt for meta description.
		$prompt_desc = sprintf(
			/* translators: 1: language, 2: target keywords, 3: content */
			__( 'Generate, in this language %1$s, an engaging SEO meta description in less than 160 characters, with at least one of these keywords in the prompt response: "%2$s", based on this content: %3$s.', 'wp-seopress-pro' ),
			esc_attr( $language ),
			esc_html( $target_keywords ),
			esc_html( $content )
		);

		$msg = apply_filters( 'seopress_ai_' . $provider . '_meta_desc', $prompt_desc, $post_id );

		if ( empty( $meta ) || 'desc' === $meta ) {
			$body['messages'][] = array(
				'role'    => 'user',
				'content' => $msg,
			);
		}

		// Site-wide custom instructions (voice / tone). Added as an extra user
		// message so it influences both the title and the description prompts,
		// for every entry point (metabox, bulk actions, WP-CLI, auto-generate
		// on publish). Kept before the JSON formatting instruction so the
		// output contract stays the last thing the model reads.
		$custom_instructions = seopress_pro_get_service( 'OptionPro' )->getAICustomInstructions();
		$custom_instructions = apply_filters( 'seopress_ai_' . $provider . '_custom_instructions', $custom_instructions, $post_id );
		if ( ! empty( $custom_instructions ) ) {
			$body['messages'][] = array(
				'role'    => 'user',
				'content' => wp_strip_all_tags( $custom_instructions ),
			);
		}

		// For providers that don't support response_format, we need to be more explicit about JSON formatting.
		$json_instruction = 'Provide the answer as a JSON object with "title" as first key and "desc" for second key for parsing in this language ' . $language . '. You must respect the grammar and typing of the language.';

		if ( ! $this->supportsResponseFormat( $provider ) ) {
			$json_instruction = 'You must respond with ONLY a valid JSON object. The JSON must have exactly two keys: "title" (for the meta title) and "desc" (for the meta description). Use this language: ' . $language . '. Format: {"title": "your title here", "desc": "your description here"}';
		}

		$body['messages'][] = array(
			'role'    => 'user',
			'content' => $json_instruction,
		);

		// Build the request body based on provider format
		// GPT-5 models use the Responses API with different parameters.
		// Gateway providers (seopress) use chat completions format for all models.
		if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
			$request_body = $this->buildGpt5RequestBody( $body );
		} else {
			$request_body = $this->buildRequestBody( $body, $provider );
		}

		// Build request args - different providers use different auth methods.
		// Referer mirrors the site URL so Google Cloud "Websites" key restrictions match.
		if ( $this->isGeminiProvider( $provider ) ) {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
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
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'x-api-key'         => $this->getProviderApiKey( $provider ),
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
					'Referer'           => trailingslashit( home_url() ),
				),
			);
		} else {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->getProviderApiKey( $provider ),
					'Content-Type'  => 'application/json',
					'Referer'       => trailingslashit( home_url() ),
				),
			);
		}

		$args = apply_filters( 'seopress_ai_' . $provider . '_request_args', $args );

		// Build URL based on provider.
		$endpoints = $this->getProviderEndpoints( $provider );
		if ( $this->isGeminiProvider( $provider ) ) {
			// Gemini URL: base + model + :generateContent?key=API_KEY.
			$model = $this->getAIModel( $provider );
			$url   = $endpoints['generate_content'] . $model . ':generateContent?key=' . $this->getProviderApiKey( $provider );
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			$url = $endpoints['messages'];
		} elseif ( $is_gpt5_model && isset( $endpoints['responses'] ) ) {
			// GPT-5 uses Responses API (only for native OpenAI; gateway-based providers use chat/completions).
			$url = $endpoints['responses'];
		} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
			$url = $endpoints['chat_completions'];
		} else {
			$url = $endpoints['completions'];
		}

		$response = wp_remote_post( $url, $args );

		// Make sure the response came back okay.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				$this->logTransportError( $response, $provider );
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );

				$message = sprintf(
					/* translators: 1: provider name, 2: response code, 3: error details */
					__( 'An error occurred with %1$s API. Response code: %2$s. Details: %3$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code,
					$this->formatApiErrorDetails( $response_code, $response_body )
				);

				// Log detailed error information.
				$error_log = array(
					'provider'      => $provider,
					'response_code' => $response_code,
					'response_body' => $response_body,
					'request_body'  => $request_body,
					'timestamp'     => current_time( 'mysql' ),
				);
				$this->logApiError( $error_log );
			}
		} else {
			// The call went through, so whatever the panel is still reporting
			// is no longer true.
			$this->clearApiErrorLog();

			$raw_data = json_decode( wp_remote_retrieve_body( $response ) );

			// Parse response based on provider format
			// GPT-5 uses the Responses API with different response structure.
			// Gateway providers (seopress) return chat completions format for all models.
			if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
				$data = $this->parseGpt5Response( $raw_data );
			} else {
				$data = $this->parseResponse( $raw_data, $provider );
			}

			$message = 'Success';

			if ( empty( $meta ) || 'title' === $meta ) {
				$result = $this->decodeJsonPayload( $data->choices[0]->message->content );

				$result = is_array( $result ) && isset( $result['title'] ) ? $result['title'] : '';

				$title = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $result ) ) ) ), '"' ) );

				// An answer the model returned without a title is a failed
				// generation: saving it would wipe the meta already in place.
				// The image generator has always guarded its own saves this way.
				if ( true === $autosave && '' !== $title ) {
					update_post_meta( $post_id, '_seopress_titles_title', sanitize_text_field( html_entity_decode( $title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) );
				}
			}

			if ( empty( $meta ) ) {
				$result = $this->decodeJsonPayload( $data->choices[0]->message->content );
				$result = is_array( $result ) && isset( $result['desc'] ) ? $result['desc'] : '';
			} elseif ( 'desc' === $meta ) {
				$result = $this->decodeJsonPayload( $data->choices[0]->message->content );
				$result = is_array( $result ) && isset( $result['desc'] ) ? $result['desc'] : '';
			}

			if ( empty( $meta ) || 'desc' === $meta ) {
				$description = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $result ) ) ) ), '"' ) );

				if ( true === $autosave && '' !== $description ) {
					update_post_meta( $post_id, '_seopress_titles_desc', sanitize_textarea_field( html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) );
				}
			}
		}

		// The transport succeeded, which is not the same as having generated
		// something: report an answer that came back without any of the
		// requested values as the failure it is.
		$success = ( 'Success' === $message );

		if ( $success ) {
			$generated = array();
			if ( empty( $meta ) || 'title' === $meta ) {
				$generated[] = $title;
			}
			if ( empty( $meta ) || 'desc' === $meta ) {
				$generated[] = $description;
			}

			if ( ! $this->hasGeneratedContent( $generated ) ) {
				$success = false;
				$message = $this->describeEmptyResponse( $data, $provider, $request_body );
			}
		}

		$data = array(
			'success' => $success,
			'message' => $message,
			'title'   => html_entity_decode( $title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			'desc'    => html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
		);

		return $data;
	}

	/**
	 * Build the AI prompt context for a taxonomy term.
	 *
	 * A term has no content of its own, which is why AI generation for terms was
	 * unusable. Feed the model the term name, its description and the titles of the
	 * most recent posts attached to it, so it has real material about the topic.
	 *
	 * @since 10.1.0
	 *
	 * @param \WP_Term $term     The term.
	 * @param string   $taxonomy The taxonomy.
	 *
	 * @return string
	 */
	protected function buildTermContext( $term, $taxonomy ) {
		$parts   = array();
		$parts[] = sprintf( 'Taxonomy term name: %s', $term->name );

		$description = wp_strip_all_tags( strip_shortcodes( (string) $term->description ) );
		if ( '' !== trim( $description ) ) {
			$parts[] = sprintf( 'Term description: %s', $description );
		}

		/**
		 * Number of related post titles sent to the model as context for a term.
		 *
		 * @since 10.1.0
		 *
		 * @param int    $number   Number of posts.
		 * @param int    $term_id  The term id.
		 * @param string $taxonomy The taxonomy.
		 */
		$number = (int) apply_filters( 'seopress_ai_term_related_posts_number', 10, $term->term_id, $taxonomy );

		if ( $number > 0 ) {
			$related = get_posts(
				array(
					'post_type'              => 'any',
					'post_status'            => 'publish',
					'posts_per_page'         => $number,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => array(
						array(
							'taxonomy' => $taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
				)
			);

			$titles = array();
			foreach ( $related as $related_id ) {
				$related_title = get_the_title( $related_id );
				if ( '' !== trim( $related_title ) ) {
					$titles[] = wp_strip_all_tags( $related_title );
				}
			}

			if ( ! empty( $titles ) ) {
				$parts[] = sprintf( 'Titles of content published in this term: %s', implode( '; ', $titles ) );
			}
		}

		$content = implode( '. ', $parts );

		// Mirror the post path: keep the payload bounded (a larger value returns a 400).
		return wp_trim_words( $content, 500 );
	}

	/**
	 * Generate a meta title and/or meta description for a taxonomy term with AI.
	 *
	 * The post generator (generateTitlesDesc) reads post_content and validates a
	 * post id, so it cannot serve terms. This resolves the term context
	 * (buildTermContext) and reuses the same provider primitives, without touching
	 * the post path.
	 *
	 * @since 10.1.0
	 *
	 * @param int    $term_id  The term id.
	 * @param string $taxonomy The taxonomy.
	 * @param string $meta     '' (both), 'title' or 'desc'.
	 * @param string $language The language locale.
	 * @param string $provider The AI provider, or null for the user's default.
	 *
	 * @return array{ success: bool, message: string, title: string, desc: string }
	 */
	public function generateTermTitlesDesc( $term_id, $taxonomy, $meta = '', $language = 'en_US', $provider = null ) {
		$term_id = absint( $term_id );
		$term    = $term_id ? get_term( $term_id, $taxonomy ) : null;

		if ( ! $term instanceof \WP_Term ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid term provided.', 'wp-seopress-pro' ),
				'title'   => '',
				'desc'    => '',
			);
		}

		if ( null === $provider ) {
			$provider = $this->getCurrentProvider();
		}

		$title       = '';
		$description = '';
		$message     = '';

		if ( empty( $language ) ) {
			$language = get_locale();
		}

		// Per-term language override on multilingual sites, mirroring the post path.
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$wpml_details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $term_id, 'element_type' => 'tax_' . $taxonomy ) );
			if ( ! empty( $wpml_details['locale'] ) ) {
				$language = $wpml_details['locale'];
			}
		}
		if ( function_exists( 'pll_get_term_language' ) ) {
			$pll_locale = pll_get_term_language( $term_id, 'locale' );
			if ( ! empty( $pll_locale ) ) {
				$language = $pll_locale;
			}
		}

		$content = $this->buildTermContext( $term, $taxonomy );

		// The term has no target keyword field; the term name is the natural keyword hint.
		$target_keywords = $term->name;

		$language = $this->getLanguageDisplayName( $language );

		$model_name = $this->getAIModel( $provider );
		$body       = array(
			'model'       => $model_name,
			'temperature' => 1,
		);

		$is_gpt5_model = strpos( $model_name, 'gpt-5' ) !== false;
		$max_tokens    = $this->getMaxOutputTokens( $provider, $model_name );
		if ( $is_gpt5_model ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens'] = $max_tokens;
		}

		if ( $this->supportsResponseFormat( $provider ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$body['messages'] = array();

		$prompt_title = sprintf(
			/* translators: 1: language, 2: target keywords, 3: content */
			__( 'Generate, in this language %1$s, an engaging SEO title in one sentence of sixty characters maximum for a taxonomy archive page (a page that lists several articles, not a single article). Include at least one of these keywords in the response: "%2$s". Use the context below only to understand the overall theme of the archive; the article titles it lists are only examples of its content, so do not copy, quote, or build the title around any single one of them. Context: %3$s.', 'wp-seopress-pro' ),
			esc_attr( $language ),
			esc_html( $target_keywords ),
			esc_html( $content )
		);
		$prompt_title = apply_filters( 'seopress_ai_' . $provider . '_meta_title', $prompt_title, 0 );

		if ( empty( $meta ) || 'title' === $meta ) {
			$body['messages'][] = array( 'role' => 'user', 'content' => $prompt_title );
		}

		$prompt_desc = sprintf(
			/* translators: 1: language, 2: target keywords, 3: content */
			__( 'Generate, in this language %1$s, an engaging SEO meta description in less than 160 characters for a taxonomy archive page (a page that lists several articles, not a single article). Include at least one of these keywords in the response: "%2$s". Use the context below only to understand the overall theme of the archive; the article titles it lists are only examples of its content, so do not copy, quote, or build the description around any single one of them. Context: %3$s.', 'wp-seopress-pro' ),
			esc_attr( $language ),
			esc_html( $target_keywords ),
			esc_html( $content )
		);
		$prompt_desc = apply_filters( 'seopress_ai_' . $provider . '_meta_desc', $prompt_desc, 0 );

		if ( empty( $meta ) || 'desc' === $meta ) {
			$body['messages'][] = array( 'role' => 'user', 'content' => $prompt_desc );
		}

		$custom_instructions = seopress_pro_get_service( 'OptionPro' )->getAICustomInstructions();
		$custom_instructions = apply_filters( 'seopress_ai_' . $provider . '_custom_instructions', $custom_instructions, 0 );
		if ( ! empty( $custom_instructions ) ) {
			$body['messages'][] = array( 'role' => 'user', 'content' => wp_strip_all_tags( $custom_instructions ) );
		}

		$json_instruction = 'Provide the answer as a JSON object with "title" as first key and "desc" for second key for parsing in this language ' . $language . '. You must respect the grammar and typing of the language.';
		if ( ! $this->supportsResponseFormat( $provider ) ) {
			$json_instruction = 'You must respond with ONLY a valid JSON object. The JSON must have exactly two keys: "title" (for the meta title) and "desc" (for the meta description). Use this language: ' . $language . '. Format: {"title": "your title here", "desc": "your description here"}';
		}
		$body['messages'][] = array( 'role' => 'user', 'content' => $json_instruction );

		if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
			$request_body = $this->buildGpt5RequestBody( $body );
		} else {
			$request_body = $this->buildRequestBody( $body, $provider );
		}

		if ( $this->isGeminiProvider( $provider ) ) {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
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
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'x-api-key'         => $this->getProviderApiKey( $provider ),
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
					'Referer'           => trailingslashit( home_url() ),
				),
			);
		} else {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->getProviderApiKey( $provider ),
					'Content-Type'  => 'application/json',
					'Referer'       => trailingslashit( home_url() ),
				),
			);
		}

		$args = apply_filters( 'seopress_ai_' . $provider . '_request_args', $args );

		$endpoints = $this->getProviderEndpoints( $provider );
		if ( $this->isGeminiProvider( $provider ) ) {
			$url = $endpoints['generate_content'] . $this->getAIModel( $provider ) . ':generateContent?key=' . $this->getProviderApiKey( $provider );
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			$url = $endpoints['messages'];
		} elseif ( $is_gpt5_model && isset( $endpoints['responses'] ) ) {
			$url = $endpoints['responses'];
		} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
			$url = $endpoints['chat_completions'];
		} else {
			$url = $endpoints['completions'];
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				$this->logTransportError( $response, $provider );
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );
				$message       = sprintf(
					/* translators: 1: provider name, 2: response code, 3: error details */
					__( 'An error occurred with %1$s API. Response code: %2$s. Details: %3$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code,
					// The details come from the provider's HTTP response: never
					// trust them as markup.
					esc_html( $this->formatApiErrorDetails( $response_code, $response_body ) )
				);
				$this->logApiError(
					array(
						'provider'      => $provider,
						'response_code' => $response_code,
						'response_body' => $response_body,
						'request_body'  => $request_body,
						'timestamp'     => current_time( 'mysql' ),
					)
				);
			}
		} else {
			// The call went through, so whatever the panel is still reporting
			// is no longer true.
			$this->clearApiErrorLog();

			$raw_data = json_decode( wp_remote_retrieve_body( $response ) );
			if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
				$data = $this->parseGpt5Response( $raw_data );
			} else {
				$data = $this->parseResponse( $raw_data, $provider );
			}

			$message = 'Success';

			$decoded = isset( $data->choices[0]->message->content ) ? $this->decodeJsonPayload( $data->choices[0]->message->content ) : array();

			if ( empty( $meta ) || 'title' === $meta ) {
				$result = is_array( $decoded ) && isset( $decoded['title'] ) ? $decoded['title'] : '';
				$title  = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $result ) ) ) ), '"' ) );
			}

			if ( empty( $meta ) || 'desc' === $meta ) {
				$result      = is_array( $decoded ) && isset( $decoded['desc'] ) ? $decoded['desc'] : '';
				$description = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $result ) ) ) ), '"' ) );
			}
		}

		$success = ( 'Success' === $message );

		if ( $success ) {
			$generated = array();
			if ( empty( $meta ) || 'title' === $meta ) {
				$generated[] = $title;
			}
			if ( empty( $meta ) || 'desc' === $meta ) {
				$generated[] = $description;
			}

			if ( ! $this->hasGeneratedContent( $generated ) ) {
				$success = false;
				$message = $this->describeEmptyResponse( $data, $provider, $request_body );
			}
		}

		return array(
			'success' => $success,
			'message' => $message,
			'title'   => html_entity_decode( $title, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			'desc'    => html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
		);
	}

	/**
	 * Generate social media meta tags (Facebook/Twitter).
	 *
	 * This function generates social media titles or descriptions based on the provided parameters.
	 *
	 * @param int    $post_id   The ID of the post for which to generate social metas.
	 * @param string $meta_type title|desc.
	 * @param string $platform  facebook|twitter.
	 * @param string $language  The language for generating social metas (default is 'en_US').
	 * @param string $provider  The AI provider to use (default is null, uses user's saved preference).
	 *
	 * @return array $data The answer from AI with success/content
	 */
	public function generateSocialMetas( // phpcs:ignore
		$post_id,
		$meta_type = 'title',
		$platform = 'facebook',
		$language = 'en_US',
		$provider = null
	) {
		// Validate the post ID.
		$post_id = absint( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid post ID provided.', 'wp-seopress-pro' ),
				'content' => '',
			);
		}

		// If no provider specified, get from user settings.
		if ( null === $provider ) {
			$provider = $this->getCurrentProvider();
		}

		// Initialize the content result.
		$content_result = '';
		$message        = '';
		if ( empty( $language ) ) {
			$language = get_locale();
		}

		$content = get_post_field( 'post_content', $post_id );
		$content = esc_attr( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $content ) ) ) ) );

		// Compatibility with current page and theme builders.
		$theme = wp_get_theme();

		// Divi.
		if ( 'Divi' == $theme->template || 'Divi' == $theme->parent_theme ) {
			$regex   = '/\[(\[?)(et_pb_[^\s\]]+)(?:(\s)[^\]]+)?\]?(?:(.+?)\[\/\2\])?|\[\/(et_pb_[^\s\]]+)?\]/';
			$content = preg_replace( $regex, '', $content );
		}

		// Bricks compatibility.
		if ( defined( 'BRICKS_DB_EDITOR_MODE' ) && ( 'bricks' == $theme->template || 'Bricks' == $theme->parent_theme ) ) {
			$page_sections = get_post_meta( $post_id, BRICKS_DB_PAGE_CONTENT, true );
			$editor_mode   = get_post_meta( $post_id, BRICKS_DB_EDITOR_MODE, true );

			if ( is_array( $page_sections ) && 'WordPress' !== $editor_mode ) {
				$content = \Bricks\Frontend::render_data( $page_sections );
			}
		}

		// Limit post content sent to 500 words (higher value will return a 400 error).
		$content = wp_trim_words( $content, 500 );

		// If no post_content use the permalink.
		if ( empty( $content ) ) {
			$content = get_permalink( $post_id );
		}

		$model_name = $this->getAIModel( $provider );
		$body       = array(
			'model'       => $model_name,
			'temperature' => 1,
		);

		// GPT-5 models use max_completion_tokens instead of max_tokens.
		$is_gpt5_model = strpos( $model_name, 'gpt-5' ) !== false;
		$max_tokens    = $this->getMaxOutputTokens( $provider, $model_name );
		if ( $is_gpt5_model ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens'] = $max_tokens;
		}

		// Add response_format only if supported by the provider.
		if ( $this->supportsResponseFormat( $provider ) ) {
			$body['response_format'] = array(
				'type' => 'json_object',
			);
		}

		$body['messages'] = array();

		// Per-post language override. The React metabox derives `$language`
		// from the editor / site locale and passes it in regardless of which
		// translation we are editing, so on multilingual sites we must look
		// up the locale of this specific post. Apply additively: only
		// override `$language` when WPML or Polylang actually resolves a
		// per-post locale.
		if ( $post_id ) {
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$wpml_details = apply_filters( 'wpml_post_language_details', null, $post_id );
				if ( ! empty( $wpml_details['locale'] ) ) {
					$language = $wpml_details['locale'];
				}
			}

			if ( function_exists( 'pll_get_post_language' ) ) {
				$pll_locale = pll_get_post_language( $post_id, 'locale' );
				if ( ! empty( $pll_locale ) ) {
					$language = $pll_locale;
				}
			}
		}

		// Convert language code to readable name.
		$language = $this->getLanguageDisplayName( $language );

		// Get target keywords.
		$target_keywords = ! empty( get_post_meta( $post_id, '_seopress_analysis_target_kw', true ) ) ? get_post_meta( $post_id, '_seopress_analysis_target_kw', true ) : null;

		// Build prompts based on platform and meta type.
		if ( 'facebook' === $platform ) {
			if ( 'title' === $meta_type ) {
				$prompt = sprintf(
					/* translators: 1: language, 2: target keywords, 3: content */
					__( 'Generate, in this language %1$s, an engaging and emotional Facebook/Open Graph title in one sentence of sixty characters maximum, optimized for social sharing with at least one of these keywords: "%2$s", based on this content: %3$s. Make it catchy and clickable for social media.', 'wp-seopress-pro' ),
					esc_attr( $language ),
					esc_html( $target_keywords ),
					esc_html( $content )
				);
			} else {
				$prompt = sprintf(
					/* translators: 1: language, 2: target keywords, 3: content */
					__( 'Generate, in this language %1$s, an engaging and compelling Facebook/Open Graph description in less than 160 characters, optimized for social sharing with at least one of these keywords: "%2$s", based on this content: %3$s. Make it emotional and encourage clicks.', 'wp-seopress-pro' ),
					esc_attr( $language ),
					esc_html( $target_keywords ),
					esc_html( $content )
				);
			}
		} else {
			// Twitter/X.
			if ( 'title' === $meta_type ) {
				$prompt = sprintf(
					/* translators: 1: language, 2: target keywords, 3: content */
					__( 'Generate, in this language %1$s, a punchy and concise X/Twitter title in one sentence of sixty characters maximum, with at least one of these keywords: "%2$s", based on this content: %3$s. Make it short, impactful and suitable for X.', 'wp-seopress-pro' ),
					esc_attr( $language ),
					esc_html( $target_keywords ),
					esc_html( $content )
				);
			} else {
				$prompt = sprintf(
					/* translators: 1: language, 2: target keywords, 3: content */
					__( 'Generate, in this language %1$s, a concise and impactful X/Twitter description in less than 160 characters, with at least one of these keywords: "%2$s", based on this content: %3$s. Keep it brief and punchy for X.', 'wp-seopress-pro' ),
					esc_attr( $language ),
					esc_html( $target_keywords ),
					esc_html( $content )
				);
			}
		}

		$msg = apply_filters( 'seopress_ai_' . $provider . '_social_' . $platform . '_' . $meta_type, $prompt, $post_id );

		$body['messages'][] = array(
			'role'    => 'user',
			'content' => $msg,
		);

		// For providers that don't support response_format, we need to be more explicit about JSON formatting.
		$json_instruction = 'Provide the answer as a JSON object with "content" as the key for parsing in this language ' . $language . '. You must respect the grammar and typing of the language.';

		if ( ! $this->supportsResponseFormat( $provider ) ) {
			$json_instruction = 'You must respond with ONLY a valid JSON object. The JSON must have exactly one key: "content" (for the ' . $meta_type . '). Use this language: ' . $language . '. Format: {"content": "your ' . $meta_type . ' here"}';
		}

		$body['messages'][] = array(
			'role'    => 'user',
			'content' => $json_instruction,
		);

		// Build the request body based on provider format.
		// GPT-5 models use the Responses API with different parameters.
		// Gateway providers (seopress) use chat completions format for all models.
		if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
			$request_body = $this->buildGpt5RequestBody( $body );
		} else {
			$request_body = $this->buildRequestBody( $body, $provider );
		}

		// Provider-aware auth headers: Gemini passes the key in the URL query
		// (no auth header), Claude uses x-api-key + anthropic-version, the rest
		// use a Bearer token. Mirrors the other AI request flows in this class.
		if ( $this->isGeminiProvider( $provider ) ) {
			$headers = array(
				'Content-Type' => 'application/json',
				'Referer'      => trailingslashit( home_url() ),
			);
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			$headers = array(
				'x-api-key'         => $this->getProviderApiKey( $provider ),
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
				'Referer'           => trailingslashit( home_url() ),
			);
		} else {
			$headers = array(
				'Authorization' => 'Bearer ' . $this->getProviderApiKey( $provider ),
				'Content-Type'  => 'application/json',
				'Referer'       => trailingslashit( home_url() ),
			);
		}

		$args = array(
			'body'        => wp_json_encode( $request_body ),
			'timeout'     => '30',
			'redirection' => '5',
			'httpversion' => '1.0',
			'blocking'    => true,
			'headers'     => $headers,
		);

		$args = apply_filters( 'seopress_ai_' . $provider . '_social_request_args', $args );

		// Provider-aware endpoint URL. Gemini and Claude expose their own
		// endpoint keys (generate_content / messages); without these branches
		// the URL falls back to an unset key and wp_remote_post() fails with
		// "A valid URL was not provided.".
		$endpoints = $this->getProviderEndpoints( $provider );
		if ( $this->isGeminiProvider( $provider ) ) {
			// Gemini URL: base + model + :generateContent?key=API_KEY.
			$model = $this->getAIModel( $provider );
			$url   = $endpoints['generate_content'] . $model . ':generateContent?key=' . $this->getProviderApiKey( $provider );
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			$url = $endpoints['messages'];
		} elseif ( $is_gpt5_model && isset( $endpoints['responses'] ) ) {
			// GPT-5 uses the Responses API (native OpenAI only; gateway-based
			// providers such as SEOPress Credits use chat/completions instead).
			$url = $endpoints['responses'];
		} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
			$url = $endpoints['chat_completions'];
		} else {
			$url = $endpoints['completions'];
		}

		$response = wp_remote_post( $url, $args );

		// Make sure the response came back okay.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				$this->logTransportError( $response, $provider );
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );
				$message       = sprintf(
					/* translators: 1: provider name, 2: response code */
					__( 'An error occurred with %1$s API, please try again. Response code: %2$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code
				);

				// Log detailed error information.
				$error_log = array(
					'provider'      => $provider,
					'response_code' => $response_code,
					'response_body' => $response_body,
					'request_body'  => $request_body,
					'timestamp'     => current_time( 'mysql' ),
				);
				$this->logApiError( $error_log );
			}
		} else {
			// The call went through, so whatever the panel is still reporting
			// is no longer true.
			$this->clearApiErrorLog();

			$raw_data = json_decode( wp_remote_retrieve_body( $response ) );

			// Parse response based on provider format.
			// GPT-5 uses the Responses API with different response structure.
			if ( $is_gpt5_model ) {
				$data = $this->parseGpt5Response( $raw_data );
			} else {
				$data = $this->parseResponse( $raw_data, $provider );
			}

			$message = 'Success';

			$result = $this->decodeJsonPayload( $data->choices[0]->message->content );

			$result = is_array( $result ) && isset( $result['content'] ) ? $result['content'] : '';

			$content_result = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $result ) ) ) ), '"' ) );
		}

		$success = ( 'Success' === $message );

		if ( $success && ! $this->hasGeneratedContent( array( $content_result ) ) ) {
			$success = false;
			$message = $this->describeEmptyResponse( $data, $provider, $request_body );
		}

		$data = array(
			'success' => $success,
			'message' => $message,
			'content' => html_entity_decode( $content_result, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
		);

		return $data;
	}

	/**
	 * Generate alt text for an image.
	 *
	 * This function generates the alternative text for an image file.
	 *
	 * @param int    $post_id   The ID of the post for which to generate titles and descriptions.
	 * @param string $action    The action to run (optional).
	 * @param string $language  The language for generating titles and descriptions (default is 'en_US').
	 * @param bool   $update_empty_alt_text  Whether to update empty alt text (default is true).
	 * @param string $provider  The AI provider to use (default is null, uses user's saved preference).
	 * @param array  $fields    Specific fields to generate (default is null, generates all). Accepts: 'alt_text', 'caption', 'description'.
	 *
	 * @return array The answer from AI: success, message, alt_text, caption, description.
	 *               Always this shape, whatever $action is. Every early return
	 *               already answered the array, so the string variant meant callers
	 *               reading the success path with the failure shape in mind.
	 */
	public function generateImgAltText(
		$post_id,
		$action = '',
		$language = 'en_US',
		$update_empty_alt_text = true,
		$provider = null,
		$fields = null
	) {
		// Validate post_id.
		$post_id = absint( $post_id );
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return array(
				'success'     => false,
				'message'     => __( 'Invalid post ID provided.', 'wp-seopress-pro' ),
				'alt_text'    => '',
				'caption'     => '',
				'description' => '',
			);
		}

		// If no provider specified, get from user settings.
		if ( null === $provider ) {
			$provider = $this->getCurrentProvider();
		}

		// Check if provider supports multimodal content.
		if ( ! $this->supportsMultimodal( $provider ) ) {
			return array(
				'success'     => false,
				'message'     => sprintf(
					/* translators: 1: provider name */
					__( 'Image alt text generation is not supported by %1$s. Please use OpenAI or another provider that supports multimodal content.', 'wp-seopress-pro' ),
					$this->getProviderName( $provider )
				),
				'alt_text'    => '',
				'caption'     => '',
				'description' => '',
			);
		}

		// Default fields to all three if not specified.
		$all_fields = array( 'alt_text', 'caption', 'description' );
		if ( null === $fields || empty( $fields ) ) {
			$fields = $all_fields;
		}
		$fields = array_intersect( $fields, $all_fields );

		// Update empty alt text only.
		$current_alt_text = get_post_meta( $post_id, '_wp_attachment_image_alt', true );

		// Get current post data for caption and description check.
		$current_post        = get_post( $post_id );
		$current_caption     = $current_post ? $current_post->post_excerpt : '';
		$current_description = $current_post ? $current_post->post_content : '';

		// If "only if missing" mode, check only the requested fields.
		if ( ! $update_empty_alt_text ) {
			$all_requested_filled = true;
			foreach ( $fields as $field ) {
				if ( 'alt_text' === $field && empty( $current_alt_text ) ) {
					$all_requested_filled = false;
				}
				if ( 'caption' === $field && empty( $current_caption ) ) {
					$all_requested_filled = false;
				}
				if ( 'description' === $field && empty( $current_description ) ) {
					$all_requested_filled = false;
				}
			}
			if ( $all_requested_filled ) {
				// Nothing to generate is not a failure: the values the caller
				// asked for are already there, and they are returned as they
				// stand.
				return array(
					'success'     => true,
					'message'     => __( 'Alt text, caption, and description already exist, no need to generate them.', 'wp-seopress-pro' ),
					'alt_text'    => $current_alt_text,
					'caption'     => $current_caption,
					'description' => $current_description,
				);
			}
		}

		if ( 'alt_text' === $action || 'image_meta' === $action ) {
			// Use site language (not admin user's profile language) since alt text is frontend content.
			$site_locale = get_option( 'WPLANG' ) ?: 'en_US';
			if ( function_exists( 'seopress_normalized_locale' ) ) {
				$language = seopress_normalized_locale( $site_locale );
			} else {
				$language = $site_locale;
			}

			// WPML.
			if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				$language = apply_filters( 'wpml_post_language_details', null, $post_id );
				$language = ! empty( $language['locale'] ) ? $language['locale'] : $site_locale;
			}

			// Polylang.
			if ( function_exists( 'pll_get_post_language' ) ) {
				$language = ! empty( pll_get_post_language( $post_id, 'locale' ) ) ? pll_get_post_language( $post_id, 'locale' ) : $site_locale;
			}
		}

		// Convert language code to readable name.
		$language = $this->getLanguageDisplayName( $language );

		$image_src = wp_get_attachment_image_src( $post_id, 'full' );

		// Check if the attachment is an SVG - these are not supported for AI image analysis.
		$mime_type = get_post_mime_type( $post_id );
		if ( 'image/svg+xml' === $mime_type ) {
			return array(
				'success'     => false,
				'message'     => __( 'SVG files are not supported for AI image analysis. Please use JPG, PNG, GIF, or WebP images.', 'wp-seopress-pro' ),
				'alt_text'    => '',
				'caption'     => '',
				'description' => '',
			);
		}

		// Fetch image as base64 for better compatibility (works with localhost, firewalls, etc.).
		// The attachment ID is passed so the file can be read from disk rather
		// than fetched over HTTP: see fetchImageAsBase64().
		$image_url  = is_array( $image_src ) && ! empty( $image_src[0] ) ? $image_src[0] : '';
		$image_data = $this->fetchImageAsBase64( $image_url, $post_id );

		if ( ! $image_data ) {
			return array(
				'success'     => false,
				'message'     => __( 'Could not fetch the image. Please check if the image URL is accessible.', 'wp-seopress-pro' ),
				'alt_text'    => '',
				'caption'     => '',
				'description' => '',
			);
		}

		// Create data URI for OpenAI (base64 format).
		$image_data_uri = 'data:' . $image_data['mime_type'] . ';base64,' . $image_data['data'];

		// Build prompt dynamically based on requested fields.
		$prompt_image_meta = sprintf(
			/* translators: %s: language */
			esc_html__( 'Analyze this image and generate the following in %s:', 'wp-seopress-pro' ),
			esc_attr( $language )
		);

		$field_number = 1;
		if ( in_array( 'alt_text', $fields, true ) ) {
			$prompt_image_meta .= "\n" . $field_number . '. ' . esc_html__( 'alt_text: A concise alternative text (max 10 words) for accessibility and SEO', 'wp-seopress-pro' );
			++$field_number;
		}
		if ( in_array( 'caption', $fields, true ) ) {
			$prompt_image_meta .= "\n" . $field_number . '. ' . esc_html__( 'caption: A short, engaging caption suitable for display below the image (1-2 sentences)', 'wp-seopress-pro' );
			++$field_number;
		}
		if ( in_array( 'description', $fields, true ) ) {
			$prompt_image_meta .= "\n" . $field_number . '. ' . esc_html__( 'description: A detailed description of the image content (2-3 sentences)', 'wp-seopress-pro' );
		}

		$prompt_image_meta = apply_filters( 'seopress_ai_' . $provider . '_image_meta', $prompt_image_meta, $post_id );

		// Build JSON keys string from requested fields.
		$json_keys = implode( ', ', $fields );

		// For providers that don't support response_format, we need to be more explicit about JSON formatting.
		if ( $this->supportsResponseFormat( $provider ) ) {
			/* translators: %s: comma-separated list of JSON keys */
			$prompt_image_meta .= "\n" . sprintf( esc_html__( 'Return the answer as a JSON object with keys: %s.', 'wp-seopress-pro' ), $json_keys );
		} else {
			$json_example_parts = array();
			foreach ( $fields as $f ) {
				$json_example_parts[] = '"' . $f . '": "..."';
			}
			/* translators: %s: JSON object example */
			$prompt_image_meta .= "\n" . sprintf( esc_html__( 'You must respond with ONLY a valid JSON object. Format: {%s}', 'wp-seopress-pro' ), implode( ', ', $json_example_parts ) );
		}

		$model_name = $this->getAIModel( $provider );

		$body = array(
			'model'       => $model_name,
			'temperature' => 1,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt_image_meta,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => $image_data_uri,
							),
						),
					),
				),
			),
		);

		// Scale max tokens based on number of requested fields (~150 per field, minimum 200).
		$max_tokens    = $this->getMaxOutputTokens( $provider, $model_name, max( 200, count( $fields ) * 150 ) );
		$is_gpt5_model = strpos( $model_name, 'gpt-5' ) !== false;
		if ( $is_gpt5_model ) {
			$body['max_completion_tokens'] = $max_tokens;
		} else {
			$body['max_tokens'] = $max_tokens;
		}

		// Add response_format only if supported by the provider.
		if ( $this->supportsResponseFormat( $provider ) ) {
			$body['response_format'] = array(
				'type' => 'json_object',
			);
		}

		// Build the request body based on provider format
		// GPT-5 models use the Responses API with different parameters.
		// Gateway providers (seopress) use chat completions format for all models.
		if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
			$request_body = $this->buildGpt5RequestBody( $body );
			// For GPT-5 with images, we need to add the image to the input
			// Using base64 data URI for better compatibility
			// GPT-5 Responses API requires role/content wrapper for multimodal inputs.
			$request_body['input'] = array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $prompt_image_meta,
						),
						array(
							'type'      => 'input_image',
							'image_url' => $image_data_uri,
						),
					),
				),
			);
		} else {
			$request_body = $this->buildRequestBody( $body, $provider );
		}

		// Build request args - different providers use different auth methods.
		// Referer mirrors the site URL so Google Cloud "Websites" key restrictions match.
		if ( $this->isGeminiProvider( $provider ) ) {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
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
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'x-api-key'         => $this->getProviderApiKey( $provider ),
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
					'Referer'           => trailingslashit( home_url() ),
				),
			);
		} else {
			$args = array(
				'body'        => wp_json_encode( $request_body ),
				'timeout'     => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking'    => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->getProviderApiKey( $provider ),
					'Content-Type'  => 'application/json',
					'Referer'       => trailingslashit( home_url() ),
				),
			);
		}

		$args = apply_filters( 'seopress_ai_' . $provider . '_request_args_alt', $args, $post_id );

		// Build URL based on provider.
		$endpoints = $this->getProviderEndpoints( $provider );
		if ( $this->isGeminiProvider( $provider ) ) {
			// Gemini URL: base + model + :generateContent?key=API_KEY.
			$model = $this->getAIModel( $provider );
			$url   = $endpoints['generate_content'] . $model . ':generateContent?key=' . $this->getProviderApiKey( $provider );
		} elseif ( $this->isClaudeProvider( $provider ) ) {
			$url = $endpoints['messages'];
		} elseif ( $is_gpt5_model && isset( $endpoints['responses'] ) ) {
			// GPT-5 uses Responses API (only for native OpenAI; gateway-based providers use chat/completions).
			$url = $endpoints['responses'];
		} elseif ( $this->isChatCompletionsProvider( $provider ) ) {
			$url = $endpoints['chat_completions'];
		} else {
			$url = $endpoints['completions'];
		}

		$response = wp_remote_post( $url, $args );

		$alt_text    = '';
		$caption     = '';
		$description = '';

		// make sure the response came back okay.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();

				$this->logTransportError( $response, $provider );
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );
				$message       = sprintf(
					/* translators: 1: provider name, 2: response code */
					__( 'An error occurred with %1$s API, please try again. Response code: %2$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code
				);

				// Log detailed error information.
				$error_log = array(
					'provider'      => $provider,
					'response_code' => $response_code,
					'response_body' => $response_body,
					'request_body'  => $request_body,
					'timestamp'     => current_time( 'mysql' ),
				);
				$this->logApiError( $error_log );
			}
		} else {
			// The call went through, so whatever the panel is still reporting
			// is no longer true.
			$this->clearApiErrorLog();

			// Get the response body once.
			$response_body = wp_remote_retrieve_body( $response );

			// Decode as object for parseResponse method.
			$response_object = json_decode( $response_body, false );

			// Check if JSON decode was successful.
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$message = sprintf(
					/* translators: 1: provider name, 2: error message */
					__( 'Invalid JSON response from %1$s API: %2$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					json_last_error_msg()
				);
			} else {
				// Parse response based on provider format
				// GPT-5 uses the Responses API with different response structure.
				// Gateway providers (seopress) return chat completions format for all models.
				if ( $is_gpt5_model && 'seopress' !== strtolower( $provider ) ) {
					$data = $this->parseGpt5Response( $response_object );
				} else {
					$data = $this->parseResponse( $response_object, $provider );
				}

				$message = 'Success';

				// Extract the content from the response.
				if ( isset( $data->choices[0]->message->content ) ) {
					$result = $data->choices[0]->message->content;

					// Check if content is null (AI refused to process).
					if ( null === $result ) {
						$refusal_message = isset( $data->choices[0]->message->refusal ) ? $data->choices[0]->message->refusal : __( 'AI refused to describe this image.', 'wp-seopress-pro' );
						$message         = $refusal_message;
					} else {
						// Parse the JSON content to extract all fields.
						$parsed_result = $this->decodeJsonPayload( $result );

						// Handle JSON format with all three fields.
						if ( is_array( $parsed_result ) ) {
							if ( isset( $parsed_result['alt_text'] ) ) {
								$alt_text = esc_attr( trim( stripslashes_deep( wp_filter_nohtml_kses( wp_strip_all_tags( strip_shortcodes( $parsed_result['alt_text'] ) ) ) ), '"' ) );
							}
							if ( isset( $parsed_result['caption'] ) ) {
								$caption = trim( stripslashes_deep( wp_strip_all_tags( strip_shortcodes( $parsed_result['caption'] ) ) ), '"' );
							}
							if ( isset( $parsed_result['description'] ) ) {
								$description = trim( stripslashes_deep( wp_strip_all_tags( strip_shortcodes( $parsed_result['description'] ) ) ), '"' );
							}
						}
					}
				} else {
					$message = __( 'Unable to extract content from AI response.', 'wp-seopress-pro' );
				}
			}
		}

		// A 200 with no usable content in it is a failed generation, not a
		// success with empty values: only the fields that were asked for count.
		$success = ( 'Success' === $message );

		if ( $success ) {
			$generated = array(
				'alt_text'    => $alt_text,
				'caption'     => $caption,
				'description' => $description,
			);

			$requested = array();
			foreach ( $fields as $field ) {
				$requested[] = $generated[ $field ];
			}

			if ( ! $this->hasGeneratedContent( $requested ) ) {
				$success = false;
				$message = $this->describeEmptyResponse( $data, $provider, $request_body );
			}
		}

		$data = array(
			'success'     => $success,
			'message'     => $message,
			'alt_text'    => html_entity_decode( $alt_text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			'caption'     => html_entity_decode( $caption, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
			'description' => html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ),
		);

		// Save alt text to post meta (only if requested and if missing when $update_empty_alt_text is false).
		if ( in_array( 'alt_text', $fields, true ) && ! empty( $alt_text ) && ( $update_empty_alt_text || empty( $current_alt_text ) ) ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', apply_filters( 'seopress_update_alt', sanitize_text_field( html_entity_decode( $alt_text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ) );
		}

		// Save caption to post_excerpt (only if requested and if missing when $update_empty_alt_text is false).
		if ( in_array( 'caption', $fields, true ) && ! empty( $caption ) && ( $update_empty_alt_text || empty( $current_caption ) ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => apply_filters( 'seopress_update_caption', sanitize_text_field( html_entity_decode( $caption, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ),
				)
			);
		}

		// Save description to post_content (only if requested and if missing when $update_empty_alt_text is false).
		if ( in_array( 'description', $fields, true ) && ! empty( $description ) && ( $update_empty_alt_text || empty( $current_description ) ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => apply_filters( 'seopress_update_description', wp_kses_post( html_entity_decode( $description, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ),
				)
			);
		}

		// One shape for one method. This used to answer the bare string when
		// $action was 'alt_text', while every early return above answered the
		// array, so the callers checking $result['alt_text'] or is_array()
		// read a success as a failure and reported nothing generated.
		return $data;
	}
}
