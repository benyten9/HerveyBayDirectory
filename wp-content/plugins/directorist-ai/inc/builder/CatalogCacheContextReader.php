<?php
/**
 * Resolve live authority and cheap source fingerprints before cache access.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Internal cache context boundary.

use DirectoristAIPro\Validation\ContractException;

/** Warm reads still recheck actor, directory and editor evidence. */
final class CatalogCacheContextReader {
	private IntegrationRegistry $integrations;
	private DirectoryFactsReader $directories;
	private FeatureCertificationRegistry $certifications;
	private CatalogCacheStore $epochs;
	private EffectiveCatalogContract $contract;
	private ?array $last_facts                 = null;
	private ?CatalogCacheContext $last_context = null;

	public function __construct( IntegrationRegistry $integrations, DirectoryFactsReader $directories, FeatureCertificationRegistry $certifications, CatalogCacheStore $epochs, ?EffectiveCatalogContract $contract = null ) {
		$this->integrations   = $integrations;
		$this->directories    = $directories;
		$this->certifications = $certifications;
		$this->epochs         = $epochs;
		$this->contract       = $contract ?? new EffectiveCatalogContract();
	}

	public function read( CatalogRequest $request, CatalogEnvironment $environment ): CatalogCacheContext {
		$context = $request->context();
		$context->assert_current();
		$editor_fingerprint = null;
		if ( null !== $request->editor_binding() ) {
			$request->editor_binding()->assert_current( $context, $request->builder() );
			$editor_fingerprint = $request->editor_binding()->fingerprint();
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $editor_fingerprint ) ) {
				throw new ContractException( 'evidence_stale', 'Editor binding evidence is invalid.' );
			}
		}
		$directory_facts = null;
		if ( null !== $request->directory_id() ) {
			$directory_facts = ( new DirectoryFactsValidator() )->normalize( $this->directories->read( $context, $request->directory_id() ), $request->directory_id() );
		}
		$provider         = $this->integrations->cache_fingerprints( $request->builder(), $request->document_kind(), $editor_fingerprint, $environment, $directory_facts, $request->editor_binding() );
		$rule_fingerprint = hash( 'sha256', $provider['rules'] . ':' . $this->certifications->fingerprint() );
		$facts            = array(
			'site_id'                  => get_current_blog_id(),
			'scope_hash'               => hash( 'sha256', get_current_blog_id() . ':' . $context->actor()->owner()->reference() . ':' . $context->profile() ),
			'builder'                  => $request->builder(),
			'document_kind'            => $request->document_kind(),
			'directory_id'             => $request->directory_id(),
			'directory_revision'       => null === $directory_facts ? null : $directory_facts->revision,
			'editor_fingerprint'       => $editor_fingerprint,
			'native_fingerprint'       => hash( 'sha256', $environment->fingerprint() . ':' . $provider['native'] ),
			'integration_fingerprint'  => $provider['integration'],
			'rule_fingerprint'         => $rule_fingerprint,
			'schema_fingerprint'       => $this->contract->fingerprint(),
			'directory_fingerprint'    => $this->directory_fingerprint( $directory_facts ),
			'design_fingerprint'       => $provider['design'],
			'invalidation_fingerprint' => $this->epochs->epoch_fingerprint( $request->directory_id() ),
			'cacheable'                => $provider['cacheable'],
			'dynamic_reasons'          => $provider['dynamic_reasons'],
		);
		// Reuse only the immutable value object after EVERY live authority/source
		// input above has been read again. This never memoizes permissions or facts.
		if ( $facts === $this->last_facts && null !== $this->last_context ) {
			return $this->last_context;
		}
		$current            = new CatalogCacheContext( $facts );
		$this->last_facts   = $facts;
		$this->last_context = $current;
		return $this->last_context;
	}

	private function directory_fingerprint( ?object $facts ): string {
		if ( null === $facts ) {
			return hash( 'sha256', 'none' );
		}
		$normalized = array(
			'revision'       => $facts->revision,
			'field_ids'      => $facts->field_ids,
			'complete'       => $facts->complete,
			'limitations'    => $facts->limitations,
			'directory_slug' => $facts->directory_slug ?? null,
			'fields'         => $facts->fields ?? array(),
		);
		return hash( 'sha256', json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
