<?php
/**
 * Per-site durable private staging, jobs and source claims.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Import;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment,Squiz.Commenting.FunctionCommentThrowTag -- Closed import adapters documented in the feature contract.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Directory\DirectorySchema;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Repository writes are short; native writes never share this connection. */
final class ImportStore {
	public function __construct( private Database $db ) {}

	public function create( Context $context, array $schema, array $source ): array {
		$this->db->readiness()->assert_ready();
		$id       = bin2hex( random_bytes( 16 ) );
		$manifest = array(
			'schema'            => $schema,
			'source'            => array_diff_key( $source, array( 'records' => true ) ),
			'mapping'           => null,
			'policy'            => null,
			'validation_cursor' => 0,
		);
		if ( strlen( self::json( $manifest ) ) > 5242880 ) {
			throw new ContractException( 'schema_bounds', 'The complete selected schema exceeds the private staging limit.' ); }
		$this->db->transaction(
			function ( Database $db ) use ( $context, $schema, $source, $id, $manifest ): void {
				$jobs    = $db->table( 'import_jobs' );
				$records = $db->table( 'import_records' );
				$db->query( "INSERT INTO $jobs (public_id,site_id,actor_ref,directory_id,source_hash,schema_hash,environment_hash,state,version,manifest_json,record_count,next_ordinal,cancel_requested,created_at,updated_at,expires_at) VALUES (%s,%d,%s,%d,%s,%s,%s,'parsed',1,%s,%d,0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))", array( $id, get_current_blog_id(), $context->actor()->owner()->reference(), $schema['directory_id'], $source['source_hash'], $schema['revision'], $schema['environment_hash'], self::json( $manifest ), count( $source['records'] ) ) );
				$job_id = $db->insert_id();
				$total  = 0;
				foreach ( $source['records'] as $row ) {
						$json = self::json( $row['data'] );
					$total   += strlen( $json );
					if ( $total > Limits::EXPANDED_BYTES ) {
						throw new ContractException( 'source_bounds', 'Parsed records exceed 20 MiB. Split the source.' ); }
					$identity = DirectorySchema::hash( array( get_current_blog_id(), $schema['directory_id'], $source['source_hash'], $row['id'] ) );
					$db->query( "INSERT INTO $records (job_id,ordinal,public_id,source_identity,source_json,provenance_json,state,updated_at) VALUES (%d,%d,%s,%s,%s,%s,'staged',UTC_TIMESTAMP())", array( $job_id, $row['ordinal'], $row['id'], $identity, $json, self::json( $row['provenance'] ) ) );
				}
			}
		);
		return $this->get( $context, $id );
	}

