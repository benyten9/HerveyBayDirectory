<?php

namespace SEOPressPro\Core\Table;

defined( 'ABSPATH' ) || exit;

use SEOPressPro\Models\Table\TableInterface;
use SEOPressPro\Models\Table\TableColumnInterface;

class QueryExistTable {

	public function exist( TableInterface $table ) {
		global $wpdb;

		$tableName = $wpdb->prefix . $table->getName();
		$query     = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tableName ) );
		try {
			$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is the prepared statement built above.
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
		$result = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepared statement built above.

		return ! empty( $result );
	}
}
