<?php
/**
 * Separate reviewed publication of an owned native builder page draft.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Closed reviewed publication boundary.

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Separately reviewed page publication and restoration preserve the native layout. */
final class BuilderPagePublication implements EffectHandler {
	private const MARKER         = '_directorist_ai_page_publication';
	private const RESTORE_MARKER = '_directorist_ai_page_restoration';
	private const SAVEPOINT      = 'dai_native_page_publication';
	private Database $db;
	private ArtifactStore $artifacts;
	private ApprovalService $approvals;
	private CatalogEnvironmentReader $environment;
	private BuilderPagePublicationRegistry $adapters;
	public function __construct( Database $db, CatalogEnvironmentReader $environment, ?BuilderPagePublicationRegistry $adapters = null ) {
		$this->db          = $db;
		$this->artifacts   = new ArtifactStore( $db );
		$this->approvals   = new ApprovalService( $db );
		$this->environment = $environment;
		$this->adapters    = $adapters ?? BuilderPagePublicationRegistry::installed();
	}

	/** Prepare exact publication wording and a private native checkpoint. */
	public function propose( Context $context, object $input ): object {
		$context->assert_execution_identity();
		$keys = array_keys( get_object_vars( $input ) );
		sort( $keys, SORT_STRING );
		if ( array( 'draft_operation_id', 'request_id', 'title' ) !== $keys || ! is_string( $input->request_id ) || ! is_string( $input->draft_operation_id ) || ! is_string( $input->title ) || '' === trim( $input->title ) || strlen( $input->title ) > 200 || 1 !== preg_match( '//u', $input->title ) || preg_match( '/[<>\x00-\x1f\x7f]/', $input->title ) || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || $context->actor()->is_guest() || get_current_user_id() !== $context->actor()->user_id() || null === $context->conversation_id() || null === $context->run_id() ) {
			throw new ContractException( 'invalid_input', 'Page publication needs an authenticated workflow and a bounded plain-text title.' );
		}
		GraphVersion::identifier( $input->request_id );
		GraphVersion::identifier( $input->draft_operation_id );
		$draft   = $this->draft( $input->draft_operation_id );
		$id      = $draft->ID;
		$builder = $this->adapters->qualify( $id, $input->draft_operation_id );
		$this->assert_transactional_tables();
		$this->authorize( $id );
		$this->assert_selection( $context, $id, $builder );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.state='running' AND r.cancel_requested=0", array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Publication requires its current owning run.' );
		}
		$before     = $this->snapshot( $id );
		$dependency = $this->adapters->dependency_version( $builder, $id );
		$title      = trim( $input->title );
		$slug       = sanitize_title( $title );
		$slug       = wp_unique_post_slug( $slug, $id, 'publish', 'page', 0 );
		if ( '' === $slug ) {
			throw new ContractException( 'invalid_input', 'Publication title must produce a usable page slug.' );
		}
		$effect      = (object) array(
			'post_id' => $id,
			'status'  => 'publish',
			'title'   => $title,
			'slug'    => $slug,
			'parent'  => 0,
		);
		$operation   = 'builder-publish-' . substr( hash( 'sha256', $context->run_id() . "\0" . $input->request_id ), 0, 32 );
		$environment = $this->environment->read()->fingerprint();
		$payload     = (object) array(
			'kind'               => 'builder_page_publication',
			'draft_operation_id' => $input->draft_operation_id,
			'builder'            => $builder,
			'dependency_version' => $dependency,
			'before'             => $before,
			'effect'             => $effect,
			'run_id'             => (int) $rows[0]['id'],
		);
		$artifact    = $this->artifacts->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'proposal', $payload, $environment );
		$target      = new EffectTarget( 'builder-page:' . $id, $this->hash( $before ), $this->hash( $effect ) );
		$plan        = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'builder.publish', '3', $artifact['content_hash'], array( $target ), (int) $rows[0]['id'] );
		$review      = $this->approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$operation,
				(int) $artifact['version'],
				$artifact['content_hash'],
				$environment,
				(object) array(
					'kind'                => 'builder_page_publish',
					'builder'             => $builder,
					'page_id'             => $id,
					'title'               => $title,
					'slug'                => $slug,
					'parent_id'           => 0,
					'before_status'       => 'draft',
					'after_status'        => 'publish',
					'content_hash'        => hash( 'sha256', 'elementor' === $builder ? (string) get_post_meta( $id, '_elementor_data', true ) : $before->post->post_content ),
					'native_state_hash'   => $this->hash( $before ),
					'dependency_version'  => $dependency,
					'template_assignment' => 'inapplicable',
					'replaces_source'     => false,
				),
				(object) array( 'max_pages' => 1 )
			)
		);
		try {
			$this->draft( $input->draft_operation_id );
			$this->authorize( $id );
			$this->assert_selection( $context, $id, $builder );
			if ( ! hash_equals( $dependency, $this->adapters->dependency_version( $builder, $id ) ) || ! hash_equals( $target->expected_version, $this->hash( $this->snapshot( $id ) ) ) || ! hash_equals( $environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Page or native environment changed while preparing publication.' );
			}
		} catch ( \Throwable $error ) {
			$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;
		}
		$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $operation,
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
			),
			'page_id'      => $id,
		);
	}

	/** Restore only the title/slug/parent/status changed by our exact publication, through a new review. */
	public function propose_restore( Context $context, object $input ): object {
		$context->assert_execution_identity();
		$keys = array_keys( get_object_vars( $input ) );
		sort( $keys, SORT_STRING );
		if ( array( 'publication_operation_id', 'request_id' ) !== $keys || ! is_string( $input->request_id ) || ! is_string( $input->publication_operation_id ) || ! in_array( $context->profile(), array( 'admin', 'builder' ), true ) || ! in_array( $context->transport(), array( 'ui', 'worker' ), true ) || $context->actor()->is_guest() || get_current_user_id() !== $context->actor()->user_id() || null === $context->conversation_id() || null === $context->run_id() ) {
			throw new ContractException( 'invalid_input', 'Publication restore requires its authenticated owning workflow.' );
		}
		GraphVersion::identifier( $input->request_id );
		GraphVersion::identifier( $input->publication_operation_id );
		$origin = $this->restore_origin( $input->publication_operation_id );
		$id     = $origin->effect->post_id;
		$this->assert_transactional_tables();
		$this->authorize( $id );
		$this->assert_selection( $context, $id, $origin->builder );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id AND c.active_run_id=r.id WHERE r.public_id=%s AND c.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.state='running' AND r.cancel_requested=0", array( $context->run_id(), $context->conversation_id(), $context->actor()->owner()->reference() ) );
		if ( 1 !== count( $rows ) ) {
			throw new ContractException( 'unauthorized', 'Publication restore requires its current owning run.' );
		}
		$before      = $this->snapshot( $id );
		$previous    = $origin->before->post;
		$effect      = (object) array(
			'post_id' => $id,
			'status'  => 'draft',
			'title'   => $previous->post_title,
			'slug'    => $previous->post_name,
			'parent'  => (int) $previous->post_parent,
		);
		$operation   = 'builder-restore-' . substr( hash( 'sha256', $context->run_id() . "\0" . $input->request_id ), 0, 32 );
		$environment = $this->environment->read()->fingerprint();
		$dependency  = $this->adapters->dependency_version( $origin->builder, $id );
		$payload     = (object) array(
			'kind'                     => 'builder_page_restoration',
			'publication_operation_id' => $input->publication_operation_id,
			'draft_operation_id'       => $origin->draft_operation_id,
			'builder'                  => $origin->builder,
			'dependency_version'       => $dependency,
			'before'                   => $before,
			'effect'                   => $effect,
			'run_id'                   => (int) $rows[0]['id'],
		);
		$artifact    = $this->artifacts->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'proposal', $payload, $environment );
		$target      = new EffectTarget( 'builder-page:' . $id, $this->hash( $before ), $this->hash( $effect ) );
		$plan        = new EffectPlan( $operation, $context->actor()->owner()->reference(), 'builder.restore', '1', $artifact['content_hash'], array( $target ), (int) $rows[0]['id'] );
		$review      = $this->approvals->create_staged(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$operation,
				(int) $artifact['version'],
				$artifact['content_hash'],
				$environment,
				(object) array(
					'kind'                     => 'builder_page_restore',
					'builder'                  => $origin->builder,
					'page_id'                  => $id,
					'title'                    => $effect->title,
					'slug'                     => $effect->slug,
					'parent_id'                => $effect->parent,
					'before_status'            => 'publish',
					'after_status'             => 'draft',
					'publication_operation_id' => $input->publication_operation_id,
					'content_changed'          => false,
					'template_assignment'      => 'inapplicable',
					'external_effects'         => 'not_reversible',
				),
				(object) array( 'max_pages' => 1 )
			)
		);
		try {
			$this->restore_origin( $input->publication_operation_id );
			$this->authorize( $id );
			$this->assert_selection( $context, $id, $origin->builder );
			if ( ! hash_equals( $target->expected_version, $this->hash( $this->snapshot( $id ) ) ) || ! hash_equals( $dependency, $this->adapters->dependency_version( $origin->builder, $id ) ) || ! hash_equals( $environment, $this->environment->read()->fingerprint() ) ) {
				throw new ContractException( 'stale_version', 'Published page changed while preparing its restoration.' );
			}
		} catch ( \Throwable $error ) {
			$this->approvals->revoke_unreserved( $context->actor(), $review['id'], $review['review_hash'] );
			throw $error;
		}
		$review = $this->approvals->activate_staged( $context->actor(), $review['id'], $review['review_hash'] );
		return (object) array(
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $operation,
				'version' => (int) $artifact['version'],
				'hash'    => $artifact['content_hash'],
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'state'       => $review['state'],
				'review_hash' => $review['review_hash'],
			),
			'page_id'      => $id,
		);
	}

	/** A current owned receipt plus its hash-verified private preimage is the only restore source. */
	private function restore_origin( string $operation ): object {
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/publications/' . $operation );
		$request->set_param( 'operation_id', $operation );
		$current = ( new BuilderDraftResultApi( $this->db ) )->publication( $request );
		if ( ! is_array( $current ) || true !== ( $current['reviewed_state_current'] ?? null ) || 'publish' !== ( $current['status'] ?? null ) ) {
			throw new ContractException( 'stale_version', 'Publication restore refuses newer native edits or an unavailable owned receipt.' );
		}
		$row        = $this->artifacts->get( Actor::user( get_current_user_id() )->owner(), $operation, 1 );
		$operations = $this->db->table( 'operations' );
		$receipts   = $this->db->rows( "SELECT receipt_json FROM $operations WHERE public_id=%s AND actor_ref=%s AND capability='builder.publish' AND capability_revision='3' AND state='succeeded' LIMIT 2", array( $operation, 'user:' . get_current_user_id() ) );
		$receipt    = 1 === count( $receipts ) ? ( new JsonDecoder( 1048576, 32 ) )->decode( $receipts[0]['receipt_json'] ) : null;
		$payload    = ( new JsonDecoder( 5242880, 64 ) )->decode( $row['payload_json'] );
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || ( $receipt->proposal_hash ?? null ) !== $row['content_hash'] || ! $payload instanceof \stdClass || 'builder_page_publication' !== ( $payload->kind ?? null ) || ( $payload->effect->post_id ?? null ) !== $current['page_id'] || 'draft' !== ( $payload->before->post->post_status ?? null ) || $this->hash( $payload->before ) !== ( $receipt->plan->targets[0]->expected_version ?? null ) || $this->hash( $payload->effect ) !== ( $receipt->plan->targets[0]->effect_hash ?? null ) ) {
			throw new ContractException( 'evidence_stale', 'Publication restore checkpoint is unavailable or changed.' );
		}
		return $payload;
	}

	public function permitted( EffectPlan $plan, Context $context ): bool {
		if ( $context->actor()->is_guest() || $plan->actor_ref !== $context->actor()->owner()->reference() || get_current_user_id() !== $context->actor()->user_id() ) {
			return false;
		}
		try {
			$loaded = $this->load( $plan );
			$this->authorize( $loaded->payload->effect->post_id );
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	public function environment_hash( EffectPlan $plan ): string {
		$this->load( $plan );
		return $this->environment->read()->fingerprint();
	}

	/** Native publication is performed once, followed by independent raw readback. */
	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded = $this->load( $plan );
		$this->assert_target( $plan, $target, $loaded->payload );
		$id = $loaded->payload->effect->post_id;
		global $wpdb;
		$lock = 'dai-page-publish-' . get_current_blog_id() . '-' . $id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), 'busy' );
		}
		$native_started = false;
		try {
			try {
				$this->begin_native_write( $id );
				$native_started = true;
			} catch ( ContractException $error ) {
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
			}
			try {
				$this->authorize( $id );
				if ( 'builder.restore' === $plan->capability ) {
					$this->restore_origin( $loaded->payload->publication_operation_id );
				} else {
					$this->draft( $loaded->payload->draft_operation_id );
				}
				if ( $loaded->payload->builder !== $this->adapters->qualify( $id, $loaded->payload->draft_operation_id ) ) {
					throw new ContractException( 'stale_version', 'Reviewed native builder identity changed.' );
				}
				if ( ! hash_equals( $loaded->payload->dependency_version, $this->adapters->dependency_version( $loaded->payload->builder, $id ) ) || ! hash_equals( $loaded->environment, $this->environment->read()->fingerprint() ) || ! hash_equals( $target->expected_version, $this->hash( $this->snapshot( $id ) ) ) ) {
					throw new ContractException( 'stale_version', 'Reviewed page changed before publication.' );
				}
			} catch ( ContractException $error ) {
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), array(), $error->error_code() );
			}
			$effect = $loaded->payload->effect;
			clean_post_cache( $id );
			try {
				$saved = BuilderTemplateDraftSaveGuard::publish_reviewed(
					$id,
					static fn() => wp_update_post(
						wp_slash(
							array(
								'ID'          => $id,
								'post_status' => $effect->status,
								'post_title'  => $effect->title,
								'post_name'   => $effect->slug,
								'post_parent' => $effect->parent,
							)
						),
						true
					)
				);
			} catch ( \Throwable $error ) {
				return $this->unknown( $target, 'native_publication_interrupted' );
			}
			$after = $this->snapshot( $id );
			try {
				$this->adapters->assert_ready( $loaded->payload->builder, $id, $loaded->payload->draft_operation_id );
			} catch ( \Throwable $error ) {
				return $this->unknown( $target, 'native_builder_changed_during_publication' );
			}
			if ( is_wp_error( $saved ) && hash_equals( $target->expected_version, $this->hash( $after ) ) ) {
				return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), $this->external( false ), 'native_publication_rejected' );
			}
			if ( ! hash_equals( $loaded->payload->dependency_version, $this->adapters->dependency_version( $loaded->payload->builder, $id ) ) || ! $this->matches_published( $loaded->payload, $after ) ) {
				return $this->unknown( $target, 'native_publication_mismatch' );
			}
			$marker = array(
				'operation_id'       => $plan->operation_id,
				'plan_hash'          => $plan->hash(),
				'version'            => $this->hash( $after ),
				'fence'              => $fence,
				'builder'            => $loaded->payload->builder,
				'dependency_version' => $loaded->payload->dependency_version,
				'draft_operation_id' => $loaded->payload->draft_operation_id,
			);
			update_post_meta( $id, 'builder.restore' === $plan->capability ? self::RESTORE_MARKER : self::MARKER, $marker );
			if ( get_post_meta( $id, 'builder.restore' === $plan->capability ? self::RESTORE_MARKER : self::MARKER, true ) !== $marker || ! hash_equals( $marker['version'], $this->hash( $this->snapshot( $id ) ) ) ) {
				return $this->unknown( $target, 'native_publication_marker_unverified' );
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', self::SAVEPOINT ) ) || false === $wpdb->query( 'COMMIT' ) ) {
				return $this->unknown( $target, 'native_publication_transaction_lost' );
			}
			clean_post_cache( $id );
			if ( ! hash_equals( $loaded->payload->dependency_version, $this->adapters->dependency_version( $loaded->payload->builder, $id ) ) || ! hash_equals( $marker['version'], $this->hash( $this->snapshot( $id ) ) ) ) {
				return $this->unknown( $target, 'page_changed_after_publication' );
			}
			return $this->success( $target, $id, $marker['version'], $fence, false, $loaded->payload );
		} finally {
			// A successful commit has removed this savepoint. Never roll back a
			// transaction started by unrelated hook code after an implicit commit.
			$previous_errors = $wpdb->suppress_errors();
			if ( $native_started && false !== $wpdb->query( $wpdb->prepare( 'ROLLBACK TO SAVEPOINT %i', self::SAVEPOINT ) ) ) {
				$wpdb->query( 'ROLLBACK' );
			}
			$wpdb->suppress_errors( $previous_errors );
			clean_post_cache( $id );
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/** A lost response is reconciled by markers and exact current state, never another save. */
	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$loaded = $this->load( $plan );
		$this->assert_target( $plan, $target, $loaded->payload );
		$id = $loaded->payload->effect->post_id;
		$this->authorize( $id );
		$this->adapters->assert_ready( $loaded->payload->builder, $id, $loaded->payload->draft_operation_id );
		$after  = $this->snapshot( $id );
		$marker = get_post_meta( $id, 'builder.restore' === $plan->capability ? self::RESTORE_MARKER : self::MARKER, true );
		if ( hash_equals( $target->expected_version, $this->hash( $after ) ) && hash_equals( $loaded->payload->dependency_version, $this->adapters->dependency_version( $loaded->payload->builder, $id ) ) && ( ! is_array( $marker ) || ( $marker['operation_id'] ?? null ) !== $plan->operation_id ) ) {
			// This proves current database state equals the reviewed preimage. It
			// cannot prove a hook never fired or reverse an external notification.
			return new EffectOutcome( 'failed', $target->expected_version, $target->expected_version, (object) array(), $this->external( false ), 'publication_database_effect_absent' );
		}
		if ( is_array( $marker ) && ( $marker['operation_id'] ?? null ) === $plan->operation_id && ( $marker['plan_hash'] ?? null ) === $plan->hash() && ( $marker['builder'] ?? null ) === $loaded->payload->builder && ( $marker['dependency_version'] ?? null ) === $loaded->payload->dependency_version && ( $marker['draft_operation_id'] ?? null ) === $loaded->payload->draft_operation_id && ( $marker['version'] ?? null ) === $this->hash( $after ) && hash_equals( $loaded->payload->dependency_version, $this->adapters->dependency_version( $loaded->payload->builder, $id ) ) && $this->matches_published( $loaded->payload, $after ) ) {
			return $this->success( $target, $id, $marker['version'], $fence, true, $loaded->payload );
		}
		return $this->unknown( $target, 'publication_marker_absent_or_changed' );
	}

	private function draft( string $operation ): \WP_Post {
		$request = new \WP_REST_Request( 'GET', '/directorist-ai/v1/builder/drafts/' . $operation );
		$request->set_param( 'operation_id', $operation );
		$result = ( new BuilderDraftResultApi( $this->db ) )->read( $request );
		$post   = is_array( $result ) ? get_post( (int) $result['draft_post_id'] ) : null;
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || true !== ( $result['reviewed_content_current'] ?? null ) || ! is_array( $result['refinement_source'] ?? null ) || ( $result['refinement_source']['operation_id'] ?? null ) !== $operation || ( $result['refinement_source']['document']->native_kind ?? null ) !== 'page' ) {
			throw new ContractException( 'evidence_stale', 'Publication needs an unchanged owned native page draft and its successful stage receipt.' );
		}
		$this->adapters->qualify( $post->ID, $operation );
		return $post;
	}

	private function authorize( int $id ): void {
		$type = get_post_type_object( 'page' );
		if ( get_current_user_id() < 1 || ! $type instanceof \WP_Post_Type || ! current_user_can( 'edit_post', $id ) || ! current_user_can( $type->cap->publish_posts ) || 'page' !== get_post_type( $id ) || get_current_user_id() !== (int) get_post_field( 'post_author', $id ) ) {
			throw new ContractException( 'unauthorized', 'Page publication requires the current author and native publish authority.' );
		}
	}

	private function assert_selection( Context $context, int $id, string $builder ): void {
		$selection = BuilderConversationTarget::active_context( $this->db, $context );
		if ( null === $selection ) {
			throw new ContractException( 'unauthorized', 'Publication has no selected document.' );
		}
		$this->assert_selected_json( $selection, $id, $builder );
	}

	private function assert_selected_json( string $selection, int $id, string $builder ): void {
		$binding = ( new JsonDecoder( 65536, 12 ) )->decode( $selection );
		if ( ! $binding instanceof \stdClass || ( $binding->target ?? null ) !== 'post:' . $id || ! ( $binding->builder_document ?? null ) instanceof \stdClass ) {
			throw new ContractException( 'unauthorized', 'Publication requires an explicit selected native page.' );
		}
		BuilderConversationTarget::assert_binding(
			$selection,
			$builder . '-page:' . $id,
			(object) array(
				'builder'     => $builder,
				'native_kind' => 'page',
				'native_id'   => 'post:' . $id,
			)
		);
	}

	private function load( EffectPlan $plan ): object {
		if ( ! ( ( 'builder.publish' === $plan->capability && '3' === $plan->capability_revision ) || ( 'builder.restore' === $plan->capability && '1' === $plan->capability_revision ) ) || 1 !== count( $plan->targets ) || null === $plan->run_id || ! preg_match( '/^user:([1-9][0-9]*)$/D', $plan->actor_ref, $match ) || get_current_user_id() !== (int) $match[1] ) {
			throw new ContractException( 'approval_mismatch', 'Page publication plan is invalid.' );
		}
		$row     = $this->artifacts->get( Actor::user( (int) $match[1] )->owner(), $plan->operation_id, 1 );
		$payload = ( new JsonDecoder( 5242880, 64 ) )->decode( $row['payload_json'] );
		if ( 'proposal' !== $row['kind'] || ! hash_equals( $plan->proposal_hash, $row['content_hash'] ) || ! hash_equals( $row['content_hash'], hash( 'sha256', $row['payload_json'] ) ) || ! $payload instanceof \stdClass || ( 'builder.restore' === $plan->capability ? 'builder_page_restoration' : 'builder_page_publication' ) !== ( $payload->kind ?? null ) || ! in_array( $payload->builder ?? null, array( 'gutenberg', 'elementor', 'divi' ), true ) || ! is_string( $payload->dependency_version ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $payload->dependency_version ) || ! is_int( $payload->effect->post_id ?? null ) || ( 'builder.restore' === $plan->capability ? ( ! is_int( $payload->effect->parent ?? null ) || $payload->effect->parent < 0 || 'draft' !== ( $payload->effect->status ?? null ) || ! is_string( $payload->publication_operation_id ?? null ) ) : 0 !== ( $payload->effect->parent ?? null ) ) || ( $payload->run_id ?? null ) !== $plan->run_id ) {
			throw new ContractException( 'evidence_stale', 'Reviewed publication artifact is unavailable.' );
		}
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$bindings      = $this->db->rows( "SELECT c.context_json FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.id=%d AND c.id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.cancel_requested=0", array( $plan->run_id, (int) $row['conversation_id'], $plan->actor_ref ) );
		if ( 1 !== count( $bindings ) ) {
			throw new ContractException( 'unauthorized', 'Publication originating run is unavailable.' );
		}
		$this->assert_selected_json( $bindings[0]['context_json'], $payload->effect->post_id, $payload->builder );
		return (object) array(
			'payload'     => $payload,
			'environment' => $row['environment_hash'],
		);
	}

	private function assert_target( EffectPlan $plan, EffectTarget $target, object $payload ): void {
		$stored = $plan->targets[0];
		if ( $stored->key !== $target->key || $stored->expected_version !== $target->expected_version || $stored->effect_hash !== $target->effect_hash || ( 'builder-page:' . $payload->effect->post_id ) !== $target->key || ! hash_equals( $target->expected_version, $this->hash( $payload->before ) ) || ! hash_equals( $target->effect_hash, $this->hash( $payload->effect ) ) ) {
			throw new ContractException( 'approval_mismatch', 'Publication target differs from the exact review.' );
		}
	}

	/** Raw native state avoids stale object-cache observations at the effect boundary. */
	public static function native_version( int $id ): string {
		return hash( 'sha256', ( new JsonCanonicalizer() )->encode( self::snapshot( $id ) ) );
	}

	private static function snapshot( int $id ): object {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d", $id ) );
		if ( ! $post instanceof \stdClass || '' !== $wpdb->last_error ) {
			throw new ContractException( 'stale_version', 'Publication page is unavailable.' );
		}
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key NOT IN (%s,%s) ORDER BY meta_key,meta_id", $id, self::MARKER, self::RESTORE_MARKER ) );
		if ( '' !== $wpdb->last_error || count( $meta ) > 2000 || strlen( wp_json_encode( array( $post, $meta ) ) ) > 4194304 ) {
			throw new ContractException( 'needs_expansion', 'Publication checkpoint exceeds its native bound.' );
		}
		$terms = $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id,term_order FROM {$wpdb->term_relationships} WHERE object_id=%d ORDER BY term_taxonomy_id", $id ) );
		if ( '' !== $wpdb->last_error || count( $terms ) > 2000 ) {
			throw new ContractException( 'needs_expansion', 'Publication term checkpoint is unavailable or exceeds its native bound.' );
		}
		return (object) array(
			'post'  => $post,
			'meta'  => $meta,
			'terms' => $terms,
		);
	}

	private function matches_published( object $payload, object $after ): bool {
		if ( $payload->effect->status !== $after->post->post_status || $payload->effect->title !== $after->post->post_title || $payload->effect->slug !== $after->post->post_name || $payload->effect->parent !== (int) $after->post->post_parent || $this->hash( $payload->before->meta ) !== $this->hash( $after->meta ) || $this->hash( $payload->before->terms ) !== $this->hash( $after->terms ) ) {
			return false;
		}
		$before_post = clone $payload->before->post;
		$after_post  = clone $after->post;
		foreach ( array( 'post_status', 'post_title', 'post_name', 'post_parent', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'guid' ) as $key ) {
			unset( $before_post->{$key}, $after_post->{$key} );
		}
		return hash_equals( $this->hash( $before_post ), $this->hash( $after_post ) );
	}

	private function hash( $value ): string {
		return hash( 'sha256', ( new JsonCanonicalizer() )->encode( $value ) );
	}

	/** Refuse engines that cannot protect native publication against other writers. */
	private function assert_transactional_tables(): void {
		global $wpdb;
		$engines = $wpdb->get_col( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s,%s)', $wpdb->posts, $wpdb->postmeta, $wpdb->term_relationships ) );
		if ( '' !== $wpdb->last_error || 3 !== count( $engines ) || array_filter( $engines, static fn( string $engine ): bool => 'innodb' !== strtolower( $engine ) ) ) {
			throw new ContractException( 'unsupported_dependency', 'Reviewed page publication requires transactional native post, metadata and term relationship tables.' );
		}
	}

	/** Lock the native row and its complete metadata range on WordPress's connection. */
	private function begin_native_write( int $id ): void {
		$this->assert_transactional_tables();
		global $wpdb;
		$previous_errors = $wpdb->suppress_errors();
		$started         = false;
		try {
			// SET TRANSACTION without SESSION is rejected inside an existing
			// transaction, so START TRANSACTION cannot implicitly commit it.
			if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new ContractException( 'unsupported_dependency', 'Native publication cannot acquire its isolated transaction.' );
			}
			$started = true;
			if ( false === $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', self::SAVEPOINT ) ) || false === $wpdb->query( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $id ) ) || false === $wpdb->query( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE", $id ) ) || false === $wpdb->query( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id=%d FOR UPDATE", $id ) ) ) {
				throw new ContractException( 'storage_error', 'Native publication could not lock its complete page checkpoint.' );
			}
		} catch ( \Throwable $error ) {
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		} finally {
			$wpdb->suppress_errors( $previous_errors );
		}
	}

	private function external( bool $verified = true ): array {
		return array(
			array(
				'kind'  => 'wordpress_post_update_hooks',
				'state' => $verified ? 'fired_not_reversible' : 'unverified_not_reversible',
			),
		);
	}

	private function unknown( EffectTarget $target, string $diagnostic ): EffectOutcome {
		return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), $this->external( false ), $diagnostic );
	}

	private function success( EffectTarget $target, int $id, string $version, int $fence, bool $reconciled, object $payload ): EffectOutcome {
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$version,
			(object) array(
				'page_id'            => $id,
				'status'             => $payload->effect->status,
				'fence'              => $fence,
				'reconciled'         => $reconciled,
				'builder'            => $payload->builder,
				'dependency_version' => $payload->dependency_version,
				'draft_operation_id' => $payload->draft_operation_id,
			),
			$this->external()
		);
	}
}
