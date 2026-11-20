<?php
/**
 * The "Target Products" filter shared by the FluentCart triggers and goal.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Fluentcart;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Services\FluentCartOrders;

/**
 * ProductFilter class
 */
final class ProductFilter {

	/**
	 * Whether an order passes the filter: no products chosen, or one of them bought.
	 *
	 * @param mixed $chosen  Chosen product ids.
	 * @param mixed $ordered Ordered product ids.
	 */
	public static function matches( $chosen, $ordered ): bool {
		$chosen = array_filter( array_map( 'intval', (array) $chosen ) );
		if ( empty( $chosen ) ) {
			return true;
		}

		return ! empty( array_intersect( $chosen, array_map( 'intval', (array) $ordered ) ) );
	}

	/**
	 * The picker field.
	 *
	 * @param string $help Help text.
	 * @return array<string, mixed>
	 */
	public static function field( string $help ): array {
		return array(
			'label'   => __( 'Target Products', 'doublescale' ),
			'type'    => 'multiselect',
			'options' => class_exists( FluentCartOrders::class ) ? FluentCartOrders::product_options() : array(),
			'help'    => $help,
		);
	}

	/**
	 * JSON schema of the setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'  => 'array',
			'items' => array( 'type' => array( 'integer', 'string' ) ),
		);
	}
}
