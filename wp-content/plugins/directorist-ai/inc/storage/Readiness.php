<?php
/**
 * Authoritative, read-only runtime storage readiness.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

use DirectoristAIPro\Validation\ContractException;

/** Option caches never authorize provider requests or native effects. */
final class Readiness {

	/**
	 * Dedicated runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Structural result, bounded to this connection/request.
	 *
	 * @var bool|null
	 */
	private ?bool $structure = null;
	/**
	 * Structural probe time; long-lived workers recheck within five seconds.
	 *
	 * @var float
	 */
	private float $probed_at = 0.0;

	/**
	 * Bind the authoritative runtime connection.
	 *
	 * @param Database $db Runtime database.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Read metadata freshly; inspect the bounded table/column set once.
	 *
	 * @return array Safe diagnostic fields.
	 */
	public function status(): array {
		$version = 0;
		$status  = 'unavailable';
		try {
			$value = $this->db->scalar( 'SELECT value FROM ' . $this->db->table( 'meta' ) . ' WHERE name=%s', array( 'schema_version' ) );
			if ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,8}$/D', $value ) ) {
				$version = (int) $value;
				$status  = $version > Schema::VERSION ? 'future_schema' : 'migration_required';
				if ( Schema::VERSION === $version ) {
					if ( null === $this->structure || microtime( true ) - $this->probed_at >= 5.0 ) {
						$this->structure = $this->supported_structure();
						$this->probed_at = microtime( true );
					}
					$status = $this->structure ? 'ready' : 'incomplete_schema';
				}
			} else {
				$status = 'invalid_metadata';
			}
		} catch ( \Throwable $error ) {
			// Only a wholly absent owned schema is eligible for initial installation.
			try {
				$prefix = substr( trim( $this->db->table( 'meta' ), '`' ), 0, -4 );
				$count  = $this->db->scalar( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,%d)=%s', array( strlen( $prefix ), $prefix ) );
				$status = '0' === (string) $count ? 'uninstalled' : 'unavailable';
			} catch ( \Throwable $metadata_error ) {
				// Lost connection or inaccessible metadata never authorize migration.
			}
		}
		$option = (int) get_option( 'directorist_ai_pro_storage_version', 0 );
		return array(
			'storage_version'  => $version,
			'expected_version' => Schema::VERSION,
			'option_version'   => $option,
			'option_drift'     => $option !== $version,
			'storage_status'   => $status,
			'effects_enabled'  => 'ready' === $status,
		);
	}

	/**
	 * Deny new work without changing metadata or native content.
	 *
	 * @return void
	 * @throws ContractException Unsupported or incomplete storage.
	 */
	public function assert_ready(): void {
		$status = $this->status();
		if ( ! $status['effects_enabled'] ) {
			throw new ContractException( 'storage_unavailable', 'Runtime storage is not compatible with this plugin version. New requests and effects are disabled. Existing records are retained.' );
		}
	}

	/** Invalidate structural checks after an explicit supported migration. */
	public function invalidate(): void {
		$this->structure = null;
		$this->probed_at = 0.0;
	}

	/**
	 * Verify required InnoDB tables, columns and uniqueness in two bounded queries.
	 *
	 * Extra columns are permitted; a future version is rejected before this check.
	 *
	 * @return bool Whether all code-required storage fields exist.
	 */
	private function supported_structure(): bool {
		$definitions         = Schema::definitions();
		$definitions['meta'] = 'name varchar(64), value longtext';
		$required            = array();
		foreach ( $definitions as $table => $definition ) {
			preg_match_all( '/(?:^|,)\s*([a-z_][a-z0-9_]*)\s+[a-z][a-z0-9]*\b/', $definition, $matches );
			$required[ trim( $this->db->table( $table ), '`' ) ] = $matches[1];
		}
		$placeholders = implode( ',', array_fill( 0, count( $required ), '%s' ) );
		$rows         = $this->db->rows( "SELECT c.TABLE_NAME,c.COLUMN_NAME,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME IN ($placeholders)", array_keys( $required ) );
		$found        = array();
		foreach ( $rows as $row ) {
			if ( 'InnoDB' !== $row['ENGINE'] ) {
				return false;
			}
			$found[ $row['TABLE_NAME'] ][] = $row['COLUMN_NAME'];
		}
		foreach ( $required as $table => $columns ) {
			if ( ! isset( $found[ $table ] ) || array_diff( $columns, $found[ $table ] ) ) {
				return false;
			}
		}
		$rows    = $this->db->rows( "SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($placeholders) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX", array_keys( $required ) );
		$indexes = array();
		foreach ( $rows as $row ) {
			if ( '0' === (string) $row['NON_UNIQUE'] && null === $row['SUB_PART'] ) {
				$indexes[ $row['TABLE_NAME'] ][ $row['INDEX_NAME'] ][] = $row['COLUMN_NAME'];
			}
		}
		foreach ( $definitions as $table => $definition ) {
			if ( 'meta' === $table ) {
				$definition .= ', PRIMARY KEY (name)';
			}
			preg_match_all( '/(?:PRIMARY KEY|UNIQUE KEY ([a-z_][a-z0-9_]*))\s*\(([^)]+)\)/i', $definition, $matches, PREG_SET_ORDER );
			$table_name = trim( $this->db->table( $table ), '`' );
			foreach ( $matches as $match ) {
				$name    = '' === $match[1] ? 'PRIMARY' : $match[1];
				$columns = array_map( 'trim', explode( ',', $match[2] ) );
				if ( ( $indexes[ $table_name ][ $name ] ?? null ) !== $columns ) {
					return false;
				}
			}
		}
		return true;
	}
}
