<?php

namespace SEOPressPro\Core\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Models\Table\TableColumnInterface;

class QueryExistTable {

	public function exist( TableInterface $table ) {
		global $wpdb;

		$query = "SHOW TABLES LIKE '{$wpdb->prefix}{$table->getName()}'";
		try {
			$result = $wpdb->query( $query );
			return $result !== 0;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public function columnExists( TableInterface $table, TableColumnInterface $column ) {
		global $wpdb;

		$tableName  = $wpdb->prefix . $table->getName();
		$columnName = $column->getName();

		// Check if the column exists
		$query  = $wpdb->prepare( "SHOW COLUMNS FROM {$tableName} LIKE %s", $columnName );
		$result = $wpdb->get_var( $query );

		return ! empty( $result );
	}
}
