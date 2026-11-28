<?php
/**
 * Turn a model-supplied semantic change into one reviewed native draft stage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Closed internal workflow boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Untrusted changes reach only the P4 planner and installed native compiler. */
final class BuilderDraftProposalService {
	private Database $db;
	private BuilderGraphRegistry $graphs;
	private BuilderDraftAdapterRegistry $adapters;
	private BuilderPlanningPolicyRegistry $policies;
	private RelationshipValidator $relationships;
	private CatalogEnvironmentReader $environment;
	private DirectoryFactsReader $directories;
	/**
	 * Current effective catalog revision for a guarded factory only.
	 *
	 * @var callable|null
	 */
	private $factory_catalog_revision;

	public function __construct( Database $db, BuilderGraphRegistry $graphs, BuilderDraftAdapterRegistry $adapters, BuilderPlanningPolicyRegistry $policies, RelationshipValidator $relationships, CatalogEnvironmentReader $environment, DirectoryFactsReader $directories, ?callable $factory_catalog_revision = null ) {
		$this->db                       = $db;
		$this->graphs                   = $graphs;
		$this->adapters                 = $adapters;
		$this->policies                 = $policies;
		$this->relationships            = $relationships;
		$this->environment              = $environment;
		$this->directories              = $directories;
		$this->factory_catalog_revision = $factory_catalog_revision;
	}

