<?php
/**
 * Resolve an explicitly selected Knowledge Graph provider.
 *
 * @package SEOPressPro
 */
namespace SEOPressPro\Helpers;

defined( 'ABSPATH' ) || exit;

class ServiceProviderReference {
	/** Return an existing organization identity, or retain the inline provider. */
	public static function resolve( $source, $context = null ) {
		if ( 'knowledge_graph_organization' !== $source || '1' !== seopress_get_toggle_option( 'social' ) ) {
			return array();
		}
		$type = seopress_get_service( 'SocialOption' )->getSocialKnowledgeType();
		if ( empty( $type ) || in_array( $type, array( 'none', 'Person' ), true ) ) {
			return array();
		}
		if ( null === $context ) {
			$context = seopress_get_service( 'ContextPage' )->getContext();
		}
		$organization = seopress_get_service( 'JsonSchemaGenerator' )->getJsonFromSchema(
			'organization', $context, array( 'remove_empty' => true )
		);
		if ( empty( $organization['@id'] ) || ! is_string( $organization['@id'] ) ) {
			return array();
		}
		return array( '@id' => $organization['@id'] );
	}
}
