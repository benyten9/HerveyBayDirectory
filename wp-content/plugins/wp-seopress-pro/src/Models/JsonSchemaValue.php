<?php

namespace SEOPressPro\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Helpers\RichSnippetType;
use SEOPress\Models\JsonSchemaValue as JsonSchemaValueBase;

/**
 * @abstract
 */
abstract class JsonSchemaValue extends JsonSchemaValueBase {
	/**
	 * @since 4.5.0
	 *
	 * @param string $file
	 * @param mixed  $name
	 *
	 * @return string
	 */
	public function getJson() {
		$file = apply_filters( 'seopress_get_json_from_file', sprintf( '%s/%s.json', SEOPRESS_PRO_TEMPLATE_JSON_SCHEMAS, $this->getName(), '.json' ) );

		if ( ! file_exists( $file ) ) {
			return '';
		}

		$json = file_get_contents( $file );

		return $json;
	}

	/**
	 * @since 4.6.0
	 *
	 * @param array $context
	 *
	 * @return array|null
	 */
	public function getCurrentSchemaManual( $context ) {
		if ( ! seopress_get_service( 'CheckContextPage' )->hasSchemaManualValues( $context ) ) {
			return null;
		}

		return $context['schemas_manual'][ $context['key_get_json_schema'] ];
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 */
	protected function getKeysForSchemaManual() {
		return array();
	}

	/**
	 * @since 4.7.0
	 *
	 * @return array
	 */
	protected function getKeysForOptionLocalBusiness() {
		return array();
	}

	/**
	 * @since 4.7.0
	 *
	 * @param array $keys
	 * @param array $data
	 *
	 * @return array
	 */
	protected function getVariablesByKeysAndData( $keys, $data = array() ) {
		$variables = array();

		foreach ( $keys as $key => $item ) {
			if ( is_string( $item ) ) {
				$variables[ $key ] = isset( $data[ $item ] ) ? $data[ $item ] : '';
			} elseif ( is_array( $item ) ) {
				$variables[ $key ] = ( isset( $item['value'] ) && isset( $data[ $item['value'] ] ) && ! empty( $data[ $item['value'] ] ) ) ? $data[ $item['value'] ] : $item['default'];
			}
		}

		return $variables;
	}

	/**
	 * @since 4.7.0
	 *
	 * @param string $type
	 * @param array  $context
	 *
	 * @return array
	 */
	public function getVariablesByType( $type, $context ) {
		switch ( $type ) {
			case RichSnippetType::MANUAL:
				$data = $this->getCurrentSchemaManual( $context );
				if ( null === $data ) {
					return array();
				}

				$keys = $this->getKeysForSchemaManual();

				return $this->getVariablesByKeysAndData( $keys, $data );
			case RichSnippetType::OPTION_LOCAL_BUSINESS:
				return $this->getKeysForOptionLocalBusiness();
			case RichSnippetType::SUB_TYPE:
				return isset( $context['variables'] ) ? $context['variables'] : array();
			default:
				return array();
		}
	}
}
