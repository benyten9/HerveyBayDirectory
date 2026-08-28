<?php
/**
 * REST API: SureCart products for sales line-item insertion.
 *
 * Returns a value-copy shape matching the saved-product picker so the frontend
 * can insert name / description / rate onto a line without storing a live
 * product id. Editing SureCart later must not alter existing documents.
 *
 * Unlike the WooCommerce controller, SureCart is a hosted API: products live on
 * SureCart's servers and every list is an HTTP round-trip. Two consequences
 * shape this controller:
 *
 * - SureCart's PHP model layer exposes no offset pagination we can rely on, so
 *   the full active list is fetched once, cached briefly, then sliced locally.
 *   `RestContactController::get_surecart_purchase_history()` paginates the same
 *   way for the same reason.
 * - Prices are integer minor units (cents) and each product carries its own
 *   currency, so a store is not single-currency the way WooCommerce is. Rows
 *   are filtered to the document currency rather than warned about — see
 *   get_items().
 *
 * @package DoubleScale\Pro\Modules\Sales\Rest\Controllers
 */

namespace DoubleScale\Pro\Modules\Sales\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * RestSureCartProductController class.
 */
class RestSureCartProductController extends RestController {

	/**
	 * Transient key for the flattened product list.
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'doublescale_surecart_products';

	/**
	 * How long the fetched list stays cached.
	 *
	 * Short by design: a price edited in SureCart should reach the picker
	 * quickly, but typing in a search box must not fire an HTTP request per
	 * keystroke.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 300;

	/**
	 * Skip expanding products with more prices than this, mirroring the
	 * WooCommerce controller's variation cap so one product cannot flood a page.
	 *
	 * @var int
	 */
	private const MAX_PRICES = 50;

	/**
	 * @var string
	 */
	protected $rest_base = 'surecart-products';