	public function get( Context $context, string $id ): array {
		$context->assert_current();
		if ( ! $context->actor()->can( 'manage_options' ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new ContractException( 'unauthorized', 'Import access requires its administrator owner.' ); }
		$table = $this->db->table( 'import_jobs' );
		$rows  = $this->db->rows( "SELECT * FROM $table WHERE public_id=%s AND actor_ref=%s AND site_id=%d", array( $id, $context->actor()->owner()->reference(), get_current_blog_id() ) );
		if ( ! $rows ) {
			throw new ContractException( 'import_unavailable', 'This private import is unavailable to this actor/site.' ); }
		$job             = $rows[0];
		$job['manifest'] = json_decode( $job['manifest_json'], true, 64, JSON_THROW_ON_ERROR );
		return $job;
	}

	/** Worker bootstrap only: identity comes from durable server state. */
	public function actor_id( string $id ): int {
		$table = $this->db->table( 'import_jobs' );
		$actor = $this->db->scalar( "SELECT actor_ref FROM $table WHERE public_id=%s AND site_id=%d", array( $id, get_current_blog_id() ) );
		return is_string( $actor ) && preg_match( '/^user:([1-9][0-9]*)$/D', $actor, $match ) ? (int) $match[1] : 0;
	}

	public function history( Context $context, int $offset = 0, int $limit = 50 ): array {
		$context->assert_current();
		if ( ! $context->actor()->can( 'manage_options' ) ) {
			throw new ContractException( 'unauthorized', 'Import history is administrator-only.' ); }
		$table = $this->db->table( 'import_jobs' );
		return $this->db->rows( "SELECT public_id,directory_id,state,version,record_count,next_ordinal,cancel_requested,created_at,expires_at,JSON_UNQUOTE(JSON_EXTRACT(manifest_json,'$.schema.name')) AS directory_name,JSON_EXTRACT(manifest_json,'$.source') AS source_json FROM $table WHERE actor_ref=%s AND site_id=%d ORDER BY id DESC LIMIT %d OFFSET %d", array( $context->actor()->owner()->reference(), get_current_blog_id(), max( 1, min( 51, $limit ) ), max( 0, $offset ) ) );
	}

	public function reviewed_rows( array $job, int $offset, string $filter ): array {
		if ( 'all' === $filter ) {
			return $this->rows( $job, $offset, 100 );
		}
		$ordinals = array_map( 'intval', array_keys( $job['manifest']['approved_manifest']['record_hashes'] ?? array() ) );
		if ( ! $ordinals ) {
			return array();
		}
		$table        = $this->db->table( 'import_records' );
		$placeholders = implode( ',', array_fill( 0, count( $ordinals ), '%d' ) );
		$operator     = 'included' === $filter ? 'IN' : 'NOT IN';
		return $this->db->rows( "SELECT * FROM $table WHERE job_id=%d AND ordinal $operator ($placeholders) ORDER BY ordinal LIMIT 100 OFFSET %d", array_merge( array( (int) $job['id'] ), $ordinals, array( $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Closed operator and generated placeholder list; every value is bound by Database.
	}

	public function update( array $job, array $manifest, string $state ): void {
		$table = $this->db->table( 'import_jobs' );
		$this->db->query( "UPDATE $table SET manifest_json=%s,state=%s,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d AND version=%d AND cancel_requested=0", array( self::json( $manifest ), $state, $job['id'], $job['version'] ) );
		if ( 1 !== $this->db->affected() ) {
			throw new ContractException( 'import_conflict', 'This import changed in another request. Reload it.' ); }
	}

	public function rows( array $job, int $offset = 0, int $limit = 100 ): array {
		$table = $this->db->table( 'import_records' );
		return $this->db->rows( "SELECT * FROM $table WHERE job_id=%d AND ordinal>=%d ORDER BY ordinal LIMIT %d", array( $job['id'], max( 0, $offset ), min( Limits::RECORDS, max( 1, $limit ) ) ) );
	}

	public static function source_record( array $row ): array {
		return array(
			'id'         => $row['public_id'],
			'ordinal'    => (int) $row['ordinal'],
			'data'       => json_decode( $row['source_json'], true, 20, JSON_THROW_ON_ERROR ),
			'provenance' => json_decode( $row['provenance_json'], true, 20, JSON_THROW_ON_ERROR ),
		); }

	public function staged( array $row, array $staged ): void {
		$table = $this->db->table( 'import_records' );
		$this->db->query( "UPDATE $table SET staged_json=%s,row_hash=%s,state=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d AND state IN ('staged','valid','invalid')", array( self::json( $staged ), $staged['row_hash'], $staged['valid'] ? 'valid' : 'invalid', $row['id'] ) );
	}

	public function reset( array $job ): void {
		$table = $this->db->table( 'import_records' );
		$this->db->query( "UPDATE $table SET staged_json=NULL,row_hash=NULL,state='staged',updated_at=UTC_TIMESTAMP() WHERE job_id=%d", array( $job['id'] ) );
	}

	public function counts( array $job ): array {
		$table  = $this->db->table( 'import_records' );
		$counts = array();
		foreach ( $this->db->rows( "SELECT state,COUNT(*) AS total FROM $table WHERE job_id=%d GROUP BY state", array( $job['id'] ) ) as $row ) {
			$counts[ $row['state'] ] = (int) $row['total']; }
		return $counts;
	}

	/** Cross-job source identities stay fenced even when a job or worker expires. */
	public function claim( array $job, array $row, string $intent_hash ): array {
		return $this->db->transaction(
			function ( Database $db ) use ( $job, $row, $intent_hash ): array {
				$table = $db->table( 'import_claims' );
				$db->query( "INSERT INTO $table (source_identity,intent_hash,job_id,record_id,state,created_at,updated_at) VALUES (%s,%s,%d,%d,'claimed',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)", array( $row['source_identity'], $intent_hash, $job['id'], $row['id'] ) );
				$claim = $db->rows( "SELECT * FROM $table WHERE source_identity=%s FOR UPDATE", array( $row['source_identity'] ) )[0];
				if ( ! hash_equals( $claim['intent_hash'], $intent_hash ) ) {
					throw new ContractException( 'duplicate_ambiguous', 'This source row already has a different reviewed import intent.' ); }
				return $claim;
			}
		);
	}

	public function running_claim( array $claim, array $job ): void {
		$table = $this->db->table( 'import_claims' );
		$this->db->query( "UPDATE $table SET state='running',updated_at=UTC_TIMESTAMP() WHERE id=%d AND job_id=%d AND state='claimed'", array( $claim['id'], $job['id'] ) );
		if ( 1 !== $this->db->affected() ) {
			throw new ContractException( 'unknown_outcome', 'This source identity is already running or requires reconciliation.' ); }
	}

	public function finish_claim( array $claim, string $state, array $receipt, int $listing_id = 0 ): void {
		$table = $this->db->table( 'import_claims' );
		$this->db->query(
			"UPDATE $table SET state=%s,receipt_json=%s,listing_id=NULLIF(%d,0),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state IN ('claimed','running','unknown')",
			array(
				$state,
				self::json(
					array(
						'operation_id' => $receipt['operation_id'] ?? null,
						'state'        => $state,
						'listing_id'   => $listing_id,
					)
				),
				$listing_id,
				$claim['id'],
			)
		);
	}

	public function result( array $row, string $state, array $receipt, string $operation_id, int $listing_id = 0 ): void {
		$table = $this->db->table( 'import_records' );
		$this->db->query( "UPDATE $table SET state=%s,receipt_json=%s,operation_id=%s,listing_id=NULLIF(%d,0),updated_at=UTC_TIMESTAMP() WHERE id=%d", array( $state, self::json( $receipt ), $operation_id, $listing_id, $row['id'] ) );
	}

	public function lease( array $job ): ?string {
		$table = $this->db->table( 'import_jobs' );
		$token = bin2hex( random_bytes( 16 ) );
		$this->db->query( "UPDATE $table SET lease_token=%s,lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),state='running' WHERE id=%d AND (lease_token IS NULL OR lease_expires_at<UTC_TIMESTAMP()) AND state IN ('queued','running','unknown') AND cancel_requested=0", array( $token, $job['id'] ) );
		return 1 === $this->db->affected() ? $token : null;
	}

	public function heartbeat( array $job, string $token ): void {
		$table = $this->db->table( 'import_jobs' );
		$this->db->query( "UPDATE $table SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND) WHERE id=%d AND lease_token=%s AND lease_expires_at>UTC_TIMESTAMP() AND cancel_requested=0", array( $job['id'], $token ) );
		// MySQL can report zero affected rows for a same-second renewal; read the exact token.
		$valid = $this->db->scalar( "SELECT COUNT(*) FROM $table WHERE id=%d AND lease_token=%s AND lease_expires_at>UTC_TIMESTAMP() AND cancel_requested=0", array( $job['id'], $token ) );
		if ( 1 !== (int) $valid ) {
			throw new ContractException( 'unknown_outcome', 'The import lease expired, changed or was cancelled before a write.' ); }
	}

	public function release_unstarted_claim( array $claim, string $operation_id ): void {
		$this->db->transaction(
			function ( Database $db ) use ( $claim, $operation_id ): void {
				$claims     = $db->table( 'import_claims' );
				$operations = $db->table( 'operations' );
				$exists     = $db->rows( "SELECT id FROM $operations WHERE public_id=%s FOR UPDATE", array( $operation_id ) );
				if ( ! $exists ) {
					$db->query( "DELETE FROM $claims WHERE id=%d AND state='claimed'", array( $claim['id'] ) ); }
			}
		);
	}

	public function release( array $job, string $token, string $state, int $next ): void {
		$table = $this->db->table( 'import_jobs' );
		$this->db->query( "UPDATE $table SET lease_token=NULL,lease_expires_at=NULL,state=IF(cancel_requested=1,'cancelled',%s),next_ordinal=%d,updated_at=UTC_TIMESTAMP() WHERE id=%d AND lease_token=%s", array( $state, $next, $job['id'], $token ) );
		if ( 1 !== $this->db->affected() ) {
			throw new ContractException( 'unknown_outcome', 'The import worker lease changed.' ); }
	}

	public function cancel( array $job ): void {
		$table = $this->db->table( 'import_jobs' );
		$this->db->query( "UPDATE $table SET version=version+IF(cancel_requested=0,1,0),cancel_requested=1,state=IF(lease_token IS NULL OR lease_expires_at<=UTC_TIMESTAMP(),'cancelled',state),updated_at=UTC_TIMESTAMP() WHERE id=%d AND state NOT IN ('succeeded','partial','cancelled')", array( $job['id'] ) );
	}

	public function runnable(): array {
		$table = $this->db->table( 'import_jobs' );
		return $this->db->rows( "SELECT public_id FROM $table WHERE site_id=%d AND state IN ('queued','running') AND cancel_requested=0 AND expires_at>UTC_TIMESTAMP() AND (lease_token IS NULL OR lease_expires_at<UTC_TIMESTAMP()) ORDER BY updated_at LIMIT 25", array( get_current_blog_id() ) );
	}

	public function diagnostic( array $job, string $code, string $message ): void {
		$table                  = $this->db->table( 'import_jobs' );
		$manifest               = $job['manifest'];
		$manifest['last_error'] = array(
			'code'    => $code,
			'message' => $message,
		);
		$this->db->query( "UPDATE $table SET manifest_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( self::json( $manifest ), $job['id'] ) );
	}

	/** Remove expired private source content; cross-job claims retain only receipt pointers. */
	public function cleanup(): void {
		$this->db->transaction(
			function ( Database $db ): void {
				$jobs    = $db->table( 'import_jobs' );
				$records = $db->table( 'import_records' );
				$expired = $db->rows( "SELECT id FROM $jobs WHERE site_id=%d AND expires_at<=UTC_TIMESTAMP() AND (lease_token IS NULL OR lease_expires_at<UTC_TIMESTAMP()) LIMIT 25 FOR UPDATE", array( get_current_blog_id() ) );
				foreach ( $expired as $job ) {
					$db->query( "DELETE FROM $records WHERE job_id=%d", array( $job['id'] ) );
					$db->query( "DELETE FROM $jobs WHERE id=%d", array( $job['id'] ) ); }
			}
		);
	}

	public static function json( $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ); }
}
