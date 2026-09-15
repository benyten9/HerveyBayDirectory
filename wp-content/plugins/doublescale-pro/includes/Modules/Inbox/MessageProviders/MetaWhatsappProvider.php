<?php
/**
 * Meta WhatsApp Message Provider
 *
 * Implements the message provider interface for Meta WhatsApp Business Api
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Inbox\MessageProviders;

use DoubleScale\Pro\Modules\Inbox\Abstracts\AbstractMessageProvider;
use DoubleScale\Core\Constants\CampaignChannel;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\Integrations\MetaWhatsapp\Api;
use DoubleScale\Core\Constants\MetaWhatsappErrorCodes;

defined( 'ABSPATH' ) || exit;

/**
 * MetaWhatsappProvider class
 */
class MetaWhatsappProvider extends AbstractMessageProvider {

	/**
	 * Provider slug
	 *
	 * @var string
	 */
	protected $provider_slug = 'meta-whatsapp';

	/**
	 * Provider name
	 *
	 * @var string
	 */
	protected $provider_name = 'Meta WhatsApp';

	/**
	 * Supported channels
	 *
	 * @var array
	 */
	protected $supported_channels = array( 'whatsapp' );

	/**
	 * Tracking classes for each channel
	 *
	 * @var array
	 */
	protected $tracking_classes = array(
		'whatsapp' => '\DoubleScale\Modules\Tracking\Whatsapp',
	);

	/**
	 * Api instance
	 *
	 * @var Api|null
	 */
	private $api = null;

	/**
	 * Meta WhatsApp requires approved templates outside the 24h session window.
	 *
	 * @param string $channel Channel type.
	 * @return bool
	 */
	public function requires_template( string $channel ): bool {
		return $this->supports_channel( $channel );
	}

	/**
	 * Send a message via Meta WhatsApp Api
	 *
	 * @param string                         $channel Channel type (whatsapp).
	 * @param array                          $data    Message data.
	 * @param ContactModel $contact Contact model.
	 *
	 * @return array Result array with success status.
	 */
	public function send_message( string $channel, array $data, ContactModel $contact ): array {
		try {
			if ( ! $this->supports_channel( $channel ) ) {
				return $this->error_result( "Meta provider does not support channel: $channel" );
			}

			$api = $this->get_api();
			if ( ! $api ) {
				return $this->error_result( __( 'Meta WhatsApp is not configured', 'doublescale') );
			}

			// Parse ContentSid format: "template_name:language_code"
			$content_sid       = $data['ContentSid'] ?? '';
			$has_content_sid   = ! empty( $content_sid ) && strpos( $content_sid, ':' ) !== false;
			$has_body          = ! empty( $data['Body'] );
			$is_session_msg    = ! empty( $data['is_session_message'] );
			$template_settings = array();

			if ( $has_content_sid ) {
				// Template message
				list( $template_name, $language ) = explode( ':', $content_sid, 2 );
				$template_settings                = $this->extract_template_settings( $data );

				// Meta rejects a media template sent without its header component
				// (error 132000), and that error says nothing about the cause. We
				// can detect the condition here, so fail with a message that names
				// it instead of spending a round-trip to be told something opaque.
				$validation_error = $this->validate_template_payload( $template_settings );
				if ( '' !== $validation_error ) {
					$this->log( 'error', 'Meta WhatsApp template payload is incomplete', array(
						'contact_id' => $contact->id ?? null,
						'template'   => $template_name,
						'reason'     => $validation_error,
					) );

					return $this->error_result( $validation_error );
				}

				$components = $this->build_components(
					$data['ContentVariables'] ?? array(),
					$template_settings
				);

				$result = $api->send_template_message(
					$data['To'],
					$template_name,
					$language,
					$components
				);

				$this->log( 'debug', 'Sending Meta WhatsApp template message', array(
					'contact_id' => $contact->id ?? null,
					'template'   => $template_name,
					'language'   => $language,
				) );

			} elseif ( $has_body && $is_session_msg ) {
				// Session message (within 24h window)
				$result = $api->send_text_message( $data['To'], $data['Body'] );

				$this->log( 'debug', 'Sending Meta WhatsApp session message', array(
					'contact_id'   => $contact->id ?? null,
					'body_preview' => substr( $data['Body'], 0, 50 ),
				) );
			} else {
				return $this->error_result(
					__( 'Whatsapp requires template (ContentSid) or session message (Body with 24h window)', 'doublescale')
				);
			}

			if ( $result['success'] ) {
				$message_id = $result['data']['messages'][0]['id'] ?? '';
				
				$this->log( 'debug', 'Meta WhatsApp message sent successfully', array(
					'message_id' => $message_id,
					'contact_id' => $contact->id ?? null,
				) );
				
				return $this->success_result( $message_id, array( 'provider' => 'meta-whatsapp' ) );
			}

			$error_code    = isset( $result['error_code'] ) ? (int) $result['error_code'] : 0;
			$error_message = $result['error'] ?? 'Unknown error';

			// Turn the opaque "(#133010) Account not registered" into actionable guidance.
			if ( $error_code && MetaWhatsappErrorCodes::is_registration_error( $error_code ) ) {
				$error_message = MetaWhatsappErrorCodes::get_error_message( $error_code );
			} elseif ( 131009 === $error_code ) {
				// Meta's 131009 never names the field. Replace it with the
				// button labels from this send so Reports can say "Track order"
				// instead of "Parameter value is not valid".
				$error_message = $this->explain_invalid_parameter( $template_settings, $result );
			}

			$this->log( 'error', 'Meta WhatsApp send failed', array(
				'error'      => $result['error'],
				'error_code' => $error_code,
				'contact_id' => $contact->id ?? null,
				'source'     => 'inbox-meta-whatsapp',
			) );

			return $this->error_result( $error_message, array( 'error_code' => $error_code ) );

		} catch ( \Exception $e ) {
			$this->log( 'error', 'Meta WhatsApp send exception', array(
				'error'      => $e->getMessage(),
				'contact_id' => $contact->id ?? null,
			) );
			return $this->error_result( $e->getMessage() );
		}
	}

