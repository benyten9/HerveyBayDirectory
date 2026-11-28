<?php
/**
 * Operation-scoped coverage for an isolated native template copy.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal proof bound to server-derived native evidence.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Unknown incoming consumers remain partial; they are untouched by a new draft. */
final class BuilderPrivateCopyCoverage {
	private string $document_key;
	private string $version_hash;
	private string $source_graph_hash;
	private object $document;
	private string $consumer_coverage;
	private string $effect_scope  = 'isolated_private_template_copy';
	private ?string $capture_id   = null;
	private ?string $capture_hash = null;

	private function __construct( BuilderGraphResult $result ) {
		$this->document_key      = $result->document_key();
		$this->version_hash      = $result->version()->hash();
		$this->source_graph_hash = $result->graph_hash();
		$this->document          = $result->graph()->document;
		$this->consumer_coverage = $result->graph()->consumer_coverage;
	}

	/** Only a signed private ordinary-page canvas can omit unrelated incoming consumers. */
	public static function for_editor_candidate( BuilderGraphInspectionRequest $request, BuilderGraphResult $result ): ?self {
		$context = $request->context();
		$context->assert_execution_identity();
		$graph    = $result->graph();
		$document = $graph->document ?? null;
		if ( $context->actor()->is_guest() || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || ! $document instanceof \stdClass || 'page' !== ( $document->native_kind ?? null ) || ! in_array( $document->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || null !== $request->editor() || $result->version()->editor_bound() || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || ! is_array( $graph->omissions ?? null ) || $graph->omissions || ! is_array( $graph->slot_coverage ?? null ) || ! $graph->slot_coverage || ( new JsonCanonicalizer() )->encode( $document ) !== ( new JsonCanonicalizer() )->encode( $request->document() ) ) {
			return null;
		}
		$request->assert_result( $result );
		$record = \DirectoristAIPro\UI\EditorWorkingCopy::fresh_page_editor_context( $document );
		if ( null === $record || 'editor_candidate' !== ( $record['output_mode'] ?? null ) || 'page' !== ( $record['document']->native_kind ?? null ) || ( $record['site_id'] ?? null ) !== get_current_blog_id() || ( $record['user_id'] ?? null ) !== get_current_user_id() || ! is_int( $record['source_id'] ?? null ) || $record['source_id'] < 1 || 'page' !== get_post_type( $record['source_id'] ) || ! current_user_can( 'edit_post', $record['source_id'] ) || ! is_int( $record['post_id'] ?? null ) || 'page' !== get_post_type( $record['post_id'] ) || \DirectoristAIPro\UI\EditorWorkingCopy::INTERNAL_STATUS !== get_post_status( $record['post_id'] ) || ! current_user_can( 'edit_post', $record['post_id'] ) || ! is_string( $record['capture_id'] ?? null ) || ! is_string( $record['captured_snapshot_hash'] ?? null ) ) {
			return null;
		}
		foreach ( $graph->slot_coverage as $slot ) {
			if ( ! $slot instanceof \stdClass || true !== ( $slot->complete ?? null ) ) {
				return null;
			}
		}
		foreach ( $graph->nodes ?? array() as $node ) {
			if ( null !== ( $node->opaque_ref ?? null ) || 'native/opaque' === ( $node->provider_id ?? null ) ) {
				return null;
			}
		}
		foreach ( $result->facts()->snapshot()->references as $reference ) {
			if ( ! $reference instanceof \stdClass || 'current' !== ( $reference->status ?? null ) ) {
				return null;
			}
		}
		$proof               = new self( $result );
		$proof->effect_scope = 'isolated_editor_candidate';
		$proof->capture_id   = $record['capture_id'];
		$proof->capture_hash = $record['captured_snapshot_hash'];
		return $proof;
	}

	/** Mint only from current saved native authority, never model or artifact input. */
	public static function for_saved_template( BuilderGraphInspectionRequest $request, BuilderGraphResult $result ): ?self {
		$graph     = $result->graph();
		$document  = $graph->document ?? null;
		$kind      = $document->native_kind ?? null;
		$kinds     = array( 'template:single_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' );
		$elementor = 'elementor' === ( $document->builder ?? null ) && in_array( $kind, $kinds, true ) && is_string( $document->native_id ?? null ) && preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) && 'partial' === ( $graph->consumer_coverage ?? null );
		$part      = 'gutenberg' === ( $document->builder ?? null ) && 'template_part' === $kind && 'complete' === ( $graph->consumer_coverage ?? null ) && null === $request->scope()->directory_id && null === $request->scope()->view && is_string( $document->native_id ?? null );
		if ( ! $document instanceof \stdClass || ( ! $elementor && ! $part ) || null !== $request->editor() || $result->version()->editor_bound() || 'complete' !== ( $graph->completeness ?? null ) || ! is_array( $graph->omissions ?? null ) || $graph->omissions || ! is_array( $graph->slot_coverage ?? null ) || ! $graph->slot_coverage || ( new JsonCanonicalizer() )->encode( $document ) !== ( new JsonCanonicalizer() )->encode( $request->document() ) ) {
			return null;
		}
		foreach ( $graph->slot_coverage as $slot ) {
			if ( ! $slot instanceof \stdClass || true !== ( $slot->complete ?? null ) ) {
				return null;
			}
		}
		foreach ( $result->facts()->snapshot()->references as $reference ) {
			if ( ! $reference instanceof \stdClass || 'current' !== ( $reference->status ?? null ) ) {
				return null;
			}
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return null;
		}
		if ( $elementor ) {
			$post_id      = (int) $match[1];
			$directory_id = $request->scope()->directory_id;
			$directory_id = null === $directory_id ? null : (int) $directory_id;
			if ( 'elementor_library' !== get_post_type( $post_id ) || ! ElementorEditorSnapshotBinding::matches_saved_document( $post_id, $kind, $directory_id ) ) {
				return null;
			}
		} else {
			// An explicit saved part is copied to a new private source, never into its consumers.
			$template = GutenbergTemplateSource::read( $document->native_id );
			if ( ! $template instanceof \WP_Block_Template || 'wp_template_part' !== $template->type || ! hash_equals( hash( 'sha256', $template->content ), $result->version()->value()->source_hash ) ) {
				return null;
			}
		}
		return new self( $result );
	}

