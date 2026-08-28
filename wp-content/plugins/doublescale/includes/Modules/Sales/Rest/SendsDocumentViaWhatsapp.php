<?php
/**
 * Shared WhatsApp share handling for the sales document REST controllers.
 *
 * @package DoubleScale\Modules\Sales
 */

namespace DoubleScale\Modules\Sales\Rest;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Sales\Services\DocumentShareLink;
use WP_Error;
use WP_REST_Request;

/**
 * SendsDocumentViaWhatsapp trait.
 *
 * Each controller keeps its own model lookup, ownership and status guards —
 * those differ per document type. Everything downstream of them is identical,
 * so it lives here.
 */
trait SendsDocumentViaWhatsapp {

	/**
	 * Read the request body shared by both send channels.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{message: string, mode: string, phone: string}
	 */
	protected function read_whatsapp_params( $request ): array {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$mode = isset( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : 'link';

		return array(
			'message' => isset( $params['message'] ) ? sanitize_textarea_field( (string) $params['message'] ) : '',
			'mode'    => 'auto' === $mode ? 'auto' : 'link',
			'phone'   => isset( $params['phone'] ) ? preg_replace( '/\D+/', '', (string) $params['phone'] ) : '',
		);
	}

	/**
	 * Guard that the customer-facing page for this document type exists.
	 *
	 * @param string $type       Document type.
	 * @param string $error_code Error code matching the email-send guard.
	 * @param string $message    Error message.
	 * @return WP_Error|null
	 */
	protected function require_public_page( string $type, string $error_code, string $message ): ?WP_Error {
		if ( DocumentShareLink::has_public_page( $type ) ) {
			return null;
		}

		return new WP_Error( $error_code, $message, array( 'status' => 400 ) );
	}

	/**
	 * Build the share payload, honouring an admin-supplied recipient override.
	 *
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @param array{message: string, mode: string, phone: string} $params Request params.
	 * @return array{phone: string, text: string, url: string, link: string}
	 */
	protected function build_whatsapp_payload( object $document, string $type, array $params ): array {
		$payload = DocumentShareLink::build( $document, $type, $params['message'] );

		if ( '' !== $params['phone'] ) {
			$payload['phone'] = $params['phone'];
			$payload['link']  = DocumentShareLink::deep_link( $payload );
		}

		return $payload;
	}

	/**
	 * Dispatch an automatic (server-side) WhatsApp send.
	 *
	 * Free returns null from the filter, so automatic sending reports as
	 * unavailable unless Pro registers a configured provider.
	 *
	 * @param object $document Document model.
	 * @param string $type     Document type.
	 * @param array  $payload  Share payload.
	 * @return true|WP_Error
	 */
	protected function dispatch_whatsapp_auto( object $document, string $type, array $payload ) {
		if ( '' === (string) $payload['phone'] ) {
			return new WP_Error(
				'whatsapp_no_recipient',
				__( 'This contact has no WhatsApp number. Add one, or share the link manually.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Send a sales document over WhatsApp through a configured provider.
		 *
		 * @param true|WP_Error|null $result   Null when no provider handled the send.
		 * @param string             $type     Document type.
		 * @param object             $document Document model.
		 * @param array              $payload  Share payload.
		 */
		$result = apply_filters( 'doublescale_sales_whatsapp_send', null, $type, $document, $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			return new WP_Error(
				'whatsapp_auto_unavailable',
				__( 'Automatic WhatsApp sending is not available. Connect a WhatsApp provider, or share the link manually.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Response body for a prepared (not yet sent) share link.
	 *
	 * The document is deliberately left untouched: the admin still has to press
	 * send inside WhatsApp, so status is only advanced by the confirm step.
	 *
	 * @param array $payload Share payload.
	 * @return array{sent: bool, link: string, phone: string, text: string, url: string}
	 */
	protected function whatsapp_link_response( array $payload ): array {
		return array(
			'sent'  => false,
			'link'  => (string) $payload['link'],
			'phone' => (string) $payload['phone'],
			'text'  => (string) $payload['text'],
			'url'   => (string) $payload['url'],
		);
	}
}
