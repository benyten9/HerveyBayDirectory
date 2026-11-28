<?php
/**
 * Trusted current design-fact source for task context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Owner;

/** Implemented by installed adapters; request/model data cannot register a provider. */
interface BuilderTaskContextFactsProvider {
	/**
	 * Recheck current native read/edit rights for this exact document.
	 *
	 * The adapter resolves the logical key and, for the selected graph, confirms
	 * that the supplied native identity is still the same document. A null native
	 * identity is used for reverse consumers and external references, which must
	 * be resolved privately.
	 * Throw a ContractException when the actor cannot inspect it.
	 *
	 * @param Context     $context Current server-bound actor and workflow.
	 * @param string      $document_key Logical document key.
	 * @param object|null $native_document Exact graph document when available.
	 */
	public function assert_document_access( Context $context, string $document_key, ?object $native_document ): void;

	/**
	 * Recheck a stored conversation citation against current actor/native state.
	 *
	 * Implementations resolve the reference kind and exact source privately,
	 * including current knowledge approval/visibility and listing permissions.
	 * Unknown, malformed, revoked or stale references must throw; the raw
	 * reference never enters the model packet.
	 *
	 * @param Context $context Current server-bound actor and workflow.
	 * @param object  $reference One stored source_refs_json item.
	 */
	public function assert_source_access( Context $context, object $reference ): void;

	/**
	 * Attest that assistant text or a summary has complete source provenance.
	 *
	 * The provider must establish that all source-derived content in this text
	 * is accounted for by the covered stored messages and their references.
	 * An empty source_refs_json array alone is not proof. Unverifiable content,
	 * including unknown assistant producers, must throw.
	 *
	 * @param Context $context Current server-bound actor and workflow.
	 * @param string  $kind Assistant message or summary.
	 * @param string  $text Exact stored text before inert-text filtering.
	 * @param array   $covered_messages Stored rows with content and references.
	 */
	public function assert_memory_provenance( Context $context, string $kind, string $text, array $covered_messages ): void;

	/**
	 * Read safe current facts for the exact owner, run, document and versions.
	 *
	 * @param Owner        $owner Server-resolved owner.
	 * @param string       $run_id Exact current workflow run.
	 * @param string       $document_key Logical graph document.
	 * @param GraphVersion $version Exact graph version.
	 * @param string       $catalog_revision Exact current catalog revision.
	 */
	public function read( Owner $owner, string $run_id, string $document_key, GraphVersion $version, string $catalog_revision ): BuilderTaskContextFacts;
}
