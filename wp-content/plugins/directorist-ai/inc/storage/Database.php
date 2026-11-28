<?php
/**
 * Dedicated non-reconnecting connection for durable AI state.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Storage;

/**
 * Transactions are restricted to runtime repositories, never native hooks.
 */
final class Database {
	/**
	 * Dedicated WordPress connection for escaping and prefix conventions.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;
	/**
	 * Transaction nesting guard.
	 *
	 * @var bool
	 */
	private bool $transaction = false;
	/**
	 * Shared connection-bound readiness probe.
	 *
	 * @var Readiness|null
	 */
	private ?Readiness $readiness = null;

	/**
	 * Open a dedicated connection using the site's existing configuration.
	 *
	 * @param \wpdb $site Source WordPress connection; never retained for writes.
	 * @throws StorageException Unsupported connection/prefix.
	 */
	public function __construct( \wpdb $site ) {
		$prefix = $site->prefix;
		if ( ! preg_match( '/^[a-zA-Z0-9_]{1,48}$/D', $prefix ) ) {
			throw new StorageException( 'Unsupported site table prefix.' );
		}
		$this->db = new \wpdb( $site->dbuser, $site->dbpassword, $site->dbname, $site->dbhost );
		// A fresh wpdb has no blog ID in multisite. Its default set_prefix
		// path may return before setting prefix, so bind our dedicated custom
		// storage connection to the exact validated caller namespace.
		$this->db->set_prefix( $prefix, false );
		$this->db->prefix = $prefix;
		$this->db->suppress_errors( true );
		if ( ! $this->db->dbh instanceof \mysqli ) {
			throw new StorageException( 'AI storage requires a supported MySQL connection.' );
		}
	}

	/**
	 * Reuse a bounded structural probe while reading authoritative metadata freshly.
	 *
	 * @return Readiness
	 */
	public function readiness(): Readiness {
		if ( null === $this->readiness ) {
			$this->readiness = new Readiness( $this );
		}
		return $this->readiness;
	}

	/**
	 * Resolve only runtime-owned table names.
	 *
	 * @param string $suffix Trusted logical table.
	 * @return string Quoted table identifier.
	 * @throws StorageException Unknown table.
	 */
	public function table( string $suffix ): string {
		$tables = array( 'meta', 'conversations', 'turns', 'messages', 'runs', 'steps', 'operations', 'target_locks', 'events', 'artifacts', 'approvals', 'usage_windows', 'usage_reservations', 'projection_sources', 'projection_heads', 'projection_entries', 'knowledge_sources', 'knowledge_chunks', 'knowledge_audit', 'catalog_cache_heads', 'catalog_cache_generations', 'graph_heads', 'graph_snapshots', 'graph_edges', 'graph_index_epochs', 'task_receipts', 'visitor_tasks', 'import_jobs', 'import_records', 'import_claims' );
		if ( ! in_array( $suffix, $tables, true ) ) {
			throw new StorageException( 'Unknown runtime table.' );
		}
		return '`' . $this->db->prefix . 'dai_' . $suffix . '`';
	}

	/**
	 * Execute trusted repository SQL with parameter binding/escaping.
	 *
	 * No wpdb query/reconnect retry is used: retrying a transaction statement
	 * after reconnect could execute it in autocommit mode.
	 *
	 * @param string $sql Trusted SQL with wpdb placeholders.
	 * @param array  $parameters Bound values.
	 * @return \mysqli_result|bool Query result.
	 * @throws StorageException Database failure.
	 * @throws StorageUnavailableException Query or connection outcome is unavailable.
	 */
	public function query( string $sql, array $parameters = array() ) {
		try {
			if ( $parameters ) {
				// wpdb normally removes its percent sentinel through the query
				// filter. Direct mysqli execution must do that explicitly, without
				// invoking arbitrary query filters or reconnect/retry behavior.
				$sql = $this->db->remove_placeholder_escape( $this->db->prepare( $sql, ...$parameters ) );
			}
			$result = $this->db->dbh->query( $sql );
			if ( false === $result ) {
				throw new StorageException( 'Runtime storage operation failed.', (int) $this->db->dbh->errno );
			}
			return $result;
		} catch ( \Throwable $error ) {
			throw new StorageUnavailableException( 'Runtime storage operation failed. Reconcile before retrying writes.', (int) $error->getCode(), $error );
		}
	}

	/**
	 * Read a scalar from a trusted repository query.
	 *
	 * @param string $sql SQL statement.
	 * @param array  $parameters Bound values.
	 * @return mixed First column or null.
	 */
	public function scalar( string $sql, array $parameters = array() ) {
		$result = $this->query( $sql, $parameters );
		$row    = $result->fetch_row();
		$result->free();
		return $row[0] ?? null;
	}

	/**
	 * Read bounded records from a trusted query.
	 *
	 * @param string $sql SQL statement.
	 * @param array  $parameters Bound values.
	 * @return array Rows.
	 */
	public function rows( string $sql, array $parameters = array() ): array {
		$result = $this->query( $sql, $parameters );
		$rows   = $result->fetch_all( MYSQLI_ASSOC );
		$result->free();
		return $rows;
	}

	/**
	 * Return the last insertion identity on this connection.
	 *
	 * @return int
	 */
	public function insert_id(): int {
		return (int) $this->db->dbh->insert_id;
	}

	/**
	 * Return affected rows for compare-and-set operations.
	 *
	 * @return int
	 */
	public function affected(): int {
		return $this->db->dbh->affected_rows;
	}

	/**
	 * Run only short repository work atomically on the dedicated connection.
	 *
	 * @param callable $callback Repository transaction body.
	 * @return mixed Callback result.
	 * @throws StorageException Nested transaction or database failure.
	 */
	public function transaction( callable $callback ) {
		if ( $this->transaction ) {
			throw new StorageException( 'Nested runtime transactions are not supported.' );
		}
		$this->transaction = true;
		try {
			$this->query( 'START TRANSACTION' );
			$result = $callback( $this );
			$this->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			try {
				$this->query( 'ROLLBACK' );
			} catch ( \Throwable $rollback_error ) {
				// Lost connection: caller must reconcile; never reconnect/replay.
			}
			throw $error;
		} finally {
			$this->transaction = false;
		}
	}
	/**
	 * Let repositories enforce atomic state/event composition.
	 *
	 * @return bool
	 */
	public function in_transaction(): bool {
		return $this->transaction;
	}
}
