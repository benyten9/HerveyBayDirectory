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
				// `default` is optional: a key may declare `value` alone and fall back to an
				// empty string, exactly as the string form does.
				$default           = isset( $item['default'] ) ? $item['default'] : '';
				$variables[ $key ] = ( isset( $item['value'] ) && isset( $data[ $item['value'] ] ) && ! empty( $data[ $item['value'] ] ) ) ? $data[ $item['value'] ] : $default;
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

	/**
	 * Number a manual schema's identifier when another of its type precedes it.
	 *
	 * Manual schemas of one type on one page all derive their `@id` from the
	 * same canonical, so a consumer merging on `@id` read two businesses, or
	 * two services, as one. The first keeps the plain fragment, so the common
	 * case of a single schema is unchanged; the next ones get `-2`, `-3`...
	 *
	 * @since 10.4.0
	 *
	 * @param array      $data    The resolved node.
	 * @param array|null $context The page context.
	 *
	 * @return array
	 */
	protected function numberManualIdentity( $data, $context ) {
		if ( ! isset( $data['@id'] ) || ! is_string( $data['@id'] ) || '' === $data['@id'] ) {
			return $data;
		}

		$offset = $this->manualSchemaOffset( $context );

		if ( $offset > 0 ) {
			$data['@id'] .= '-' . ( $offset + 1 );
		}

		return $data;
	}

	/**
	 * How many manual schemas of this type come before the current one.
	 *
	 * @since 10.4.0
	 *
	 * @param array|null $context The page context.
	 *
	 * @return int
	 */
	protected function manualSchemaOffset( $context ) {
		if ( ! is_array( $context )
			|| ! isset( $context['schemas_manual'] )
			|| ! is_array( $context['schemas_manual'] )
			|| ! isset( $context['key_get_json_schema'] ) ) {
			return 0;
		}

		$names = array( $this->getName() );
		if ( defined( static::class . '::ALIAS' ) ) {
			$names = array_merge( $names, (array) constant( static::class . '::ALIAS' ) );
		}

		$offset = 0;

		foreach ( $context['schemas_manual'] as $key => $schema ) {
			if ( $key === $context['key_get_json_schema'] ) {
				return $offset;
			}

			$type = isset( $schema['_seopress_pro_rich_snippets_type'] ) ? $schema['_seopress_pro_rich_snippets_type'] : '';

			if ( in_array( $type, $names, true ) ) {
				++$offset;
			}
		}

		return 0;
	}
}
