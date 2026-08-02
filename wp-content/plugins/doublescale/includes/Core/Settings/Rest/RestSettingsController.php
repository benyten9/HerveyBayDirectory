<?php

/**
 * RestSettingsController class.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core\Settings\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Core\UserRoles\UserRoles;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;

/**
 * RestSettingsController class.
 *
 * @since 1.0.0
 */
class RestSettingsController extends RestController {

	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Register the routes for the controller.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
				),
			)
		);

		// Cron status endpoint
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/cron-status",
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_cron_status' ),
				'permission_callback' => array( $this, 'get_permissions_check' ),
			)
		);

		// Run cron manually endpoint
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/run-cron",
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_cron_manually' ),
				'permission_callback' => array( $this, 'update_permissions_check' ),
				'args'                => array(
					'hook' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return is_string( $param ) && ! empty( $param );
						},
					),
				),
			)
		);

		// Note: Bounce webhooks endpoint moved to Pro plugin (RestSettingsControllerPro)
	}

	/**
	 * Retrieves schema, conforming to JSON Schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_schema() {
		$schema = array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'settings',
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'business'         => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'business_name'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'business_address' => array(
							'type'    => 'string',
							'default' => '',
						),
						'business_logo'    => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				'calendar'         => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'week_starts_on' => array(
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 0,
							'maximum'     => 6,
							'description' => 'First day of the week for CRM calendars. 0 = Sunday, 1 = Monday, … 6 = Saturday.',
						),
					),
				),
				'email'            => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'from_name'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'from_email'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'reply_to'      => array(
							'type'    => 'string',
							'default' => get_option( 'admin_email' ),
						),
						'email_footer'  => array(
							'type'    => 'string',
							'default' => Settings::get_default_email_footer(),
						),
						'max_in_second' => array(
							'type'    => 'integer',
							'default' => 10,
						),
						'max_in_day'    => array(
							'type'    => 'integer',
							'default' => 10000,
						),
					),
				),
				'sms'              => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'max_in_second' => array(
							'type'    => 'integer',
							'default' => 10,
						),
					),
				),
				'whatsapp'         => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'max_in_second' => array(
							'type'    => 'integer',
							'default' => 10,
						),
					),
				),
				'double_optin'     => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'email_subject'         => array(
							'type'    => 'string',
							'default' => Settings::get_default_opt_in_subject(),
						),
						'email_content'         => array(
							'type'    => 'string',
							'default' => Settings::get_default_opt_in_content(),
						),
						'after_confirmation'    => array(
							'type'    => 'string',
							'default' => 'message',
						),
						'confirmation_message'  => array(
							'type'    => 'string',
							'default' => Settings::get_default_confirmation_message(),
						),
						'confirmation_redirect' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
				'cart'             => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'enable_cart_tracking' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'create_contacts_in_crm' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Create CRM contacts from abandoned carts for list/tag assignment. When off, contacts are only created by active abandoned-cart automations.', 'doublescale' ),
						),
						'wait_period'          => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'cool_off_period'      => array(
							'type'    => 'integer',
							'default' => 15,
						),
						'lost_cart_days'       => array(
							'type'    => 'integer',
							'default' => 15,
						),
						'gdpr_compliance'      => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'gdpr_message'         => array(
							'type'    => 'string',
							'default' => 'Your email, phone number, and cart are saved so we can send you reminders about this order. {{no_thanks text="No Thanks"}}',
						),
						'tags'                 => array(
							'type'    => 'array',
							'items'   => array(
								'type' => array( 'number', 'string' ),
							),
							'default' => array(),
						),
						'lists'                => array(
							'type'    => 'array',
							'items'   => array(
								'type' => array( 'number', 'string' ),
							),
							'default' => array(),
						),
						'lost_tags'            => array(
							'type'    => 'array',
							'items'   => array(
								'type' => array( 'number', 'string' ),
							),
							'default' => array(),
						),
						'lost_lists'           => array(
							'type'    => 'array',
							'items'   => array(
								'type' => array( 'number', 'string' ),
							),
							'default' => array(),
						),
					),
				),
				'currency'         => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'currency' => array(
							'type'    => 'string',
							'default' => 'USD',
						),
					),
				),
				'website_tracking' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'enabled'        => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'retention_type' => array(
							'type'    => 'string',
							'default' => 'days',
							'enum'    => array( 'days', 'never' ),
						),
						'retention_days' => array(
							'type'    => 'string',
							'default' => '30',
						),
					),
				),
				'button_settings'  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'default'              => array(),
				),
				'ai'               => array(
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => array(
						'provider'     => array(
							'type'    => 'string',
							'default' => '',
							'enum'    => array( '', 'openai', 'anthropic', 'gemini', 'custom' ),
						),
						'model'        => array(
							'type'    => 'string',
							'default' => '',
						),
						'api_key'      => array(
							'type'    => 'string',
							'default' => '',
						),
						'base_url'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'connections'  => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'default'              => array(),
						),
						'access'       => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'default'              => array(
								'enabled'       => true,
								'allowed_roles' => array( 'doublescale_crm_manager', 'administrator', 'doublescale_sales_manager', 'doublescale_sales_rep' ),
							),
						),
						'data_access'  => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'default'              => array(
								'crm_data'          => true,
								'conversation_data' => true,
								'campaign_data'     => true,
								'activity_data'     => true,
								'support_data'      => true,
								'booking_data'      => true,
							),
						),
						'data_sources' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
							'default'              => array(
								'business_profile'       => true,
								'brand_voice'            => '',
								'industry'               => '',
								'product_description'    => '',
								'ideal_customer_profile' => '',
								'custom_instructions'    => '',
							),
						),
					),
				),
				'debugging'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'log_level' => array(
							'type'    => 'string',
							'default' => 'error',
							'enum'    => array( 'error', 'error,debug', 'error,debug,info' ),
						),
					),
				),
			),
		);
		return $schema;
	}

	/**
	 * Retrieves settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get( $request ) 	{ // phpcs:ignore
		$settings = Settings::get_all();

		$result = array();
		foreach ( $this->get_schema()['properties'] as $group_key => $group_schema ) {
			if ( $group_key === 'button_settings' ) {
				// Handle button_settings specially since it's not a structured schema.
				$result[ $group_key ] = $settings[ $group_key ] ?? $group_schema['default'];
			} else {
				$result[ $group_key ] = array();
				foreach ( $group_schema['properties'] as $setting_key => $setting_schema ) {
					$saved_value = $settings[ $group_key ][ $setting_key ] ?? null;
					// Use default if value is null, empty string, or empty editor content (for string types).
					if ( null === $saved_value || ( 'string' === $setting_schema['type'] && $this->is_empty_value( $saved_value ) ) ) {
						$result[ $group_key ][ $setting_key ] = $setting_schema['default'];
					} else {
						$result[ $group_key ][ $setting_key ] = $saved_value;
					}
				}
			}
		}

		// Decrypt and mask AI Api keys so plaintext keys never leave the server.
		if ( isset( $result['ai'] ) ) {
			if ( ! empty( $result['ai']['api_key'] ) ) {
				$result['ai']['api_key'] = $this->mask_key(
					Settings::decrypt_value( $result['ai']['api_key'] )
				);
			}
			if ( ! empty( $result['ai']['connections'] ) && is_array( $result['ai']['connections'] ) ) {
				foreach ( $result['ai']['connections'] as $p => &$conn ) {
					if ( ! empty( $conn['api_key'] ) ) {
						$conn['api_key'] = $this->mask_key(
							Settings::decrypt_value( $conn['api_key'] )
						);
					}
				}
				unset( $conn );
			}
		}

		return new WP_REST_Response( $result, 200 );
	}



	/**
	 * Updates settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update( $request ) {
		$settings = $request->get_json_params();

		// Validate and sanitize settings
		$validation_result = $this->validate_settings( $settings );
		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		// Collect warnings (non-blocking validation issues)
		$warnings = $this->collect_warnings( $settings );

		// Resolve AI keys: unmask → encrypt → persist per-provider connections.
		if ( isset( $settings['ai'] ) ) {
			$existing_ai          = Settings::get( 'ai', array() );
			$existing_connections = $existing_ai['connections'] ?? array();
			$active_key_masked    = isset( $settings['ai']['api_key'] ) && $this->is_masked( $settings['ai']['api_key'] );

			// Unmask keys inside connections.
			// Masked → restore encrypted value from DB.
			// New plaintext → encrypt before saving.
			if ( ! empty( $settings['ai']['connections'] ) && is_array( $settings['ai']['connections'] ) ) {
				foreach ( $settings['ai']['connections'] as $p => &$conn ) {
					if ( isset( $conn['api_key'] ) ) {
						if ( $this->is_masked( $conn['api_key'] ) ) {
							$conn['api_key'] = $existing_connections[ $p ]['api_key'] ?? '';
						} elseif ( ! empty( $conn['api_key'] ) ) {
							$conn['api_key'] = Settings::encrypt_value( $conn['api_key'] );
						}
					}
				}
				unset( $conn );
			} else {
				$settings['ai']['connections'] = $existing_connections;
			}

			// Resolve the active api_key.
			$provider = $settings['ai']['provider'] ?? '';
			if ( $active_key_masked ) {
				$conn_key                  = $settings['ai']['connections'][ $provider ]['api_key'] ?? '';
				$settings['ai']['api_key'] = $conn_key ?: ( $existing_ai['api_key'] ?? '' );
			} elseif ( ! empty( $settings['ai']['api_key'] ) ) {
				$settings['ai']['api_key'] = Settings::encrypt_value( $settings['ai']['api_key'] );
			}

			// Sync the current provider's settings into its connection slot.
			if ( $provider ) {
				$settings['ai']['connections'][ $provider ] = array(
					'api_key'  => $settings['ai']['api_key'] ?? '',
					'model'    => $settings['ai']['model'] ?? '',
					'base_url' => $settings['ai']['base_url'] ?? '',
				);
			}
		}

		// Sanitize AI governance fields.
		if ( isset( $settings['ai']['access'] ) ) {
			$settings['ai']['access']['enabled']       = (bool) ( $settings['ai']['access']['enabled'] ?? true );
			$allowed                                   = $settings['ai']['access']['allowed_roles'] ?? array();
			$valid_roles                               = array(
				UserRoles::CRM_MANAGER,
				UserRoles::ADMINISTRATOR,
				UserRoles::SALES_MANAGER,
				UserRoles::SALES_REP,
				UserRoles::SUPPORT_MANAGER,
				UserRoles::SUPPORT_AGENT,
				UserRoles::BOOKING_MANAGER,
				UserRoles::BOOKING_AGENT,
			);
			$settings['ai']['access']['allowed_roles'] = array_values( array_intersect( (array) $allowed, $valid_roles ) );
		}

		if ( isset( $settings['ai']['data_access'] ) ) {
			foreach ( array( 'crm_data', 'conversation_data', 'campaign_data', 'activity_data', 'support_data', 'booking_data' ) as $key ) {
				$settings['ai']['data_access'][ $key ] = (bool) ( $settings['ai']['data_access'][ $key ] ?? true );
			}
		}

		if ( isset( $settings['ai']['data_sources'] ) ) {
			$settings['ai']['data_sources']['business_profile'] = (bool) ( $settings['ai']['data_sources']['business_profile'] ?? true );
			foreach ( array( 'brand_voice', 'industry', 'product_description', 'ideal_customer_profile', 'custom_instructions' ) as $key ) {
				$settings['ai']['data_sources'][ $key ] = sanitize_textarea_field( $settings['ai']['data_sources'][ $key ] ?? '' );
			}
		}

		// Sanitize settings
		$settings = $this->sanitize_settings( $settings );

		// Cart tracking off must also disable blind CRM contact sync (UI hides that toggle).
		if ( isset( $settings['cart'] ) && is_array( $settings['cart'] ) ) {
			if ( empty( $settings['cart']['enable_cart_tracking'] ) ) {
				$settings['cart']['create_contacts_in_crm'] = false;
			}
		}

		Settings::update_many( $settings );

		$response = array( 'success' => true );
		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Check if a value looks like a masked key (all asterisks except last ≤4 chars).
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	private function is_masked( $value ) {
		return (bool) preg_match( '/^\*+.{0,4}$/', $value );
	}

	/**
	 * Mask an Api key for safe display, showing only the last 4 characters.
	 *
	 * @param string $key Raw Api key.
	 * @return string Masked key (e.g. "************c123") or empty string.
	 */
	private function mask_key( $key ) {
		if ( empty( $key ) ) {
			return '';
		}
		$visible = substr( $key, -4 );
		return str_repeat( '*', max( 8, strlen( $key ) - 4 ) ) . $visible;
	}

	/**
	 * Validate settings before saving
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Settings to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_settings( $settings ) {
		// Validate email settings
		if ( isset( $settings['email'] ) ) {
			$email_validation = $this->validate_email_settings( $settings['email'] );
			if ( is_wp_error( $email_validation ) ) {
				return $email_validation;
			}
		}

		// Validate Sms settings
		if ( isset( $settings['sms'] ) ) {
			$sms_validation = $this->validate_sms_settings( $settings['sms'] );
			if ( is_wp_error( $sms_validation ) ) {
				return $sms_validation;
			}
		}

		// Validate WhatsApp settings
		if ( isset( $settings['whatsapp'] ) ) {
			$whatsapp_validation = $this->validate_whatsapp_settings( $settings['whatsapp'] );
			if ( is_wp_error( $whatsapp_validation ) ) {
				return $whatsapp_validation;
			}
		}

		// Validate calendar week start (0 = Sunday … 6 = Saturday).
		if ( isset( $settings['calendar']['week_starts_on'] ) ) {
			$day = (int) $settings['calendar']['week_starts_on'];
			if ( $day < 0 || $day > 6 ) {
				return new \WP_Error(
					'invalid_calendar_week_starts_on',
					__( 'Calendar week start day must be between 0 (Sunday) and 6 (Saturday).', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Validate email settings
	 *
	 * @since 1.0.0
	 *
	 * @param array $email Email settings.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_email_settings( $email ) {
		// Validate from_email
		if ( isset( $email['from_email'] ) && ! empty( $email['from_email'] ) ) {
			if ( ! is_email( $email['from_email'] ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'From email is not a valid email address', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
			// Note: smtp connection validation moved to collect_warnings() for non-blocking behavior
		}

		// Validate reply_to
		if ( isset( $email['reply_to'] ) && ! empty( $email['reply_to'] ) ) {
			if ( ! is_email( $email['reply_to'] ) ) {
				return new WP_Error(
					'invalid_email',
					__( 'Reply-to is not a valid email address', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		// Validate max_in_second
		if ( isset( $email['max_in_second'] ) ) {
			$max_in_second = intval( $email['max_in_second'] );

			if ( $max_in_second < 1 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max emails per second must be at least 1', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			// if ( $max_in_second > 100 ) {
			// return new WP_Error(
			// 'invalid_rate_limit',
			// __( 'Max emails per second cannot exceed 100 (server limitation)', 'doublescale'),
			// array( 'status' => 400 )
			// );
			// }
		}

		// Validate max_in_day
		if ( isset( $email['max_in_day'] ) ) {
			$max_in_day = intval( $email['max_in_day'] );

			if ( $max_in_day < 1 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max emails per day must be at least 1', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			if ( $max_in_day > 1000000 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max emails per day cannot exceed 1,000,000', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Collect warnings for settings (non-blocking validation issues)
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Settings to check.
	 * @return array Array of warning messages.
	 */
	private function collect_warnings( $settings ) {
		$warnings = array();

		// Check smtp connection for from_email
		if ( isset( $settings['email']['from_email'] ) && ! empty( $settings['email']['from_email'] ) ) {
			$email_warning = $this->check_smtp_connection( $settings['email']['from_email'] );
			if ( $email_warning ) {
				$warnings[] = $email_warning;
			}
		}

		return $warnings;
	}

	/**
	 * Check smtp connection for an email address (non-blocking)
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address to check.
	 * @return string|null Warning message if there's an issue, null otherwise.
	 */
	private function check_smtp_connection( $email ) {
		$email_oauth = 'DoubleScale\\Pro\\Modules\\Inbox\\Oauth\\EmailOauth';
		if ( ! class_exists( $email_oauth, false ) || ! call_user_func( array( $email_oauth, 'smtp_settings_class' ) ) ) {
			return __( 'Warning: No SMTP backend is loaded. Enable the DoubleScale SMTP module or activate SMTP for reliable email delivery.', 'doublescale' );
		}

		$connection_id = call_user_func( array( $email_oauth, 'smtp_get_connection_by_from_email' ), $email );

		if ( empty( $connection_id ) ) {
			return sprintf(
				/* translators: %s: email address */
				__( 'Warning: No SMTP connection configured for: %s. Add a matching connection in CRM SMTP settings.', 'doublescale' ),
				$email
			);
		}

		return null;
	}

	/**
	 * Validate Sms settings
	 *
	 * @since 1.0.0
	 *
	 * @param array &$sms Sms settings (passed by reference to allow cleanup).
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_sms_settings( &$sms ) {
		// SMS providers (Twilio, etc.) enforce per-account daily quotas, so
		// strip the field rather than persist a value we never check.
		if ( isset( $sms['max_in_day'] ) ) {
			unset( $sms['max_in_day'] );
		}

		// Validate max_in_second
		if ( isset( $sms['max_in_second'] ) ) {
			$max_in_second = intval( $sms['max_in_second'] );

			if ( $max_in_second < 1 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max Sms per second must be at least 1', 'doublescale' ),
					array( 'status' => 400 )
				);
			}

			if ( $max_in_second > 10 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max Sms per second cannot exceed 10 (provider Api limit)', 'doublescale' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Validate WhatsApp settings
	 *
	 * @since 1.0.0
	 *
	 * @param array &$whatsapp WhatsApp settings (passed by reference to allow cleanup).
	 * @return true|WP_Error
	 */
	private function validate_whatsapp_settings( &$whatsapp ) {
		// Meta WhatsApp enforces tier-based quotas (1K/10K/100K/unlimited per
		// 24h), so strip the field rather than persist a value we never check.
		if ( isset( $whatsapp['max_in_day'] ) ) {
			unset( $whatsapp['max_in_day'] );
		}

		// Validate max_in_second is within acceptable range (1-10)
		if ( isset( $whatsapp['max_in_second'] ) ) {
			$max_in_second = intval( $whatsapp['max_in_second'] );

			if ( $max_in_second > 10 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max WhatsApp per second cannot exceed 10 (provider Api limit)', 'doublescale' ),
					array(
						'status' => 400,
					)
				);
			}

			if ( $max_in_second < 1 ) {
				return new WP_Error(
					'invalid_rate_limit',
					__( 'Max WhatsApp per second must be at least 1', 'doublescale' ),
					array(
						'status' => 400,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize settings recursively
	 *
	 * @since 1.0.0
	 *
	 * @param array $settings Settings to sanitize.
	 * @return array Sanitized settings.
	 */
	private function sanitize_settings( $settings ) {
		$sanitized = array();

		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_settings( $value );
			} elseif ( is_string( $value ) ) {
				// Special handling for email_footer and email_content (allow HTML)
				if ( in_array( $key, array( 'email_footer', 'email_content', 'confirmation_message', 'gdpr_message' ), true ) ) {
					$sanitized[ $key ] = self::kses_post_with_merge_tags( $value );
				} else {
					$sanitized[ $key ] = sanitize_text_field( $value );
				}
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = absint( $value );
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = (bool) $value;
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize HTML like wp_kses_post but allow merge-tag URLs in href attributes.
	 *
	 * wp_kses_post treats the {{group part of {{group:slug}} as an unknown URL
	 * protocol and strips it.  This method extracts every merge-tag group used
	 * in the content and adds it to the allowed-protocols list so KSES leaves
	 * the href values intact while still stripping dangerous markup.
	 *
	 * @param string $html HTML content.
	 * @return string Sanitized HTML with merge-tag hrefs preserved.
	 */
	public static function kses_post_with_merge_tags( $html ) {
		$protocols = wp_allowed_protocols();

		if ( preg_match_all( '/\{\{(\w+):/', $html, $matches ) ) {
			foreach ( array_unique( $matches[1] ) as $group ) {
				$protocols[] = '{{' . $group;
			}
		}

		return wp_kses( $html, wp_kses_allowed_html( 'post' ), $protocols );
	}

	/**
	 * Checks if a given request has access to update settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function update_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Checks if a given request has access to get settings.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Note: get_bounce_webhooks() method removed - functionality moved to Pro plugin
	 * See: DoubleScale\Core\Settings\Rest\RestSettingsControllerPro
	 */

	/**
	 * Check if a value is considered empty
	 *
	 * This checks for empty strings and also for empty editor content like
	 * '<p class="editor-paragraph"><br></p>' which is the default empty state
	 * from the Lexical editor component.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if value is empty, false otherwise.
	 */
	private function is_empty_value( $value ) {
		// Check for empty string.
		if ( '' === $value ) {
			return true;
		}

		// Check for empty editor content (Lexical editor's empty state).
		$trimmed = trim( $value );
		if ( '<p class="editor-paragraph"><br></p>' === $trimmed ) {
			return true;
		}

		// Check for other common empty HTML patterns.
		$stripped = trim( wp_strip_all_tags( $value ) );
		if ( '' === $stripped ) {
			return true;
		}

		return false;
	}

	/**
	 * Get cron job status
	 *
	 * Returns status information about all scheduled cron jobs including
	 * last run time, next scheduled time, and server configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_cron_status( $request ) {
		$events = array();

		// Email campaigns (every 60 seconds).
		$campaigns_tasks = \DoubleScale\Core\PluginKernel::instance()->campaigns_tasks;
		$email_heartbeat = $campaigns_tasks->get_heartbeat_status( 'doublescale_email_campaigns' );

		$last_run = $email_heartbeat['last_run'] ?? null;

		// Calculate next_scheduled on-the-fly.
		if ( $last_run ) {
			$next_run = strtotime( $last_run ) + 60;
		} else {
			$next_run = as_next_scheduled_action( 'doublescale_campaigns_doublescale_email_campaigns' );
		}

		if ( ! $next_run || true === $next_run ) {
			$next_run = time() + 60;
		}

		$events[] = array(
			'hook'       => 'doublescale_campaigns_doublescale_email_campaigns',
			'is_overdue' => $this->is_task_overdue( $last_run, 90 ), // 90 seconds threshold.
			'human_name' => __( 'Scheduled Email Sending Tasks', 'doublescale' ),
			'next_run'   => $next_run ? human_time_diff( $next_run, time() ) : __( 'Unknown', 'doublescale' ),
			'last_run'   => $last_run ? human_time_diff( strtotime( $last_run ), time() ) . ' ' . __( 'ago', 'doublescale' ) : __( 'Never', 'doublescale' ),
			'interval'   => 60,
		);

		// Sms campaigns (every 60 seconds) - Pro only.
		if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
			$sms_heartbeat = $campaigns_tasks->get_heartbeat_status( 'doublescale_sms_campaigns' );
			$last_run      = $sms_heartbeat['last_run'] ?? null;

			// Calculate next_scheduled on-the-fly.
			if ( $last_run ) {
				$next_run = strtotime( $last_run ) + 60;
			} else {
				$next_run = as_next_scheduled_action( 'doublescale_campaigns_doublescale_sms_campaigns' );
			}

			if ( ! $next_run || true === $next_run ) {
				$next_run = time() + 60;
			}

			$events[] = array(
				'hook'       => 'doublescale_campaigns_doublescale_sms_campaigns',
				'is_overdue' => $this->is_task_overdue( $last_run, 90 ),
				'human_name' => __( 'Scheduled Sms Sending Tasks', 'doublescale' ),
				'next_run'   => $next_run ? human_time_diff( $next_run, time() ) : __( 'Unknown', 'doublescale' ),
				'last_run'   => $last_run ? human_time_diff( strtotime( $last_run ), time() ) . ' ' . __( 'ago', 'doublescale' ) : __( 'Never', 'doublescale' ),
				'interval'   => 60,
			);
		}

		// Email sequences - Not shipping in this version.
		// $sequences_heartbeat = $campaigns_tasks->get_heartbeat_status( 'doublescale_email_sequences' );
		// $next_run = isset( $sequences_heartbeat['next_scheduled'] ) ? strtotime( $sequences_heartbeat['next_scheduled'] ) : time() + 60;
		// $last_run = $sequences_heartbeat['last_run'] ?? null;
		//
		// $events[] = array(
		// 'hook'       => 'doublescale_campaigns_doublescale_email_sequences',
		// 'is_overdue' => $this->is_task_overdue( $last_run, 90 ),
		// 'human_name' => __( 'Scheduled Email Processing', 'doublescale'),
		// 'next_run'   => $next_run ? human_time_diff( $next_run, time() ) : __( 'Unknown', 'doublescale'),
		// 'last_run'   => $last_run ? human_time_diff( strtotime( $last_run ), time() ) . ' ' . __( 'ago', 'doublescale') : __( 'Never', 'doublescale'),
		// 'interval'   => 60,
		// 'run_count'  => $sequences_heartbeat['run_count'] ?? 0,
		// );

		// WhatsApp campaigns - Not shipping in this version.
		// if ( class_exists( 'DoubleScale\Core\PluginKernel' ) ) {
		// $whatsapp_heartbeat = $campaigns_tasks->get_heartbeat_status( 'doublescale_whatsapp_campaigns' );
		// $next_run = isset( $whatsapp_heartbeat['next_scheduled'] ) ? strtotime( $whatsapp_heartbeat['next_scheduled'] ) : time() + 60;
		// $last_run = $whatsapp_heartbeat['last_run'] ?? null;
		//
		// $events[] = array(
		// 'hook'       => 'doublescale_campaigns_doublescale_whatsapp_campaigns',
		// 'is_overdue' => $this->is_task_overdue( $last_run, 90 ),
		// 'human_name' => __( 'Scheduled WhatsApp Sending Tasks', 'doublescale'),
		// 'next_run'   => $next_run ? human_time_diff( $next_run, time() ) : __( 'Unknown', 'doublescale'),
		// 'last_run'   => $last_run ? human_time_diff( strtotime( $last_run ), time() ) . ' ' . __( 'ago', 'doublescale') : __( 'Never', 'doublescale'),
		// 'interval'   => 60,
		// 'run_count'  => $whatsapp_heartbeat['run_count'] ?? 0,
		// );
		// }

		// Daily tasks.
		$daily_tasks      = \DoubleScale\Core\PluginKernel::instance()->daily_tasks;
		$daily3_heartbeat = $daily_tasks->get_heartbeat_status( 'doublescale_daily3' );
		$last_run         = $daily3_heartbeat['last_run'] ?? null;

		// Calculate next_scheduled on-the-fly.
		if ( $last_run ) {
			$next_run = strtotime( $last_run ) + DAY_IN_SECONDS;
		} else {
			$next_run = as_next_scheduled_action( 'doublescale_daily_doublescale_daily3' );
		}

		if ( ! $next_run || true === $next_run ) {
			$next_run = time() + DAY_IN_SECONDS;
		}

		$events[] = array(
			'hook'       => 'doublescale_daily_doublescale_daily3',
			'is_overdue' => $this->is_task_overdue( $last_run, 90000 ), // ~25 hours threshold.
			'human_name' => __( 'Scheduled Automation Tasks', 'doublescale' ),
			'next_run'   => $next_run ? human_time_diff( $next_run, time() ) : __( 'Unknown', 'doublescale' ),
			'last_run'   => $last_run ? human_time_diff( strtotime( $last_run ), time() ) . ' ' . __( 'ago', 'doublescale' ) : __( 'Never', 'doublescale' ),
			'interval'   => DAY_IN_SECONDS,
		);

		// Server information
		$memory_limit  = $this->get_memory_limit_in_mb();
		$memory_usage  = memory_get_usage( true ) / 1024 / 1024;
		$usage_percent = ( $memory_usage / $memory_limit ) * 100;

		$has_server_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$site_url        = site_url();
		$cron_url        = add_query_arg( 'doing_wp_cron', '', $site_url . '/wp-cron.php' );

		return new WP_REST_Response(
			array(
				'cron_events' => $events,
				'server'      => array(
					'memory_limit'       => $memory_limit . 'MB',
					'usage_percent'      => round( $usage_percent, 2 ),
					'max_execution_time' => ini_get( 'max_execution_time' ) . ' seconds',
					'has_server_cron'    => $has_server_cron,
					'cron_url'           => $cron_url,
					'site_path'          => ABSPATH,
				),
			),
			200
		);
	}

	/**
	 * Check if task is overdue
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $last_run Last run timestamp.
	 * @param int         $threshold Seconds threshold.
	 * @return bool
	 */
	private function is_task_overdue( $last_run, $threshold ) {
		if ( ! $last_run ) {
			return false; // Never ran, not overdue yet
		}

		$last_run_timestamp = strtotime( $last_run . ' UTC' );
		if ( ! $last_run_timestamp ) {
			return false; // Invalid datetime format
		}

		$time_since_last_run = time() - $last_run_timestamp;

		return $time_since_last_run > $threshold;
	}

	/**
	 * Get memory limit in MB
	 *
	 * @since 1.0.0
	 *
	 * @return int Memory limit in megabytes.
	 */
	private function get_memory_limit_in_mb() {
		$memory_limit = ini_get( 'memory_limit' );

		if ( preg_match( '/^(\d+)(.)$/', $memory_limit, $matches ) ) {
			if ( 'G' === $matches[2] ) {
				return $matches[1] * 1024;
			} elseif ( 'M' === $matches[2] ) {
				return $matches[1];
			} elseif ( 'K' === $matches[2] ) {
				return $matches[1] / 1024;
			}
		}

		return 128; // Default fallback
	}

	/**
	 * Manually run a cron job
	 *
	 * Triggers a scheduled task immediately for debugging or manual execution.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_cron_manually( $request ) {
		$hook = $request->get_param( 'hook' );

		// Whitelist of allowed hooks.
		$valid_hooks = array(
			'doublescale_campaigns_doublescale_email_campaigns' => __( 'Scheduled Email Sending Tasks', 'doublescale' ),
			'doublescale_daily_doublescale_daily3' => __( 'Scheduled Automation Tasks', 'doublescale' ),
			'doublescale_daily_doublescale_daily4' => __( 'Daily Cleanup Tasks', 'doublescale' ),
		);

		// Add Pro hooks if SMS campaign processing is available.
		if ( class_exists( \DoubleScale\Pro\Modules\Campaigns\Sms\SmsProcessing::class ) ) {
			$valid_hooks['doublescale_campaigns_doublescale_sms_campaigns'] = __( 'Scheduled Sms Sending Tasks', 'doublescale' );
			// WhatsApp not shipping in this version.
			// $valid_hooks['doublescale_campaigns_doublescale_whatsapp_campaigns'] = __( 'Scheduled WhatsApp Sending Tasks', 'doublescale');
		}

		// Email sequences not shipping in this version.
		// $valid_hooks['doublescale_campaigns_doublescale_email_sequences'] = __( 'Scheduled Email Processing', 'doublescale');

		if ( ! isset( $valid_hooks[ $hook ] ) ) {
			return new WP_Error(
				'invalid_hook',
				__( 'The provided hook name is not valid', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		// Trigger the action immediately
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook name is validated against allowlist.
			do_action( $hook );
		} catch ( \Exception $e ) {
			return new WP_Error(
				'cron_execution_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to run task: %s', 'doublescale' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		} catch ( \Throwable $e ) {
			// Catch PHP 7+ errors as well
			return new WP_Error(
				'cron_execution_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to run task: %s', 'doublescale' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: task name */
					__( 'Successfully ran %s', 'doublescale' ),
					$valid_hooks[ $hook ]
				),
			),
			200
		);
	}
}
