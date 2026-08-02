<?php

namespace SEOPressPro\Services\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Core\Table\QueryCreateTable;
use SEOPressPro\Core\Table\QueryExistTable;

class TableManager {

	protected $queryCreateTable;

	protected $queryExistTable;

	public function __construct() {
		$this->queryCreateTable = new QueryCreateTable();
		$this->queryExistTable  = new QueryExistTable();
	}

	public function exist( TableInterface $table ) {
		return $this->queryExistTable->exist( $table );
	}

	public function create( TableInterface $table ) {
		// Check if the table exists
		$tableExists = $this->exist( $table );

		// If the table does not exist, create it
		if ( ! $tableExists ) {
			$this->queryCreateTable->create( $table );
		} else {
			// If the table exists, check for missing columns and add them
			$this->queryCreateTable->addMissingColumns( $table );
		}
	}

	public function createTablesIfNeeded( $tables ) {
		foreach ( $tables as $table ) {
			$this->create( $table );
		}
	}
}
