<?php

namespace SEOPressPro\Core\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Models\Table\TableColumnInterface;

class QueryCreateTable {

	protected $queryExistTable;

	public function __construct( ?QueryExistTable $queryExistTable = null ) {
		// Initialize QueryExistTable either via dependency injection or fallback
		$this->queryExistTable = $queryExistTable ?: new QueryExistTable();
	}

	// Construct the SQL statement for a column definition
	public function constructColumn( TableColumnInterface $column, $isAlter = false ) {
		$line = sprintf( '%s %s', $column->getName(), $column->getType() );

		// Handle primary key separately
		if ( $column->getPrimaryKey() && ! $isAlter ) {
			$line .= ' NOT NULL AUTO_INCREMENT';
		} else {
			$defaultValue = $column->getDefaultValue();
			if ( $defaultValue !== null ) {
				// SQL expressions that must not be quoted, otherwise MySQL treats them as string
				// literals and rejects them on typed columns (e.g. CURRENT_TIMESTAMP on datetime).
				$sqlExpressions = array( 'CURRENT_TIMESTAMP', 'NOW()', 'NULL' );
				if ( is_string( $defaultValue ) && in_array( strtoupper( $defaultValue ), $sqlExpressions, true ) ) {
					$line .= sprintf( ' DEFAULT %s', strtoupper( $defaultValue ) );
				} else {
					$line .= sprintf( " DEFAULT '%s'", $defaultValue );
				}
			} else {
				$line .= ' DEFAULT NULL';
			}
		}

		return $line;
	}


	// Generate the primary key definition
	public function getPrimaryKey( $columns ) {
		$value = '';
		foreach ( $columns as $column ) {
			if ( ! $column->getPrimaryKey() ) {
				continue;
			}

			if ( empty( $value ) ) {
				$value .= 'PRIMARY KEY (';
			} else {
				$value .= ', ';
			}

			$value .= $column->getName();
		}

		if ( ! empty( $value ) ) {
			$value .= ')';
		}

		return $value;
	}

	// Add missing columns to an existing table
	public function addMissingColumns( TableInterface $table ) {
		global $wpdb;

		$newColumns = array();
		$columns    = $table->getColumns();
		$tableName  = $wpdb->prefix . $table->getName();

		// Loop through the columns and check if they exist
		foreach ( $columns as $column ) {
			if ( ! $this->queryExistTable->columnExists( $table, $column ) ) {
				// If the column does not exist, prepare it for addition
				$newColumns[] = $this->constructColumn( $column, true ); // true indicates ALTER
			}
		}

		// If new columns are detected, run ALTER TABLE to add them
		if ( ! empty( $newColumns ) ) {
			$sql = "ALTER TABLE {$tableName} ADD (" . implode( ', ', $newColumns ) . ')';
			try {
				$wpdb->query( $sql );
			} catch ( \Exception $e ) {
				return false;
			}
		}
	}

	// Create the table if it doesn't exist
	public function create( TableInterface $table ) {
		global $wpdb;

		$charset   = $wpdb->get_charset_collate();
		$indexes   = array();
		$columns   = $table->getColumns();
		$tableName = $wpdb->prefix . $table->getName();

		// Prepare the columns and primary key
		$data = array();
		foreach ( $columns as $column ) {
			$data[] = $this->constructColumn( $column );
			if ( $column->getIndex() ) {
				$indexes[] = "CREATE INDEX idx_{$column->getName()} ON {$tableName} ({$column->getName()})";
			}
		}

		$primaryKey = $this->getPrimaryKey( $columns );
		if ( ! empty( $primaryKey ) ) {
			$data[] = $primaryKey;
		}

		// Create the SQL statement for creating the table
		$sql   = array();
		$sql[] = "CREATE TABLE {$tableName} (";
		$sql[] = implode( ', ', $data );
		$sql[] = ") {$charset};";
		$sql   = implode( "\n", $sql );

		// Use dbDelta to create the table
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		try {
			maybe_create_table( $tableName, $sql );
		} catch ( \Exception $e ) {
			return false;
		}

		// Create the indexes
		try {
			foreach ( $indexes as $index ) {
				$wpdb->query( $index );
			}
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
