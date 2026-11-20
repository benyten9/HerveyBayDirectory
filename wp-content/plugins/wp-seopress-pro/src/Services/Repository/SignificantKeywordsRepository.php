<?php

namespace SEOPressPro\Services\Repository;

defined( 'ABSPATH' ) || exit;



class SignificantKeywordsRepository {

	protected $tableSignificantWords;

	public function __construct() {
		$tables = seopress_pro_get_service( 'TableList' )->getTables();
		seopress_pro_get_service( 'TableManager' )->createTablesIfNeeded( $tables );
		$this->tableSignificantWords = seopress_pro_get_service( 'TableList' )->getTableSignificantKeywords();
	}

	/**
	 *
	 * @param string $word
	 * @return array
	 */
	public function getAllWordsCorrespondent( $word, $postId ) {

		global $wpdb;

		$postTypes     = seopress_get_service( 'WordPressData' )->getPostTypes();
		$keysPostTypes = array();
		foreach ( $postTypes as $key => $post ) {
			$keysPostTypes[] = $key;
		}

		$keysPostTypes = array_map(
			function ( $item ) {
				return "'" . esc_sql( $item ) . "'";
			},
			$keysPostTypes
		);

		$alias = $this->tableSignificantWords->getAlias();
		$sql   = "SELECT {$alias}.* ";
		$sql  .= "FROM {$wpdb->prefix}{$this->tableSignificantWords->getName()} {$alias} ";
		$sql  .= "INNER JOIN {$wpdb->posts} p ON p.ID = {$alias}.post_id ";
		$sql  .= "WHERE {$alias}.word = %s ";
		$sql  .= "AND {$alias}.post_id != %d ";
		$sql  .= "AND p.post_status IN ('publish', 'draft', 'pending', 'future') ";
		$sql  .= 'AND p.post_type IN (' . implode( ',', $keysPostTypes ) . ') ';

		$sql = $wpdb->prepare( $sql, array( $word, $postId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names come from the plugin own table definitions, never from user input.

		$data = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepared statement built above.

		return $data;
	}

	/**
	 *
	 * @param string $word
	 * @return int
	 */
	public function countAllWordsCorrespondent( $word ) {

		global $wpdb;

		$alias = $this->tableSignificantWords->getAlias();
		$sql   = "SELECT COUNT({$alias}.id) ";
		$sql  .= "FROM {$wpdb->prefix}{$this->tableSignificantWords->getName()} {$this->tableSignificantWords->getAlias()} ";
		$sql  .= "WHERE {$alias}.word = %s";

		$sql  = $wpdb->prepare( $sql, $word ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names come from the plugin own table definitions, never from user input.
		$data = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepared statement built above.

		return (int) $data;
	}

	/**
	 *
	 * @return int
	 */
	public function countAllDocuments() {

		global $wpdb;

		$alias = $this->tableSignificantWords->getAlias();
		$sql   = "SELECT COUNT(DISTINCT {$alias}.post_id) as total ";
		$sql  .= "FROM {$wpdb->prefix}{$this->tableSignificantWords->getName()} {$alias} ";

		$data = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names come from the plugin own table definitions, never from user input.

		return (int) $data;
	}

	/**
	 *
	 * @return string
	 */
	protected function getInsertInstruction() {
		global $wpdb;

		return "
            INSERT INTO {$wpdb->prefix}{$this->tableSignificantWords->getName()}
            (
                post_id,
                word,
                count,
                tf
            ) VALUES
        ";
	}

	/**
	 * Get VALUES for INSERT INTO
	 *
	 * @param array $args
	 * @return string
	 */
	protected function getInsertValuesInstruction( $args ) {
		global $wpdb;

		$argsDefault = array(
			'post_id' => 0,
			'word'    => '',
			'count'   => 0,
			'tf'      => 0,
		);

		$args = array_merge( $argsDefault, $args );

		// The word comes straight out of the post content, so it has to be
		// bound: an apostrophe alone used to break the INSERT.
		return $wpdb->prepare(
			'( %d, %s, %d, %f )',
			$args['post_id'],
			$args['word'],
			$args['count'],
			$args['tf']
		);
	}

	public function insertSignificantKeywords( $significantKeywords ) {

		// An empty batch inserts zero rows and must not build an invalid query.
		if ( empty( $significantKeywords ) ) {
			return 0;
		}

		global $wpdb;

		$sql    = $this->getInsertInstruction();
		$values = array();

		foreach ( $significantKeywords as $key => $keyword ) {
			$values[] = $this->getInsertValuesInstruction( $keyword );
		}
		$sql .= implode( ', ', $values );

		try {
			return $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is the prepared statement built above.
		} catch ( \Exception $e ) {
			return null;
		}
	}

	public function removeSignificantKeywordsByPostId( $postId ) {
		global $wpdb;

		try {
			$wpdb->delete( $wpdb->prefix . $this->tableSignificantWords->getName(), array( 'post_id' => $postId ) );
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
