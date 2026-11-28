<?php
/**
 * Read-only recovery information for older image capacity interruptions.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Storage\Database;

/** Historical state and messages remain unchanged; no read can cancel or restart work. */
final class ListingImageRecovery {
	/**
	 * Site-scoped runtime storage.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind the existing runtime connection.
	 *
	 * @param Database $db Runtime storage.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Describe only an exact, current legacy image-budget interruption.
	 *
	 * @param array $run Already owner-authorized run.
	 * @return array|null Read-only view metadata, never an executable task or receipt.
	 */
	public function view( array $run ): ?array {
		$checkpoint = json_decode( $run['checkpoint_json'] ?? '{}' );
		if ( 'waiting_input' !== ( $run['state'] ?? null ) || true !== ( $checkpoint->clarification_pending ?? null ) || isset( $checkpoint->managed_image_pending ) || isset( $checkpoint->managed_pending ) || ( isset( $checkpoint->error ) && 'budget_exhausted' !== $checkpoint->error ) ) {
			return null;
		}
		$results = $checkpoint->tool_results ?? null;
		$last    = is_array( $results ) && $results ? end( $results ) : null;
		if ( ! is_object( $last ) || false !== ( $last->ok ?? null ) || 'budget_exhausted' !== ( $last->code ?? null ) || ! in_array( $last->operation ?? null, array( 'listing.form_image', 'media.propose' ), true ) ) {
			return null;
		}
		$table = $this->db->table( 'runs' );
		if ( ! $this->db->scalar( "SELECT id FROM $table WHERE id=%d AND public_id=%s AND owner_ref=%s AND state='waiting_input' AND checkpoint_json=%s", array( $run['id'], $run['public_id'], $run['owner_ref'], $run['checkpoint_json'] ) ) ) {
			return null;
		}
		if ( $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'operations' ) . " WHERE run_id=%d AND state IN ('running','unknown','partial')", array( $run['id'] ) ) ) {
			return null;
		}
		$tasks = $this->db->rows( 'SELECT receipt_json FROM ' . $this->db->table( 'task_receipts' ) . ' WHERE run_id=%s AND owner_ref=%s', array( $run['public_id'], $run['owner_ref'] ) );
		foreach ( $tasks as $task ) {
			foreach ( json_decode( $task['receipt_json'], true )['calls'] ?? array() as $call ) {
				if ( in_array( $call['state'] ?? null, array( 'running', 'unknown' ), true ) ) {
					return null;
				}
			}
		}
		return array(
			'outcome_kind'                  => 'image_capacity_limit',
			'failure_code'                  => 'budget_exhausted',
			'failure_notice'                => 'This earlier image request stopped at an AI usage limit. It is not asking for more listing details. Cancel this earlier request before starting another image, or use the native uploader. Your conversation and existing images are retained. Current usage limits still apply.',
			'recovery'                      => 'restart_image',
			'historical_state'              => 'waiting_input',
			'historical_run_id'             => $run['public_id'],
			'historical_messages_preserved' => true,
		);
	}
}
