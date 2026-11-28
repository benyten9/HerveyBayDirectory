<?php
/**
 * Administrator import lifecycle and exact parent authorization.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Approvals\{ApprovalService, ApprovalSpec};
use DirectoristAIPro\Capabilities\{Actor, Context};
use DirectoristAIPro\Directory\DirectorySchema;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Effects\{EffectLedger, EffectPlan, EffectTarget};
use DirectoristAIPro\Storage\{ArtifactStore, ConversationStore, Database};
use DirectoristAIPro\Validation\ContractException;

/** REST can request lifecycle operations; no client supplies child effect plans. */
final class ImportService {
	private ImportStore $store;
	private ImportSchema $schema;
	private ApprovalService $approvals;
	private EffectLedger $ledger;

	public function __construct( private Database $db ) {
		$this->store     = new ImportStore( $db );
		$this->schema    = new ImportSchema( new DirectoristInspector() );
		$this->approvals = new ApprovalService( $db );
		$this->ledger    = new EffectLedger( $db );
	}

	public function stage( Context $context, int $directory_id, array $source ): array {
		return $this->view( $context, $this->store->create( $context, $this->schema->capture( $context, $directory_id ), $source )['public_id'] );
	}

	public function schema( Context $context, int $directory_id ): array {
		$schema = $this->schema->capture( $context, $directory_id );
		return array(
			'directory_id'    => $directory_id,
			'name'            => $schema['name'],
			'revision'        => $schema['revision'],
			'fields'          => ImportSchema::descriptors( $schema ),
			'behavior'        => array_diff_key( $schema['behavior'], array( 'eligibility' => true ) ),
			'settings_audit'  => $schema['settings'],
			'policy_defaults' => $this->policy_defaults( $context, $schema ),
		);
	}

	public function history( Context $context, int $offset = 0 ): array {
		return array_map( array( $this, 'history_item' ), $this->store->history( $context, $offset ) ); }

	public function history_page( Context $context, int $offset = 0 ): array {
		$jobs = $this->store->history( $context, 0, Limits::RECENT_IMPORTS );
		return array(
			'jobs'        => 0 === $offset ? array_map( array( $this, 'history_item' ), $jobs ) : array(),
			'has_more'    => false,
			'next_offset' => null,
		);
	}

	private function history_item( array $job ): array {
		$source                  = json_decode( $job['source_json'] ?? '{}', true );
		$job['source_name']      = ImportPresentation::source_name( is_array( $source ) ? $source : array() );
		$job['expired']          = strtotime( $job['expires_at'] . ' UTC' ) <= time();
		$job['cancel_requested'] = (bool) $job['cancel_requested'];
		unset( $job['source_json'] );
		return $job;
	}

	public function source_paths( Context $context, string $id, string $search = '', int $offset = 0 ): array {
		$job = $this->store->get( $context, $id );
		return SourceCatalog::page( $this->source_records( $job ), $search, $offset );
	}

	public function source_path( Context $context, string $id, string $path ): array {
		$job = $this->store->get( $context, $id );
		return SourceCatalog::check( $this->source_records( $job ), $job['manifest']['schema'], $path );
	}

	private function source_records( array $job ): \Generator {
		for ( $offset = 0; $offset < (int) $job['record_count']; $offset += 100 ) {
			foreach ( $this->store->rows( $job, $offset, 100 ) as $row ) {
				yield json_decode( $row['source_json'], true, 32, JSON_THROW_ON_ERROR );
			}
		}
	}

