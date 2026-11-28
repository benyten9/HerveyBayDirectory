<?php
/**
 * Pure, conservative recognition of one native public candidate query.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Domain;

/** The caller owns current-site authority and native query/hook execution. */
final class CandidateQuerySql {
	/** Recognition budget only; larger statements keep their native SQL. */
	private const MAX_BYTES = 16384;

	/**
	 * Replace only the required directory join with duplicate-safe membership.
	 *
	 * Table arguments must come from the caller's current wpdb, not model input.
	 * Unsupported statements return null so the original query remains in force.
	 *
	 * @param string $sql          Compiled native public candidate SQL.
	 * @param string $posts        Current site's native posts table name.
	 * @param string $postmeta     Current site's native postmeta table name.
	 * @param int    $directory_id Positive native directory ID.
	 * @param string $post_type    Bound native listing post type.
	 * @return string|null Equivalent membership SQL, or null for native fallback.
	 */
	public static function rewrite( string $sql, string $posts, string $postmeta, int $directory_id, string $post_type ): ?string {
		if ( strlen( $sql ) > self::MAX_BYTES || strlen( $posts ) > 64 || strlen( $postmeta ) > 64 || ! preg_match( '/\A([A-Za-z0-9_]*)posts\z/', $posts, $tables ) || $postmeta !== $tables[1] . 'postmeta' || $directory_id < 1 || ! preg_match( '/\A[A-Za-z0-9_-]{1,20}\z/', $post_type ) ) {
			return null;
		}
		$p          = preg_quote( $posts, '~' );
		$m          = preg_quote( $postmeta, '~' );
		$type       = preg_quote( $post_type, '~' );
		$column     = static fn( string $table, string $field ): string => $table . '\s*\.\s*' . $field;
		$id         = $column( $p, 'ID' );
		$meta_id    = $column( $m, 'post_id' );
		$meta_key   = $column( $m, 'meta_key' );
		$meta_value = $column( $m, 'meta_value' );
		// A whole-statement match proves the metadata leaf is a required positive
		// AND conjunct and that no other expression depends on the removed rows.
		$pattern = '~\A\s*SELECT\s+(?:DISTINCT\s+)?' . $id . '\s+FROM\s+' . $p . '\s+'
			. '(?<join>INNER\s+JOIN\s+' . $m . '\s+ON\s*\(\s*' . $id . '\s*=\s*' . $meta_id . '\s*\))'
			. '\s+WHERE\s+1\s*=\s*1\s+AND\s*\(\s*\(\s*'
			. '(?<leaf>' . $meta_key . "\s*=\s*'_directory_type'\s+AND\s+CAST\s*\(\s*" . $meta_value . "\s+AS\s+SIGNED\s*\)\s*=\s*'" . $directory_id . "')"
			. '\s*\)\s*\)\s+AND\s+' . $column( $p, 'post_password' ) . "\s*=\s*''"
			. '\s+AND\s+' . $column( $p, 'post_type' ) . "\s*=\s*'" . $type . "'"
			. '\s+AND\s*\(\s*\(\s*' . $column( $p, 'post_status' ) . "\s*=\s*'publish'\s*\)\s*\)"
			. '\s+GROUP\s+BY\s+' . $id
			. '\s+ORDER\s+BY\s+(?:' . $column( $p, 'menu_order' ) . '\s*,\s*)?' . $id . '\s+ASC'
			. '\s+LIMIT\s+(?<offset>0|[1-9][0-9]*)\s*,\s*(?<size>[1-9][0-9]*)\s*\z~';
		if ( 1 !== preg_match( $pattern, $sql, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$offset  = $match['offset'][0];
		$maximum = (string) PHP_INT_MAX;
		$size    = $match['size'][0];
		if ( strlen( $offset ) > strlen( $maximum ) || ( strlen( $offset ) === strlen( $maximum ) && strcmp( $offset, $maximum ) > 0 ) || strlen( $size ) > 4 || (int) $size > 1001 ) {
			return null;
		}
		$membership = "INNER JOIN (SELECT DISTINCT post_id FROM $postmeta WHERE meta_key = '_directory_type' AND CAST(meta_value AS SIGNED) = '$directory_id') dai_directory_membership ON ($posts.ID = dai_directory_membership.post_id)";
		// Apply from the later offset so every other original byte is untouched.
		$result = substr_replace( $sql, '1=1', $match['leaf'][1], strlen( $match['leaf'][0] ) );
		return substr_replace( $result, $membership, $match['join'][1], strlen( $match['join'][0] ) );
	}
}
