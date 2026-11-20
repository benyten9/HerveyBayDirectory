<?php
/**
 * Identity of the site entity the automatic schemas publish under.
 *
 * @package SEOPressPro
 */

namespace SEOPressPro\Helpers;

defined( 'ABSPATH' ) || exit;

class PublisherEntity {

	/**
	 * Identifier the automatic publisher shares with the Knowledge Graph node.
	 *
	 * The automatic Article and Video schemas build their publisher from the
	 * Knowledge Graph fields, so they describe the same business the free
	 * plugin prints as an Organization node. Giving both the same `@id` is
	 * what tells a consumer they are one entity rather than two.
	 *
	 * The identifier is site-wide on purpose: the Organization node is printed
	 * on the front page only, so the reference is resolved across the site
	 * rather than inside the document. The publisher keeps its name, logo and
	 * sameAs for that reason, nothing here replaces them.
	 *
	 * Returns an empty string whenever the two would not describe the same
	 * thing, in which case no identifier is attached at all:
	 *
	 * - the free helper is missing, on an older free plugin,
	 * - the Social feature is off, so no Organization node is ever printed,
	 * - the Knowledge Graph type is unset or "none", same reason,
	 * - the Knowledge Graph describes a Person, while this publisher is typed
	 *   Organization. Claiming one `@id` for two types is worse than none.
	 *
	 * The id is read back from the Organization generator rather than rebuilt
	 * from the helper, so a site that moves the node with the
	 * `seopress_get_json_data_organization` filter, which is the workaround
	 * #1597 hands customers, gets a publisher pointing at wherever the
	 * Organization actually is. Building it independently meant an
	 * Organization at `#org` and an article publisher at `/#organization`.
	 *
	 * ServiceProviderReference already resolves it that way and carries the
	 * same guards, so it is used rather than duplicated.
	 *
	 * @since 10.4.0
	 *
	 * @return string Absolute identifier, or an empty string.
	 */
	public static function get_id() {
		if ( ! class_exists( '\SEOPress\Helpers\SchemaEntityId' ) ) {
			return '';
		}

		$reference = ServiceProviderReference::resolve( 'knowledge_graph_organization' );

		return empty( $reference['@id'] ) ? '' : (string) $reference['@id'];
	}
}
