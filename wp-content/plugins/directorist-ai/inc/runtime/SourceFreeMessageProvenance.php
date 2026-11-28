<?php
/** Exact producer attestation for a source-free, no-tool model completion.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Bind ordinary prose to its actual owned run, result step and durable position. */
final class SourceFreeMessageProvenance {
	/**
	 * Runtime source for complete step evidence.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind site-scoped runtime storage.
	 *
	 * @param Database $db Site-scoped runtime storage.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Sign only a producer-marked completion whose complete model step history used no tools.
	 *
	 * @param array  $run Actual locked run.
	 * @param int    $sequence Allocated message sequence.
	 * @param object $content Exact server-produced text.
	 * @param object $checkpoint Actual checkpoint.
	 * @return object Durable attestation.
	 * @throws ContractException The no-tool producer claim is invalid.
	 */
	public function seal( array $run, int $sequence, object $content, object $checkpoint ): object {
		if ( ! $this->valid_source_free_step( $run, $content, $checkpoint ) ) {
			throw new ContractException( 'invalid_output', 'Source-free completion provenance is unavailable.' );
		}
		return (object) array(
			'contract'  => 'directorist-ai/source-free-message-v1',
			'run_id'    => $run['public_id'],
			'signature' => $this->signature( $run, $sequence, $content, $checkpoint ),
		);
	}

	/**
	 * Check exact saved content and the durable no-tool model step again on replay.
	 *
	 * @param array  $run Actual owned run.
	 * @param int    $sequence Stored message sequence.
	 * @param object $content Exact saved content.
	 * @param object $checkpoint Actual saved checkpoint.
	 * @return bool Whether complete provenance remains valid.
	 */
	public function verifies( array $run, int $sequence, object $content, object $checkpoint ): bool {
		$seal = $content->provenance ?? null;
		if ( ! $seal instanceof \stdClass || array( 'contract', 'run_id', 'signature' ) !== array_keys( get_object_vars( $seal ) ) || 'directorist-ai/source-free-message-v1' !== $seal->contract || $run['public_id'] !== $seal->run_id || ! is_string( $seal->signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $seal->signature ) ) {
			return false;
		}
		try {
			return $this->valid_source_free_step( $run, $content, $checkpoint ) && hash_equals( $this->signature( $run, $sequence, $content, $checkpoint ), $seal->signature );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Require one succeeded zero-call model step for the entire run.
	 *
	 * @param array  $run Actual owned run.
	 * @param object $content Exact text.
	 * @param object $checkpoint Actual checkpoint.
	 * @return bool Whether the durable step agrees with the producer claim.
	 */
	private function valid_source_free_step( array $run, object $content, object $checkpoint ): bool {
		$claim = $checkpoint->source_free ?? null;
		if ( ! $claim instanceof \stdClass || array( 'step_id', 'input_hash' ) !== array_keys( get_object_vars( $claim ) ) || ! is_int( $claim->step_id ) || $claim->step_id < 1 || ! is_string( $claim->input_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $claim->input_hash ) || ! isset( $checkpoint->provider_ref ) || ! is_string( $checkpoint->provider_ref ) || ! preg_match( '/^[a-f0-9]{64}$/D', $checkpoint->provider_ref ) || ! isset( $content->text ) || ! is_string( $content->text ) || '' === trim( $content->text ) || strlen( $content->text ) > 65536 ) {
			return false;
		}
		$steps = $this->db->rows( 'SELECT id,kind,input_hash,state,result_ref,data_json FROM ' . $this->db->table( 'steps' ) . ' WHERE run_id=%d ORDER BY sequence LIMIT 2', array( (int) $run['id'] ) );
		if ( 1 !== count( $steps ) || (int) $steps[0]['id'] !== $claim->step_id || 'model' !== $steps[0]['kind'] || 'succeeded' !== $steps[0]['state'] || $steps[0]['input_hash'] !== $claim->input_hash || $steps[0]['result_ref'] !== $checkpoint->provider_ref ) {
			return false;
		}
		try {
			$data = json_decode( $steps[0]['data_json'], false, 8, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return false;
		}
		if ( ! $data instanceof \stdClass || ! is_int( $data->tool_calls ?? null ) || 0 !== $data->tool_calls ) {
			return false;
		}
		$allowed = array( 'ability_names', 'max_tokens', 'timeout', 'usage_reservation', 'source', 'feature', 'task', 'requested_provider', 'requested_model', 'started_at', 'started_time', 'duration_ms', 'input_tokens', 'output_tokens', 'tool_calls', 'provider_id', 'model_id', 'reported_model', 'usage_reported', 'error', 'managed_receipt', 'transport_duration_ms', 'total_duration_ms', 'waiting_duration_ms' );
		if ( array_diff( array_keys( get_object_vars( $data ) ), $allowed ) ) {
			return false;
		}
		return ( ! isset( $data->provider_id ) && ! isset( $data->model_id ) ) || ( isset( $data->provider_id, $data->model_id ) && is_string( $data->provider_id ) && 1 === preg_match( '/^[a-z0-9_-]{1,128}$/D', $data->provider_id ) && is_string( $data->model_id ) && 1 === preg_match( '/^[A-Za-z0-9._:\/@+-]{1,191}$/D', $data->model_id ) );
	}

	/**
	 * Bind the producer claim to the site, owner, run and exact row position.
	 *
	 * @param array  $run Actual owned run.
	 * @param int    $sequence Stored message sequence.
	 * @param object $content Exact text.
	 * @param object $checkpoint Actual checkpoint.
	 * @return string Private HMAC digest.
	 */
	private function signature( array $run, int $sequence, object $content, object $checkpoint ): string {
		$binding = array(
			'site'                   => get_current_blog_id(),
			'owner'                  => $run['owner_ref'],
			'conversation'           => (int) $run['conversation_id'],
			'conversation_public_id' => $run['conversation_public_id'],
			'profile'                => $run['profile'],
			'run'                    => $run['public_id'],
			'turn'                   => (int) $run['trigger_turn_id'],
			'sequence'               => $sequence,
			'text'                   => $content->text,
			'claim'                  => $checkpoint->source_free,
			'provider_ref'           => $checkpoint->provider_ref,
		);
		return hash_hmac( 'sha256', wp_json_encode( $binding, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES ), wp_salt( 'auth' ) . '|source-free-message-v1' );
	}
}
