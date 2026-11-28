<?php
/**
 * Registers Directorist domain reads with the shared capability gateway.
 *
 * @package DirectoristAIPro
 */

// phpcs:disable NormalizedArrays.Arrays.CommaAfterLast.MissingMultiLine -- Retain exact reviewed PHP tokens.
namespace DirectoristAIPro\Domain;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\EffectHandlerRegistry;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Validation\SchemaRegistry;
use DirectoristAIPro\Storage\Database;

/** Domain code owns native field semantics; the model receives closed contracts. */
final class DirectoristModule {
	/**
	 * Shared proposal service for operation and effect registration.
	 *
	 * @var ListingProposalService|null
	 */
	private ?ListingProposalService $proposals = null;
	/**
	 * Shared moderation service.
	 *
	 * @var ListingModerationService|null
	 */
	private ?ListingModerationService $moderation = null;
	/**
	 * Shared taxonomy service.
	 *
	 * @var TaxonomyService|null
	 */
	private ?TaxonomyService $taxonomy = null;
	/**
	 * Optional isolated runtime storage.
	 *
	 * @var Database|null
	 */
	private ?Database $db;

	/**
	 * Bind optional isolated storage for integration tests.
	 *
	 * @param Database|null $db Optional runtime storage.
	 */
	public function __construct( ?Database $db = null ) {
		$this->db = $db;
	}

