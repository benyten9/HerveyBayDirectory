<?php
/**
 * Class BounceHandlerManager
 * This class is responsible for managing bounce handlers
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Services;

use Exception;

/**
 * BounceHandlerManager class
 */
final class BounceHandlerManager {

	/**
	 * Registered bounce handler class names (lazy loaded)
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $handler_classes = array();

	/**
	 * Instantiated bounce handlers (lazy loaded)
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $handlers = array();

	/**
	 * Class Instance.
	 *
	 * @since 1.0.0
	 *
	 * @var BounceHandlerManager
	 */
	private static $instance;

	/**
	 * Manager Instance.
	 *
	 * Instantiates or reuses an instance of Manager.
	 *
	 * @since  1.0.0
	 *
	 * @return BounceHandlerManager
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'doublescale_ready', array( $this, 'load_handlers' ) );
		add_action( 'init', array( $this, 'handle_early_webhook' ), 1 );
	}

	/**
	 * Handle bounce webhook requests early via query parameters.
	 *
	 * Intercepts requests with ?doublescale_webhook=bounce before permalink
	 * processing. Delegates to existing verify_webhook_security() and
	 * handle_webhook() methods via a WP_REST_Request object.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_early_webhook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public webhook endpoint, secured via Api key
		if ( ! isset( $_GET['doublescale_webhook'] ) || 'bounce' !== $_GET['doublescale_webhook'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public webhook endpoint, secured via Api key
		$provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';

		if ( empty( $provider ) ) {
			status_header( 400 );
			wp_send_json(
				array(
					'success' => false,
					'message' => 'Missing provider parameter.',
				)
			);
		}

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'POST';
		$request = new \WP_REST_Request( $method, '/doublescale/v1/webhooks/bounce/' . $provider );
		$request->set_url_params( array( 'provider' => $provider ) );
		$request->set_query_params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$raw_body = file_get_contents( 'php://input' );
		if ( ! empty( $raw_body ) ) {
			$request->set_body( $raw_body );
			$json_data = json_decode( $raw_body, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $json_data ) ) {
				$request->set_body_params( $json_data );
			}
		}

		// Reuse existing security verification.
		$auth_result = $this->verify_webhook_security( $request );

		if ( is_wp_error( $auth_result ) ) {
			$status = $auth_result->get_error_data()['status'] ?? 403;
			status_header( $status );
			wp_send_json(
				array(
					'success' => false,
					'code'    => $auth_result->get_error_code(),
					'message' => $auth_result->get_error_message(),
				)
			);
		}

		// Reuse existing webhook handler.
		$response = $this->handle_webhook( $request );

		status_header( $response->get_status() );
		wp_send_json( $response->get_data() );
	}

	/**
	 * Build a permalink-independent webhook URL.
	 *
	 * Uses home_url() with query parameters instead of rest_url() so the URL
	 * remains stable regardless of WordPress permalink settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug         Provider slug.
	 * @param string $security_key Security key.
	 *
	 * @return string Webhook URL.
	 */
	public static function build_webhook_url( $slug, $security_key ) {
		return home_url( '/' ) . '?' . implode(
			'&',
			array(
				'doublescale_webhook=bounce',
				'provider=' . rawurlencode( $slug ),
				'key=' . rawurlencode( $security_key ),
			)
		);
	}

