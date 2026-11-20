<?php
/**
 * The product picker of the WooCommerce product rules (Products in Orders,
 * Products in Order, Wishlist Items, Subscription Items; Pro's Cart Items).
 *
 * The editor searches WooCommerce's own REST API page by page as the admin
 * types (and loads the names of saved products by id), instead of every rule
 * listing every product in the admin page's config: on a store with thousands
 * of products that took hundreds of megabytes and many seconds on each
 * DoubleScale admin page.
 *
 * @package DoubleScale\Modules\Automations
 */

namespace DoubleScale\Modules\Automations\Rules\Woocommerce;

defined( 'ABSPATH' ) || exit;

/**
 * ProductPicker.
 */
final class ProductPicker {

	/** Field type the editor renders as a searchable multi-select. */
	public const TYPE = 'infinite_scroll_multiselect';

	/** WooCommerce's products endpoint (published products, searched by name). */
	public const ENDPOINT = '/wc/v3/products';

	/** How the editor reads that endpoint. */
	public const SETTINGS = array(
		'rootArrayResponse' => true,
		'perPage'           => 20,
		'searchParamName'   => 'search',
		'apiParams'         => array( 'status' => 'publish' ),
	);
}
