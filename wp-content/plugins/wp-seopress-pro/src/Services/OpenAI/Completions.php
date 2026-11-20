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
	 * How many times the current request has already been retried on a refusal.
	 *
	 * An instance flag rather than a parameter: the four generation methods
	 * have six arguments each already, and nothing outside this class has any
	 * business setting it.
	 *
	 * @since 10.4.0
	 *
	 * @var int
	 */
	private $refusal_retry_depth = 0;

	/**
	 * The size cap the last image fetch ran into, in bytes, or 0.
	 *
	 * fetchImageAsBase64() answers false for every failure, and the alt text
	 * generator used to report all of them as "Could not fetch the image".
	 * An image that is simply too large, with no smaller generated size to
	 * fall back to, deserves its own message.
	 *
	 * @since 10.4.0
	 *
	 * @var int
	 */
	protected $oversized_image_limit = 0;

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
				return 'claude-sonnet-5';
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
				return array( 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-haiku-4-5' );
			default:
				return array();
		}
	}

	/**
	 * The order a provider's models are fallen back to.
	 *
	 * Deliberately not `getSupportedModels()`. That list is ordered for the
	 * settings dropdown, which puts the default first, and following it meant a
	 * site that had picked Terra and lost access to it was moved to Sol at
	 * 30$/MTok of output instead of Luna at 1.20$: two and a half times the
	 * bill of the model the user chose, decided for them, on a feature that
	 * runs over every post on the site.
	 *
	 * So the fallback tries the cheaper tiers first: Luna before Terra before
	 * Sol, lite before flash before pro, small before medium before large,
	 * Haiku before Sonnet. A dearer model is only reached when every cheaper
	 * one has been refused too, and the swap is then written to the AI log,
	 * so it is never silent.
	 *
	 * @since 10.4.0
	 *
	 * @param string $provider The AI provider.
	 *
	 * @return string[]
	 */
	private function getFallbackModels( $provider ) {
		$provider = strtolower( $provider );

		switch ( $provider ) {
			case 'openai':
				$order = array( 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol' );
				break;
			case 'deepseek':
				$order = array( 'deepseek-v4-flash', 'deepseek-v4-pro' );
				break;
			case 'gemini':
				$order = array(
					'gemini-2.5-flash-lite',
					'gemini-2.5-flash',
					'gemini-3-flash-preview',
					'gemini-2.5-pro',
					'gemini-3.1-pro-preview',
				);
				break;
			case 'mistral':
				$order = array(
					'mistral-small-latest',
					'magistral-small-latest',
					'mistral-medium-latest',
					'magistral-medium-latest',
					'mistral-large-latest',
				);
				break;
			case 'claude':
				$order = array( 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-sonnet-5' );
				break;
			default:
				$order = array();
				break;
		}

		$supported = $this->getSupportedModels( $provider );

		// The supported list is the authority on what exists: anything named
		// here that is not in it is dropped, and anything it has that is not
		// named here goes last rather than becoming unreachable.
		$ordered = array_values( array_intersect( $order, $supported ) );

		/**
		 * Filter the order a provider's models are fallen back to.
		 *
		 * @since 10.4.0
		 *
		 * @param string[] $ordered  Model names, the one tried first in front.
		 * @param string   $provider The AI provider.
		 */
		$order = apply_filters(
			'seopress_ai_model_fallback_order',
			array_values( array_unique( array_merge( $ordered, $supported ) ) ),
			$provider
		);

		// array_diff() throws on PHP 8 when a filter answers null.
		return is_array( $order ) ? array_values( array_map( 'strval', $order ) ) : array();
	}

	/**
	 * Models the provider has refused for this account, by provider.
	 *
	 * Kept in a transient rather than an option: a permission granted on the
	 * provider's side must take effect on its own, without the user having to
	 * find a button here.
	 *
	 * @since 10.4.0
	 *
	 * @param string $provider The AI provider.
	 *
	 * @return array Model names this account cannot call.
	 */
	public function getRefusedModels( $provider ) {
		$refused = get_transient( $this->refusedModelsKey( $provider ) );

		return is_array( $refused ) ? $refused : array();
	}

	/**
	 * Where the refusals for a provider are stored.
	 *
	 * Scoped to the key in use, through a short digest of it, so entering a new
	 * key starts from a clean slate on its own. The key itself never appears:
	 * only eight hex characters derived from it.
	 *
	 * @since 10.4.0
	 *
	 * @param string $provider The AI provider.
	 *
	 * @return string
	 */
	private function refusedModelsKey( $provider ) {
		$provider = strtolower( $provider );
		$api_key  = (string) $this->getProviderApiKey( $provider );

		return 'seopress_ai_refused_models_' . $provider . '_' . substr( md5( $api_key ), 0, 8 );
	}

	/**
	 * Record that the provider refused a model for this account.
	 *
	 * @since 10.4.0
	 *
	 * @param string $provider The AI provider.
	 * @param string $model    The model the provider would not serve.
	 *
	 * @return void
	 */
	public function rememberRefusedModel( $provider, $model ) {
		$provider = strtolower( $provider );
		$key      = $this->refusedModelsKey( $provider );

		// Read again here rather than reusing a value fetched earlier in the
		// request: a bulk generation refused on two items at once would
		// otherwise write back a list missing the other one. A transient has no
		// compare and set, so this narrows the window rather than closing it;
		// the cost of losing an entry is one extra refused request, which
		// records it again.
		$refused = $this->getRefusedModels( $provider );

		if ( in_array( $model, $refused, true ) ) {
			return;
		}

		$refused[] = $model;

		set_transient( $key, $refused, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Forget the refusals for a provider.
	 *
	 * Called from ClearRefusedAiModels when the provider, the model or the key
	 * changes: the user has just told us the situation is different, and making
	 * them wait out a six hour transient to be believed is the trap this whole
	 * feature was meant to avoid.
	 *
	 * @since 10.4.0
	 *
	 * @param string $provider The AI provider.
	 *
	 * @return void
	 */
	public function clearRefusedModels( $provider ) {
		delete_transient( $this->refusedModelsKey( $provider ) );
	}

	/**
	 * The model a "no access" error is about.
	 *
	 * The model actually sent is the answer whenever the caller knows it. The
	 * message is only parsed as a fallback, and then against our own list
	 * rather than loosely: whatever the provider phrases, only a model we could
	 * have asked for is worth recording.
	 *
	 * @since 10.4.0
	 *
	 * @param string      $provider The AI provider.
	 * @param string      $haystack The provider's error body or message.
	 * @param string|null $model    The model the request was sent with.
	 *
	 * @return string|null
	 */
	private function refusedModelFromMessage( $provider, $haystack, $model = null, $explicit = false ) {
		$supported = $this->getSupportedModels( $provider );

		// Only a marker that is about a model by definition lets the model
		// that was sent stand in for one the body does not name. The generic
		// ones are not: Gemini answers 403 PERMISSION_DENIED for an API that
		// is not enabled, a key reported as leaked or a blocked referrer, and
		// Claude 403 permission_error for workspace restrictions. Taking the
		// sent model for those marked every model refused, one request at a
		// time, and replaced Google's own explanation with ours.
		if ( $explicit && null !== $model && '' !== $model && in_array( $model, $supported, true ) ) {
			return $model;
		}

		$longest = null;

		foreach ( $supported as $candidate ) {
			if ( false === strpos( $haystack, $candidate ) ) {
				continue;
			}

			// "gpt-5.6-sol" contains no other name, but a future list could
			// nest one inside another: the longest match is the real one.
			if ( null === $longest || strlen( $candidate ) > strlen( $longest ) ) {
				$longest = $candidate;
			}
		}

		return $longest;
	}

	/**
	 * Whether the provider is saying this account may not call that model.
	 *
	 * Written against the shape of the answer rather than against each
	 * provider's wording, because the wording is the part that changes and
	 * there are five of them. A 403 or 404 that names a model we could have
	 * asked for, and carries a refusal marker in any of the fields the
	 * providers use for one, is a refusal:
	 *
	 * - OpenAI: `error.code` is `model_not_found`, or `error.message` says the
	 *   project does not have access to the model;
	 * - Claude: `error.type` is `not_found_error`;
	 * - Gemini: `error.status` is `NOT_FOUND` or `PERMISSION_DENIED`;
	 * - Mistral: a top level `message` with no nested error object.
	 *
	 * Requiring the model name is what keeps this from firing on an unrelated
	 * 404, such as a wrong endpoint: that body does not name a model.
	 *
	 * @since 10.4.0
	 *
	 * @param int   $response_code The HTTP status.
	 * @param array $error_data    The decoded error body.
	 *
	 * @return bool
	 */
	private function isModelNotAllowed( $response_code, $error_data ) {
		if ( ! in_array( (int) $response_code, array( 403, 404 ), true ) ) {
			return false;
		}

		$markers = array(
			isset( $error_data['error']['code'] ) && is_scalar( $error_data['error']['code'] ) ? (string) $error_data['error']['code'] : '',
			isset( $error_data['error']['type'] ) && is_scalar( $error_data['error']['type'] ) ? (string) $error_data['error']['type'] : '',
			isset( $error_data['error']['status'] ) && is_scalar( $error_data['error']['status'] ) ? (string) $error_data['error']['status'] : '',
			isset( $error_data['type'] ) && is_scalar( $error_data['type'] ) ? (string) $error_data['type'] : '',
		);

		$refusals = array( 'model_not_found', 'not_found_error', 'not_found', 'permission_denied', 'invalid_model', 'permission_error' );

		foreach ( $markers as $marker ) {
			if ( '' !== $marker && in_array( strtolower( $marker ), $refusals, true ) ) {
				return true;
			}
		}

		$message = $this->errorMessageFrom( $error_data );

		if ( '' === $message ) {
			return false;
		}

		// A 403 or 404 whose message is about a model, however it is phrased.
		foreach ( array( 'does not have access to model', 'not found for api version', 'is not found', 'no access to model', 'invalid model' ) as $phrase ) {
			if ( false !== stripos( $message, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the refusal marker is about a model whatever the body says.
	 *
	 * OpenAI's `model_not_found` and Mistral's `invalid_model` can only mean
	 * the model. Everything else isModelNotAllowed() accepts also covers
	 * refusals that have nothing to do with it, so for those the body has to
	 * name the model before it is recorded.
	 *
	 * @since 10.4.0
	 *
	 * @param array $error_data The decoded error body.
	 *
	 * @return bool
	 */
	private function isExplicitModelRefusal( $error_data ) {
		foreach ( array( array( 'error', 'code' ), array( 'error', 'type' ), array( 'type' ) ) as $path ) {
			$value = $error_data;

			foreach ( $path as $segment ) {
				$value = is_array( $value ) && isset( $value[ $segment ] ) ? $value[ $segment ] : null;
			}

			if ( is_scalar( $value ) && in_array( strtolower( (string) $value ), array( 'model_not_found', 'invalid_model' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The provider's own sentence, wherever it put it.
	 *
	 * @since 10.4.0
	 *
	 * @param array $error_data The decoded error body.
	 *
	 * @return string
	 */
	private function errorMessageFrom( $error_data ) {
		foreach ( array( array( 'error', 'message' ), array( 'message' ), array( 'error' ) ) as $path ) {
			$value = $error_data;

			foreach ( $path as $segment ) {
				if ( ! is_array( $value ) || ! isset( $value[ $segment ] ) ) {
					$value = null;
					break;
				}

				$value = $value[ $segment ];
			}

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Record a refused model and say what has to change, or answer null.
	 *
	 * Every generation path calls this, which is the point: a site that only
	 * uses automatic alt text on upload never reaches the title and description
	 * path, and with the recording living there it would have stayed stuck on a
	 * model it cannot call until someone read the logs.
	 *
	 * @since 10.4.0
	 *
	 * @param string      $provider      The AI provider.
	 * @param string|null $model         The model the request was sent with.
	 * @param int         $response_code The HTTP status.
	 * @param string      $response_body The raw body.
	 *
	 * @return string|null The sentence to show, or null when this is not a refusal.
	 */
	public function recordModelRefusal( $provider, $model, $response_code, $response_body ) {
		$provider   = '' !== (string) $provider ? strtolower( (string) $provider ) : $this->getCurrentProvider();
		$error_data = json_decode( (string) $response_body, true );

		if ( ! is_array( $error_data ) || ! $this->isModelNotAllowed( $response_code, $error_data ) ) {
			return null;
		}

		$haystack = (string) $response_body;
		$refused  = $this->refusedModelFromMessage( $provider, $haystack, $model, $this->isExplicitModelRefusal( $error_data ) );

		if ( null === $refused ) {
			return null;
		}

		$this->rememberRefusedModel( $provider, $refused );

		$remaining = array_values(
			array_diff( $this->getFallbackModels( $provider ), $this->getRefusedModels( $provider ) )
		);

		// The retry was itself refused. Nothing was generated, and the user
		// chose neither of the models this request tried, so say what was
		// refused and that the next attempt uses another one.
		if ( ! empty( $remaining ) && 0 !== $this->refusal_retry_depth ) {
			return sprintf(
				/* translators: 1: comma separated list of refused models, 2: model the next request uses. */
				__( 'Your account cannot use these models: %1$s. Nothing was generated. Try again: the next request uses %2$s. Allow the model you want for your project in your provider dashboard, then save the AI settings again to go back to it. This is a model permission, not a billing limit.', 'wp-seopress-pro' ),
				implode( ', ', $this->getRefusedModels( $provider ) ),
				$remaining[0]
			);
		}

		if ( ! empty( $remaining ) ) {
			return sprintf(
				/* translators: 1: model that was refused, 2: model used instead. */
				__( 'Your account cannot use the %1$s model, so %2$s is being used instead. Allow %1$s for your project in your provider dashboard, then save the AI settings again to go back to it. This is a model permission, not a billing limit.', 'wp-seopress-pro' ),
				$refused,
				$remaining[0]
			);
		}

		return __( 'Your account cannot use any of the available models. Allow at least one of them for your project in your provider dashboard, then save the AI settings again. This is a model permission, not a billing limit.', 'wp-seopress-pro' );
	}

	/**
	 * Send the request again, once, now that the refused model is out.
	 *
	 * A retry that succeeds used to be silent: the result came back, the
	 * success cleared the AI log, and the site had moved from Terra to Luna,
	 * or from Sonnet to Haiku, without anyone being told. The refusal is put
	 * back in the log the AI settings tab shows, so the swap is visible where
	 * the provider settings are.
	 *
	 * @since 10.4.0
	 *
	 * @param callable $retry    Re-entry into the generation method.
	 * @param string   $provider The provider that refused.
	 * @param int      $code     The status of the refused response.
	 * @param string   $refusal  What recordModelRefusal() said about it.
	 *
	 * @return mixed Whatever the generation method answers.
	 */
	private function retryAfterRefusal( $retry, $provider = '', $code = 0, $refusal = '' ) {
		++$this->refusal_retry_depth;

		try {
			$result = $retry();
		} finally {
			$this->refusal_retry_depth = 0;
		}

		if ( is_array( $result ) && ! empty( $result['success'] ) && '' !== (string) $refusal ) {
			$this->logApiError(
				array(
					'provider'      => $provider,
					'response_code' => $code,
					'response_body' => (string) $refusal,
					'request_body'  => '',
					'timestamp'     => current_time( 'mysql' ),
				)
			);
		}

		return $result;
	}

	/**
	 * Whether a refused request is worth sending once more.
	 *
	 * The refusal has just been recorded, so getAIModel() will answer a
	 * different model: one retry turns a failed user action into a successful
	 * one instead of asking them to click again. Only one, and only when
	 * another model is actually available.
	 *
	 * @since 10.4.0
	 *
	 * @param string      $provider The AI provider.
	 * @param string|null $model    The model that was refused.
	 *
	 * @return bool
	 */
	private function shouldRetryAfterRefusal( $provider, $model ) {
		if ( 0 !== $this->refusal_retry_depth ) {
			return false;
		}

		$provider = '' !== (string) $provider ? strtolower( (string) $provider ) : $this->getCurrentProvider();

		$usable = array_diff( $this->getFallbackModels( $provider ), $this->getRefusedModels( $provider ) );

		if ( empty( $usable ) ) {
			return false;
		}

		// The model is only replaceable if the one that failed is out of the
		// running, which rememberRefusedModel() has just made true.
		return null === $model || ! in_array( $model, $usable, true );
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
	 * @param int         $response_code HTTP response code.
	 * @param string      $response_body Raw response body.
	 * @param string|null $provider      The provider that answered, when known.
	 * @param string|null $model         The model the request was sent with.
	 *
	 * Public because the connection test needs the same treatment: it used to
	 * decode `error.message` itself and print nothing for the HTML, empty and
	 * plain-text bodies this already handles. See Usage::describeApiFailure().
	 *
	 * @return string Short, plain-text details for the error notice.
	 */
	public function formatApiErrorDetails( $response_code, $response_body, $provider = null, $model = null ) {
		// A model this account may not call is not a billing problem, which is
		// where the raw provider wording sends people. Record it so the next
		// request picks another one, and say what actually has to change.
		$refusal = $this->recordModelRefusal( (string) $provider, $model, $response_code, $response_body );

		if ( null !== $refusal ) {
			return $refusal;
		}

		// The provider's own error message, when the body is its JSON.
		$error_data = json_decode( (string) $response_body, true );

		if ( isset( $error_data['error']['message'] ) && is_string( $error_data['error']['message'] ) && '' !== $error_data['error']['message'] ) {
			return $error_data['error']['message'];
		}

		// Mistral puts it at the top level instead of under `error`.
		if ( isset( $error_data['message'] ) && is_string( $error_data['message'] ) && '' !== $error_data['message'] ) {
			return $error_data['message'];
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
	 * The image mime types a provider can actually read.
	 *
	 * OpenAI, Claude and Mistral read JPEG, PNG, GIF and WebP only. Gemini reads
	 * HEIC and HEIF on top of those.
	 *
	 * WordPress 7.1 stopped refusing HEIC and HEIF uploads the server cannot
	 * process, and skips the image editor support check entirely when the
	 * browser handles the processing, so an attachment whose type no provider
	 * reads now reaches the media library. The automatic generation hooked on
	 * `add_attachment` runs on it, and without this list the file is base64
	 * encoded and sent, which costs a request and comes back as an opaque
	 * provider error.
	 *
	 * @since 10.3.0
	 *
	 * @param string $provider The AI provider (openai, gemini, claude, etc.).
	 *
	 * @return array The mime types that may be sent for analysis.
	 */
	public function getSupportedImageMimeTypes( $provider = 'openai' ) {
		$mime_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		if ( $this->isGeminiProvider( $provider ) ) {
			$mime_types[] = 'image/heic';
			$mime_types[] = 'image/heif';
		}

		/**
		 * Filters the image mime types sent to the AI provider for analysis.
		 *
		 * @since 10.3.0
		 *
		 * @param array  $mime_types The mime types that may be sent.
		 * @param string $provider   The AI provider the request is going to.
		 */
		$mime_types = apply_filters( 'seopress_ai_supported_image_mime_types', $mime_types, $provider );

		return is_array( $mime_types ) ? $mime_types : array();
	}

	/**
	 * Whether an image of this type may be sent to the provider.
	 *
	 * The one check every path that sends an image goes through: the alt
	 * text generation and the AI Assistant chat.
	 *
	 * @since 10.4.0
	 *
	 * @param string $mime_type The image mime type.
	 * @param string $provider  The AI provider the image would go to.
	 *
	 * @return bool
	 */
	public function is_supported_image_mime_type( $mime_type, $provider = 'openai' ) {
		return in_array( (string) $mime_type, $this->getSupportedImageMimeTypes( $provider ), true );
	}

	/**
	 * The image mime type a URL names through its file extension.
	 *
	 * For a provider that is handed the URL and fetches it itself, the bytes
	 * are never read here, so the extension is all there is to go on.
	 *
	 * @since 10.4.0
	 *
	 * @param string $url The image URL.
	 *
	 * @return string The mime type, or '' when the URL names no image type.
	 */
	public function get_image_url_mime_type( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		$type = wp_check_filetype( wp_basename( (string) $path ), wp_get_mime_types() );

		return ( ! empty( $type['type'] ) && 0 === strpos( $type['type'], 'image/' ) ) ? $type['type'] : '';
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

		// Sonnet 5 rejects custom sampling and enables thinking by default.
		// These short metadata requests reserve their token budget for the answer.
		if ( 'claude-sonnet-5' === $body['model'] ) {
			$claude_body['thinking'] = array( 'type' => 'disabled' );
		} elseif ( isset( $body['temperature'] ) ) {
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
	 * Image mime types the multimodal providers accept.
	 *
	 * @since 10.4.0
	 *
	 * @return string[]
	 */
	protected function allowed_image_mime_types() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
	}

	/**
	 * Upload subfolders whose files the web server refuses to serve.
	 *
	 * WooCommerce and Easy Digital Downloads keep paid downloads there and deny
	 * direct access. Reading one from disk on behalf of a URL in the request
	 * would bypass exactly that deny, whatever the parent product's status.
	 *
	 * @since 10.4.0
	 *
	 * @return string[] Paths relative to the uploads directory.
	 */
	protected function protected_upload_folders() {
		/**
		 * Filter the upload subfolders an image named by URL is never read from.
		 *
		 * @since 10.4.0
		 *
		 * @param string[] $folders Relative to the uploads directory.
		 */
		$folders = apply_filters( 'seopress_ai_image_protected_folders', array( 'woocommerce_uploads', 'edd' ) );

		return is_array( $folders ) ? array_filter( array_map( 'strval', $folders ) ) : array();
	}

	/**
	 * Decide what the disk says about an attachment, once and for all.
	 *
	 * Resolving an id from a URL turned this into a file reader for anything
	 * in the media library: a protected PDF under woocommerce_uploads or edd,
	 * which the web server denies over HTTP, came back through it, and a large
	 * video was read whole into memory and base64 encoded. The caller is
	 * trusted to edit posts, not to read every file on the site.
	 *
	 * The answer is final whenever the file is on this server. A refusal used
	 * to fall through to an HTTP request for the same URL, which served a
	 * private post's image anyway, since uploads are public files; and an
	 * image over the size cap fell through to a loopback request that was then
	 * truncated, refused, and reported as "Could not fetch the image".
	 *
	 * The permission checks only apply when the id was resolved from a URL
	 * that came in with the request. An explicit id is a decision the caller
	 * already made: WP-CLI, a cron hook and the alt text ability all run with
	 * no current user or with their own capability check, and asking
	 * current_user_can() there would answer no for the wrong reason.
	 *
	 * `read_post`, not `edit_post`: this reads an image to send it to a
	 * provider, it changes nothing. The chat asks `edit_post` of the post it
	 * works on because it may write to it (wp-seopress-pro#1881).
	 *
	 * @since 10.4.0
	 *
	 * @param int  $attachment_id     The attachment.
	 * @param int  $max_bytes         Largest file accepted.
	 * @param bool $resolved_from_url Whether the id came from an untrusted URL.
	 *
	 * @return array|false|null The payload, false for a final refusal, or null
	 *                          when there is no local file (offloaded media),
	 *                          in which case the URL may be fetched.
	 */
	protected function local_attachment_image( $attachment_id, $max_bytes, $resolved_from_url = false ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id < 1 ) {
			return null;
		}

		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! is_readable( $path ) ) {
			return null;
		}

		// An image, and not an SVG: providers do not read it, and it is markup
		// rather than pixels.
		if ( ! wp_attachment_is_image( $attachment_id ) || 'image/svg+xml' === get_post_mime_type( $attachment_id ) ) {
			return false;
		}

		if ( $resolved_from_url ) {
			// The caller has to be allowed to see what it named. A private or
			// protected attachment is not theirs to send anywhere.
			if ( ! current_user_can( 'read_post', $attachment_id ) ) {
				return false;
			}

			$uploads  = wp_get_upload_dir();
			$base     = trailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
			$relative = ltrim( substr( wp_normalize_path( $path ), strlen( $base ) ), '/' );

			foreach ( $this->protected_upload_folders() as $folder ) {
				if ( 0 === strpos( $relative, trailingslashit( trim( wp_normalize_path( $folder ), '/' ) ) ) ) {
					return false;
				}
			}
		}

		$file = $this->largest_file_under( $attachment_id, $path, $max_bytes );

		if ( null === $file ) {
			$this->oversized_image_limit = $max_bytes;

			return false;
		}

		return $this->image_from_file( $attachment_id, $file );
	}

	/**
	 * The attachment a generated size URL belongs to.
	 *
	 * attachment_url_to_postid() only matches the stored `_wp_attached_file`,
	 * so `photo-1024x768.jpg` resolved to nothing and went out over HTTP,
	 * past the `read_post` and protected folder checks that only apply to a
	 * resolved attachment. The size suffix is stripped to find the parent,
	 * then the file has to be one of that attachment's own sizes, so a file
	 * that only looks like a size of something else is not mistaken for it.
	 *
	 * @since 10.4.0
	 *
	 * @param string $url An uploads URL that matched no attachment.
	 *
	 * @return int The attachment ID, or 0.
	 */
	protected function attachment_id_from_size_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$file = wp_basename( $path );

		if ( ! preg_match( '/^(.+)-\d+x\d+(\.[a-z0-9]+)$/i', $file, $matches ) ) {
			return 0;
		}

		$base_url   = (string) preg_replace( '/[?#].*$/', '', $url );
		$directory  = substr( $base_url, 0, strlen( $base_url ) - strlen( $file ) );
		$candidates = array( $matches[1] . $matches[2], $matches[1] . '-scaled' . $matches[2] );

		foreach ( $candidates as $candidate ) {
			$attachment_id = attachment_url_to_postid( $directory . $candidate );

			if ( ! $attachment_id ) {
				continue;
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );
			$sizes    = is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();

			if ( in_array( $file, wp_list_pluck( $sizes, 'file' ), true ) ) {
				return (int) $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * The original file, or the largest generated size that fits the cap.
	 *
	 * Sites that switch off `big_image_size_threshold`, which photographers
	 * commonly do, and large PNG or GIF files under 2560px, have originals
	 * over 5 MB. A provider describes a 2048px copy just as well, and every
	 * caller that names its attachment used to get one from the old loopback
	 * fetch of the displayed size, so falling back to a generated size keeps
	 * that working.
	 *
	 * @since 10.4.0
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $path          Its file.
	 * @param int    $max_bytes     Largest file accepted.
	 *
	 * @return string|null A readable path, or null when nothing fits.
	 */
	protected function largest_file_under( $attachment_id, $path, $max_bytes ) {
		$size = filesize( $path );

		if ( false !== $size && $size > 0 && $size <= $max_bytes ) {
			return $path;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return null;
		}

		$best      = null;
		$best_area = 0;
		$directory = trailingslashit( dirname( $path ) );

		foreach ( $metadata['sizes'] as $intermediate ) {
			if ( empty( $intermediate['file'] ) ) {
				continue;
			}

			$candidate = $directory . wp_basename( $intermediate['file'] );

			if ( ! is_readable( $candidate ) ) {
				continue;
			}

			$bytes = filesize( $candidate );
			$area  = (int) ( isset( $intermediate['width'] ) ? $intermediate['width'] : 0 ) * (int) ( isset( $intermediate['height'] ) ? $intermediate['height'] : 0 );

			if ( false !== $bytes && $bytes > 0 && $bytes <= $max_bytes && $area > $best_area ) {
				$best      = $candidate;
				$best_area = $area;
			}
		}

		return $best;
	}

	/**
	 * Whether a URL resolves somewhere the server is allowed to fetch from.
	 *
	 * wp_safe_remote_get() refuses loopback, 10/8, 172.16/12 and 192.168/16.
	 * Before WordPress 7.0 it does not refuse 169.254.0.0/16, which is where
	 * the cloud metadata services live (169.254.169.254 on AWS, Azure and GCP
	 * alike), nor 100.64.0.0/10, which some hosts use for internal services,
	 * and PRO supports 6.5 and up. So they are checked here, on every hop:
	 * fetchImageAsBase64() follows redirects itself so that a public URL
	 * cannot hand the second request to one of them.
	 *
	 * Both address families are checked. Reading only the A records left a
	 * host whose AAAA record points at a private IPv6 address unchecked.
	 *
	 * A host a site has already declared internal-but-allowed through core's
	 * `http_request_host_is_external` filter (an in-house media server, say)
	 * stays allowed, the way it is for every other remote request.
	 *
	 * @since 10.4.0
	 *
	 * @param string $url The URL about to be fetched.
	 *
	 * @return bool
	 */
	protected function remote_host_is_allowed( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( trim( $host, '.[]' ) );

		// The site's own host stays reachable. A local install, a staging box
		// behind a VPN and a container all resolve to an address the checks
		// below reject, and refusing the site its own media would be a
		// regression rather than a fix.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( is_string( $home_host ) && strtolower( trim( $home_host, '.' ) ) === $host ) {
			return true;
		}

		if ( apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$addresses = array( $host );
		} else {
			$addresses = $this->resolve_host( $host );

			if ( array() === $addresses ) {
				return false;
			}
		}

		foreach ( $addresses as $address ) {
			if ( ! $this->address_is_public( $address ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether one IPv4 or IPv6 address may be fetched from.
	 *
	 * filter_var()'s private and reserved flags are not enough on IPv6.
	 * Before PHP 8.3 they accept IPv4-mapped addresses, and
	 * `::ffff:169.254.169.254` reaches the metadata service through the IPv4
	 * stack. On every version they accept NAT64, 6to4 and IPv4-compatible
	 * addresses wrapping a private IPv4, as well as site-local and multicast.
	 *
	 * So the address is normalised first. An IPv4-mapped (`::ffff:0:0/96`) or
	 * IPv4-compatible (`::/96`) address is checked as the IPv4 address it
	 * carries. Any other IPv6 address has to be global unicast, `2000::/3`,
	 * minus 6to4 (`2002::/16`) and Teredo (`2001::/32`), which embed an IPv4
	 * address of their own. That also rules out NAT64 (`64:ff9b::/96`),
	 * unique local, link-local, site-local and multicast.
	 *
	 * @since 10.4.0
	 *
	 * @param string $address An IP address.
	 *
	 * @return bool
	 */
	protected function address_is_public( $address ) {
		$address = (string) $address;

		if ( filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $address );

			if ( false === $packed || 16 !== strlen( $packed ) ) {
				return false;
			}

			$prefix = substr( $packed, 0, 12 );

			if ( str_repeat( "\0", 10 ) . "\xff\xff" === $prefix || str_repeat( "\0", 12 ) === $prefix ) {
				return $this->address_is_public( inet_ntop( substr( $packed, 12 ) ) );
			}

			$first = ord( $packed[0] );

			if ( 0x20 !== ( $first & 0xe0 ) ) {
				return false;
			}

			if ( "\x20\x02" === substr( $packed, 0, 2 ) || "\x20\x01\0\0" === substr( $packed, 0, 4 ) ) {
				return false;
			}

			return (bool) filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		// 100.64.0.0/10, the carrier grade NAT range, is neither private nor
		// reserved as far as filter_var() is concerned.
		$octets = explode( '.', $address );

		return ! ( '100' === $octets[0] && 64 <= (int) $octets[1] && 127 >= (int) $octets[1] );
	}

	/**
	 * Every address a host resolves to, IPv4 and IPv6.
	 *
	 * @since 10.4.0
	 *
	 * @param string $host The host name.
	 *
	 * @return string[]
	 */
	protected function resolve_host( $host ) {
		/**
		 * Short-circuit the resolution of a remote image host.
		 *
		 * Return an array of IPv4 and IPv6 addresses to use them instead of
		 * asking DNS, for a split-horizon setup or for tests. Every address
		 * returned is still checked.
		 *
		 * @since 10.4.0
		 *
		 * @param string[]|null $addresses Null to resolve normally.
		 * @param string        $host      The host name.
		 */
		$addresses = apply_filters( 'seopress_ai_image_host_addresses', null, $host );

		if ( is_array( $addresses ) ) {
			return array_values( array_filter( array_map( 'strval', $addresses ) ) );
		}

		$addresses = gethostbynamel( $host );
		$addresses = is_array( $addresses ) ? $addresses : array();

		if ( function_exists( 'dns_get_record' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a host with no AAAA record is a warning on some resolvers, not an error.
			$records = @dns_get_record( $host, DNS_AAAA );

			foreach ( is_array( $records ) ? $records : array() as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$addresses[] = (string) $record['ipv6'];
				}
			}
		}

		return $addresses;
	}

	/**
	 * The mime type of an image, read from its own bytes.
	 *
	 * Content-Type is the host's word for what it sent, and it is wrong in
	 * both directions: an HTML error page served as image/png was base64
	 * encoded and handed to the provider, while a real JPEG served as
	 * application/octet-stream by an offloading plugin was refused.
	 * getimagesizefromstring() reads the magic bytes instead, so what the
	 * header claims stops mattering either way.
	 *
	 * @since 10.4.0
	 *
	 * @param string $bytes The image data.
	 *
	 * @return string|false The mime type, or false when the bytes are not a supported image.
	 */
	protected function sniff_image_mime_type( $bytes ) {
		if ( ! is_string( $bytes ) || '' === $bytes || ! function_exists( 'getimagesizefromstring' ) ) {
			return false;
		}

		// getimagesize() does not read HEIC or HEIF, so a real HEIC file came
		// back as "not an image" and never reached Gemini, which reads it.
		// Whether the provider in use reads the type is decided later, by
		// getSupportedImageMimeTypes().
		$heif_type = $this->heif_mime_type( $bytes );

		if ( $heif_type ) {
			return $heif_type;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a body that is not an image is a false return, not a warning to show anyone.
		$size = @getimagesizefromstring( $bytes );

		if ( ! is_array( $size ) || empty( $size['mime'] ) ) {
			// getimagesize() reads AVIF only from PHP 8.1, and PRO runs on 7.4.
			// Before that a real AVIF came back as "not an image", was dropped
			// from the request, and the provider wrote alt text for a picture
			// it never saw.
			return $this->is_avif( $bytes ) ? 'image/avif' : false;
		}

		$mime_type = strtolower( $size['mime'] );

		return in_array( $mime_type, $this->allowed_image_mime_types(), true ) ? $mime_type : false;
	}

	/**
	 * Whether bytes are an AVIF image, read from the ISO BMFF brand.
	 *
	 * @since 10.4.0
	 *
	 * @param string $bytes The image data.
	 *
	 * @return bool
	 */
	protected function is_avif( $bytes ) {
		return is_string( $bytes )
			&& strlen( $bytes ) >= 12
			&& 'ftyp' === substr( $bytes, 4, 4 )
			&& in_array( substr( $bytes, 8, 4 ), array( 'avif', 'avis' ), true );
	}

	/**
	 * The HEIC or HEIF mime type of bytes, read from the ISO BMFF major brand.
	 *
	 * @since 10.4.0
	 *
	 * @param string $bytes The image data.
	 *
	 * @return string|false image/heic, image/heif, or false for anything else.
	 */
	protected function heif_mime_type( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < 12 || 'ftyp' !== substr( $bytes, 4, 4 ) ) {
			return false;
		}

		$brand = substr( $bytes, 8, 4 );

		if ( in_array( $brand, array( 'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx' ), true ) ) {
			return 'image/heic';
		}

		if ( in_array( $brand, array( 'mif1', 'msf1' ), true ) ) {
			return 'image/heif';
		}

		return false;
	}

	/**
	 * Fetch an image and convert it to base64 for the multimodal APIs.
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

		/**
		 * Filter the largest image the chat will read or download, in bytes.
		 *
		 * @since 10.4.0
		 *
		 * @param int    $bytes Maximum size. Default 5 MB.
		 * @param string $url   The image being fetched.
		 */
		$max_bytes = (int) apply_filters( 'seopress_ai_image_max_bytes', 5 * MB_IN_BYTES, $url );

		$this->oversized_image_limit = 0;

		if ( 1 > $max_bytes ) {
			return false;
		}

		// A data URI carries its own bytes, so there is nothing to fetch.
		if ( 0 === strpos( $url, 'data:' ) ) {
			return $this->image_from_data_uri( $url, $max_bytes );
		}

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
		 * wp_remote_get() answered "A valid URL was not provided." and the
		 * only thing the user saw was "Could not fetch the image".
		 *
		 * The file is on the same server in every one of those cases, so
		 * reading it directly removes the whole class of failure. Offloaded
		 * media (S3 and friends) has no local file, so it falls through to the
		 * HTTP path below.
		 */
		// A root-relative or protocol-relative URL is never a valid argument to
		// wp_remote_get(); it answers "A valid URL was not provided." Sites do
		// produce them, through a CDN plugin or an upload_url_path that was set
		// relative, so resolve rather than fail. Done before the uploads test
		// below, so a relative uploads URL is still read from disk.
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			// A root-relative path is relative to the host, not to home:
			// home_url() appends it to the home path, so a site in /blog got
			// /blog/blog/wp-content/... back.
			$home = wp_parse_url( home_url() );

			if ( ! empty( $home['scheme'] ) && ! empty( $home['host'] ) ) {
				$url = $home['scheme'] . '://' . $home['host'] . ( ! empty( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
			}
		}

		$resolved_from_url = false;

		if ( ! $attachment_id && '' !== $url ) {
			$uploads = wp_get_upload_dir();

			// The id is resolved from the URL only when the URL is under the
			// uploads directory, so an external one costs no query against
			// the unindexed _wp_attached_file meta_value.
			if ( ! empty( $uploads['baseurl'] ) && 0 === strpos( $url, $uploads['baseurl'] ) ) {
				$attachment_id = attachment_url_to_postid( $url );

				if ( ! $attachment_id ) {
					$attachment_id = $this->attachment_id_from_size_url( $url );
				}

				$resolved_from_url = (bool) $attachment_id;
			}
		}

		if ( $attachment_id ) {
			$local = $this->local_attachment_image( $attachment_id, $max_bytes, $resolved_from_url );

			// Anything but "no local file" is the final answer. See
			// local_attachment_image().
			if ( null !== $local ) {
				return $local;
			}
		}

		if ( '' === $url ) {
			return false;
		}

		$image_body = $this->download_image( $url, $max_bytes );

		if ( null === $image_body ) {
			return false;
		}

		// A 200 proves the host answered, not that it answered with an image.
		$mime_type = $this->sniff_image_mime_type( $image_body );

		if ( ! $mime_type ) {
			return false;
		}

		return array(
			'mime_type' => $mime_type,
			'data'      => base64_encode( $image_body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API expects base64.
		);
	}

	/**
	 * Download an image, checking every host on the way.
	 *
	 * wp_safe_remote_get() revalidates redirect hops itself, but only against
	 * what core refuses, and before 7.0 that leaves out the metadata ranges.
	 * A public URL answering `302 Location: http://169.254.169.254/...` passed
	 * the check made on the first URL and had the second hop go out. So
	 * redirects are not followed by the transport: each Location is resolved
	 * against the URL that sent it, checked, and requested on its own.
	 *
	 * The host is still resolved more than once (here, then by the
	 * transport), so a DNS answer that changes between the two is not
	 * covered.
	 *
	 * @since 10.4.0
	 *
	 * @param string $url       Where the image is.
	 * @param int    $max_bytes Largest image accepted.
	 *
	 * @return string|null The body, or null when it was refused or failed.
	 */
	protected function download_image( $url, $max_bytes ) {
		/**
		 * Filter how many redirects an image download may follow.
		 *
		 * @since 10.4.0
		 *
		 * @param int $redirects Default 2.
		 */
		$redirects = max( 0, (int) apply_filters( 'seopress_ai_image_max_redirects', 2 ) );
		$current   = $url;

		for ( $hop = 0; $hop <= $redirects; $hop++ ) {
			if ( ! $this->remote_host_is_allowed( $current ) ) {
				return null;
			}

			// wp_safe_remote_get() rather than wp_remote_get(): the URL comes
			// from the request body, and esc_url_raw() is no defence against a
			// private address. limit_response_size bounds what a host can make
			// the worker hold in memory, raised by one byte so that a
			// truncated body is distinguishable from a file exactly at the
			// limit. It bounds memory, not time: the transfer still runs to
			// the end. See SEC-02.
			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'limit_response_size' => $max_bytes + 1,
				)
			);

			if ( is_wp_error( $response ) ) {
				return null;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_array( $location ) ? end( $location ) : (string) $location;

				if ( '' === $location ) {
					return null;
				}

				$current = \WP_Http::make_absolute_url( $location, $current );

				continue;
			}

			if ( 200 !== $code ) {
				return null;
			}

			$body = wp_remote_retrieve_body( $response );

			// limit_response_size truncates the body rather than failing the
			// request, and half an image is not an image: the provider bills
			// the call and answers about nothing. Treat it as too large.
			if ( ! is_string( $body ) ) {
				return null;
			}

			if ( strlen( $body ) > $max_bytes ) {
				$this->oversized_image_limit = $max_bytes;

				return null;
			}

			return $body;
		}

		// Still redirecting after the last allowed hop.
		return null;
	}

	/**
	 * Decode an inline data URI into a provider payload.
	 *
	 * @since 10.4.0
	 *
	 * @param string $url       The data URI.
	 * @param int    $max_bytes Largest image accepted.
	 *
	 * @return array|false
	 */
	protected function image_from_data_uri( $url, $max_bytes ) {
		if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', $url, $matches ) ) {
			return false;
		}

		// Four base64 characters carry three bytes, so anything longer than
		// this cannot fit under the cap and is not worth decoding.
		if ( strlen( $matches[2] ) > ( (int) ceil( $max_bytes * 4 / 3 ) + 4 ) ) {
			$this->oversized_image_limit = $max_bytes;

			return false;
		}

		$decoded = base64_decode( $matches[2], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an inline image, not obfuscation.

		if ( false === $decoded ) {
			return false;
		}

		if ( strlen( $decoded ) > $max_bytes ) {
			$this->oversized_image_limit = $max_bytes;

			return false;
		}

		$mime_type = $this->sniff_image_mime_type( $decoded );

		if ( ! $mime_type ) {
			return false;
		}

		return array(
			'mime_type' => $mime_type,
			'data'      => base64_encode( $decoded ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API expects base64.
		);
	}

	/**
	 * Read a vetted attachment file from disk into a provider payload.
	 *
	 * @since 10.4.0
	 *
	 * @param int    $attachment_id The attachment local_attachment_image() vetted.
	 * @param string $path          The original or one of its generated sizes.
	 *
	 * @return array|false
	 */
	protected function image_from_file( $attachment_id, $path ) {
		if ( ! $path || ! is_readable( $path ) ) {
			return false;
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, not a remote request.

		if ( false === $contents || '' === $contents ) {
			return false;
		}

		// The bytes decide, not post_mime_type. The stored type is what
		// WordPress recorded at upload, and it stops describing the file in
		// both directions: since 6.7 core can write the main file in another
		// format (image_editor_output_format, JPEG to WebP) without updating
		// it, largest_file_under() may pick a generated size in that other
		// format, and an offloading plugin may have saved
		// application/octet-stream. A media_type that does not match the data
		// is rejected by the provider, so read it from the data.
		$mime_type = $this->sniff_image_mime_type( $contents );

		if ( ! $mime_type ) {
			return false;
		}

		return array(
			'mime_type' => $mime_type,
			'data'      => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API expects base64.
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

		// A model the provider has already refused for this account cannot be
		// rescued by retrying it. Move to the next one we support rather than
		// leaving the feature dead with no way out from our own settings. The
		// order is the fallback order, not the dropdown order: see
		// getFallbackModels().
		$refused = $this->getRefusedModels( $provider );
		if ( ! empty( $refused ) && in_array( $model, $refused, true ) ) {
			$usable = array_values( array_diff( $this->getFallbackModels( $provider ), $refused ) );

			// All refused: keep the stored choice so the error names the model
			// the user actually picked.
			if ( ! empty( $usable ) ) {
				$model = $usable[0];
			}
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
		// The arguments as the caller passed them, before anything below
		// converts them, so a retry after a refused model replays the same
		// call, every parameter included.
		$call_args = func_get_args();

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

				// A model this account may not call has just been recorded, so
				// getAIModel() now answers a different one: send the request
				// once more rather than making the user click again.
				$refusal = $this->recordModelRefusal( $provider, $model_name, $response_code, $response_body );

				if ( null !== $refusal && $this->shouldRetryAfterRefusal( $provider, $model_name ) ) {
					return $this->retryAfterRefusal(
						function () use ( $call_args ) {
							return $this->generateTitlesDesc( ...$call_args );
						},
						$provider,
						$response_code,
						$refusal
					);
				}

				$message = null !== $refusal ? $refusal : sprintf(
					/* translators: 1: provider name, 2: response code, 3: error details */
					__( 'An error occurred with %1$s API. Response code: %2$s. Details: %3$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code,
					$this->formatApiErrorDetails( $response_code, $response_body, $provider, $model_name )
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
				if ( true === $autosave ) {
					$this->saveTitlesDesc( $post_id, array( 'title' => $title ) );
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

				if ( true === $autosave ) {
					$this->saveTitlesDesc( $post_id, array( 'desc' => $description ) );
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
	 * Save an SEO title and/or meta description on a post.
	 *
	 * Shared by the bulk generation that writes as it goes and by the bulk
	 * review, which only writes the suggestions the user approved. An empty
	 * value is never written: it would wipe the meta already in place.
	 *
	 * @since 10.4.0
	 *
	 * @param int   $post_id The post ID.
	 * @param array $values  'title' and/or 'desc'.
	 *
	 * @return void
	 */
	public function saveTitlesDesc( $post_id, $values ) {
		if ( isset( $values['title'] ) && '' !== $values['title'] ) {
			update_post_meta( $post_id, '_seopress_titles_title', sanitize_text_field( html_entity_decode( $values['title'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) );
		}

		if ( isset( $values['desc'] ) && '' !== $values['desc'] ) {
			update_post_meta( $post_id, '_seopress_titles_desc', sanitize_textarea_field( html_entity_decode( $values['desc'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ) );
		}
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
		// The arguments as the caller passed them, before anything below
		// converts them, so a retry after a refused model replays the same
		// call, every parameter included.
		$call_args = func_get_args();

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

				$refusal = $this->recordModelRefusal( $provider, $model_name, $response_code, $response_body );

				if ( null !== $refusal && $this->shouldRetryAfterRefusal( $provider, $model_name ) ) {
					return $this->retryAfterRefusal(
						function () use ( $call_args ) {
							return $this->generateTermTitlesDesc( ...$call_args );
						},
						$provider,
						$response_code,
						$refusal
					);
				}

				$message = null !== $refusal ? $refusal : sprintf(
					/* translators: 1: provider name, 2: response code, 3: error details */
					__( 'An error occurred with %1$s API. Response code: %2$s. Details: %3$s', 'wp-seopress-pro' ),
					$this->getProviderName( $provider ),
					$response_code,
					// The details come from the provider's HTTP response: never
					// trust them as markup.
					esc_html( $this->formatApiErrorDetails( $response_code, $response_body, $provider, $model_name ) )
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
		// The arguments as the caller passed them, before anything below
		// converts them, so a retry after a refused model replays the same
		// call, every parameter included.
		$call_args = func_get_args();

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

				// Nothing recorded the refusal here, so a site that only uses
				// this path stayed on a model it cannot call until someone read
				// the logs. Record it, then send the request once more with the
				// model getAIModel() answers now.
				$refusal = $this->recordModelRefusal( $provider, $model_name, $response_code, $response_body );

				if ( null !== $refusal && $this->shouldRetryAfterRefusal( $provider, $model_name ) ) {
					return $this->retryAfterRefusal(
						function () use ( $call_args ) {
							return $this->generateSocialMetas( ...$call_args );
						},
						$provider,
						$response_code,
						$refusal
					);
				}

				$message = null !== $refusal ? $refusal : sprintf(
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
	 * The result returned for an image whose type the provider cannot read.
	 *
	 * @since 10.4.0
	 *
	 * @param string $mime_type The image mime type.
	 *
	 * @return array
	 */
	private function unsupported_image_type_result( $mime_type ) {
		return array(
			'success'     => false,
			'message'     => sprintf(
				/* translators: %s: the image mime type, for example image/heic. */
				__( '%s images are not supported for AI image analysis. Please use JPG, PNG, GIF, or WebP images.', 'wp-seopress-pro' ),
				'' !== (string) $mime_type ? (string) $mime_type : __( 'Unknown', 'wp-seopress-pro' )
			),
			'alt_text'    => '',
			'caption'     => '',
			'description' => '',
		);
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
	 * @param bool   $persist   Whether the generated values are written to the attachment (default true).
	 *                          False answers the suggestion without touching it, which is what a
	 *                          read-only caller needs.
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
		$fields = null,
		$persist = true
	) {
		// The arguments as the caller passed them, before anything below
		// converts them, so a retry after a refused model replays the same
		// call, every parameter included.
		$call_args = func_get_args();

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

		// Any other type the provider cannot read is refused here rather than
		// paid for: see getSupportedImageMimeTypes().
		if ( ! $this->is_supported_image_mime_type( $mime_type, $provider ) ) {
			return $this->unsupported_image_type_result( $mime_type );
		}

		// Fetch image as base64 for better compatibility (works with localhost, firewalls, etc.).
		// The attachment ID is passed so the file can be read from disk rather
		// than fetched over HTTP: see fetchImageAsBase64().
		$image_url  = is_array( $image_src ) && ! empty( $image_src[0] ) ? $image_src[0] : '';
		$image_data = $this->fetchImageAsBase64( $image_url, $post_id );

		if ( ! $image_data ) {
			$message = __( 'Could not fetch the image. Please check if the image URL is accessible.', 'wp-seopress-pro' );

			if ( $this->oversized_image_limit > 0 ) {
				$message = sprintf(
					/* translators: %s: maximum file size, for example "5 MB" */
					__( 'This image is too large to be analyzed. The limit is %s, and no smaller size of it is available.', 'wp-seopress-pro' ),
					size_format( $this->oversized_image_limit )
				);
			}

			return array(
				'success'     => false,
				'message'     => $message,
				'alt_text'    => '',
				'caption'     => '',
				'description' => '',
			);
		}

		// What is sent is the type read from the bytes, which is not always the
		// one recorded on the attachment, so it is checked as well.
		if ( ! $this->is_supported_image_mime_type( $image_data['mime_type'], $provider ) ) {
			return $this->unsupported_image_type_result( $image_data['mime_type'] );
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

				// Nothing recorded the refusal here, so a site that only uses
				// this path stayed on a model it cannot call until someone read
				// the logs. Record it, then send the request once more with the
				// model getAIModel() answers now.
				$refusal = $this->recordModelRefusal( $provider, $model_name, $response_code, $response_body );

				if ( null !== $refusal && $this->shouldRetryAfterRefusal( $provider, $model_name ) ) {
					return $this->retryAfterRefusal(
						function () use ( $call_args ) {
							return $this->generateImgAltText( ...$call_args );
						},
						$provider,
						$response_code,
						$refusal
					);
				}

				$message = null !== $refusal ? $refusal : sprintf(
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

		// Save the requested fields, only the missing ones when $update_empty_alt_text is false.
		if ( $persist ) {
			$generated_values = array(
				'alt_text'    => $alt_text,
				'caption'     => $caption,
				'description' => $description,
			);

			$current = array(
				'alt_text'    => $current_alt_text,
				'caption'     => $current_caption,
				'description' => $current_description,
			);

			$to_save = array();
			foreach ( $fields as $field ) {
				if ( $update_empty_alt_text || empty( $current[ $field ] ) ) {
					$to_save[ $field ] = $generated_values[ $field ];
				}
			}

			$this->saveImageMeta( $post_id, $to_save );
		}

		// One shape for one method. This used to answer the bare string when
		// $action was 'alt_text', while every early return above answered the
		// array, so the callers checking $result['alt_text'] or is_array()
		// read a success as a failure and reported nothing generated.
		return $data;
	}

	/**
	 * Save image metadata on an attachment.
	 *
	 * Shared by the bulk generation that writes as it goes and by the bulk
	 * review, which only writes the suggestions the user approved. An empty
	 * value is never written: it would wipe the metadata already in place.
	 *
	 * @since 10.4.0
	 *
	 * @param int   $post_id The attachment ID.
	 * @param array $values  'alt_text', 'caption' and/or 'description'.
	 *
	 * @return void
	 */
	public function saveImageMeta( $post_id, $values ) {
		// Alt text goes to post meta.
		if ( ! empty( $values['alt_text'] ) ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', apply_filters( 'seopress_update_alt', sanitize_text_field( html_entity_decode( $values['alt_text'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ) );
		}

		// Caption goes to post_excerpt.
		if ( ! empty( $values['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => apply_filters( 'seopress_update_caption', sanitize_text_field( html_entity_decode( $values['caption'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ),
				)
			);
		}

		// Description goes to post_content.
		if ( ! empty( $values['description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => apply_filters( 'seopress_update_description', wp_kses_post( html_entity_decode( $values['description'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 ) ), $post_id ),
				)
			);
		}
	}
}