	/**
	 * Load bounce handlers
	 *
	 * Discovers and registers handler classes without instantiating them.
	 * Handlers are instantiated lazily when first needed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_handlers() {
		$handlers_dir = DOUBLESCALE_PRO_PLUGIN_DIR . 'includes/Modules/Inbox/BounceHandlers/';

		if ( ! is_dir( $handlers_dir ) ) {
			return;
		}

		// Load all handler files (PascalCase post-rename, ending in BounceHandler.php).
		foreach ( glob( $handlers_dir . '*BounceHandler.php' ) as $file ) {
			require_once $file;

			// Extract class name from file
			$class_name = $this->get_class_name_from_file( $file );

			if ( $class_name && class_exists( $class_name ) ) {
				$this->register( $class_name );
			}
		}

		/**
		 * Fires after all bounce handlers have been loaded and registered.
		 *
		 * @since 1.0.0
		 */
		do_action( 'doublescale_bounce_handlers_loaded' );
	}

	/**
	 * Register a bounce handler class
	 *
	 * Stores the class name for lazy instantiation. The handler is not
	 * instantiated until it's actually needed (when a webhook is received).
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Handler class name.
	 *
	 * @return bool
	 */
	public function register( $class_name ) {
		// Validate input
		if ( empty( $class_name ) || ! is_string( $class_name ) ) {
			doublescale_get_logger()->error(
				'Invalid class name provided for bounce handler registration',
				array(
					'source'     => 'bounce-handler-manager',
					'class_name' => $class_name,
					'type'       => gettype( $class_name ),
				)
			);
			return false;
		}

		// Check if class exists
		if ( ! class_exists( $class_name ) ) {
			doublescale_get_logger()->error(
				'Bounce handler class does not exist: ' . $class_name,
				array(
					'source'     => 'bounce-handler-manager',
					'class_name' => $class_name,
				)
			);
			return false;
		}

		// Validate that class extends the abstract class (without instantiating)
		if ( ! is_subclass_of( $class_name, '\DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler' ) ) {
			doublescale_get_logger()->error(
				'Bounce handler must extend DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler: ' . $class_name,
				array(
					'source'     => 'bounce-handler-manager',
					'class_name' => $class_name,
					'parent'     => get_parent_class( $class_name ),
				)
			);
			return false;
		}

		$slug = $this->get_slug_from_class( $class_name );

		// Check for duplicate registrations
		if ( isset( $this->handler_classes[ $slug ] ) ) {
			doublescale_get_logger()->info(
				'Bounce handler already registered, overwriting: ' . $slug,
				array(
					'source'           => 'bounce-handler-manager',
					'class_name'       => $class_name,
					'slug'             => $slug,
					'existing_class'   => $this->handler_classes[ $slug ],
				)
			);
		}

		// Store class name for lazy loading
		$this->handler_classes[ $slug ] = $class_name;

		return true;
	}

	/**
	 * Get slug from class name
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Class name.
	 *
	 * @return string
	 */
	private function get_slug_from_class( $class_name ) {
		// DoubleScale\Pro\Modules\Inbox\BounceHandlers\SendgridBounceHandler -> sendgrid.
		$parts = explode( '\\', $class_name );
		$class = end( $parts );

		// Strip the BounceHandler suffix (PascalCase) and legacy snake-case variant.
		$class = preg_replace( '/BounceHandler$/', '', $class );
		$slug  = str_replace( array( '_bounce_handler', '-', '_' ), '', strtolower( $class ) );

		return $slug;
	}

	/**
	 * Get class name from file path
	 *
	 * Extracts the fully qualified class name from a bounce handler file path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_path Full path to the bounce handler file.
	 *
	 * @return string|null Fully qualified class name or null if not found.
	 */
	private function get_class_name_from_file( $file_path ) {
		// Extract filename: SendgridBounceHandler.php (PascalCase post-rename).
		$filename = basename( $file_path, '.php' );

		// Build fully qualified class name.
		$full_class_name = '\\DoubleScale\\Pro\\Modules\\Inbox\\BounceHandlers\\' . $filename;

		return $full_class_name;
	}

	/**
	 * Get handler instance (lazy loaded)
	 *
	 * Returns the handler instance for a given slug. If the handler hasn't been
	 * instantiated yet, it will be created on first access.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Handler slug.
	 *
	 * @return \DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler|null Handler instance or null if not found.
	 */
	private function get_handler( $slug ) {
		// Check if handler is already instantiated
		if ( isset( $this->handlers[ $slug ] ) ) {
			return $this->handlers[ $slug ];
		}

		// Check if handler class is registered
		if ( ! isset( $this->handler_classes[ $slug ] ) ) {
			return null;
		}

		// Lazy instantiate the handler
		$class_name = $this->handler_classes[ $slug ];

		try {
			$handler = new $class_name();

			// Cache the instance
			$this->handlers[ $slug ] = $handler;

			doublescale_get_logger()->debug(
				'Bounce handler instantiated: ' . $handler->get_name(),
				array(
					'source'     => 'bounce-handler-manager',
					'slug'       => $slug,
					'class_name' => $class_name,
				)
			);

			return $handler;

		} catch ( \Exception $e ) {
			doublescale_get_logger()->error(
				'Failed to instantiate bounce handler: ' . $e->getMessage(),
				array(
					'source'     => 'bounce-handler-manager',
					'slug'       => $slug,
					'class_name' => $class_name,
					'exception'  => $e->getMessage(),
					'trace'      => $e->getTraceAsString(),
				)
			);

			return null;
		}
	}

	/**
	 * Register REST Api routes
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'doublescale/v1',
			'/webhooks/bounce/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook_security' ),
				'args'                => array(
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Verify webhook security
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return bool|\WP_Error
	 */
	public function verify_webhook_security( $request ) {
		$provider  = $request->get_param( 'provider' );
		$client_ip = $this->get_client_ip();

		// Check rate limiting first
		if ( ! $this->check_rate_limit( $provider, $client_ip ) ) {
			doublescale_get_logger()->info(
				'Bounce webhook rate limit exceeded',
				array(
					'source'   => 'bounce-webhook',
					'provider' => $provider,
					'ip'       => $client_ip,
				)
			);

			return new \WP_Error( 'rate_limit_exceeded', 'Rate limit exceeded', array( 'status' => 429 ) );
		}

		$security_key = get_option( 'doublescale_bounce_security_key' );

		// Security key must exist - it should be generated when webhook URLs are first requested
		if ( ! $security_key ) {
			doublescale_get_logger()->error(
				'Bounce webhook security key not found in database',
				array(
					'source'   => 'bounce-webhook',
					'provider' => $provider,
					'ip'       => $client_ip,
					'note'     => 'Key should be generated in get_webhook_urls()',
				)
			);

			return new \WP_Error( 'missing_security_key', 'Security key not configured', array( 'status' => 500 ) );
		}

		$provided_key = $request->get_param( 'key' );

		// Validate key format
		if ( empty( $provided_key ) || ! is_string( $provided_key ) ) {
			doublescale_get_logger()->info(
				'Invalid security key format in bounce webhook',
				array(
					'source'   => 'bounce-webhook',
					'provider' => $provider,
					'ip'       => $client_ip,
					'key_type' => gettype( $provided_key ),
				)
			);

			return new \WP_Error( 'invalid_key_format', 'Invalid security key format', array( 'status' => 400 ) );
		}

		if ( ! hash_equals( $security_key, (string) $provided_key ) ) {
			// Log failed attempts for security monitoring
			doublescale_get_logger()->info(
				'Invalid security key in bounce webhook',
				array(
					'source'     => 'bounce-webhook',
					'provider'   => $provider,
					'ip'         => $client_ip,
					'key_length' => strlen( $provided_key ),
				)
			);

			do_action(
				'doublescale_bounce_webhook_failed_auth',
				array(
					'provider'   => $provider,
					'ip'         => $client_ip,
					'timestamp'  => time(),
					'key_length' => strlen( $provided_key ),
				)
			);

			return new \WP_Error( 'invalid_key', 'Invalid security key', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle webhook
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$start_time = microtime( true );
		$provider   = $request->get_param( 'provider' );
		$request_id = wp_generate_uuid4();

		// Validate provider
		if ( empty( $provider ) || ! is_string( $provider ) ) {
			doublescale_get_logger()->error(
				'Invalid provider parameter in bounce webhook',
				array(
					'source'     => 'bounce-webhook',
					'provider'   => $provider,
					'request_id' => $request_id,
					'ip'         => $this->get_client_ip(),
				)
			);

			return new \WP_REST_Response(
				array(
					'success'    => false,
					'message'    => 'Invalid provider parameter',
					'request_id' => $request_id,
				),
				400
			);
		}

		// Get handler (lazy loaded)
		$handler = $this->get_handler( $provider );

		if ( ! $handler ) {
			doublescale_get_logger()->info(
				'Unknown provider in bounce webhook: ' . $provider,
				array(
					'source'             => 'bounce-webhook',
					'provider'           => $provider,
					'request_id'         => $request_id,
					'available_handlers' => array_keys( $this->handler_classes ),
					'ip'                 => $this->get_client_ip(),
				)
			);

			return new \WP_REST_Response(
				array(
					'success'    => false,
					'message'    => 'Unknown provider: ' . $provider,
					'request_id' => $request_id,
				),
				404
			);
		}

		try {
			// Get and validate data from request
			$data = $this->extract_webhook_data( $request, $provider );

			if ( empty( $data ) ) {
				doublescale_get_logger()->info(
					'Empty webhook data received from provider: ' . $provider,
					array(
						'source'     => 'bounce-webhook',
						'provider'   => $provider,
						'request_id' => $request_id,
						'ip'         => $this->get_client_ip(),
					)
				);

				return new \WP_REST_Response(
					array(
						'success'    => false,
						'message'    => 'Empty webhook data',
						'request_id' => $request_id,
					),
					400
				);
			}

			// Log incoming webhook
			doublescale_get_logger()->debug(
				sprintf( 'Bounce webhook received from provider: %s', $provider ),
				array(
					'source'     => 'bounce-webhook',
					'provider'   => $provider,
					'request_id' => $request_id,
					'data_size'  => is_array( $data ) ? count( $data ) : strlen( serialize( $data ) ),
					'ip'         => $this->get_client_ip(),
				)
			);

			// Process webhook with retry mechanism
			$result = $this->process_webhook_with_retry( $handler, $data, $provider, $request_id );

			$processing_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );

			// Log successful processing
			doublescale_get_logger()->info(
				sprintf( 'Bounce webhook processed successfully for provider: %s', $provider ),
				array(
					'source'          => 'bounce-webhook',
					'provider'        => $provider,
					'request_id'      => $request_id,
					'result'          => $result,
					'processing_time' => $processing_time . 'ms',
				)
			);

			// Fire action for external logging/monitoring systems
			do_action( 'doublescale_bounce_webhook_processed', $provider, $result, $data, $request_id );

			return new \WP_REST_Response(
				array(
					'success'         => true,
					'provider'        => $provider,
					'message'         => 'Bounce webhook processed',
					'result'          => $result,
					'request_id'      => $request_id,
					'processing_time' => $processing_time . 'ms',
				),
				200
			);

		} catch ( \Exception $e ) {
			$processing_time = round( ( microtime( true ) - $start_time ) * 1000, 2 );

			doublescale_get_logger()->error(
				'Bounce webhook processing failed: ' . $e->getMessage(),
				array(
					'source'          => 'bounce-webhook',
					'provider'        => $provider,
					'request_id'      => $request_id,
					'exception'       => $e->getMessage(),
					'trace'           => $e->getTraceAsString(),
					'processing_time' => $processing_time . 'ms',
					'ip'              => $this->get_client_ip(),
				)
			);

			// Fire action for failed webhook processing
			do_action( 'doublescale_bounce_webhook_failed', $provider, $e, $request_id );

			return new \WP_REST_Response(
				array(
					'success'    => false,
					'message'    => 'Webhook processing failed',
					'request_id' => $request_id,
					'error'      => WP_DEBUG ? $e->getMessage() : 'Internal server error',
				),
				500
			);
		}
	}

	/**
	 * Get webhook URLs
	 *
	 * Returns webhook URLs and metadata for all registered handlers.
	 * Handlers are instantiated only to get their metadata.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $include_metadata Whether to include full metadata (description, doc_url, etc.).
	 *
	 * @return array
	 */
	public function get_webhook_urls( $include_metadata = true ) {
		$security_key = get_option( 'doublescale_bounce_security_key' );

		// Generate security key if it doesn't exist
		if ( ! $security_key ) {
			$security_key = 'doublescale_' . wp_generate_password( 32, false );
			update_option( 'doublescale_bounce_security_key', $security_key );
			update_option( 'doublescale_bounce_security_key_generated_at', time() );

			doublescale_get_logger()->info(
				'Generated bounce webhook security key',
				array(
					'source'     => 'bounce-handler-manager',
					'key_length' => strlen( $security_key ),
					'context'    => 'get_webhook_urls',
				)
			);
		}

		$urls = array();
		foreach ( $this->handler_classes as $slug => $class_name ) {
			// Get handler instance (will be lazy loaded)
			$handler = $this->get_handler( $slug );

			if ( $handler ) {
				$webhook_data = array(
					'slug' => $slug,
					'name' => $handler->get_name(),
					'url'  => self::build_webhook_url( $slug, $security_key ),
				);

				// Include additional metadata if requested
				if ( $include_metadata ) {
					$webhook_data['description']        = $handler->get_description();
					$webhook_data['doc_url']            = $handler->get_doc_url();
					$webhook_data['setup_instructions'] = $handler->get_setup_instructions();
				}

				$urls[ $slug ] = $webhook_data;
			}
		}

		return $urls;
	}

	/**
	 * Get registered handler classes
	 *
	 * Returns the registered handler class names without instantiating them.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of slug => class_name pairs.
	 */
	public function get_handler_classes() {
		return $this->handler_classes;
	}

	/**
	 * Get instantiated handlers
	 *
	 * Returns only the handlers that have been instantiated so far.
	 * Use get_handler_classes() to get all registered handlers.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of instantiated handler instances.
	 */
	public function get_handlers() {
		return $this->handlers;
	}

	/**
	 * Extract webhook data from request
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param string           $provider Provider name.
	 *
	 * @return array|null
	 */
	private function extract_webhook_data( $request, $provider ) {
		// Try JSON params first
		$data = $request->get_json_params();

		// Fallback to regular params
		if ( empty( $data ) ) {
			$data = $request->get_params();
		}

		// Special handling for providers that send raw input
		$raw_input_providers = apply_filters( 'doublescale_bounce_raw_input_providers', array( 'amazonses', 'ses' ) );

		if ( empty( $data ) || in_array( $provider, $raw_input_providers, true ) ) {
			$raw_data = file_get_contents( 'php://input' );

			if ( ! empty( $raw_data ) ) {
				// Try to decode as JSON
				$decoded = json_decode( $raw_data, true );

				if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
					$data = $decoded;
				} else {
					// Try to parse as form data
					parse_str( $raw_data, $parsed );
					if ( ! empty( $parsed ) ) {
						$data = $parsed;
					} else {
						// Store raw data for custom parsing
						$data = array( '_raw' => $raw_data );
					}
				}
			}
		}

		// Validate data structure
		if ( ! is_array( $data ) ) {
			doublescale_get_logger()->info(
				'Invalid webhook data structure from provider: ' . $provider,
				array(
					'source'    => 'bounce-webhook',
					'provider'  => $provider,
					'data_type' => gettype( $data ),
				)
			);
			return null;
		}

		// Apply provider-specific data filters
		$data = apply_filters( "doublescale_bounce_webhook_data_{$provider}", $data, $request );
		$data = apply_filters( 'doublescale_bounce_webhook_data', $data, $provider, $request );

		return $data;
	}

	/**
	 * Process webhook with retry mechanism
	 *
	 * @since 1.0.0
	 *
	 * @param \DoubleScale\Pro\Modules\Inbox\Abstracts\BounceHandler $handler Handler instance.
	 * @param array                              $data Webhook data.
	 * @param string                             $provider Provider name.
	 * @param string                             $request_id Request ID.
	 *
	 * @return bool
	 * @throws \Exception If all retry attempts fail.
	 */
	private function process_webhook_with_retry( $handler, $data, $provider, $request_id ) {
		$max_retries = apply_filters( 'doublescale_bounce_webhook_max_retries', 3 );
		$retry_delay = apply_filters( 'doublescale_bounce_webhook_retry_delay', 1 ); // seconds

		$last_exception = null;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			try {
				$handler->set_data( $data );
				$result = $handler->handle();

				// If we get here, processing was successful
				if ( $attempt > 1 ) {
					doublescale_get_logger()->info(
						sprintf( 'Bounce webhook processing succeeded on attempt %d for provider: %s', $attempt, $provider ),
						array(
							'source'     => 'bounce-webhook',
							'provider'   => $provider,
							'request_id' => $request_id,
							'attempt'    => $attempt,
							'result'     => $result,
						)
					);
				}

				return $result;

			} catch ( \Exception $e ) {
				$last_exception = $e;

				doublescale_get_logger()->info(
					sprintf( 'Bounce webhook processing failed on attempt %d for provider: %s - %s', $attempt, $provider, $e->getMessage() ),
					array(
						'source'     => 'bounce-webhook',
						'provider'   => $provider,
						'request_id' => $request_id,
						'attempt'    => $attempt,
						'exception'  => $e->getMessage(),
					)
				);

				// Don't sleep on the last attempt
				if ( $attempt < $max_retries ) {
					// Exponential backoff: delay * (2 ^ (attempt - 1))
					$delay = $retry_delay * pow( 2, $attempt - 1 );
					sleep( $delay );
				}
			}
		}

		// All attempts failed, throw the last exception
		throw $last_exception;
	}

	/**
	 * Get client IP address
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_client_ip() {
		// Check for various headers that might contain the real IP
		$headers = array(
			'HTTP_CF_CONNECTING_IP',     // Cloudflare
			'HTTP_X_REAL_IP',           // Nginx
			'HTTP_X_FORWARDED_FOR',     // Load balancers/proxies
			'HTTP_X_FORWARDED',         // Proxies
			'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
			'HTTP_FORWARDED_FOR',       // Proxies
			'HTTP_FORWARDED',           // Proxies
			'REMOTE_ADDR',              // Standard
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

				// Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}

				// Validate IP address
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Check rate limiting for webhook requests
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider Provider name.
	 * @param string $ip Client IP address.
	 *
	 * @return bool True if request is allowed, false if rate limited.
	 */
	private function check_rate_limit( $provider, $ip ) {
		$rate_limit_enabled = apply_filters( 'doublescale_bounce_webhook_rate_limit_enabled', true );

		if ( ! $rate_limit_enabled ) {
			return true;
		}

		$max_requests = apply_filters( 'doublescale_bounce_webhook_rate_limit_max', 100 ); // requests per window
		$window_size  = apply_filters( 'doublescale_bounce_webhook_rate_limit_window', 300 ); // 5 minutes in seconds

		$cache_key    = "doublescale_bounce_rate_limit_{$provider}_{$ip}";
		$current_time = time();
		$window_start = $current_time - $window_size;

		// Get current request timestamps
		$requests = get_transient( $cache_key );
		if ( ! is_array( $requests ) ) {
			$requests = array();
		}

		// Remove old requests outside the window
		$requests = array_filter(
			$requests,
			function( $timestamp ) use ( $window_start ) {
				return $timestamp > $window_start;
			}
		);

		// Check if limit exceeded
		if ( count( $requests ) >= $max_requests ) {
			doublescale_get_logger()->info(
				'Rate limit exceeded for bounce webhook',
				array(
					'source'        => 'bounce-webhook',
					'provider'      => $provider,
					'ip'            => $ip,
					'request_count' => count( $requests ),
					'max_requests'  => $max_requests,
					'window_size'   => $window_size,
				)
			);

			return false;
		}

		// Add current request
		$requests[] = $current_time;

		// Store updated requests with expiration
		set_transient( $cache_key, $requests, $window_size + 60 );

		return true;
	}
}
