<?php
/**
 * Runtime registration and guarded discovery operation for builder catalogs.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact closed schemas and operation metadata.

use DirectoristAIPro\Approvals\EffectHandlerRegistry;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Installed integrations register trusted providers; requests cannot mutate this registry. */
final class BuilderModule {
	private IntegrationRegistry $integrations;
	private ?Database $db;
	private bool $collect_integrations;
	private ?EffectHandler $draft_handler = null;
	private ?BuilderPagePublication $page_publication = null;
	private ?BuilderTemplateAssignment $template_assignment = null;
	private ?BuilderScopedLiveApply $scoped_live_apply = null;
	private ?array $draft_change_operations = null;

	public function __construct( ?IntegrationRegistry $integrations = null, ?Database $db = null ) {
		$this->collect_integrations = null === $integrations;
		$this->integrations = $integrations ?? new IntegrationRegistry();
		$this->db           = $db;
	}

	/** Attach before the shared runtime freezes its operation registry. */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'directorist_ai_pro_register_effect_handlers', array( $this, 'register_effects' ) );
		$guard = new BuilderTemplateDraftSaveGuard();
		add_filter( 'wp_insert_post_data', array( $guard, 'preserve_draft' ), 10, 4 );
		add_filter( 'rest_pre_insert_wp_template', array( $guard, 'reject_publish' ), 10, 2 );
		add_filter( 'rest_pre_insert_wp_template_part', array( $guard, 'reject_publish' ), 10, 2 );
		add_filter( 'rest_pre_insert_page', array( $guard, 'reject_publish' ), 10, 2 );
		add_filter( 'rest_pre_insert_et_body_layout', array( $guard, 'reject_publish' ), 10, 2 );
		add_filter( 'rest_pre_insert_elementor_library', array( $guard, 'reject_publish' ), 10, 2 );
		global $wpdb;
		$reviewed_save = new GutenbergReviewedSaveGuard( new BuilderDraftResultApi( new Database( $wpdb ) ) );
		add_filter( 'rest_pre_insert_page', array( $reviewed_save, 'verify' ), 10, 2 );
		add_filter( 'rest_request_before_callbacks', array( $reviewed_save, 'intercept_autosave' ), 10, 3 );
		add_filter( 'rest_dispatch_request', array( $reviewed_save, 'dispatch' ), PHP_INT_MAX, 4 );
		add_filter( 'directorist_ai_gutenberg_draft_preview_context', array( $this, 'draft_preview_context' ), 10, 2 );
		add_filter(
			'directorist_ai_elementor_assignment_receipt_valid',
			static function ( bool $valid, int $draft_id, string $operation_id, $marker ): bool {
				global $wpdb;
				return ( new BuilderTemplateAssignmentResult( new Database( $wpdb ) ) )->elementor_receipt_valid( $valid, $draft_id, $operation_id, $marker );
			},
			10,
			4
		);
		add_filter( 'directorist_ai_builder_scoped_live_ticket_valid', array( $this, 'live_ticket_valid' ), 10, 5 );
		( new GutenbergEditorSnapshotApi() )->hooks();
		( new GutenbergEditorCapture() )->hooks();
		( new ElementorEditorSession() )->hooks();
		( new ElementorEditorSnapshotApi() )->hooks();
		( new DiviEditorSnapshotApi() )->hooks();
		add_action(
			'rest_api_init',
			function (): void {
				global $wpdb;
				( new BuilderDraftResultApi( new Database( $wpdb ) ) )->register();
				if ( null !== $this->scoped_live_apply ) {
					( new BuilderScopedLiveResultApi( $this->db ?? new Database( $wpdb ), $this->scoped_live_apply ) )->register();
				}
			}
		);
	}

	/** Native adapters call this immediately before Save writes any reviewed content. */
	public function live_ticket_valid( bool $valid, int $source_id, string $operation_id, string $impact_hash, object $inspection ): bool {
		if ( null === $this->scoped_live_apply ) {
			return false;
		}
		global $wpdb;
		return ( new BuilderScopedLiveTicketVerifier( $this->db ?? new Database( $wpdb ), $this->scoped_live_apply ) )->valid( $valid, $source_id, $operation_id, $impact_hash, $inspection );
	}

	/** Optional native editor preview; no storage installation or runtime execution. */
	public function draft_preview_context( $context, int $post_id ): ?array {
		try {
			global $wpdb;
			return ( new BuilderDraftEditorContextReader( $this->db ?? new Database( $wpdb ), $wpdb ) )->read( $post_id );
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** Explicit saved control inspection never inherits an unrelated page-creation recipe. */
	private static function saved_control_contract( object $compiler, object $input, object $graph, array $selection ): ?object {
		if ( ! $selection || ! is_callable( array( $compiler, 'authoring_contract' ) ) ) { return null; }
		$filtered = ! empty( $input->control_paths );
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$requested = array();
		foreach ( $input->authoring_components ?? array() as $component ) {
			$requested[ $component->provider_id . '|' . $component->native_id ] = false;
		}
		$explicit_types = (bool) $requested;
		$seen = array();
		foreach ( $selection as $locator ) {
			$key = $canonical->encode( $locator );
			if ( isset( $seen[ $key ] ) ) { return null; }
			$seen[ $key ] = true;
			$matches = array_values( array_filter( $graph->nodes ?? array(), static fn( object $node ): bool => $canonical->encode( $node->locator ?? null ) === $key ) );
			if ( 1 !== count( $matches ) ) { return null; }
			$node = $matches[0];
			$identity = $node->provider_id . '|' . $node->native_id;
			if ( ! $explicit_types ) { $requested[ $identity ] = false; }
			if ( 'directorist-ai' === $node->provider_id || null !== ( $node->opaque_ref ?? null ) || ! array_key_exists( $identity, $requested ) || $canonical->encode( $node->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || $canonical->encode( $node->locator->document ?? null ) !== $canonical->encode( $graph->document ?? null ) ) { return null; }
			$requested[ $identity ] = true;
		}
		if ( in_array( false, $requested, true ) ) { return null; }
		$contract = clone $compiler->authoring_contract();
		// An exact saved-node read also supplies existing controls when the model
		// omits the optional path filter. Preserve generic insertion capabilities
		// for an ambiguous native container, without an unrelated root factory.
		unset( $contract->first_loop );
		if ( ! $filtered ) { return $contract; }
		$operations = array( 'set_control', 'bind_field' );
		if ( 1 === count( $selection ) && in_array( $node->native_id, $contract->removable_components ?? array(), true ) ) {
			$operations[] = 'remove'; }
		$contract->operations = array_values( array_intersect( $contract->operations ?? array(), $operations ) );
		// Discovery still supplies the exact current public control schemas and
		// native preimages. This removes insertion hints only; native planning,
		// field-binding, scope, source and semantic checks remain authoritative.
		foreach ( array( 'first_loop', 'insert_parents', 'insert_child_shapes', 'insertion_attributes', 'content_placement', 'max_insert_nodes', 'max_insert_depth', 'initial_controls_rule', 'section_recipe', 'listing_grid_recipe', 'new_loop_responsive_rule', 'single_listing_recipe', 'home_search_card_recipe', 'search_recipe' ) as $key ) { unset( $contract->{$key} ); }
		return $contract;
	}

	/** Oversized discovery cannot decide insertion eligibility or grant a proposal. */
	private static function needs_smaller_authoring_read( object $packet ): bool {
		return 'needs_narrowing' === ( $packet->state ?? null ) && false === ( $packet->can_propose_private_draft ?? null ) && (bool) array_filter(
			$packet->diagnostics ?? array(),
			static fn( object $diagnostic ): bool => 'authoring_contract_byte_bound_exceeded' === ( $diagnostic->code ?? null )
		);
	}

	/** Existing leaf removal needs identity and scope, not an unrelated style inventory. */
	private static function saved_leaf_removal_subset( object $contract, object $input, object $graph, ?object $node, array $selection, object $authoring, bool $source_ready ): ?object {
		if ( ! $source_ready || null === $node || ! self::needs_smaller_authoring_read( $authoring ) || ! empty( $input->control_paths ) || ! in_array( 'remove', $contract->operations ?? array(), true ) || ! in_array( $node->native_id, $contract->removable_components ?? array(), true ) || null !== ( $node->opaque_ref ?? null ) || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) || array() !== ( $graph->omissions ?? null ) || array_filter( $node->locator->slot_path ?? array(), static fn( string $part ): bool => str_starts_with( $part, 'slot-' ) ) ) {
			return null; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( null !== ( $graph->version->editor_epoch ?? null ) || null !== ( $node->scope->breakpoint_id ?? null ) || 'normal' !== ( $node->scope->interaction ?? null ) || $canonical->encode( $node->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || $canonical->encode( $node->locator->document ?? null ) !== $canonical->encode( $graph->document ?? null ) || 1 !== count( $selection ) || $canonical->encode( $selection[0] ) !== $canonical->encode( $node->locator ) ) {
			return null; }
		$draft = (object) array_intersect_key( get_object_vars( $contract ), array_fill_keys( array( 'revision', 'document_kinds', 'removal_rule', 'removable_components' ), true ) );
		$draft->operations = array( 'remove' );
		$draft->max_changes = 1;
		return (object) array(
			'state'                      => 'complete_for_selection',
			'readiness_scope'            => 'exact_saved_leaf_removal',
			'can_propose_private_draft'  => true,
			'catalog_revision'           => $authoring->catalog_revision,
			'semantic_rule_set_revision' => $authoring->semantic_rule_set_revision,
			'graph_hash'                 => $canonical->digest( $graph ),
			'graph_version_hash'         => ( new GraphVersion( (array) $graph->version ) )->hash(),
			'selected_area'              => (object) array( 'provider_id' => $node->provider_id, 'native_id' => $node->native_id, 'locator' => $node->locator, 'scope' => $node->scope ),
			'draft'                      => $draft,
			'note'                       => 'Complete contract for removing only this exact existing presentation leaf. No style/control values, insertions or other targets are authorized. Use the complete current native graph/version with one remove change. The ordinary source, scope, policy, semantic, approval and native-save validation remain mandatory.',
		);
	}

	/** Read-only related colour slots from the same public, schema-validated native owner. */
	private static function related_color_context( object $response, object $input, BuilderPlanningPolicyRegistry $policies ): ?object {
		$a = $response->authoring ?? null;
		$graph = $response->graph ?? null;
		if ( 'complete_for_selection' !== ( $a->state ?? null ) || true !== ( $a->can_propose_private_draft ?? null ) || ! is_array( $input->control_paths ?? null ) || ! $input->control_paths || ! is_array( $input->selection ?? null ) || 1 !== count( $input->selection ) || ! ( $a->selected_area->locator ?? null ) instanceof \stdClass || ! $graph instanceof \stdClass || 'complete' !== ( $graph->completeness ?? null ) || 'complete' !== ( $graph->consumer_coverage ?? null ) ) { return null; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		if ( $canonical->encode( $input->selection[0] ) !== $canonical->encode( $a->selected_area->locator ) || $canonical->encode( $input->document ?? null ) !== $canonical->encode( $graph->document ?? null ) ) { return null; }
		if ( ! is_string( $response->document_key ?? null ) || '' === $response->document_key ) { return null; }
		foreach ( array( $response->graph_hash ?? null, $response->fact_revision ?? null, $a->catalog_revision ?? null, $a->semantic_rule_set_revision ?? null ) as $revision ) { if ( ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) { return null; } }
		$nodes = array_values( array_filter( $graph->nodes ?? array(), static fn( object $n ): bool => $canonical->encode( $n->locator ?? null ) === $canonical->encode( $input->selection[0] ) ) );
		if ( 1 !== count( $nodes ) ) { return null; }
		$n = $nodes[0];
		if ( null !== ( $n->opaque_ref ?? null ) || in_array( $n->provider_id ?? null, array( 'native/opaque', 'directorist-ai' ), true ) || ! ( $n->attributes ?? null ) instanceof \stdClass || $canonical->encode( $n->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || $canonical->encode( $a->selected_area->scope ?? null ) !== $canonical->encode( $input->scope ?? null ) || $canonical->encode( $n->locator->document ?? null ) !== $canonical->encode( $graph->document ) || ( $n->provider_id ?? null ) !== ( $a->selected_area->provider_id ?? null ) || ( $n->native_id ?? null ) !== ( $a->selected_area->native_id ?? null ) ) { return null; }
		$indexes = array_values( array_filter( $a->control_index ?? array(), static fn( object $index ): bool => ( $index->provider_id ?? null ) === $n->provider_id && ( $index->native_id ?? null ) === $n->native_id ) );
		if ( 1 !== count( $indexes ) || ! is_array( $indexes[0]->paths ?? null ) ) { return null; }
		$policy = $policies->component( $n->provider_id, $n->native_id );
		if ( null === $policy ) { return null; }
		$private = array_column( array_filter( $policy->controls, static fn( object $c ): bool => true === $c->private ), 'path' );
		$requested_color = false;
		foreach ( $a->components ?? array() as $component ) {
			if ( ( $component->provider_id ?? null ) !== $n->provider_id || ( $component->native_id ?? null ) !== $n->native_id ) { continue; }
			foreach ( $component->controls ?? array() as $control ) {
				$requested_color = $requested_color || ( false === ( $control->private ?? null ) && in_array( $control->path ?? null, $a->requested_control_paths ?? array(), true ) && in_array( $control->path ?? null, $indexes[0]->paths, true ) && ( preg_match( '/colou?r/i', $control->path ) || preg_match( '/colou?r/i', $control->value_schema->title ?? '' ) ) );
			}
		}
		if ( ! $requested_color ) { return null; }
		$out = (object) array( 'revision' => 'native-related-colours-v1', 'read_only' => true, 'provider_id' => $n->provider_id, 'native_id' => $n->native_id, 'locator' => $n->locator, 'scope' => $n->scope, 'groups' => array(), 'omitted_groups' => 0, 'note' => 'Literal source colours only. Unset, inherited, global, dynamic and omitted slots are UNKNOWN; declared defaults are not computed CSS. Inner registered surfaces may cover outer backgrounds: inspect the actual inner surface/control before choosing a legible colour pair. These related values are readonly evidence, not schemas or permission.' );
		$leaves = 0;
		foreach ( $policy->controls as $control ) {
			if ( false !== $control->private || ! in_array( $control->path, $indexes[0]->paths, true ) ) { continue; }
			$blocked = false;
			foreach ( $private as $path ) { $blocked = $blocked || str_starts_with( $path, $control->path . '.' ) || str_starts_with( $control->path, $path . '.' ) || $path === $control->path; }
			if ( $blocked ) { continue; }
			$color = (bool) preg_match( '/colou?r/i', $control->path . ' ' . ( $control->value_schema->title ?? '' ) );
			if ( ! $color ) { continue; }
			$value = $n->attributes;
			foreach ( explode( '.', $control->path ) as $part ) { if ( ! $value instanceof \stdClass || ! property_exists( $value, $part ) ) { $value = null; break; } $value = $value->{$part}; }
			if ( null === $value || ( $value instanceof \stdClass && ( 'object' !== ( $control->value_schema->type ?? null ) || false !== ( $control->value_schema->additionalProperties ?? null ) ) ) ) { ++$out->omitted_groups; continue; }
			try { $policies->validate_control( $policy, $control->path, $value ); } catch ( \Throwable $error ) { ++$out->omitted_groups; continue; }
			$slots = array(); $visited = 0; $truncated = false;
			self::color_slots( $value, array(), $color, $slots, $visited, $truncated );
			if ( ! $slots ) { ++$out->omitted_groups; continue; }
			$group = (object) array( 'control_path' => $control->path, 'label' => mb_strcut( (string) ( $control->value_schema->title ?? $control->path ), 0, 160, 'UTF-8' ), 'slots' => $slots, 'omitted_slots_unknown' => $truncated );
			$out->groups[] = $group;
			if ( count( $out->groups ) > 16 || $leaves + count( $slots ) > 32 || strlen( wp_json_encode( $out ) ) > 4096 ) { array_pop( $out->groups ); ++$out->omitted_groups; continue; }
			$leaves += count( $slots );
		}
		while ( $out->groups && strlen( wp_json_encode( $out ) ) > 4096 ) { array_pop( $out->groups ); ++$out->omitted_groups; }
		if ( strlen( wp_json_encode( $out ) ) > 4096 ) { return null; }
		return $out;
	}

	/** Preserve exact native slot paths/presence without copying arbitrary objects. */
	private static function color_slots( mixed $value, array $path, bool $color, array &$slots, int &$visited, bool &$truncated ): void {
		if ( ++$visited > 256 || count( $path ) > 10 || count( $slots ) >= 32 ) { $truncated = true; return; }
		if ( is_string( $value ) && $color && self::literal_color( $value ) ) { $slots[] = (object) array( 'member_path' => $path, 'value' => $value, 'source_kind' => in_array( 'default_value', $path, true ) ? 'declared_default_not_computed' : 'native_source_scalar' ); return; }
		if ( ! $value instanceof \stdClass ) { return; }
		if ( is_bool( $value->present ?? null ) && is_string( $value->value ?? null ) && $color && self::literal_color( $value->value ) ) { $slots[] = (object) array( 'member_path' => $path, 'present' => $value->present, 'value' => $value->value, 'source_kind' => 'native_source_slot' ); return; }
		foreach ( get_object_vars( $value ) as $key => $child ) {
			$member_color = (bool) preg_match( '/colou?r/i', $key );
			$slot = in_array( $key, array( 'base', 'normal', 'hover', 'desktop', 'tablet', 'mobile', 'default_value', 'value' ), true );
			self::color_slots( $child, array_merge( $path, array( $key ) ), $member_color || ( $color && $slot ), $slots, $visited, $truncated );
		}
	}

	private static function literal_color( string $value ): bool {
		return strlen( $value ) <= 96 && ( 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $value ) || 1 === preg_match( '/^(?:rgb|rgba|hsl|hsla)\([0-9.,% +\/-]+\)$/iD', $value ) || in_array( strtolower( $value ), array( 'transparent', 'black', 'white', 'red', 'blue', 'green', 'yellow', 'orange', 'purple', 'gray', 'grey', 'navy', 'teal', 'silver', 'maroon', 'lime', 'aqua', 'fuchsia', 'olive' ), true ) );
	}

	/** Match only an installed creation contract for the exact inspected root and components. */
	private static function page_content_contract( object $compiler, object $input, object $graph, ?object $selected_node ): ?object {
		if ( null !== $selected_node || 1 !== count( $input->selection ) || ! empty( $input->control_paths ) ) {
			return null;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$roots = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => 'directorist-ai' === $node->provider_id && in_array( $node->native_id, array( 'gutenberg-document', 'elementor-document', 'divi-document' ), true ) ) );
		$identities = static function ( array $items ) use ( $canonical ): array {
			$keys = array_map( static fn( object $item ): string => $canonical->encode( $item ), $items );
			sort( $keys, SORT_STRING );
			return $keys;
		};
		if ( 1 !== count( $roots ) || $canonical->encode( $input->selection[0] ) !== $canonical->encode( $roots[0]->locator ) ) {
			return null;
		}
		foreach ( array( 'page_content', 'page_media' ) as $kind ) {
			$components_method = $kind . '_authoring_components';
			$contract_method   = $kind . '_authoring_contract_for_graph';
			if ( ! is_callable( array( $compiler, $components_method ) ) || ! is_callable( array( $compiler, $contract_method ) ) ) {
				continue;
			}
			$components = $compiler->$components_method( $input->document, $input->scope );
			if ( ! $components || $identities( $components ) !== $identities( $input->authoring_components ?? array() ) ) {
				continue;
			}
			$contract = $compiler->$contract_method( $graph );
			BuilderAuthoringContract::assert_metadata( $contract );
			return $contract;
		}
		return null;
	}

	/** Match only the installed, bounded single-listing composition at its exact root. */
	private static function single_listing_contract( object $compiler, object $input, object $graph, ?object $selected_node ): ?object {
		if ( null !== $selected_node || ! in_array( $input->document->builder ?? null, array( 'divi', 'elementor' ), true ) || 'template:single_listing' !== ( $input->document->native_kind ?? null ) || 'single' !== ( $input->scope->view ?? null ) || null !== ( $input->scope->breakpoint_id ?? null ) || 'normal' !== ( $input->scope->interaction ?? null ) || 1 !== count( $input->selection ) || ! empty( $input->control_paths ) || ! is_callable( array( $compiler, 'single_listing_authoring_components' ) ) || ! is_callable( array( $compiler, 'single_listing_authoring_contract_for_graph' ) ) ) {
			return null; }
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$identities = static function ( array $items ) use ( $canonical ): array {
			$keys = array_map( static fn( object $item ): string => $canonical->encode( $item ), $items );
			sort( $keys, SORT_STRING );
			return $keys;
		};
		$elementor = 'elementor' === $input->document->builder;
		$native_ids = $elementor ? array( 'container', 'directorist_listing_card_title', 'directorist_single_listing_description', 'directorist_listing_card_pricing', 'directorist_listing_card_address' ) : array( 'divi/section', 'divi/row', 'divi/column', 'directorist/listings-title', 'directorist/listings-description', 'directorist/listings-pricing', 'directorist/listings-address' );
		$expected = array();
		foreach ( $native_ids as $native ) {
			$provider = $elementor ? ( 'container' === $native ? 'elementor/core' : 'directorist-elementor' ) : ( str_starts_with( $native, 'divi/' ) ? 'divi/core' : 'directorist-divi-integration' );
			$expected[] = (object) array( 'provider_id' => $provider, 'native_id' => $native );
		}
		$node_count = count( $expected );
		$depth = $elementor ? 2 : 4;
		$creation_mode = $elementor ? 'elementor_native_single_listing_v1' : 'divi_native_single_listing_v1';
		$root_native_id = $elementor ? 'elementor-document' : 'divi-document';
		$components = $compiler->single_listing_authoring_components( $input->document, $input->scope );
		$roots = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => 'directorist-ai' === $node->provider_id && $node->native_id === $root_native_id ) );
		if ( 1 !== count( $roots ) || $canonical->encode( $input->selection[0] ) !== $canonical->encode( $roots[0]->locator ) || $identities( $components ) !== $identities( $expected ) || $identities( $components ) !== $identities( $input->authoring_components ?? array() ) ) {
			return null; }
		$contract = $compiler->single_listing_authoring_contract_for_graph( $graph );
		BuilderAuthoringContract::assert_metadata( $contract );
		$placement = $contract->native_loop_placement ?? null;
		if ( ( $contract->creation_mode ?? null ) !== $creation_mode || array( 'template:single_listing' ) !== ( $contract->document_kinds ?? null ) || array( 'insert' ) !== ( $contract->operations ?? null ) || 1 !== ( $contract->max_changes ?? null ) || ( $contract->max_insert_nodes ?? null ) !== $node_count || ( $contract->max_insert_depth ?? null ) !== $depth || ! $placement instanceof \stdClass || $canonical->encode( $placement->parent ?? null ) !== $canonical->encode( $roots[0]->locator ) || $canonical->encode( $placement->scope ?? null ) !== $canonical->encode( $input->scope ) || ! is_array( $contract->insertion_attributes ?? null ) || count( $contract->insertion_attributes ) !== $node_count ) {
			return null; }
		$recipe = $elementor ? array( array( 'container', null, null ), array( 'directorist_listing_card_title', 0, 0 ), array( 'directorist_single_listing_description', 0, 1 ), array( 'directorist_listing_card_pricing', 0, 2 ), array( 'directorist_listing_card_address', 0, 3 ) ) : array( array( 'divi/section', null, null ), array( 'divi/row', 0, 0 ), array( 'divi/column', 1, 0 ), array( 'directorist/listings-title', 2, 0 ), array( 'directorist/listings-description', 2, 1 ), array( 'directorist/listings-pricing', 2, 2 ), array( 'directorist/listings-address', 2, 3 ) );
		$children = array_filter( $graph->edges, static fn( object $edge ): bool => 'contains' === $edge->kind && $roots[0]->id === $edge->source && null === $edge->target_document_key );
		if ( ( $contract->single_listing_recipe ?? null ) !== $recipe || count( $children ) !== ( $placement->saved_direct_children ?? null ) || count( $children ) !== ( $placement->append_position ?? null ) || $canonical->encode( $placement->required_insert_root ?? null ) !== $canonical->encode( $expected[0] ) ) {
			return null; }
		$declared = array();
		foreach ( $contract->insertion_attributes as $row ) {
			$schema = $row->insert_attributes_schema ?? null;
			if ( ! $row instanceof \stdClass || ! is_string( $row->provider_id ?? null ) || ! is_string( $row->native_id ?? null ) || ! $schema instanceof \stdClass || 'object' !== ( $schema->type ?? null ) || false !== ( get_object_vars( $schema )['additionalProperties'] ?? null ) || ! ( $schema->properties ?? null ) instanceof \stdClass || array_diff( array_keys( get_object_vars( $schema->properties ) ), array( 'initial_controls' ) ) ) {
				return null; }
			$declared[] = (object) array( 'provider_id' => $row->provider_id, 'native_id' => $row->native_id );
		}
		return $identities( $declared ) === $identities( $expected ) ? $contract : null;
	}

	/** Match a certified composed Elementor layout only at its unchanged document root. */
	private static function page_layout_contract( object $compiler, object $input, object $graph, ?object $selected_node ): ?object {
		if ( null !== $selected_node || 'elementor' !== ( $input->document->builder ?? null ) || 'page' !== ( $input->document->native_kind ?? null ) || 1 !== count( $input->selection ) || ! empty( $input->control_paths ) || ! is_callable( array( $compiler, 'page_layout_authoring_components' ) ) || ! is_callable( array( $compiler, 'page_layout_authoring_contract_for_graph' ) ) ) {
			return null;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$roots = array_values( array_filter( $graph->nodes, static fn( object $node ): bool => 'directorist-ai' === $node->provider_id && 'elementor-document' === $node->native_id ) );
		$components = $compiler->page_layout_authoring_components( $input->document, $input->scope );
		$identities = static function ( array $items ) use ( $canonical ): array {
			$keys = array_map( static fn( object $item ): string => $canonical->encode( $item ), $items );
			sort( $keys, SORT_STRING );
			return $keys;
		};
		if ( 4 !== count( $components ) || 1 !== count( $roots ) || $canonical->encode( $input->selection[0] ) !== $canonical->encode( $roots[0]->locator ) || $identities( $components ) !== $identities( $input->authoring_components ?? array() ) ) {
			return null;
		}
		$contract = $compiler->page_layout_authoring_contract_for_graph( $graph );
		BuilderAuthoringContract::assert_metadata( $contract );
		if ( 'elementor_native_root_layout_v1' !== ( $contract->creation_mode ?? null ) || array( 'insert' ) !== ( $contract->operations ?? null ) || 1 !== ( $contract->max_changes ?? null ) || ! BuilderAuthoringContract::has_actionable_contract( (object) array( 'draft' => $contract ) ) || $canonical->encode( $contract->first_loop->parent ) !== $canonical->encode( $roots[0]->locator ) || $canonical->encode( $contract->first_loop->scope ) !== $canonical->encode( $input->scope ) ) {
			return null;
		}
		return $contract;
	}

	/**
	 * Keep an installed closed first-loop recipe separate from saved-node edits.
	 *
	 * @param object      $compiler Installed native compiler.
	 * @param object      $input Validated inspection input.
	 * @param object      $graph Current native graph.
	 * @param object|null $selected_node Optional saved selected area.
	 * @return object|null Exact insertion-only contract or ordinary authoring.
	 */
	private static function closed_loop_contract( object $compiler, object $input, object $graph, ?object $selected_node ): ?object {
		if ( null !== $selected_node || ! empty( $input->selection ) || ! empty( $input->control_paths ) || ! in_array( $input->document->builder ?? null, array( 'gutenberg', 'divi' ), true ) || 1 !== count( $input->authoring_components ?? array() ) || ! is_callable( array( $compiler, 'authoring_contract_for_graph' ) ) ) {
			return null;
		}
		$contract = $compiler->authoring_contract_for_graph( $graph );
		$recipe   = $contract->first_loop ?? null;
		$subtree  = $recipe->subtree ?? null;
		$nodes    = $subtree->nodes ?? null;
		$root     = is_array( $nodes ) && 1 === count( $nodes ) ? $nodes[0] : null;
		$wanted   = $input->authoring_components[0];
		if ( ! $contract instanceof \stdClass || ! in_array( $input->document->native_kind ?? null, $contract->document_kinds ?? array(), true ) || 1 !== ( $recipe->max_changes ?? null ) || 1 !== ( $recipe->max_insert_nodes ?? null ) || ! is_int( $recipe->position ?? null ) || $recipe->position < 0 || ! $recipe->parent instanceof \stdClass || ! $root instanceof \stdClass || ( $subtree->root_client_id ?? null ) !== ( $root->client_id ?? null ) || ( $root->provider_id ?? null ) !== ( $wanted->provider_id ?? null ) || ( $root->native_id ?? null ) !== ( $wanted->native_id ?? null ) || ! $root->attributes instanceof \stdClass || array( 'native_loop_recipe' ) !== array_keys( get_object_vars( $root->attributes ) ) || ! is_string( $root->attributes->native_loop_recipe ) || ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $root->attributes->native_loop_recipe ) || array() !== ( $subtree->edges ?? null ) ) {
			return null;
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$parents   = array_filter( $graph->nodes ?? array(), static fn( object $node ): bool => $canonical->encode( $node->locator ?? null ) === $canonical->encode( $recipe->parent ) );
		if ( 1 !== count( $parents ) ) {
			return null;
		}
		$closed = (object) array( 'revision' => $contract->revision, 'document_kinds' => $contract->document_kinds, 'operations' => array( 'insert' ), 'max_changes' => 1, 'first_loop' => $recipe );
		if ( is_string( $contract->new_layout_presentation ?? null ) ) {
			$closed->new_layout_presentation = substr( $contract->new_layout_presentation, 0, 2048 );
		}
		BuilderAuthoringContract::assert_metadata( $closed );
		return $closed;
	}


	/**
	 * Attach bounded read-only defaults only for requested public new-node controls.
	 *
	 * @throws \DirectoristAIPro\Validation\ContractException Unsupported native default packet.
	 */
	private static function initial_control_defaults( object $authoring, object $compiler, object $document, object $scope, array $requested_paths ): void {
		if ( ! $requested_paths || ! is_callable( array( $compiler, 'initial_control_values_for_component' ) ) ) {
			return; }
		foreach ( $authoring->components as$component ) {
			if ( false !== ( $component->saved_instance_available ?? null ) || 'new_component_insertion' !== ( $component->authoring_role ?? null ) ) {
				continue;
			}
			$controls = array_values( array_filter( $component->controls ?? array(), static fn( object $c ): bool=>false === ( $c->private ?? null ) && ( $c->value_schema ?? null ) instanceof \stdClass ) );
			$paths = array_column( $controls, 'path' );
			if ( ! $paths ) {
				continue;
			}
			$values = $compiler->initial_control_values_for_component( clone$document, clone$scope, (object) array( 'provider_id' => $component->provider_id, 'native_id' => $component->native_id ), $paths );
			( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 16 ) )->check( $values );
			if ( ! $values instanceof \stdClass || array_diff( array_keys( get_object_vars( $values ) ), $paths ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native initial defaults differ from the requested public controls.' );
			}
			$component->initial_control_values = json_decode( ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $values ) );
		}
	}

	/**
	 * Register strict operations and collect trusted integration providers once.
	 *
	 * @throws \DirectoristAIPro\Validation\ContractException Unsupported native compiler declaration.
	 */
	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		/**
		 * Register one authoritative provider snapshot per builder.
		 *
		 * Providers are trusted PHP objects supplied by installed integrations during
		 * bootstrap. This action is not a REST, ability, model or request surface.
		 *
		 * @param IntegrationRegistry $integrations Frozen shared integration registry.
		 */
		if ( $this->collect_integrations ) {
			do_action( 'directorist_ai_pro_register_builder_providers', $this->integrations );
		}
		$this->integrations->freeze();
		$graph_providers = new BuilderGraphRegistry();
		/** Installed integration code may register one inspect-only graph reader per builder. */
		do_action( 'directorist_ai_pro_register_builder_graph_providers', $graph_providers );
		$graph_providers->freeze();
		$draft_adapters = new BuilderDraftAdapterRegistry();
		/** Installed integrations supply native compiler, facts and writer together. */
		do_action( 'directorist_ai_pro_register_builder_draft_adapters', $draft_adapters );
		$draft_adapters->freeze();
		// Capture the trusted bootstrap listeners, without constructing every
		// editor's control families on visitor chat and unrelated frontend requests.
		$semantic_hook = isset( $GLOBALS['wp_filter']['directorist_ai_pro_register_builder_semantic_rules'] ) ? clone $GLOBALS['wp_filter']['directorist_ai_pro_register_builder_semantic_rules'] : null;
		$semantic_rules = new SemanticRuleRegistry(
			static function ( SemanticRuleRegistry $target ) use ( $semantic_hook ): void {
				if ( null === $semantic_hook ) {
					return;
				}
				global $wp_current_filter, $wp_actions;
				$hook = 'directorist_ai_pro_register_builder_semantic_rules';
				$wp_current_filter[] = $hook;
				$wp_actions[ $hook ] = ( $wp_actions[ $hook ] ?? 0 ) + 1;
				try {
					// The captured WP_Hook retains priority and accepted argument
					// behavior. Listeners added after bootstrap cannot enter it.
					$semantic_hook->do_action( array( $target ) );
				} finally {
					array_pop( $wp_current_filter );
				}
			}
		);
		$semantic_rules->freeze();
		$relationship_validator = new RelationshipValidator( $semantic_rules );
		/** Internal planning services may retain this frozen validator and use validate_current(). */
		do_action( 'directorist_ai_pro_builder_relationship_validator_ready', $relationship_validator );
		$planning_policies = new BuilderPlanningPolicyRegistry();
		/** Installed adapters register strict control/insert/variant schemas during trusted bootstrap. */
		do_action( 'directorist_ai_pro_register_builder_planning_policies', $planning_policies );
		$planning_policies->freeze();
		do_action( 'directorist_ai_pro_builder_planning_policies_ready', $planning_policies );
		$recipes = new BuilderRecipeRegistry();
		/** Installed integrations register bounded declarative recipes during trusted bootstrap. */
		do_action( 'directorist_ai_pro_register_builder_recipes', $recipes );
		$recipes->freeze();
		do_action( 'directorist_ai_pro_builder_recipe_registry_ready', $recipes );
		$certifications = new FeatureCertificationRegistry();
		/** Register only independently tested native handler tuples; Phase 5 owns them. */
		do_action( 'directorist_ai_pro_register_builder_certifications', $certifications );
		$certifications->freeze();
		$catalog_contract = new EffectiveCatalogContract();
		$schemas->register( 'builder.describe.input', wp_json_encode( $this->input_schema() ) );
		$schemas->register( 'builder.describe.output', wp_json_encode( array( 'type' => 'object', 'oneOf' => array( $catalog_contract->schema(), $this->discovery_output_schema() ) ) ) );
		$inspector   = new DirectoristInspector();
		$directories = new DirectoristDirectoryFactsReader( $inspector );
		$compiler    = new EffectiveCatalogCompiler( $this->integrations, $directories, $certifications, $catalog_contract );
		$environment = new CatalogEnvironmentReader( $this->integrations );
		$bindings    = new EditorBindingResolver();
		global $wpdb;
		$runtime_db = $this->db ?? new Database( $wpdb );
		$graph_storage_available = $runtime_db->readiness()->status()['effects_enabled'];
		$cache = null;
		if ( $graph_storage_available ) {
			$cache_store = new CatalogCacheStore( $runtime_db, $catalog_contract );
			$cache       = new CatalogCacheService( $cache_store, $compiler, new CatalogCacheContextReader( $this->integrations, $directories, $certifications, $cache_store, $catalog_contract ), static fn(): CatalogEnvironment => $environment->read() );
			$cache->hooks();
			$prewarm = new CatalogCachePrewarm(
				$cache_store,
				$cache,
				static fn(): CatalogEnvironment => $environment->read(),
				fn( CatalogRequest $request ): bool => $this->permitted( $request->context(), $inspector, $request->directory_id() )
			);
			$cache->enable_prewarm( $prewarm );
			$prewarm->hooks();
			$graphs = new GraphSnapshotStore( $runtime_db );
			do_action( 'directorist_ai_pro_builder_change_planner_factory_ready', new BuilderChangePlannerFactory( $runtime_db, $graphs, $relationship_validator, $planning_policies ) );
			do_action( 'directorist_ai_pro_builder_task_context_factory_ready', new BuilderTaskContextFactory( $runtime_db, $graphs, $relationship_validator, $planning_policies ) );
			do_action( 'directorist_ai_pro_builder_recipe_compiler_factory_ready', new BuilderRecipeCompilerFactory( $runtime_db, $recipes, $graphs, $planning_policies ) );
		}
		$graph_db = $runtime_db;
		$graph_projection = new BuilderGraphProjectionService( $graph_db, $relationship_validator );
		$publication_adapters = BuilderPagePublicationRegistry::installed();
		if ( $graph_storage_available && $publication_adapters->available() ) {
			$this->page_publication = new BuilderPagePublication( $graph_db, $environment, $publication_adapters );
			$publication = $this->page_publication;
			$schemas->register( 'builder.publish_propose.input', wp_json_encode( $this->publish_propose_input_schema() ) );
			$schemas->register( 'builder.publish_propose.output', wp_json_encode( $this->publish_propose_output_schema() ) );
			$schemas->register( 'builder.publish.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.publish.output', wp_json_encode( $this->stage_output_schema() ) );
			$schemas->register( 'builder.restore_propose.input', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ), 'publication_operation_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ) ), 'required' => array( 'request_id', 'publication_operation_id' ), 'additionalProperties' => false ) ) );
			$schemas->register( 'builder.restore_propose.output', wp_json_encode( $this->publish_propose_output_schema() ) );
			$schemas->register( 'builder.restore.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.restore.output', wp_json_encode( $this->stage_output_schema() ) );
			$can_publish = static fn( Context $context ): bool => ! $context->actor()->is_guest() && $context->actor()->can( 'publish_pages' );
			$registry->register( new Operation( array( 'id' => 'builder.publish_propose', 'revision' => '2', 'description' => 'Prepare a separate exact review to publish one unchanged owned native builder page draft. No source replacement or template assignment.', 'input_schema' => 'builder.publish_propose.input', 'output_schema' => 'builder.publish_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ), fn( object $input, Context $context ): bool => $can_publish( $context ), $can_publish, static fn(): bool => true, static fn( object $input, Context $context ): object => $publication->propose( $context, $input ) ) );
			$registry->register( new Operation( array( 'id' => 'builder.publish', 'revision' => '3', 'description' => 'Publish only the owned native builder page at the top level with the exact title/slug authorized by its separate publication approval.', 'input_schema' => 'builder.publish.input', 'output_schema' => 'builder.publish.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), fn( object $input, Context $context ): bool => $can_publish( $context ), $can_publish, static fn(): bool => true, static fn(): object => (object) array() ) );
			$registry->register( new Operation( array( 'id' => 'builder.restore_propose', 'revision' => '1', 'description' => 'Prepare a separate exact review to return an unchanged owned published builder page to its prior draft title, slug, parent and status. Refuses newer edits; does not reverse external hooks or template assignments.', 'input_schema' => 'builder.restore_propose.input', 'output_schema' => 'builder.restore_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ), fn( object $input, Context $context ): bool => $can_publish( $context ), $can_publish, static fn(): bool => true, static fn( object $input, Context $context ): object => $publication->propose_restore( $context, $input ) ) );
			$registry->register( new Operation( array( 'id' => 'builder.restore', 'revision' => '1', 'description' => 'Restore only the reviewed native page publication fields after exact human approval; preserve content and refuse newer edits.', 'input_schema' => 'builder.restore.input', 'output_schema' => 'builder.restore.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), fn( object $input, Context $context ): bool => $can_publish( $context ), $can_publish, static fn(): bool => true, static fn(): object => (object) array() ) );
		}
		$assignment_adapters = BuilderTemplateAssignmentRegistry::installed();
		if ( $graph_storage_available && $assignment_adapters->available() ) {
			$assignment = new BuilderTemplateAssignment( $graph_db, $environment, $assignment_adapters );
			$this->template_assignment = $assignment;
			$can_assign = static fn( Context $context ): bool => ! $context->actor()->is_guest() && $context->actor()->can( 'edit_theme_options' );
			$schemas->register( 'builder.template_assign_propose.input', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ), 'draft_operation_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ), 'directory_id' => array( 'type' => 'integer', 'minimum' => 0 ) ), 'required' => array( 'request_id', 'draft_operation_id', 'directory_id' ), 'additionalProperties' => false ) ) );
			$schemas->register( 'builder.template_assign_propose.output', wp_json_encode( $this->template_propose_output_schema() ) );
			$schemas->register( 'builder.template_assign.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.template_assign.output', wp_json_encode( $this->stage_output_schema() ) );
			$schemas->register( 'builder.template_restore_propose.input', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ), 'assignment_operation_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ) ), 'required' => array( 'request_id', 'assignment_operation_id' ), 'additionalProperties' => false ) ) );
			$schemas->register( 'builder.template_restore_propose.output', wp_json_encode( $this->template_propose_output_schema() ) );
			$schemas->register( 'builder.template_restore.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.template_restore.output', wp_json_encode( $this->stage_output_schema() ) );
			$registry->register( new Operation( array( 'id' => 'builder.template_assign_propose', 'revision' => '1', 'description' => 'Prepare a separate review for assigning one unchanged owned native template draft to the selected directory. Server derives target and affected consumers.', 'input_schema' => 'builder.template_assign_propose.input', 'output_schema' => 'builder.template_assign_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ), fn( object $input, Context $context ): bool => $can_assign( $context ), $can_assign, static fn(): bool => true, static fn( object $input, Context $context ): object => $assignment->propose( $context, $input ) ) );
			$registry->register( new Operation( array( 'id' => 'builder.template_assign', 'revision' => '1', 'description' => 'Apply one exact human-approved native template assignment without retrying uncertain writes.', 'input_schema' => 'builder.template_assign.input', 'output_schema' => 'builder.template_assign.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), fn( object $input, Context $context ): bool => $can_assign( $context ), $can_assign, static fn(): bool => true, static fn(): object => (object) array() ) );
			$registry->register( new Operation( array( 'id' => 'builder.template_restore_propose', 'revision' => '1', 'description' => 'Prepare a separate review to restore exactly the current owned native template assignment from its private checkpoint.', 'input_schema' => 'builder.template_restore_propose.input', 'output_schema' => 'builder.template_restore_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ), fn( object $input, Context $context ): bool => $can_assign( $context ), $can_assign, static fn(): bool => true, static fn( object $input, Context $context ): object => $assignment->propose_restore( $context, $input ) ) );
			$registry->register( new Operation( array( 'id' => 'builder.template_restore', 'revision' => '1', 'description' => 'Restore only the exact separately approved native template assignment while rejecting newer edits and consumer changes.', 'input_schema' => 'builder.template_restore.input', 'output_schema' => 'builder.template_restore.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), fn( object $input, Context $context ): bool => $can_assign( $context ), $can_assign, static fn(): bool => true, static fn(): object => (object) array() ) );
		}
		$live_adapters = BuilderScopedLiveApplyRegistry::installed();
		if ( $graph_storage_available && $live_adapters->available() ) {
			$live = new BuilderScopedLiveApply( $graph_db, $environment, $live_adapters );
			$this->scoped_live_apply = $live;
			$can_live = static fn( Context $context ): bool => ! $context->actor()->is_guest() && $context->actor()->can( 'edit_pages' );
			$schemas->register( 'builder.live_propose.input', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ), 'draft_operation_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ), 'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ), 'editor_binding_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$' ) ), 'required' => array( 'request_id', 'draft_operation_id', 'directory_id', 'editor_binding_id' ), 'additionalProperties' => false ) ) );
			$schemas->register( 'builder.live_propose.output', wp_json_encode( $this->live_propose_output_schema() ) );
			$schemas->register( 'builder.live_apply.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.live_apply.output', wp_json_encode( $this->stage_output_schema() ) );
			$registry->register( new Operation( array( 'id' => 'builder.live_propose', 'revision' => '1', 'description' => 'Prepare a separate exact review for one scoped text change in the currently open native page editor.', 'input_schema' => 'builder.live_propose.input', 'output_schema' => 'builder.live_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ), fn( object $input, Context $context ): bool => $can_live( $context ), $can_live, static fn(): bool => true, static fn( object $input, Context $context ): object => $live->propose( $context, $input ) ) );
			$registry->register( new Operation( array( 'id' => 'builder.live_apply', 'revision' => '1', 'description' => 'Issue one exact approved native editor ticket. Completion requires a separate native Save and readback.', 'input_schema' => 'builder.live_apply.input', 'output_schema' => 'builder.live_apply.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ), fn( object $input, Context $context ): bool => $can_live( $context ), $can_live, static fn(): bool => true, static fn(): object => (object) array() ) );
		}
		$draft_handlers = array();
		if ( $graph_storage_available ) {
			foreach ( array( 'gutenberg', 'elementor', 'divi' ) as $builder ) {
				$native_reader = $graph_providers->provider( $builder );
				$draft_adapter = $draft_adapters->adapter( $builder );
				if ( $native_reader instanceof BuilderNativeSourceReader && null !== $draft_adapter ) {
					$draft_handlers[ $builder ] = new BuilderDraftEffectHandler( $graph_db, new BuilderChangePlannerFactory( $graph_db, new GraphSnapshotStore( $graph_db ), $relationship_validator, $planning_policies ), $draft_adapter->facts, $native_reader, $draft_adapter->compiler, $draft_adapter->writer, $environment, $directories );
				}
			}
		}
		if ( $draft_handlers ) {
			$this->draft_handler = 1 === count( $draft_handlers ) ? reset( $draft_handlers ) : new BuilderDraftEffectDispatcher( $graph_db, $draft_handlers );
			$declared_operations = array();
			$all_declared = true;
			foreach ( array_keys( $draft_handlers ) as $builder ) {
				$native_compiler = $draft_adapters->adapter( $builder )->compiler;
				if ( ! is_callable( array( $native_compiler, 'authoring_contract' ) ) ) {
					$all_declared = false;
					break;
				}
				$contract = $native_compiler->authoring_contract();
				( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 12 ) )->check( $contract );
				if ( ! is_array( $contract->operations ?? null ) ) {
					throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_contract', 'Native compiler operation declaration is unavailable.' );
				}
				$declared_operations = array_merge( $declared_operations, $contract->operations );
				if ( is_callable( array( $native_compiler, 'compile_directory_query' ) ) ) {
					$declared_operations[] = 'configure_directory_query'; }
				if ( is_callable( array( $native_compiler, 'compile_directory_binding' ) ) && is_callable( array( $native_compiler, 'directory_binding_contract_for_graph' ) ) && is_callable( array( $native_compiler, 'directory_binding_directory_ids' ) ) ) {
					$declared_operations[] = 'configure_directory_binding';
				}
				if ( is_callable( array( $native_compiler, 'compile_saved_variant_controls' ) ) && is_callable( array( $native_compiler, 'saved_variant_controls_contract' ) ) ) {
					$declared_operations[] = 'configure_saved_variants';
				}
			}
			$this->draft_change_operations = $all_declared ? array_values( array_unique( $declared_operations, SORT_STRING ) ) : null;
			$schemas->register( 'builder.draft_propose.input', wp_json_encode( $this->draft_propose_input_schema() ) );
			$schemas->register( 'builder.draft_propose.output', wp_json_encode( $this->draft_propose_output_schema() ) );
			$factory_catalog_revision = static function ( Context $context, object $document, ?int $directory_id ) use ( $compiler, $cache, $environment ): string {
				$catalog_request = new CatalogRequest( $context, $document->builder, $document->native_kind, $directory_id, null );
				$current = $environment->read();
				$catalog = null === $cache ? $compiler->compile( $catalog_request, $current ) : $cache->describe( $catalog_request, $current )->catalog();
				return $catalog->revision;
			};
			$draft_proposals = new BuilderDraftProposalService( $graph_db, $graph_providers, $draft_adapters, $planning_policies, $relationship_validator, $environment, $directories, $factory_catalog_revision );
			$registry->register(
				new Operation(
					array( 'id' => 'builder.draft_propose', 'revision' => '2', 'description' => 'Validate one scoped saved native builder change and prepare a separate private draft for exact human review.', 'input_schema' => 'builder.draft_propose.input', 'output_schema' => 'builder.draft_propose.output', 'effect' => 'private', 'profiles' => array( 'admin', 'builder' ), 'allow_guest' => false ),
					fn( object $input, Context $context ): bool => $this->permitted( $context, $inspector, $input->directory_id ),
					fn( Context $context ): bool => $this->can_build( $context ),
					static fn(): bool => $graph_providers->available(),
					static fn( object $input, Context $context ): object => $draft_proposals->propose( $context, $input )
				)
			);
			$schemas->register( 'builder.stage.input', wp_json_encode( $this->stage_input_schema() ) );
			$schemas->register( 'builder.stage.output', wp_json_encode( $this->stage_output_schema() ) );
			$registry->register(
				new Operation(
					array( 'id' => 'builder.stage', 'revision' => '1', 'description' => 'Create one isolated native page or template draft from an exact human-approved builder stage.', 'input_schema' => 'builder.stage.input', 'output_schema' => 'builder.stage.output', 'effect' => 'write', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false, 'retry_policy' => 'reconcile' ),
					fn( object $input, Context $context ): bool => ! $context->actor()->is_guest() && ( $context->actor()->can( 'edit_pages' ) || $context->actor()->can( 'edit_theme_options' ) ),
					fn( Context $context ): bool => ! $context->actor()->is_guest() && ( $context->actor()->can( 'edit_pages' ) || $context->actor()->can( 'edit_theme_options' ) ),
					static fn(): bool => true,
					static fn(): object => (object) array()
				)
			);
		}
		$registry->register(
			new Operation(
				array( 'id' => 'builder.describe', 'revision' => '1', 'description' => 'Describe current authorized native builder components and eligible operation tuples.', 'input_schema' => 'builder.describe.input', 'output_schema' => 'builder.describe.output', 'effect' => 'read', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false ),
				fn( object $input, Context $context ): bool => $this->permitted( $context, $inspector, $input->directory_id ),
				fn( Context $context ): bool => $this->can_build( $context ),
				static fn(): bool => true,
				static function ( object $input, Context $context ) use ( $cache, $compiler, $environment, $bindings, $graph_db, $draft_adapters ): object {
					$selected = BuilderConversationTarget::active_context( $graph_db, $context );
					if ( null !== $selected ) {
						BuilderConversationTarget::assert_catalog_binding( $selected, $input->builder, $input->directory_id );
					}
					$binding = $bindings->resolve( $input->editor_binding_id ?? null, $context, $input->builder );
					$request = new CatalogRequest( $context, $input->builder, $input->document_kind, $input->directory_id, $binding );
					$current = $environment->read();
					$catalog = null === $cache ? $compiler->compile( $request, $current ) : $cache->describe( $request, $current )->catalog();
					if ( 'discovery' !== ( $input->projection ?? null ) ) {
						return $catalog;
					}
					$native_adapter = $draft_adapters->adapter( $input->builder );
					$priority = null === $native_adapter ? array() : ( BuilderAuthoringContract::insertion_identities_for_document( $native_adapter->compiler ) ?? array() );
					return BuilderAuthoringContract::discovery( $catalog, $priority );
				}
			)
		);
		$schemas->register( 'builder.inspect.input', wp_json_encode( $this->inspect_input_schema() ) );
		$schemas->register( 'builder.inspect.output', wp_json_encode( $this->inspect_output_schema() ) );
		$registry->register(
			new Operation(
				array( 'id' => 'builder.inspect', 'revision' => '2', 'description' => 'Inspect the selected authorized native graph; include_authoring returns bounded installed planning schemas and relationships for selected/current and candidate components.', 'input_schema' => 'builder.inspect.input', 'output_schema' => 'builder.inspect.output', 'effect' => 'read', 'profiles' => array( 'admin', 'builder', 'external' ), 'allow_guest' => false ),
				fn( object $input, Context $context ): bool => $this->permitted( $context, $inspector, $input->directory_id ),
				fn( Context $context ): bool => $this->can_build( $context ),
				static fn(): bool => $graph_providers->available() && $graph_storage_available,
				static function ( object $input, Context $context ) use ( $bindings, $directories, $graph_providers, $graph_projection, $environment, $cache, $compiler, $planning_policies, $relationship_validator, $draft_adapters, $graph_db ): object {
					if ( true === ( $input->include_single_layout ?? false ) && true !== ( $input->include_authoring ?? false ) ) {
						throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Single-layout discovery requires include_authoring.' ); }
					if ( true === ( $input->include_single_layout ?? false ) && ! in_array( $input->document->builder, array( 'divi', 'elementor' ), true ) ) {
						throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'This builder uses ordinary include_authoring with exact authoring_components. Omit include_single_layout and inspect only the required native components.' );
					}
					$provider = $graph_providers->provider( $input->document->builder );
					if ( null === $provider ) {
						throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_dependency', 'Native graph provider is unavailable.' );
					}
					$binding = $bindings->resolve( $input->editor_binding_id ?? null, $context, $input->document->builder );
					$directory_facts = null === $input->directory_id ? null : $directories->read( $context, $input->directory_id );
					$request = new BuilderGraphInspectionRequest( $context, $input->document, $input->scope, $input->selection, $directory_facts, $binding, $environment );
					$inspection = $graph_projection->inspect( $provider, $request );
					$response = $inspection->result->response();
					$response->semantic_report = $inspection->semantic_report;
					$response->projection = $inspection->projection;
					$query_adapter = $draft_adapters->adapter( $input->document->builder );
					$query_capture = BuilderDirectoryQueryService::editor_query_context( $input->document );
					if ( null !== $query_capture && null !== $query_adapter && is_callable( array( $query_adapter->compiler, 'directory_query_contract_for_graph' ) ) && $inspection->semantic_report->accepted ) {
						$query = $query_adapter->compiler->directory_query_contract_for_graph( $response->graph );
						$binding_json = BuilderConversationTarget::active_context( $graph_db, $context );
						$query->targets = array_values(
							array_filter(
								$query->targets ?? array(),
								static function ( object $item ) use ( $binding_json, $response ): bool {
									try {
										$nodes = array_values( array_filter( $response->graph->nodes, static fn( object $n ): bool => ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $n->locator ) === ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $item->target ) ) );
										if ( 1 !== count( $nodes ) ) {
																return false;
										}
										if ( 'template:home_search' === $response->graph->document->native_kind ) {
											if ( 'complete' !== $response->graph->completeness || 'complete' !== $response->graph->consumer_coverage || 'directorist-gb-integration' !== $nodes[0]->provider_id || 'directorist-gb-integration/homepage-search-loop' !== $nodes[0]->native_id ) {
												return false;
											}
											BuilderDirectoryQueryService::assert_home_certificate( $item->native_certificate ?? null, $response->graph->document, $response->graph->version->source_hash, $item->target, $item->configuration ?? $item->before );
										}
										BuilderEditorSelection::assert_changes( $binding_json, $response->graph, array( (object) array( 'target_id' => $nodes[0]->id ) ) );
										return true;
									} catch ( \Throwable $error ) {
										return false; }
								}
							)
						);
						$response->directory_query = $query;
					}
					if ( 'page' === $input->document->native_kind && 'editor_candidate' === ( $query_capture['output_mode'] ?? null ) && null !== $query_adapter && is_callable( array( $query_adapter->compiler, 'directory_binding_contract_for_graph' ) ) && $inspection->semantic_report->accepted ) {
						$directory_binding = $query_adapter->compiler->directory_binding_contract_for_graph( $response->graph );
						$binding_json = BuilderConversationTarget::active_context( $graph_db, $context );
						$directory_binding->targets = array_values(
							array_filter(
								$directory_binding->targets ?? array(),
								static function ( object $item ) use ( $binding_json, $response ): bool {
									try {
										$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
										$nodes = array_values( array_filter( $response->graph->nodes, static fn( object $node ): bool => $canonical->encode( $node->locator ) === $canonical->encode( $item->target ) ) );
										if ( 1 !== count( $nodes ) ) {
											return false; }
										BuilderEditorSelection::assert_changes( $binding_json, $response->graph, array( (object) array( 'target_id' => $nodes[0]->id ) ) );
										return true;
									} catch ( \Throwable $error ) {
										return false;
									}
								}
							)
						);
						$response->directory_binding = $directory_binding;
					}
					if ( true === ( $input->include_saved_variants ?? false ) && in_array( $input->document->builder, array( 'elementor', 'gutenberg' ), true ) && 'page' === $input->document->native_kind && 'editor_candidate' === ( $query_capture['output_mode'] ?? null ) && null !== $query_adapter && is_callable( array( $query_adapter->compiler, 'saved_variant_controls_contract' ) ) && $inspection->semantic_report->accepted ) {
						$response->saved_variants = ( new BuilderSavedVariantControlsService( $graph_db, $provider, $query_adapter->compiler, $environment, $directories ) )->describe( $request, $inspection->result );
					}
					$response->authoring = null;
					$selection_context = BuilderConversationTarget::active_context( $graph_db, $context );
					$selection_binding = null === $selection_context ? null : json_decode( $selection_context );
					$selected_node = null;
					if ( isset( $selection_binding->builder_selection ) ) {
						BuilderEditorSelection::assert_source( $selection_binding->builder_selection, $response->graph->document );
						$selected_node = BuilderEditorSelection::resolve_node( $selection_binding->builder_selection, $response->graph );
					}
					if ( $input->include_authoring ?? false ) {
						$catalog_request = new CatalogRequest( $context, $input->document->builder, $input->document->native_kind, $input->directory_id, $binding );
						$current = $environment->read();
						$catalog = null === $cache ? $compiler->compile( $catalog_request, $current ) : $cache->describe( $catalog_request, $current )->catalog();
						$adapter = $draft_adapters->adapter( $input->document->builder );
						$augments_dependencies = false;
						if ( null !== $adapter ) {
							try {
								$augments_dependencies = $adapter->facts->create( $request ) instanceof CandidateGraphRelationshipAugmentor;
							} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
								if ( ! in_array( $error->error_code(), array( 'unsupported_contract', 'unsupported_operation', 'unsupported_dependency' ), true ) ) {
									throw $error;
								}
							}
						}
						$authoring_selection = $input->selection;
						if ( null !== $selected_node && ! $authoring_selection ) {
							$authoring_selection = array( $selected_node->locator );
						}
						if ( true === ( $input->include_single_layout ?? false ) ) {
							if ( null !== $selected_node || ! in_array( $input->document->builder, array( 'divi', 'elementor' ), true ) || 'template:single_listing' !== $input->document->native_kind || ! empty( $input->authoring_components ) || null === $adapter || ! is_callable( array( $adapter->compiler, 'single_listing_authoring_components' ) ) ) {
								throw new \DirectoristAIPro\Validation\ContractException( 'scope_conflict', 'Single-layout discovery requires its exact native root and no mixed component request.' ); }
							$input = clone $input;
							$input->authoring_components = $adapter->compiler->single_listing_authoring_components( $input->document, $input->scope );
						}
						$content_contract = null === $adapter ? null : self::page_content_contract( $adapter->compiler, $input, $response->graph, $selected_node );
						$layout_contract = null === $adapter ? null : self::page_layout_contract( $adapter->compiler, $input, $response->graph, $selected_node );
						$content_contract = $layout_contract ?? $content_contract;
						$single_contract = null === $adapter ? null : self::single_listing_contract( $adapter->compiler, $input, $response->graph, $selected_node );
						if ( true === ( $input->include_single_layout ?? false ) && null === $single_contract ) {
							throw new \DirectoristAIPro\Validation\ContractException( 'scope_conflict', 'This exact native single-listing layout contract is unavailable.' ); }
						$content_contract = $single_contract ?? $content_contract;
						$closed_loop = null === $adapter ? null : self::closed_loop_contract( $adapter->compiler, $input, $response->graph, $selected_node );
						if ( null !== $closed_loop ) {
							$content_contract = $closed_loop;
							$authoring_selection = array( $closed_loop->first_loop->parent );
						}
						$insertion_only = null !== $content_contract && array( 'insert' ) === ( $content_contract->operations ?? null );
						$insertion_schemas = array();
						foreach ( $single_contract->insertion_attributes ?? array() as $row ) {
							$insertion_schemas[ $row->provider_id . '|' . $row->native_id ] = $row->insert_attributes_schema; }
						$response->authoring = ( new BuilderAuthoringContract( $planning_policies, $relationship_validator ) )->describe( $catalog, $response->graph, $authoring_selection, $input->authoring_components ?? array(), $augments_dependencies, null !== $closed_loop, $input->control_paths ?? array(), $insertion_only, null !== $adapter && is_callable( array( $adapter->compiler, 'creation_control_schema_for_component' ) ) ? static fn( object $identity, array $paths ) => $adapter->compiler->creation_control_schema_for_component( clone $input->document, clone $input->scope, $identity, $paths ) : null, $insertion_schemas, null !== $layout_contract || null !== $closed_loop );
						if ( null !== $adapter ) {
							BuilderAuthoringContract::narrow_document_insertions( $response->authoring, $adapter->compiler, $input->document );
						}
						if ( null !== $adapter ) {
							self::initial_control_defaults( $response->authoring, $adapter->compiler, $input->document, $input->scope, $input->control_paths ?? array() ); }
						if ( null !== $selected_node ) {
							$response->authoring->selected_area = (object) array( 'locator' => $selected_node->locator, 'scope' => $selected_node->scope, 'provider_id' => $selected_node->provider_id, 'native_id' => $selected_node->native_id );
						}

						$response->authoring->draft = null;
						$response->authoring->can_propose_private_draft = false;
						if ( null !== $adapter && is_callable( array( $adapter->compiler, 'authoring_contract' ) ) ) {
							$control_node = $selected_node;
							if ( 1 === count( $authoring_selection ) ) {
								$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
								foreach ( $response->graph->nodes as $node ) {
									if ( $canonical->encode( $node->locator ) === $canonical->encode( $authoring_selection[0] ) ) {
										$control_node = $node;
										break; }
								}
							}
							// An explicit saved-node control read is independent of a
							// toolbar conversation selection. It needs the current control
							// codec contract, not a creation-only document factory.
							$saved_control_contract = self::saved_control_contract( $adapter->compiler, $input, $response->graph, $authoring_selection );
							$exact_control_read = null !== $saved_control_contract;
							if ( $exact_control_read && null === $selected_node && null !== $control_node && 1 === count( $authoring_selection ) ) {
								// Describe the explicitly inspected native area without changing
								// the page conversation's toolbar selection or write fence.
								$response->authoring->selected_area = (object) array( 'locator' => $control_node->locator, 'scope' => $control_node->scope, 'provider_id' => $control_node->provider_id, 'native_id' => $control_node->native_id );
							}
							$contract = $saved_control_contract ?? $content_contract ?? ( null === $selected_node && ! $exact_control_read && is_callable( array( $adapter->compiler, 'authoring_contract_for_graph' ) ) ? $adapter->compiler->authoring_contract_for_graph( $response->graph ) : $adapter->compiler->authoring_contract() );
							BuilderAuthoringContract::narrow_selected_controls( $response->authoring, $control_node, $adapter->compiler );
							$contract = BuilderAuthoringContract::narrow_draft_controls( $contract, $response->authoring, $control_node, $adapter->compiler );
							BuilderAuthoringContract::assert_metadata( $contract );
							$response->authoring->draft = $contract;
							$copy_ready = isset( $inspection->private_copy_report ) && $inspection->private_copy_report->accepted;
							$draft_source_ready = in_array( $context->profile(), array( 'admin', 'builder' ), true ) && in_array( $context->transport(), array( 'ui', 'worker' ), true ) && null !== $context->conversation_id() && null !== $context->run_id() && 'complete' === $response->graph->completeness && ( ( 'complete' === $response->graph->consumer_coverage && $inspection->semantic_report->accepted ) || $copy_ready ) && null === $response->graph->version->editor_epoch && in_array( $input->document->native_kind, $contract->document_kinds ?? array(), true );
							$response->authoring->can_propose_private_draft = $draft_source_ready && 'complete_for_selection' === $response->authoring->state;
							$removal_subset = self::saved_leaf_removal_subset( $contract, $input, $response->graph, $control_node, $authoring_selection, $response->authoring, $draft_source_ready && $inspection->semantic_report->accepted );
							if ( null !== $removal_subset ) {
								$response->authoring->ready_subsets = array_merge( $response->authoring->ready_subsets ?? array(), array( $removal_subset ) );
								$response->authoring->next_read = 'ready_subsets contains a complete exact_saved_leaf_removal contract. For that removal, prepare its one remove operation directly; unrelated style controls do not need inspection or a client decision. The wider style inventory still needs narrowing for a separate control edit.';
							}
							// An oversized discovery is not a proposed insertion. Retain its
							// exact control-path recovery until a smaller schema is available.
							if ( ! self::needs_smaller_authoring_read( $response->authoring ) && is_callable( array( $adapter->compiler, 'authoring_readiness_for_graph' ) ) ) {
								// Schema availability alone does not prove that this saved
								// scope has a native insertion parent or a resolved view.
								$readiness = $adapter->compiler->authoring_readiness_for_graph( $response->graph, $input->scope, $input->authoring_components ?? array(), $authoring_selection );
								( new \DirectoristAIPro\Validation\JsonDecoder( 4096, 8 ) )->check( $readiness );
								$response->authoring->draft_readiness = $readiness;
								if ( true !== ( $readiness->ready ?? null ) ) {
									$response->authoring->can_propose_private_draft = false;
									$response->authoring->state = 'needs_narrowing';
									$response->authoring->diagnostics = array_merge( $response->authoring->diagnostics, $readiness->diagnostics ?? array() );
									$response->authoring->next_read = $readiness->next_read ?? 'Clarify the directory, view or supported saved region before proposing a draft.';
								}
							}
							$first_loop = $draft_source_ready && null === $selected_node && ! $exact_control_read ? BuilderElementorLoopCreationProof::for_saved_page( $request, $inspection->result ) : null;
							$new_elementor_loop = 'elementor' === $input->document->builder && array_filter( $input->authoring_components ?? array(), static fn( object $component ): bool => 'directorist-elementor' === $component->provider_id && in_array( $component->native_id, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) && ! array_filter( $response->graph->nodes, static fn( object $node ): bool => 'directorist-elementor' === $node->provider_id && in_array( $node->native_id, array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) && ( $node->scope->directory_id ?? null ) === ( $input->scope->directory_id ?? null ) );
							if ( $new_elementor_loop && null === $first_loop && null === $layout_contract ) {
								$response->authoring->can_propose_private_draft = false;
								$response->authoring->state = 'needs_narrowing';
								$response->authoring->diagnostics[] = (object) array( 'code' => 'native_first_loop_proof_required' );
								$response->authoring->next_read = 'A first directory loop requires the installed native proof for this saved document, directory and initial view. Clarify or refresh that selection before proposing.';
							}
							if ( null !== $first_loop ) {
								$hint = $first_loop->authoring_hint();
								if ( $response->authoring->can_propose_private_draft ) {
									$response->authoring->draft->first_loop = $hint;
								} elseif ( 'needs_narrowing' === $response->authoring->state && array_filter( $input->authoring_components ?? array(), static fn( object $component ): bool => $hint->provider_id === $component->provider_id && $hint->native_id === $component->native_id ) ) {
									// An unrelated unsupported search parent must not hide the
									// exact one-loop recipe whose defaults already supply search.
									$identity = (object) array( 'provider_id' => $hint->provider_id, 'native_id' => $hint->native_id );
									$subset = ( new BuilderAuthoringContract( $planning_policies, $relationship_validator ) )->describe( $catalog, $response->graph, array( $hint->parent ), array( $identity ), $augments_dependencies, true, array(), true );
									if ( 'complete_for_selection' === $subset->state ) {
										$subset->readiness_scope = 'exact_first_directory_loop';
										$subset->can_propose_private_draft = true;
										$subset->graph_version_hash = $inspection->result->version()->hash();
										$subset->graph_hash = $inspection->result->graph_hash();
										$subset->authoring_components = array( $identity );
										$subset->draft = (object) array( 'revision' => $contract->revision, 'max_changes' => 1, 'operations' => array( 'insert' ), 'first_loop' => $hint );
										$subset->next_read = 'This complete subset supports exactly one native first-loop insertion. Copy draft.first_loop parent/scope and insert one declared loop with empty attributes. Native defaults provide search, cards, header and pagination. Preserve saved source content. Do not insert or hand-author those default children. Other requested unavailable components remain unavailable.';
										$response->authoring->ready_subsets = array( $subset );
										$response->authoring->suggested_authoring_components = array( $identity );
									}
								}
							}
							$effectiveness = BuilderAuthoringContract::describe_control_effectiveness( $response->authoring, $control_node, $adapter->compiler );
							if ( isset( $response->authoring->selected_area ) ) {
								$unset_controls = BuilderAuthoringContract::describe_unset_controls( $response->authoring, $control_node, $adapter->compiler, $inspection->result );
								if ( $unset_controls ) {
									$response->authoring->selected_area->native_unset_controls = $unset_controls; }
							}
							if ( null !== $effectiveness ) {
								$response->authoring->control_effectiveness = $effectiveness;
							}
						}
						if ( true === ( $response->authoring->can_propose_private_draft ?? false ) && ! BuilderAuthoringContract::has_actionable_contract( $response->authoring ) ) {
							$response->authoring->can_propose_private_draft = false;
							$response->authoring->state = 'needs_narrowing';
							$response->authoring->diagnostics[] = (object) array( 'code' => 'authoring_contracts_required' );
							$response->authoring->next_read = 'No actionable component schema or native creation recipe was returned. Keep current discovery and inspect the exact required authoring_components. Do not repeat this empty read or claim a draft is ready.';
						}
						$related_colors = self::related_color_context( $response, $input, $planning_policies );
						if ( null !== $related_colors ) { $response->authoring->related_color_context = $related_colors; }
						if ( null !== ( $control_node ?? null ) && null !== $adapter && is_callable( array( $adapter->compiler, 'selected_insertion_components_for_node' ) ) ) {
							$insert_components = array();
							foreach ( $adapter->compiler->selected_insertion_components_for_node( $control_node ) as $identity ) {
								foreach ( $catalog->components as $component ) {
									if ( $identity->provider_id === $component->source_provider_id && $identity->native_id === $component->native_id && in_array( $component->availability, array( 'available', 'read_only' ), true ) && $relationship_validator->supports_relationship( 'contains', $control_node->provider_id, $control_node->native_id, $identity->provider_id, $identity->native_id ) ) {
										$insert_components[] = $identity;
										break;
									}
								}
							}
							if ( $insert_components && count( $insert_components ) <= 12 ) {
								$response->authoring->selected_insertion_read = (object) array(
									'parent'               => $control_node->locator,
									'scope'                => $control_node->scope,
									'authoring_components' => $insert_components,
									'next_read'            => 'For a requested new child, inspect only this exact parent locator and scope with include_authoring:true, empty control_paths and the desired authoring_components from this list. Do not include virtual cached-view slots or unchanged saved children in selection. This is read guidance only; the returned insertion schema, native composition and selected-area authority still validate every draft.',
								);
								$insertion_ready = 'complete_for_selection' === $response->authoring->state && true === $response->authoring->can_propose_private_draft && 'existing_insertion_parents' === ( $response->authoring->source_contract_mode ?? null );
								$response->authoring->selected_insertion_read->contract_ready = $insertion_ready;
								if ( $insertion_ready ) {
									$response->authoring->selected_insertion_read->next_read = 'The requested insertion contract is already complete. Propose the requested new child using this parent, its exact scope, returned insert_attributes_schema and native_positions. The native compiler preserves existing child content and styling. Do not inspect unchanged child controls or cached-view slots merely to preserve them, and do not repeat this authoring read. A separate saved-child edit needs its own exact scope and control contract.';
								}
								if ( is_callable( array( $adapter->compiler, 'selected_insertion_positions_for_node' ) ) ) {
									$positions = $adapter->compiler->selected_insertion_positions_for_node( $control_node, $response->graph );
									if ( null !== $positions ) {
										BuilderAuthoringContract::assert_metadata( $positions );
										$response->authoring->selected_insertion_read->native_positions = $positions;
									}
								}
							}
						}
						if ( null !== $adapter ) {
							$design_context = BuilderDesignContext::capture( $adapter->compiler, $response->graph );
							if ( null !== $design_context ) {
								$response->authoring->design_context = $design_context;
							}
						}
						$request->assert_environment_current();
						$fresh = $provider->inspect( $request );
						$request->assert_result( $fresh );
						$base = $inspection->result;
						if ( ! hash_equals( $base->version()->hash(), $fresh->version()->hash() ) || ! hash_equals( $base->graph_hash(), $fresh->graph_hash() ) || ! hash_equals( $base->facts()->revision(), $fresh->facts()->revision() ) ) {
							throw new \DirectoristAIPro\Validation\ContractException( 'evidence_stale', 'Native authoring context changed during catalog assembly.' );
						}
					}
					// Delivery may omit unrelated derived style inventories only after
					// all semantic, authoring and freshness checks use the full graph.
					if ( null !== $selected_node && strlen( wp_json_encode( $response ) ) > 1048576 ) {
						return BuilderSelectedGraphDelivery::project( $response, $selected_node );
					}
					return $response;
				}
			)
		);
	}

	/** Register the certified native draft handler after operation registration. */
	public function register_effects( EffectHandlerRegistry $handlers ): void {
		if ( null !== $this->page_publication ) {
			$handlers->register( 'builder.publish', $this->page_publication );
			$handlers->register( 'builder.restore', $this->page_publication );
		}
		if ( null !== $this->template_assignment ) {
			$handlers->register( 'builder.template_assign', $this->template_assignment );
			$handlers->register( 'builder.template_restore', $this->template_assignment );
		}
		if ( null !== $this->scoped_live_apply ) {
			$handlers->register( 'builder.live_apply', $this->scoped_live_apply );
		}
		if ( null !== $this->draft_handler ) {
			$handlers->register( 'builder.stage', $this->draft_handler );
		}
	}

	/** Recheck builder and optional directory authority at invocation. */
	private function permitted( Context $context, DirectoristInspector $inspector, ?int $directory_id ): bool {
		return $this->can_build( $context ) && ( null === $directory_id || $inspector->can_describe( $context, $directory_id, 'submission' ) );
	}

	/** A profile never grants page/template editing authority. */
	private function can_build( Context $context ): bool {
		return ! $context->actor()->is_guest() && ( $context->actor()->can( 'edit_pages' ) || $context->actor()->can( 'edit_posts' ) || $context->actor()->can( 'edit_theme_options' ) );
	}

	private function stage_input_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'approval_id' => array( 'type' => 'string' ), 'operation_id' => array( 'type' => 'string' ), 'review_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ), 'required' => array( 'approval_id', 'operation_id', 'review_hash' ), 'additionalProperties' => false );
	}

	private function publish_propose_input_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'request_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ), 'draft_operation_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_.:-]{1,128}$' ), 'title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ) ), 'required' => array( 'request_id', 'draft_operation_id', 'title' ), 'additionalProperties' => false );
	}

	private function publish_propose_output_schema(): array {
		$draft = $this->draft_propose_output_schema();
		return array( 'type' => 'object', 'properties' => array( 'operation_id' => $draft['properties']['operation_id'], 'artifact' => $draft['properties']['artifact'], 'review' => $draft['properties']['review'], 'page_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'required' => array( 'operation_id', 'artifact', 'review', 'page_id' ), 'additionalProperties' => false );
	}

	private function template_propose_output_schema(): array {
		$publish = $this->publish_propose_output_schema();
		unset( $publish['properties']['page_id'] );
		$publish['properties']['draft_id'] = array( 'type' => 'integer', 'minimum' => 1 );
		$publish['required'] = array( 'operation_id', 'artifact', 'review', 'draft_id' );
		return $publish;
	}

	private function live_propose_output_schema(): array {
		$publish = $this->publish_propose_output_schema();
		unset( $publish['properties']['page_id'] );
		$publish['properties']['source_page_id'] = array( 'type' => 'integer', 'minimum' => 1 );
		$publish['required'] = array( 'operation_id', 'artifact', 'review', 'source_page_id' );
		return $publish;
	}

	private function draft_propose_input_schema(): array {
		$inspect = $this->inspect_input_schema();
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'request_id'      => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$' ),
				'document'        => $inspect['properties']['document'],
				'directory_id'    => $inspect['properties']['directory_id'],
				'scope'           => $inspect['properties']['scope'],
				'intent'          => array( 'enum' => array( 'private_page_draft', 'private_template_draft' ) ),
				'directory_query' => BuilderDirectoryQueryService::configuration_schema(),
				'changes'         => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => BuilderChangeSchema::schema( $inspect['properties']['selection']['items'], $inspect['properties']['scope'], $this->draft_change_operations ) ),
			),
			'required'             => array( 'request_id', 'document', 'directory_id', 'scope', 'intent', 'changes' ),
			'additionalProperties' => false,
		);
	}

	private function draft_propose_output_schema(): array {
		$reference = array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'version' => array( 'type' => 'integer' ), 'hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ), 'required' => array( 'id', 'version', 'hash' ), 'additionalProperties' => false );
		return array( 'type' => 'object', 'properties' => array( 'operation_id' => array( 'type' => 'string' ), 'artifact' => $reference, 'review' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'string' ), 'state' => array( 'type' => 'string' ), 'review_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ) ), 'required' => array( 'id', 'state', 'review_hash' ), 'additionalProperties' => false ), 'builder' => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ), 'document_key' => array( 'type' => 'string' ), 'intent' => array( 'enum' => array( 'private_page_draft', 'private_template_draft' ) ), 'native_content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ), 'block_count' => array( 'type' => 'integer' ), 'output_mode' => array( 'enum' => array( 'editor_candidate', 'private_draft' ) ) ), 'required' => array( 'operation_id', 'artifact', 'review', 'builder', 'document_key', 'intent', 'native_content_hash', 'block_count' ), 'additionalProperties' => false );
	}

	private function stage_output_schema(): array {
		return array( 'type' => 'object', 'properties' => array( 'operation_id' => array( 'type' => 'string' ), 'plan_hash' => array( 'type' => 'string' ), 'proposal_hash' => array( 'type' => 'string' ), 'target_set_hash' => array( 'type' => 'string' ), 'state' => array( 'enum' => array( 'succeeded', 'failed', 'partial', 'unknown' ) ), 'plan' => array( 'type' => 'object' ), 'targets' => array( 'type' => 'object' ), 'external_effects' => array( 'type' => 'array' ), 'observed_at' => array( 'type' => 'string' ) ), 'required' => array( 'operation_id', 'plan_hash', 'proposal_hash', 'target_set_hash', 'state', 'plan', 'targets', 'external_effects', 'observed_at' ), 'additionalProperties' => false );
	}

	/** Closed compact discovery validates before the gateway's response byte bound. */
	private function discovery_output_schema(): array {
		$token = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 256 );
		$component = array(
			'type'                 => 'object',
			'properties'           => array(
				'provider_id'  => $token,
				'native_id'    => $token,
				'availability' => array( 'enum' => array( 'available', 'read_only', 'unavailable', 'unsupported', 'conflict' ) ),
				'operations'   => array( 'type' => 'array', 'maxItems' => 20, 'items' => $token ),
			),
			'required'             => array( 'provider_id', 'native_id', 'availability', 'operations' ),
			'additionalProperties' => false,
		);
		$provider = array( 'type' => 'object', 'properties' => array( 'reader_id' => $token, 'status' => $token ), 'required' => array( 'reader_id', 'status' ), 'additionalProperties' => false );
		$properties = array(
			'contract'               => array( 'const' => 'directorist-ai/builder-discovery-v1' ),
			'builder'                => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ),
			'document_kind'          => $token,
			'directory_id'           => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
			'revision'               => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
			'state'                  => array( 'enum' => array( 'complete_for_discovery', 'needs_narrowing' ) ),
			'components'             => array( 'type' => 'array', 'maxItems' => 100, 'items' => $component ),
			'omitted_components'     => array( 'type' => 'integer', 'minimum' => 0 ),
			'next_read'              => array( 'type' => 'string', 'maxLength' => 2000 ),
			'catalog_provider_state' => array( 'enum' => array( 'complete', 'incomplete' ) ),
			'unavailable_providers'  => array( 'type' => 'array', 'maxItems' => 100, 'items' => $provider ),
		);
		return array( 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false );
	}

	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'builder'           => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ),
				'document_kind'     => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,255}$' ),
				'projection'        => array( 'enum' => array( 'discovery' ) ),
				'directory_id'      => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
				'editor_binding_id' => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,255}$' ),
			),
			'required'             => array( 'builder', 'document_kind', 'directory_id' ),
			'additionalProperties' => false,
		);
	}

	private function inspect_input_schema(): array {
		$scope = array(
			'type'                 => 'object',
			'properties'           => array(
				'directory_id'  => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[1-9][0-9]{0,127}$' ),
				'view'          => array( 'enum' => array( null, 'grid', 'list', 'map', 'single' ) ),
				'breakpoint_id' => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,127}$' ),
				'interaction'   => array( 'enum' => array( null, 'normal', 'hover' ) ),
			),
			'required'             => array( 'directory_id', 'view', 'breakpoint_id', 'interaction' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document'               => array(
					'type'                 => 'object',
					'properties'           => array(
						'builder'     => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ),
						'native_kind' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$' ),
						'native_id'   => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$' ),
					),
					'required'             => array( 'builder', 'native_kind', 'native_id' ),
					'additionalProperties' => false,
				),
				'include_authoring'      => array( 'type' => 'boolean', 'description' => 'Use false for a saved-graph-only read. Use true when requesting exact control_paths or writable native component schemas.' ),
				'include_saved_variants' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Explicit read-only opt-in for an existing native loop: Elementor saved card/search variants or Gutenberg Grid/List card backgrounds, with exact native scopes and before values for one reviewed transaction. Omit for ordinary selected reads or initial creation; default false performs no variant inventory.' ),
				'include_single_layout'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'Explicit bounded Divi or Elementor single-listing creation read. Requires include_authoring:true, exact current template document root selection, normal/single scope and authoring_components:[]. Server selects its exact native wrappers and dynamic Title/Description/Price/Address components; no generic component cap or saved-edit authority changes.' ),
				'control_paths'          => array( 'type' => 'array', 'maxItems' => 200, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'maxLength' => 256, 'pattern' => '^[A-Za-z0-9_-]+(?:\\.[A-Za-z0-9_-]+){0,15}$' ), 'description' => 'Optional exact registered public property paths, copied from control_index, such as style.color.text. Never prefix a component identity: core/heading.style is invalid. Do not guess parent groups such as style.color unless explicitly registered. For an existing-page edit without returned node locators, first read selection:[], include_authoring:false, authoring_components:[], control_paths:[], then copy exact returned locators and paths into include_authoring:true reads. Omit the filter for an ordinary full read. Filtering grants no write permission; native source and full policy are still validated.' ),
				'authoring_components'   => array( 'type' => 'array', 'maxItems' => 12, 'uniqueItems' => true, 'items' => array( 'type' => 'object', 'properties' => array( 'provider_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$' ), 'native_id' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$' ) ), 'required' => array( 'provider_id', 'native_id' ), 'additionalProperties' => false ) ),
				'directory_id'           => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
				'editor_binding_id'      => array( 'type' => array( 'string', 'null' ), 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:/-]{0,255}$' ),
				'scope'                  => $scope,
				'selection'              => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'document'      => array( 'type' => 'object', 'properties' => array( 'builder' => array( 'enum' => array( 'gutenberg', 'elementor', 'divi' ) ), 'native_kind' => array( 'type' => 'string' ), 'native_id' => array( 'type' => 'string' ) ), 'required' => array( 'builder', 'native_kind', 'native_id' ), 'additionalProperties' => false ),
							'snapshot_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
							'slot_path'     => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 32, 'items' => array( 'type' => 'string' ) ),
							'native_id'     => array( 'type' => array( 'string', 'null' ) ),
							'editor_epoch'  => array( 'type' => array( 'string', 'null' ) ),
						),
						'required'             => array( 'document', 'snapshot_hash', 'slot_path', 'native_id', 'editor_epoch' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'document', 'directory_id', 'scope', 'selection' ),
			'additionalProperties' => false,
		);
	}

	private function inspect_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'document_key'      => array( 'type' => 'string' ),
				'graph_hash'        => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'opaque_hash'       => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'graph'             => array( 'type' => 'object' ),
				'context_trace'     => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'fact_revision'     => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'semantic_report'   => array( 'type' => 'object' ),
				'directory_query'   => array( 'type' => 'object' ),
				'directory_binding' => array( 'type' => 'object' ),
				'saved_variants'    => array( 'type' => 'object' ),
				'projection'        => array( 'type' => 'object' ),
				'authoring'         => array( 'type' => array( 'object', 'null' ) ),
			),
			'required'             => array( 'document_key', 'graph_hash', 'opaque_hash', 'graph', 'context_trace', 'fact_revision', 'semantic_report', 'projection', 'authoring' ),
			'additionalProperties' => false,
		);
	}
}
