<?php
/**
 * Meta WhatsApp Template Fetcher
 *
 * Fetches approved templates from Meta WhatsApp Business Api
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Campaigns\Services;

use DoubleScale\Core\Managers\IntegrationsManager;

defined( 'ABSPATH' ) || exit;

/**
 * MetaTemplateFetcher class
 *
 * Fetches WhatsApp templates from Meta WhatsApp Business Api.
 */
class MetaTemplateFetcher {

	/**
	 * Fetch approved templates from Meta WhatsApp
	 * Returns raw data, does NOT save to database
	 *
	 * @since 1.0.0
	 *
	 * @return array List of approved templates.
	 * @throws \Exception If Meta WhatsApp is not configured.
	 */
	public function fetch_approved_templates(): array {
		$integration = IntegrationsManager::instance()->get_integration( 'meta-whatsapp' );

		if ( ! $integration || ! $integration->is_connected() ) {
			throw new \Exception( esc_html__( 'Meta WhatsApp not configured. Please configure Meta WhatsApp in Settings > Integrations.', 'doublescale' ) );
		}

		$api    = $integration->connect();
		$result = $api->get_message_templates( 'APPROVED' );

		if ( ! $result['success'] ) {
			throw new \Exception( esc_html( $result['error'] ?? __( 'Failed to fetch templates from Meta', 'doublescale' ) ) );
		}

		$templates = array();
		foreach ( $result['data']['data'] ?? array() as $meta_template ) {
			$templates[] = $this->normalize_template( $meta_template );
		}

		doublescale_get_logger()->debug(
			'Fetched Meta WhatsApp templates',
			array(
				'count' => count( $templates ),
				'code'  => 'meta_whatsapp_templates_fetched',
			)
		);

		return $templates;
	}

