<?php
/**
 * Message Provider Validation Trait
 * Shared methods for validating message provider connections
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Traits;

use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use WP_Error;

/**
 * Trait MessageProviderValidation
 *
 * Provides common methods for validating message provider connections.
 * Used by: Campaign Controller, Contact Controller, Integration Controller
 *
 * @since 1.0.0
 */
trait MessageProviderValidation {

	/**
	 * Validate provider connection for Sms/Whatsapp
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type ('sms', 'whatsapp').
	 * @return true|WP_Error True if provider is connected, WP_Error otherwise.
	 */
	protected function validate_provider_connection( $channel ) {
		$provider = MessageProviderRegistry::instance()->get_provider( $channel );

		if ( ! $provider ) {
			$provider_name = $this->get_default_provider_name( $channel );

			return new WP_Error(
				'provider_not_configured',
				sprintf(
					/* translators: 1: Channel name (Sms/Whatsapp), 2: Provider name (Twilio) */
					__( '%1$s provider (%2$s) is not configured. Please configure the integration in Settings > Integrations.', 'doublescale'),
					ucfirst( $channel ),
					$provider_name
				),
				array(
					'status'        => 400,
					'channel'       => $channel,
					'provider_name' => $provider_name,
					'help_link'     => admin_url( 'admin.php?page=doublescale#/settings/integrations' ),
				)
			);
		}

		if ( ! $provider->is_configured() ) {
			return new WP_Error(
				'provider_not_connected',
				sprintf(
					/* translators: 1: Channel name (Sms/Whatsapp), 2: Provider name */
					__( '%1$s provider (%2$s) is not connected. Please connect the integration in Settings > Integrations.', 'doublescale'),
					ucfirst( $channel ),
					$provider->get_provider_name()
				),
				array(
					'status'        => 400,
					'channel'       => $channel,
					'provider_name' => $provider->get_provider_name(),
					'provider_slug' => $provider->get_provider_slug(),
					'help_link'     => admin_url( 'admin.php?page=doublescale#/settings/integrations' ),
				)
			);
		}

		return true;
	}

	/**
	 * Get default provider name for channel
	 *
	 * Maps provider slugs to user-friendly display names.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type.
	 * @return string Provider name.
	 */
	protected function get_default_provider_name( $channel ) {
		$default_slug = MessageProviderRegistry::instance()->get_default_provider_slug( $channel );

		// Map common provider slugs to friendly names
		$provider_names = array(
			'twilio' => 'Twilio',
		);

		return $provider_names[ $default_slug ] ?? ucfirst( $default_slug );
	}
}

