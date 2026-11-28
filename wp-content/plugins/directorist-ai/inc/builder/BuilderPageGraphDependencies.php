<?php
/**
 * Current native relational dependencies for separately reviewed page publication.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Read-only reuse of installed native graph contracts.

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** Reuses source readers and rules; no duplicate native schema inventory. */
final class BuilderPageGraphDependencies {

	public static function read( string $builder, int $page_id, string $media_version ): string {
		$integrations = new IntegrationRegistry();
		do_action( 'directorist_ai_pro_register_builder_providers', $integrations );
		$integrations->freeze();
		$providers = new BuilderGraphRegistry();
		do_action( 'directorist_ai_pro_register_builder_graph_providers', $providers );
		$providers->freeze();
		$provider = $providers->provider( $builder );
		if ( ! $provider instanceof BuilderNativeSourceReader ) {
			throw new ContractException( 'unsupported_dependency', 'Native publication graph reader is unavailable.' );
		}
		$context = new Context( Actor::user( get_current_user_id() ), 'builder', 'ui' );
		$request = new BuilderGraphInspectionRequest(
			$context,
			(object) array(
				'builder'     => $builder,
				'native_kind' => 'page',
				'native_id'   => 'post:' . $page_id,
			),
			(object) array(
				'directory_id'  => null,
				'view'          => null,
				'breakpoint_id' => null,
				'interaction'   => 'normal',
			),
			array(),
			null,
			null,
			new CatalogEnvironmentReader( $integrations )
		);
		$result  = $provider->inspect( $request );
		$request->assert_result( $result );
		// A saved page can explicitly bind several directories while its root
		// remains unscoped. Seed the reader from a native scoped node, never a
		// site default or model guess, so all its native bindings are resolved.
		$scopes = array();
		foreach ( $result->graph()->nodes as $node ) {
			if ( null !== ( $node->scope->directory_id ?? null ) ) {
				$scopes[ ( new JsonCanonicalizer() )->encode( $node->scope ) ] = $node->scope;
			}
		}
		if ( $scopes ) {
			ksort( $scopes, SORT_STRING );
			$scope   = reset( $scopes );
			$primary = ( new DirectoristDirectoryFactsReader( new DirectoristInspector() ) )->read( $context, (int) $scope->directory_id );
			if ( ! $primary->complete ) {
				throw new ContractException( 'unsupported_contract', 'Native page publication directory facts are incomplete.' );
			}
			$request = new BuilderGraphInspectionRequest( $context, $request->document(), $scope, array(), $primary, null, new CatalogEnvironmentReader( $integrations ) );
			$result  = $provider->inspect( $request );
			$request->assert_result( $result );
		}
		$rules = new SemanticRuleRegistry();
		do_action( 'directorist_ai_pro_register_builder_semantic_rules', $rules );
		$rules->freeze();
		$validation = ( new RelationshipValidator( $rules ) )->validate( $result->document_key(), $result->version(), $result->graph(), $result->facts() );
		if ( ! $validation->accepted ) {
			throw new ContractException( 'unsupported_contract', 'The current native page has unresolved or invalid relationships: ' . implode( ', ', array_unique( array_map( static fn( object $diagnostic ): string => $diagnostic->code, $validation->diagnostics ) ) ) );
		}
		$ids = array();
		foreach ( $result->graph()->nodes as $node ) {
			if ( null !== ( $node->scope->directory_id ?? null ) ) {
				$ids[] = (int) $node->scope->directory_id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_NUMERIC );
		if ( count( $ids ) > 16 ) {
			throw new ContractException( 'needs_expansion', 'Native page publication exceeds its directory dependency bound.' );
		}
		$reader      = new DirectoristDirectoryFactsReader( new DirectoristInspector() );
		$directories = array();
		foreach ( $ids as $id ) {
			$directory = $reader->read( $context, $id );
			if ( ! $directory->complete ) {
				throw new ContractException( 'unsupported_contract', 'Native page publication directory facts are incomplete.' );
			}
			$directories[] = (object) array(
				'id'       => $id,
				'revision' => $directory->revision,
			);
		}
		// Status, title and parent deliberately change in this reviewed effect.
		// Their raw checkpoint stays in the effect plan. Native dependencies are
		// versioned separately so that publication cannot reinterpret them.
		$facts   = $result->facts()->snapshot();
		$version = $result->version()->value();
		return hash(
			'sha256',
			( new JsonCanonicalizer() )->encode(
				(object) array(
					'environment'   => $version->environment_hash,
					'adapter'       => $version->adapter_revision,
					'rules'         => $validation->rule_set_revision,
					'normalization' => $version->normalization_revision,
					'directories'   => $directories,
					'references'    => $facts->references,
					'media'         => $media_version,
				)
			)
		);
	}
}
