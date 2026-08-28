<?php
/**
 * Product Catalog module bootstrap.
 *
 * @package DoubleScale\Pro\Modules\ProductCatalog
 */

namespace DoubleScale\Pro\Modules\ProductCatalog;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Admin\AdminLoader;
use DoubleScale\Admin\MenuRegistry;
use DoubleScale\Core\Container;
use DoubleScale\Modules\Sales\AbstractSalesChildModule;
use DoubleScale\Pro\Modules\ProductCatalog\Abilities\ProductAbilities;

final class Module extends AbstractSalesChildModule {

	/**
	 * Read-only product abilities for the WordPress Abilities API.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function abilities(): array {
		return ProductAbilities::definitions();
	}

	public function slug(): string {
		return 'product_catalog';
	}

	public function label(): string {
		return __( 'Products', 'doublescale' );
	}

	public function description(): string {
		return __( 'Save reusable products and services, then insert them as line items on invoices, proposals, and credit notes.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	/**
	 * Products ship in Pro and are not gated on the documents release flag.
	 * Credit notes also opt out, and the picker is shared with them.
	 *
	 * Note: this flag gates migrations() as well as is_enabled(), so leaving it
	 * true would silently skip creating the products table.
	 *
	 * @return bool
	 */
	protected function requires_documents_ready(): bool {
		return false;
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestProductController::class,
			Rest\Controllers\RestProductGroupController::class,
		);
	}

	protected function boot_child( Container $container ): void {
		unset( $container );

		MenuRegistry::add(
			array(
				'page_title'      => __( 'Products', 'doublescale' ),
				'menu_title'      => __( 'Products', 'doublescale' ),
				'capability'      => 'doublescale_access',
				'slug'            => 'doublescale&path=sales/products',
				'callback'        => array( AdminLoader::class, 'page_wrapper' ),
				'position'        => 46,
				'group'           => 'sales',
				'requires_module' => 'product_catalog',
			)
		);
	}
}