	/** Register the module before the runtime freezes operation definitions. */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'directorist_ai_pro_register_effect_handlers', array( $this, 'register_effects' ) );
	}

	/**
	 * Register read-only directory and listing inspection operations.
	 *
	 * @param Registry       $registry Shared operation registry.
	 * @param SchemaRegistry $schemas Shared strict schemas.
	 */
	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		$inspector = new DirectoristInspector();
		$schemas->register( 'directory.describe.input', wp_json_encode( $this->directory_input_schema() ) );
		$schemas->register( 'directory.describe.output', wp_json_encode( $this->directory_output_schema() ) );
		$schemas->register( 'listing.inspect.input', wp_json_encode( $this->listing_input_schema() ) );
		$schemas->register( 'listing.inspect.output', wp_json_encode( $this->listing_output_schema() ) );
		$registry->register(
			new Operation(
				array(
					'id'            => 'directory.describe',
					'revision'      => '1',
					'description'   => 'Describe one current Directorist directory schema and its allowed taxonomy values.',
					'input_schema'  => 'directory.describe.input',
					'output_schema' => 'directory.describe.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'owner', 'visitor', 'builder', 'external' ),
					'allow_guest'   => true,
				),
				static fn( object $input, Context $context ): bool => $inspector->can_describe( $context, $input->directory_id, $input->purpose ),
				static fn(): bool => true,
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $inspector->describe( $context, $input->directory_id, $input->purpose )
			)
		);
		$catalog = new ListingQueryCatalog( $inspector );
		$schemas->register( 'listing.catalog.input', wp_json_encode( $this->catalog_input_schema() ) );
		$schemas->register( 'listing.catalog.output', wp_json_encode( $this->catalog_output_schema() ) );
		$schemas->register( 'listing.search.input', wp_json_encode( $this->search_input_schema() ) );
		$schemas->register( 'listing.search.output', wp_json_encode( $this->search_output_schema() ) );
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact catalog metadata.
		$registry->register(
			new Operation(
				array( 'id' => 'listing.catalog', 'revision' => '1', 'description' => 'Describe an authorized logical listing query catalog without storage mappings.', 'input_schema' => 'listing.catalog.input', 'output_schema' => 'listing.catalog.output', 'effect' => 'read', 'profiles' => array( 'admin', 'owner', 'visitor', 'external' ), 'allow_guest' => true ),
				static fn( object $input, Context $context ): bool => $catalog->permitted( $context, $input->directory_id, $input->dataset ),
				static fn(): bool => true,
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $catalog->describe( $context, $input->directory_id, $input->dataset )
			)
		);
		$query = new ListingQueryService( $inspector, $catalog, new ListingQueryCursor( wp_salt( 'auth' ) ), 1000, null, $this->db );
		$registry->register(
			new Operation(
				array( 'id' => 'listing.search', 'revision' => '1', 'description' => 'Execute one bounded authorized logical listing query without SQL input.', 'input_schema' => 'listing.search.input', 'output_schema' => 'listing.search.output', 'effect' => 'read', 'profiles' => array( 'admin', 'owner', 'visitor', 'external' ), 'allow_guest' => true ),
				static fn( object $input, Context $context ): bool => $catalog->permitted( $context, $input->directory_id, $input->dataset ),
				static fn(): bool => true,
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $query->search( $context, $input )
			)
		);
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.inspect',
					'revision'      => '1',
					'description'   => 'Inspect exact editable Directorist listings through their current directory schema.',
					'input_schema'  => 'listing.inspect.input',
					'output_schema' => 'listing.inspect.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'owner', 'builder', 'external' ),
					'allow_guest'   => false,
				),
				static fn( object $input, Context $context ): bool => $inspector->can_inspect( $context, $input->directory_id, $input->listing_ids ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest() && ( $inspector->can_edit_listings( $context ) || $context->actor()->can( get_post_type_object( ATBDP_POST_TYPE )->cap->create_posts ?? 'edit_posts' ) ),
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $inspector->inspect( $context, $input->directory_id, $input->listing_ids )
			)
		);
		$schemas->register( 'listing.propose.input', wp_json_encode( $this->proposal_input_schema() ) );
		$schemas->register( 'listing.propose.output', wp_json_encode( $this->proposal_output_schema() ) );
		$schemas->register( 'listing.apply.input', wp_json_encode( $this->apply_input_schema() ) );
		$schemas->register( 'listing.apply.output', wp_json_encode( $this->apply_output_schema() ) );
		$schemas->register( 'listing.create.input', wp_json_encode( $this->apply_input_schema() ) );
		$schemas->register( 'listing.create.output', wp_json_encode( $this->apply_output_schema() ) );
		$proposals = $this->proposal_service( $inspector );
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.propose',
					'revision'      => '1',
					'description'   => 'Create one private version-bound listing change or new-listing proposal and pending human review.',
					'input_schema'  => 'listing.propose.input',
					'output_schema' => 'listing.propose.output',
					'effect'        => 'private',
					'profiles'      => array( 'admin', 'owner', 'external' ),
					'allow_guest'   => false,
				),
				static fn( object $input, Context $context ): bool => $proposals->permitted( $context, $input ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest() && ( $inspector->can_edit_listings( $context ) || $inspector->can_create_listings( $context ) ),
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $proposals->propose( $context, $input )
			)
		);
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.apply',
					'revision'      => '1',
					'description'   => 'Apply one exact human-approved Directorist listing proposal.',
					'input_schema'  => 'listing.apply.input',
					'output_schema' => 'listing.apply.output',
					'effect'        => 'write',
					'profiles'      => array( 'admin', 'owner', 'external' ),
					'allow_guest'   => false,
					'retry_policy'  => 'reconcile',
				),
				static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_edit_listings( $context ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_edit_listings( $context ),
				static fn(): bool => $inspector->available(),
				static fn(): object => (object) array()
			)
		);
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.create',
					'revision'      => '1',
					'description'   => 'Submit one exact reviewed new Directorist listing through its native form.',
					'input_schema'  => 'listing.create.input',
					'output_schema' => 'listing.create.output',
					'effect'        => 'write',
					'profiles'      => array( 'admin', 'owner', 'external' ),
					'allow_guest'   => false,
					'retry_policy'  => 'reconcile'
				),
				static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest(),
				static fn( Context $context ): bool => ! $context->actor()->is_guest(),
				static fn(): bool => $inspector->available(),
				static fn(): object => (object) array()
			)
		);
		$schemas->register(
			'media.propose.input',
			wp_json_encode(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'directory_id' => array(
							'type'    => 'integer',
							'minimum' => 1
						),
						'listing_id'   => array(
							'type'    => 'integer',
							'minimum' => 1
						),
						'kind'         => array( 'enum' => array( 'text', 'image' ) ),
						'brief'        => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 400
						),
						'field_id'     => array(
							'type'      => 'string',
							'maxLength' => 64
						),
						'slot'         => array(
							'type' => array( 'string', 'null' ),
							'enum' => array( 'preview', 'gallery', null )
						)
					),
					'required'             => array( 'directory_id', 'listing_id', 'kind', 'brief', 'field_id', 'slot' ),
					'additionalProperties' => false
				)
			)
		);
		$schemas->register(
			'media.propose.output',
			wp_json_encode(
				array(
					'type'                 => 'object',
					'properties'           => array(
						'status'       => array( 'enum' => array( 'ready', 'staged', 'use_listing_propose', 'image_unavailable' ) ),
						'capability'   => array( 'const' => 'media.import' ),
						'artifact'     => array( 'type' => 'object' ),
						'operation_id' => array( 'type' => 'string' ),
						'review'       => array( 'type' => 'object' )
					),
					'required'             => array( 'status' ),
					'additionalProperties' => false
				)
			)
		);
		$registry->register(
			new Operation(
				array(
					'id'            => 'media.propose',
					'revision'      => '1',
					'description'   => 'For text use listing.propose; for an image check native eligibility before the budgeted worker generates one private reviewable suggestion.',
					'input_schema'  => 'media.propose.input',
					'output_schema' => 'media.propose.output',
					'effect'        => 'private',
					'profiles'      => array( 'admin', 'owner' ),
					'allow_guest'   => false
				),
				static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_inspect( $context, $input->directory_id, array( $input->listing_id ) ) && ( 'text' === $input->kind || ( 'image' === $input->kind && 'images' === $input->field_id && MediaSuggestionService::permitted( $inspector, $context, $input->directory_id, $input->listing_id, $input->slot ) ) ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_edit_listings( $context ),
				static fn(): bool => $inspector->available(),
				static fn( object $input ): object => (object) array( 'status' => 'text' === $input->kind ? 'use_listing_propose' : ( MediaSuggestionService::image_provider_available() ? 'ready' : 'image_unavailable' ) )
			)
		);
		$schemas->register( 'media.import.input', wp_json_encode( $this->apply_input_schema() ) );
		$schemas->register( 'media.import.output', wp_json_encode( $this->apply_output_schema() ) );
		$registry->register(
			new Operation(
				array(
					'id'            => 'media.import',
					'revision'      => '1',
					'description'   => 'Import one reviewed trusted inline image to a native Directorist attachment and image field.',
					'input_schema'  => 'media.import.input',
					'output_schema' => 'media.import.output',
					'effect'        => 'write',
					'profiles'      => array( 'admin', 'owner', 'external' ),
					'allow_guest'   => false,
					'retry_policy'  => 'reconcile'
				),
				static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_edit_listings( $context ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest() && $inspector->can_edit_listings( $context ),
				static fn(): bool => $inspector->available(),
				static fn(): object => (object) array()
			)
		);
		$schemas->register( 'listing.bulk_propose.input', wp_json_encode( $this->moderation_input_schema() ) );
		$schemas->register( 'listing.bulk_propose.output', wp_json_encode( $this->moderation_output_schema() ) );
		$schemas->register( 'listing.bulk_apply.input', wp_json_encode( $this->apply_input_schema() ) );
		$schemas->register( 'listing.bulk_apply.output', wp_json_encode( $this->apply_output_schema() ) );
		$moderation = $this->moderation_service( $inspector );
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.bulk_propose',
					'revision'      => '1',
					'description'   => 'Create one bounded explicit listing moderation review.',
					'input_schema'  => 'listing.bulk_propose.input',
					'output_schema' => 'listing.bulk_propose.output',
					'effect'        => 'private',
					'profiles'      => array( 'admin', 'external' ),
					'allow_guest'   => false,
				),
				static fn( object $input, Context $context ): bool => $moderation->permitted( $context, $input ),
				static fn( Context $context ): bool => ! $context->actor()->is_guest(),
				static fn(): bool => $inspector->available(),
				static fn( object $input, Context $context ): object => $moderation->propose( $context, $input )
			)
		);
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.bulk_apply',
					'revision'      => '1',
					'description'   => 'Apply one exact reviewed listing moderation batch.',
					'input_schema'  => 'listing.bulk_apply.input',
					'output_schema' => 'listing.bulk_apply.output',
					'effect'        => 'write',
					'profiles'      => array( 'admin', 'external' ),
					'allow_guest'   => false,
					'retry_policy'  => 'reconcile',
				),
				static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest(),
				static fn( Context $context ): bool => ! $context->actor()->is_guest(),
				static fn(): bool => $inspector->available(),
				static fn(): object => (object) array()
			)
		);
		$schemas->register( 'taxonomy.propose.input', wp_json_encode( $this->taxonomy_input_schema() ) );
		$schemas->register( 'taxonomy.propose.output', wp_json_encode( $this->taxonomy_output_schema() ) );
		$schemas->register( 'taxonomy.apply.input', wp_json_encode( $this->apply_input_schema() ) );
		$schemas->register( 'taxonomy.apply.output', wp_json_encode( $this->apply_output_schema() ) );
		$taxonomy = $this->taxonomy_service();
		// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact operation metadata.
		$registry->register( new Operation( array( 'id' => 'taxonomy.propose', 'revision' => '1', 'description' => 'Propose one narrow Directorist category or location change.', 'input_schema' => 'taxonomy.propose.input', 'output_schema' => 'taxonomy.propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'external' ), 'allow_guest' => false ), static fn( object $input, Context $context ): bool => $taxonomy->permitted( $context, $input ), static fn(): bool => true, static fn(): bool => $inspector->available(), static fn( object $input, Context $context ): object => $taxonomy->propose( $context, $input ) ) );
		$registry->register( new Operation( array( 'id' => 'taxonomy.apply', 'revision' => '1', 'description' => 'Apply one exact reviewed Directorist taxonomy change.', 'input_schema' => 'taxonomy.apply.input', 'output_schema' => 'taxonomy.apply.output', 'effect' => 'write', 'profiles' => array( 'admin', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), static fn( object $input, Context $context ): bool => ! $context->actor()->is_guest(), static fn(): bool => true, static fn(): bool => $inspector->available(), static fn(): object => (object) array() ) );
		// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Register the exact native listing effect implementation.
	 *
	 * @param EffectHandlerRegistry $handlers Trusted effect registry.
	 */
	public function register_effects( EffectHandlerRegistry $handlers ): void {
		$inspector = new DirectoristInspector();
		$handlers->register( 'listing.apply', new ListingEffectHandler( $this->proposal_service( $inspector ), $inspector ) );
		$handlers->register( 'listing.create', new ListingCreationHandler( $this->proposal_service( $inspector ), $inspector ) );
		$handlers->register( 'media.import', new MediaImportHandler( new MediaSuggestionService( $this->db, $inspector, new ApprovalService( $this->db ) ), $inspector ) );
		$handlers->register( 'listing.bulk_apply', new ListingModerationHandler( $this->moderation_service( $inspector ), $inspector ) );
		$handlers->register( 'taxonomy.apply', new TaxonomyEffectHandler( $this->taxonomy_service() ) );
	}

	/** Directory request schema. */
	private function directory_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'purpose'      => array( 'enum' => array( 'public_search', 'submission', 'management' ) ),
			),
			'required'             => array( 'directory_id', 'purpose' ),
			'additionalProperties' => false,
		);
	}

	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact catalog schemas.
	/** Logical catalog input schema. */
	private function catalog_input_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'dataset' => array( 'enum' => array( 'public', 'own', 'management' ) ) ), 'required' => array( 'directory_id', 'dataset' ), 'additionalProperties' => false );
	}

	/** Logical catalog output schema. */
	private function catalog_output_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'contract' => array( 'const' => 'directorist-ai/catalog-v1' ), 'directory_id' => array( 'type' => 'integer' ), 'dataset' => array( 'enum' => array( 'public', 'own', 'management' ) ), 'revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'schema_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'fields' => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ), 'limitations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ), 'required' => array( 'contract', 'directory_id', 'dataset', 'revision', 'schema_hash', 'fields', 'limitations' ), 'additionalProperties' => false );
	}

	/** Bounded logical listing query input schema. */
	private function search_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'dataset'          => array( 'enum' => array( 'public', 'own', 'management' ) ),
				'catalog_revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'where'            => $this->predicate_schema( 4, true ),
				'order'            => array(
					'type'     => 'array',
					'maxItems' => 3,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'field'     => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$' ),
							'direction' => array( 'enum' => array( 'asc', 'desc' ) ),
						),
						'required'             => array( 'field', 'direction' ),
						'additionalProperties' => false,
					),
				),
				'limit'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
				'cursor'           => array( 'type' => array( 'string', 'null' ), 'maxLength' => 4096 ),
				'include_count'    => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'directory_id', 'dataset', 'catalog_revision', 'where', 'order', 'limit', 'cursor', 'include_count' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Recursively close the four-level Boolean predicate grammar.
	 *
	 * @param int  $remaining_depth Remaining nested groups.
	 * @param bool $root Whether empty all is allowed.
	 */
	private function predicate_schema( int $remaining_depth, bool $root = false ): array {
		$leaf     = array(
			'type'                 => 'object',
			'properties'           => array(
				'field' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$' ),
				'op'    => array( 'enum' => array( 'eq', 'in', 'lt', 'lte', 'gt', 'gte', 'between', 'text', 'exists', 'near' ) ),
				'value' => true,
			),
			'required'             => array( 'field', 'op', 'value' ),
			'additionalProperties' => false,
		);
		$variants = array( $leaf );
		if ( $remaining_depth > 0 ) {
			$child      = $this->predicate_schema( $remaining_depth - 1 );
			$variants[] = array( 'type' => 'object', 'properties' => array( 'all' => array( 'type' => 'array', 'minItems' => $root ? 0 : 1, 'maxItems' => 20, 'items' => $child ) ), 'required' => array( 'all' ), 'additionalProperties' => false );
			$variants[] = array( 'type' => 'object', 'properties' => array( 'any' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => $child ) ), 'required' => array( 'any' ), 'additionalProperties' => false );
		}
		return array( 'oneOf' => $variants );
	}

	/** Strict logical search response schema. */
	private function search_output_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'         => array( 'const' => 'directorist-ai/search-v1' ),
				'directory_id'     => array( 'type' => 'integer' ),
				'dataset'          => array( 'enum' => array( 'public', 'own', 'management' ) ),
				'catalog_revision' => $hash,
				'query_hash'       => $hash,
				'items'            => array( 'type' => 'array', 'maxItems' => 50, 'items' => $this->listing_item_schema() ),
				'next_cursor'      => array( 'type' => array( 'string', 'null' ) ),
				'count'            => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ),
				'diagnostics'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'candidate_count' => array( 'type' => 'integer', 'minimum' => 0 ),
						'predicate_count' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 20 ),
						'boolean_depth'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 4 ),
						'candidate_limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000 ),
						'strategy'        => array( 'enum' => array( 'native_batches' ) ),
						'batch_count'     => array( 'type' => 'integer', 'minimum' => 0 ),
					),
					'required'             => array( 'candidate_count', 'predicate_count', 'boolean_depth', 'candidate_limit' ),
					'additionalProperties' => false,
				),
				'limitations'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'contract', 'directory_id', 'dataset', 'catalog_revision', 'query_hash', 'items', 'next_cursor', 'count', 'diagnostics', 'limitations' ),
			'additionalProperties' => false,
		);
	}

	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	/** Directory response schema. */
	private function directory_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'         => array( 'const' => 'directorist-ai/directory-v1' ),
				'directory'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
						'slug' => array( 'type' => 'string' ),
					),
					'required'             => array( 'id', 'name', 'slug' ),
					'additionalProperties' => false,
				),
				'purpose'          => array( 'enum' => array( 'public_search', 'submission', 'management' ) ),
				'schema_hash'      => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'environment_hash' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'complete'         => array( 'type' => 'boolean' ),
				'groups'           => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'label'  => array( 'type' => 'string' ),
							'fields' => array(
								'type'  => 'array',
								'items' => array( 'type' => 'string' ),
							),
						),
						'required'             => array( 'label', 'fields' ),
						'additionalProperties' => false,
					),
				),
				'fields'           => array(
					'type'  => 'array',
					'items' => $this->field_schema(),
				),
				'taxonomies'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'field_id'  => array( 'type' => 'string' ),
							'taxonomy'  => array( 'type' => 'string' ),
							'terms'     => array(
								'type'  => 'array',
								'items' => $this->term_schema(),
							),
							'truncated' => array( 'type' => 'boolean' ),
						),
						'required'             => array( 'field_id', 'taxonomy', 'terms', 'truncated' ),
						'additionalProperties' => false,
					),
				),
				'moderation'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'create_status' => array( 'type' => 'string' ),
						'edit_status'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'create_status', 'edit_status' ),
					'additionalProperties' => false,
				),
				'limitations'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'contract', 'directory', 'purpose', 'schema_hash', 'environment_hash', 'complete', 'groups', 'fields', 'taxonomies', 'moderation', 'limitations' ),
			'additionalProperties' => false,
		);
	}

	/** Listing request schema. */
	private function listing_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'listing_ids'  => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 20,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'required'             => array( 'directory_id', 'listing_ids' ),
			'additionalProperties' => false,
		);
	}

	/** Listing response schema. */
	private function listing_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'     => array( 'const' => 'directorist-ai/listings-v1' ),
				'directory_id' => array( 'type' => 'integer' ),
				'schema_hash'  => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'complete'     => array( 'type' => 'boolean' ),
				'listings'     => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => $this->listing_item_schema(),
				),
				'limitations'  => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'contract', 'directory_id', 'schema_hash', 'complete', 'listings', 'limitations' ),
			'additionalProperties' => false,
		);
	}

	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact schema leaves.
	/** Shared strict listing fact envelope used by inspection and search. */
	private function listing_item_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'status'      => array( 'type' => 'string' ),
				'author_id'   => array( 'type' => 'integer' ),
				'complete'    => array( 'type' => 'boolean' ),
				'version'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'modified_gmt' => array( 'type' => 'string' ),
						'content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'schema_hash'  => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					),
					'required'             => array( 'modified_gmt', 'content_hash', 'schema_hash' ),
					'additionalProperties' => false,
				),
				'plan'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => array( 'type' => array( 'integer', 'null' ) ),
						'complete' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'id', 'complete' ),
					'additionalProperties' => false,
				),
				'fields'      => array( 'type' => 'array', 'items' => $this->fact_schema() ),
				'limitations' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'id', 'status', 'author_id', 'complete', 'version', 'plan', 'fields', 'limitations' ),
			'additionalProperties' => false,
		);
	}
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact JSON Schema leaves remain readable.
	/** Listing proposal input schema. */
	private function proposal_input_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'listing_id'   => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
				'base'         => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'target'       => array( 'type' => 'object', 'properties' => array( 'kind' => array( 'const' => 'listing' ), 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'required' => array( 'kind', 'id' ), 'additionalProperties' => false ),
						'modified_gmt' => array( 'type' => 'string' ),
						'content_hash' => $hash,
						'schema_hash'  => $hash,
					),
					'required'             => array( 'target', 'modified_gmt', 'content_hash', 'schema_hash' ),
					'additionalProperties' => false,
				),
				'changes'      => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 50,
					'items'    => array( 'type' => 'object', 'properties' => array( 'field_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$' ), 'action' => array( 'enum' => array( 'set', 'clear' ) ), 'value' => true ), 'required' => array( 'field_id', 'action', 'value' ), 'additionalProperties' => false ),
				),
			),
			'required'             => array( 'directory_id', 'listing_id', 'base', 'changes' ),
			'additionalProperties' => false,
		);
	}

	/** Listing proposal output schema. */
	private function proposal_output_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'artifact'         => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'version' => array( 'type' => 'integer' ), 'hash' => $hash ), 'required' => array( 'id', 'version', 'hash' ), 'additionalProperties' => false ),
				'capability'       => array( 'enum' => array( 'listing.apply', 'listing.create' ) ),
				'operation_id'     => array( 'type' => 'string' ),
				'review'           => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ), 'review_hash' => $hash, 'expires_at' => array( 'type' => 'string' ) ), 'required' => array( 'id', 'state', 'review_hash', 'expires_at' ), 'additionalProperties' => false ),
				'diff'             => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'properties' => array( 'field_id' => array( 'type' => 'string' ), 'before' => true, 'after' => true ), 'required' => array( 'field_id', 'before', 'after' ), 'additionalProperties' => false ) ),
				'predicted_status' => array( 'type' => 'string' ),
			),
			'required'             => array( 'artifact', 'operation_id', 'review', 'diff', 'predicted_status' ),
			'additionalProperties' => false,
		);
	}

	/** Exact approval execution input schema. */
	private function apply_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'approval_id' => array( 'type' => 'string' ), 'operation_id' => array( 'type' => 'string' ), 'review_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ),
			'required'             => array( 'approval_id', 'operation_id', 'review_hash' ),
			'additionalProperties' => false,
		);
	}

	/** Durable effect receipt schema. */
	private function apply_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'operation_id' => array( 'type' => 'string' ), 'plan_hash' => array( 'type' => 'string' ), 'proposal_hash' => array( 'type' => 'string' ), 'target_set_hash' => array( 'type' => 'string' ), 'state' => array( 'enum' => array( 'succeeded', 'failed', 'partial', 'unknown' ) ), 'plan' => array( 'type' => 'object' ), 'targets' => array( 'type' => 'object' ), 'external_effects' => array( 'type' => 'array' ), 'observed_at' => array( 'type' => 'string' ) ),
			'required'             => array( 'operation_id', 'plan_hash', 'proposal_hash', 'target_set_hash', 'state', 'plan', 'targets', 'external_effects', 'observed_at' ),
			'additionalProperties' => false,
		);
	}

	/** Bounded explicit moderation proposal schema. */
	private function moderation_input_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$base = array( 'type' => 'object', 'properties' => array( 'modified_gmt' => array( 'type' => 'string' ), 'content_hash' => $hash, 'schema_hash' => $hash ), 'required' => array( 'modified_gmt', 'content_hash', 'schema_hash' ), 'additionalProperties' => false );
		return array( 'type' => 'object', 'properties' => array( 'action' => array( 'enum' => array( 'publish', 'unpublish' ) ), 'targets' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 20, 'items' => array( 'type' => 'object', 'properties' => array( 'listing_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'base' => $base ), 'required' => array( 'listing_id', 'directory_id', 'base' ), 'additionalProperties' => false ) ) ), 'required' => array( 'action', 'targets' ), 'additionalProperties' => false );
	}

	/** Moderation proposal/review response schema. */
	private function moderation_output_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array( 'type' => 'object', 'properties' => array( 'artifact' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'version' => array( 'type' => 'integer' ), 'hash' => $hash ), 'required' => array( 'id', 'version', 'hash' ), 'additionalProperties' => false ), 'operation_id' => array( 'type' => 'string' ), 'review' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ), 'review_hash' => $hash, 'expires_at' => array( 'type' => 'string' ) ), 'required' => array( 'id', 'state', 'review_hash', 'expires_at' ), 'additionalProperties' => false ), 'targets' => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'object' ) ) ), 'required' => array( 'artifact', 'operation_id', 'review', 'targets' ), 'additionalProperties' => false );
	}

	/** Narrow taxonomy proposal schema. */
	private function taxonomy_input_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'taxonomy' => array( 'enum' => array( 'category', 'location' ) ), 'action' => array( 'enum' => array( 'create', 'rename', 'reparent' ) ), 'term_id' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ), 'name' => array( 'type' => array( 'string', 'null' ), 'maxLength' => 200 ), 'parent_id' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 0 ), 'base' => array( 'type' => array( 'object', 'null' ), 'properties' => array( 'content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ), 'required' => array( 'content_hash' ), 'additionalProperties' => false ) ), 'required' => array( 'directory_id', 'taxonomy', 'action', 'term_id', 'name', 'parent_id', 'base' ), 'additionalProperties' => false );
	}

	/** Taxonomy proposal/review response schema. */
	private function taxonomy_output_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array( 'type' => 'object', 'properties' => array( 'artifact' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'version' => array( 'type' => 'integer' ), 'hash' => $hash ), 'required' => array( 'id', 'version', 'hash' ), 'additionalProperties' => false ), 'operation_id' => array( 'type' => 'string' ), 'review' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ), 'review_hash' => $hash, 'expires_at' => array( 'type' => 'string' ) ), 'required' => array( 'id', 'state', 'review_hash', 'expires_at' ), 'additionalProperties' => false ), 'before' => true, 'after' => array( 'type' => 'object' ) ), 'required' => array( 'artifact', 'operation_id', 'review', 'before', 'after' ), 'additionalProperties' => false );
	}
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

	/** Closed directory field descriptor schema. */
	private function field_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'field_id'          => array( 'type' => 'string' ),
				'widget'            => array( 'type' => 'string' ),
				'type'              => array( 'type' => 'string' ),
				'label'             => array( 'type' => 'string' ),
				'required'          => array( 'type' => 'boolean' ),
				'editable'          => array( 'type' => 'boolean' ),
				'admin_only'        => array( 'type' => 'boolean' ),
				'conditional_logic' => array(
					'type'                 => array( 'object', 'null' ),
					'additionalProperties' => true,
				),
				'allowed_values'    => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'value' => true,
							'label' => array( 'type' => 'string' ),
						),
						'required'             => array( 'value', 'label' ),
						'additionalProperties' => false,
					),
				),
				'limits'            => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => array( 'string', 'number' ) ),
				),
			),
			'required'             => array( 'field_id', 'widget', 'type', 'label', 'required', 'editable', 'admin_only', 'conditional_logic', 'allowed_values', 'limits' ),
			'additionalProperties' => false,
		);
	}

	/** Closed taxonomy term schema. */
	private function term_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'        => array( 'type' => 'integer' ),
				'name'      => array( 'type' => 'string' ),
				'parent_id' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'id', 'name', 'parent_id' ),
			'additionalProperties' => false,
		);
	}

	/** Closed listing fact/source schema with an intentionally polymorphic value. */
	private function fact_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'field_id' => array( 'type' => 'string' ),
				'known'    => array( 'type' => 'boolean' ),
				'value'    => true,
				'editable' => array( 'type' => 'boolean' ),
				'source'   => array(
					'type'                 => 'object',
					'properties'           => array(
						'kind'        => array( 'enum' => array( 'post', 'meta', 'term' ) ),
						'object_id'   => array( 'type' => 'string' ),
						'key'         => array( 'type' => 'string' ),
						'observed_at' => array( 'type' => 'string' ),
					),
					'required'             => array( 'kind', 'object_id', 'key', 'observed_at' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'field_id', 'known', 'value', 'editable', 'source' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build one site-scoped proposal service lazily for both registration hooks.
	 *
	 * @param DirectoristInspector $inspector Native inspection adapter.
	 */
	private function proposal_service( DirectoristInspector $inspector ): ListingProposalService {
		if ( null === $this->proposals ) {
			if ( null === $this->db ) {
				global $wpdb;
				$this->db = new Database( $wpdb );
			}
			$db              = $this->db;
			$this->proposals = new ListingProposalService( $db, $inspector, new ApprovalService( $db ) );
		}
		return $this->proposals;
	}

	/**
	 * Build one site-scoped moderation service lazily.
	 *
	 * @param DirectoristInspector $inspector Native inspector.
	 */
	private function moderation_service( DirectoristInspector $inspector ): ListingModerationService {
		if ( null === $this->moderation ) {
			if ( null === $this->db ) {
				global $wpdb;
				$this->db = new Database( $wpdb );
			}
			$this->moderation = new ListingModerationService( $this->db, $inspector, new ApprovalService( $this->db ) );
		}
		return $this->moderation;
	}

	/** Build one site-scoped taxonomy service lazily. */
	private function taxonomy_service(): TaxonomyService {
		if ( null === $this->taxonomy ) {
			if ( null === $this->db ) {
				global $wpdb;
				$this->db = new Database( $wpdb );
			}
			$this->taxonomy = new TaxonomyService( $this->db, new ApprovalService( $this->db ) );
		}
		return $this->taxonomy;
	}
}
