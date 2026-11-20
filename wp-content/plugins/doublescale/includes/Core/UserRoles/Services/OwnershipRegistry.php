<?php
/**
 * Every column that says "this record belongs to that user".
 *
 * @package DoubleScale\Core\UserRoles\Services
 */

namespace DoubleScale\Core\UserRoles\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Offboarding moves all of a leaving user's records in one step, so it needs
 * one list of where ownership lives. Pro tables are listed here too: a table
 * that does not exist (module off, Pro inactive) is skipped, not an error.
 */
final class OwnershipRegistry {

	/**
	 * @return array<string, array{table:string, column:string, label:string}>
	 *         Keyed by a stable slug; `table` is without the WP prefix.
	 */
	public static function columns(): array {
		$columns = array(
			'contacts'     => array(
				'table'  => 'doublescale_contacts',
				'column' => 'owner_id',
				'label'  => __( 'Contacts', 'doublescale' ),
			),
			'deals'        => array(
				'table'  => 'doublescale_deals',
				'column' => 'owner_id',
				'label'  => __( 'Deals', 'doublescale' ),
			),
			'tasks'        => array(
				'table'  => 'doublescale_tasks',
				'column' => 'assigned_to',
				'label'  => __( 'Tasks', 'doublescale' ),
			),
			'projects'     => array(
				'table'  => 'doublescale_projects',
				'column' => 'owner_id',
				'label'  => __( 'Projects', 'doublescale' ),
			),
			'tickets'      => array(
				'table'  => 'doublescale_support_tickets',
				'column' => 'agent_user_id',
				'label'  => __( 'Support tickets', 'doublescale' ),
			),
			'invoices'     => array(
				'table'  => 'doublescale_sales_invoices',
				'column' => 'sale_agent_user_id',
				'label'  => __( 'Invoices', 'doublescale' ),
			),
			'proposals'    => array(
				'table'  => 'doublescale_sales_proposals',
				'column' => 'assigned_user_id',
				'label'  => __( 'Proposals', 'doublescale' ),
			),
			'contracts'    => array(
				'table'  => 'doublescale_sales_contracts',
				'column' => 'assigned_user_id',
				'label'  => __( 'Contracts', 'doublescale' ),
			),
			'credit_notes' => array(
				'table'  => 'doublescale_sales_credit_notes',
				'column' => 'sale_agent_user_id',
				'label'  => __( 'Credit notes', 'doublescale' ),
			),
		);

		/**
		 * Filters the columns offboarding reassigns.
		 *
		 * @param array<string, array{table:string, column:string, label:string}> $columns
		 */
		$filtered = apply_filters( 'doublescale_owned_record_columns', $columns );

		return is_array( $filtered ) ? $filtered : $columns;
	}

	/**
	 * Whether a (prefixed) table exists. Memoised per request.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		static $known = array();

		if ( ! array_key_exists( $table, $known ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$known[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table );
		}

		return $known[ $table ];
	}
}