	public function view( Context $context, string $id, int $offset = 0, string $filter = 'all' ): array {
		if ( $offset < 0 || ! in_array( $filter, array( 'all', 'included', 'excluded' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Choose a bounded record page and known review filter.' );
		}
		$job = $this->store->get( $context, $id );
		// A stopped worker may never release its lease. Complete an already
		// requested cancellation once that lease has expired, without new work.
		if ( (int) $job['cancel_requested'] && 'cancelled' !== $job['state'] && $job['lease_expires_at'] && strtotime( $job['lease_expires_at'] . ' UTC' ) <= time() ) {
			$this->store->cancel( $job );
			$job = $this->store->get( $context, $id );
		}
		$manifest           = $job['manifest'];
		$preparation        = $this->preparation( $context, $job );
		$approved           = $manifest['approved_manifest'] ?? null;
		$reviewed_selection = is_array( $approved ) ? array(
			'valid_only'       => true === ( $approved['valid_only'] ?? false ),
			'included_count'   => count( $approved['record_hashes'] ),
			'invalid_excluded' => (int) $approved['invalid_excluded'],
			'excluded_count'   => (int) $job['record_count'] - count( $approved['record_hashes'] ),
			'review_hash'      => $manifest['review']['review_hash'] ?? null,
		) : null;
		$rows               = array();
		foreach ( $this->store->reviewed_rows( $job, $offset, $filter ) as $row ) {
			$staged       = $row['staged_json'] ? json_decode( $row['staged_json'], true, 64, JSON_THROW_ON_ERROR ) : null;
			$receipt      = $row['receipt_json'] ? json_decode( $row['receipt_json'], true ) : null;
			$included     = is_array( $approved ) ? isset( $approved['record_hashes'][ (string) $row['ordinal'] ] ) : null;
			$listing      = $row['listing_id'] ? (int) $row['listing_id'] : null;
			$edit_url     = $listing && $context->actor()->can( 'edit_post', $listing ) ? get_edit_post_link( $listing, 'raw' ) : null;
			$result_state = ImportPresentation::result_state( $job, $row );
			$rows[]       = array_merge(
				array(
					'id'              => $row['public_id'],
					'ordinal'         => (int) $row['ordinal'],
					'state'           => $row['state'],
					'source'          => json_decode( $row['source_json'], true ),
					'provenance'      => json_decode( $row['provenance_json'], true ),
					'values'          => $staged['values'] ?? null,
					'display_values'  => ImportPresentation::display_values( $staged['values'] ?? null, $manifest['schema'] ),
					'errors'          => $staged['errors'] ?? array(),
					'warnings'        => $staged['warnings'] ?? array(),
					'receipt'         => $receipt,
					'listing_id'      => $listing,
					'listing_url'     => $edit_url,
					'edit_url'        => $edit_url,
					'public_url'      => $listing ? ImportPresentation::public_url( $listing, (int) $job['directory_id'] ) : null,
					'review_included' => $included,
					'result_state'    => $result_state,
				),
				ImportPresentation::diagnostic( $receipt, $row['state'] )
			);
		}
		return array(
			'id'                 => $id,
			'state'              => $job['state'],
			'version'            => (int) $job['version'],
			'directory_id'       => (int) $job['directory_id'],
			'directory_name'     => $manifest['schema']['name'],
			'source'             => $manifest['source'],
			'source_name'        => ImportPresentation::source_name( $manifest['source'] ),
			'source_hash'        => $job['source_hash'],
			'created_at'         => $job['created_at'],
			'schema_hash'        => $job['schema_hash'],
			'fields'             => ImportSchema::descriptors( $manifest['schema'] ),
			'mapping'            => $manifest['mapping'],
			'mapping_run_id'     => $manifest['mapping_run_id'] ?? null,
			'policy_defaults'    => $this->policy_defaults( $context, $manifest['schema'] ),
			'directory_behavior' => array_diff_key( $manifest['schema']['behavior'], array( 'eligibility' => true ) ),
			'policy'             => $manifest['policy'],
			'selected_author'    => AuthorCatalog::selected( $context, (int) $job['directory_id'], (int) ( $manifest['policy']['author_id'] ?? $context->actor()->user_id() ) ),
			'review'             => $manifest['review'] ?? null,
			'reviewed_selection' => $reviewed_selection,
			'record_count'       => (int) $job['record_count'],
			'counts'             => $this->store->counts( $job ),
			'validation_cursor'  => $manifest['validation_cursor'],
			'next_ordinal'       => (int) $job['next_ordinal'],
			'cancel_requested'   => (bool) $job['cancel_requested'],
			'last_error'         => $manifest['last_error'] ?? null,
			'rows'               => $rows,
			'offset'             => $offset,
			'filter'             => $filter,
			'filtered_count'     => 'all' === $filter ? (int) $job['record_count'] : (int) ( $reviewed_selection[ 'included' === $filter ? 'included_count' : 'excluded_count' ] ?? 0 ),
			'expires_at'         => $job['expires_at'],
			'expired'            => strtotime( $job['expires_at'] . ' UTC' ) <= time(),
			'capture_current'    => $preparation['capture_current'],
			'preparation_issue'  => $preparation['preparation_issue'],
		);
	}

	/** Inspect the pinned preparation without replacing it or changing its saved review. */
	private function preparation( Context $context, array $job ): array {
		if ( strtotime( $job['expires_at'] . ' UTC' ) <= time() ) {
			return array(
				'capture_current'   => false,
				'preparation_issue' => array(
					'code'    => 'import_expired',
					'message' => 'This private import expired. Already created listings remain in WordPress. Start a new import using the original source for remaining records.',
				),
			);
		}
		try {
			$schema = $this->schema->capture( $context, (int) $job['directory_id'] );
		} catch ( \Throwable $error ) {
			return array(
				'capture_current'   => null,
				'preparation_issue' => array(
					'code'    => 'capture_unavailable',
					'message' => 'We could not check this import. Try again to continue. Your saved import and report are still available.',
				),
			);
		}
		$current = hash_equals( $job['schema_hash'], $schema['revision'] ) && hash_equals( $job['environment_hash'], $schema['environment_hash'] );
		return array(
			'capture_current'   => $current,
			'preparation_issue' => $current ? null : array(
				'code'    => 'capture_stale',
				'message' => 'This import is out of date. Start again with your original file or URL. Your saved import and report are still available.',
			),
		);
	}

	/** Invoke one existing accounted mapping route outside this service. */
	public function mapping_request( Context $context, string $id ): array {
		$job     = $this->editable( $context, $id );
		$records = array_map( array( ImportStore::class, 'source_record' ), $this->store->rows( $job, 0, Limits::RECORDS ) );
		return array(
			'context' => array(
				'listing_import' => array(
					'mode'         => 'mapping',
					'job_id'       => $id,
					'directory_id' => (int) $job['directory_id'],
					'schema_hash'  => $job['schema_hash'],
					'job_version'  => (int) $job['version'],
					'source_hash'  => $job['source_hash'],
				),
			),
			'input'   => Mapping::sample( $job['manifest']['schema'], $records ),
		);
	}

	public function mapping( Context $context, string $id, int $version, array $input, array $policy ): array {
		$job = $this->editable( $context, $id );
		$this->version( $job, $version );
		$records = array_map( array( ImportStore::class, 'source_record' ), $this->store->rows( $job, 0, Limits::RECORDS ) );
		if ( 'worker' === $context->transport() && $context->run_id() ) {
			$permitted = Mapping::sample( $job['manifest']['schema'], $records );
			foreach ( $input['fields'] ?? array() as $entry ) {
				if ( ! is_array( $entry ) ) { throw new ContractException( 'mapping_invalid', 'AI mapping must name existing fields and source paths.' ); }
				foreach ( CompositeFields::paths( $entry ) as $path ) {
					if ( ! in_array( $path, $permitted['source_paths'], true ) ) { throw new ContractException( 'mapping_invalid', 'AI mapping selected an undisclosed or private source path. Use manual mapping for that source fact.' ); }
				}
			}
		}
		$manifest                      = $job['manifest'];
		$manifest['mapping']           = Mapping::check( $input, $manifest['schema'], $records );
		$manifest['policy']            = $this->policy( $context, $manifest['schema'], $policy );
		$manifest['validation_cursor'] = 0;
		$manifest['mapping_run_id']    = 'worker' === $context->transport() && $context->run_id() ? $context->run_id() : null;
		unset( $manifest['review'], $manifest['approved_manifest'], $manifest['artifact'], $manifest['operation_id'] );
		$this->db->transaction(
			function () use ( $job, $manifest ): void {
				$this->store->update( $job, $manifest, 'mapped' );
				$this->store->reset( $job );
			}
		);
		return $this->view( $context, $id );
	}

	/** Validate all records through bounded resumable preview requests. */
	public function validate( Context $context, string $id ): array {
		$job = $this->store->get( $context, $id );
		$this->fresh( $context, $job );
		if ( ! in_array( $job['state'], array( 'mapped', 'validating', 'validated' ), true ) ) {
			throw new ContractException( 'import_state', 'Save mapping and review policy before validating.' ); }
		if ( 'validated' === $job['state'] ) {
			return $this->view( $context, $id ); }
		$manifest = $job['manifest'];
		$started  = microtime( true );
		$staged   = array();
		foreach ( $this->store->rows( $job, $manifest['validation_cursor'], 100 ) as $row ) {
			$staged[]                      = array( $row, ( new RecordValidator() )->validate( $context, $manifest['schema'], $manifest['mapping'], ImportStore::source_record( $row ), $manifest['policy'] ) );
			$manifest['validation_cursor'] = (int) $row['ordinal'] + 1;
			if ( microtime( true ) - $started > Limits::BATCH_SECONDS ) {
				break; }
		}
		$this->db->transaction(
			function () use ( $job, $manifest, $staged ): void {
				$this->store->update( $job, $manifest, $manifest['validation_cursor'] >= (int) $job['record_count'] ? 'validated' : 'validating' );
				foreach ( $staged as $entry ) {
					$this->store->staged( $entry[0], $entry[1] ); }
			}
		);
		return $this->view( $context, $id );
	}

	public function review( Context $context, string $id, int $version, bool $valid_only, array $selected = array() ): array {
		$job = $this->store->get( $context, $id );
		$this->version( $job, $version );
		$this->fresh( $context, $job );
		if ( 'validated' !== $job['state'] ) {
			throw new ContractException( 'import_state', 'Validate every source record before creating the exact review.' ); }
		if ( count( $selected ) > Limits::RECORDS || count( array_unique( $selected ) ) !== count( $selected ) ) {
			throw new ContractException( 'selection_invalid', 'Select unique source record IDs.' ); }
		$manifest = $job['manifest'];
		$hashes   = array();
		$aux      = array();
		$invalid  = 0;
		$seen     = array();
		foreach ( $this->store->rows( $job, 0, Limits::RECORDS ) as $row ) {
			if ( $selected && ! in_array( $row['public_id'], $selected, true ) ) {
				continue; }
			$seen[] = $row['public_id'];
			if ( 'valid' !== $row['state'] ) {
				++$invalid;
				continue; }
			$staged = json_decode( $row['staged_json'], true, 64, JSON_THROW_ON_ERROR );
			$this->row_hash( $row, $staged );
			$hashes[ (string) $row['ordinal'] ] = $row['row_hash'];
			foreach ( $manifest['schema']['fields'] as $field_id => $field ) {
				if ( in_array( $field['widget'], array( 'category', 'location', 'tag', 'image_upload' ), true ) && ! empty( $staged['values'][ $field_id ] ) ) {
					$aux[ $field_id ] = array_values( array_unique( array_merge( $aux[ $field_id ] ?? array(), $staged['values'][ $field_id ] ) ) ); }
			}
		}
		if ( ( $selected && count( $seen ) !== count( $selected ) ) || ! $hashes || ( $invalid && ! $valid_only ) ) {
			throw new ContractException( 'selection_invalid', 'Choose valid-only import explicitly when selected source records include errors. At least one valid record is required.' ); }
		$approved                      = array(
			'revision'               => Limits::REVISION,
			'job_id'                 => $id,
			'actor_ref'              => $job['actor_ref'],
			'site_id'                => (int) $job['site_id'],
			'directory_id'           => (int) $job['directory_id'],
			'source_hash'            => $job['source_hash'],
			'schema_hash'            => $job['schema_hash'],
			'environment_hash'       => $job['environment_hash'],
			'mapping'                => $manifest['mapping'],
			'policy'                 => $manifest['policy'],
			'record_hashes'          => $hashes,
			'valid_only'             => $valid_only,
			'invalid_excluded'       => $invalid,
			'existing_auxiliary_ids' => $aux,
			'expires_at'             => $job['expires_at'],
		);
		$conversation                  = ( new ConversationStore( $this->db ) )->create( $context->actor()->owner(), bin2hex( random_bytes( 16 ) ), 'admin', (object) array( 'target' => 'listing-import:' . $id ), 86400 );
		$artifact                      = ( new ArtifactStore( $this->db ) )->put( $context->actor()->owner(), $conversation['public_id'], 'proposal', json_decode( ImportStore::json( $approved ) ), $job['environment_hash'] );
		$operation                     = 'import-' . $id . '-' . (int) $job['version'];
		$plan                          = new EffectPlan( $operation, $job['actor_ref'], 'listing.import', Limits::REVISION, $artifact['content_hash'], array( new EffectTarget( 'listingimport:' . $id, $job['schema_hash'], $artifact['content_hash'] ) ) );
		$review                        = $this->approvals->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				(int) $artifact['version'],
				$artifact['content_hash'],
				$job['environment_hash'],
				(object) array(
					'action'                 => 'Queue exact listing import',
					'directory'              => $manifest['schema']['name'],
					'records'                => count( $hashes ),
					'invalid_excluded'       => $invalid,
					'author_id'              => $manifest['policy']['author_id'],
					'status'                 => $manifest['policy']['status'],
					'listing_expiry_days'    => $manifest['schema']['behavior']['default_expiration'],
					'duplicate_policy'       => 'skip_recognized_identity; report ambiguity; create only',
					'auxiliary_effects'      => 'Associate reviewed existing terms and owned attachments. No creation.',
					'existing_auxiliary_ids' => (object) $aux,
					'omitted_fields'         => $manifest['mapping']['omit_fields'],
					'default_fields'         => $manifest['mapping']['default_fields'],
				),
				(object) array(
					'records'              => count( $hashes ),
					'batch_records'        => Limits::BATCH_RECORDS,
					'batch_seconds'        => Limits::BATCH_SECONDS,
					'execution_expires_at' => $job['expires_at'],
				)
			)
		);
		$manifest['approved_manifest'] = $approved;
		$manifest['artifact']          = array(
			'id'      => $artifact['public_id'],
			'version' => (int) $artifact['version'],
			'hash'    => $artifact['content_hash'],
		);
		$manifest['operation_id']      = $operation;
		$manifest['review']            = $review;
		$this->store->update( $job, $manifest, 'reviewed' );
		return $this->view( $context, $id );
	}

