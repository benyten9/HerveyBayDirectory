<?php
/**
 * Message Provider Registry
 * Central registry for all message providers
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\Services;

use DoubleScale\Pro\Modules\Inbox\MessageProviderInterface;

/**
 * MessageProviderRegistry class
 *
 * Manages registration and retrieval of message providers.
 * Inspired by smtp's Mailers registry pattern.
 *
 * @since 1.0.0
 */
class MessageProviderRegistry {

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @var MessageProviderRegistry
	 */
	private static $instance;

	/**
	 * Registered providers
	 *
	 * @since 1.0.0
	 *
	 * @var MessageProviderInterface[]
	 */
	private $providers = array();

	/**
	 * Default provider slugs per channel
	 * Sms: Twilio (only provider)
	 * WhatsApp: Meta WhatsApp (only supported provider for WhatsApp)
	 *
	 * Note: Twilio WhatsApp support has been disabled. Only Meta WhatsApp is supported for WhatsApp messaging.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $default_providers = array(
		'sms'      => 'twilio',
		'whatsapp' => 'meta-whatsapp',
	);

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @return MessageProviderRegistry
	 */
	public static function instance(): MessageProviderRegistry {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor (singleton pattern)
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_default_providers();
	}

	/**
	 * Load default provider slugs from settings.
	 *
	 * @return void
	 */
	private function load_default_providers(): void {
		$whatsapp_provider = get_option( 'doublescale_default_whatsapp_provider', 'meta-whatsapp' );
		if ( is_string( $whatsapp_provider ) && '' !== $whatsapp_provider ) {
			$this->default_providers['whatsapp'] = $whatsapp_provider;
		}
	}

	/**
	 * Register a message provider
	 *
	 * @since 1.0.0
	 *
	 * @param MessageProviderInterface $provider Provider instance to register
	 * @return void
	 */
	public function register( MessageProviderInterface $provider): void {
		$slug = $provider->get_provider_slug();

		if ( isset( $this->providers[ $slug ] ) ) {
			doublescale_get_logger()->info(
				sprintf( 'Provider "%s" is already registered, overwriting', $slug ),
				array(
					'code'          => 'provider_already_registered',
					'provider_slug' => $slug,
				)
			);
		}

		$this->providers[ $slug ] = $provider;
	}

	/**
	 * Get provider for a specific channel
	 *
	 * Returns a configured provider for the channel. If a preferred provider is specified,
	 * it will be used if available and configured. Otherwise, falls back to:
	 * 1. The default provider for the channel (if configured)
	 * 2. Any other configured provider that supports the channel
	 *
	 * @since 1.0.0
	 *
	 * @param string      $channel Channel type ('sms', 'whatsapp')
	 * @param string|null $preferred_provider Optional provider slug
	 * @return MessageProviderInterface|null Provider instance or null if not available
	 */
	public function get_provider( string $channel, ?string $preferred_provider = null): ?MessageProviderInterface {
		// If preferred provider is specified, try it first
		if ( $preferred_provider ) {
			$provider = $this->get_configured_provider_by_slug( $preferred_provider, $channel );
			if ( $provider ) {
				return $provider;
			}
		}

		// Try the default provider for this channel
		$default_slug = $this->default_providers[ $channel ] ?? null;
		if ( $default_slug ) {
			$provider = $this->get_configured_provider_by_slug( $default_slug, $channel );
			if ( $provider ) {
				return $provider;
			}
		}

		// Fallback: Find any configured provider that supports this channel
		foreach ( $this->providers as $slug => $provider ) {
			if ( $provider->supports_channel( $channel ) && $provider->is_configured() ) {
				doublescale_get_logger()->debug(
					sprintf( 'Using fallback provider "%s" for channel: %s', $slug, $channel ),
					array(
						'code'          => 'fallback_provider_used',
						'provider_slug' => $slug,
						'channel'       => $channel,
					)
				);
				return $provider;
			}
		}

		// No configured provider found
		return null;
	}

	/**
	 * Get a configured provider by slug for a specific channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_slug Provider slug
	 * @param string $channel Channel type
	 * @return MessageProviderInterface|null Provider if found, supports channel, and is configured
	 */
	private function get_configured_provider_by_slug( string $provider_slug, string $channel ): ?MessageProviderInterface {
		if ( ! isset( $this->providers[ $provider_slug ] ) ) {
			return null;
		}

		$provider = $this->providers[ $provider_slug ];

		if ( ! $provider->supports_channel( $channel ) ) {
			return null;
		}

		if ( ! $provider->is_configured() ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Get provider by slug
	 *
	 * @since 1.0.0
	 *
	 * @param string $provider_slug Provider slug (e.g., 'twilio', 'vonage')
	 * @return MessageProviderInterface|null Provider instance or null if not found
	 */
	public function get_provider_by_slug( string $provider_slug ): ?MessageProviderInterface {
		return $this->providers[ $provider_slug ] ?? null;
	}

	/**
	 * Get all registered providers
	 *
	 * @since 1.0.0
	 *
	 * @return MessageProviderInterface[] Array of provider instances
	 */
	public function get_all_providers(): array {
		return $this->providers;
	}

	/**
	 * Get providers that support a channel, as UI/API-friendly choice rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type ('sms', 'whatsapp').
	 * @return array<int, array{slug: string, name: string, configured: bool, requires_template: bool}>
	 */
	public function get_providers_for_channel( string $channel ): array {
		$choices = array();

		foreach ( $this->providers as $provider ) {
			if ( ! $provider->supports_channel( $channel ) ) {
				continue;
			}

			$choices[] = array(
				'slug'              => $provider->get_provider_slug(),
				'name'              => $provider->get_provider_name(),
				'configured'        => $provider->is_configured(),
				'requires_template' => method_exists( $provider, 'requires_template' )
					? (bool) $provider->requires_template( $channel )
					: true,
			);
		}

		/**
		 * Filter WhatsApp/SMS provider choices exposed to the admin UI.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $choices Provider choice rows.
		 * @param string $channel Channel type.
		 */
		return apply_filters( 'doublescale_message_provider_choices', $choices, $channel );
	}

	/**
	 * Set default provider for a channel
	 * Future: This can be made dynamic via settings
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type
	 * @param string $provider_slug Provider slug
	 * @return void
	 */
	public function set_default_provider( string $channel, string $provider_slug): void {
		$this->default_providers[ $channel ] = $provider_slug;

		if ( 'whatsapp' === $channel ) {
			update_option( 'doublescale_default_whatsapp_provider', $provider_slug, false );
		}
	}

	/**
	 * Get default provider slug for a channel
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel Channel type
	 * @return string|null Provider slug or null if no default
	 */
	public function get_default_provider_slug( string $channel): ?string {
		return $this->default_providers[ $channel ] ?? null;
	}
}


