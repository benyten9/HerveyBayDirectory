<?php
/**
 * Reviewed directory creation and unsaved native-editor suggestions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Directory;

use Directorist\Multi_Directory\Multi_Directory_Manager;
use DirectoristAIPro\Approvals\EffectHandler;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Effects\EffectOutcome;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Native effect adapter.
/** Native Directory Builder AI integration. */
final class DirectoryEffect implements EffectHandler {
	public function __construct( private Database $db, private DirectoryService $service ) {}

	public function permitted( EffectPlan $plan, Context $context ): bool {
		try {
			if ( $plan->actor_ref !== $context->actor()->owner()->reference() || 1 !== count( $plan->targets ) ) {
				return false; }
			$p = $this->service->proposal_for_plan( $plan );
			$this->service->assert_form( $context, $p->form );
			return ( 'create' === $p->form->mode ? 'directory.create' : 'directory.editor_fill' ) === $plan->capability && 'directoryform:' . $context->actor()->user_id() . ':' . $p->form->directory_id === $plan->targets[0]->key;
		} catch ( \Throwable $error ) {
			return false; }
	}

	public function environment_hash( EffectPlan $plan ): string {
		return ( new DirectorySchema() )->environment_hash(); }

	private function context( EffectPlan $plan ): Context {
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT c.profile,c.public_id,r.public_id AS run_id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s", array( $plan->run_id, $plan->actor_ref ) );
		if ( ! $rows ) {
			throw new ContractException( 'unauthorized', 'The directory run is unavailable.' ); }
		return new Context( Actor::user( (int) substr( $plan->actor_ref, 5 ) ), $rows[0]['profile'], 'worker', $rows[0]['public_id'], $rows[0]['run_id'] );
	}

	public function commit( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$p = $this->service->proposal_for_plan( $plan );
		$this->service->assert_form( $this->context( $plan ), $p->form );
		if ( 'directory.editor_fill' === $plan->capability ) {
			return new EffectOutcome(
				'succeeded',
				$target->expected_version,
				$target->expected_version,
				(object) array(
					'config'       => $p->config,
					'editor_hash'  => $p->form->editor_hash,
					'directory_id' => $p->form->directory_id,
					'saved'        => false,
				)
			);
		}
		if ( $this->marked( $plan ) ) {
			return $this->reconcile( $plan, $target, $fence ); }
		$config = json_decode( wp_json_encode( $p->config ), true );
		( new DirectorySchema() )->validate( $config );
		if ( term_exists( $config['name'], ATBDP_DIRECTORY_TYPE ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, null, (object) array(), array(), 'directory_name_exists' ); }
		// Native hooks can have external effects. Record the new term before metadata writes;
		// an uncertain write is reconciled, never repeated against an unmarked existing term.
		$created = Multi_Directory_Manager::create_directory( array( 'directory_name' => $config['name'] ) );
		if ( empty( $created['status']['success'] ) || empty( $created['term_id'] ) ) {
			return new EffectOutcome( 'failed', $target->expected_version, null, (object) array(), array(), 'directory_creation_failed' ); }
		$id = (int) $created['term_id'];
		update_term_meta( $id, '_directorist_ai_directory_operation', $plan->operation_id );
		update_term_meta( $id, '_directorist_ai_directory_actor', $plan->actor_ref );
		update_term_meta( $id, '_directorist_ai_directory_hash', $plan->proposal_hash );
		$config = array_replace( ( new DirectorySchema() )->creation_defaults(), $config );
		unset( $config['name'] );
		$result = Multi_Directory_Manager::update_directory(
			array(
				'term_id'        => $id,
				'directory_name' => $p->config->name,
				'fields_value'   => $config,
			)
		);
		if ( empty( $result['status']['success'] ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'directory_id' => $id ), array(), 'directory_configuration_uncertain' ); }
		return $this->reconcile( $plan, $target, $fence );
	}

	private function marked( EffectPlan $plan ): int {
		$terms = get_terms(
			array(
				'taxonomy'   => ATBDP_DIRECTORY_TYPE,
				'hide_empty' => false,
				'number'     => 2,
				'fields'     => 'ids',
				'meta_key'   => '_directorist_ai_directory_operation',
				'meta_value' => $plan->operation_id,
			)
		);
		return ! is_wp_error( $terms ) && 1 === count( $terms ) ? (int) $terms[0] : 0;
	}

	public function reconcile( EffectPlan $plan, EffectTarget $target, int $fence ): EffectOutcome {
		$p = $this->service->proposal_for_plan( $plan );
		if ( 'directory.editor_fill' === $plan->capability ) {
			return $this->commit( $plan, $target, $fence ); }
		$id = $this->marked( $plan );
		if ( ! $id || get_term_meta( $id, '_directorist_ai_directory_actor', true ) !== $plan->actor_ref || get_term_meta( $id, '_directorist_ai_directory_hash', true ) !== $plan->proposal_hash ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array(), array(), 'directory_marker_unavailable' ); }
		$schema           = new DirectorySchema();
		$actual           = $schema->normalize( $schema->native( $id ) );
		$expected         = json_decode( wp_json_encode( $p->config ), true );
		$expected['name'] = esc_attr( $expected['name'] );
		if ( DirectorySchema::hash( $expected ) !== DirectorySchema::hash( $actual ) ) {
			return new EffectOutcome( 'unknown', $target->expected_version, null, (object) array( 'directory_id' => $id ), array(), 'directory_readback_mismatch' ); }
		return new EffectOutcome(
			'succeeded',
			$target->expected_version,
			$schema->source_hash( $id ),
			(object) array(
				'directory_id' => $id,
				'saved'        => true,
				'edit_url'     => admin_url( 'edit.php?post_type=at_biz_dir&page=' . ( directorist_is_multi_directory_enabled() ? 'atbdp-directory-types' : 'atbdp-layout-builder' ) . '&action=edit&listing_type_id=' . $id ),
			),
			array(
				array(
					'kind'  => 'native_directory_hooks',
					'state' => 'dispatched',
				),
			)
		);
	}
}
