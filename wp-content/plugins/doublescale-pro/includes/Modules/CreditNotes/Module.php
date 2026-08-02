<?php
/**
 * Credit Notes module bootstrap.
 *
 * @package DoubleScale\Pro\Modules\CreditNotes
 */

namespace DoubleScale\Pro\Modules\CreditNotes;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;

final class Module extends AbstractSalesChildModule {

	public function slug(): string {
		return 'credit_notes';
	}

	public function label(): string {
		return __( 'Credit Notes', 'doublescale' );
	}

	public function description(): string {
		return __( 'Issue credit notes, apply credit to invoices, and track open customer balances.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Credit notes ship in Pro and are not gated on the documents release flag.
	 *
	 * @return bool
	 */
	protected function requires_documents_ready(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestCreditNoteController::class,
			Rest\Controllers\RestCreditNoteApplicationController::class,
			Rest\Controllers\RestPublicCreditNoteController::class,
		);
	}

	protected function boot_child( Container $container ): void {
		unset( $container );

		$this->loadModuleMergeTagFiles();

		new Renderer\CreditNoteFrontendHandler();
		new Services\CreditNotePortalProvider();

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Credit Notes', 'doublescale' ),
				'menu_title'      => __( 'Credit Notes', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/credit-notes',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 45,
				'group'           => 'sales',
				'requires_module' => 'credit_notes',
			)
		);
	}
}
