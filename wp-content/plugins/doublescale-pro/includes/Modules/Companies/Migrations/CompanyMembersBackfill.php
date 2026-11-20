<?php
/**
 * One-time backfill: turn the free-text `company_name` on contacts into
 * company records and link those contacts as members.
 *
 * Names are grouped trimmed and case-insensitively, so "ACME" and "acme "
 * become one company. The earliest contact in a group is the primary
 * contact. Contacts already in a company are left alone, so a rerun after
 * the ledger was cleared does not duplicate anything.
 *
 * @package DoubleScale\Modules\Contacts\Migrations
 */

namespace DoubleScale\Pro\Modules\Companies\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * CompanyMembersBackfill migration.
 */
class CompanyMembersBackfill {

	/**
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$contacts  = $wpdb->prefix . 'doublescale_contacts';
		$companies = $wpdb->prefix . 'doublescale_companies';
		$members   = $wpdb->prefix . 'doublescale_company_contact';

		foreach ( array( $contacts, $companies, $members ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT c.id, TRIM(c.company_name) AS name, c.company_registration_number AS reg, c.tax_vat_number AS vat
			FROM `{$contacts}` c
			LEFT JOIN `{$members}` m ON m.contact_id = c.id
			WHERE c.company_name IS NOT NULL AND TRIM(c.company_name) <> '' AND m.id IS NULL
			ORDER BY c.id ASC"
		);

		if ( empty( $rows ) ) {
			return;
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$key              = function_exists( 'mb_strtolower' ) ? mb_strtolower( $row->name ) : strtolower( $row->name );
			$groups[ $key ][] = $row;
		}

		$now = current_time( 'mysql', true );
		foreach ( $groups as $group ) {
			$first = $group[0];

			$company_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM `{$companies}` WHERE LOWER(TRIM(name)) = LOWER(%s) LIMIT 1", $first->name )
			);

			if ( ! $company_id ) {
				$wpdb->insert(
					$companies,
					array(
						'name'                => $first->name,
						'registration_number' => $first->reg ?: null,
						'tax_vat_number'      => $first->vat ?: null,
						'created_at'          => $now,
						'updated_at'          => $now,
					)
				);
				$company_id = (int) $wpdb->insert_id;
				$has_primary = false;
			} else {
				$has_primary = (bool) $wpdb->get_var(
					$wpdb->prepare( "SELECT 1 FROM `{$members}` WHERE company_id = %d AND role = 'primary' LIMIT 1", $company_id )
				);
			}

			if ( ! $company_id ) {
				continue;
			}

			foreach ( $group as $row ) {
				$wpdb->insert(
					$members,
					array(
						'company_id' => $company_id,
						'contact_id' => (int) $row->id,
						'role'       => $has_primary ? 'member' : 'primary',
						'created_at' => $now,
					)
				);
				$has_primary = true;
			}
		}
		// phpcs:enable
	}
}