	/**
	 * Fetch single template by external ID
	 *
	 * @since 1.0.0
	 *
	 * @param string $external_id Template external ID (e.g., "hello_world:en_US").
	 * @return array|null Template data or null if not found.
	 */
	public function fetch_by_sid( string $external_id ): ?array {
		$templates = $this->fetch_approved_templates();

		foreach ( $templates as $template ) {
			if ( $template['sid'] === $external_id ) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Normalize a Meta template to Plugin format
	 *
	 * @param array $meta_template Raw template from Meta Api.
	 * @return array Normalized template.
	 */
	private function normalize_template( array $meta_template ): array {
		$body_component = $this->find_component( $meta_template['components'] ?? array(), 'BODY' );
		$body_text      = $body_component['text'] ?? '';
		$variables      = $this->extract_variables( $body_component );

		// Composite key: template_name:language
		$external_id = $meta_template['name'] . ':' . $meta_template['language'];

		$header_component = $this->find_component( $meta_template['components'] ?? array(), 'HEADER' );

		$settings = array(
			'provider'    => 'meta-whatsapp',
			'external_id' => $external_id,
			'variables'   => $variables,
			'components'  => $meta_template['components'] ?? array(),
			'status'      => $meta_template['status'] ?? 'APPROVED',
		);

		// Surface the header format so senders know a media component is required,
		// and seed the media from Meta's approved example. Without a link the send
		// is rejected outright, and the example is the only URL knowable at fetch
		// time — callers can still override it per send.
		// Surface the declared buttons and the template's interactive sub-type so
		// the send path knows a button component is required. Without this a
		// catalog template is sent with body/header only and Meta answers
		// "(#131008) Required parameter is missing".
		$buttons = $this->extract_buttons( $meta_template['components'] ?? array() );
		if ( ! empty( $buttons ) ) {
			$settings['buttons'] = $buttons;

			$interactive = $this->detect_interactive_type( $buttons );
			if ( '' !== $interactive ) {
				$settings['template_type'] = $interactive;
			}
		}

		$header_format = strtoupper( (string) ( $header_component['format'] ?? '' ) );
		if ( '' !== $header_format ) {
			$settings['header_format'] = $header_format;

			$example_media = $this->extract_header_example_media( $header_component, $header_format );
			if ( $example_media ) {
				$settings['header_media'] = $example_media;
			}
		}

		return array(
			'sid'      => $external_id,
			'name'     => $this->format_template_name( $meta_template['name'], $meta_template['language'] ),
			'body'     => $body_text,
			'category' => $meta_template['category'] ?? 'UTILITY',
			'language' => $meta_template['language'],
			'settings' => $settings,
		);
	}

	/**
	 * Pull the BUTTONS block out of an approved template's components.
	 *
	 * The order matters: a button component's `index` refers to the button's
	 * position in the approved template, so the list must not be re-sorted.
	 *
	 * @param array $components Components array from Meta.
	 * @return array Declared buttons, in their approved order.
	 */
	private function extract_buttons( array $components ): array {
		$buttons_component = $this->find_component( $components, 'BUTTONS' );

		if ( ! $buttons_component || empty( $buttons_component['buttons'] ) ) {
			return array();
		}

		$buttons = $buttons_component['buttons'];

		return is_array( $buttons ) ? array_values( $buttons ) : array();
	}

	/**
	 * Name the interactive sub-type a template's buttons imply.
	 *
	 * Meta does not return a "sub-type" field: a catalog template is simply one
	 * whose buttons include a CATALOG button. Recording it saves every consumer
	 * from re-deriving it, and tells the UI which extra fields to collect.
	 *
	 * @param array $buttons Declared buttons.
	 * @return string CATALOG, MPM, FLOW, COPY_CODE, or '' when plain.
	 */
	private function detect_interactive_type( array $buttons ): string {
		$interactive = array( 'CATALOG', 'MPM', 'FLOW', 'COPY_CODE' );

		foreach ( $buttons as $button ) {
			if ( ! is_array( $button ) ) {
				continue;
			}

			$type = strtoupper( (string) ( $button['type'] ?? '' ) );

			if ( in_array( $type, $interactive, true ) ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Pull the example media URL Meta stores against an approved media header.
	 *
	 * Meta returns the sample used at approval time under `example.header_handle`.
	 * It is a usable link, so it gives media templates a working default rather
	 * than failing the send when no media was chosen for this particular send.
	 *
	 * @param array|null $header_component Header component from Meta.
	 * @param string     $header_format    Uppercase header format.
	 * @return array|null Media descriptor, or null when the header carries no media.
	 */
	private function extract_header_example_media( ?array $header_component, string $header_format ): ?array {
		$media_formats = array(
			'IMAGE'    => 'image',
			'VIDEO'    => 'video',
			'DOCUMENT' => 'document',
		);

		if ( ! $header_component || ! isset( $media_formats[ $header_format ] ) ) {
			return null;
		}

		$handles = $header_component['example']['header_handle'] ?? array();
		$link    = is_array( $handles ) ? ( $handles[0] ?? '' ) : (string) $handles;

		if ( empty( $link ) ) {
			return null;
		}

		$media = array(
			'type' => $media_formats[ $header_format ],
			'link' => (string) $link,
		);

		if ( 'document' === $media['type'] ) {
			$path     = wp_parse_url( (string) $link, PHP_URL_PATH );
			$filename = $path ? basename( $path ) : '';
			if ( ! empty( $filename ) ) {
				$media['filename'] = $filename;
			}
		}

		return $media;
	}

	/**
	 * Format template name for display
	 *
	 * @param string $name     Template name.
	 * @param string $language Language code.
	 * @return string Formatted name.
	 */
	private function format_template_name( string $name, string $language ): string {
		$formatted = ucwords( str_replace( '_', ' ', $name ) );
		return sprintf( '%s (%s)', $formatted, strtoupper( $language ) );
	}

	/**
	 * Find a component by type
	 *
	 * @param array  $components Components array.
	 * @param string $type       Component type (HEADER, BODY, FOOTER, BUTTONS).
	 * @return array|null Component or null if not found.
	 */
	private function find_component( array $components, string $type ): ?array {
		foreach ( $components as $component ) {
			if ( ( $component['type'] ?? '' ) === $type ) {
				return $component;
			}
		}
		return null;
	}

	/**
	 * Extract variables from body component
	 *
	 * Supports both positional ({{1}}, {{2}}) and named ({{name}}, {{order}}) variables.
	 *
	 * @param array|null $body_component Body component.
	 * @return array Variables array.
	 */
	private function extract_variables( ?array $body_component ): array {
		if ( ! $body_component ) {
			return array();
		}

		$variables = array();
		$text      = $body_component['text'] ?? '';

		// Find both {{1}}, {{2}} (positional) and {{name}}, {{order}} (named)
		preg_match_all( '/\{\{([a-zA-Z_][a-zA-Z0-9_]*|\d+)\}\}/', $text, $matches );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		// Get example values if available
		$examples = $body_component['example']['body_text'][0] ?? array();

		foreach ( $matches[1] as $position => $key ) {
			$is_numeric    = is_numeric( $key );
			$example_index = $is_numeric ? ( (int) $key - 1 ) : $position;

			$variables[ (string) $key ] = array(
				'key'     => (string) $key,
				'type'    => $is_numeric ? 'positional' : 'named',
				'example' => $examples[ $example_index ] ?? '',
			);
		}

		return $variables;
	}
}