	public function launch( Context $context, string $id, string $review_hash ): array {
		$job = $this->store->get( $context, $id );
		$this->fresh( $context, $job );
		$manifest = $job['manifest'];
		if ( ! isset( $manifest['review'] ) || ! hash_equals( $manifest['review']['review_hash'], $review_hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Approve the exact current import review.' ); }
		$current_review = $this->approvals->get( $context->actor(), $manifest['review']['id'] );
		if ( 'pending' === $current_review['state'] ) {
			$this->approvals->decide( $context->actor(), $manifest['review']['id'], $review_hash, 'approve' ); }
		$permit  = $this->approvals->reserve( $context->actor(), $manifest['review']['id'], $review_hash, $manifest['operation_id'], $job['environment_hash'] );
		$receipt = $this->ledger->execute( $permit->plan, new LaunchEffect( $this, $context, $id ) );
		$this->approvals->complete( $permit, $receipt );
		if ( 'succeeded' === $receipt['state'] ) {
			$this->enqueue( $id ); }
		return $this->view( $context, $id );
	}

	/** Called by the exact parent ledger adapter, never by raw REST input. */
	public function authorize_launch( Context $context, string $id, EffectPlan $plan, bool $for_write = true ): array {
		$job = $this->store->get( $context, $id );
		if ( $for_write ) {
			$this->fresh( $context, $job ); }
		$manifest = $job['manifest'];
		if ( 1 !== count( $plan->targets ) || 'listing.import' !== $plan->capability || ( $manifest['operation_id'] ?? '' ) !== $plan->operation_id || $plan->actor_ref !== $job['actor_ref'] || 'listingimport:' . $id !== $plan->targets[0]->key || $plan->targets[0]->expected_version !== $job['schema_hash'] || ! hash_equals( $manifest['artifact']['hash'] ?? '', $plan->proposal_hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Parent import authorization does not match this immutable job.' ); }
		$artifact = ( new ArtifactStore( $this->db ) )->get( $context->actor()->owner(), $manifest['artifact']['id'], $manifest['artifact']['version'] );
		if ( ! hash_equals( $artifact['content_hash'], $plan->proposal_hash ) || DirectorySchema::hash( json_decode( $artifact['payload_json'], true ) ) !== DirectorySchema::hash( $manifest['approved_manifest'] ) ) {
			throw new ContractException( 'approval_mismatch', 'The approved import manifest was changed.' ); }
		return $job;
	}

	public function queue_launch( Context $context, string $id, EffectPlan $plan ): void {
		$job = $this->authorize_launch( $context, $id, $plan );
		if ( 'reviewed' === $job['state'] ) {
			$this->store->update( $job, $job['manifest'], 'queued' ); } elseif ( ! in_array( $job['state'], array( 'queued', 'running', 'succeeded', 'partial', 'unknown', 'cancelled', 'paused' ), true ) ) {
			throw new ContractException( 'import_state', 'This reviewed import cannot be queued.' ); }
	}

	/** Internal child authorization is derived from a succeeded parent launch only. */
	public function authorized( Context $context, string $id, array $row, bool $for_write = true ): array {
		$job = $this->store->get( $context, $id );
		if ( $for_write ) {
			$this->fresh( $context, $job ); }
		$manifest = $job['manifest'];
		if ( $for_write && ( (int) $job['cancel_requested'] || ! in_array( $job['state'], array( 'queued', 'running', 'unknown' ), true ) || strtotime( $job['expires_at'] . ' UTC' ) <= time() ) ) {
			throw new ContractException( 'import_paused', 'This import is cancelled, expired or requires fresh review.' ); }
		$review = $this->approvals->get( $context->actor(), $manifest['review']['id'] );
		if ( 'consumed' !== $review['state'] || ! hash_equals( $review['review_hash'], $manifest['review']['review_hash'] ) ) {
			throw new ContractException( 'approval_required', 'The exact parent import launch is not durably authorized.' ); }
		$receipt = $this->ledger->receipt( $manifest['operation_id'], $job['actor_ref'] );
		if ( 'succeeded' !== ( $receipt['state'] ?? '' ) || ! hash_equals( $receipt['proposal_hash'] ?? '', $manifest['artifact']['hash'] ) || 1 !== count( $receipt['plan']['targets'] ?? array() ) || 'listingimport:' . $id !== $receipt['plan']['targets'][0]['key'] ) {
			throw new ContractException( 'approval_mismatch', 'No succeeded exact parent scheduling receipt exists.' ); }
		$parent = new EffectPlan( $manifest['operation_id'], $job['actor_ref'], 'listing.import', Limits::REVISION, $manifest['artifact']['hash'], array( new EffectTarget( 'listingimport:' . $id, $job['schema_hash'], $manifest['artifact']['hash'] ) ) );
		$this->authorize_launch( $context, $id, $parent, $for_write );
		if ( (int) $row['job_id'] !== (int) $job['id'] || ! isset( $manifest['approved_manifest']['record_hashes'][ (string) $row['ordinal'] ] ) || ! hash_equals( $manifest['approved_manifest']['record_hashes'][ (string) $row['ordinal'] ], $row['row_hash'] ?? '' ) ) {
			throw new ContractException( 'approval_mismatch', 'This record is not in the exact reviewed manifest.' ); }
		$staged = json_decode( $row['staged_json'], true, 64, JSON_THROW_ON_ERROR );
		$this->row_hash( $row, $staged );
		if ( ! $staged['valid'] ) {
			throw new ContractException( 'approval_mismatch', 'An invalid record cannot be delegated.' ); }
		if ( ! $for_write ) {
			return $job; }
		$fresh = ( new RecordValidator() )->validate( $context, $manifest['schema'], $manifest['mapping'], ImportStore::source_record( $row ), $manifest['policy'] );
		if ( ! $fresh['valid'] || ! hash_equals( $fresh['row_hash'], $row['row_hash'] ) ) {
			throw new ContractException( 'native_validation_changed', 'Native values, taxonomy or media changed since review.' ); }
		return $job;
	}

	public function process( string $id ): void {
		$actor_id = $this->store->actor_id( $id );
		if ( ! $actor_id ) {
			return; }
		$previous = get_current_user_id();
		wp_set_current_user( $actor_id );
		try {
			$this->process_owned( $id );
		} finally {
			wp_set_current_user( $previous ); }
	}

	private function process_owned( string $id ): void {
		$actor_id = $this->store->actor_id( $id );
		if ( ! $actor_id ) {
			return; }
		$context = new Context( Actor::user( $actor_id ), 'admin', 'worker' );
		$job     = $this->store->get( $context, $id );
		$lease   = $this->store->lease( $job );
		if ( ! $lease ) {
			return; }
		$next    = (int) $job['next_ordinal'];
		$state   = 'running';
		$started = microtime( true );
		try {
			foreach ( $this->store->rows( $job, $next, Limits::BATCH_RECORDS ) as $row ) {
				$current = $this->store->get( $context, $id );
				if ( (int) $current['cancel_requested'] ) {
					$state = 'cancelled';
					break; }
				$this->store->heartbeat( $job, $lease );
				if ( ! isset( $current['manifest']['approved_manifest']['record_hashes'][ (string) $row['ordinal'] ] ) ) {
					$next = (int) $row['ordinal'] + 1;
					continue; }
				if ( in_array( $row['state'], array( 'succeeded', 'skipped', 'failed' ), true ) ) {
					$next = (int) $row['ordinal'] + 1;
					continue; }
				$job       = $this->authorized( $context, $id, $row, false );
				$intent    = DirectorySchema::hash( array( $row['source_identity'], $row['row_hash'], $job['manifest']['policy'] ) );
				$operation = 'import-row-' . substr( $row['source_identity'], 0, 32 );
				try {
					$claim = $this->store->claim( $job, $row, $intent );
					if ( 'succeeded' === $claim['state'] ) {
						$existing_post = get_post( (int) $claim['listing_id'] );
						if ( ! $existing_post instanceof \WP_Post || (int) directorist_get_listing_directory( $existing_post->ID ) !== (int) $job['directory_id'] || get_post_meta( $existing_post->ID, '_directorist_ai_import_identity', true ) !== $row['source_identity'] || ! hash_equals( (string) get_post_meta( $existing_post->ID, '_directorist_ai_import_intent', true ), $intent ) ) {
							throw new ContractException( 'duplicate_ambiguous', 'The recognized source listing was deleted or changed. Review it manually.' ); }
						$this->store->result(
							$row,
							'skipped',
							array(
								'code'       => 'recognized_source_identity',
								'listing_id' => (int) $claim['listing_id'],
							),
							$operation,
							(int) $claim['listing_id']
						);
						$next = (int) $row['ordinal'] + 1;
						continue;
					}
					$plan   = new EffectPlan( $operation, $job['actor_ref'], 'listing.importrow', Limits::REVISION, $intent, array( new EffectTarget( 'importrow:' . $row['source_identity'], $job['schema_hash'], $intent ) ) );
					$effect = new RowEffect( $this, $this->store, $context, $id, $row, $claim, $lease );
					if ( 'claimed' === $claim['state'] && (int) $claim['job_id'] === (int) $job['id'] ) {
						$this->authorized( $context, $id, $row );
						$receipt = $this->ledger->execute( $plan, $effect ); } elseif ( (int) $claim['job_id'] === (int) $job['id'] ) {
						$receipt = $this->ledger->reconcile( $plan, $effect ); } else {
							throw new ContractException( 'duplicate_ambiguous', 'Another import owns this source identity with an unresolved outcome.' ); }
						$target  = array_values( $receipt['targets'] )[0] ?? array();
						$effects = (array) ( $target['effects'] ?? array() );
						$listing = (int) ( $effects['listing_id'] ?? 0 );
						$this->store->finish_claim( $claim, $receipt['state'], $receipt, $listing );
						$this->store->result( $row, $receipt['state'], $receipt, $operation, $listing );
						if ( 'unknown' === $receipt['state'] ) {
							$state = 'unknown';
							break; }
				} catch ( ContractException $error ) {
					if ( in_array( $error->error_code(), array( 'schema_changed', 'unauthorized', 'import_expired', 'emergency_stop', 'native_validation_changed', 'import_paused' ), true ) ) {
						if ( isset( $claim ) && (int) $claim['job_id'] === (int) $job['id'] ) {
							$this->store->release_unstarted_claim( $claim, $operation ); }
						$this->store->diagnostic( $job, $error->error_code(), $error->getMessage() );
						$state = 'paused';
						break;
					}
					$this->store->result(
						$row,
						'unknown_outcome' === $error->error_code() ? 'unknown' : 'failed',
						array(
							'code'    => $error->error_code(),
							'message' => $error->getMessage(),
						),
						$operation
					);
					if ( 'unknown_outcome' === $error->error_code() ) {
						$state = 'unknown';
						break; }
				}
				$next = (int) $row['ordinal'] + 1;
				if ( microtime( true ) - $started >= Limits::BATCH_SECONDS ) {
					break; }
			}
			if ( $next >= (int) $job['record_count'] ) {
				$counts = $this->store->counts( $job );
				$state  = ! empty( $counts['failed'] ) ? 'partial' : 'succeeded'; }
		} catch ( ContractException $error ) {
			$this->store->diagnostic( $job, $error->error_code(), $error->getMessage() );
			$state = 'paused'; } catch ( \Throwable $error ) {
			$state = 'unknown'; } finally {
				$this->store->release( $job, $lease, $state, $next ); }
			if ( 'running' === $state ) {
				$this->enqueue( $id ); }
	}

	/** Administrator requests a continuation; the queued worker derives all effects. */
	public function resume( Context $context, string $id ): array {
		$job = $this->store->get( $context, $id );
		if ( ! in_array( $job['state'], array( 'queued', 'running', 'unknown', 'paused' ), true ) || (int) $job['cancel_requested'] ) {
			throw new ContractException( 'import_state', 'Only an active approved import can continue.' ); }
		foreach ( $this->store->rows( $job, (int) $job['next_ordinal'], Limits::RECORDS ) as $row ) {
			if ( isset( $job['manifest']['approved_manifest']['record_hashes'][ (string) $row['ordinal'] ] ) ) {
				$this->authorized( $context, $id, $row, false );
				break; }
		}
		if ( 'paused' === $job['state'] ) {
			$this->fresh( $context, $job );
			$this->store->update( $job, $job['manifest'], 'queued' ); }
		$this->enqueue( $id );
		return $this->view( $context, $id );
	}

	/** Existing scheduler recovery hook calls this bounded, server-only scan. */
	public function recover(): void {
		foreach ( $this->store->runnable() as $job ) {
			$this->enqueue( $job['public_id'] ); } }
	public function cleanup(): void {
		$this->store->cleanup(); }

	public function cancel( Context $context, string $id ): array {
		$this->store->cancel( $this->store->get( $context, $id ) );
		return $this->view( $context, $id ); }

	public function report( Context $context, string $id ): string {
		$job  = $this->store->get( $context, $id );
		$file = fopen( 'php://temp', 'w+' );
		fputcsv( $file, array( 'record_id', 'ordinal', 'state', 'listing_id', 'source', 'diagnostic', 'import_result' ), ',', '"', '' );
		foreach ( $this->store->rows( $job, 0, Limits::RECORDS ) as $row ) {
			$diagnostic = $row['receipt_json'] ? $row['receipt_json'] : $row['staged_json'];
			$cells      = array( $row['public_id'], $row['ordinal'], $row['state'], $row['listing_id'] ?? '', $row['source_json'], $diagnostic, ImportPresentation::result_label( ImportPresentation::result_state( $job, $row ) ) );
			fputcsv( $file, array_map( array( self::class, 'csv_cell' ), $cells ), ',', '"', '' ); }
		rewind( $file );
		$csv = stream_get_contents( $file );
		fclose( $file );
		return $csv;
	}

	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return preg_match( '/^[\s\x00-\x1f]*[=+@-]/u', $value ) ? "'" . $value : $value; }

	private function editable( Context $context, string $id ): array {
		$job = $this->store->get( $context, $id );
		$this->fresh( $context, $job );
		if ( ! in_array( $job['state'], array( 'parsed', 'mapped', 'validating', 'validated', 'reviewed' ), true ) ) {
			throw new ContractException( 'import_immutable', 'A launched import is immutable. Stage a new source/review.' ); }
		return $job;
	}

	private function fresh( Context $context, array $job ): void {
		$this->db->readiness()->assert_ready();
		if ( (int) $job['cancel_requested'] ) {
			throw new ContractException( 'import_cancelled', 'This import was cancelled. Start a new import from the original source to continue.' ); }
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'emergency_stop', 'Import writes are paused by the site administrator.' ); }
		if ( strtotime( $job['expires_at'] . ' UTC' ) <= time() ) {
			throw new ContractException( 'import_expired', 'This private import expired. Stage it again and approve a new review.' ); }
		$schema = $this->schema->capture( $context, (int) $job['directory_id'] );
		if ( ! hash_equals( $schema['revision'], $job['schema_hash'] ) || ! hash_equals( $schema['environment_hash'], $job['environment_hash'] ) ) {
			throw new ContractException( 'schema_changed', 'The selected directory or dependency changed. Revalidate with a fresh source and review.' ); }
		if ( isset( $job['manifest']['policy'] ) && $job['manifest']['policy'] ) {
			$this->policy( $context, $schema, $job['manifest']['policy'] ); }
	}

	private function policy_defaults( Context $context, array $schema ): array {
		$status = directorist_get_listing_create_status( $schema['directory_id'] );
		$type   = get_post_type_object( ATBDP_POST_TYPE );
		if ( ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) || ( 'publish' === $status && ! $context->actor()->can( $type->cap->publish_posts ) ) ) {
			$status = 'pending'; }
		return array(
			'author_id'           => $context->actor()->user_id(),
			'status'              => $status,
			'accept_terms'        => false,
			'accept_privacy'      => false,
			'existing_media_only' => false,
			'duplicate_policy'    => 'skip_recognized_identity',
		);
	}

