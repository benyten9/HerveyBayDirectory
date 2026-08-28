<?php

/**
 * Class Rest_Integration_Controller
 * This class is responsible for handling the Integration REST Api
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Integrations\Rest;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Exception;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\Managers\IntegrationsManager;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use DoubleScale\Core\Constants\CampaignChannel;

/**
 * Rest Integration Controller
 */
class RestIntegrationController extends RestController {


	/**
	 * REST Base
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $rest_base = 'integrations';

	/**
	 * Register the routes for the objects of the controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Provider status check endpoint.
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/provider-status",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_provider_status' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => array(
						'channel' => array(
							'description' => __( 'Channel type to check', 'doublescale'),
							'type'        => 'string',
							'enum'        => array( 'sms', 'whatsapp' ),
							'required'    => false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/whatsapp-default-provider",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_whatsapp_default_provider' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_whatsapp_default_provider' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'args'                => array(
						'provider' => array(
							'description'       => __( 'WhatsApp provider slug', 'doublescale' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Dynamic integration slug route (catches /integrations/{slug})
		register_rest_route(
			$this->namespace,
			"/{$this->rest_base}/(?P<slug>[\w-]+)",
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'get_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'update_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);
	}

	/**
	 * Get item schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		 return array(
			 '$schema'    => 'http://json-schema.org/draft-04/schema#',
			 'title'      => 'integration',
			 'type'       => 'object',
			 'properties' => array(
				 'slug'     => array(
					 'description' => __( 'Integration Slug', 'doublescale'),
					 'type'        => 'string',
					 'readonly'    => true,
				 ),
				 'settings' => array(
					 'description' => __( 'Integration Settings', 'doublescale'),
					 'type'        => 'object',
					 'required'    => true,
					 'arg_options' => array(
						 'validate_callback' => array( $this, 'validate_item_settings' ),
					 ),
				 ),
			 ),
		 );
	}

	/**
	 * Validate the create item request
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value The value of the parameter.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param The parameter name.
	 *
	 * @return WP_Error|bool
	 */
	public function validate_item_settings( $value, $request, $param ) {
		try {
			// Allow empty settings for disconnection
			if ( empty( $value ) ) {
				return true;
			}

			$slug              = $request->get_param( 'slug' );
			$integration       = IntegrationsManager::instance()->get_integration( $slug );
			$attributes_schema = $integration->rest_controller->get_settings_schema();
			$validator         = rest_validate_value_from_schema( $value, $attributes_schema, $param );

			if ( is_wp_error( $validator ) ) {
				return $validator;
			}

			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get Integration
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get( WP_REST_Request $request ) {
		try {
			$slug        = $request->get_param( 'slug' );
			$integration = IntegrationsManager::instance()->get_integration( $slug );
			$settings    = $integration->get_settings();

			$schema     = $integration->rest_controller->get_settings_schema();
			$properties = $schema['properties'] ?? array();

			if ( ! empty( $properties ) ) {
				$frontend_settings = array();
				foreach ( array_keys( $properties ) as $key ) {
					if ( isset( $settings[ $key ] ) ) {
						$frontend_settings[ $key ] = $settings[ $key ];
					}
				}
				$settings = $frontend_settings;
			}

			return new WP_REST_Response(
				array(
					'settings'     => $settings,
					'is_connected' => $integration->is_connected(),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Update Integration
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function update( WP_REST_Request $request ) {
		try {
			$slug        = $request->get_param( 'slug' );
			$settings    = $request->get_param( 'settings' ) ?? array();
			$integration = IntegrationsManager::instance()->get_integration( $slug );

			// Skip validation if settings are empty (disconnecting)
			if ( ! empty( $settings ) ) {
				$validator = $integration->validate( $settings );
				if ( is_wp_error( $validator ) ) {
					return $validator;
				}
				if ( ! $validator ) {
					return new WP_Error( 'rest_invalid_request', __( 'Invalid settings.', 'doublescale'), array( 'status' => 400 ) );
				}
			}

			$integration->update_settings( $settings );

			return new WP_REST_Response(
				array(
					'settings'     => $settings,
					'is_connected' => $integration->is_connected(),
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rest_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
	}

	/**
	 * Get Permissions Check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function get_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Update Permissions Check
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return bool|WP_Error
	 */
	public function update_permissions_check( $request ) {
		return Permissions::has_crm_manager_access();
	}

	/**
	 * Get provider status for Sms/Whatsapp channels
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_REST_Response
	 */
	public function get_provider_status( $request ) {
		$channel = $request->get_param( 'channel' );

		// If no channel specified, return status for all messaging channels
		if ( empty( $channel ) ) {
			return new WP_REST_Response(
				array(
					'sms'      => $this->get_channel_provider_status( CampaignChannel::STR_SMS ),
					'whatsapp' => $this->get_channel_provider_status( CampaignChannel::STR_WHATSAPP ),
				),
				200
			);
		}

		// Return status for specific channel
		return new WP_REST_Response(
			$this->get_channel_provider_status( $channel ),
			200
		);
	}

	/**
	 * Get provider status for a specific channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type.
	 *
	 * @return array Provider status information
	 */
	private function get_channel_provider_status( $channel ) {
		$registry     = MessageProviderRegistry::instance();
		$provider     = $registry->get_provider( $channel );
		$default_slug = $registry->get_default_provider_slug( $channel );

		if ( ! $provider ) {
			return array(
				'connected'         => false,
				'provider_name'     => $this->get_default_provider_name( $channel ),
				'provider_slug'     => $default_slug,
				'selected_slug'     => $default_slug,
				'using_fallback'    => false,
				'requires_template' => CampaignChannel::STR_WHATSAPP === $channel,
				'error'             => sprintf(
					/* translators: %s: Provider name */
					__( '%s provider is not configured', 'doublescale'),
					$this->get_default_provider_name( $channel )
				),
				'help_link'         => admin_url( 'admin.php?page=doublescale#/integrations' ),
			);
		}

		$is_configured = $provider->is_configured();
		$resolved_slug = $provider->get_provider_slug();

		// The registry falls back to any configured provider when the selected
		// one is unusable. Surface that, otherwise messages silently leave
		// through a channel the user did not choose.
		$using_fallback = ( $default_slug && $resolved_slug !== $default_slug );
		$fallback_notice = null;

		if ( $using_fallback ) {
			$fallback_notice = sprintf(
				/* translators: 1: Selected provider name, 2: Provider actually used */
				__( '%1$s is selected but not connected, so messages are being sent through %2$s instead. Connect %1$s or change the default provider.', 'doublescale' ),
				$this->get_default_provider_name( $channel ),
				$provider->get_provider_name()
			);
		}

		$requires_template = method_exists( $provider, 'requires_template' )
			? (bool) $provider->requires_template( $channel )
			: true;

		return array(
			'connected'         => $is_configured,
			'provider_name'     => $provider->get_provider_name(),
			'provider_slug'     => $resolved_slug,
			'selected_slug'     => $default_slug,
			'using_fallback'    => $using_fallback,
			'fallback_notice'   => $fallback_notice,
			'requires_template' => $requires_template,
			'error'             => $is_configured ? null : sprintf(
				/* translators: %s: Provider name */
				__( '%s provider is not connected', 'doublescale'),
				$provider->get_provider_name()
			),
			'help_link'         => admin_url( 'admin.php?page=doublescale#/integrations' ),
		);
	}

	/**
	 * Get default provider name for channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type.
	 *
	 * @return string Provider name
	 */
	private function get_default_provider_name( $channel ) {
		$default_slug = MessageProviderRegistry::instance()->get_default_provider_slug( $channel );
		if ( ! $default_slug ) {
			return '';
		}

		$instance = MessageProviderRegistry::instance()->get_provider_by_slug( $default_slug );
		if ( $instance ) {
			return $instance->get_provider_name();
		}

		return ucfirst( str_replace( '-', ' ', (string) $default_slug ) );
	}

	/**
	 * Get default WhatsApp provider slug and available registered providers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_whatsapp_default_provider( $request ) {
		unset( $request );

		$registry = MessageProviderRegistry::instance();
		$slug     = $registry->get_default_provider_slug( CampaignChannel::STR_WHATSAPP );
		$choices  = $registry->get_providers_for_channel( CampaignChannel::STR_WHATSAPP );

		return new WP_REST_Response(
			array(
				'provider'             => $slug,
				'provider_name'        => $this->get_default_provider_name( CampaignChannel::STR_WHATSAPP ),
				'available_providers'  => $choices,
			),
			200
		);
	}

	/**
	 * Update default WhatsApp provider slug.
	 *
	 * Accepts any provider registered in MessageProviderRegistry that supports WhatsApp.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_whatsapp_default_provider( $request ) {
		$provider = sanitize_text_field( (string) $request->get_param( 'provider' ) );
		$registry = MessageProviderRegistry::instance();
		$instance = $registry->get_provider_by_slug( $provider );

		if ( ! $instance || ! $instance->supports_channel( CampaignChannel::STR_WHATSAPP ) ) {
			return new WP_Error(
				'invalid_provider',
				__( 'Selected provider is not a registered WhatsApp provider.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$registry->set_default_provider( CampaignChannel::STR_WHATSAPP, $provider );

		return new WP_REST_Response(
			array(
				'success'              => true,
				'provider'             => $provider,
				'provider_name'        => $instance->get_provider_name(),
				'available_providers'  => $registry->get_providers_for_channel( CampaignChannel::STR_WHATSAPP ),
			),
			200
		);
	}
}
