<?php
/**
 * Resumable installation of runtime-owned InnoDB tables.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/**
 * Install schema before publishing the version; DDL is never a transaction.
 */
final class Schema {
	/** Current schema revision. */
	public const VERSION = 14;

	/**
	 * Create or resume the initial schema under a connection-scoped lock.
	 *
	 * @param Database      $db Dedicated runtime connection.
	 * @param callable|null $checkpoint Optional instrumentation after each DDL.
	 * @return void
	 * @throws StorageException Busy, unsupported future version, or failed DDL.
	 */
	public static function migrate( Database $db, ?callable $checkpoint = null ): void {
		$db->readiness()->invalidate();
		$lock = 'dai-schema-' . substr( hash( 'sha256', $db->scalar( 'SELECT DATABASE()' ) . $db->table( 'meta' ) ), 0, 40 );
		if ( '1' !== (string) $db->scalar( 'SELECT GET_LOCK(%s, 0)', array( $lock ) ) ) {
			throw new StorageException( 'Runtime schema installation is busy or unavailable.' );
		}
		try {
			$db->query( 'CREATE TABLE IF NOT EXISTS ' . $db->table( 'meta' ) . ' (name varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, value longtext NOT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
			$value = $db->scalar( 'SELECT value FROM ' . $db->table( 'meta' ) . ' WHERE name=%s', array( 'schema_version' ) );
			if ( null !== $value && ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{0,8}$/D', $value ) ) ) {
				throw new StorageException( 'Invalid runtime schema metadata. Automatic migration is disabled.' );
			}
			$version = (int) $value;
			if ( $version > self::VERSION ) {
				throw new StorageException( 'A newer runtime schema is installed. Downgrade writes are disabled.' );
			}
			if ( self::VERSION === $version ) {
				return;
			}
			foreach ( self::definitions() as $table => $columns ) {
				$db->query( 'CREATE TABLE IF NOT EXISTS ' . $db->table( $table ) . ' (' . $columns . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
				if ( $checkpoint ) {
					$checkpoint( $table );
				}
			}
			if ( $version < 2 ) {
				self::upgrade_to_two( $db );
			}
			if ( $version < 4 ) {
				self::upgrade_to_four( $db );
			}
			if ( $version < 8 ) {
				self::upgrade_to_eight( $db );
			}
			if ( $version < 10 ) {
				self::upgrade_to_ten( $db, $checkpoint );
			}
			if ( $version < 12 ) {
				// Full source-versioned native contracts exceed the former 32-character column.
				$db->query( 'ALTER TABLE ' . $db->table( 'visitor_tasks' ) . ' MODIFY adapter_revision varchar(128) NOT NULL' );
			}
			if ( $version < 14 ) {
				self::upgrade_image_capacity( $db ); }
			$db->query( 'INSERT INTO ' . $db->table( 'meta' ) . ' (name,value) VALUES (%s,%s) ON DUPLICATE KEY UPDATE value=VALUES(value)', array( 'schema_version', (string) self::VERSION ) );
		} finally {
			$db->readiness()->invalidate();
			$db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $lock ) );
		}
	}

