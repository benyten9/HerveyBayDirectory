<?php
namespace SEOPressPro\Helpers;
defined( 'ABSPATH' ) || exit;

/** Shared shipping semantics for automatic and manually assigned Product schemas. */
class WooCommerceShipping {
	public static function is_delivery_method( $method ) {
		return 'local_pickup' !== $method->id && ( ! method_exists( $method, 'is_enabled' ) || $method->is_enabled() );
	}

	/**
	 * The rate a method charges for a product, before tax.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $method  The shipping method.
	 * @param mixed $product The product.
	 *
	 * @return float
	 */
	public static function get_base_amount( $method, $product ) {
		// Shipping class surcharges never apply to WooCommerce's free method.
		if ( 'free_shipping' === $method->id ) {
			return 0;
		}
		$shipping_class_id = (int) $product->get_shipping_class_id();
		$instance          = $method->instance_settings;
		$cost              = isset( $instance['cost'] ) ? (float) $instance['cost'] : 0;
		if ( $shipping_class_id && isset( $instance['type'] ) && 'class' === $instance['type'] ) {
			$cost_key = 'class_cost_' . $shipping_class_id;
			if ( ! empty( $instance[ $cost_key ] ) ) {
				$cost += (float) $instance[ $cost_key ];
			}
		}
		return $cost;
	}

	/**
	 * The rate a method charges for a product, shipped to one place.
	 *
	 * @since 10.4.0 Takes the product and the destination, and adds the tax the
	 *               shop displays. Used to return the rate before tax.
	 *
	 * @param mixed $method      The shipping method.
	 * @param mixed $product     The product.
	 * @param array $destination One DefinedRegion, or an empty array for the shop base.
	 *
	 * @return float
	 */
	public static function get_amount( $method, $product, $destination = array() ) {
		return self::with_display_tax( self::get_base_amount( $method, $product ), $method, $product, $destination );
	}

	/**
	 * Group a zone's destinations by the rate actually charged to each of them.
	 *
	 * A zone is one list of locations, but not one tax rate: a shop with per
	 * country rates charges a different amount to each country in the zone, and
	 * a country with no rate at all is charged nothing on top. Publishing one
	 * amount for the whole zone advertised a price the customer is not charged
	 * everywhere but the one place the rate happened to be computed for.
	 *
	 * Destinations that end up on the same amount stay in the same offer, so a
	 * shop with a single rate, or no tax at all, emits exactly what it did
	 * before.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $method       The shipping method.
	 * @param mixed $product      The product.
	 * @param array $destinations The zone's DefinedRegion list.
	 *
	 * @return array[] One entry per distinct amount: `amount` and `destinations`.
	 */
	public static function group_by_amount( $method, $product, $destinations ) {
		$base = self::get_base_amount( $method, $product );

		if ( empty( $destinations ) || ! is_array( $destinations ) ) {
			return array(
				array(
					'amount'       => self::with_display_tax( $base, $method, $product ),
					'destinations' => array(),
				),
			);
		}

		$groups = array();

		foreach ( $destinations as $destination ) {
			$amount = self::with_display_tax( $base, $method, $product, is_array( $destination ) ? $destination : array() );
			$key    = (string) $amount;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'amount'       => $amount,
					'destinations' => array(),
				);
			}