	/**
	 * Build Meta template components from variables
	 *
	 * Supports both positional ({{1}}, {{2}}) and named ({{name}}, {{order}}) variables.
	 * - Positional: {"1": "John", "2": "Order #123"} - sorted numerically
	 * - Named: {"name": "John", "order": "Order #123"} - includes parameter_name
	 *
	 * A template's HEADER is a component in its own right, separate from BODY.
	 * Meta validates the component list against the approved template, so a
	 * template approved with an IMAGE/VIDEO/DOCUMENT header that is sent with a
	 * body component alone is rejected (error 132000) — the media template never
	 * reaches the recipient. The header is built from the template's own stored
	 * definition, so only templates that actually declare one gain a component.
	 *
	 * @param array|string $variables         Variables array or JSON string.
	 * @param array        $template_settings Stored template settings (components, header media).
	 *
	 * @return array Components array for Meta Api.
	 */
	private function build_components( $variables, array $template_settings = array() ): array {
		// Handle both JSON string and array
		if ( is_string( $variables ) ) {
			$variables = json_decode( $variables, true ) ?? array();
		}

		// Ensure we have an array at this point
		if ( ! is_array( $variables ) ) {
			$variables = array();
		}

		$header   = $this->build_header_component( $template_settings );
		$buttons  = $this->build_button_components( $template_settings );
		$carousel = $this->build_carousel_component( $template_settings );

		if ( $carousel ) {
			$buttons[] = $carousel;
		}

		// A catalog or image-only template carries a header and/or buttons with
		// no body variables at all. Returning early on empty variables would
		// drop those too — which is how a catalog send lost its action and came
		// back as "(#131008) Required parameter is missing".
		if ( empty( $variables ) ) {
			$components = $header ? array( $header ) : array();
			return array_merge( $components, $buttons );
		}

		$body = $this->build_body_component( $variables );

		if ( ! $body ) {
			$components = $header ? array( $header ) : array();
			return array_merge( $components, $buttons );
		}

		$components = array( $body );

		// Meta requires header before body.
		if ( $header ) {
			array_unshift( $components, $header );
		}

		// Buttons come last, after header and body.
		return array_merge( $components, $buttons );
	}

	/**
	 * Build the body component from resolved template variables.
	 *
	 * Shared by the template itself and by each carousel card, so a card's body
	 * follows exactly the same ordering and blank-value rules as the top level.
	 *
	 * @param array $variables Resolved variables (slot => value).
	 *
	 * @return array|null Body component, or null when there is nothing to send.
	 */
	private function build_body_component( array $variables ): ?array {
		if ( empty( $variables ) ) {
			return null;
		}

		// Detect if using positional or named variables
		$is_positional = true;
		foreach ( array_keys( $variables ) as $key ) {
			if ( ! is_numeric( $key ) ) {
				$is_positional = false;
				break;
			}
		}

		// Sort positional variables numerically (1, 2, 10 not 1, 10, 2)
		if ( $is_positional ) {
			uksort(
				$variables,
				function ( $a, $b ) {
					return (int) $a - (int) $b;
				}
			);
		}

		$parameters  = array();
		$blank_slots = array();

		foreach ( $variables as $key => $value ) {
			$text = (string) $value;

			// A merge tag that resolves to nothing (contact has no first name,
			// no city, an unset custom field) arrives here as ''. Optional
			// contact fields are normal input, so the send must not depend on
			// them being populated — but a blank parameter is still wrong to
			// emit: it renders as "Hi ," for the recipient, and Meta's own
			// count check (error 132000) means dropping the slot instead would
			// break the template. Substitute a single space: it keeps the slot,
			// keeps the value non-empty, and adds no invented content. A word
			// like "there" would inject English into templates the customer
			// wrote and approved in their own language.
			if ( '' === trim( $text ) ) {
				$blank_slots[] = $key;
				$text          = ' ';
			}

			$param = array(
				'type' => 'text',
				'text' => $text,
			);

			// Named variables require parameter_name in Meta Api
			if ( ! is_numeric( $key ) ) {
				$param['parameter_name'] = $key;
			}

			$parameters[] = $param;
		}

		// Without this, a blank-variable send is indistinguishable from any
		// other failure: the send-path log records template and language but
		// never the parameters.
		if ( ! empty( $blank_slots ) ) {
			$this->log(
				'warning',
				'Meta WhatsApp template variable resolved to an empty value',
				array(
					'slots' => $blank_slots,
				)
			);
		}

		if ( empty( $parameters ) ) {
			return null;
		}

		return array(
			'type'       => 'body',
			'parameters' => $parameters,
		);
	}

	/**
	 * Build the carousel component a CAROUSEL template requires.
	 *
	 * A carousel is a list of cards, each with its own header/body/buttons. The
	 * card's own `card_index` positions it, and every card must carry its media:
	 * Meta approves the carousel with sample images but returns none of them, so
	 * the sender supplies one per card.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array|null Carousel component, or null when the template has none.
	 */
	private function build_carousel_component( array $template_settings ): ?array {
		$declared = $this->get_declared_cards( $template_settings );

		if ( empty( $declared ) ) {
			return null;
		}

		$supplied = $template_settings['card_params'] ?? array();
		if ( is_string( $supplied ) ) {
			$supplied = json_decode( $supplied, true ) ?? array();
		}
		$supplied = is_array( $supplied ) ? $supplied : array();

		$cards = array();

		foreach ( $declared as $index => $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}

			$params = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();

			// A card is described by the same component vocabulary as the
			// template itself, so its pieces are built by the same methods.
			$card_settings = array(
				'components'    => $card['components'] ?? array(),
				'header_media'  => $params['header_media'] ?? array(),
				'button_params' => $params['button_params'] ?? array(),
			);

			$card_components = array();

			$header = $this->build_header_component( $card_settings );
			if ( $header ) {
				$card_components[] = $header;
			}

			$variables = $params['variables'] ?? array();
			if ( is_array( $variables ) && ! empty( $variables ) ) {
				$body = $this->build_body_component( $variables );
				if ( $body ) {
					$card_components[] = $body;
				}
			}

			foreach ( $this->build_button_components( $card_settings ) as $button ) {
				$card_components[] = $button;
			}

			$cards[] = array(
				'card_index' => (int) $index,
				'components' => $card_components,
			);
		}

		if ( empty( $cards ) ) {
			return null;
		}

