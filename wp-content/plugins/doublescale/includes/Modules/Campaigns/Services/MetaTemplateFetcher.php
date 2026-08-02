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

		return array(
			'sid'      => $external_id,
			'name'     => $this->format_template_name( $meta_template['name'], $meta_template['language'] ),
			'body'     => $body_text,
			'category' => $meta_template['category'] ?? 'UTILITY',
			'language' => $meta_template['language'],
			'settings' => array(
				'provider'    => 'meta-whatsapp',
				'external_id' => $external_id,
				'variables'   => $variables,
				'components'  => $meta_template['components'] ?? array(),
				'status'      => $meta_template['status'] ?? 'APPROVED',
			),
		);
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
