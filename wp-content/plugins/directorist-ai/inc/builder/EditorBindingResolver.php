<?php
/**
 * Resolve only server-issued editor evidence.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment -- Compact trusted resolver.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Client input may name a binding; it can never construct one. */
final class EditorBindingResolver {
	public function resolve( ?string $binding_id, Context $context, string $builder ): ?EditorBinding {
		if ( null === $binding_id ) {
			return null;
		}
		if ( ! ProviderRegistration::valid_token( $binding_id ) ) {
			throw new ContractException( 'evidence_stale', 'Editor binding identity is invalid.' );
		}
		$binding = match ( $builder ) {
			'gutenberg' => GutenbergEditorSnapshotBinding::load( $context, $binding_id ),
			'elementor' => ElementorEditorSnapshotBinding::load( $context, $binding_id ),
			'divi'      => DiviEditorSnapshotBinding::load( $context, $binding_id ),
			default     => apply_filters( 'directorist_ai_pro_resolve_builder_editor_binding', null, $binding_id, $context, $builder ),
		};
		if ( ! $binding instanceof EditorBinding ) {
			throw new ContractException( 'evidence_stale', 'Editor binding is unavailable.' );
		}
		$binding->assert_current( $context, $builder );
		return $binding;
	}
}
