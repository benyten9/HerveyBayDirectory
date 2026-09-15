<?php
/**
 * Add missing columns declared on a CREATE TABLE statement.
 *
 * ALTER migrations that use AFTER a later column fail silently and get
 * recorded as ran. This helper reads the current CREATE definition and
 * adds any column that is not on the physical table, with no AFTER clause.
 *
 * @package DoubleScale\Core\Database
 */

namespace DoubleScale\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * SchemaColumns helper.
 */
final class SchemaColumns {

	/**
	 * @param string $table Fully qualified table name.
	 * @param string $query Column list from {@see Migration::get_query()}.
	 * @return void
	 */
	public static function ensure_from_query( string $table, string $query ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$declared = self::parse_columns( $query );
		if ( empty( $declared ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$present = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );
		$present = array_map( 'strtolower', is_array( $present ) ? $present : array() );

		foreach ( $declared as $name => $definition ) {
			if ( in_array( strtolower( $name ), $present, true ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD `{$name}` {$definition}" );
		}
	}

	/**
	 * @param string $query CREATE TABLE inner list.
	 * @return array<string, string> Column name => SQL definition (without the name).
	 */
	public static function parse_columns( string $query ): array {
		$columns = array();

		foreach ( self::split_definitions( $query ) as $part ) {
			if ( preg_match( '/^(PRIMARY\s+KEY|UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX|CONSTRAINT)\b/i', $part ) ) {
				continue;
			}

			if ( ! preg_match( '/^`?([A-Za-z0-9_]+)`?\s+(.+)$/s', $part, $matches ) ) {
				continue;
			}

			$columns[ $matches[1] ] = trim( $matches[2] );
		}

		return $columns;
	}

	/**
	 * Split a column list on commas that are not inside parentheses or quotes.
	 *
	 * @param string $query CREATE TABLE inner list.
	 * @return string[]
	 */
	private static function split_definitions( string $query ): array {
		$parts  = array();
		$buffer = '';
		$depth  = 0;
		$quote  = '';
		$length = strlen( $query );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $query[ $i ];

			if ( '' !== $quote ) {
				$buffer .= $char;
				if ( $char === $quote && ( 0 === $i || '\\' !== $query[ $i - 1 ] ) ) {
					$quote = '';
				}
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}

			if ( '(' === $char ) {
				++$depth;
				$buffer .= $char;
				continue;
			}

			if ( ')' === $char ) {
				$depth = max( 0, $depth - 1 );
				$buffer .= $char;
				continue;
			}

			if ( ',' === $char && 0 === $depth ) {
				$trimmed = trim( $buffer );
				if ( '' !== $trimmed ) {
					$parts[] = $trimmed;
				}
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		$trimmed = trim( $buffer );
		if ( '' !== $trimmed ) {
			$parts[] = $trimmed;
		}

		return $parts;
	}
}
