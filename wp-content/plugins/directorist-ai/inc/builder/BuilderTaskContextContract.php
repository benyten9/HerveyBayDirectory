<?php
/**
 * Canonical context-packet identity and deterministic size metrics.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact packet boundary.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

/** Packet completeness is derived and hash-bound, never accepted from request data. */
final class BuilderTaskContextContract {
	private JsonCanonicalizer $canonical;

	public function __construct() {
		$this->canonical = new JsonCanonicalizer();
	}

	/** Finalize identity and fixed-point packet byte metrics. */
	public function finalize( array $payload ): object {
		unset( $payload['context_id'], $payload['metrics'] );
		$content_bytes         = strlen( $this->canonical->encode( (object) $payload ) );
		$payload['metrics']    = (object) array(
			'content_bytes'    => $content_bytes,
			'estimated_tokens' => intdiv( $content_bytes + 3, 4 ),
			'token_estimator'  => 'utf8-bytes-ceil-div-4-v1',
			'packet_bytes'     => 0,
		);
		$payload['context_id'] = hash( 'sha256', $this->canonical->encode( (object) $payload ) );
		for ( $iteration = 0; $iteration < 4; ++$iteration ) {
			$bytes = strlen( $this->canonical->encode( (object) $payload ) );
			if ( $bytes === $payload['metrics']->packet_bytes ) {
				break;
			}
			$payload['metrics']->packet_bytes = $bytes;
		}
		$packet = json_decode( json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
		return $this->validate( $packet );
	}

	/**
	 * Verify shape, closure claims, identity and deterministic metrics.
	 *
	 * @throws ContractException Packet contract or integrity validation failed.
	 */
	public function validate( object $packet ): object {
		( new JsonDecoder( 65536, 24 ) )->check( $packet );
		$this->closed( $packet, array( 'contract', 'context_id', 'authority', 'task', 'component_cards', 'graph_region', 'fresh_facts', 'closure', 'omissions', 'read_more', 'metrics', 'requires_full_server_validation' ) );
		if ( 'directorist-ai/builder-task-context-v1' !== $packet->contract || true !== $packet->requires_full_server_validation || ! preg_match( '/^[a-f0-9]{64}$/D', $packet->context_id ) || ! is_array( $packet->component_cards ) || count( $packet->component_cards ) > 30 || ! is_array( $packet->omissions ) || count( $packet->omissions ) > 200 || ! is_array( $packet->read_more ) || count( $packet->read_more ) > 200 ) {
			throw new ContractException( 'invalid_output', 'Builder task-context packet is invalid.' );
		}
		$this->closed( $packet->closure, array( 'state', 'required_node_ids', 'reasons', 'missing_dependencies' ) );
		if ( ! in_array( $packet->closure->state, array( 'complete_for_task', 'needs_expansion', 'needs_narrowing' ), true ) || ! is_array( $packet->closure->required_node_ids ) || count( $packet->closure->required_node_ids ) > 200 || ! is_array( $packet->closure->reasons ) || ! is_array( $packet->closure->missing_dependencies ) || ( 'complete_for_task' === $packet->closure->state && ( $packet->closure->reasons || $packet->closure->missing_dependencies ) ) ) {
			throw new ContractException( 'invalid_output', 'Builder task-context closure claim is invalid.' );
		}
		$this->closed( $packet->task, array( 'request', 'content_role', 'can_grant_tools', 'can_grant_approval', 'scope', 'allowed_operations', 'artifact_refs', 'summary', 'recent_messages' ) );
		if ( 'untrusted_user_intent' !== $packet->task->content_role || false !== $packet->task->can_grant_tools || false !== $packet->task->can_grant_approval || ! is_array( $packet->task->recent_messages ) || count( $packet->task->recent_messages ) > 12 ) {
			throw new ContractException( 'invalid_output', 'Builder task-context intent authority is invalid.' );
		}
		if ( null !== $packet->task->summary ) {
			$this->closed( $packet->task->summary, array( 'text', 'through_sequence', 'source_hash', 'content_role', 'can_grant_tools', 'can_grant_approval' ) );
			if ( 'untrusted_memory' !== $packet->task->summary->content_role || false !== $packet->task->summary->can_grant_tools || false !== $packet->task->summary->can_grant_approval ) {
				throw new ContractException( 'invalid_output', 'Builder task-context summary authority is invalid.' );
			}
		}
		foreach ( $packet->task->recent_messages as $message ) {
			$this->closed( $message, array( 'sequence', 'role', 'text', 'content_role', 'can_grant_tools', 'can_grant_approval' ) );
			if ( 'untrusted_conversation' !== $message->content_role || false !== $message->can_grant_tools || false !== $message->can_grant_approval ) {
				throw new ContractException( 'invalid_output', 'Builder task-context message authority is invalid.' );
			}
		}
		$this->closed( $packet->graph_region, array( 'document', 'scope', 'nodes', 'edges', 'max_depth' ) );
		if ( ! is_array( $packet->graph_region->nodes ) || ! is_array( $packet->graph_region->edges ) || count( $packet->graph_region->nodes ) > 200 || count( $packet->graph_region->edges ) > 1000 ) {
			throw new ContractException( 'invalid_output', 'Builder task-context graph slice is invalid.' );
		}
		if ( 'complete_for_task' === $packet->closure->state ) {
			$included_ids = array();
			foreach ( $packet->graph_region->nodes as $node ) {
				if ( ! $node instanceof \stdClass || ! isset( $node->id, $node->opaque ) || ! is_string( $node->id ) || ! is_bool( $node->opaque ) || $node->opaque ) {
					throw new ContractException( 'invalid_output', 'Builder task-context completeness contains an invalid graph node.' );
				}
				$included_ids[] = $node->id;
			}
			sort( $included_ids, SORT_STRING );
			$required_ids = $packet->closure->required_node_ids;
			sort( $required_ids, SORT_STRING );
			if ( $included_ids !== $required_ids || count( array_unique( $included_ids, SORT_STRING ) ) !== count( $included_ids ) ) {
				throw new ContractException( 'invalid_output', 'Builder task-context completeness lacks its required graph nodes.' );
			}
		}
		$this->closed( $packet->metrics, array( 'content_bytes', 'estimated_tokens', 'token_estimator', 'packet_bytes' ) );
		if ( 'utf8-bytes-ceil-div-4-v1' !== $packet->metrics->token_estimator || ! is_int( $packet->metrics->content_bytes ) || ! is_int( $packet->metrics->estimated_tokens ) || ! is_int( $packet->metrics->packet_bytes ) || intdiv( $packet->metrics->content_bytes + 3, 4 ) !== $packet->metrics->estimated_tokens ) {
			throw new ContractException( 'invalid_output', 'Builder task-context metrics are invalid.' );
		}
		$copy       = json_decode( json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		$context_id = $copy->context_id;
		unset( $copy->context_id, $copy->metrics );
		$content_bytes = strlen( $this->canonical->encode( $copy ) );
		if ( $content_bytes !== $packet->metrics->content_bytes ) {
			throw new ContractException( 'invalid_output', 'Builder task-context content metric is invalid.' );
		}
		$identity = json_decode( json_encode( $packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		unset( $identity->context_id );
		$identity->metrics->packet_bytes = 0;
		if ( ! hash_equals( $context_id, hash( 'sha256', $this->canonical->encode( $identity ) ) ) ) {
			throw new ContractException( 'invalid_output', 'Builder task-context identity is invalid.' );
		}
		if ( strlen( $this->canonical->encode( $packet ) ) !== $packet->metrics->packet_bytes ) {
			throw new ContractException( 'invalid_output', 'Builder task-context packet metric is invalid.' );
		}
		return $packet;
	}

	public function encode( object $packet ): string {
		return $this->canonical->encode( $this->validate( $packet ) );
	}

	private function closed( $value, array $expected ): void {
		if ( ! $value instanceof \stdClass ) {
			throw new ContractException( 'invalid_output', 'Builder task-context object is invalid.' );
		}
		$actual = array_keys( get_object_vars( $value ) );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new ContractException( 'invalid_output', 'Builder task-context object shape is invalid.' );
		}
	}
}
