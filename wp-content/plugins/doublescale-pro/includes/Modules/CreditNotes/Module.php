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
use DoubleScale\Core\Services\ShortcodePageProvisioner;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Pro\Modules\CreditNotes\Abilities\CreditNoteAbilities;

final class Module extends AbstractSalesChildModule {

	/**
	 * Read-only credit note abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return CreditNoteAbilities::definitions();
	}

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

		Migrations\SalesCreditNotesTableCurrencyNullable::ensure();

		$this->loadModuleMergeTagFiles();

		new Renderer\CreditNoteFrontendHandler();

		// Auto-create the page hosting that shortcode, so a sent credit note
		// links somewhere instead of resolving to an empty URL. Registered into
		// free's shared provisioner, which runs the single `admin_init` pass.
		// Guarded: an older free has no such class, and Pro must degrade
		// silently rather than fatal under that upgrade order.
		if ( class_exists( ShortcodePageProvisioner::class ) ) {
			ShortcodePageProvisioner::register(
				Renderer\CreditNoteFrontendHandler::SHORTCODE_NAME,
				array(
					'title' => __( 'Credit Note', 'doublescale-pro' ),
					'slug'  => 'doublescale-credit-note',
				)
			);
		}
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
