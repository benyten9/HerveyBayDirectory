<?php
/**
 * Deals module bootstrap.
 *
 * Owns: pipelines, stages, deals, pipeline/deal managers, deal REST API.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Deals;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Pro\Modules\Deals\Abilities\DealAbilities;

final class Module extends AbstractSalesChildModule {

	/**
	 * Read-only deal abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return DealAbilities::definitions();
	}

	public function slug(): string {
		return 'deals';
	}

	public function label(): string {
		return __( 'Pipelines & Deals', 'doublescale' );
	}

	public function description(): string {
		return __( 'Manage sales pipelines, deal stages, and track deal progress.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Pipelines ship in Pro and are not gated on the documents release flag.
	 *
	 * @return bool
	 */
	protected function requires_documents_ready(): bool {
		return false;
	}

	public function onActivate(): void {
		// Intent can be saved while Sales is off; roles provision when the
		// parent activates (Sales owns the shared sales roles).
		if ( ! $this->is_enabled() ) {
			return;
		}
		\DoubleScale\Core\UserRoles\UserRoles::provision_crm_roles();
	}

	public function onDeactivate(): void {
		// SALES_REP / SALES_MANAGER are co-owned with the Sales module — only
		// deprovision when no owning module still needs them.
		\DoubleScale\Core\UserRoles\UserRoles::enforce_module_scoped_roles();
	}

	public function register( Container $container ): void {
		$container->singleton(
			Services\DealCalendarProvider::class,
			static fn() => new Services\DealCalendarProvider()
		);
		$container->singleton(
			Services\DealAttachmentActivityLogger::class,
			static fn() => new Services\DealAttachmentActivityLogger()
		);
		$container->singleton(
			Services\DealPortalProvider::class,
			static fn() => new Services\DealPortalProvider()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestPipelineController::class,
			Rest\Controllers\RestDealController::class,
			Rest\Controllers\RestStageController::class,
		);
	}

	protected function boot_child( Container $container ): void {
		Migrations\DealTableCurrencyColumn::ensure();

		$container->get( Services\PipelineManager::class );
		$container->get( Services\DealManager::class );

		// Admin/staff calendar bridge: contributes deals (on expected_close_date)
		// to the cross-module calendar feed (owner-scoped for reps; all for managers).
		$container->get( Services\DealCalendarProvider::class );

		// Client Portal calendar bridge: contributes the contact's own deal close
		// dates (title + status only — never pipeline internals).
		$container->get( Services\DealPortalProvider::class );

		$container->get( Services\DealAttachmentActivityLogger::class )->register();

		// Opt-in: mark linked deals Won when their invoice is fully paid.
		$container->get( Services\DealInvoicePaidCloser::class )->register();

		// Deleting a contact cascades to their deals through delete_deal() so
		// the deal deleting event and doublescale_deal_deleted listeners run
		// (association cleanup, task cleanup, project detach).
		add_action(
			'doublescale_contact_deleting',
			static function ( $contact ) {
				if ( ! $contact || ! isset( $contact->id ) ) {
					return;
				}
				if (
					function_exists( 'doublescale_is_module_storage_ready' )
					&& ! doublescale_is_module_storage_ready( 'deals', Models\DealModel::class )
				) {
					return;
				}
				try {
					$deal_ids = Models\DealModel::where( 'contact_id', (int) $contact->id )
						->pluck( 'id' )
						->toArray();
					foreach ( $deal_ids as $deal_id ) {
						Services\DealManager::instance()->delete_deal( (int) $deal_id );
					}
				} catch ( \Throwable $e ) {
					// Table missing or mid-migration — skip cascade.
				}
			}
		);

		$this->loadModuleMergeTagFiles();
	}
}
