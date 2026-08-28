<?php
/**
 * Analytics module bootstrap.
 *
 * Owns: reporting REST controllers (dashboard reports, automation reports).
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;
use DoubleScale\Pro\Modules\Analytics\Abilities\ReportAbilities;

final class Module extends AbstractModule {

	/**
	 * Read-only reporting abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return ReportAbilities::definitions();
	}

	public function slug(): string {
		return 'analytics';
	}

	public function label(): string {
		return __( 'Analytics & Reports', 'doublescale' );
	}

	public function description(): string {
		return __( 'Dashboard reports, pipeline analytics, and automation performance insights.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return false;
	}

	public function register( Container $container ): void {
		$container->singleton( Services\ReportingService::class );
		$container->singleton( Services\ContractReportService::class );
		$container->singleton( Services\ProposalReportService::class );
		$container->singleton( Services\InvoiceReportService::class );
		$container->singleton( Services\CreditNoteReportService::class );
		$container->singleton( Services\ProjectReportService::class );
		$container->singleton( Services\TaskReportService::class );
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestReportsController::class,
			Rest\Controllers\RestAutomationReportsController::class,
			Rest\Controllers\RestContractReportsController::class,
			Rest\Controllers\RestProposalReportsController::class,
			Rest\Controllers\RestInvoiceReportsController::class,
			Rest\Controllers\RestCreditNoteReportsController::class,
			Rest\Controllers\RestProjectReportsController::class,
			Rest\Controllers\RestTaskReportsController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );
	}
}