	/** Capture a saved native source, validate a semantic plan, then prepare an exact draft review. */
	public function propose( Context $context, object $input ): object {
		$context->assert_execution_identity();
		if ( $context->actor()->is_guest() || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || null === $context->conversation_id() || null === $context->run_id() || ! is_string( $input->request_id ?? null ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $input->request_id ) || ! in_array( $input->intent ?? null, array( 'private_page_draft', 'private_template_draft' ), true ) || ! is_array( $input->changes ?? null ) || 1 > count( $input->changes ) || 10 < count( $input->changes ) ) {
			throw new ContractException( 'unauthorized', 'Reviewed native draft proposal requires an active authenticated builder run.' );
		}
		$document = $input->document ?? null;
		if ( ! $document instanceof \stdClass || ! in_array( $document->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Native builder document is invalid.' );
		}
		if ( 'page' === ( $document->native_kind ?? null ) && 'private_page_draft' !== $input->intent ) {
			throw new ContractException( 'unsupported_operation', 'A saved page requires the private page draft destination.', array( 'native_page_draft_intent_required' ) );
		}
		$template_kind = is_string( $document->native_kind ?? null ) && ( str_starts_with( $document->native_kind, 'template:' ) || 'template_part' === $document->native_kind );
		if ( $template_kind && 'private_template_draft' !== $input->intent ) {
			throw new ContractException( 'unsupported_operation', 'A saved template requires the private template draft destination.', array( 'native_template_draft_intent_required' ) );
		}
		$reader  = $this->graphs->provider( $document->builder );
		$adapter = $this->adapters->adapter( $document->builder );
		if ( ! $reader instanceof BuilderNativeSourceReader || null === $adapter ) {
			throw new ContractException( 'unsupported_dependency', 'Installed native builder draft adapter is unavailable.' );
		}
		$directory_id = $input->directory_id ?? null;
		$facts        = null === $directory_id ? null : $this->directories->read( $context, $directory_id );
		$request      = new BuilderGraphInspectionRequest( $context, $document, $input->scope, array(), $facts, null, $this->environment );
		$projection   = ( new BuilderGraphProjectionService( $this->db, $this->relationships ) )->inspect( $reader, $request );
		$result       = $projection->result;
		BuilderCompoundCardStyle::assert_run( $this->db, $context, $result->graph(), $input );
		$factory_verified = false;
		if ( null !== $context->builder_factory_authority() ) {
			if ( null === $this->factory_catalog_revision ) {
				throw new ContractException( 'stale_version', 'Current native factory catalog authority is unavailable.' );
			}
			$catalog_revision = ( $this->factory_catalog_revision )( $context, $document, $directory_id );
			if ( ! is_string( $catalog_revision ) ) {
				throw new ContractException( 'stale_version', 'Current native factory catalog revision is unavailable.' );
			}
			$factory_method = 'elementor' === $document->builder ? 'page_layout_authoring_contract_for_graph' : 'authoring_contract_for_graph';
			if ( ! is_callable( array( $adapter->compiler, $factory_method ) ) ) {
				throw new ContractException( 'stale_version', 'Current native factory contract is unavailable.' );
			}
			$factory_contract = $adapter->compiler->$factory_method( $result->graph() );
			BuilderAuthoringContract::assert_metadata( $factory_contract );
			if ( ! ( $factory_contract->first_loop ?? null ) instanceof \stdClass ) {
				throw new ContractException( 'stale_version', 'Current native factory insertion is unavailable.' );
			}
			$capture = \DirectoristAIPro\UI\EditorWorkingCopy::editor_context( $document );
			BuilderFactoryAuthority::assert_current( $context, $input, $result, $catalog_revision, $this->relationships->rule_set_revision(), $capture, $factory_contract->first_loop );
			$factory_verified = true;
		}
		if ( 1 === count( $input->changes ) && 'configure_saved_variants' === ( $input->changes[0]->op ?? null ) ) {
			if ( isset( $input->directory_query ) ) { throw new ContractException( 'invalid_input', 'A saved-variant style transaction cannot change the directory query.' ); }
			return ( new BuilderSavedVariantControlsService( $this->db, $reader, $adapter->compiler, $this->environment, $this->directories ) )->stage( $request, $result, $input, $input->changes[0] );
		}
		if ( 1 === count( $input->changes ) && 'configure_directory_query' === ( $input->changes[0]->op ?? null ) ) {
			if ( isset( $input->directory_query ) ) {
				throw new ContractException( 'invalid_input', 'Use one directory configuration for this existing loop.' ); }
			return ( new BuilderDirectoryQueryService( $this->db, $reader, $adapter->compiler, $this->environment, $this->directories ) )->stage( $request, $result, $input, $input->changes[0] );
		}
		if ( 1 === count( $input->changes ) && 'configure_directory_binding' === ( $input->changes[0]->op ?? null ) ) {
			if ( isset( $input->directory_query ) ) {
				throw new ContractException( 'invalid_input', 'Use one native directory binding for this saved area.' );
			}
			return ( new BuilderDirectoryBindingService( $this->db, $reader, $adapter->compiler, $this->environment, $this->directories ) )->stage( $request, $result, $input, $input->changes[0] );
		}
		$copy          = BuilderPrivateCopyCoverage::for_editor_candidate( $request, $result ) ?? ( 'private_template_draft' === $input->intent ? BuilderPrivateCopyCoverage::for_saved_template( $request, $result ) : null );
		$loop_creation = BuilderElementorLoopCreationProof::for_saved_page( $request, $result );
		if ( null !== $loop_creation ) {
			if ( 1 !== count( $input->changes ) ) {
				throw new ContractException( 'scope_conflict', 'New Elementor view accepts one reviewed Listings Loop insertion.' );
			}
			$loop_creation->assert_change( $input->changes[0] );
		}
		$copy_ready = null !== $copy && isset( $projection->private_copy_report ) && $projection->private_copy_report->accepted;
		if ( 'complete' !== $result->completeness() || ( ! ( $projection->semantic_report->accepted && 'complete' === $result->consumer_coverage() ) && ! $copy_ready ) ) {
			throw new ContractException( 'incomplete_graph', 'Saved native builder relationships are not complete for a reviewed draft.' );
		}
		// Native recipes expand before planning; reviewed artifacts contain the full validated tree.
		$changes = $input->changes;
		if ( isset( $input->directory_query ) && 'gutenberg' === $document->builder && 'page' === $document->native_kind && is_callable( array( $adapter->compiler, 'expand_initial_directory_changes' ) ) ) {
			try {
				$changes = ( new BuilderDirectoryQueryService( $this->db, $reader, $adapter->compiler, $this->environment, $this->directories ) )->expand_initial_changes( $request, $result, $changes, $input->directory_query );
			} catch ( ContractException $error ) {
				if ( self::repairable_initial_field_failure( $error, $factory_verified ) ) {
					// The factory fence passed before expansion. Recheck current native
					// authority before classifying only this fixed capability rejection.
					$request->assert_environment_current();
					$reader->read_saved_source( $request, $result );
					throw new ContractException( 'invalid_input', 'A requested native card field is not configured in every selected directory.', array( 'initial_target_card_field_unavailable' ) );
				}
				throw $error;
			}
		}

		$native_preset_view = null !== $loop_creation && in_array( $input->scope->view, array( 'grid', 'list' ), true ) ? $input->scope->view : null;
		if ( is_callable( array( $adapter->compiler, 'native_preset_view_for_changes' ) ) ) {
			$certified_view = $adapter->compiler->native_preset_view_for_changes( $result->graph(), $changes );
			if ( null !== $certified_view && ! in_array( $certified_view, array( 'grid', 'list' ), true ) ) {
				throw new ContractException( 'unsupported_contract', 'Native layout view certification is invalid.' );
			}
			$native_preset_view = $certified_view ?? $native_preset_view;
		}
		if ( $adapter->compiler instanceof BuilderDraftAuthoringExpander ) {
			$changes = $adapter->compiler->expand_authoring_changes( $result->graph(), $changes );
			$marker  = $input->changes[0]->subtree->nodes[0]->attributes->native_loop_recipe ?? null;
			if ( 1 === count( $input->changes ) && in_array( $marker, array( 'directory_grid', 'archive_default', 'home_search_default' ), true ) ) {
				// This marker has already passed the installed closed recipe expander.
				$native_preset_view = 'grid';
			}
		}
		$source_operation_id = 'builder-source-' . substr( hash( 'sha256', $context->run_id() . "\0" . $result->document_key() . "\0" . $result->version()->hash() ), 0, 32 );
		$source_ref          = ( new BuilderNativeCheckpointService( $this->db ) )->capture( $reader, $request, $result, $source_operation_id, true, $copy );
		$planner             = ( new BuilderChangePlannerFactory( $this->db, new GraphSnapshotStore( $this->db ), $this->relationships, $this->policies ) )->create( $adapter->facts->create( $request ) );
		$proposal            = $planner->propose( $context, $input->request_id . '-proposal', $result->document_key(), $result->version(), $input->scope, $changes, array(), null, null, $copy, $loop_creation );
		$stage               = ( new BuilderDraftStagePreparationService( $this->db, $planner ) )->prepare( $reader, $adapter->compiler, $request, $result, $proposal->artifact, $proposal->review->id, $source_ref, $source_operation_id, $input->request_id . '-stage', $input->intent, $native_preset_view, $input->directory_query ?? null );
		return (object) array(
			'operation_id'        => $stage->operation_id,
			'artifact'            => $stage->artifact,
			'review'              => $stage->review,
			'builder'             => $document->builder,
			'document_key'        => $result->document_key(),
			'intent'              => $input->intent,
			'native_content_hash' => $stage->native_content_hash,
			'block_count'         => $stage->block_count,
			'output_mode'         => $stage->output_mode,
		);
	}

	/**
	 * Only the native role marker after a verified factory may use proposal repair.
	 *
	 * @param ContractException $error Original native capability rejection.
	 * @param bool              $factory_verified Current cold factory fence passed.
	 * @return bool Whether this sole native capability marker is repairable.
	 */
	private static function repairable_initial_field_failure( ContractException $error, bool $factory_verified ): bool {
		return $factory_verified
			&& 'unsupported_dependency' === $error->error_code()
			&& array( 'initial_target_card_field_unavailable' ) === $error->repair_codes();
	}
}
