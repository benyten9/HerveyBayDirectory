<?php
/**
 * Account Api Helper Trait
 *
 * Provides common functionality for getting smtp account Api instances.
 * Used by both bulk mailers and curl multi mailers to avoid code duplication.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 * @subpackage Emails\Traits
 */

namespace DoubleScale\Modules\Emails\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * AccountApiHelper trait
 *
 * @since 1.0.0
 */
trait AccountApiHelper {

	/**
	 * Cached account Api instance
	 *
	 * @var object|null
	 */
	private $account_api = null;

	/**
	 * Cached from email for smart routing
	 *
	 * @var string|null
	 */
	private $from_email = null;

	/**
	 * Get the mailer slug (must be implemented by using class)
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Get the SMTP mailer Accounts class FQCN (bundled SMTP module).
	 *
	 * Override this method if the class name differs from the standard format.
	 *
	 * @return string Fully qualified class name (e.g. DoubleScale\Modules\Smtp\Providers\Mailgun\Accounts).
	 */
	protected function get_mailer_accounts_class() {
		$slug = $this->get_slug();
		// Convert slug to class name (e.g., 'mailgun' -> 'Mailgun', 'smtp2go' -> 'SMTP2GO')
		$class_name = $this->slug_to_class_name( $slug );
		return '\\DoubleScale\\Modules\\Smtp\\Providers\\' . $class_name . '\\Accounts';
	}

	/**
	 * Convert mailer slug to class name
	 *
	 * @param string $slug Mailer slug (e.g., 'mailgun', 'smtp2go', 'sendgrid')
	 *
	 * @return string Class name (e.g., 'Mailgun', 'SMTP2GO', 'SendGrid')
	 */
	protected function slug_to_class_name( $slug ) {
		// Map of special case slugs that don't follow simple ucfirst pattern
		$special_cases = array(
			'mailgun'      => 'Mailgun',
			'sendgrid'     => 'SendGrid',
			'mailersend'   => 'MailerSend',
			'sendinblue'   => 'SendInBlue',
			'postmark'     => 'PostMark',
			'sparkpost'    => 'SparkPost',
			'mailjet'      => 'Mailjet',
			'elasticemail' => 'ElasticEmail',
			'aws'          => 'Aws',
			'smtp2go'      => 'SMTP2GO',
		);

		return $special_cases[ $slug ] ?? ucfirst( $slug );
	}

	/**
	 * Mailer provider instance for this slug from the bundled SMTP module registry.
	 *
	 * @return object|null Mailer with public $accounts or null.
	 */
	protected function get_registered_mailer_instance() {
		if ( class_exists( '\\DoubleScale\\Modules\\Smtp\\Providers\\Mailers' ) ) {
			$mailer = \DoubleScale\Modules\Smtp\Providers\Mailers::get_mailer( $this->get_slug() );
			return $mailer ?: null;
		}
		return null;
	}

