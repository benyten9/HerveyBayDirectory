<?php
/**
 * Pure, unregistered Elementor page draft candidate compilation.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\ContractException;

/** Produces a bounded native-shaped proposal; it has no writer or approval route. */
final class ElementorPageCandidateCompiler {
	public const REVISION = 'elementor-page-candidate-v1';

	/**
	 * Compile one container and one plain-text heading from a trusted live catalog.
	 *
	 * The caller must obtain $catalog from an installed, server-owned provider
	 * and mint the seed as an immutable reviewed operation input. The emitted
	 * eight-digit hex IDs are this recipe's bounded subset of native IDs, not a
	 * statement that all Elementor IDs have that length. This helper neither
	 * reads a client-supplied registry nor grants a write.
	 *
	 * @param object           $request Exact bounded candidate intent.
	 * @param ProviderSnapshot $catalog Trusted installed native catalog.
	 * @return object Read-only candidate and source fingerprints.
	 * @throws ContractException Invalid intent or unavailable native contract.
	 */
	public function compile( object $request, ProviderSnapshot $catalog ): object {
		$keys = array_keys( get_object_vars( $request ) );
		sort( $keys, SORT_STRING );
		if ( array( 'catalog_source_fingerprint', 'contract', 'draft_title', 'heading_text', 'seed' ) !== $keys || self::REVISION !== $request->contract || ! is_string( $request->catalog_source_fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/D', $request->catalog_source_fingerprint ) || ! is_string( $request->seed ) || ! preg_match( '/^[a-f0-9]{64}$/D', $request->seed ) ) {
			throw new ContractException( 'invalid_input', 'Elementor page candidate request is invalid.' );
		}
		$title   = $this->plain_text( $request->draft_title, 120 );
		$heading = $this->plain_text( $request->heading_text, 240 );
		if ( '4' !== $catalog->native_generation() || ! preg_match( '/^4\.[0-9]+(?:\.|$)/D', $catalog->native_version() ) || 'complete' !== $catalog->completeness() || ! hash_equals( $catalog->source_fingerprint(), $request->catalog_source_fingerprint ) || ( $catalog->observed_versions()['elementor'] ?? null ) !== $catalog->native_version() ) {
			throw new ContractException( 'unsupported_contract', 'Current Elementor catalog cannot compile this page candidate.' );
		}
		$selected = array();
		foreach ( $catalog->components() as $component ) {
			$id = $component->native_id();
			if ( ! in_array( $id, array( 'container', 'heading' ), true ) ) {
				continue;
			}
			if ( isset( $selected[ $id ] ) || $catalog->blocks_writes( $id ) || 'elementor/core' !== $component->source_provider_id() || 'available' !== $component->availability() || ! in_array( 'page', $component->document_kinds(), true ) ) {
				throw new ContractException( 'unsupported_contract', 'Current Elementor component is unavailable for this page candidate.' );
			}
			$selected[ $id ] = $component->descriptor();
		}
		if ( ! isset( $selected['container'], $selected['heading'] ) ) {
			throw new ContractException( 'unsupported_dependency', 'Required native Elementor components are unavailable.' );
		}
		$this->assert_native_shape( $selected['container'], $selected['heading'] );
		$container_id = substr( hash( 'sha256', self::REVISION . '|' . $request->seed . '|container' ), 0, 8 );
		$heading_id   = substr( hash( 'sha256', self::REVISION . '|' . $request->seed . '|heading' ), 0, 8 );
		if ( hash_equals( $container_id, $heading_id ) ) {
			throw new ContractException( 'unsupported_contract', 'Native Elementor candidate identities conflict.' );
		}
		$elements = array(
			array(
				'id'       => $container_id,
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => $heading_id,
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => $heading ),
						'elements'   => array(),
						'isInner'    => false,
					),
				),
				'isInner'  => false,
			),
		);
		$identity = array(
			self::REVISION,
			$request->seed,
			$catalog->source_fingerprint(),
			$selected['container']['source_fingerprint'],
			$selected['heading']['source_fingerprint'],
			$title,
			$elements,
		);
		return (object) array(
			'contract'                   => self::REVISION,
			'document_kind'              => 'page',
			'draft_title'                => $title,
			'catalog_source_fingerprint' => $catalog->source_fingerprint(),
			'component_fingerprints'     => (object) array(
				'container' => $selected['container']['source_fingerprint'],
				'heading'   => $selected['heading']['source_fingerprint'],
			),
			'elements'                   => $elements,
			'candidate_hash'             => hash( 'sha256', json_encode( $identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) ),
			'write_enabled'              => false,
		);
	}

	/**
	 * Verify only the exact native element/child/control subset we emit.
	 *
	 * @param array $container Native container descriptor.
	 * @param array $heading Native heading descriptor.
	 * @throws ContractException Unavailable source-derived shape.
	 */
	private function assert_native_shape( array $container, array $heading ): void {
		$container_controls = $container['controls'];
		$heading_controls   = $heading['controls'];
		$title_schema       = $heading['attributes_schema']->properties->title ?? null;
		$title_control      = $heading_controls->native->title ?? null;
		$allowed_children   = $container_controls->semantic->allowed_child_types ?? null;
		if ( 'element' !== ( $container_controls->kind ?? null ) || 'widget' !== ( $heading_controls->kind ?? null ) || ! in_array( 'children', $container['slots'], true ) || ! is_array( $allowed_children ) || ! in_array( 'heading', $allowed_children, true ) || ! is_object( $title_schema ) || 'string' !== ( $title_schema->type ?? null ) || ! is_object( $title_control ) || ! in_array( $title_control->type ?? null, array( 'text', 'textarea' ), true ) || ! empty( $title_control->condition ) || ! empty( $title_control->conditions ) || ! empty( $title_control->responsive ) ) {
			throw new ContractException( 'unsupported_contract', 'Native Elementor page shape or heading control is unavailable.' );
		}
	}

	/**
	 * Accept only bounded plain UTF-8 text for the fixed recipe.
	 *
	 * @param mixed $value Supplied text.
	 * @param int   $limit Maximum UTF-8 byte length.
	 * @return string Validated text.
	 * @throws ContractException Invalid text.
	 */
	private function plain_text( $value, int $limit ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > $limit || 1 !== preg_match( '//u', $value ) || preg_match( '/[<>\x00-\x1f\x7f]/', $value ) ) {
			throw new ContractException( 'invalid_input', 'Elementor page candidate text is invalid.' );
		}
		return $value;
	}
}
