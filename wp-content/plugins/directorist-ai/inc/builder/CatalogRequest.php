<?php
/**
 * Server-owned effective catalog request.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact server-owned request value object.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Keeps authority and native editor state outside provider declarations. */
final class CatalogRequest {
	private Context $context;
	private string $builder;
	private string $document_kind;
	private ?int $directory_id;
	private ?EditorBinding $editor_binding;

	public function __construct( Context $context, string $builder, string $document_kind, ?int $directory_id, ?EditorBinding $editor_binding = null ) {
		if ( ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! ProviderRegistration::valid_token( $document_kind ) || ( null !== $directory_id && $directory_id < 1 ) ) {
			throw new ContractException( 'invalid_input', 'Catalog request is invalid.' );
		}
		$this->context        = $context;
		$this->builder        = $builder;
		$this->document_kind  = $document_kind;
		$this->directory_id   = $directory_id;
		$this->editor_binding = $editor_binding;
	}

	public function context(): Context {
		return $this->context; }
	public function builder(): string {
		return $this->builder; }
	public function document_kind(): string {
		return $this->document_kind; }
	public function directory_id(): ?int {
		return $this->directory_id; }
	public function editor_binding(): ?EditorBinding {
		return $this->editor_binding; }
}
