<?php

namespace SEOPressPro\Services\Repository;

defined( 'ABSPATH' ) || exit;

use SEOPress\Models\AbstractRepository;

class SEOIssuesRepository extends AbstractRepository {


	public function __construct() {
		$this->table = seopress_pro_get_service( 'TableList' )->getTableSEOIssues();
	}

	protected function getAuthorizedInsertValues(): array {
		return array(
			'post_id',
			'issue_name',
			'issue_desc',
			'issue_type',
			'issue_priority',
			'issue_ignore',
		);
	}

	protected function getAuthorizedUpdateValues(): array {
		return array(
			'issue_name',
			'issue_desc',
			'issue_type',
			'issue_priority',
			'issue_ignore',
		);
	}

	/**
	 * Remove every issue row for a post, whatever the type.
	 *
	 * Used when the post leaves the audit entirely: permanent deletion, or a
	 * scan that skips it (noindex, active redirection). Nothing else ever
	 * removes these rows, so without this the audit keeps reporting pages
	 * that no longer exist or are deliberately out of scope.
	 *
	 * @param int $postId Post id.
	 *
	 * @return int|false Number of rows removed, or false on error.
	 */
	public function deleteAllForPost( $postId ) {
		global $wpdb;

		$postId = absint( $postId );

		if ( empty( $postId ) ) {
			return false;
		}

		$tableName = esc_sql( $this->getTableName() );

		try {
			return $wpdb->delete(
				$tableName,
				array( 'post_id' => $postId ),
				array( '%d' )
			);
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public function issueAlreadyExistForPostId( $postId, $issue_name ) {
		global $wpdb;

		$postId     = absint( $postId );
		$issue_name = sanitize_text_field( $issue_name );

		$tableName = esc_sql( $this->getTableName() );

		$sql = $wpdb->prepare( "SELECT id FROM {$tableName} WHERE post_id = %d AND issue_name = %s", $postId, $issue_name );

		$result = $wpdb->get_results( $sql );

		return ! empty( $result );
	}

	/**
	 * @param array $issue
	 */
	/**
	 * Reduce an issue to the columns it is allowed to write, ready for $wpdb.
	 *
	 * The values reach this class from the site audit, which reads them out of
	 * the post content (image URLs, alt texts, headings), so they are authored
	 * by anyone who can edit a post. They belong in bound parameters, not in a
	 * concatenated statement.
	 *
	 * @param array $issue      The issue data.
	 * @param array $authorized The column names that may be written.
	 *
	 * @return array Column name => scalar value.
	 */
	protected function toColumns( array $issue, array $authorized ) {
		$data = array();

		foreach ( $authorized as $column ) {
			if ( ! isset( $issue[ $column ] ) ) {
				continue;
			}

			$value = $issue[ $column ];

			if ( $value instanceof \DateTime ) {
				$value = $value->format( 'Y-m-d H:i:s' );
			} elseif ( is_array( $value ) ) {
				$value = empty( $value ) ? null : maybe_serialize( $value );
			} elseif ( in_array( $column, array( 'post_id', 'issue_ignore' ), true ) ) {
				// The integer columns were cast by the previous statement builder.
				$value = (int) $value;
			}

			$data[ $column ] = $value;
		}

		return $data;
	}

	/**
	 * @param array $issue
	 */
	public function insertSEOIssue( $issue ) {

		global $wpdb;

		$data = $this->toColumns( (array) $issue, $this->getAuthorizedInsertValues() );

		if ( empty( $data ) ) {
			return null;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, values are bound by $wpdb->insert().
			return $wpdb->insert( $this->getTableName(), $data );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public function updateSEOIssue( $postId, $issue ) {
		global $wpdb;

		$postId     = absint( $postId );
		$issue_name = sanitize_text_field( $issue['issue_name'] );

		$data = $this->toColumns( (array) $issue, $this->getAuthorizedUpdateValues() );

		if ( empty( $data ) ) {
			return null;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table, values and WHERE are bound by $wpdb->update().
			return $wpdb->update(
				$this->getTableName(),
				$data,
				array(
					'post_id'    => $postId,
					'issue_name' => $issue_name,
				)
			);
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public function deleteSEOIssue( $postId, $issue_type ) {
		global $wpdb;

		$postId     = absint( $postId );
		$issue_type = sanitize_text_field( $issue_type );

		$tableName = esc_sql( $this->getTableName() );

		try {
			return $wpdb->delete(
				$tableName,
				array(
					'post_id'    => $postId,
					'issue_type' => $issue_type,
				),
				array(
					'%d',
					'%s',
				)
			);
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Remove rows for ($postId, $issue_type) whose issue_name is NOT in
	 * $keep_names. Used after a content-analysis rescan to drop the issues
	 * that no longer apply while preserving the row ids (and therefore the
	 * issue_ignore flag) of the issues that are still active.
	 *
	 * Passing an empty $keep_names array deletes every row for that pair,
	 * matching the legacy deleteSEOIssue() behaviour.
	 *
	 * @param int      $postId     Post id.
	 * @param string   $issue_type Issue type (e.g. 'img_alt').
	 * @param string[] $keep_names issue_name values that must survive.
	 *
	 * @return int|false Number of rows removed, or false on error.
	 */
	public function deleteOrphans( $postId, $issue_type, array $keep_names ) {
		global $wpdb;

		$postId     = absint( $postId );
		$issue_type = sanitize_text_field( $issue_type );

		if ( empty( $postId ) || '' === $issue_type ) {
			return false;
		}

		$tableName = esc_sql( $this->getTableName() );

		// No survivors: behave like deleteSEOIssue().
		if ( empty( $keep_names ) ) {
			try {
				return $wpdb->delete(
					$tableName,
					array(
						'post_id'    => $postId,
						'issue_type' => $issue_type,
					),
					array(
						'%d',
						'%s',
					)
				);
			} catch ( \Exception $e ) {
				return false;
			}
		}

		$keep_names = array_values(
			array_unique(
				array_map( 'sanitize_text_field', $keep_names )
			)
		);

		$placeholders = implode( ', ', array_fill( 0, count( $keep_names ), '%s' ) );
		$params       = array_merge( array( $postId, $issue_type ), $keep_names );

		try {
			return $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"DELETE FROM {$tableName} WHERE post_id = %d AND issue_type = %s AND issue_name NOT IN ({$placeholders})",
					$params
				)
			);
		} catch ( \Exception $e ) {
			return false;
		}
	}

	public function getSEOIssue( $postId, $columns = array( '*' ) ) {

		global $wpdb;
		$strColumns = implode( ', ', $columns );
		$sql        = $wpdb->prepare(
			"SELECT *
             FROM {$this->getTableName()}
             WHERE post_id = %d AND issue_type = %s
             LIMIT 100",
			$postId,
			$strColumns
		);

		$result = $wpdb->get_results( $sql, ARRAY_A );

		if ( empty( $result ) ) {
			return null;
		}

		return array_map( 'maybe_unserialize', $result );
	}
}
