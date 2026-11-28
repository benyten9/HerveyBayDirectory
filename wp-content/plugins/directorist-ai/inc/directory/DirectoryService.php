<?php
/**
 * Administrator-bound directory proposals use the shared review runtime.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Directory;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Approvals\ApprovalSpec;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed native workflow.
/** Native Directory Builder AI integration. */
final class DirectoryService {
	public function __construct( private Database $db ) {}

	public function authority( Context $context, int $id ): void {
		$context->assert_current();
		if ( 'admin' !== $context->profile() || $context->actor()->is_guest() || ! $context->actor()->can( 'manage_options' ) || get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'unauthorized', 'Directory assistance is available to site administrators.' ); }
		if ( $id && ! get_term( $id, ATBDP_DIRECTORY_TYPE ) instanceof \WP_Term ) {
			throw new ContractException( 'directory_unavailable', 'This directory no longer exists.' ); }
		if ( ! $id && ! directorist_is_multi_directory_enabled() ) {
			throw new ContractException( 'directory_mode_required', 'Enable multiple directories in Directorist settings before creating another directory.' ); }
		$this->db->readiness()->assert_ready();
	}

	public function bind( Context $context, object $input ): object {
		if ( ! is_int( $input->directory_id ?? null ) || $input->directory_id < 0 || ! ( $input->values ?? null ) instanceof \stdClass || strlen( wp_json_encode( $input->values ) ) > 196608 ) {
			throw new ContractException( 'invalid_input', 'The native directory context is invalid.' ); }
		$this->authority( $context, $input->directory_id );
		$schema = new DirectorySchema();
		$config = $schema->native( $input->directory_id );
		$values = json_decode( wp_json_encode( $input->values ), true );
		if ( array_diff( array_keys( $values ), array_merge( DirectorySchema::SECTIONS, DirectorySchema::SETTINGS, array( 'name' ) ) ) ) {
			throw new ContractException( 'invalid_input', 'The capture contains unsupported directory settings.' ); }
		foreach ( $values as $key => $value ) {
			$config[ $key ] = $value; }
		if ( ! is_string( $config['name'] ) || strlen( $config['name'] ) > 300 || wp_strip_all_tags( $config['name'] ) !== $config['name'] ) {
			throw new ContractException( 'invalid_input', 'The directory name is invalid.' ); }
		$schema->validate( $config );
		return (object) array(
			'directory_id' => $input->directory_id,
			'mode'         => $input->directory_id ? 'edit' : 'create',
			'config'       => json_decode( wp_json_encode( $config ) ),
			'editor_hash'  => DirectorySchema::hash( $values ),
			'source_hash'  => $schema->source_hash( $input->directory_id ),
			'schema_hash'  => $schema->environment_hash(),
			'area'         => is_string( $input->area ?? null ) ? sanitize_text_field( substr( $input->area, 0, 100 ) ) : '',
		);
	}

	public function bound( Context $context ): object {
		if ( ! $context->conversation_id() || ! $context->run_id() ) {
			throw new ContractException( 'unauthorized', 'A bound directory conversation is required.' ); }
		$row  = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $context->conversation_id() );
		$form = json_decode( $row['context_json'] )->directory_builder ?? null;
		if ( ! $form instanceof \stdClass ) {
			throw new ContractException( 'unauthorized', 'The directory conversation is unavailable.' ); }
		$this->assert_form( $context, $form );
		return $form;
	}

	public function assert_form( Context $context, object $form ): void {
		$this->authority( $context, $form->directory_id );
		$schema = new DirectorySchema();
		if ( ! hash_equals( $form->schema_hash, $schema->environment_hash() ) || ! hash_equals( $form->source_hash, $schema->source_hash( $form->directory_id ) ) ) {
			throw new ContractException( 'evidence_stale', 'This directory or its available controls changed. Start a fresh request.' ); }
	}

	public function inspect( Context $context ): object {
		$form    = $this->bound( $context );
		$catalog = ( new DirectorySchema() )->catalog( json_decode( wp_json_encode( $form->config ), true ) );
		return json_decode(
			wp_json_encode(
				array(
					'mode'    => $form->mode,
					'area'    => $form->area,
					'catalog' => $catalog,
				)
			)
		);
	}

	public function propose( Context $context, object $input ): object {
		$form     = $this->bound( $context );
		$schema   = new DirectorySchema();
		$base     = json_decode( wp_json_encode( $form->config ), true );
		$config   = $schema->compile( $base, $input, 'edit' === $form->mode );
		$existing = term_exists( $config['name'], ATBDP_DIRECTORY_TYPE );
		if ( $existing && (int) $existing['term_id'] !== $form->directory_id ) {
			throw new ContractException( 'invalid_directory_proposal', 'A directory with this name already exists. Ask for another name or edit that directory.' ); }
		if ( $form->directory_id && DirectorySchema::hash( $config ) === DirectorySchema::hash( $schema->normalize( $base ) ) ) {
			throw new ContractException( 'invalid_directory_proposal', 'The proposed configuration contains no changes.' ); }
		$capability = 'create' === $form->mode ? 'directory.create' : 'directory.editor_fill';
		$payload    = (object) array(
			'kind'      => 'directory_configuration',
			'form'      => $form,
			'config'    => json_decode( wp_json_encode( $config ) ),
			'summary'   => json_decode( wp_json_encode( $schema->summary( $config ) ) ),
			'before'    => json_decode( wp_json_encode( $schema->summary( $base ) ) ),
			'omissions' => $schema->placement_omissions( $base, $input ),
			'proposal'  => $input,
		);
		$run        = $this->db->rows( 'SELECT id,trigger_turn_id,checkpoint_json FROM ' . $this->db->table( 'runs' ) . ' WHERE public_id=%s AND owner_ref=%s', array( $context->run_id(), $context->actor()->owner()->reference() ) )[0];
		$checkpoint = json_decode( $run['checkpoint_json'] );
		// Identical later refinements need a fresh review after the earlier one is revoked.
		// Retries within this same accepted turn retain their original operation identity.
		$turn       = (int) ( $checkpoint->latest_turn_id ?? $run['trigger_turn_id'] );
		$identity   = hash( 'sha256', $context->run_id() . ':' . $turn . ':' . wp_json_encode( $payload ) );
		$operation  = 'directory-' . substr( $identity, 0, 32 );
		$artifact   = ( new ArtifactStore( $this->db ) )->put_once( $context->actor()->owner(), $context->conversation_id(), $operation, 'proposal', $payload, $form->schema_hash );
		$plan       = new EffectPlan( $operation, $context->actor()->owner()->reference(), $capability, '1', $artifact['content_hash'], array( new EffectTarget( 'directoryform:' . $context->actor()->user_id() . ':' . $form->directory_id, $form->source_hash, $artifact['content_hash'] ) ), (int) $run['id'] );
		$review     = ( new ApprovalService( $this->db ) )->create(
			$context->actor(),
			new ApprovalSpec(
				$plan,
				$artifact['public_id'],
				1,
				$artifact['content_hash'],
				$form->schema_hash,
				(object) array(
					'kind'         => 'directory_configuration',
					'mode'         => $form->mode,
					'directory_id' => $form->directory_id,
					'editor_hash'  => $form->editor_hash,
					'before'       => $payload->before,
					'after'        => $payload->summary,
					'omissions'    => $payload->omissions,
					'saved'        => 'create' === $form->mode,
				),
				(object) array(
					'max_targets' => 1,
					'max_fields'  => 100,
				)
			)
		);
		return (object) array(
			'capability'   => $capability,
			'operation_id' => $operation,
			'artifact'     => (object) array(
				'id'      => $artifact['public_id'],
				'version' => 1,
			),
			'review'       => (object) array(
				'id'          => $review['id'],
				'review_hash' => $review['review_hash'],
			),
		);
	}

	public function proposal_for_plan( EffectPlan $plan ): object {
		$artifacts = $this->db->table( 'artifacts' );
		$approvals = $this->db->table( 'approvals' );
		$rows      = $this->db->rows( "SELECT a.payload_json FROM $artifacts a INNER JOIN $approvals p ON p.artifact_id=a.public_id AND p.artifact_version=a.version WHERE p.actor_ref=%s AND p.capability=%s AND a.public_id=%s AND a.content_hash=%s AND a.owner_ref=p.actor_ref", array( $plan->actor_ref, $plan->capability, $plan->operation_id, $plan->proposal_hash ) );
		$payload   = $rows ? json_decode( $rows[0]['payload_json'] ) : null;
		if ( ! $payload || 'directory_configuration' !== $payload->kind ) {
			throw new ContractException( 'approval_mismatch', 'The reviewed directory proposal is unavailable.' ); }
		return $payload;
	}
}
