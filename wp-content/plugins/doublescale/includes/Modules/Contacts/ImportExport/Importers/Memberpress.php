<?php
/**
 * MemberPress Importer
 *
 * This class is responsible for handling the MemberPress importer
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Contacts\ImportExport\Importers;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Abstracts\Importer;

/**
 * MemberPress Importer class
 */
class Memberpress extends Importer {

	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'MemberPress';

	/**
	 * Slug
	 *
	 * @var string
	 */
	public $slug = 'memberpress';

	/**
	 * Is Integration
	 *
	 * @var bool
	 */
	protected $is_integration = false;

	/**
	 * Selected membership IDs to filter by
	 *
	 * @var array
	 */
	protected $membership_filter = array();

	/**
	 * Constructor
	 *
	 * @param array $args args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( $args );
		$this->membership_filter = $args['membership_filter'] ?? array();
	}

	/**
	 * Is Active
	 *
	 * @var bool
	 */
	public function is_active() {
		return doublescale_is_plugin_active( 'memberpress/memberpress.php' );
	}

	/**
	 * Run importer
	 */
	public function run() {
		global $wpdb;

		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are from MemberPress plugin tables.

		$members_table      = $wpdb->prefix . 'mepr_members';
		$transactions_table = $wpdb->prefix . 'mepr_transactions';
		$users_table        = $wpdb->users;
		$usermeta_table     = $wpdb->usermeta;
		$posts_table        = $wpdb->posts;

		$mapping = array(
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'email'      => 'user_email',
		);

		$filter_ids    = array_map( 'intval', $this->membership_filter );
		$has_filter    = ! empty( $filter_ids );
		$filter_clause = '';

		if ( $has_filter ) {
			$placeholders = implode( ',', array_fill( 0, count( $filter_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $transactions_table is a trusted prefixed table name; $placeholders is the dynamically built '%d,%d…' placeholder string bound via spread $filter_ids.
			$filter_clause = $wpdb->prepare(
				"INNER JOIN $transactions_table AS tf ON m.user_id = tf.user_id AND tf.status = 'complete' AND tf.product_id IN ($placeholders)",
				...$filter_ids
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		$count_sql = "SELECT COUNT(DISTINCT m.user_id) FROM $members_table AS m";
		if ( $has_filter ) {
			$count_sql .= " $filter_clause";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $members_table is a trusted table-name constant; $filter_clause is already prepared above via $wpdb->prepare().
		$total = $wpdb->get_var( $count_sql );

		$result = $this->import_with_offset(
			$total,
			$this->offset,
			function ( $offset ) use ( $wpdb, $members_table, $transactions_table, $users_table, $usermeta_table, $posts_table, $filter_clause, $has_filter ) {
				$base_query = "SELECT
						u.user_email,
						fn.meta_value AS first_name,
						ln.meta_value AS last_name,
						GROUP_CONCAT(DISTINCT p.post_title SEPARATOR ',') AS lists,
						GROUP_CONCAT(DISTINCT p.post_title SEPARATOR ',') AS tags
					FROM $members_table AS m
					$filter_clause
					INNER JOIN $users_table AS u ON m.user_id = u.ID
					LEFT JOIN $usermeta_table AS fn ON m.user_id = fn.user_id AND fn.meta_key = 'first_name'
					LEFT JOIN $usermeta_table AS ln ON m.user_id = ln.user_id AND ln.meta_key = 'last_name'
					LEFT JOIN $transactions_table AS t ON m.user_id = t.user_id AND t.status = 'complete'
					LEFT JOIN $posts_table AS p ON t.product_id = p.ID AND p.post_type = 'memberpressproduct'
					GROUP BY m.user_id, u.user_email, fn.meta_value, ln.meta_value
					LIMIT %d, 20";

				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $base_query interpolates trusted table-name vars and a pre-prepared $filter_clause; $offset is bound via prepare() on the next line.
				return $wpdb->get_results(
					$wpdb->prepare( $base_query, $offset ),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			},
			$mapping
		);

		// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $result;
	}

	/**
	 * Get memberships
	 *
	 * @return array
	 */
	protected function get_memberships() {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Querying MemberPress membership CPT.
		$memberships = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_title ASC",
				'memberpressproduct',
				'publish'
			)
		);

		$result = array();
		foreach ( $memberships ?? array() as $membership ) {
			$result[] = array(
				'key'   => (string) $membership->ID,
				'label' => $membership->post_title,
			);
		}

		return $result;
	}

	/**
	 * Get fields
	 *
	 * @return array
	 */
	public function get_fields() {
		$memberships = $this->get_memberships();

		return array(
			'membership_filter' => array(
				'type'    => 'membership_filter',
				'label'   => __( 'Filter by Membership', 'doublescale' ),
				'options' => $memberships,
				'tooltip' => __( 'Select which MemberPress memberships to import. Only members with a completed transaction for the selected memberships will be imported. Leave empty to import all members.', 'doublescale' ),
			),
			'lists_mapping'     => array(
				'type'    => 'lists_mapping',
				'label'   => __( 'Memberships to Lists Mapping', 'doublescale' ),
				'options' => $memberships,
				'tooltip' => __( 'Map MemberPress memberships to Plugin lists. For each membership level (e.g. Gold, Basic), you can either: 1) "Assign to (Plugin)" - Choose one or more existing Plugin lists to add contacts to, or 2) "Auto Create" - Automatically create a new Plugin list with the same name as the membership. Contacts will only be added to lists for memberships they have a completed transaction for.', 'doublescale' ),
			),
			'tags_mapping'      => array(
				'type'    => 'tags_mapping',
				'label'   => __( 'Memberships to Tags Mapping', 'doublescale' ),
				'options' => $memberships,
				'tooltip' => __( 'Map MemberPress memberships to Plugin tags. For each membership level (e.g. Gold, Basic), you can either: 1) "Assign to (Plugin)" - Choose one or more existing Plugin tags to apply to contacts, or 2) "Auto Create" - Automatically create a new Plugin tag with the same name as the membership. Contacts will only receive tags for memberships they have a completed transaction for.', 'doublescale' ),
			),
		);
	}
}