		return array(
			'type'  => 'carousel',
			'cards' => $cards,
		);
	}

	/**
	 * Read the CAROUSEL cards declared by the approved template.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array Declared cards, in their approved order.
	 */
	private function get_declared_cards( array $template_settings ): array {
		$components = $template_settings['components'] ?? array();

		if ( is_string( $components ) ) {
			$components = json_decode( $components, true ) ?? array();
		}

		if ( ! is_array( $components ) ) {
			return array();
		}

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			if ( 'CAROUSEL' === strtoupper( (string) ( $component['type'] ?? '' ) ) ) {
				$cards = $component['cards'] ?? array();
				return is_array( $cards ) ? array_values( $cards ) : array();
			}
		}

		return array();
	}

	/**
	 * Build the button components a template's approved BUTTONS block requires.
	 *
	 * Meta validates the components array against the approved template. A
	 * button that carries a runtime value — a catalog action, a product list, a
	 * flow token, a coupon code, a dynamic URL suffix, a quick-reply payload —
	 * must be sent as its own component or the request is rejected with
	 * "(#131008) Required parameter is missing".
	 *
	 * Buttons that carry no runtime value (a static URL, a phone number) take no
	 * component at all: sending one for them is itself an error.
	 *
	 * `index` is the button's position in the **approved template**, not its
	 * position in the emitted array — a coupon button sitting second stays
	 * index 1 even when the first button emits nothing.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array Button components, empty when none are required.
	 */
	private function build_button_components( array $template_settings ): array {
		$buttons = $this->get_declared_buttons( $template_settings );

		if ( empty( $buttons ) ) {
			return array();
		}

		$supplied   = $this->get_button_params( $template_settings );
		$components = array();

		foreach ( $buttons as $index => $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			$sub_type   = strtoupper( (string) ( $button['type'] ?? '' ) );
			$params     = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();
			$parameters = $this->build_button_parameters( $sub_type, $button, $params );

			if ( null === $parameters ) {
				continue;
			}

			$components[] = array(
				'type'       => 'button',
				'sub_type'   => strtolower( $sub_type ),
				'index'      => (int) $index,
				'parameters' => $parameters,
			);
		}

		return $components;
	}

	/**
	 * Build the parameters for one declared button.
	 *
	 * @param string $sub_type Uppercase button type from the approved template.
	 * @param array  $button   The declared button definition.
	 * @param array  $params   Runtime values supplied for this button.
	 *
	 * @return array|null Parameters, or null when this button needs no component.
	 */
	private function build_button_parameters( string $sub_type, array $button, array $params ): ?array {
		switch ( $sub_type ) {
			case 'CATALOG':
				// The thumbnail is optional — Meta falls back to the first item
				// in the catalog — but the component itself is required.
				$action = array();
				if ( ! empty( $params['thumbnail_product_retailer_id'] ) ) {
					$action['thumbnail_product_retailer_id'] = (string) $params['thumbnail_product_retailer_id'];
				}

				return array(
					array(
						'type'   => 'action',
						'action' => $action,
					),
				);

			case 'MPM':
				$action = array();
				if ( ! empty( $params['thumbnail_product_retailer_id'] ) ) {
					$action['thumbnail_product_retailer_id'] = (string) $params['thumbnail_product_retailer_id'];
				}
				if ( ! empty( $params['sections'] ) && is_array( $params['sections'] ) ) {
					$action['sections'] = $params['sections'];
				}

				return array(
					array(
						'type'   => 'action',
						'action' => $action,
					),
				);

			case 'FLOW':
				// Meta generates a flow token when none is supplied, so the
				// component is still sent with whatever the caller provided.
				$action = array();
				if ( ! empty( $params['flow_token'] ) ) {
					$action['flow_token'] = (string) $params['flow_token'];
				}
				if ( ! empty( $params['flow_action_data'] ) && is_array( $params['flow_action_data'] ) ) {
					$action['flow_action_data'] = $params['flow_action_data'];
				}

				return array(
					array(
						'type'   => 'action',
						'action' => $action,
					),
				);

			case 'COPY_CODE':
				$coupon = isset( $params['coupon_code'] ) ? trim( (string) $params['coupon_code'] ) : '';
				if ( '' === $coupon ) {
					return null;
				}

				return array(
					array(
						'type'        => 'coupon_code',
						'coupon_code' => $coupon,
					),
				);

			case 'URL':
				// Only a URL with a {{n}} placeholder takes a parameter; a static
				// link must not be sent as a component.
				if ( ! $this->url_button_is_dynamic( $button ) ) {
					return null;
				}

				$text = isset( $params['text'] ) ? trim( (string) $params['text'] ) : '';
				if ( '' === $text ) {
					return null;
				}

				return array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				);

			case 'QUICK_REPLY':
				$payload = isset( $params['payload'] ) ? trim( (string) $params['payload'] ) : '';
				if ( '' === $payload ) {
					return null;
				}

				return array(
					array(
						'type'    => 'payload',
						'payload' => $payload,
					),
				);
		}

		// PHONE_NUMBER, VOICE_CALL and any future static button: nothing to send.
		return null;
	}

	/**
	 * Whether a URL button's link carries a {{n}} placeholder.
	 *
	 * @param array $button Declared button definition.
	 *
	 * @return bool
	 */
	private function url_button_is_dynamic( array $button ): bool {
		$url = (string) ( $button['url'] ?? '' );

		return (bool) preg_match( '/\{\{\s*[^}]+\s*\}\}/', $url );
	}

	/**
	 * Read the BUTTONS block declared by the approved template.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array Declared buttons, in their approved order.
	 */
	private function get_declared_buttons( array $template_settings ): array {
		$components = $template_settings['components'] ?? array();

		if ( is_string( $components ) ) {
			$components = json_decode( $components, true ) ?? array();
		}

		if ( ! is_array( $components ) ) {
			$components = array();
		}

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			if ( 'BUTTONS' === strtoupper( (string) ( $component['type'] ?? '' ) ) ) {
				$buttons = $component['buttons'] ?? array();
				return is_array( $buttons ) ? array_values( $buttons ) : array();
			}
		}

		$buttons = $template_settings['buttons'] ?? array();

		return is_array( $buttons ) ? array_values( $buttons ) : array();
	}

	/**
	 * Read the runtime values supplied for the template's buttons.
	 *
	 * Keyed by the button's index in the approved template.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array
	 */
	private function get_button_params( array $template_settings ): array {
		$params = $template_settings['button_params'] ?? array();

		if ( is_string( $params ) ) {
			$params = json_decode( $params, true ) ?? array();
		}

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Build the header component for a template that declares one.
	 *
	 * Meta expects the media itself, not just a flag: an IMAGE header needs
	 * `{"type":"image","image":{"link":...}}`. The media parameter key mirrors
	 * the parameter type, and DOCUMENT additionally carries a filename — without
	 * it WhatsApp shows the document with a blank name.
	 *
	 * Returns null when the template has no header, or when it declares a media
	 * header but no media was supplied: a half-built header component would be
	 * rejected by Meta just as surely as a missing one, and failing to attach
	 * media is better surfaced as a warning than as an opaque API error.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array|null Header component, or null when there is nothing valid to send.
	 */
	private function build_header_component( array $template_settings ): ?array {
		if ( empty( $template_settings ) ) {
			return null;
		}

		$format = $this->get_header_format( $template_settings );
		if ( '' === $format ) {
			return null;
		}

		if ( 'TEXT' === $format ) {
			return $this->build_text_header_component( $template_settings );
		}

		if ( 'LOCATION' === $format ) {
			return $this->build_location_header_component( $template_settings );
		}

		$media_types = array(
			'IMAGE'    => 'image',
			'VIDEO'    => 'video',
			'DOCUMENT' => 'document',
		);

		if ( ! isset( $media_types[ $format ] ) ) {
			return null;
		}

		$media_type = $media_types[ $format ];
		$media      = $template_settings['header_media'] ?? array();

		if ( ! is_array( $media ) ) {
			return null;
		}

		// A media header can be addressed either by public URL (link) or by an
		// uploaded media handle (id). Meta accepts exactly one of the two.
		//
		// The link is not necessarily Meta's own example: it is stored in the
		// template's settings column, which the templates REST controller writes
		// from request input without validating nested keys. Sanitize it before
		// sending it onward, as TwilioMessageProvider does for its media URLs —
		// esc_url_raw() also drops disallowed schemes, so a non-http value
		// becomes empty and is treated as "no media supplied" below.
		$link     = isset( $media['link'] ) ? esc_url_raw( trim( (string) $media['link'] ) ) : '';
		$media_id = isset( $media['id'] ) ? trim( (string) $media['id'] ) : '';

		if ( '' === $link && '' === $media_id ) {
			// send_message() already refuses this case with the contact and
			// template in hand, so this is only the low-level trace of it —
			// logging a second warning here would be an uncorrelated duplicate.
			$this->log(
				'debug',
				'Meta WhatsApp header media missing; send_message() reports the failure',
				array(
					'format' => $format,
				)
			);
			return null;
		}

		$media_payload = '' !== $media_id
			? array( 'id' => $media_id )
			: array( 'link' => $link );

		// Only documents use a filename; Meta ignores/rejects it elsewhere.
		if ( 'document' === $media_type ) {
			$filename = isset( $media['filename'] ) ? trim( (string) $media['filename'] ) : '';
			if ( '' !== $filename ) {
				$media_payload['filename'] = $filename;
			}
		}

		return array(
			'type'       => 'header',
			'parameters' => array(
				array(
					'type'      => $media_type,
					$media_type => $media_payload,
				),
			),
		);
	}

	/**
	 * Build a header component for a LOCATION header.
	 *
	 * The coordinates are supplied at send time, never at template approval, so
	 * a location template always needs them from the caller.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array|null Header component, or null when coordinates are missing.
	 */
	private function build_location_header_component( array $template_settings ): ?array {
		$media = $template_settings['header_media'] ?? array();

		if ( ! is_array( $media ) ) {
			return null;
		}

		$latitude  = isset( $media['latitude'] ) ? trim( (string) $media['latitude'] ) : '';
		$longitude = isset( $media['longitude'] ) ? trim( (string) $media['longitude'] ) : '';

		// Both coordinates are required; name and address are optional labels.
		if ( '' === $latitude || '' === $longitude ) {
			return null;
		}

		$location = array(
			'latitude'  => $latitude,
			'longitude' => $longitude,
		);

		foreach ( array( 'name', 'address' ) as $key ) {
			if ( ! empty( $media[ $key ] ) ) {
				$location[ $key ] = (string) $media[ $key ];
			}
		}

		return array(
			'type'       => 'header',
			'parameters' => array(
				array(
					'type'     => 'location',
					'location' => $location,
				),
			),
		);
	}

	/**
	 * Build a header component for a TEXT header that has its own variable.
	 *
	 * Header variables are numbered independently of body variables — a template
	 * with {{1}} in both header and body needs a different value in each.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array|null Header component, or null when the header is static.
	 */
	private function build_text_header_component( array $template_settings ): ?array {
		$header_variables = $template_settings['header_variables'] ?? array();

		if ( is_string( $header_variables ) ) {
			$header_variables = json_decode( $header_variables, true ) ?? array();
		}

		// A TEXT header with no placeholder is static — Meta wants no component.
		if ( ! is_array( $header_variables ) || empty( $header_variables ) ) {
			return null;
		}

		uksort(
			$header_variables,
			function ( $a, $b ) {
				return is_numeric( $a ) && is_numeric( $b ) ? (int) $a - (int) $b : strcmp( (string) $a, (string) $b );
			}
		);

		$parameters = array();
		foreach ( $header_variables as $key => $value ) {
			$text = (string) $value;

			// Same reasoning as body parameters: a blank value renders broken but
			// dropping the slot breaks Meta's count check.
			if ( '' === trim( $text ) ) {
				$text = ' ';
			}

			$parameter = array(
				'type' => 'text',
				'text' => $text,
			);

			if ( ! is_numeric( $key ) ) {
				$parameter['parameter_name'] = $key;
			}

			$parameters[] = $parameter;
		}

		return array(
			'type'       => 'header',
			'parameters' => $parameters,
		);
	}

	/**
	 * Collect the template-shape keys the header builder needs from message data.
	 *
	 * Senders pass these alongside ContentSid/ContentVariables. Normalizing here
	 * keeps every send path (individual, campaign, automation) on one contract.
	 *
	 * @param array $data Message data as handed to send_message().
	 *
	 * @return array Template settings for build_components().
	 */
	private function extract_template_settings( array $data ): array {
		$settings = $data['TemplateSettings'] ?? array();

		if ( is_string( $settings ) ) {
			$settings = json_decode( $settings, true ) ?? array();
		}

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Explicit top-level keys win: a caller attaching media for this one send
		// should override whatever the cached template definition carried.
		//
		// `button_params` belongs here for the same reason as the rest: a coupon
		// code, catalog section or dynamic-URL suffix is a per-send value, and
		// get_button_params() reads it straight off these settings. Omitting it
		// dropped any top-level value silently, so the template reached Meta with
		// its buttons unfilled and came back as "(#131008) Required parameter is
		// missing" — naming a parameter the caller had in fact supplied.
		foreach ( array( 'components', 'header_media', 'header_variables', 'button_params', 'card_params' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$settings[ $key ] = $data[ $key ];
			}
		}

		// The body values travel separately, but validation has to see them
		// against the template's declared slots to catch a send that would go
		// out with fewer parameters than the approved template expects.
		if ( isset( $data['ContentVariables'] ) ) {
			$settings['content_variables'] = $data['ContentVariables'];
		}

		return $settings;
	}

	/**
	 * Validate a template payload before it is sent to Meta.
	 *
	 * Meta answers a malformed interactive template with "(#131008) Required
	 * parameter is missing", which names neither the component nor the field.
	 * Every condition checked here is one we can detect locally, so the caller
	 * gets a message describing what to fix instead of an opaque API error.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Error message, or '' when the send may proceed.
	 */
	private function validate_template_payload( array $template_settings ): string {
		if ( empty( $template_settings ) ) {
			return '';
		}

		$format = $this->get_header_format( $template_settings );

		if ( 'LOCATION' === $format ) {
			$media     = $template_settings['header_media'] ?? array();
			$media     = is_array( $media ) ? $media : array();
			$latitude  = isset( $media['latitude'] ) ? trim( (string) $media['latitude'] ) : '';
			$longitude = isset( $media['longitude'] ) ? trim( (string) $media['longitude'] ) : '';

			if ( '' === $latitude || '' === $longitude ) {
				return __( 'This WhatsApp template has a location header, so it needs a latitude and longitude before it can be sent.', 'doublescale' );
			}
		}

		$missing_variables = $this->get_missing_body_variables( $template_settings );
		if ( ! empty( $missing_variables ) ) {
			return sprintf(
				/* translators: %s: comma-separated list of variable slots, e.g. "1, 2". */
				__( 'This WhatsApp template needs a value for every variable it declares, but %s was not supplied. Fill each template variable before sending.', 'doublescale' ),
				implode( ', ', $missing_variables )
			);
		}

		$missing_media = $this->get_missing_header_media_format( $template_settings );
		if ( $missing_media ) {
			return sprintf(
				/* translators: %s: header type — image, video or document. */
				__( 'This WhatsApp template needs a %s in its header, but none was supplied. Re-sync your templates in Settings > Integrations, or attach media to this send.', 'doublescale' ),
				strtolower( $missing_media )
			);
		}

		$reachability_error = $this->validate_media_is_reachable( $template_settings );
		if ( '' !== $reachability_error ) {
			return $reachability_error;
		}

		$carousel_error = $this->validate_carousel_payload( $template_settings );
		if ( '' !== $carousel_error ) {
			return $carousel_error;
		}

		return $this->validate_button_payload( $template_settings );
	}

	/**
	 * Check that a header media URL actually serves the media it claims.
	 *
	 * Meta fetches the file itself and does not report a failure: it accepts the
	 * request, cannot retrieve the URL, and delivers the message **without** the
	 * media. The recipient sees a broken message and the sender is told nothing,
	 * so a dead link has to be caught here or it fails silently.
	 *
	 * Only URLs are probed — an uploaded media id has nothing to fetch.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Error message, or '' when the media is reachable.
	 */
	private function validate_media_is_reachable( array $template_settings ): string {
		$media = $template_settings['header_media'] ?? array();

		if ( ! is_array( $media ) || ! empty( $media['id'] ) ) {
			return '';
		}

		$link = isset( $media['link'] ) ? esc_url_raw( trim( (string) $media['link'] ) ) : '';
		if ( '' === $link ) {
			return '';
		}

		$expected = strtolower( (string) ( $media['type'] ?? '' ) );
		if ( ! in_array( $expected, array( 'image', 'video', 'document' ), true ) ) {
			return '';
		}

		$response = wp_remote_request(
			$link,
			array(
				'method'      => 'HEAD',
				'timeout'     => 10,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return sprintf(
				/* translators: %s: the media URL. */
				__( 'The media URL could not be opened: %s. Check the link and try again.', 'doublescale' ),
				$link
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: the media URL. */
				__( 'The media URL could not be opened (HTTP %1$d): %2$s. Check the link and try again.', 'doublescale' ),
				$status,
				$link
			);
		}

		// A link that returns a web page instead of a file is the common
		// mistake: a share page rather than the file itself.
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( '' !== $content_type ) {
			$family = 'document' === $expected ? '' : $expected . '/';

			if ( '' !== $family && 0 !== strpos( $content_type, $family ) ) {
				return sprintf(
					/* translators: 1: expected media type, 2: content type the URL returned. */
					__( 'That URL is not %1$s — it returns %2$s. Link directly to the file, not to a web page.', 'doublescale' ),
					$expected,
					$content_type
				);
			}
		}

		return '';
	}

	/**
	 * Validate that every carousel card carries the media its header declares.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Error message, or '' when the cards are complete.
	 */
	private function validate_carousel_payload( array $template_settings ): string {
		$cards = $this->get_declared_cards( $template_settings );

		if ( empty( $cards ) ) {
			return '';
		}

		$supplied = $template_settings['card_params'] ?? array();
		if ( is_string( $supplied ) ) {
			$supplied = json_decode( $supplied, true ) ?? array();
		}
		$supplied = is_array( $supplied ) ? $supplied : array();

		foreach ( $cards as $index => $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}

			$params = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();

			$missing = $this->get_missing_header_media_format(
				array(
					'components'   => $card['components'] ?? array(),
					'header_media' => $params['header_media'] ?? array(),
				)
			);

			if ( $missing ) {
				return sprintf(
					/* translators: 1: card number, 2: media type — image, video or document. */
					__( 'This WhatsApp carousel template needs a %2$s for card %1$d. Every card must have its own media before the message can be sent.', 'doublescale' ),
					(int) $index + 1,
					strtolower( $missing )
				);
			}
		}

		return '';
	}

	/**
	 * Validate the runtime values supplied for a template's buttons.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Error message, or '' when the buttons are complete.
	 */
	private function validate_button_payload( array $template_settings ): string {
		$buttons = $this->get_declared_buttons( $template_settings );

		if ( empty( $buttons ) ) {
			return '';
		}

		$supplied = $this->get_button_params( $template_settings );

		foreach ( $buttons as $index => $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			$sub_type = strtoupper( (string) ( $button['type'] ?? '' ) );
			$params   = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();

			if ( 'COPY_CODE' === $sub_type && empty( $params['coupon_code'] ) ) {
				return __( 'This WhatsApp template has a copy-code button, so it needs a coupon code before it can be sent.', 'doublescale' );
			}

			if ( 'MPM' === $sub_type && empty( $params['sections'] ) ) {
				return __( 'This WhatsApp template shows a product list, so it needs at least one section of products before it can be sent.', 'doublescale' );
			}

			if ( 'URL' === $sub_type && $this->url_button_is_dynamic( $button ) ) {
				$text  = isset( $params['text'] ) ? trim( (string) $params['text'] ) : '';
				$label = $this->button_label( $button );

				if ( '' === $text ) {
					return sprintf(
						/* translators: %s: WhatsApp button label, e.g. Track order. */
						__( 'The "%s" button needs the value that completes its URL, not a full link.', 'doublescale' ),
						$label
					);
				}

				if ( $this->url_suffix_looks_like_absolute_url( $text ) ) {
					return sprintf(
						/* translators: %s: WhatsApp button label, e.g. Track order. */
						__( 'The "%s" button needs the suffix that completes the approved link, not the full URL.', 'doublescale' ),
						$label
					);
				}

				if ( preg_match( '/\s/', $text ) ) {
					return sprintf(
						/* translators: %s: WhatsApp button label, e.g. Track order. */
						__( 'The "%s" button cannot contain spaces or line breaks.', 'doublescale' ),
						$label
					);
				}
			}
		}

		return '';
	}

	/**
	 * The label the editor shows for a declared button.
	 *
	 * @param array $button Declared button definition.
	 * @return string
	 */
	private function button_label( array $button ): string {
		$text = trim( (string) ( $button['text'] ?? '' ) );

		return '' !== $text ? $text : strtoupper( (string) ( $button['type'] ?? 'button' ) );
	}

	/**
	 * Whether a URL-button value is a full address rather than a suffix.
	 *
	 * Meta concatenates this value onto the approved URL. A scheme (`https://`)
	 * here is what produces 131009, and also the broken
	 * `https://example.com/track/https://…` links.
	 *
	 * @param string $value Runtime suffix.
	 * @return bool
	 */
	private function url_suffix_looks_like_absolute_url( string $value ): bool {
		return (bool) preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value );
	}

	/**
	 * Labels of dynamic URL buttons whose suffix Meta will reject as 131009.
	 *
	 * @param array $template_settings Stored template settings.
	 * @return array<int, string>
	 */
	private function invalid_url_suffix_labels( array $template_settings ): array {
		$labels   = array();
		$supplied = $this->get_button_params( $template_settings );

		foreach ( $this->get_declared_buttons( $template_settings ) as $index => $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			if ( 'URL' !== strtoupper( (string) ( $button['type'] ?? '' ) ) ) {
				continue;
			}

			if ( ! $this->url_button_is_dynamic( $button ) ) {
				continue;
			}

			$params = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();
			$text   = isset( $params['text'] ) ? trim( (string) $params['text'] ) : '';

			if ( '' === $text ) {
				continue;
			}

			if ( $this->url_suffix_looks_like_absolute_url( $text ) || preg_match( '/\s/', $text ) ) {
				$labels[] = $this->button_label( $button );
			}
		}

		return $labels;
	}

	/**
	 * Labels of interactive buttons that actually received a value on this send.
	 *
	 * @param array $template_settings Stored template settings.
	 * @return array<int, string>
	 */
	private function filled_interactive_button_labels( array $template_settings ): array {
		$labels   = array();
		$supplied = $this->get_button_params( $template_settings );

		foreach ( $this->get_declared_buttons( $template_settings ) as $index => $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			$params = isset( $supplied[ $index ] ) && is_array( $supplied[ $index ] )
				? $supplied[ $index ]
				: array();
			$filled = false;

			foreach ( array( 'text', 'coupon_code', 'thumbnail_product_retailer_id', 'flow_token' ) as $key ) {
				if ( '' !== trim( (string) ( $params[ $key ] ?? '' ) ) ) {
					$filled = true;
					break;
				}
			}

			if ( ! empty( $params['sections'] ) ) {
				$filled = true;
			}

			if ( $filled ) {
				$labels[] = $this->button_label( $button );
			}
		}

		return $labels;
	}

	/**
	 * Replace Meta's unnamed 131009 with the buttons this send actually filled.
	 *
	 * @param array $template_settings Stored template settings.
	 * @param array $result            Provider/API result.
	 * @return string
	 */
	private function explain_invalid_parameter( array $template_settings, array $result ): string {
		$suspects = $this->invalid_url_suffix_labels( $template_settings );
		if ( empty( $suspects ) ) {
			$suspects = $this->filled_interactive_button_labels( $template_settings );
		}

		$details = '';
		if ( isset( $result['data']['error']['error_data']['details'] ) && is_string( $result['data']['error']['error_data']['details'] ) ) {
			$details = trim( $result['data']['error']['error_data']['details'] );
		}

		if ( ! empty( $suspects ) ) {
			$message = sprintf(
				/* translators: %s: comma-separated WhatsApp button labels, e.g. Track order. */
				__( 'Meta rejected the value for %s. For a link button, enter only the suffix that completes the approved URL, not a full http(s) address.', 'doublescale' ),
				implode( ', ', $suspects )
			);

			if ( '' !== $details ) {
				$message .= ' ' . $details;
			}

			return $message;
		}

		if ( '' !== $details ) {
			return sprintf(
				/* translators: %s: Meta error details. */
				__( 'Meta rejected a parameter value on this template: %s', 'doublescale' ),
				$details
			);
		}

		return __( 'Meta rejected a parameter value on this template. For a link button, enter only the suffix that completes the approved URL, not a full http(s) address.', 'doublescale' );
	}

	/**
	 * Detect a template that needs header media when none is available.
	 *
	 * Meta rejects such a send with error 132000, whose message describes a
	 * component-count mismatch and never mentions media — so callers cannot act
	 * on it. Detecting the condition before the request lets the send fail with a
	 * reason the user can actually do something about.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Format needing media (IMAGE/VIDEO/DOCUMENT), or '' when fine.
	 */
	/**
	 * Find body variables the template declares but the send did not supply.
	 *
	 * Meta counts parameters against the approved template: a template with
	 * three slots sent with two — or with none — is rejected as error 132000,
	 * "component count mismatch", which names neither the template nor the
	 * missing slots. The contact dialog makes a user fill every variable before
	 * sending, but an automation step can be saved with its variables empty, so
	 * this is the path where an incomplete send actually reaches the API.
	 *
	 * A slot that resolves to an empty string is *not* missing — that is the
	 * blank-merge-tag case, which build_body_component() handles by sending a
	 * space so the slot survives. Only a slot with no entry at all counts here.
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return array Missing slot keys, empty when the send is complete.
	 */
	private function get_missing_body_variables( array $template_settings ): array {
		$declared = $template_settings['variables'] ?? array();

		if ( is_string( $declared ) ) {
			$declared = json_decode( $declared, true ) ?? array();
		}

		if ( ! is_array( $declared ) || empty( $declared ) ) {
			return array();
		}

		$supplied = $template_settings['content_variables'] ?? array();

		if ( is_string( $supplied ) ) {
			$supplied = json_decode( $supplied, true ) ?? array();
		}

		if ( ! is_array( $supplied ) ) {
			$supplied = array();
		}

		$missing = array();

		foreach ( array_keys( $declared ) as $slot ) {
			if ( ! array_key_exists( (string) $slot, $supplied ) ) {
				$missing[] = (string) $slot;
			}
		}

		return $missing;
	}

	private function get_missing_header_media_format( array $template_settings ): string {
		if ( empty( $template_settings ) ) {
			return '';
		}

		$format = $this->get_header_format( $template_settings );

		if ( ! in_array( $format, array( 'IMAGE', 'VIDEO', 'DOCUMENT' ), true ) ) {
			return '';
		}

		$media = $template_settings['header_media'] ?? array();
		if ( ! is_array( $media ) ) {
			return $format;
		}

		// Sanitize exactly as build_header_component() does: a link that
		// esc_url_raw() rejects yields no header there, so treating it as media
		// here would put us back to silently sending a body-only payload.
		$has_media = '' !== esc_url_raw( trim( (string) ( $media['link'] ?? '' ) ) )
			|| '' !== trim( (string) ( $media['id'] ?? '' ) );

		return $has_media ? '' : $format;
	}

	/**
	 * Read the HEADER format out of a template's stored component definition.
	 *
	 * Falls back to the type of any supplied header media, so a send still works
	 * when the template definition was stored before header components were
	 * captured (templates cached by an older version).
	 *
	 * @param array $template_settings Stored template settings.
	 *
	 * @return string Uppercase format (IMAGE, VIDEO, DOCUMENT, TEXT) or '' when absent.
	 */
	private function get_header_format( array $template_settings ): string {
		// The stored format is what the fetcher recorded from the approved
		// template, so it is the primary source; deriving it from `components`
		// below is the fallback for rows saved before it was captured.
		if ( ! empty( $template_settings['header_format'] ) ) {
			return strtoupper( (string) $template_settings['header_format'] );
		}

		$components = $template_settings['components'] ?? array();

		if ( is_string( $components ) ) {
			$components = json_decode( $components, true ) ?? array();
		}

		if ( is_array( $components ) ) {
			foreach ( $components as $component ) {
				if ( ! is_array( $component ) ) {
					continue;
				}

				if ( 'HEADER' === strtoupper( (string) ( $component['type'] ?? '' ) ) ) {
					return strtoupper( (string) ( $component['format'] ?? 'TEXT' ) );
				}
			}
		}

		// No stored HEADER component: trust explicitly supplied media instead.
		$media = $template_settings['header_media'] ?? array();
		if ( is_array( $media ) && ! empty( $media['type'] ) ) {
			return strtoupper( (string) $media['type'] );
		}

		return '';
	}

	/**
	 * Get the Api instance
	 *
	 * @return Api|null Api instance or null.
	 */
	private function get_api() {
		if ( ! $this->api ) {
			$integration = $this->get_integration();
			if ( ! $integration ) {
				return null;
			}

			$this->api = $integration->connect();
		}
		return $this->api;
	}

	/**
	 * Parse incoming webhook data from Meta
	 *
	 * @param array $post_data Raw webhook data.
	 *
	 * @return array Normalized message data.
	 */
	public function parse_incoming_webhook( array $post_data ): array {
		// Shortcut when caller already isolated a single message + change value.
		if ( isset( $post_data['_message'], $post_data['_value'] ) && is_array( $post_data['_message'] ) && is_array( $post_data['_value'] ) ) {
			return $this->parse_message_data( $post_data['_message'], $post_data['_value'] );
		}

		// Meta webhook format (first message in first change — legacy single-message path).
		$entry   = $post_data['entry'][0] ?? array();
		$changes = $entry['changes'][0] ?? array();
		$value   = $changes['value'] ?? array();
		$message = $value['messages'][0] ?? array();

		return $this->parse_message_data( $message, $value );
	}

	/**
	 * Parse a single inbound Meta message from a change value block.
	 *
	 * @param array $message Message object from Meta webhook.
	 * @param array $value   Change value containing metadata.
	 * @return array Normalized message data.
	 */
	public function parse_message_data( array $message, array $value ): array {
		$from_number = $message['from'] ?? '';
		$to_number   = $value['metadata']['display_phone_number'] ?? '';

		if ( ! empty( $from_number ) && strpos( $from_number, '+' ) !== 0 ) {
			$from_number = '+' . $from_number;
		}
		if ( ! empty( $to_number ) && strpos( $to_number, '+' ) !== 0 ) {
			$to_number = '+' . $to_number;
		}

		$message_body = '';
		$message_type = $message['type'] ?? 'text';

		switch ( $message_type ) {
			case 'text':
				$message_body = $message['text']['body'] ?? '';
				break;
			case 'button':
				$message_body = $message['button']['text'] ?? '';
				break;
			case 'interactive':
				$interactive  = $message['interactive'] ?? array();
				$message_body = $interactive['button_reply']['title'] ?? $interactive['list_reply']['title'] ?? '';
				break;
		}

		return array(
			'from_number'  => $from_number,
			'to_number'    => $to_number,
			'message_body' => $message_body,
			'message_id'   => $message['id'] ?? '',
			'media_urls'   => array(),
		);
	}

	/**
	 * Verify webhook signature from Meta
	 *
	 * @param array  $server Server variables.
	 * @param array  $post   POST data.
	 * @param string $url    Request URL.
	 *
	 * @return bool True if signature is valid.
	 */
	public function verify_webhook_signature( array $server, array $post, string $url ): bool {
		$signature = $server['HTTP_X_HUB_SIGNATURE_256'] ?? '';

		if ( empty( $signature ) ) {
			return false;
		}

		$integration = $this->get_integration();
		$app_secret  = $integration ? $integration->get_setting( 'app_secret' ) : '';

		if ( empty( $app_secret ) ) {
			$this->log( 'warning', 'Meta webhook: app_secret not configured' );
			return false;
		}

		// Get raw body for signature verification
		$raw_body = file_get_contents( 'php://input' );
		$expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $app_secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Send webhook response
	 *
	 * @param string $reply_message Optional reply message.
	 *
	 * @return void
	 */
	public function send_webhook_response( string $reply_message = '' ): void {
		status_header( 200 );
		echo 'OK';
		exit;
	}

	/**
	 * Process webhook data from Meta
	 *
	 * @param string $channel      Channel type.
	 * @param array  $webhook_data Webhook data.
	 *
	 * @return array Processed webhook result.
	 */
	public function process_webhook( string $channel, array $webhook_data ): array {
		if ( ! $this->supports_channel( $channel ) ) {
			return $this->webhook_error_result( 'Channel not supported' );
		}

		$results = array();

		foreach ( $webhook_data['entry'] ?? array() as $entry ) {
			foreach ( $entry['changes'] ?? array() as $change ) {
				$value = $change['value'] ?? array();

				if ( ! empty( $value['statuses'] ) && is_array( $value['statuses'] ) ) {
					foreach ( $value['statuses'] as $status ) {
						$status_result = $this->build_status_webhook_result( $status );
						if ( $status_result ) {
							$results[] = $status_result;
						}
					}
				}

				if ( ! empty( $value['messages'] ) && is_array( $value['messages'] ) ) {
					foreach ( $value['messages'] as $message ) {
						$incoming_result = $this->build_incoming_webhook_result( $message, $value );
						if ( $incoming_result ) {
							$results[] = $incoming_result;
						}
					}
				}
			}
		}

		if ( empty( $results ) ) {
			return $this->webhook_error_result( 'Unknown webhook type' );
		}

		if ( 1 === count( $results ) ) {
			return $results[0];
		}

		return array(
			'valid'   => true,
			'results' => $results,
		);
	}

	/**
	 * Build a standardized webhook result for a Meta delivery status update.
	 *
	 * @param array $status Status object from Meta webhook.
	 * @return array|null Webhook result or null when status id is missing.
	 */
	private function build_status_webhook_result( array $status ): ?array {
		if ( empty( $status['id'] ) ) {
			return null;
		}

		$error_code = isset( $status['errors'][0]['code'] ) ? (int) $status['errors'][0]['code'] : null;
		$error_msg  = $status['errors'][0]['message'] ?? null;

		$is_opt_out_error = $error_code && MetaWhatsappErrorCodes::is_opt_out_error( $error_code );

		$metadata = array();
		if ( $is_opt_out_error ) {
			$metadata['is_opt_out']     = true;
			$metadata['opt_out_reason'] = MetaWhatsappErrorCodes::get_opt_out_reason( $error_code );
			$metadata['recipient_id']   = $status['recipient_id'] ?? null;

			$this->log(
				'info',
				'Meta WhatsApp opt-out detected from error code',
				array(
					'error_code'   => $error_code,
					'error_msg'    => $error_msg,
					'recipient_id' => $status['recipient_id'] ?? null,
					'reason'       => $metadata['opt_out_reason'],
				)
			);
		}

		if ( ! empty( $status['conversation'] ) ) {
			$conversation             = $status['conversation'];
			$metadata['conversation'] = array(
				'id'                   => $conversation['id'] ?? null,
				'origin_type'          => $conversation['origin']['type'] ?? null,
				'expiration_timestamp' => $conversation['expiration_timestamp'] ?? null,
			);

			$recipient_phone = $status['recipient_id'] ?? null;
			if ( $recipient_phone && ! empty( $conversation['expiration_timestamp'] ) ) {
				$this->store_conversation_expiration( $recipient_phone, $conversation );
			}
		}

		return $this->webhook_success_result(
			$status['id'],
			$this->map_meta_status( $status['status'] ?? '' ),
			$error_code,
			$error_msg,
			$metadata
		);
	}

	/**
	 * Build a standardized webhook result for an inbound Meta message.
	 *
	 * @param array $message Inbound message object.
	 * @param array $value   Parent change value (metadata).
	 * @return array|null Webhook result or null when message id is missing.
	 */
	private function build_incoming_webhook_result( array $message, array $value ): ?array {
		if ( empty( $message['id'] ) ) {
			return null;
		}

		$from_phone = $message['from'] ?? null;
		$timestamp  = $message['timestamp'] ?? null;

		if ( $from_phone && $timestamp ) {
			$expiration_timestamp = (int) $timestamp + ( 24 * 3600 );
			$this->store_conversation_expiration(
				$from_phone,
				array(
					'id'                   => null,
					'origin_type'          => 'user_initiated',
					'expiration_timestamp' => (string) $expiration_timestamp,
				)
			);
		}

		$parsed_incoming = $this->parse_message_data( $message, $value );

		return $this->webhook_success_result(
			$message['id'],
			'received',
			null,
			null,
			array(
				'parsed_incoming' => $parsed_incoming,
			)
		);
	}

	/**
	 * Store conversation expiration for a contact
	 *
	 * @param string $phone_number   Contact's phone number.
	 * @param array  $conversation   Conversation data from Meta.
	 */
	private function store_conversation_expiration( string $phone_number, array $conversation ): void {
		// Find contact by WhatsApp phone number
		$contact = ContactModel::where( 'whatsapp_phone', $phone_number )
			->orWhere( 'whatsapp_phone', '+' . ltrim( $phone_number, '+' ) )
			->orWhere( 'whatsapp_phone', ltrim( $phone_number, '+' ) )
			->first();

		if ( ! $contact ) {
			// Try normalized phone match
			$normalized = ltrim( $phone_number, '+' );
			$contact    = ContactModel::where( 'whatsapp_phone', 'LIKE', '%' . $normalized )
				->first();
		}

		if ( ! $contact ) {
			$this->log( 'debug', 'Cannot store conversation expiration - contact not found', array(
				'phone_number' => $phone_number,
			) );
			return;
		}

		// Store in contact meta
		$expiration_data = array(
			'expiration_timestamp' => $conversation['expiration_timestamp'],
			'origin_type'          => $conversation['origin_type'] ?? 'unknown',
			'conversation_id'      => $conversation['id'] ?? null,
			'updated_at'           => time(),
		);

		doublescale_update_contact_meta( $contact->id, 'whatsapp_conversation_window', $expiration_data );

		$this->log( 'debug', 'Stored WhatsApp conversation window expiration', array(
			'contact_id'           => $contact->id,
			'expiration_timestamp' => $conversation['expiration_timestamp'],
			'origin_type'          => $conversation['origin_type'] ?? 'unknown',
		) );
	}

	/**
	 * Map Meta status to standard status
	 *
	 * @param string $meta_status Meta status string.
	 *
	 * @return string Standard status string.
	 */
	private function map_meta_status( string $meta_status ): string {
		$map = array(
			'sent'      => 'sent',
			'delivered' => 'delivered',
			'read'      => 'read',
			'failed'    => 'failed',
		);

		return $map[ $meta_status ] ?? 'pending';
	}
}