	private function policy( Context $context, array $schema, array $input ): array {
		if ( array_diff( array_keys( $input ), array( 'author_id', 'status', 'accept_terms', 'accept_privacy', 'existing_media_only', 'duplicate_policy' ) ) || ! is_int( $input['author_id'] ?? null ) || ! in_array( $input['status'] ?? null, array( 'draft', 'pending', 'publish' ), true ) || 'skip_recognized_identity' !== ( $input['duplicate_policy'] ?? 'skip_recognized_identity' ) ) {
			throw new ContractException( 'policy_invalid', 'Review an authorized existing author, native status, acceptance and create-only duplicate policy.' ); }
		$type   = get_post_type_object( ATBDP_POST_TYPE );
		$author = Actor::user( $input['author_id'] );
		if ( ! $author->valid() || ( $input['author_id'] !== $context->actor()->user_id() && ! $context->actor()->can( $type->cap->edit_others_posts ) ) ) {
			throw new ContractException( 'unauthorized', 'The selected author is unavailable or unauthorized.' ); }
		( new DirectoristInspector() )->creation_eligibility( new Context( $author, 'owner', 'worker' ), $schema['directory_id'] );
		$native_status = directorist_get_listing_create_status( $schema['directory_id'] );
		if ( 'publish' === $input['status'] && ( 'publish' !== $native_status || ! $context->actor()->can( $type->cap->publish_posts ) || ! $author->can( $type->cap->publish_posts ) ) ) {
			throw new ContractException( 'unauthorized', 'Publishing must be permitted by directory moderation and both actor/author capabilities.' ); }
		foreach ( array( 'accept_terms', 'accept_privacy', 'existing_media_only' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_bool( $input[ $key ] ) ) {
				throw new ContractException( 'policy_invalid', 'Acceptance and media policies must be explicit booleans.' ); }
		}
		return array(
			'author_id'           => $input['author_id'],
			'status'              => $input['status'],
			'accept_terms'        => $input['accept_terms'] ?? false,
			'accept_privacy'      => $input['accept_privacy'] ?? false,
			'existing_media_only' => $input['existing_media_only'] ?? false,
			'duplicate_policy'    => 'skip_recognized_identity',
		);
	}

	private function version( array $job, int $version ): void {
		if ( (int) $job['version'] !== $version ) {
			throw new ContractException( 'import_conflict', 'Reload the current import before changing its mapping or review.' ); } }
	private function row_hash( array $row, array $staged ): void {
		$hash = $staged['row_hash'] ?? '';
		unset( $staged['row_hash'] );
		if ( ! hash_equals( DirectorySchema::hash( $staged ), $hash ) || ! hash_equals( $row['row_hash'], $hash ) ) {
			throw new ContractException( 'approval_mismatch', 'Private staged record content changed after review.' ); } }
	private function enqueue( string $id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'directorist_ai_pro_import', array( 'job_id' => $id ), 'directorist-ai-pro', false, 10 ); } }
}
