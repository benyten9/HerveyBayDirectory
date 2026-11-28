<?php
/**
 * Exact saved-page authority for creating the first directory-scoped Elementor loop.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal typed proof.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** A new view may descend from the selected page root without changing that root. */
final class BuilderElementorLoopCreationProof {
	private string $document_key;
	private string $version_hash;
	private string $graph_hash;
	private object $scope;
	private object $parent_locator;
	private string $parent_id;
	private JsonCanonicalizer $canonical;
	private string $loop_identity;
	private ?int $append_position;
	private ?object $attributes_schema;

	private function __construct( BuilderGraphInspectionRequest $request, BuilderGraphResult $result, object $root_node, ?int $append_position = null, ?object $attributes_schema = null ) {
		$this->document_key    = $result->document_key();
		$this->version_hash    = $result->version()->hash();
		$this->graph_hash      = $result->graph_hash();
		$this->scope           = $request->scope();
		$this->parent_locator  = $root_node->locator;
		$this->parent_id       = $root_node->id;
		$this->canonical       = new JsonCanonicalizer();
		$this->append_position = $append_position;
		$this->attributes_schema = null === $attributes_schema ? null : json_decode( $this->canonical->encode( $attributes_schema ) );
		$this->loop_identity   = 'template:home_search' === $request->document()->native_kind ? 'directorist_homepage_search_loop' : 'directorist_listings_loop';
	}

	/** Mint only from a complete current saved page and installed native loop. */
	public static function for_saved_page( BuilderGraphInspectionRequest $request, BuilderGraphResult $result ): ?self {
		$document      = $request->document();
		$scope         = $request->scope();
		$facts         = $request->directory_facts();
		$graph         = $result->graph();
		$kind          = $document->native_kind ?? null;
		$kinds         = array( 'page', 'template:archive_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' );
		$copy          = BuilderPrivateCopyCoverage::for_saved_template( $request, $result );
		$loop_identity = 'template:home_search' === $kind ? 'directorist_homepage_search_loop' : 'directorist_listings_loop';
		if ( 'elementor' !== ( $document->builder ?? null ) || ! in_array( $kind, $kinds, true ) || ! is_string( $document->native_id ?? null ) || ! preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) || null !== $request->editor() || $result->version()->editor_bound() || ! is_string( $scope->directory_id ?? null ) || ! preg_match( '/^[1-9][0-9]*$/D', $scope->directory_id ) || ! in_array( $scope->view ?? null, array( 'grid', 'list', 'map' ), true ) || null !== ( $scope->breakpoint_id ?? null ) || 'normal' !== ( $scope->interaction ?? null ) || ! $facts instanceof \stdClass || true !== ( $facts->complete ?? null ) || (string) ( $facts->directory_id ?? null ) !== $scope->directory_id || 'complete' !== ( $graph->completeness ?? null ) || ( 'complete' !== ( $graph->consumer_coverage ?? null ) && null === $copy ) || ! is_array( $graph->omissions ?? null ) || $graph->omissions || ( new JsonCanonicalizer() )->encode( $document ) !== ( new JsonCanonicalizer() )->encode( $graph->document ?? null ) ) {
			return null;
		}
		$post_id = (int) $match[1];
		$plugin  = class_exists( '\\Elementor\\Plugin' ) ? \Elementor\Plugin::instance() : null;
		if ( ( in_array( $kind, array( 'page', 'template:archive_listing' ), true ) ? 'page' !== get_post_type( $post_id ) : 'elementor_library' !== get_post_type( $post_id ) ) || ! current_user_can( 'edit_post', $post_id ) || ! ElementorEditorSnapshotBinding::matches_saved_document( $post_id, $kind, (int) $scope->directory_id ) || ! $plugin || ! isset( $plugin->elements_manager ) || ! is_object( $plugin->elements_manager->get_element_types( $loop_identity ) ) ) {
			return null;
		}
		$native         = $result->native_snapshot()->document_data ?? null;
		$existing_loops = array_filter( $graph->nodes, static fn( object $node ): bool => 'directorist-elementor' === ( $node->provider_id ?? null ) && in_array( $node->native_id ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) );
		if ( $existing_loops && ( 'page' !== $kind || ! is_array( $native ) || ! self::native_loops_exclude_directory( $native, $scope->directory_id, count( $existing_loops ) ) ) ) {
			return null;
		}
		$parent = null;
		foreach ( $graph->nodes as $node ) {
			if ( 'directorist-elementor' === ( $node->provider_id ?? null ) && in_array( $node->native_id ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) {
				$directory = $node->scope->directory_id ?? null;
				if ( ! is_string( $directory ) || ! preg_match( '/^[1-9][0-9]*$/D', $directory ) || $directory === $scope->directory_id || null !== ( $node->opaque_ref ?? null ) ) {
					return null;
				}
			}
			if ( 'directorist-ai' === ( $node->provider_id ?? null ) && 'elementor-document' === ( $node->native_id ?? null ) && array( 'document' ) === ( $node->locator->slot_path ?? null ) && hash_equals( $scope->directory_id, (string) ( $node->scope->directory_id ?? '' ) ) && null === ( $node->scope->view ?? null ) ) {
				if ( null !== $parent ) {
					return null;
				}
				$parent = $node;
			}
			if ( ( new JsonCanonicalizer() )->encode( $node->scope ?? null ) === ( new JsonCanonicalizer() )->encode( $scope ) ) {
				return null;
			}
		}
		$attributes_schema = null;
		if ( 'page' !== $kind ) {
			if ( ! class_exists( '\\DirectoristElementor\\AI\\ElementorTemplateLoopCreation' ) ) {
				return null;
			}
			$attributes_schema = \DirectoristElementor\AI\ElementorTemplateLoopCreation::attributes_schema( $document, $scope, $facts );
			if ( ! $attributes_schema instanceof \stdClass ) {
				return null;
			}
		}
		return $parent instanceof \stdClass ? new self( $request, $result, $parent, is_array( $native ) ? count( $native ) : null, $attributes_schema ) : null;
	}