			$groups[ $key ]['destinations'][] = $destination;
		}

		return array_values( $groups );
	}

	/**
	 * Put the shipping rate on the same tax basis as the price beside it.
	 *
	 * WooCommerce stores shipping costs excluding tax, while the product price in
	 * the same Offer follows `woocommerce_tax_display_shop`. A shop entering prices
	 * including VAT therefore published a price with tax next to a shipping rate
	 * without it, and the two cannot both be right: the reported shop advertised
	 * 4.09 against a real 4.95.
	 *
	 * Only when the price beside it really carries tax, which means the shop both
	 * displays including tax and entered its prices that way. The offer price of a
	 * simple product is the raw `get_price()`, so on the common European setup of
	 * prices entered excluding tax and displayed including it, taxing the shipping
	 * alone would trade one mismatch for another: 50 excluding tax next to 4.95
	 * including it, so the rate stays excluding tax there.
	 *
	 * Variation prices do not follow this rule yet: they go through
	 * `wc_get_price_including_tax()` when the shop displays including tax,
	 * whatever the entry setting. On that same European setup a variable
	 * product therefore still publishes prices with tax next to a rate without
	 * it. Aligning every offer price on `wc_get_price_to_display()` is the fix,
	 * and it is out of scope here.
	 *
	 * Left alone when tax is off, when the method is not taxable, when shipping
	 * is not taxed for this product, or when there is nothing to tax.
	 *
	 * @since 10.4.0
	 *
	 * @param float $cost        The rate, excluding tax.
	 * @param mixed $method      The shipping method.
	 * @param mixed $product     The product being shipped, whose tax class applies.
	 * @param array $destination One DefinedRegion, or an empty array for the shop base.
	 *
	 * @return float
	 */
	public static function with_display_tax( $cost, $method, $product = null, $destination = array() ) {
		if ( $cost <= 0 ) {
			return $cost;
		}

		if ( ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return $cost;
		}

		if ( 'incl' !== get_option( 'woocommerce_tax_display_shop' ) ) {
			return $cost;
		}

		// The price in the same Offer only carries tax when the shop entered it
		// that way. See the note above.
		if ( ! function_exists( 'wc_prices_include_tax' ) || ! wc_prices_include_tax() ) {
			return $cost;
		}

		// A method can be configured as not taxable, and WooCommerce charges it
		// untaxed regardless of the display setting.
		//
		// Read from the property rather than through is_taxable(), which also
		// answers no for a VAT exempt customer and no at all when there is no
		// customer object. Whose visit builds the cache must not decide what
		// every later visitor is shown.
		if ( isset( $method->tax_status ) && 'taxable' !== $method->tax_status ) {
			return $cost;
		}

		if ( ! self::product_ships_taxed( $product ) ) {
			return $cost;
		}

		if ( ! class_exists( '\\WC_Tax' ) ) {
			return $cost;
		}

		// WooCommerce 10.6 lets a site declare that its shipping costs are
		// already entered including tax. calc_shipping_tax() then answers the
		// part already inside the cost, so adding it would charge it twice.
		// WooCommerce reads the filter through wc_string_to_bool(), so 'no'
		// means no there and must mean no here.
		$shipping_prices_include_tax = apply_filters( 'woocommerce_shipping_prices_include_tax', false );
		$shipping_prices_include_tax = function_exists( 'wc_string_to_bool' )
			? wc_string_to_bool( $shipping_prices_include_tax )
			: (bool) $shipping_prices_include_tax;

		if ( $shipping_prices_include_tax ) {
			return $cost;
		}

		$rates = self::shipping_tax_rates( $product, $destination );

		if ( empty( $rates ) ) {
			return $cost;
		}

		$taxes = \WC_Tax::calc_shipping_tax( $cost, $rates );

		if ( ! is_array( $taxes ) ) {
			return $cost;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( $cost + array_sum( $taxes ), $decimals );
	}

	/**
	 * Whether WooCommerce taxes the shipping of this product.
	 *
	 * Shipping is taxed for a product whose status is `taxable` and for one set
	 * to "Shipping only" (`shipping`), which is what `is_shipping_taxable()`
	 * answers. And when the shop sets an explicit shipping tax class, WooCommerce
	 * does not look at the items at all: shipping is taxed under that class
	 * whatever the product's own status.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $product The product being shipped, or null.
	 *
	 * @return bool
	 */
	protected static function product_ships_taxed( $product ) {
		if ( null === $product || ! method_exists( $product, 'get_tax_status' ) ) {
			return true;
		}

		if ( 'inherit' !== get_option( 'woocommerce_shipping_tax_class' ) ) {
			return true;
		}

		return in_array( $product->get_tax_status(), array( 'taxable', 'shipping' ), true );
	}

	/**
	 * The tax rates that apply to shipping something to one place.
	 *
	 * `WC_Tax::get_shipping_tax_rates()` without arguments asks for the taxable
	 * address of the current visitor, falling back to geolocation and then to
	 * the shop base. In a schema that is published for every zone at once, that
	 * is the wrong address for all of them but one, and it also makes the
	 * output depend on who happens to load the page first.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $product     The product being shipped.
	 * @param array $destination One DefinedRegion, or an empty array.
	 *
	 * @return array
	 */
	protected static function shipping_tax_rates( $product, $destination ) {
		if ( ! class_exists( '\\WC_Tax' ) || ! method_exists( '\\WC_Tax', 'find_shipping_rates' ) ) {
			return array();
		}

		$args = self::destination_tax_args( $destination );

		$args['tax_class'] = self::shipping_tax_class( $product );

		return \WC_Tax::find_shipping_rates( $args );
	}

	/**
	 * The address the tax is computed against.
	 *
	 * @since 10.4.0
	 *
	 * @param array $destination One DefinedRegion, or an empty array.
	 *
	 * @return array
	 */
	protected static function destination_tax_args( $destination ) {
		$base = array(
			'country'  => '',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		if ( function_exists( 'wc_get_base_location' ) ) {
			$location        = wc_get_base_location();
			$base['country'] = isset( $location['country'] ) ? $location['country'] : '';
			$base['state']   = isset( $location['state'] ) ? $location['state'] : '';
		}

		// WooCommerce passes the base postcode and city too, so a rate limited
		// to the shop's own postcode applies to it.
		$base['postcode'] = self::base_postcode();
		$base['city']     = self::base_city();

		// A shop taxing on its own address charges the same everywhere, so the
		// destination is not consulted at all.
		if ( 'base' === get_option( 'woocommerce_tax_based_on' ) ) {
			return $base;
		}

		if ( ! is_array( $destination ) || empty( $destination ) ) {
			return $base;
		}

		$args = $base;

		if ( ! empty( $destination['addressCountry'] ) ) {
			$args['country'] = (string) $destination['addressCountry'];
			// The country changed, so the base state, postcode and city no
			// longer belong to it.
			$args['state']    = '';
			$args['postcode'] = '';
			$args['city']     = '';
		}

		if ( ! empty( $destination['addressRegion'] ) ) {
			$args['state'] = (string) $destination['addressRegion'];
		}

		if ( ! empty( $destination['postalCode'] ) ) {
			$args['postcode'] = (string) $destination['postalCode'];
			// The base city is not in that postcode.
			$args['city'] = '';
		}

		return $args;
	}

	/**
	 * The tax class shipping is charged under.
	 *
	 * `inherit` means the tax class of what is in the cart. There is no cart
	 * here, and the offer is about one product, so that product's class is the
	 * answer: a shop shipping a reduced rate item was publishing the standard
	 * rate on top of it.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $product The product being shipped.
	 *
	 * @return string
	 */
	protected static function shipping_tax_class( $product ) {
		$configured = get_option( 'woocommerce_shipping_tax_class' );

		if ( 'inherit' !== $configured ) {
			return is_string( $configured ) ? $configured : '';
		}

		if ( null !== $product && method_exists( $product, 'get_tax_class' ) ) {
			return (string) $product->get_tax_class();
		}

		return '';
	}

	/**
	 * The shop's postcode, as WooCommerce reads it.
	 *
	 * @since 10.4.0
	 *
	 * @return string
	 */
	protected static function base_postcode() {
		if ( function_exists( 'WC' ) && isset( WC()->countries ) && method_exists( WC()->countries, 'get_base_postcode' ) ) {
			return (string) WC()->countries->get_base_postcode();
		}

		return (string) get_option( 'woocommerce_store_postcode', '' );
	}

	/**
	 * The shop's city, as WooCommerce reads it.
	 *
	 * @since 10.4.0
	 *
	 * @return string
	 */
	protected static function base_city() {
		if ( function_exists( 'WC' ) && isset( WC()->countries ) && method_exists( WC()->countries, 'get_base_city' ) ) {
			return (string) WC()->countries->get_base_city();
		}

		return (string) get_option( 'woocommerce_store_city', '' );
	}

	/**
	 * Offers computed for a cache key, or null.
	 *
	 * Computing them asks WooCommerce for the tax rates of every destination
	 * of every zone. Those lookups are only cached for the request without a
	 * persistent object cache, so a shop with a 50 country zone ran about a
	 * hundred queries on every product view that missed the page cache. The
	 * offers are kept in a transient instead, keyed on everything they depend
	 * on, see cache_signature().
	 *
	 * @since 10.4.0
	 *
	 * @param string $key The caller's cache key, signature included.
	 *
	 * @return array|null
	 */
	public static function get_cached_offers( $key ) {
		$cached = get_transient( self::transient_name( $key ) );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Keep offers computed for a cache key.
	 *
	 * Store them before `seopress_pro_wc_schema_shipping_details` runs, so a
	 * filter that depends on the product still runs on every render.
	 *
	 * @since 10.4.0
	 *
	 * @param string $key    The caller's cache key, signature included.
	 * @param array  $offers The offers.
	 *
	 * @return void
	 */
	public static function set_cached_offers( $key, $offers ) {
		set_transient( self::transient_name( $key ), $offers, DAY_IN_SECONDS );
	}

	/**
	 * @since 10.4.0
	 *
	 * @param string $key The caller's cache key.
	 *
	 * @return string
	 */
	protected static function transient_name( $key ) {
		return 'seopress_pro_wc_ship_' . md5( $key . '|' . ( defined( 'SEOPRESS_PRO_VERSION' ) ? SEOPRESS_PRO_VERSION : '' ) );
	}

	/**
	 * Invalidate every cached offer.
	 *
	 * WooCommerce bumps its `shipping` transient version when zones, methods
	 * or the tax settings screen change, and that version is in the key. Tax
	 * rates edited through the REST API or `WC_Tax` only clear the `taxes`
	 * cache group, which lives for the request without a persistent cache, so
	 * those hooks bump a version of our own.
	 *
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public static function flush_cache() {
		update_option( 'seopress_pro_wc_shipping_tax_version', (string) microtime( true ), true );
	}

	/**
	 * Hook the invalidation on WooCommerce's tax rate events.
	 *
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public static function register_hooks() {
		foreach ( array( 'woocommerce_tax_rate_added', 'woocommerce_tax_rate_updated', 'woocommerce_tax_rate_deleted' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
	}

	/**
	 * Everything the published rate depends on, for a cache key.
	 *
	 * The value used to be cached under the shipping class and the currency
	 * while depending on the visitor's address, their cart and their exemption
	 * status, so with a persistent object cache the first visitor decided what
	 * every later one was shown. Those three are gone from the computation; what
	 * is left is configuration, and it belongs in the key.
	 *
	 * @since 10.4.0
	 *
	 * @param mixed $product The product being shipped.
	 *
	 * @return string
	 */
	public static function cache_signature( $product ) {
		$tax_enabled = function_exists( 'wc_tax_enabled' ) && wc_tax_enabled();

		return implode(
			'|',
			array(
				$tax_enabled ? '1' : '0',
				(string) get_option( 'woocommerce_tax_display_shop' ),
				function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? '1' : '0',
				(string) get_option( 'woocommerce_tax_based_on' ),
				self::shipping_tax_class( $product ),
				( null !== $product && method_exists( $product, 'get_tax_status' ) ) ? (string) $product->get_tax_status() : '',
				// Zones, methods and the tax settings screen bump this one.
				class_exists( '\\WC_Cache_Helper' ) ? (string) \WC_Cache_Helper::get_transient_version( 'shipping' ) : '',
				// Tax rates edited outside that screen bump this one.
				(string) get_option( 'seopress_pro_wc_shipping_tax_version', '' ),
				// The base address is where a shop taxing on it computes the rate.
				function_exists( 'wc_get_base_location' ) ? implode( ',', (array) wc_get_base_location() ) : '',
				self::base_postcode(),
				self::base_city(),
			)
		);
	}
}
