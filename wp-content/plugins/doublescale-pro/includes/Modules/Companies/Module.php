<?php
/**
 * Companies module bootstrap.
 *
 * Owns: companies, their people (primary, billing, member), the company page,
 * and records that belong to a company: tickets, invoices, proposals, files,
 * projects, deals and tasks a company's people all see in their client portal.
 *
 * Free keeps a `company_id` on its records and asks about companies through
 * filters (`doublescale_record_company_id`, `doublescale_portal_scope_company_ids`,
 * `doublescale_portal_company`, `doublescale_document_cc`,
 * `doublescale_support_company_cc`); this module answers them.
 *
 * @package DoubleScale\Pro\Modules\Companies
 */

namespace DoubleScale\Pro\Modules\Companies;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'companies';
	}

	public function label(): string {
		return __( 'Companies', 'doublescale' );
	}

	public function description(): string {
		return __( 'Companies with their people, and records the whole company sees in the client portal.', 'doublescale' );
	}

	public function dependencies(): array {
		return array( 'core', 'contacts' );
	}

	/**
	 * Part of Contacts: always on with Pro, no switch on the Modules page.
	 */
	public function is_toggleable(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestCompanyController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		Services\CompanyService::register();

		// Deals that existed before their contact joined a company take it,
		// once per Pro version (after every module's tables are in place).
		if ( function_exists( 'doublescale_ensure_once' ) ) {
			doublescale_ensure_once( 'pro_company_deals', DOUBLESCALE_PRO_VERSION, array( self::class, 'backfill_deal_companies' ) );
		}

		add_filter(
			'doublescale_admin_config',
			static function ( $config ) {
				$config['companiesEnabled'] = true;
				return $config;
			}
		);
	}

	/**
	 * Deals with no company take their contact's.
	 */
	public static function backfill_deal_companies(): void {
		global $wpdb;

		$deals   = $wpdb->prefix . 'doublescale_deals';
		$members = $wpdb->prefix . 'doublescale_company_contact';
		foreach ( array( $deals, $members ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return;
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $wpdb->get_results( "SHOW COLUMNS FROM `{$deals}` LIKE 'company_id'" ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE `{$deals}` d INNER JOIN `{$members}` m ON m.contact_id = d.contact_id SET d.company_id = m.company_id WHERE d.company_id IS NULL" );
	}
}