	/** Existing loops must each bind exactly one other directory in native runtime settings. */
	public static function native_loops_exclude_directory( array $elements, string $target, ?int $expected_loops = null ): bool {
		if ( ! preg_match( '/^[1-9][0-9]*$/D', $target ) ) {
			return false;
		}
		$count = 0;
		$loops = 0;
		$scan  = static function ( array $nodes, int $depth ) use ( &$scan, &$count, &$loops, $target ): bool {
			if ( $depth > 40 ) {
				return false;
			}
			foreach ( $nodes as $node ) {
				if ( ++$count > 1900 || ! $node instanceof \stdClass || ! is_array( $node->elements ?? array() ) ) {
					return false;
				}
				if ( in_array( $node->{'elType'} ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) {
					++$loops;
					$settings = $node->settings ?? null;
					$ids      = $settings instanceof \stdClass ? ( $settings->directory_type_ids ?? null ) : null;
					$active   = $settings->active_directory_type_id ?? null;
					$default  = $settings->default_directory_type_id ?? null;
					if ( ! is_array( $ids ) || array( 0 ) !== array_keys( $ids ) || ! ( is_string( $active ) || is_int( $active ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $active ) || (string) $active === $target || ! ( is_string( $ids[0] ) || is_int( $ids[0] ) ) || (string) $ids[0] !== (string) $active || ! ( is_string( $default ) || is_int( $default ) ) || (string) $default !== (string) $active || ( isset( $settings->directory_type_id ) && ( ! ( is_string( $settings->directory_type_id ) || is_int( $settings->directory_type_id ) ) || (string) $settings->directory_type_id !== (string) $active ) ) ) {
						return false;
					}
				}
				if ( ! $scan( $node->elements ?? array(), $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		};
		return $scan( $elements, 0 ) && ( null === $expected_loops || $expected_loops === $loops );
	}

	public function assert_graph( BuilderGraphResult $result ): void {
		if ( $this->document_key !== $result->document_key() || ! hash_equals( $this->version_hash, $result->version()->hash() ) || ! hash_equals( $this->graph_hash, $result->graph_hash() ) ) {
			throw new ContractException( 'stale_version', 'Elementor loop creation proof belongs to another saved graph.' );
		}
	}

	public function assert_snapshot( string $document_key, GraphVersion $version, string $graph_hash ): void {
		if ( $this->document_key !== $document_key || ! hash_equals( $this->version_hash, $version->hash() ) || ! hash_equals( $this->graph_hash, $graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Elementor loop creation proof differs from the published graph.' );
		}
	}

	public function assert_change( object $change ): void {
		$subtree = $change->subtree ?? null;
		$nodes   = $subtree->nodes ?? null;
		$root    = is_array( $nodes ) && 1 === count( $nodes ) ? $nodes[0] : null;
		if ( ( null !== $this->append_position && ( $change->position ?? null ) !== $this->append_position ) || 'insert' !== ( $change->op ?? null ) || $this->canonical->encode( $change->scope ?? null ) !== $this->canonical->encode( $this->scope ) || $this->canonical->encode( $change->parent ?? null ) !== $this->canonical->encode( $this->parent_locator ) || ! $root instanceof \stdClass || 'directorist-elementor' !== ( $root->provider_id ?? null ) || ( $root->native_id ?? null ) !== $this->loop_identity || ! $root->attributes instanceof \stdClass || ! $this->attributes_match( $root->attributes ) || ! is_array( $subtree->edges ?? null ) || $subtree->edges ) {
			throw new ContractException( 'scope_conflict', 'Only one exact Listings Loop may enter the selected new view.' );
		}
	}

	public function assert_normalized_change( object $change ): void {
		$subtree = $change->subtree ?? null;
		$nodes   = $subtree->nodes ?? null;
		$root    = is_array( $nodes ) && 1 === count( $nodes ) ? $nodes[0] : null;
		if ( ( null !== $this->append_position && ( $change->position ?? null ) !== $this->append_position ) || 'insert' !== ( $change->op ?? null ) || ! hash_equals( $this->parent_id, (string) ( $change->parent_id ?? '' ) ) || $this->canonical->encode( $change->scope ?? null ) !== $this->canonical->encode( $this->scope ) || ! $root instanceof \stdClass || 'directorist-elementor' !== ( $root->provider_id ?? null ) || ( $root->native_id ?? null ) !== $this->loop_identity || ! $root->attributes instanceof \stdClass || ! $this->attributes_match( $root->attributes ) || ( $subtree->root_node_id ?? null ) !== ( $root->node_id ?? null ) || ! is_array( $subtree->edges ?? null ) || $subtree->edges ) {
			throw new ContractException( 'scope_conflict', 'Reviewed Elementor loop no longer matches its exact parent and view.' );
		}
	}

	public function permits_parent( string $id, object $locator, object $scope ): bool {
		return $id === $this->parent_id && $this->canonical->encode( $locator ) === $this->canonical->encode( $this->parent_locator ) && $this->canonical->encode( $scope ) === $this->canonical->encode( $this->scope );
	}

	/** Candidate facts may use only this freshly minted proof's closed native values. */
	public function permits_attributes( object $attributes ): bool {
		return $this->attributes_match( $attributes );
	}

	/** Optional fields are a closed fresh native template schema, never generic controls. */
	private function attributes_match( object $attributes ): bool {
		if ( null === $this->attributes_schema ) {
			return ! get_object_vars( $attributes );
		}
		try {
			$schemas = new \DirectoristAIPro\Validation\SchemaRegistry();
			$schemas->register( 'native-template-loop-attributes', wp_json_encode( $this->attributes_schema ) );
			( new \DirectoristAIPro\Validation\ContractValidator( $schemas ) )->validate( $attributes, 'native-template-loop-attributes' );
			return true;
		} catch ( ContractException $error ) {
			return false;
		}
	}

	public function effect_scope(): string {
		return 'elementor_first_directory_loop_copy';
	}

	/** Exact parent and child scope shown with native authoring options. */
	public function authoring_hint(): object {
		$hint = (object) array(
			'operation'        => 'insert',
			'parent'           => $this->parent_locator,
			'parent_view'      => null,
			'scope'            => $this->scope,
			'provider_id'      => 'directorist-elementor',
			'native_id'        => $this->loop_identity,
			'attributes'       => new \stdClass(),
			'max_insert_nodes' => 1,
			'effect_scope'     => $this->effect_scope(),
		);
		if ( null !== $this->append_position ) {
			$hint->position = $this->append_position;
		}
		if ( null !== $this->attributes_schema ) {
			$hint->attributes_schema = json_decode( $this->canonical->encode( $this->attributes_schema ) );
			$hint->native_query_defaults = (object) array( 'query_mode' => 'default', 'directory_membership' => 'inherited runtime query; selected directory is editor design only' );
		}
		return $hint;
	}
}
