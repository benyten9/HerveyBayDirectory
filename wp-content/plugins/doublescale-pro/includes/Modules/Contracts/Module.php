<?php
/**
 * Contracts sub-feature under Sales.
 *
 * @package DoubleScale\Pro\Modules\Contracts
 */

namespace DoubleScale\Pro\Modules\Contracts;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Pro\Modules\Contracts\Renderer\ContractFrontendHandler;
use DoubleScale\Pro\Modules\Contracts\Rest\Controllers\RestContractController;
use DoubleScale\Pro\Modules\Contracts\Rest\Controllers\RestContractTypeController;
use DoubleScale\Pro\Modules\Contracts\Rest\Controllers\RestPublicContractController;
use DoubleScale\Pro\Modules\Contracts\Services\ContractCalendarProvider;
use DoubleScale\Pro\Modules\Contracts\Services\ContractPortalProvider;
use DoubleScale\Pro\Modules\Contracts\Services\ExpiringContracts;

/**
 * Contracts module.
 */
final class Module extends AbstractSalesChildModule {

	public function version(): string {
		return '1.0.0';
	}

	public function slug(): string {
		return 'contracts';
	}

	public function label(): string {
		return __( 'Contracts', 'doublescale' );
	}

	public function description(): string {
		return __( 'Manage customer contracts, types, attachments, and e-signatures.', 'doublescale' );
	}

	public function restControllers(): array {
		return array(
			RestContractController::class,
			RestContractTypeController::class,
			RestPublicContractController::class,
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function child_migration_files(): array {
		return array(
			$this->sales_migration_path( 'SalesContractsTable.php' ),
			$this->sales_migration_path( 'SalesContractTypesTable.php' ),
			$this->sales_migration_path( 'SalesContractTableIssuerSnapshotColumn.php' ),
		);
	}

	protected function boot_child( Container $container ): void {
		unset( $container );

		$this->loadModuleMergeTagFiles();

		new ContractFrontendHandler();
		new ContractPortalProvider();
		new ContractCalendarProvider();

		add_action( 'init', array( $this, 'register_expiring_schedule' ) );

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Contracts', 'doublescale' ),
				'menu_title'      => __( 'Contracts', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/contracts',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 44,
				'group'           => 'sales',
				'requires_module' => 'contracts',
			)
		);
	}

	/**
	 * @return void
	 */
	public function register_expiring_schedule(): void {
		$this->register_recurring_sales_task(
			'doublescale_sales_expiring_contracts',
			static function () {
				( new ExpiringContracts() )->run();
			},
			DAY_IN_SECONDS
		);
	}
}
