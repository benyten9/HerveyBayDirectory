<?php
/**
 * Trusted current native relationship-fact source.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Storage\Owner;

/** Implemented by installed native adapters, never by model or request data. */
interface RelationshipFactsProvider {
	/**
	 * Read one exact site/owner/document/version fact snapshot.
	 *
	 * @param Owner        $owner Authorized storage owner.
	 * @param string       $document_key Logical document identity.
	 * @param GraphVersion $version Exact current graph version.
	 * @return RelationshipFacts Current bounded native facts.
	 */
	public function read( Owner $owner, string $document_key, GraphVersion $version ): RelationshipFacts;
}
