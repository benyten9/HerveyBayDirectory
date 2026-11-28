<?php
/**
 * Stop identical requests that have already completed without advancing the task.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** A progress check is independent of administrator spend and round allowances. */
final class GenerationProgress {
	/** Semantically identical invalid output cannot buy another identical repair. */
	public static function output_fingerprint( string $function, string $arguments ): string {
		try {
			$decoded = json_decode( $arguments, false, 64, JSON_THROW_ON_ERROR );
			if ( $decoded instanceof \stdClass ) {
				unset( $decoded->request_id );
				$arguments = ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $decoded );
			}
		} catch ( \Throwable $ignored ) { /* Invalid JSON keeps its exact fingerprint. */ }
		return hash( 'sha256', $function . "\0" . $arguments );
	}

	/** Progress belongs to one turn and admitted native declaration, not global spend. */
	public static function repeated_output( object $checkpoint, string $scope, ?string $fingerprint ): bool {
		if ( null === $fingerprint || ! preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) { return false; }
		$state = $checkpoint->invalid_output_progress ?? null;
		$recent = $state instanceof \stdClass && ( $state->scope ?? null ) === $scope && is_array( $state->fingerprints ?? null ) ? array_slice( $state->fingerprints, -4 ) : array();
		$repeated = in_array( $fingerprint, $recent, true );
		$checkpoint->invalid_output_progress = (object) array( 'scope' => $scope, 'fingerprints' => array_slice( array_merge( $recent, array( $fingerprint ) ), -4 ) );
		return $repeated;
	}
	/**
	 * Stop a persisted schema defect even when unrelated feedback keeps changing.
	 *
	 * @param object $checkpoint Owned run checkpoint.
	 * @param string $scope Current turn and frozen declaration fingerprint.
	 * @param array  $feedback Closed server-produced schema diagnostics.
	 * @param string $category Closed validation stage when diagnostics are unavailable.
	 * @return bool One exact defect occurred in three of the last four completed attempts.
	 */
	public static function schema_failure( object $checkpoint, string $scope, array $feedback, string $category ): bool {
		$state      = $checkpoint->schema_repair_progress ?? null;
		$history    = $state instanceof \stdClass && ( $state->scope ?? null ) === $scope && is_array( $state->attempts ?? null ) ? array_slice( $state->attempts, -3 ) : array();
		$signatures = array();
		foreach ( array_slice( $feedback, 0, 4 ) as $row ) {
			$row = (array) $row;
			if ( ! is_string( $row['path'] ?? null ) || ! is_string( $row['keyword'] ?? null ) ) {
				continue;
			}
			ksort( $row, SORT_STRING );
			$signatures[] = hash( 'sha256', json_encode( $row ) );
		}
		if ( ! $signatures ) {
			$signatures[] = hash( 'sha256', 'validation:' . $category );
		}
		$signatures                         = array_values( array_unique( $signatures ) );
		$history[]                          = $signatures;
		$checkpoint->schema_repair_progress = (object) array(
			'scope'    => $scope,
			'attempts' => $history,
		);
		foreach ( $signatures as $signature ) {
			$count = 0;
			foreach ( $history as $attempt ) {
				$count += is_array( $attempt ) && in_array( $signature, $attempt, true ) ? 1 : 0;
			}
			if ( $count >= 3 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True only after two completed attempts with the same complete policy and context.
	 *
	 * @param string $input_hash Exact request hash.
	 * @param array  $steps Prior model steps in this owned run.
	 * @return bool Whether the same input has stalled.
	 */
	public static function stalled( string $input_hash, array $steps ): bool {
		$count = 0;
		foreach ( $steps as $step ) {
			if ( ! in_array( $step['state'] ?? null, array( 'succeeded', 'failed' ), true ) || ! is_string( $step['input_hash'] ?? null ) || ! hash_equals( $input_hash, $step['input_hash'] ) ) {
				continue;
			}
			$count += max( 1, (int) ( $step['attempt'] ?? 1 ) );
		}
		return $count >= 2;
	}

	/**
	 * Detect repeated invalid tool arguments independently of changing call IDs.
	 *
	 * @param object $checkpoint Owned run progress.
	 * @param string $input Rejected tool arguments.
	 * @param array  $result Native validation result.
	 * @return bool Whether this rejected proposal was already tried.
	 */
	public static function proposal_failure( object $checkpoint, string $input, array $result ): bool {
		try {
			$arguments = json_decode( $input, false, 64, JSON_THROW_ON_ERROR );
			if ( $arguments instanceof \stdClass ) {
				// A fresh idempotency key cannot disguise the same rejected change.
				unset( $arguments->request_id );
				$input = ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $arguments );
			}
		} catch ( \Throwable $error ) {
			// Invalid JSON keeps its exact original fingerprint; it grants no write.
		}
		$key                           = hash( 'sha256', $input . "\0" . json_encode( array( $result['code'] ?? null, $result['repair_codes'] ?? array() ) ) );
		$recent                        = is_array( $checkpoint->proposal_failures ?? null ) ? array_slice( $checkpoint->proposal_failures, -4 ) : array();
		$repeated                      = in_array( $key, $recent, true );
		$checkpoint->proposal_failures = array_slice( array_merge( $recent, array( $key ) ), -4 );
		return $repeated;
	}
}