	/**
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'currency' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		return Permissions::has_sales_rep_access();
	}

	/**
	 * Paginated SureCart product list. Shape ({data, total}) matches what
	 * InfiniteScrollSelect expects.
	 *
	 * Filtering, searching and slicing all happen after the fetch because the
	 * upstream list arrives whole; `total` therefore reflects the rows that
	 * survive filtering, which is what the infinite scroller needs to know when
	 * to stop asking for more.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$empty = new WP_REST_Response(
			array(
				'data'  => array(),
				'total' => 0,
			),
			200
		);

		// `\SureCart` with a capital C is the real namespace. PHP resolves class
		// names case-insensitively once a class is loaded, but class_exists()
		// must hand the autoloader the exact spelling it registers under — the
		// lowercase form returns false and would empty the picker on every call.
		if ( ! defined( 'SURECART_PLUGIN_FILE' ) || ! class_exists( '\SureCart\Models\Product' ) ) {
			return $empty;
		}

		$rows = $this->get_cached_rows();
		if ( array() === $rows ) {
			return $empty;
		}

		// A SureCart store can price products in several currencies at once, so
		// unlike WooCommerce there is no single store currency to compare
		// against. Rows that cannot be inserted correctly are removed rather
		// than offered with a warning: inserting one would copy a foreign-
		// currency amount onto the document as a bare number, with no FX
		// conversion anywhere in the sales stack.
		$currency = strtolower( trim( (string) $request->get_param( 'currency' ) ) );
		if ( '' !== $currency ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $currency ) {
						return $row['currency'] === $currency;
					}
				)
			);
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$needle = $this->normalize( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					function ( $row ) use ( $needle ) {
						return false !== strpos( $this->normalize( $row['name'] ), $needle )
							|| false !== strpos( $this->normalize( $row['long_description'] ), $needle );
					}
				)
			);
		}

		$total    = count( $rows );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;
		$offset   = ( $page - 1 ) * $per_page;

		return new WP_REST_Response(
			array(
				'data'  => array_slice( $rows, $offset, $per_page ),
				'total' => $total,
			),
			200
		);
	}

	/**
	 * Flattened product rows, from cache when warm.
	 *
	 * A failed fetch is not cached, so a transient upstream error does not
	 * blank the picker for the whole TTL. An empty *success* is cached though:
	 * a store with no products yet is a normal steady state, and treating it as
	 * a miss would fire an API round-trip (~300ms) on every keystroke forever.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_cached_rows(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = $this->fetch_rows();
		if ( null === $rows ) {
			return array();
		}

		set_transient( self::CACHE_KEY, $rows, self::CACHE_TTL );

		return $rows;
	}

	/**
	 * Fetch active products from SureCart and flatten them into picker rows.
	 *
	 * One row per price, not per product: SureCart models a product's paid
	 * variants as separate Price objects, so a product with monthly and yearly
	 * prices must offer both. This mirrors how the WooCommerce controller
	 * expands a variable product into its variations.
	 *
	 * Returns null on failure, distinct from an empty array meaning "the store
	 * really has no sellable products" — the caller caches the latter and not
	 * the former.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function fetch_rows(): ?array {
		try {
			// `product_collections` must be expanded explicitly or it comes back
			// absent and every row lands under "Uncategorized" in the picker.
			$products = \SureCart\Models\Product::where( array( 'archived' => false ) )
				->with( array( 'prices', 'product_collections' ) )
				->get();
		} catch ( \Throwable $e ) {
			return null;
		}

		// SureCart reports failure by returning WP_Error rather than throwing —
		// an unconnected store answers "Please connect your site to SureCart"
		// this way, which is the normal state between installing the plugin and
		// entering an API key. Reporting failure leaves the cache cold so the
		// list appears as soon as the store is connected.
		if ( is_wp_error( $products ) ) {
			return null;
		}

		// A collection response may arrive wrapped as `{ data: [...] }`.
		if ( is_object( $products ) && isset( $products->data ) && is_array( $products->data ) ) {
			$products = $products->data;
		}

		// Anything else is a shape we do not understand — treat it as a failure
		// rather than caching it as "no products".
		if ( ! is_array( $products ) ) {
			return null;
		}

		if ( array() === $products ) {
			return array();
		}

		$rows = array();
		foreach ( $products as $product ) {
			foreach ( $this->expand_prices( $product ) as $row ) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $rows;
	}

	/**
	 * Expand one product into a row per active price.
	 *
	 * @param object $product SureCart product.
	 * @return array<int, array<string, mixed>>
	 */
	private function expand_prices( $product ): array {
		$name = isset( $product->name ) ? (string) $product->name : '';
		if ( '' === $name ) {
			return array();
		}

		$description = '';
		if ( isset( $product->description ) && is_string( $product->description ) ) {
			$description = wp_strip_all_tags( $product->description );
		}

		// An expanded-but-unset collection arrives as a one-element list whose
		// name is null, so `isset()` alone is not enough to reject it.
		$group = '';
		if ( isset( $product->product_collections->data ) && is_array( $product->product_collections->data ) ) {
			foreach ( $product->product_collections->data as $collection ) {
				$candidate = isset( $collection->name ) ? trim( (string) $collection->name ) : '';
				if ( '' !== $candidate ) {
					$group = $candidate;
					break;
				}
			}
		}

		$prices = array();
		if ( isset( $product->prices->data ) && is_array( $product->prices->data ) ) {
			$prices = $product->prices->data;
		}

		if ( array() === $prices || count( $prices ) > self::MAX_PRICES ) {
			return array();
		}

		// Drop the prices that can never become rows before deciding whether the
		// product needs disambiguating: counting raw prices would put a
		// "— Monthly" suffix on a product whose only other price is archived.
		$usable = array();
		foreach ( $prices as $price ) {
			if ( ! empty( $price->archived ) ) {
				continue;
			}
			if ( ! isset( $price->currency ) || '' === (string) $price->currency ) {
				continue;
			}
			$usable[] = $price;
		}

		// A suffix only helps when it actually tells two rows apart. SureCart
		// does not require price names to be unique (nor to exist), so fall
		// back to the amount when the names would collide — two rows reading
		// "Plan — Standard" are worse than no suffix at all.
		$multiple    = count( $usable ) > 1;
		$price_names = array();
		foreach ( $usable as $price ) {
			$price_names[] = isset( $price->name ) ? (string) $price->name : '';
		}
		$names_distinguish = $multiple
			&& ! in_array( '', $price_names, true )
			&& count( array_unique( $price_names ) ) === count( $price_names );

		$rows = array();

		foreach ( $usable as $price ) {
			$currency = strtolower( (string) $price->currency );

			// SureCart stores amounts in minor units. Dividing by 100 matches
			// how orders are read elsewhere in the plugin; it is wrong for
			// zero-decimal currencies (JPY and friends), which SureCart also
			// reports in minor units — accepted here because the sales stack
			// has no per-currency exponent table to consult.
			// Cast to float explicitly: PHP's `/` yields an int for an evenly
			// divisible pair, which would serialise as `25` where every other
			// rate in the payload is a decimal.
			$amount = isset( $price->amount ) ? (float) ( (int) $price->amount / 100 ) : 0.0;

			// Only disambiguate when it is needed; a single-price product should
			// read as its own name. When several prices share a name (or have
			// none), the amount is the only thing that tells them apart.
			$label = $name;
			if ( $multiple ) {
				$suffix = $names_distinguish
					? (string) $price->name
					: $this->format_minor_amount( $amount, $currency );
				$label  = $name . ' — ' . $suffix;
			}

			$rows[] = array(
				'id'               => isset( $price->id ) ? (string) $price->id : '',
				'name'             => $label,
				'long_description' => $description,
				'unit'             => '',
				'group_name'       => $group,
				'rate'             => $amount,
				'currency'         => $currency,
				'tax'              => array(),
			);
		}

		return $rows;
	}

	/**
	 * Render an amount as a bare disambiguating suffix (e.g. "19.99 USD").
	 *
	 * Deliberately not localised currency formatting: this only ever appears
	 * inside a label whose job is to tell two otherwise-identical rows apart,
	 * and the picker renders the properly formatted price alongside it.
	 *
	 * @param float  $amount   Major-unit amount.
	 * @param string $currency Lowercase ISO code.
	 * @return string
	 */
	private function format_minor_amount( float $amount, string $currency ): string {
		return number_format( $amount, 2 ) . ' ' . strtoupper( $currency );
	}

	/**
	 * Casefold for accent-insensitive substring search.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize( string $value ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * Drop the cached list.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