	/** Current/source graph binding; candidate nodes may differ but authority may not. */
	public function assert_graph( string $document_key, GraphVersion $version, object $graph, bool $candidate = false ): void {
		if ( $this->is_editor_candidate() ) {
			$record = \DirectoristAIPro\UI\EditorWorkingCopy::fresh_page_editor_context( $this->document );
			if ( null === $record || 'editor_candidate' !== ( $record['output_mode'] ?? null ) || ! hash_equals( (string) $this->capture_id, (string) ( $record['capture_id'] ?? '' ) ) || ! hash_equals( (string) $this->capture_hash, (string) ( $record['captured_snapshot_hash'] ?? '' ) ) || \DirectoristAIPro\UI\EditorWorkingCopy::INTERNAL_STATUS !== get_post_status( $record['post_id'] ) || ! current_user_can( 'edit_post', $record['source_id'] ) || ! current_user_can( 'edit_post', $record['post_id'] ) ) {
				throw new ContractException( 'stale_version', 'The private editor capture changed before review.' );
			}
		}
		$coverage = $graph->consumer_coverage ?? null;
		if ( $this->document_key !== $document_key || ! hash_equals( $this->version_hash, $version->hash() ) || ( new JsonCanonicalizer() )->encode( $this->document ) !== ( new JsonCanonicalizer() )->encode( $graph->document ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || $this->consumer_coverage !== $coverage || ! is_array( $graph->omissions ?? null ) || $graph->omissions ) {
			throw new ContractException( 'incomplete_graph', 'Private copy coverage differs from the saved template graph.' );
		}
		if ( ! $candidate && ! hash_equals( $this->source_graph_hash, hash( 'sha256', ( new JsonCanonicalizer() )->encode( $graph ) ) ) ) {
			throw new ContractException( 'stale_version', 'Private copy coverage belongs to another source graph.' );
		}
	}

	public function effect_scope(): string {
		return $this->effect_scope;
	}

	public function is_editor_candidate(): bool {
		return 'isolated_editor_candidate' === $this->effect_scope;
	}
}
