<?php
/**
 * Version-bound bounded model context for one authorized builder task.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Selection logic is kept together for auditability.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Model context is useful evidence, never write authority. */
final class BuilderTaskContextAssembler {
	private const MAX_SUMMARY_SOURCE_MESSAGES = 200;
	private Database $db;
	private GraphSnapshotStore $graphs;
	private RelationshipValidator $relationships;
	private BuilderPlanningPolicyRegistry $policies;
	private EffectiveCatalogContract $catalog_contract;
	private BuilderTaskContextContract $packet_contract;
	private JsonCanonicalizer $canonical;

	public function __construct( Database $db, GraphSnapshotStore $graphs, RelationshipValidator $relationships, BuilderPlanningPolicyRegistry $policies, ?EffectiveCatalogContract $catalog_contract = null, ?BuilderTaskContextContract $packet_contract = null ) {
		$this->db               = $db;
		$this->graphs           = $graphs;
		$this->relationships    = $relationships;
		$this->policies         = $policies;
		$this->catalog_contract = $catalog_contract ?? new EffectiveCatalogContract();
		$this->packet_contract  = $packet_contract ?? new BuilderTaskContextContract();
		$this->canonical        = new JsonCanonicalizer();
	}

	/**
	 * Assemble from current owned storage plus freshly re-fenced trusted providers.
	 *
	 * No native artifact body, node attributes, opaque reference, hidden slot path,
	 * catalog default or summary authority field is copied into the packet.
	 *
	 * @throws ContractException Current authority or required evidence is unavailable.
	 */
	public function assemble( Context $context, BuilderTaskContextRequest $request, GraphVersion $version, BuilderTaskContextCatalogProvider $catalogs, RelationshipFactsProvider $relationship_facts, BuilderTaskContextFactsProvider $context_facts ): object {
		$context->assert_execution_identity();
		if ( ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || null === $context->run_id() || null === $context->conversation_id() || ! $this->can_build( $context ) ) {
			throw new ContractException( 'unauthorized', 'Builder task context requires a bound builder workflow.' );
		}
		$owner = $context->actor()->owner();
		$run_id = $context->run_id();
		ConversationStore::identifier( $run_id );
		$runtime = $this->runtime( $context, $owner, $run_id );
		$catalog_before = $catalogs->read( $owner, $run_id );
		$this->catalog_authority( $catalog_before, $owner, $run_id );
		$catalog = $this->catalog_contract->validate( $catalog_before->catalog() );

		$report = $this->relationships->validate_current( $this->graphs, $owner, $request->document_key(), $version, $relationship_facts );
		$snapshot = $this->graphs->current( $owner, $request->document_key(), $version, 2000, 5000, false );
		if ( $snapshot->generation !== $report->snapshot_generation || ! hash_equals( $snapshot->graph_hash, $report->snapshot_hash ) ) {
			throw new ContractException( 'stale_version', 'Builder graph changed before task-context selection.' );
		}
		$context_facts->assert_document_access( $context, $request->document_key(), $snapshot->graph->document );
		BuilderConversationTarget::assert_binding( $runtime['context_json'], $request->document_key(), $snapshot->graph->document );

		$native_facts = $relationship_facts->read( $owner, $request->document_key(), $version );
		if ( $native_facts->owner_ref() !== $owner->reference() || ! hash_equals( $native_facts->revision(), $report->fact_revision ) ) {
			throw new ContractException( 'evidence_stale', 'Builder relationship facts changed before task-context selection.' );
		}
		$this->catalog_graph_binding( $catalog, $snapshot->graph, $version, $request, $native_facts );
		$design_before = $context_facts->read( $owner, $run_id, $request->document_key(), $version, $catalog->revision );
		$this->context_fact_authority( $design_before, $runtime, $owner, $run_id, $request->document_key(), $version, $catalog->revision );

		$selection = $this->select_graph( $snapshot->graph, $request, $report, $native_facts, $owner, $context, $context_facts );
		$cards = $this->component_cards( $catalog, $snapshot->graph, $request, $selection );
		if ( in_array( 'directory_field_revoked', $selection['reasons'], true ) ) {
			$selection['nodes'] = array();
			$selection['edges'] = array();
			$selection['required_ids'] = array();
			$selection['fields'] = array();
			$selection['dependencies'] = array();
			$selection['references'] = array();
			$selection['consumers'] = array();
			$cards['cards'] = array();
			$selection['omissions'][] = $this->omission( 'revoked_field_context_withheld', 'graph_region' );
			$selection['read_more'][] = $this->read_more( 'refresh_directory_schema', 'graph', 1 );
		}
		$task = $this->task( $runtime, $owner, $version, $context, $context_facts );
		$omissions = array_merge( $task['omissions'], $selection['omissions'], $cards['omissions'] );
		$read_more = array_merge( $selection['read_more'], $cards['read_more'] );
		$reasons = array_values( array_unique( array_merge( $selection['reasons'], $cards['reasons'] ), SORT_STRING ) );
		sort( $reasons, SORT_STRING );
		$state = $this->state( $selection['state'], $cards['state'] );
		foreach ( $task['omissions'] as $omission ) {
			if ( 'older_messages_omitted' === $omission->code ) {
				$state = $this->state( $state, 'needs_expansion' );
				$reasons[] = 'unsummarized_history_exceeds_bound';
				$read_more[] = $this->read_more( 'summarize_or_narrow_history', 'task.recent_messages', 1 );
			} elseif ( in_array( $omission->code, array( 'source_reference_unavailable', 'source_reference_invalid', 'memory_provenance_unverified' ), true ) ) {
				$state = $this->state( $state, 'needs_expansion' );
				$reasons[] = 'historical_source_access_incomplete';
				$read_more[] = $this->read_more( 'refresh_or_narrow_history', 'task', 1 );
			} elseif ( 'summary_source_bound_exceeded' === $omission->code ) {
				$state = $this->state( $state, 'needs_expansion' );
				$reasons[] = 'summary_source_bound_exceeded';
				$read_more[] = $this->read_more( 'narrow_summary_coverage', 'task.summary', 1 );
			} elseif ( in_array( $omission->code, array( 'invalid_artifact_reference', 'artifact_unavailable', 'artifact_stale_or_unbound' ), true ) ) {
				$state = $this->state( $state, 'needs_expansion' );
				$reasons[] = 'task_artifact_unavailable';
				$read_more[] = $this->read_more( 'refresh_task_artifact', 'task.artifact_refs', 1 );
			}
		}
		$reasons = array_values( array_unique( $reasons, SORT_STRING ) );
		sort( $reasons, SORT_STRING );

		$payload = array(
			'contract'                        => 'directorist-ai/builder-task-context-v1',
			'authority'                       => (object) array(
				'site_id'                    => get_current_blog_id(),
				'owner_binding'              => hash( 'sha256', $this->canonical->encode( (object) array( 'site_id' => get_current_blog_id(), 'owner_ref' => $owner->reference() ) ) ),
				'run_id'                     => $run_id,
				'conversation_id'            => $runtime['conversation_public_id'],
				'catalog_revision'           => $catalog->revision,
				'catalog_generation'         => $catalog_before->generation(),
				'catalog_binding_revision'   => $catalog_before->revision(),
				'graph_version_hash'         => $version->hash(),
				'graph_generation'           => $snapshot->generation,
				'graph_hash'                 => $snapshot->graph_hash,
				'rule_set_revision'          => $report->rule_set_revision,
				'relationship_fact_revision' => $report->fact_revision,
				'context_fact_revision'      => $design_before->revision(),
			),
			'task'                            => (object) array(
				'request'            => $task['request'],
				'content_role'       => 'untrusted_user_intent',
				'can_grant_tools'    => false,
				'can_grant_approval' => false,
				'scope'              => $request->scope(),
				'allowed_operations' => $request->allowed_operations(),
				'artifact_refs'      => $task['artifact_refs'],
				'summary'            => $task['summary'],
				'recent_messages'    => $task['recent_messages'],
			),
			'component_cards'                 => $cards['cards'],
			'graph_region'                    => (object) array(
				'document'  => $snapshot->graph->document,
				'scope'     => $request->scope(),
				'nodes'     => $selection['nodes'],
				'edges'     => $selection['edges'],
				'max_depth' => $selection['max_depth'],
			),
			'fresh_facts'                     => (object) array(
				'directory_fields' => $selection['fields'],
				'dependencies'     => $selection['dependencies'],
				'references'       => $selection['references'],
				'shared_consumers' => $selection['consumers'],
				'design_tokens'    => $design_before->design_tokens(),
			),
			'closure'                         => (object) array(
				'state'                => $state,
				'required_node_ids'    => $selection['required_ids'],
				'reasons'              => $reasons,
				'missing_dependencies' => $selection['missing'],
			),
			'omissions'                       => $this->sort_objects( $omissions, array( 'path', 'code' ) ),
			'read_more'                       => $this->sort_objects( $read_more, array( 'target', 'code' ) ),
			'requires_full_server_validation' => true,
		);

		$packet = $this->fit_budget( $payload, $request->limits() );
		$design_after = $context_facts->read( $owner, $run_id, $request->document_key(), $version, $catalog->revision );
		$this->context_fact_authority( $design_after, $runtime, $owner, $run_id, $request->document_key(), $version, $catalog->revision );
		if ( ! hash_equals( $design_before->revision(), $design_after->revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Builder task-context facts changed during assembly.' );
		}
		$catalog_after = $catalogs->read( $owner, $run_id );
		$this->catalog_authority( $catalog_after, $owner, $run_id );
		if ( ! hash_equals( $catalog_before->revision(), $catalog_after->revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Builder catalog changed during task-context assembly.' );
		}
		$after = $this->graphs->current( $owner, $request->document_key(), $version, 2000, 5000, false );
		if ( $after->generation !== $snapshot->generation || ! hash_equals( $after->graph_hash, $snapshot->graph_hash ) ) {
			throw new ContractException( 'stale_version', 'Builder graph changed during task-context assembly.' );
		}
		$context_facts->assert_document_access( $context, $request->document_key(), $after->graph->document );
		$native_after = $relationship_facts->read( $owner, $request->document_key(), $version );
		if ( $native_after->owner_ref() !== $owner->reference() || ! hash_equals( $native_facts->revision(), $native_after->revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Builder relationship facts changed during task-context assembly.' );
		}
		$consumers_after = $this->graphs->affected_consumers( $owner, $request->document_key(), 50, 500 );
		if ( ! hash_equals( $selection['consumer_revision'], $this->consumer_revision( $consumers_after ) ) ) {
			throw new ContractException( 'evidence_stale', 'Builder shared consumers changed during task-context assembly.' );
		}
		foreach ( $selection['authorized_consumer_keys'] as $consumer_key ) {
			$context_facts->assert_document_access( $context, $consumer_key, null );
		}
		foreach ( $selection['authorized_reference_keys'] as $reference_key ) {
			$context_facts->assert_document_access( $context, $reference_key, null );
		}
		$runtime_after = $this->runtime( $context, $owner, $run_id );
		foreach ( array( 'run_internal_id', 'conversation_internal_id', 'turn_internal_id', 'message_sequence', 'input_json', 'summary_json', 'context_json' ) as $key ) {
			if ( $runtime[ $key ] !== $runtime_after[ $key ] ) {
				throw new ContractException( 'evidence_stale', 'Builder task changed during context assembly.' );
			}
		}
		$this->recheck_task_sources( $runtime, $task['source_rows'], $task['memory_proofs'], $context, $context_facts );
		$this->recheck_task_artifacts( $runtime, $task['artifact_refs'], $owner, $version );
		$context->assert_execution_identity();
		if ( ! $this->can_build( $context ) ) {
			throw new ContractException( 'unauthorized', 'Builder capability changed during task-context assembly.' );
		}
		return $packet;
	}

	/** Hash visible messages covered by a summary; producers and tests use the same stable rule. */
	public static function summary_source_hash( array $messages ): string {
		$canonical = new JsonCanonicalizer();
		$rows = array();
		foreach ( $messages as $message ) {
			$rows[] = (object) array(
				'sequence'         => (int) $message['sequence'],
				'role'             => (string) $message['role'],
				'content_json'     => (string) $message['content_json'],
				'source_refs_json' => (string) $message['source_refs_json'],
			);
		}
		return hash( 'sha256', $canonical->encode( $rows ) );
	}

	private function runtime( Context $context, Owner $owner, string $run_id ): array {
		$rows = $this->db->rows(
			'SELECT r.id run_internal_id,r.public_id run_public_id,r.state run_state,r.cancel_requested,r.checkpoint_json,c.id conversation_internal_id,c.public_id conversation_public_id,c.profile,c.active_run_id,c.summary_json,c.context_json FROM ' . $this->db->table( 'runs' ) . ' r INNER JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.owner_ref=%s AND c.erasure_requested=0 AND c.active_run_id=r.id AND r.cancel_requested=0',
			array( $run_id, $owner->reference(), $owner->reference() )
		);
		if ( ! $rows || ! in_array( $rows[0]['run_state'], array( 'queued', 'running', 'waiting_input', 'waiting_approval', 'waiting_browser' ), true ) || $rows[0]['profile'] !== $context->profile() || $rows[0]['conversation_public_id'] !== $context->conversation_id() ) {
			throw new ContractException( 'not_found', 'Builder task run is unavailable.' );
		}
		try {
			$checkpoint = ( new JsonDecoder( 1048576, 32 ) )->decode( $rows[0]['checkpoint_json'] );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Builder task checkpoint is unavailable.' );
		}
		if ( ! $checkpoint instanceof \stdClass || ! isset( $checkpoint->latest_turn_id ) || ! is_int( $checkpoint->latest_turn_id ) || $checkpoint->latest_turn_id < 1 ) {
			throw new ContractException( 'evidence_stale', 'Builder task checkpoint has no current turn.' );
		}
		$turns = $this->db->rows( 'SELECT t.id turn_internal_id,t.sequence turn_sequence,t.input_json,m.sequence message_sequence FROM ' . $this->db->table( 'turns' ) . ' t INNER JOIN ' . $this->db->table( 'messages' ) . " m ON m.turn_id=t.id AND m.role='user' WHERE t.id=%d AND t.run_id=%d AND t.conversation_id=%d", array( $checkpoint->latest_turn_id, (int) $rows[0]['run_internal_id'], (int) $rows[0]['conversation_internal_id'] ) );
		if ( ! $turns ) {
			throw new ContractException( 'evidence_stale', 'Builder task current turn is unavailable.' );
		}
		return array_merge( $rows[0], $turns[0] );
	}

	private function catalog_authority( BuilderTaskContextCatalogSnapshot $snapshot, Owner $owner, string $run_id ): void {
		if ( get_current_blog_id() !== $snapshot->site_id() || $owner->reference() !== $snapshot->owner_ref() || $run_id !== $snapshot->run_id() ) {
			throw new ContractException( 'unauthorized', 'Builder catalog belongs to another task authority.' );
		}
	}

	private function context_fact_authority( BuilderTaskContextFacts $facts, array $runtime, Owner $owner, string $run_id, string $document_key, GraphVersion $version, string $catalog_revision ): void {
		if ( get_current_blog_id() !== $facts->site_id() || $owner->reference() !== $facts->owner_ref() || $run_id !== $facts->run_id() || $runtime['conversation_public_id'] !== $facts->conversation_id() || $document_key !== $facts->document_key() || ! hash_equals( $version->hash(), $facts->graph_version_hash() ) || ! hash_equals( $catalog_revision, $facts->catalog_revision() ) ) {
			throw new ContractException( 'unauthorized', 'Builder task-context facts belong to another authority.' );
		}
	}

	private function catalog_graph_binding( object $catalog, object $graph, GraphVersion $version, BuilderTaskContextRequest $request, RelationshipFacts $facts ): void {
		$scope = $request->scope();
		$directory = null === $catalog->directory_id ? null : (string) $catalog->directory_id;
		if ( $directory !== $scope->directory_id ) {
			throw new ContractException( 'needs_narrowing', 'Requested directory is outside the current builder catalog.' );
		}
		$member_revision = $facts->directory_revision_for( $scope->directory_id );
		if ( $catalog->builder !== $graph->document->builder || $catalog->document_kind !== $graph->document->native_kind || ! hash_equals( $catalog->environment_fingerprint, $version->environment_hash() ) || ! hash_equals( $facts->directory_revision(), $version->directory_schema_hash() ) || ( null !== $catalog->directory_revision && ( null === $member_revision || ! hash_equals( $catalog->directory_revision, $member_revision ) ) ) ) {
			throw new ContractException( 'stale_version', 'Builder catalog does not match the selected graph context.' );
		}
	}

	private function task( array $runtime, Owner $owner, GraphVersion $version, Context $context, BuilderTaskContextFactsProvider $context_facts ): array {
		$input = ( new JsonDecoder( 1048576, 32 ) )->decode( $runtime['input_json'] );
		if ( ! is_object( $input ) || ! isset( $input->text, $input->artifact_refs ) || ! is_string( $input->text ) || ! is_array( $input->artifact_refs ) || count( $input->artifact_refs ) > 20 ) {
			throw new ContractException( 'evidence_stale', 'Stored builder task input is invalid.' );
		}
		$omissions = array();
		$request_text = $this->inert_text( $input->text, 8192 );
		if ( $request_text !== $input->text ) {
			$omissions[] = $this->omission( 'unsafe_task_markup_removed', 'task.request' );
		}
		$artifact_refs = array();
		$store = new ArtifactStore( $this->db );
		foreach ( $input->artifact_refs as $index => $reference ) {
			$reference_keys = $reference instanceof \stdClass ? array_keys( get_object_vars( $reference ) ) : array();
			sort( $reference_keys, SORT_STRING );
			if ( array( 'hash', 'id', 'version' ) !== $reference_keys || ! is_string( $reference->id ) || ! is_int( $reference->version ) || $reference->version < 1 || ! is_string( $reference->hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $reference->hash ) ) {
				$omissions[] = $this->omission( 'invalid_artifact_reference', 'task.artifact_refs.' . $index );
				continue;
			}
			try {
				$row = $store->get( $owner, $reference->id, $reference->version );
			} catch ( StorageException $error ) {
				$omissions[] = $this->omission( 'artifact_unavailable', 'task.artifact_refs.' . $index );
				continue;
			}
			if ( 'native_snapshot' === $row['kind'] || (int) $row['conversation_id'] !== (int) $runtime['conversation_internal_id'] || ! hash_equals( $row['content_hash'], $reference->hash ) || ! hash_equals( $row['environment_hash'], $version->environment_hash() ) ) {
				$omissions[] = $this->omission( 'artifact_stale_or_unbound', 'task.artifact_refs.' . $index );
				continue;
			}
			$artifact_refs[] = (object) array( 'id' => $row['public_id'], 'version' => (int) $row['version'], 'hash' => $row['content_hash'], 'kind' => $row['kind'] );
		}
		$source_rows = array();
		$memory_proofs = array();
		$summary = $this->summary( $runtime, $context, $context_facts, $omissions, $source_rows, $memory_proofs );
		$through = null === $summary ? 0 : $summary->through_sequence;
		$recent = $this->recent_messages( $runtime, $through, $context, $context_facts, $omissions, $source_rows, $memory_proofs );
		return array( 'request' => $request_text, 'artifact_refs' => $artifact_refs, 'summary' => $summary, 'recent_messages' => $recent, 'omissions' => $omissions, 'source_rows' => $source_rows, 'memory_proofs' => $memory_proofs );
	}

	private function summary( array $runtime, Context $context, BuilderTaskContextFactsProvider $context_facts, array &$omissions, array &$source_rows, array &$memory_proofs ): ?object {
		if ( null === $runtime['summary_json'] ) {
			return null;
		}
		try {
			$summary = ( new JsonDecoder( 16384, 8 ) )->decode( $runtime['summary_json'] );
		} catch ( \Throwable $error ) {
			$omissions[] = $this->omission( 'summary_invalid', 'task.summary' );
			return null;
		}
		$keys = $summary instanceof \stdClass ? array_keys( get_object_vars( $summary ) ) : array();
		sort( $keys, SORT_STRING );
		if ( array( 'source_hash', 'text', 'through_sequence' ) !== $keys || ! is_string( $summary->text ) || strlen( $summary->text ) > 8192 || ! is_int( $summary->through_sequence ) || $summary->through_sequence < 1 || $summary->through_sequence >= (int) $runtime['message_sequence'] || ! is_string( $summary->source_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $summary->source_hash ) ) {
			$omissions[] = $this->omission( 'summary_unversioned_or_stale', 'task.summary' );
			return null;
		}
		if ( $summary->through_sequence > self::MAX_SUMMARY_SOURCE_MESSAGES ) {
			$omissions[] = $this->omission( 'summary_source_bound_exceeded', 'task.summary' );
			return null;
		}
		$messages = $this->db->rows( 'SELECT sequence,role,content_json,source_refs_json FROM ' . $this->db->table( 'messages' ) . ' WHERE conversation_id=%d AND sequence<=%d ORDER BY sequence', array( (int) $runtime['conversation_internal_id'], $summary->through_sequence ) );
		if ( ! $messages || ! hash_equals( $summary->source_hash, self::summary_source_hash( $messages ) ) ) {
			$omissions[] = $this->omission( 'summary_unversioned_or_stale', 'task.summary' );
			return null;
		}
		foreach ( $messages as $message ) {
			if ( ! $this->source_access_current( $message, $context, $context_facts, $omissions, 'task.summary' ) ) {
				return null;
			}
		}
		if ( ! $this->memory_provenance_current( $context, $context_facts, 'summary', $summary->text, $messages, $omissions, 'task.summary' ) ) {
			return null;
		}
		foreach ( $messages as $message ) {
			$source_rows[ (int) $message['sequence'] ] = $message;
		}
		$memory_proofs[] = array( 'kind' => 'summary', 'text' => $summary->text, 'sequences' => array_map( static fn( array $message ): int => (int) $message['sequence'], $messages ) );
		$text = $this->inert_text( $summary->text, 4096 );
		if ( $text !== $summary->text ) {
			$omissions[] = $this->omission( 'unsafe_summary_content_removed', 'task.summary.text' );
		}
		return (object) array(
			'text'               => $text,
			'through_sequence'   => $summary->through_sequence,
			'source_hash'        => $summary->source_hash,
			'content_role'       => 'untrusted_memory',
			'can_grant_tools'    => false,
			'can_grant_approval' => false,
		);
	}

	private function recent_messages( array $runtime, int $after, Context $context, BuilderTaskContextFactsProvider $context_facts, array &$omissions, array &$source_rows, array &$memory_proofs ): array {
		$table = $this->db->table( 'messages' );
		$count = (int) $this->db->scalar( "SELECT COUNT(*) FROM $table WHERE conversation_id=%d AND sequence>%d AND sequence<=%d AND role IN ('user','assistant')", array( (int) $runtime['conversation_internal_id'], $after, (int) $runtime['message_sequence'] ) );
		$rows = $this->db->rows( "SELECT sequence,role,content_json,source_refs_json FROM $table WHERE conversation_id=%d AND sequence>%d AND sequence<=%d AND role IN ('user','assistant') ORDER BY sequence DESC LIMIT 12", array( (int) $runtime['conversation_internal_id'], $after, (int) $runtime['message_sequence'] ) );
		$rows = array_reverse( $rows );
		if ( $count > count( $rows ) ) {
			$omissions[] = $this->omission( 'older_messages_omitted', 'task.recent_messages' );
		}
		$recent = array();
		foreach ( $rows as $row ) {
			if ( ! $this->source_access_current( $row, $context, $context_facts, $omissions, 'task.recent_messages.' . (int) $row['sequence'] ) ) {
				continue;
			}
			try {
				$content = ( new JsonDecoder( 65536, 16 ) )->decode( $row['content_json'] );
			} catch ( \Throwable $error ) {
				$omissions[] = $this->omission( 'message_invalid', 'task.recent_messages.' . (int) $row['sequence'] );
				continue;
			}
			$text = $content instanceof \stdClass && isset( $content->text ) && is_string( $content->text ) ? $content->text : '';
			if ( '' === $text ) {
				$omissions[] = $this->omission( 'message_content_omitted', 'task.recent_messages.' . (int) $row['sequence'] );
				continue;
			}
			if ( 'assistant' === $row['role'] && ! $this->memory_provenance_current( $context, $context_facts, 'assistant', $text, array( $row ), $omissions, 'task.recent_messages.' . (int) $row['sequence'] ) ) {
				continue;
			}
			$safe = $this->inert_text( $text, 4096 );
			if ( $safe !== $text ) {
				$omissions[] = $this->omission( 'unsafe_message_content_removed', 'task.recent_messages.' . (int) $row['sequence'] );
			}
			$recent[] = (object) array(
				'sequence'           => (int) $row['sequence'],
				'role'               => $row['role'],
				'text'               => $safe,
				'content_role'       => 'untrusted_conversation',
				'can_grant_tools'    => false,
				'can_grant_approval' => false,
			);
			$source_rows[ (int) $row['sequence'] ] = $row;
			if ( 'assistant' === $row['role'] ) {
				$memory_proofs[] = array( 'kind' => 'assistant', 'text' => $text, 'sequences' => array( (int) $row['sequence'] ) );
			}
		}
		return $recent;
	}

	/** Stored source IDs remain private and are never copied into a packet. */
	private function source_access_current( array $row, Context $context, BuilderTaskContextFactsProvider $context_facts, array &$omissions, string $path ): bool {
		$references = $this->source_references( (string) $row['source_refs_json'] );
		if ( null === $references ) {
			$omissions[] = $this->omission( 'source_reference_invalid', $path );
			return false;
		}
		foreach ( $references as $reference ) {
			try {
				$context->assert_execution_identity();
				$context_facts->assert_source_access( $context, $reference );
			} catch ( \Throwable $error ) {
				$omissions[] = $this->omission( 'source_reference_unavailable', $path );
				return false;
			}
		}
		return true;
	}

	private function recheck_task_artifacts( array $runtime, array $references, Owner $owner, GraphVersion $version ): void {
		$store = new ArtifactStore( $this->db );
		foreach ( $references as $reference ) {
			try {
				$row = $store->get( $owner, $reference->id, $reference->version );
			} catch ( StorageException $error ) {
				throw new ContractException( 'evidence_stale', 'Builder task artifact changed during context assembly.' );
			}
			if ( (int) $row['conversation_id'] !== (int) $runtime['conversation_internal_id'] || ! hash_equals( $row['content_hash'], $reference->hash ) || ! hash_equals( $row['environment_hash'], $version->environment_hash() ) ) {
				throw new ContractException( 'evidence_stale', 'Builder task artifact changed during context assembly.' );
			}
		}
	}

	private function source_references( string $json ): ?array {
		try {
			$references = ( new JsonDecoder( 65536, 16 ) )->decode( $json );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( ! is_array( $references ) || count( $references ) > 20 ) {
			return null;
		}
		foreach ( $references as $reference ) {
			if ( ! $reference instanceof \stdClass || ! isset( $reference->kind ) || ! is_string( $reference->kind ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,47}$/D', $reference->kind ) ) {
				return null;
			}
		}
		return $references;
	}

	private function memory_provenance_current( Context $context, BuilderTaskContextFactsProvider $context_facts, string $kind, string $text, array $messages, array &$omissions, string $path ): bool {
		try {
			$context->assert_execution_identity();
			$context_facts->assert_memory_provenance( $context, $kind, $text, $messages );
		} catch ( \Throwable $error ) {
			$omissions[] = $this->omission( 'memory_provenance_unverified', $path );
			return false;
		}
		return true;
	}

	private function recheck_task_sources( array $runtime, array $source_rows, array $memory_proofs, Context $context, BuilderTaskContextFactsProvider $context_facts ): void {
		$table = $this->db->table( 'messages' );
		$fresh_rows = array();
		foreach ( $source_rows as $sequence => $before ) {
			$rows = $this->db->rows( "SELECT sequence,role,content_json,source_refs_json FROM $table WHERE conversation_id=%d AND sequence=%d", array( (int) $runtime['conversation_internal_id'], (int) $sequence ) );
			if ( 1 !== count( $rows ) || $before['role'] !== $rows[0]['role'] || $before['content_json'] !== $rows[0]['content_json'] || $before['source_refs_json'] !== $rows[0]['source_refs_json'] ) {
				throw new ContractException( 'evidence_stale', 'Builder conversation sources changed during task-context assembly.' );
			}
			$omissions = array();
			if ( ! $this->source_access_current( $rows[0], $context, $context_facts, $omissions, 'task' ) ) {
				throw new ContractException( 'evidence_stale', 'Builder conversation source access changed during task-context assembly.' );
			}
			$fresh_rows[ (int) $sequence ] = $rows[0];
		}
		foreach ( $memory_proofs as $proof ) {
			$covered = array();
			foreach ( $proof['sequences'] as $sequence ) {
				$covered[] = $fresh_rows[ $sequence ];
			}
			$omissions = array();
			if ( ! $this->memory_provenance_current( $context, $context_facts, $proof['kind'], $proof['text'], $covered, $omissions, 'task' ) ) {
				throw new ContractException( 'evidence_stale', 'Builder conversation provenance changed during task-context assembly.' );
			}
		}
	}

	private function select_graph( object $graph, BuilderTaskContextRequest $request, object $report, RelationshipFacts $facts, Owner $owner, Context $context, BuilderTaskContextFactsProvider $context_facts ): array {
		$nodes = array();
		foreach ( $graph->nodes as $node ) {
			$nodes[ $node->id ] = $node;
		}
		$parents = array();
		$outgoing = array();
		$incoming = array();
		foreach ( $graph->edges as $edge ) {
			$outgoing[ $edge->source ][] = $edge;
			if ( null === $edge->target_document_key ) {
				$incoming[ $edge->target ][] = $edge;
			}
			if ( 'contains' === $edge->kind && null === $edge->target_document_key ) {
				$parents[ $edge->target ] = $edge->source;
			}
		}
		$contexts = array();
		foreach ( $report->resolved_contexts as $resolved_context ) {
			$contexts[ $resolved_context->consumer_node_id ][] = $resolved_context->provider_node_id;
		}
		$required = array();
		$queue = array();
		$omissions = array();
		$read_more = array();
		$reasons = array();
		$missing = array();
		$state = 'complete_for_task';
		foreach ( $request->selection() as $node_id ) {
			if ( ! isset( $nodes[ $node_id ] ) ) {
				$state = 'needs_narrowing';
				$reasons[] = 'selected_node_unavailable';
				$missing[] = 'node:' . $node_id;
				$read_more[] = $this->read_more( 'select_known_node', 'node:' . $node_id, 1 );
				continue;
			}
			if ( $this->canonical->encode( $nodes[ $node_id ]->scope ) !== $this->canonical->encode( $request->scope() ) ) {
				$state = 'needs_narrowing';
				$reasons[] = 'selected_scope_mismatch';
				$missing[] = 'node:' . $node_id;
				$read_more[] = $this->read_more( 'select_node_in_requested_scope', 'node:' . $node_id, 1 );
				continue;
			}
			$required[ $node_id ] = array( 'depth' => 0, 'reasons' => array( 'selected' ) );
			$queue[] = $node_id;
		}
		$overflow = false;
		for ( $cursor = 0; $cursor < count( $queue ); ++$cursor ) {
			$id = $queue[ $cursor ];
			$depth = $required[ $id ]['depth'];
			$node = $nodes[ $id ];
			if ( null !== $node->opaque_ref || null === $this->relationships->component_rule( $node->provider_id, $node->native_id ) ) {
				$state = 'needs_narrowing';
				$reasons[] = null !== $node->opaque_ref ? 'opaque_node_selected' : 'unknown_component_selected';
				$omissions[] = $this->omission( null !== $node->opaque_ref ? 'opaque_node_payload_private' : 'unknown_node_payload_private', 'graph_region.nodes.' . $id );
			}
			$add = array();
			if ( isset( $parents[ $id ] ) ) {
				$add[ $parents[ $id ] ][] = 'ancestor';
			}
			foreach ( $contexts[ $id ] ?? array() as $provider ) {
				$add[ $provider ][] = 'context_provider';
			}
			foreach ( $outgoing[ $id ] ?? array() as $edge ) {
				if ( 'contains' !== $edge->kind && null === $edge->target_document_key ) {
					$add[ $edge->target ][] = $edge->kind;
				}
			}
			foreach ( $incoming[ $id ] ?? array() as $edge ) {
				if ( 'context' === $edge->kind ) {
					$add[ $edge->source ][] = 'context_provider';
				}
			}
			foreach ( $add as $next => $why ) {
				$next_depth = $depth + 1;
				if ( $next_depth > $request->limits()->max_depth ) {
					$overflow = true;
					$missing[] = 'node:' . $next;
					continue;
				}
				if ( ! isset( $required[ $next ] ) ) {
					$required[ $next ] = array( 'depth' => $next_depth, 'reasons' => $why );
					$queue[] = $next;
				} else {
					$required[ $next ]['reasons'] = array_values( array_unique( array_merge( $required[ $next ]['reasons'], $why ), SORT_STRING ) );
				}
			}
			if ( count( $required ) > $request->limits()->max_nodes ) {
				$overflow = true;
				break;
			}
		}
		if ( $overflow ) {
			$state = 'needs_expansion';
			$reasons[] = 'required_graph_closure_exceeds_bound';
			$read_more[] = $this->read_more( 'expand_graph_closure', 'graph', max( count( $required ), $request->limits()->max_nodes + 1 ) );
		}
		if ( count( $required ) > $request->limits()->max_nodes ) {
			$omitted_ids = array_slice( array_keys( $required ), $request->limits()->max_nodes );
			foreach ( $omitted_ids as $omitted_id ) {
				$missing[] = 'node:' . $omitted_id;
				unset( $required[ $omitted_id ] );
			}
		}
		foreach ( $report->diagnostics as $diagnostic ) {
			if ( ! $diagnostic->blocking ) {
				continue;
			}
			$state = $diagnostic->retry ? $this->state( $state, 'needs_expansion' ) : 'needs_narrowing';
			$reasons[] = $diagnostic->code;
			$missing[] = $diagnostic->path;
			$read_more[] = $this->read_more( $diagnostic->retry ? 'refresh_required_fact' : 'narrow_invalid_region', $diagnostic->path, 1 );
		}

		$selected_nodes = array();
		$max_depth = 0;
		ksort( $required, SORT_STRING );
		foreach ( $required as $id => $trace ) {
			$node = $nodes[ $id ];
			$max_depth = max( $max_depth, $trace['depth'] );
			$selected_nodes[] = (object) array(
				'id'              => $id,
				'provider_id'     => $node->provider_id,
				'native_id'       => $node->native_id,
				'scope'           => $node->scope,
				'opaque'          => null !== $node->opaque_ref,
				'locator_hash'    => hash( 'sha256', $this->canonical->encode( $node->locator ) ),
				'closure_reasons' => array_values( array_unique( $trace['reasons'], SORT_STRING ) ),
			);
		}
		$selected_edges = array();
		$fields = array();
		$dependencies = array();
		$references = array();
		$authorized_reference_keys = array();
		foreach ( $graph->edges as $edge ) {
			$source_selected = isset( $required[ $edge->source ] );
			$target_selected = null === $edge->target_document_key && isset( $required[ $edge->target ] );
			if ( ! $source_selected || ( null === $edge->target_document_key && ! $target_selected ) ) {
				continue;
			}
			$external = null;
			if ( null !== $edge->target_document_key ) {
				$reference = $facts->reference( $edge->target_document_key );
				if ( null === $reference || 'current' !== $reference->status ) {
					$state = 'needs_expansion';
					$reasons[] = 'reference_unavailable';
					$missing[] = 'reference:' . hash( 'sha256', $edge->target_document_key );
					continue;
				}
				try {
					$context_facts->assert_document_access( $context, $edge->target_document_key, null );
				} catch ( ContractException $error ) {
					$state = 'needs_expansion';
					$reasons[] = 'reference_access_incomplete';
					$missing[] = 'reference:' . hash( 'sha256', $edge->target_document_key );
					$read_more[] = $this->read_more( 'authorize_or_narrow_reference', 'reference', 1 );
					continue;
				}
				$authorized_reference_keys[ $edge->target_document_key ] = true;
				$external = hash( 'sha256', $this->canonical->encode( (object) array( 'site_id' => get_current_blog_id(), 'document_key' => $edge->target_document_key ) ) );
				$references[ $external ] = (object) array( 'id' => $external, 'version_hash' => $reference->version_hash, 'status' => 'current' );
			}
			$selected_edges[] = (object) array(
				'kind'                => $edge->kind,
				'source'              => $edge->source,
				'target'              => null === $external ? $edge->target : null,
				'target_document_ref' => $external,
				'scope'               => $edge->scope,
				'order'               => $edge->order,
				'rule_id'             => $edge->provenance->rule_id,
				'rule_revision'       => $edge->provenance->resolver_revision,
			);
			if ( 'field' === $edge->kind && $target_selected ) {
				$field = $facts->field( $nodes[ $edge->target ]->native_id, $nodes[ $edge->target ]->scope->directory_id );
				if ( null !== $field && $field->eligible ) {
					$fields[ $field->id ] = (object) array( 'id' => $field->id, 'type' => $field->type, 'eligible' => true, 'fact_revision' => $facts->revision() );
				} else {
					$state = 'needs_expansion';
					$reasons[] = 'directory_field_revoked';
					$missing[] = 'field:' . hash( 'sha256', $nodes[ $edge->target ]->native_id );
				}
			}
			if ( 'dependency' === $edge->kind && $target_selected ) {
				$dependency = $facts->dependency( $nodes[ $edge->target ]->native_id );
				if ( null !== $dependency && 'available' === $dependency->status ) {
					$dependencies[ $dependency->id ] = (object) array( 'id' => $dependency->id, 'status' => 'available', 'version' => $dependency->version, 'fact_revision' => $facts->revision() );
				} else {
					$state = 'needs_expansion';
					$reasons[] = 'dependency_unavailable';
					$missing[] = 'dependency:' . $nodes[ $edge->target ]->native_id;
				}
			}
		}
		if ( count( $selected_edges ) > $request->limits()->max_edges ) {
			$state = 'needs_expansion';
			$reasons[] = 'required_edge_closure_exceeds_bound';
			$read_more[] = $this->read_more( 'expand_edge_closure', 'graph.edges', count( $selected_edges ) );
			$selected_edges = array();
		}
		$consumer_result = $this->graphs->affected_consumers( $owner, $request->document_key(), 50, 500 );
		$consumers = array();
		$authorized_consumer_keys = array();
		$consumer_access_incomplete = false;
		foreach ( $consumer_result->consumers as $consumer ) {
			try {
				$context_facts->assert_document_access( $context, $consumer, null );
			} catch ( ContractException $error ) {
				$consumer_access_incomplete = true;
				continue;
			}
			$authorized_consumer_keys[] = $consumer;
			$consumers[] = hash( 'sha256', $this->canonical->encode( (object) array( 'site_id' => get_current_blog_id(), 'document_key' => $consumer ) ) );
		}
		sort( $consumers, SORT_STRING );
		if ( ! $consumer_result->complete || $consumer_access_incomplete ) {
			$state = 'needs_expansion';
			$reasons[] = $consumer_access_incomplete ? 'shared_consumer_access_incomplete' : 'shared_consumer_closure_incomplete';
			$missing[] = 'shared_consumers';
			$read_more[] = $this->read_more( 'authorize_or_narrow_shared_consumers', 'shared_consumers', count( $consumers ) + 1 );
		}
		$required_ids = array_keys( $required );
		sort( $required_ids, SORT_STRING );
		$missing = array_values( array_unique( $missing, SORT_STRING ) );
		sort( $missing, SORT_STRING );
		$reasons = array_values( array_unique( $reasons, SORT_STRING ) );
		sort( $reasons, SORT_STRING );
		if ( 'complete_for_task' === $state && ( $reasons || $missing ) ) {
			$state = 'needs_expansion';
		}
		return array(
			'state'                     => $state,
			'nodes'                     => $selected_nodes,
			'edges'                     => $selected_edges,
			'max_depth'                 => $max_depth,
			'required_ids'              => $required_ids,
			'fields'                    => array_values( $fields ),
			'dependencies'              => array_values( $dependencies ),
			'references'                => array_values( $references ),
			'consumers'                 => $consumers,
			'authorized_consumer_keys'  => $authorized_consumer_keys,
			'authorized_reference_keys' => array_keys( $authorized_reference_keys ),
			'consumer_revision'         => $this->consumer_revision( $consumer_result ),
			'omissions'                 => $omissions,
			'read_more'                 => $read_more,
			'reasons'                   => $reasons,
			'missing'                   => $missing,
		);
	}

	private function consumer_revision( object $result ): string {
		$consumers = $result->consumers;
		sort( $consumers, SORT_STRING );
		return hash( 'sha256', $this->canonical->encode( (object) array( 'consumers' => $consumers, 'complete' => $result->complete ) ) );
	}

	private function component_cards( object $catalog, object $graph, BuilderTaskContextRequest $request, array $selection ): array {
		$by_node = array();
		foreach ( $graph->nodes as $node ) {
			$by_node[ $node->id ] = $node;
		}
		$wanted = array();
		foreach ( $selection['required_ids'] as $id ) {
			if ( isset( $by_node[ $id ] ) ) {
				$node = $by_node[ $id ];
				$wanted[ $node->provider_id . '|' . $node->native_id ] = (object) array( 'provider_id' => $node->provider_id, 'native_id' => $node->native_id );
			}
		}
		foreach ( $request->candidate_components() as $component ) {
			$wanted[ $component->provider_id . '|' . $component->native_id ] = $component;
		}
		ksort( $wanted, SORT_STRING );
		$available = array();
		foreach ( $catalog->components as $component ) {
			$available[ $component->source_provider_id . '|' . $component->native_id ] = $component;
		}
		$cards = array();
		$omissions = array();
		$read_more = array();
		$reasons = array();
		$state = 'complete_for_task';
		if ( count( $wanted ) > 30 ) {
			$omitted = array_slice( array_keys( $wanted ), 30 );
			$wanted = array_slice( $wanted, 0, 30, true );
			$state = 'needs_narrowing';
			$reasons[] = 'component_card_bound_exceeded';
			foreach ( $omitted as $identity_key ) {
				$omissions[] = $this->omission( 'component_card_omitted_for_bound', 'component_cards.' . hash( 'sha256', $identity_key ) );
			}
			$read_more[] = $this->read_more( 'narrow_component_selection', 'component_cards', count( $omitted ) );
		}
		foreach ( $wanted as $key => $identity ) {
			$component = $available[ $key ] ?? null;
			$rule = $this->relationships->component_rule( $identity->provider_id, $identity->native_id );
			if ( null === $component || null === $rule || in_array( $component->availability, array( 'unavailable', 'unsupported', 'conflict' ), true ) ) {
				$state = 'needs_narrowing';
				$reasons[] = 'component_card_unavailable';
				$omissions[] = $this->omission( 'component_card_unavailable', 'component_cards.' . hash( 'sha256', $key ) );
				$read_more[] = $this->read_more( 'select_supported_component', 'component:' . hash( 'sha256', $key ), 1 );
				continue;
			}
			$features = array();
			foreach ( $component->features as $feature ) {
				if ( in_array( $feature->operation, $request->allowed_operations(), true ) ) {
					$features[] = $feature->operation;
				}
			}
			$features = array_values( array_unique( $features, SORT_STRING ) );
			sort( $features, SORT_STRING );
			$policy = $this->policies->component( $identity->provider_id, $identity->native_id );
			if ( null === $policy || 'available' !== $component->availability ) {
				$withheld = array_values( array_diff( $features, array( 'inspect' ) ) );
				if ( $withheld ) {
					$state = 'needs_narrowing';
					$reasons[] = 'component_write_policy_unavailable';
					$omissions[] = $this->omission( 'component_write_operations_withheld', 'component_cards.' . hash( 'sha256', $key ) . '.allowed_operations' );
					$read_more[] = $this->read_more( 'select_inspect_only_component', 'component:' . hash( 'sha256', $key ), 1 );
					$features = array_values( array_intersect( $features, array( 'inspect' ) ) );
				}
			}
			$controls = array();
			if ( null !== $policy ) {
				foreach ( $policy->controls as $control ) {
					if ( $control->private || ! in_array( $control->path, $request->control_paths(), true ) ) {
						continue;
					}
					$controls[] = (object) array( 'path' => $control->path, 'value_shape' => $this->schema_shape( $control->value_schema ), 'field_types' => $control->field_types );
				}
			}
			$relationship_tuples = array();
			foreach ( $wanted as $target ) {
				foreach ( array( 'contains', 'context', 'field', 'dependency', 'template', 'style' ) as $kind ) {
					foreach ( $this->relationships->relationship_rules( $kind, $identity->provider_id, $identity->native_id, $target->provider_id, $target->native_id ) as $relationship ) {
						$relationship_tuples[] = (object) array(
							'kind'               => $relationship->kind,
							'target_provider_id' => $target->provider_id,
							'target_native_id'   => $target->native_id,
							'rule_id'            => $relationship->id,
							'rule_revision'      => $relationship->revision,
							'context_id'         => $relationship->context_id,
							'field_types'        => $relationship->field_types,
							'dependency_ids'     => $relationship->dependency_ids,
							'reference_mode'     => $relationship->reference_mode,
							'scope_policy'       => $relationship->scope_policy,
							'resolver_id'        => $relationship->resolver_id,
						);
					}
				}
			}
			$insert_shape = null;
			if ( null !== $policy && in_array( 'insert_component', $features, true ) ) {
				$required_private = false;
				$insert_shape = $this->safe_insert_shape( $policy, $required_private );
				if ( $required_private ) {
					$state = 'needs_narrowing';
					$reasons[] = 'private_insert_requirement';
					$omissions[] = $this->omission( 'private_insert_shape_withheld', 'component_cards.' . hash( 'sha256', $key ) . '.insert_shape' );
					$read_more[] = $this->read_more( 'choose_supported_component', 'component:' . hash( 'sha256', $key ), 1 );
					$insert_shape = null;
				}
			}
			$cards[] = (object) array(
				'provider_id'            => $component->source_provider_id,
				'native_id'              => $component->native_id,
				'adapter_id'             => $component->adapter_id,
				'adapter_revision'       => $component->adapter_revision,
				'native_version'         => $component->native_version,
				'availability'           => $component->availability,
				'allowed_operations'     => $features,
				'slots'                  => $component->slots,
				'declared_context'       => $component->declared_context,
				'semantic_rule_refs'     => $component->semantic_rule_refs,
				'resolver_refs'          => $component->resolver_refs,
				'required_contexts'      => $rule->requires_contexts,
				'dependencies'           => array_values( array_map( static fn( object $dependency ): object => (object) array( 'type' => $dependency->type, 'id' => $dependency->id, 'status' => $dependency->status, 'version' => $dependency->version ), array_filter( $component->dependencies, static fn( object $dependency ): bool => 'available' === $dependency->status ) ) ),
				'insert_shape'           => $insert_shape,
				'selected_controls'      => $controls,
				'outgoing_relationships' => $relationship_tuples,
				'policy_revision'        => null === $policy ? null : $policy->revision,
			);
		}
		return array( 'cards' => $cards, 'omissions' => $omissions, 'read_more' => $read_more, 'reasons' => $reasons, 'state' => $state );
	}

	/** Remove private control branches before exposing insert structure. */
	private function safe_insert_shape( object $policy, bool &$required_private ): object {
		$shape = $this->schema_shape( $policy->insert_attributes_schema );
		foreach ( $policy->controls as $control ) {
			if ( ! $control->private ) {
				continue;
			}
			$parts = explode( '.', $control->path );
			$parent = $shape;
			$last = array_pop( $parts );
			foreach ( $parts as $part ) {
				if ( ! isset( $parent->properties ) || ! isset( $parent->properties->{$part} ) ) {
					continue 2;
				}
				$parent = $parent->properties->{$part};
			}
			if ( isset( $parent->required ) && in_array( $last, $parent->required, true ) ) {
				$required_private = true;
				$parent->required = array_values( array_diff( $parent->required, array( $last ) ) );
			}
			if ( isset( $parent->properties ) ) {
				unset( $parent->properties->{$last} );
			}
		}
		return $shape;
	}

	/** Reduce a schema to structural constraints and safe scalar enums only. */
	private function schema_shape( object $schema, int $depth = 0 ): object {
		if ( $depth > 6 ) {
			return (object) array( 'truncated' => true );
		}
		$result = array();
		if ( isset( $schema->type ) && ( is_string( $schema->type ) || is_array( $schema->type ) ) ) {
			$result['type'] = $schema->type;
		}
		foreach ( array( 'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems' ) as $key ) {
			if ( property_exists( $schema, $key ) && ( is_int( $schema->{$key} ) || is_float( $schema->{$key} ) ) ) {
				$result[ $key ] = $schema->{$key};
			}
		}
		if ( isset( $schema->enum ) && is_array( $schema->enum ) && count( $schema->enum ) <= 50 ) {
			$enum = array_values( array_filter( $schema->enum, fn( $value ): bool => is_bool( $value ) || null === $value || is_int( $value ) || is_float( $value ) || ( is_string( $value ) && strlen( $value ) <= 64 && preg_match( '#^[A-Za-z0-9_.:/ -]+$#D', $value ) ) ) );
			if ( count( $enum ) === count( $schema->enum ) ) {
				$result['enum'] = $enum;
			}
		}
		if ( isset( $schema->required ) && is_array( $schema->required ) ) {
			$result['required'] = array_values( array_filter( $schema->required, static fn( $value ): bool => is_string( $value ) && preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $value ) ) );
		}
		if ( isset( $schema->properties ) && $schema->properties instanceof \stdClass ) {
			$properties = array();
			foreach ( get_object_vars( $schema->properties ) as $name => $child ) {
				if ( preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $name ) && $child instanceof \stdClass ) {
					$properties[ $name ] = $this->schema_shape( $child, $depth + 1 );
				}
			}
			ksort( $properties, SORT_STRING );
			$result['properties'] = (object) $properties;
		}
		$additional_properties = $schema->{'additionalProperties'} ?? null;
		if ( is_bool( $additional_properties ) ) {
			$result['additionalProperties'] = $additional_properties;
		}
		return (object) $result;
	}

	private function fit_budget( array $payload, object $limits ): object {
		$packet = $this->packet_contract->finalize( $payload );
		if ( $this->within_budget( $packet, $limits ) ) {
			return $packet;
		}
		if ( null !== $payload['task']->summary ) {
			$payload['task']->summary = null;
			$payload['omissions'][] = $this->omission( 'summary_omitted_for_budget', 'task.summary' );
			$this->budget_context_omission( $payload, 'summary_omitted_for_budget', 'task.summary' );
			$packet = $this->packet_contract->finalize( $payload );
		}
		if ( ! $this->within_budget( $packet, $limits ) && $payload['fresh_facts']->design_tokens ) {
			$payload['fresh_facts']->design_tokens = array();
			$payload['omissions'][] = $this->omission( 'design_tokens_omitted_for_budget', 'fresh_facts.design_tokens' );
			$packet = $this->packet_contract->finalize( $payload );
		}
		if ( ! $this->within_budget( $packet, $limits ) && $payload['task']->recent_messages ) {
			$payload['task']->recent_messages = array();
			$payload['omissions'][] = $this->omission( 'recent_messages_omitted_for_budget', 'task.recent_messages' );
			$this->budget_context_omission( $payload, 'recent_messages_omitted_for_budget', 'task.recent_messages' );
			$packet = $this->packet_contract->finalize( $payload );
		}
		if ( ! $this->within_budget( $packet, $limits ) ) {
			$payload['graph_region']->nodes = array();
			$payload['graph_region']->edges = array();
			$payload['component_cards'] = array();
			$payload['fresh_facts'] = (object) array( 'directory_fields' => array(), 'dependencies' => array(), 'references' => array(), 'shared_consumers' => array(), 'design_tokens' => array() );
			$payload['closure']->state = 'needs_narrowing';
			$payload['closure']->reasons = array_values( array_unique( array_merge( $payload['closure']->reasons, array( 'packet_budget_exceeded' ) ), SORT_STRING ) );
			$payload['closure']->missing_dependencies = array_values( array_unique( array_merge( $payload['closure']->missing_dependencies, $payload['closure']->required_node_ids ), SORT_STRING ) );
			$payload['omissions'][] = $this->omission( 'required_context_omitted_for_budget', 'graph_region' );
			$payload['read_more'][] = $this->read_more( 'narrow_task_selection', 'graph', count( $payload['closure']->required_node_ids ) );
			$packet = $this->packet_contract->finalize( $payload );
		}
		if ( ! $this->within_budget( $packet, $limits ) ) {
			throw new ContractException( 'needs_expansion', 'Builder task metadata alone exceeds the context budget.' );
		}
		return $packet;
	}

	private function budget_context_omission( array &$payload, string $reason, string $target ): void {
		$payload['closure']->state = 'needs_narrowing';
		$payload['closure']->reasons = array_values( array_unique( array_merge( $payload['closure']->reasons, array( $reason ) ), SORT_STRING ) );
		$payload['read_more'][] = $this->read_more( 'narrow_context_budget', $target, 1 );
	}

	private function within_budget( object $packet, object $limits ): bool {
		return $packet->metrics->packet_bytes <= $limits->max_bytes && $packet->metrics->estimated_tokens <= $limits->max_tokens;
	}

	private function state( string $left, string $right ): string {
		$order = array( 'complete_for_task' => 0, 'needs_expansion' => 1, 'needs_narrowing' => 2 );
		return $order[ $left ] >= $order[ $right ] ? $left : $right;
	}

	private function can_build( Context $context ): bool {
		return ! $context->actor()->is_guest() && ( $context->actor()->can( 'edit_pages' ) || $context->actor()->can( 'edit_posts' ) || $context->actor()->can( 'edit_theme_options' ) );
	}

	private function inert_text( string $text, int $limit ): string {
		$text = wp_strip_all_tags( $text, true );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text ) ?? '';
		$text = preg_replace( '/\b(api[_-]?key|access[_-]?token|client[_-]?secret|password)\b\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $text ) ?? '';
		return mb_substr( $text, 0, $limit, 'UTF-8' );
	}

	private function omission( string $code, string $path ): object {
		return (object) array( 'code' => $code, 'path' => $path );
	}

	private function read_more( string $code, string $target, int $required_minimum ): object {
		return (object) array( 'code' => $code, 'target' => $target, 'required_minimum' => max( 1, $required_minimum ) );
	}

	private function sort_objects( array $values, array $keys ): array {
		$unique = array();
		foreach ( $values as $value ) {
			$unique[ $this->canonical->encode( $value ) ] = $value;
		}
		$values = array_values( $unique );
		usort(
			$values,
			static function ( object $left, object $right ) use ( $keys ): int {
				foreach ( $keys as $key ) {
					$comparison = ( $left->{$key} ?? '' ) <=> ( $right->{$key} ?? '' );
					if ( 0 !== $comparison ) {
						return $comparison;
					}
				}
				return 0;
			}
		);
		return $values;
	}
}
