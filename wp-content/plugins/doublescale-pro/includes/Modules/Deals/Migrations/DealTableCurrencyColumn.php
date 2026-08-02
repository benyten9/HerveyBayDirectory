<?php
/**
 * Add nullable currency column to deals and backfill linked deals only.
 *
 * Unlinked deals stay NULL so they continue following the global settings
 * currency. Deals already tied to a proposal or invoice get a one-time freeze
 * from that document's currency.
 *
 * @package DoubleScale\Pro\Modules\Deals\Migrations
 */

namespace DoubleScale\Pro\Modules\Deals\Migrations;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;

/**
 * DealTableCurrencyColumn migration.
 */
class DealTableCurrencyColumn {

	/**
	 * Ensure column + backfill (safe on every boot).
	 *
	 * @return void
	 */
	public static function ensure(): void {
		( new self() )->run();
	}

	/**
	 * Idempotent schema + backfill.
	 *
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$table = $wpdb->prefix . 'doublescale_deals';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$this->ensure_column( $table );
		$this->backfill_linked_deals( $table );
	}

	/**
	 * Add currency column when missing (nullable, no default).
	 *
	 * @param string $table Fully qualified table name.
	 * @return void
	 */
	private function ensure_column( string $table ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'currency'", ARRAY_A );
		if ( ! empty( $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `currency` VARCHAR(10) NULL AFTER `value`"
		);
	}

	/**
	 * Freeze currency on deals that already have a linked proposal or invoice.
	 * Unlinked rows and already-frozen rows are left alone.
	 *
	 * @param string $table Fully qualified deals table name.
	 * @return void
	 */
	private function backfill_linked_deals( string $table ): void {
		global $wpdb;

		$assoc_table = $wpdb->prefix . 'doublescale_activity_associations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$assoc_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $assoc_table ) );
		if ( $assoc_exists !== $assoc_table ) {
			return;
		}

		$deal_type     = (int) ActivityAssociationModel::ENTITY_TYPE_DEAL;
		$invoice_type  = (int) ActivityAssociationModel::ENTITY_TYPE_INVOICE;
		$proposal_type = (int) ActivityAssociationModel::ENTITY_TYPE_PROPOSAL;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deal_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT d.id
				FROM `{$table}` d
				INNER JOIN `{$assoc_table}` deal_assoc
					ON deal_assoc.entity_type = %d AND deal_assoc.entity_id = d.id
				INNER JOIN `{$assoc_table}` doc_assoc
					ON doc_assoc.activity_id = deal_assoc.activity_id
					AND doc_assoc.entity_type IN (%d, %d)
				WHERE d.currency IS NULL OR d.currency = ''",
				$deal_type,
				$invoice_type,
				$proposal_type
			)
		);

		if ( empty( $deal_ids ) ) {
			return;
		}

		$invoices_table  = $wpdb->prefix . 'doublescale_sales_invoices';
		$proposals_table = $wpdb->prefix . 'doublescale_sales_proposals';
		$fallback        = Settings::get_currency();

		foreach ( $deal_ids as $deal_id ) {
			$deal_id  = (int) $deal_id;
			$currency = $this->resolve_linked_document_currency(
				$assoc_table,
				$invoices_table,
				$proposals_table,
				$deal_id,
				$deal_type,
				$invoice_type,
				$proposal_type,
				$fallback
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'currency' => $currency ),
				array( 'id' => $deal_id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Prefer invoice currency, then proposal; drafts resolve via document_currency.
	 *
	 * @param string $assoc_table     Associations table.
	 * @param string $invoices_table  Invoices table.
	 * @param string $proposals_table Proposals table.
	 * @param int    $deal_id         Deal ID.
	 * @param int    $deal_type       Deal entity type.
	 * @param int    $invoice_type    Invoice entity type.
	 * @param int    $proposal_type   Proposal entity type.
	 * @param string $fallback        Global currency fallback.
	 * @return string
	 */
	private function resolve_linked_document_currency(
		string $assoc_table,
		string $invoices_table,
		string $proposals_table,
		int $deal_id,
		int $deal_type,
		int $invoice_type,
		int $proposal_type,
		string $fallback
	): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$invoice = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.currency, i.sent_at
				FROM `{$assoc_table}` deal_assoc
				INNER JOIN `{$assoc_table}` doc_assoc
					ON doc_assoc.activity_id = deal_assoc.activity_id
					AND doc_assoc.entity_type = %d
				INNER JOIN `{$invoices_table}` i ON i.id = doc_assoc.entity_id
				WHERE deal_assoc.entity_type = %d AND deal_assoc.entity_id = %d
				ORDER BY i.id ASC
				LIMIT 1",
				$invoice_type,
				$deal_type,
				$deal_id
			),
			ARRAY_A
		);

		if ( ! empty( $invoice ) ) {
			return Settings::document_currency(
				$invoice['currency'] ?? null,
				$invoice['sent_at'] ?? null
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$proposal = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.currency, p.sent_at
				FROM `{$assoc_table}` deal_assoc
				INNER JOIN `{$assoc_table}` doc_assoc
					ON doc_assoc.activity_id = deal_assoc.activity_id
					AND doc_assoc.entity_type = %d
				INNER JOIN `{$proposals_table}` p ON p.id = doc_assoc.entity_id
				WHERE deal_assoc.entity_type = %d AND deal_assoc.entity_id = %d
				ORDER BY p.id ASC
				LIMIT 1",
				$proposal_type,
				$deal_type,
				$deal_id
			),
			ARRAY_A
		);

		if ( ! empty( $proposal ) ) {
			return Settings::document_currency(
				$proposal['currency'] ?? null,
				$proposal['sent_at'] ?? null
			);
		}

		return $fallback;
	}
}