	/**
	 * Find account ID for this mailer from smtp settings
	 *
	 * Uses smtp smart routing to get the appropriate connection,
	 * then checks if it uses this mailer. Falls back to fallback connection.
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return string|null Account ID or null if not found
	 */
	protected function find_account_id( $from_email = null ) {
		// Resolve the bundled SMTP module Settings class.
		$settings_class = class_exists( '\\DoubleScale\\Modules\\Smtp\\Settings' )
			? '\\DoubleScale\\Modules\\Smtp\\Settings'
			: null;

		if ( $settings_class && method_exists( $settings_class, 'get_smart_route' ) ) {
			$route = call_user_func( array( $settings_class, 'get_smart_route' ), $from_email );
			$slug  = $this->get_slug();

			// Check default connection from smart route
			if ( ! empty( $route['default_connection'] ) ) {
				$connection = $route['default_connection'];

				if ( ! empty( $connection['mailer'] ) && $connection['mailer'] === $slug ) {
					return $connection['account_id'] ?? null;
				}
			}

			// Check fallback connection if default doesn't use this mailer
			if ( ! empty( $route['fallback_connection'] ) ) {
				$connection = $route['fallback_connection'];

				if ( ! empty( $connection['mailer'] ) && $connection['mailer'] === $slug ) {
					return $connection['account_id'] ?? null;
				}
			}

			return null;
		}

		// Fallback to direct option reading if no SMTP Settings class was available.
		$option_name = class_exists( '\\DoubleScale\\Pro\\Modules\\Inbox\\Oauth\\EmailOauth' )
			? \DoubleScale\Pro\Modules\Inbox\Oauth\EmailOauth::smtp_routing_option_name()
			: 'doublescale_smtp_settings';
		$settings    = get_option( $option_name, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Check if connections exist
		if ( empty( $settings['connections'] ) || ! is_array( $settings['connections'] ) ) {
			return null;
		}

		$connections = $settings['connections'];
		$slug        = $this->get_slug();

		// Check default_connection first
		if ( ! empty( $settings['default_connection'] ) ) {
			$default_connection_id = $settings['default_connection'];

			if ( isset( $connections[ $default_connection_id ] ) ) {
				$connection = $connections[ $default_connection_id ];

				if ( ! empty( $connection['mailer'] ) && $connection['mailer'] === $slug ) {
					return $connection['account_id'] ?? null;
				}
			}
		}

		// Check fallback_connection if default doesn't use this mailer
		if ( ! empty( $settings['fallback_connection'] ) ) {
			$fallback_connection_id = $settings['fallback_connection'];

			if ( isset( $connections[ $fallback_connection_id ] ) ) {
				$connection = $connections[ $fallback_connection_id ];

				if ( ! empty( $connection['mailer'] ) && $connection['mailer'] === $slug ) {
					return $connection['account_id'] ?? null;
				}
			}
		}

		return null;
	}

	/**
	 * Set the from email for smart routing
	 *
	 * @param string|null $from_email From email address
	 * @return $this
	 */
	public function set_from_email( $from_email ) {
		// Reset cached Api if from_email changes
		if ( $this->from_email !== $from_email ) {
			$this->account_api = null;
		}
		$this->from_email = $from_email;
		return $this;
	}

	/**
	 * Get the from email
	 *
	 * @return string|null
	 */
	public function get_from_email() {
		return $this->from_email;
	}

	/**
	 * Get the account Api instance for this mailer
	 *
	 * @param string|null $from_email Optional from email for smart routing.
	 * @return object|null Account Api instance or null if not available
	 */
	protected function get_account_api( $from_email = null ) {
		// Use provided from_email or fall back to cached value
		$effective_from_email = $from_email ?? $this->from_email;

		// Reset cache if from_email is different from cached
		if ( $effective_from_email !== null && $this->from_email !== $effective_from_email ) {
			$this->account_api = null;
			$this->from_email  = $effective_from_email;
		}

		if ( $this->account_api !== null ) {
			return $this->account_api;
		}

		$accounts_class = $this->get_mailer_accounts_class();

		if ( ! class_exists( $accounts_class ) ) {
			return null;
		}

		$account_id = $this->find_account_id( $effective_from_email );

		if ( ! $account_id ) {
			return null;
		}

		try {
			$mailer = $this->get_registered_mailer_instance();

			if ( ! $mailer || ! isset( $mailer->accounts ) ) {
				return null;
			}

			$account_api = $mailer->accounts->connect( $account_id );

			if ( is_wp_error( $account_api ) ) {
				return null;
			}

			$this->account_api = $account_api;
			return $this->account_api;
		} catch ( \Exception $e ) {
			$this->log_account_api_error( $e->getMessage() );
			return null;
		}
	}

	/**
	 * Log account Api error
	 *
	 * @param string $error_message Error message
	 */
	protected function log_account_api_error( $error_message ) {
		doublescale_get_logger()->error(
			/* translators: %s: mailer name */
			sprintf( __( 'Failed to get %s Account Api', 'doublescale' ), ucfirst( $this->get_slug() ) ),
			array(
				'code'   => 'bulk_email_' . $this->get_slug() . '_api_error',
				'error'  => $error_message,
				'mailer' => $this->get_slug(),
			)
		);
	}

	/**
	 * Get account credentials (for mailers that need direct Api access)
	 *
	 * This method gets raw credentials from the mailer account.
	 * Useful for curl multi mailers that need Api keys directly.
	 *
	 * @param string      $credential_key The key to get from credentials (e.g., 'api_key')
	 * @param string|null $from_email     Optional from email for smart routing.
	 *
	 * @return array|null Array with credentials or null if not available
	 */
	protected function get_account_credentials( $credential_key = 'api_key', $from_email = null ) {
		$accounts_class = $this->get_mailer_accounts_class();

		if ( ! class_exists( $accounts_class ) ) {
			return null;
		}

		// Use provided from_email or fall back to cached value
		$effective_from_email = $from_email ?? $this->from_email;

		$account_id = $this->find_account_id( $effective_from_email );

		if ( ! $account_id ) {
			return null;
		}

		try {
			$mailer = $this->get_registered_mailer_instance();

			if ( ! $mailer || ! isset( $mailer->accounts ) ) {
				return null;
			}

			$accounts = $mailer->accounts->get_accounts();

			if ( empty( $accounts[ $account_id ]['credentials'][ $credential_key ] ) ) {
				return null;
			}

			return array(
				$credential_key => $accounts[ $account_id ]['credentials'][ $credential_key ],
				'account_id'    => $account_id,
			);
		} catch ( \Exception $e ) {
			$this->log_account_api_error( $e->getMessage() );
			return null;
		}
	}

	/**
	 * Reset cached account Api instance
	 *
	 * Useful when mailer configuration changes.
	 */
	public function reset_account_api() {
		$this->account_api = null;
		$this->from_email  = null;
	}
}