	/**
	 * Runtime schema; later catalog/index modules own their separate migrations.
	 *
	 * @return array Table definitions.
	 */
	public static function definitions(): array {
		$id   = 'varchar(128) CHARACTER SET ascii COLLATE ascii_bin';
		$hash = 'char(64) CHARACTER SET ascii COLLATE ascii_bin';
		return array(
			'import_jobs'               => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, site_id bigint unsigned NOT NULL, actor_ref $id NOT NULL, directory_id bigint unsigned NOT NULL, source_hash $hash NOT NULL, schema_hash $hash NOT NULL, environment_hash $hash NOT NULL, state varchar(32) NOT NULL, version bigint unsigned NOT NULL DEFAULT 1, manifest_json longtext NOT NULL, record_count int unsigned NOT NULL, next_ordinal int unsigned NOT NULL DEFAULT 0, cancel_requested tinyint unsigned NOT NULL DEFAULT 0, lease_token $id NULL, lease_expires_at datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), KEY actor_updated(actor_ref,updated_at), KEY directory_source(directory_id,source_hash), KEY runnable(state,lease_expires_at), KEY expiry(expires_at)",
			'import_records'            => "id bigint unsigned NOT NULL AUTO_INCREMENT, job_id bigint unsigned NOT NULL, ordinal int unsigned NOT NULL, public_id $hash NOT NULL, source_identity $hash NOT NULL, source_json longtext NOT NULL, provenance_json longtext NOT NULL, staged_json longtext NULL, row_hash $hash NULL, state varchar(32) NOT NULL DEFAULT 'staged', operation_id $id NULL, receipt_json longtext NULL, listing_id bigint unsigned NULL, updated_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY job_ordinal(job_id,ordinal), KEY source_state(source_identity,state), KEY job_state(job_id,state,ordinal)",
			'import_claims'             => "id bigint unsigned NOT NULL AUTO_INCREMENT, source_identity $hash NOT NULL, intent_hash $hash NOT NULL, job_id bigint unsigned NOT NULL, record_id bigint unsigned NOT NULL, state varchar(32) NOT NULL, listing_id bigint unsigned NULL, receipt_json longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY source_identity(source_identity), KEY job_state(job_id,state)",
			'task_receipts'             => "id bigint unsigned NOT NULL AUTO_INCREMENT, task_id $id NOT NULL, owner_ref $id NOT NULL, run_id $id NOT NULL, parent_task_id $id NULL, identity_hash $hash NOT NULL, logical_task varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, uses_ai tinyint unsigned NOT NULL, feature varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, billing_mode varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, state_version bigint unsigned NOT NULL DEFAULT 1, outcome varchar(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, receipt_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY task_id(task_id), KEY run_parent(run_id,parent_task_id), KEY owner_created(owner_ref,created_at), KEY source_feature(source,feature,created_at), KEY expiry_state(expires_at,state), KEY created_ai(created_at,uses_ai)",
			'visitor_tasks'             => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, owner_ref $id NOT NULL, conversation_public_id $id NOT NULL, run_public_id $id NULL, request_id $id NOT NULL, input_hash $hash NOT NULL, adapter_id varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, adapter_revision varchar(128) NOT NULL, directory_id bigint unsigned NOT NULL, listing_id bigint unsigned NOT NULL, state varchar(32) NOT NULL, state_version bigint unsigned NOT NULL DEFAULT 1, operation_key $hash NOT NULL, prepared_json longtext NOT NULL, proposal_hash $hash NULL, quote_id $id NULL, quote_expires_at datetime NULL, receipt_json longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), UNIQUE KEY owner_request(owner_ref,request_id), UNIQUE KEY operation_key(operation_key), KEY conversation_state(conversation_public_id,state), KEY owner_updated(owner_ref,updated_at), KEY expiry(expires_at)",
			'conversations'             => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, owner_user_id bigint unsigned NULL, guest_hash $hash NULL, owner_ref $id NOT NULL, request_id $id NOT NULL, input_hash $hash NOT NULL, profile varchar(32) NOT NULL, retention_seconds int unsigned NOT NULL, context_json longtext NOT NULL, summary_json longtext NULL, active_run_id bigint unsigned NULL, erasure_requested tinyint unsigned NOT NULL DEFAULT 0, state_version bigint unsigned NOT NULL DEFAULT 1, next_turn_seq bigint unsigned NOT NULL DEFAULT 1, next_message_seq bigint unsigned NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), UNIQUE KEY owner_request(owner_ref,request_id), KEY owner_updated(owner_user_id,updated_at), KEY guest_expiry(guest_hash,expires_at), KEY expiry(expires_at), CHECK ((owner_user_id IS NOT NULL AND guest_hash IS NULL) OR (owner_user_id IS NULL AND guest_hash IS NOT NULL))",
			'turns'                     => "id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, sequence bigint unsigned NOT NULL, request_id $id NOT NULL, input_hash $hash NOT NULL, reply_to_run_id bigint unsigned NULL, run_id bigint unsigned NULL, state varchar(32) NOT NULL, input_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY conversation_sequence(conversation_id,sequence), UNIQUE KEY conversation_request(conversation_id,request_id), KEY run_id(run_id)",
			'messages'                  => 'id bigint unsigned NOT NULL AUTO_INCREMENT, conversation_id bigint unsigned NOT NULL, turn_id bigint unsigned NOT NULL, sequence bigint unsigned NOT NULL, role varchar(16) NOT NULL, content_json longtext NOT NULL, source_refs_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY conversation_sequence(conversation_id,sequence), KEY turn_id(turn_id)',
			'runs'                      => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, owner_ref $id NOT NULL, conversation_id bigint unsigned NULL, trigger_turn_id bigint unsigned NULL, state varchar(32) NOT NULL, state_version bigint unsigned NOT NULL DEFAULT 1, checkpoint_json longtext NOT NULL, next_step_seq bigint unsigned NOT NULL DEFAULT 1, next_event_seq bigint unsigned NOT NULL DEFAULT 1, available_at datetime NOT NULL, lease_owner $id NULL, lease_until datetime NULL, fence bigint unsigned NOT NULL DEFAULT 0, cancel_requested tinyint unsigned NOT NULL DEFAULT 0, budget_json longtext NOT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), UNIQUE KEY trigger_turn(trigger_turn_id), KEY runnable(state,available_at), KEY lease_expiry(lease_until), KEY conversation_state(conversation_id,state)",
			'steps'                     => "id bigint unsigned NOT NULL AUTO_INCREMENT, run_id bigint unsigned NOT NULL, sequence bigint unsigned NOT NULL, kind varchar(32) NOT NULL, input_hash $hash NOT NULL, attempt int unsigned NOT NULL DEFAULT 0, state varchar(32) NOT NULL, provider_request_ref $id NULL, result_ref $id NULL, data_json longtext NOT NULL, PRIMARY KEY(id), UNIQUE KEY run_sequence(run_id,sequence), KEY run_state(run_id,state)",
			'operations'                => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, run_id bigint unsigned NULL, actor_ref $id NOT NULL, capability varchar(96) NOT NULL, capability_revision varchar(32) NOT NULL, proposal_hash $hash NOT NULL, target_set_hash $hash NOT NULL, approval_id bigint unsigned NULL, state varchar(32) NOT NULL, reconciliation_key $id NOT NULL, receipt_json longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), KEY run_state(run_id,state), KEY actor_capability(actor_ref,capability)",
			'target_locks'              => "target_key $id NOT NULL, operation_id bigint unsigned NOT NULL, fence bigint unsigned NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(target_key), KEY operation_id(operation_id), KEY expiry(expires_at)",
			'events'                    => 'id bigint unsigned NOT NULL AUTO_INCREMENT, run_id bigint unsigned NOT NULL, sequence bigint unsigned NOT NULL, type varchar(48) NOT NULL, payload_json longtext NOT NULL, created_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY run_sequence(run_id,sequence), KEY expiry(expires_at)',
			'artifacts'                 => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, version bigint unsigned NOT NULL, parent_version bigint unsigned NULL, owner_ref $id NOT NULL, conversation_id bigint unsigned NULL, run_id bigint unsigned NULL, kind varchar(48) NOT NULL, target_key $id NULL, base_hash $hash NULL, environment_hash $hash NOT NULL, content_hash $hash NOT NULL, payload_json longtext NOT NULL, source_refs_json longtext NOT NULL, created_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY artifact_version(public_id,version), KEY target_version(target_key,version), KEY owner_expiry(conversation_id,expires_at)",
			'approvals'                 => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, actor_ref $id NOT NULL, capability varchar(96) NOT NULL, proposal_hash $hash NOT NULL, artifact_id $id NOT NULL, artifact_version bigint unsigned NOT NULL, target_set_hash $hash NOT NULL, base_versions_hash $hash NOT NULL, environment_hash $hash NOT NULL, state varchar(32) NOT NULL, expires_at datetime NOT NULL, reserved_operation_id bigint unsigned NULL, reserved_operation_public_id $id NULL, review_json longtext NOT NULL, review_identity $hash NOT NULL, created_at datetime NOT NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), UNIQUE KEY reserved_operation(reserved_operation_id), UNIQUE KEY reserved_operation_public(reserved_operation_public_id), UNIQUE KEY review_identity(review_identity), KEY actor_expiry(actor_ref,state,expires_at)",
			'usage_windows'             => "scope_key $id NOT NULL, window_start datetime NOT NULL, model_rounds bigint unsigned NOT NULL DEFAULT 0, input_tokens bigint unsigned NOT NULL DEFAULT 0, output_tokens bigint unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY(scope_key,window_start), KEY window_start(window_start)",
			'usage_reservations'        => "id bigint unsigned NOT NULL AUTO_INCREMENT, public_id $id NOT NULL, run_id bigint unsigned NOT NULL, window_start datetime NOT NULL, scope_keys_json longtext NOT NULL, reserved_input bigint unsigned NOT NULL, reserved_output bigint unsigned NOT NULL, modality varchar(16) NOT NULL DEFAULT 'text', image_quantity int unsigned NOT NULL DEFAULT 0, capacity_json longtext NULL, actual_input bigint unsigned NULL, actual_output bigint unsigned NULL, created_at datetime NOT NULL, settled_at datetime NULL, PRIMARY KEY(id), UNIQUE KEY public_id(public_id), KEY run_id(run_id), KEY unsettled(settled_at)",
			'projection_sources'        => "listing_id bigint unsigned NOT NULL, epoch bigint unsigned NOT NULL, public_eligible tinyint unsigned NOT NULL, source_hash $hash NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(listing_id), KEY public_epoch(public_eligible,epoch)",
			'projection_heads'          => "scope_key $id NOT NULL, directory_id bigint unsigned NOT NULL, active_generation bigint unsigned NULL, building_generation bigint unsigned NULL, active_catalog_revision $hash NULL, building_catalog_revision $hash NULL, state varchar(24) NOT NULL, cursor_id bigint unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY(scope_key,directory_id), KEY state_updated(state,updated_at)",
			'projection_entries'        => "scope_key $id NOT NULL, directory_id bigint unsigned NOT NULL, generation bigint unsigned NOT NULL, listing_id bigint unsigned NOT NULL, source_epoch bigint unsigned NOT NULL, source_hash $hash NOT NULL, content_hash $hash NOT NULL, eligible tinyint unsigned NOT NULL, facts_json longtext NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(scope_key,directory_id,generation,listing_id), KEY generation_eligible(scope_key,directory_id,generation,eligible,listing_id), KEY listing_epoch(listing_id,source_epoch)",
			'knowledge_sources'         => "source_key $id NOT NULL, post_id bigint unsigned NOT NULL, scope_key varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, directory_id bigint unsigned NULL, directory_key bigint unsigned NOT NULL DEFAULT 0, approval_epoch bigint unsigned NOT NULL, generation_counter bigint unsigned NOT NULL DEFAULT 0, approved tinyint unsigned NOT NULL, eligible tinyint unsigned NOT NULL, wake_required tinyint unsigned NOT NULL DEFAULT 1, source_revision $hash NOT NULL, active_generation bigint unsigned NULL, building_generation bigint unsigned NULL, building_revision $hash NULL, state varchar(24) NOT NULL, cursor_position int unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY(source_key), UNIQUE KEY source_alias(post_id,scope_key,directory_key), KEY post_approval(post_id,approved), KEY scope_eligible(scope_key,directory_key,approved,eligible), KEY wake_required(approved,wake_required,updated_at)",
			'knowledge_chunks'          => "source_key $id NOT NULL, generation bigint unsigned NOT NULL, section_key $id NOT NULL, source_revision $hash NOT NULL, scope_key varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, directory_id bigint unsigned NULL, position int unsigned NOT NULL, content_hash $hash NOT NULL, document_title varchar(512) NOT NULL, section_title varchar(512) NOT NULL, chunk_text text NOT NULL, search_text text NOT NULL, eligible tinyint unsigned NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY(source_key,generation,section_key), UNIQUE KEY source_position(source_key,generation,position), KEY source_generation(source_key,generation,eligible,position), KEY scope_eligibility(scope_key,directory_id,eligible,source_key), KEY stale_chunks(eligible,updated_at)",
			'knowledge_audit'           => "id bigint unsigned NOT NULL AUTO_INCREMENT, source_key $id NOT NULL, approval_epoch bigint unsigned NOT NULL, event varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, actor_user_id bigint unsigned NOT NULL, source_revision $hash NOT NULL, details_hash $hash NOT NULL, created_at datetime NOT NULL, PRIMARY KEY(id), KEY source_epoch(source_key,approval_epoch), KEY actor_created(actor_user_id,created_at)",
			'catalog_cache_heads'       => "context_key $hash NOT NULL, scope_hash $hash NOT NULL, builder varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, document_kind varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, directory_id bigint unsigned NOT NULL DEFAULT 0, editor_mode tinyint unsigned NOT NULL, generation_counter bigint unsigned NOT NULL DEFAULT 0, active_generation bigint unsigned NULL, active_key_hash $hash NULL, building_generation bigint unsigned NULL, building_key_hash $hash NULL, state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, failure_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL, updated_at datetime NOT NULL, PRIMARY KEY(context_key), KEY scope_builder(scope_hash,builder,directory_id), KEY state_updated(state,updated_at), KEY updated_at(updated_at)",
			'catalog_cache_generations' => "context_key $hash NOT NULL, generation bigint unsigned NOT NULL, key_hash $hash NOT NULL, catalog_revision $hash NULL, payload_hash $hash NULL, state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, payload_json longtext NULL, created_at datetime NOT NULL, completed_at datetime NULL, PRIMARY KEY(context_key,generation), KEY context_key_hash(context_key,key_hash,generation), KEY state_completed(state,completed_at), KEY completed_at(completed_at)",
			'graph_heads'               => "graph_key $hash NOT NULL, document_key $id NOT NULL, owner_ref $id NOT NULL, builder varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, document_kind $id NOT NULL, native_id $id NOT NULL, source_epoch bigint unsigned NOT NULL DEFAULT 1, generation_counter bigint unsigned NOT NULL DEFAULT 0, current_version_hash $hash NOT NULL, current_source_hash $hash NOT NULL, current_editor_epoch $id NULL, current_editor_revision $id NULL, current_environment_hash $hash NOT NULL, current_directory_hash $hash NOT NULL, current_adapter_revision $id NOT NULL, current_normalization_revision $id NOT NULL, active_generation bigint unsigned NULL, building_generation bigint unsigned NULL, building_version_hash $hash NULL, building_attempt_id $id NULL, eligible tinyint unsigned NOT NULL DEFAULT 0, state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, failure_code varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL, updated_at datetime NOT NULL, PRIMARY KEY(graph_key), UNIQUE KEY owner_document(owner_ref,document_key), KEY owner_builder(owner_ref,builder,document_kind), KEY state_updated(state,updated_at)",
			'graph_snapshots'           => "graph_key $hash NOT NULL, generation bigint unsigned NOT NULL, attempt_id $id NOT NULL, version_hash $hash NOT NULL, version_json longtext NOT NULL, snapshot_hash $hash NULL, graph_hash $hash NULL, relation_hash $hash NULL, native_artifact_hash $hash NULL, state varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, completeness varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL, consumer_complete tinyint unsigned NOT NULL DEFAULT 0, node_count int unsigned NOT NULL DEFAULT 0, edge_count int unsigned NOT NULL DEFAULT 0, slot_count int unsigned NOT NULL DEFAULT 0, graph_json longtext NULL, native_artifact_json longtext NULL, created_at datetime NOT NULL, completed_at datetime NULL, PRIMARY KEY(graph_key,generation), UNIQUE KEY graph_attempt(graph_key,attempt_id), KEY version_state(graph_key,version_hash,state,generation), KEY completed_at(completed_at)",
			'graph_edges'               => "graph_key $hash NOT NULL, document_key $id NOT NULL, generation bigint unsigned NOT NULL, edge_key $hash NOT NULL, owner_ref $id NOT NULL, kind varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, source_node $id NOT NULL, target_node $id NOT NULL, target_document_key $id NULL, scope_hash $hash NOT NULL, edge_order int NULL, provenance_json text NOT NULL, edge_json text NOT NULL, PRIMARY KEY(graph_key,generation,edge_key), KEY forward_edge(graph_key,generation,source_node,kind), KEY reverse_edge(target_document_key,target_node,kind,document_key), KEY owner_target(owner_ref,target_document_key,kind)",
			'graph_index_epochs'        => 'epoch_key varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, epoch bigint unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, PRIMARY KEY(epoch_key)',
		);
	}

	/**
	 * Fence every pre-binding graph and install the owner/attempt snapshot hash.
	 *
	 * The head becomes unavailable before old edge or snapshot bytes are removed,
	 * so an interrupted DDL/data migration can only fail closed and safely resume.
	 *
	 * @param Database      $db Dedicated migration connection.
	 * @param callable|null $checkpoint Optional migration fault injection.
	 * @return void
	 */
	private static function upgrade_to_ten( Database $db, ?callable $checkpoint ): void {
		$snapshots = $db->table( 'graph_snapshots' );
		$table     = trim( $snapshots, '`' );
		$column    = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', array( $table, 'snapshot_hash' ) );
		if ( ! $column ) {
			$db->query( "ALTER TABLE $snapshots ADD snapshot_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER version_json" );
		}
		if ( $checkpoint ) {
			$checkpoint( 'graph_snapshot_binding_column' );
		}
		$heads = $db->table( 'graph_heads' );
		$db->query( "UPDATE $heads h LEFT JOIN (SELECT graph_key,MAX(generation) AS max_generation FROM $snapshots GROUP BY graph_key) s ON s.graph_key=h.graph_key SET h.generation_counter=GREATEST(h.generation_counter,COALESCE(h.active_generation,0),COALESCE(h.building_generation,0),COALESCE(s.max_generation,0)),h.active_generation=NULL,h.building_generation=NULL,h.building_version_hash=NULL,h.building_attempt_id=NULL,h.eligible=0,h.state='unavailable',h.failure_code='graph_binding_migration',h.updated_at=UTC_TIMESTAMP()" );
		if ( $checkpoint ) {
			$checkpoint( 'graph_snapshot_binding_fence' );
		}
		$db->query( 'DELETE FROM ' . $db->table( 'graph_edges' ) );
		$db->query( "DELETE FROM $snapshots" );
		if ( $checkpoint ) {
			$checkpoint( 'graph_snapshot_binding_cleanup' );
		}
	}

	/**
	 * Resume the approval reservation identifier upgrade.
	 *
	 * @param Database $db Dedicated migration connection.
	 * @return void
	 */
	private static function upgrade_to_two( Database $db ): void {
		$table  = trim( $db->table( 'approvals' ), '`' );
		$column = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', array( $table, 'reserved_operation_public_id' ) );
		if ( ! $column ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'approvals' ) . ' ADD reserved_operation_public_id varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reserved_operation_id' );
		}
		$index = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s', array( $table, 'reserved_operation_public' ) );
		if ( ! $index ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'approvals' ) . ' ADD UNIQUE KEY reserved_operation_public(reserved_operation_public_id)' );
		}
	}

	/**
	 * Resume the idempotent exact-review identity upgrade.
	 *
	 * @param Database $db Dedicated migration connection.
	 */
	private static function upgrade_to_four( Database $db ): void {
		$table  = trim( $db->table( 'approvals' ), '`' );
		$column = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', array( $table, 'review_identity' ) );
		if ( ! $column ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'approvals' ) . ' ADD review_identity char(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER review_json' );
		}
		$rows = $db->rows( 'SELECT id,review_json FROM ' . $db->table( 'approvals' ) . ' WHERE review_identity IS NULL' );
		foreach ( $rows as $row ) {
			$db->query( 'UPDATE ' . $db->table( 'approvals' ) . ' SET review_identity=%s WHERE id=%d AND review_identity IS NULL', array( hash( 'sha256', $row['review_json'] ), (int) $row['id'] ) );
		}
		$index = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s', array( $table, 'review_identity' ) );
		if ( ! $index ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'approvals' ) . ' ADD UNIQUE KEY review_identity(review_identity)' );
		}
		$db->query( 'ALTER TABLE ' . $db->table( 'approvals' ) . ' MODIFY review_identity char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL' );
	}

	/**
	 * Upgrade the published version-7 catalog cache without trusting old payloads.
	 *
	 * @param Database $db Dedicated migration connection.
	 */
	private static function upgrade_to_eight( Database $db ): void {
		$heads        = trim( $db->table( 'catalog_cache_heads' ), '`' );
		$generations  = trim( $db->table( 'catalog_cache_generations' ), '`' );
		$payload_hash = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', array( $generations, 'payload_hash' ) );
		if ( ! $payload_hash ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'catalog_cache_generations' ) . ' ADD payload_hash char(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER catalog_revision' );
		}
		$counter = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s', array( $heads, 'generation_counter' ) );
		if ( ! $counter ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'catalog_cache_heads' ) . ' ADD generation_counter bigint unsigned NOT NULL DEFAULT 0 AFTER editor_mode' );
		}
		$index = $db->rows( 'SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s ORDER BY SEQ_IN_INDEX LIMIT 1', array( $generations, 'context_key_hash' ) );
		if ( $index ) {
			$db->query( 'ALTER TABLE ' . $db->table( 'catalog_cache_generations' ) . ' DROP INDEX context_key_hash' );
		}
		$db->query( 'ALTER TABLE ' . $db->table( 'catalog_cache_generations' ) . ' ADD KEY context_key_hash(context_key,key_hash,generation)' );
		foreach ( array( array( $heads, 'updated_at', 'catalog_cache_heads' ), array( $generations, 'completed_at', 'catalog_cache_generations' ) ) as $definition ) {
			list( $table, $index_name, $logical ) = $definition;
			$exists                               = (int) $db->scalar( 'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s', array( $table, $index_name ) );
			if ( ! $exists ) {
				$db->query( 'ALTER TABLE ' . $db->table( $logical ) . " ADD KEY $index_name($index_name)" );
			}
		}
		$db->query( 'UPDATE ' . $db->table( 'catalog_cache_generations' ) . " SET state='failed',payload_hash=NULL,payload_json=NULL,completed_at=UTC_TIMESTAMP() WHERE payload_json IS NOT NULL OR state='building'" );
		$db->query(
			'UPDATE ' . $db->table( 'catalog_cache_heads' ) . ' h LEFT JOIN (SELECT context_key,MAX(generation) maximum_generation FROM ' . $db->table( 'catalog_cache_generations' ) . ") g ON g.context_key=h.context_key SET h.generation_counter=GREATEST(h.generation_counter,COALESCE(g.maximum_generation,0),COALESCE(h.active_generation,0),COALESCE(h.building_generation,0)),h.active_generation=NULL,h.active_key_hash=NULL,h.building_generation=NULL,h.building_key_hash=NULL,h.state='unavailable',h.failure_code='cache_migration',h.updated_at=UTC_TIMESTAMP()"
		);
	}
	/**
	 * Add typed capacity evidence without rewriting existing reservations or receipts.
	 *
	 * @param Database $db Site-scoped runtime connection.
	 */
	private static function upgrade_image_capacity( Database $db ): void {
		$table = $db->table( 'usage_reservations' );
		foreach ( array(
			'modality'       => "varchar(16) NOT NULL DEFAULT 'text'",
			'image_quantity' => 'int unsigned NOT NULL DEFAULT 0',
			'capacity_json'  => 'longtext NULL',
		) as $column => $definition ) {
			if ( ! $db->rows( "SHOW COLUMNS FROM $table LIKE %s", array( $column ) ) ) {
				$db->query( "ALTER TABLE $table ADD COLUMN $column $definition" );
			}
		}
	}
}
