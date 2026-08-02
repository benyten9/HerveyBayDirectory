<?php
/**
 * Message Provider Interface
 * Defines contract for all messaging providers (Sms, WhatsApp, etc.)
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * Contract that all message providers must implement.
 *
 * @since 1.0.0
 */
interface MessageProviderInterface {

	/**
	 * Send message via specified channel.
	 *
	 * @param string        $channel Channel type ('sms', 'whatsapp', 'voice', etc.)
	 * @param array         $data Message data including 'Body', 'To', and optional 'StatusCallback'
	 * @param ContactModel $contact Contact model for context
	 * @return array Result array with keys: success, message_id, error, metadata
	 */
	public function send_message( string $channel, array $data, ContactModel $contact ): array;

	/**
	 * @return string Provider identifier (e.g., 'twilio', 'vonage', 'aws_sns')
	 */
	public function get_provider_slug(): string;

	/**
	 * @return bool True if provider has valid credentials and is ready to send messages
	 */
	public function is_configured(): bool;

	/**
	 * @param string $channel Channel type ('sms', 'whatsapp', 'voice', etc.)
	 * @return bool True if provider supports the channel
	 */
	public function supports_channel( string $channel ): bool;

	/**
	 * @param string $channel Channel type ('sms', 'whatsapp')
	 * @return string|null Webhook URL for this provider/channel, or null if not supported
	 */
	public function get_webhook_url( string $channel ): ?string;

	/**
	 * @return string Human-readable provider name (e.g., 'Twilio', 'Vonage Sms')
	 */
	public function get_provider_name(): string;

	/**
	 * Process webhook from provider.
	 *
	 * @param string $channel Channel type ('sms', 'whatsapp')
	 * @param array  $webhook_data Raw webhook data from provider ($_POST or $_GET)
	 * @return array Standardized webhook result
	 */
	public function process_webhook( string $channel, array $webhook_data ): array;
}
