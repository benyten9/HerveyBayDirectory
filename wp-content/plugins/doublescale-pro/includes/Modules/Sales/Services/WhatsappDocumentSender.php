<?php
/**
 * Sends sales documents over WhatsApp through a configured provider.
 *
 * @package DoubleScale\Pro\Modules\Sales
 */

namespace DoubleScale\Pro\Modules\Sales\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Campaigns\Services\WhatsappConversationWindow;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Inbox\Services\MessageProviderRegistry;
use DoubleScale\Core\Constants\CampaignChannel;
use WP_Error;

/**
 * WhatsappDocumentSender.
 *
 * Backs the free `doublescale_sales_whatsapp_send` filter. Free returns null
 * from that filter, which surfaces as "automatic sending unavailable"; this
 * class is what makes the automatic option appear at all.
 */
final class WhatsappDocumentSender {

	/**
	 * Hook the sender into free's dispatch filter.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'doublescale_sales_whatsapp_send', array( __CLASS__, 'send' ), 10, 4 );
		add_filter( 'doublescale_sales_whatsapp_auto_available', array( __CLASS__, 'is_available' ) );
	}

	/**
	 * Whether a configured WhatsApp provider exists.
	 *
	 * Drives the "Send automatically" button in the admin UI, so it must not
	 * throw when the Inbox module is absent.
	 *
	 * @param bool $available Incoming value from free.
	 * @return bool
	 */
	public static function is_available( $available = false ): bool {
		unset( $available );

		return null !== self::resolve_provider();
	}

	/**
	 * Send a document link over WhatsApp.
	 *
	 * @param true|WP_Error|null $result   Result from an earlier filter callback.
	 * @param string             $type     Document type.
	 * @param object             $document Document model.
	 * @param array              $payload  Share payload (phone, text, url, link).
	 * @return true|WP_Error|null
	 */
	public static function send( $result, string $type, object $document, array $payload ) {
		unset( $type );

		// Another provider already handled it.
		if ( null !== $result ) {
			return $result;
		}

		$provider = self::resolve_provider();
		if ( null === $provider ) {
			return null;
		}

		$contact = self::contact_of( $document );
		if ( ! $contact instanceof ContactModel ) {
			return new WP_Error(
				'whatsapp_no_contact',
				__( 'This document has no linked contact to message.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$channel = self::channel();

		// Meta rejects free-form sends outside the 24h window; fail loudly rather
		// than silently dropping the message.
		if ( $provider->requires_template( $channel ) ) {
			$closed = self::conversation_window_error( (int) $contact->id );
			if ( $closed instanceof WP_Error ) {
				return $closed;
			}
		}

		$data = array(
			'To'                 => (string) $payload['phone'],
			'Body'               => (string) $payload['text'],
			'is_session_message' => true,
		);

		$webhook_url = $provider->get_webhook_url( $channel );
		if ( $webhook_url ) {
			$data['StatusCallback'] = $webhook_url;
		}

		try {
			$sent = $provider->send_message( $channel, $data, $contact );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'whatsapp_send_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		if ( empty( $sent['success'] ) ) {
			return new WP_Error(
				'whatsapp_send_failed',
				! empty( $sent['error'] )
					? (string) $sent['error']
					: __( 'The WhatsApp provider rejected the message.', 'doublescale' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}

	/**
	 * Resolve a configured WhatsApp provider, if any.
	 *
	 * @return \DoubleScale\Pro\Modules\Inbox\MessageProviderInterface|null
	 */
	private static function resolve_provider() {
		if ( ! class_exists( MessageProviderRegistry::class ) ) {
			return null;
		}

		try {
			$provider = MessageProviderRegistry::instance()->get_provider( self::channel() );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! $provider || ! $provider->is_configured() ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Error describing a closed 24h conversation window, or null when open.
	 *
	 * @param int $contact_id Contact id.
	 * @return WP_Error|null
	 */
	private static function conversation_window_error( int $contact_id ): ?WP_Error {
		if ( ! class_exists( WhatsappConversationWindow::class ) ) {
			return null;
		}

		$window = WhatsappConversationWindow::check( $contact_id );
		if ( ! empty( $window['active'] ) ) {
			return null;
		}

		$reason = isset( $window['reason'] ) && 'no_inbound_messages' === $window['reason']
			? __( 'This contact has never messaged you on WhatsApp, so a free-form message cannot be delivered. Share the link manually instead.', 'doublescale' )
			: __( 'The 24-hour WhatsApp conversation window has expired. Share the link manually instead.', 'doublescale' );

		return new WP_Error(
			'conversation_window_closed',
			$reason,
			array(
				'status'            => 400,
				'window'            => $window,
				'requires_template' => true,
			)
		);
	}

	/**
	 * Channel slug used by the provider registry.
	 *
	 * @return string
	 */
	private static function channel(): string {
		return class_exists( CampaignChannel::class ) && defined( CampaignChannel::class . '::STR_WHATSAPP' )
			? CampaignChannel::STR_WHATSAPP
			: 'whatsapp';
	}

	/**
	 * Contact attached to a document.
	 *
	 * @param object $document Document model.
	 * @return ContactModel|null
	 */
	private static function contact_of( object $document ): ?ContactModel {
		if ( method_exists( $document, 'loadMissing' ) ) {
			$document->loadMissing( 'contact' );
		}

		$contact = isset( $document->contact ) ? $document->contact : null;

		return $contact instanceof ContactModel ? $contact : null;
	}
}
