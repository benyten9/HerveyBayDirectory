<?php
/**
 * Proposals & invoices sub-feature under Sales.
 *
 * @package DoubleScale\Modules\Documents
 */

namespace DoubleScale\Modules\Documents;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Modules\Documents\Renderer\InvoiceFrontendHandler;
use DoubleScale\Modules\Documents\Renderer\ProposalFrontendHandler;
use DoubleScale\Modules\Documents\Rest\Controllers\RestInvoiceController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestInvoiceOnlinePaymentController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestInvoicePaymentController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestPaymentController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestProposalController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestPublicInvoiceController;
use DoubleScale\Modules\Documents\Rest\Controllers\RestPublicProposalController;
use DoubleScale\Modules\Documents\Services\ExpiringProposals;
use DoubleScale\Modules\Documents\Services\OverdueInvoices;
use DoubleScale\Modules\Sales\Services\SalesRepNotifications;

/**
 * Sales documents module (proposals + invoices + payments).
 */
final class Module extends AbstractSalesChildModule {

	public function slug(): string {
		return 'documents';
	}

	public function label(): string {
		return __( 'Proposals & Invoices', 'doublescale' );
	}

	public function description(): string {
		return __( 'Create proposals and invoices, record payments, and manage the full quote-to-cash flow.', 'doublescale' );
	}

	/**
	 * Honors legacy `proposals` / `invoices` toggles until `documents` is saved explicitly.
	 *
	 * @param array<string, mixed> $stored Normalized `doublescale_enabled_modules` array.
	 * @return bool
	 */
	protected function child_stored_intent( array $stored ): bool {
		if ( array_key_exists( $this->slug(), $stored ) ) {
			return (bool) $stored[ $this->slug() ];
		}

		$proposals = ! array_key_exists( 'proposals', $stored ) || (bool) $stored['proposals'];
		$invoices  = ! array_key_exists( 'invoices', $stored ) || (bool) $stored['invoices'];

		return $proposals || $invoices;
	}

	public function restControllers(): array {
		return array(
			RestProposalController::class,
			RestPublicProposalController::class,
			RestInvoiceController::class,
			RestInvoicePaymentController::class,
			RestPublicInvoiceController::class,
			RestInvoiceOnlinePaymentController::class,
			RestPaymentController::class,
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function child_migration_files(): array {
		return array(
			$this->sales_migration_path( 'SalesProposalsTable.php' ),
			$this->sales_migration_path( 'SalesProposalTableViewedAt.php' ),
			$this->sales_migration_path( 'SalesProposalTableSignatureColumns.php' ),
			$this->sales_migration_path( 'SalesProposalTableResponseColumns.php' ),
			$this->sales_migration_path( 'SalesProposalTableTemplateColumn.php' ),
			$this->sales_migration_path( 'SalesProposalTableTemplateColorColumn.php' ),
			$this->sales_migration_path( 'SalesInvoicesTable.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableCustomerColumns.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableProposalIdColumn.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableStripeColumn.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableExternalPaymentRefColumn.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableTemplateColumn.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableTemplateColorColumn.php' ),
			$this->sales_migration_path( 'SalesInvoicePaymentsTable.php' ),
			$this->sales_migration_path( 'SalesInvoiceTableIssuerSnapshotColumn.php' ),
			$this->sales_migration_path( 'SalesProposalTableIssuerSnapshotColumn.php' ),
		);
	}

	protected function boot_child( Container $container ): void {
		unset( $container );

		require_once dirname( __DIR__ ) . '/Sales/MergeTags/AbstractSalesMergeTag.php';
		$this->loadModuleMergeTagFiles();

		new ProposalFrontendHandler();
		new InvoiceFrontendHandler();

		add_action( 'init', array( $this, 'register_schedules' ) );
		add_action( 'doublescale_sales_invoice_paid', array( $this, 'on_invoice_paid' ), 10, 1 );

		// Deleting a contact cascades to their proposals (one model at a time so
		// the proposal deleting event cleans associations and detaches invoices).
		// Invoices are financial records: the REST layer blocks deleting a
		// contact that still has any, so they are intentionally not cascaded.
		add_action(
			'doublescale_contact_deleting',
			static function ( $contact ) {
				if ( ! $contact || ! isset( $contact->id ) ) {
					return;
				}
				if (
					function_exists( 'doublescale_is_module_storage_ready' )
					&& ! doublescale_is_module_storage_ready( 'documents', Models\ProposalModel::class )
				) {
					return;
				}
				try {
					$proposals = Models\ProposalModel::where( 'contact_id', (int) $contact->id )->get();
					foreach ( $proposals as $proposal ) {
						$proposal->delete();
					}
				} catch ( \Throwable $e ) {
					// Table missing or storage mid-migration — skip cascade.
				}
			}
		);

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Proposals', 'doublescale' ),
				'menu_title'      => __( 'Proposals', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/proposals',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 41,
				'group'           => 'sales',
				'requires_module' => 'documents',
			)
		);

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Invoices', 'doublescale' ),
				'menu_title'      => __( 'Invoices', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/invoices',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 42,
				'group'           => 'sales',
				'requires_module' => 'documents',
			)
		);

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Payments', 'doublescale' ),
				'menu_title'      => __( 'Payments', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/payments',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 43,
				'group'           => 'sales',
				'requires_module' => 'documents',
			)
		);
	}

	/**
	 * @return void
	 */
	public function register_schedules(): void {
		$this->register_recurring_sales_task(
			'doublescale_sales_expiring_proposals',
			static function () {
				( new ExpiringProposals() )->run();
			},
			DAY_IN_SECONDS
		);

		$this->register_recurring_sales_task(
			'doublescale_sales_overdue_invoices',
			static function () {
				( new OverdueInvoices() )->run();
			},
			HOUR_IN_SECONDS
		);
	}

	/**
	 * @param \DoubleScale\Modules\Documents\Models\InvoiceModel $invoice Invoice.
	 * @return void
	 */
	public function on_invoice_paid( $invoice ): void {
		if ( ! $invoice instanceof \DoubleScale\Modules\Documents\Models\InvoiceModel ) {
			return;
		}
		( new SalesRepNotifications() )->notify_invoice_paid( $invoice );
	}
}
