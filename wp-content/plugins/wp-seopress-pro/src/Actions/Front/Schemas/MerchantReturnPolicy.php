<?php

namespace SEOPressPro\Actions\Front\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SEOPress\Core\Hooks\ExecuteHooksFrontend;

/**
 * Inject a global merchant return policy (hasMerchantReturnPolicy /
 * MerchantReturnPolicy) into the Organization schema.
 *
 * Google supports both a merchant-level policy on the Organization and a
 * product-level one on the Offer
 * (https://developers.google.com/search/docs/appearance/structured-data/return-policy).
 * The policy is configured in SEO > PRO > WooCommerce and stored in
 * seopress_pro_option_name; we emit it through the free plugin's
 * `seopress_get_json_data_organization` filter so no Free change is needed.
 *
 * The Organization schema is only printed on the front page, so the Product schema
 * generators reuse getPolicySchema() to attach the same policy to their offers —
 * otherwise a product page would carry no return policy at all.
 *
 * @since 10.0.0
 */
class MerchantReturnPolicy implements ExecuteHooksFrontend {

	/**
	 * Map our stored values to schema.org enumeration members (without the
	 * scheme, which seopress_check_ssl() prepends).
	 *
	 * @var array<string, array<string, string>>
	 */
	const MAPS = array(
		'category'  => array(
			'finite'        => 'MerchantReturnFiniteReturnWindow',
			'unlimited'     => 'MerchantReturnUnlimitedWindow',
			'not_permitted' => 'MerchantReturnNotPermitted',
		),
		'method'    => array(
			'by_mail'  => 'ReturnByMail',
			'in_store' => 'ReturnInStore',
			'at_kiosk' => 'ReturnAtKiosk',
		),
		'fees'      => array(
			'free'     => 'FreeReturn',
			'customer' => 'ReturnFeesCustomerResponsibility',
			'shipping' => 'ReturnShippingFees',
		),
		'refund'    => array(
			'full'         => 'FullRefund',
			'exchange'     => 'ExchangeRefund',
			'store_credit' => 'StoreCreditRefund',
		),
		'condition' => array(
			'new'         => 'NewCondition',
			'used'        => 'UsedCondition',
			'refurbished' => 'RefurbishedCondition',
			'damaged'     => 'DamagedCondition',
		),
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'seopress_get_json_data_organization', array( $this, 'addReturnPolicy' ) );
	}

	/**
	 * Append hasMerchantReturnPolicy to the Organization schema.
	 *
	 * @param array $data The Organization schema data.
	 *
	 * @return array
	 */
	public function addReturnPolicy( $data ) {
		$policy = self::getPolicySchema();

		if ( empty( $policy ) ) {
			return $data;
		}

		$data['hasMerchantReturnPolicy'] = $policy;

		return $data;
	}

	/**
	 * Build the MerchantReturnPolicy schema from the stored settings.
	 *
	 * Exposed so the Product schema generators can attach the same policy to their
	 * offers: the Organization schema is only printed on the front page, so without
	 * this a product page carries no return policy at all.
	 *
	 * @since 10.1.0
	 *
	 * @return array The policy schema, or an empty array when not configured.
	 */
	public static function getPolicySchema() {
		$options = get_option( 'seopress_pro_option_name' );
		if ( ! is_array( $options ) ) {
			return array();
		}

		if ( empty( $options['seopress_woocommerce_return_policy_enable'] ) ) {
			return array();
		}

		// Required: at least one applicable country + a policy category.
		// Every scalar read below is coerced defensively: seopress_pro_option_name is
		// restored verbatim by the settings importer (no field-level sanitization), so a
		// malformed export can store an array where a string is expected. This code now
		// runs on every product page, not just the front page, so a bad value must never
		// reach an array offset or trim().
		$countries = self::parseCountries( isset( $options['seopress_woocommerce_return_policy_applicable_country'] ) ? $options['seopress_woocommerce_return_policy_applicable_country'] : '' );
		$category  = self::readScalar( $options, 'seopress_woocommerce_return_policy_category' );
		if ( empty( $countries ) || empty( self::MAPS['category'][ $category ] ) ) {
			return array();
		}

		$ssl = function_exists( 'seopress_check_ssl' ) ? seopress_check_ssl() : 'https://';

		$policy = array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $countries,
			'returnPolicyCategory' => $ssl . 'schema.org/' . self::MAPS['category'][ $category ],
		);

		// merchantReturnDays is required for a finite return window.
		if ( 'finite' === $category ) {
			$days = (int) self::readScalar( $options, 'seopress_woocommerce_return_policy_days' );
			if ( $days > 0 ) {
				$policy['merchantReturnDays'] = $days;
			}
		}

		$country = trim( self::readScalar( $options, 'seopress_woocommerce_return_policy_country' ) );
		if ( '' !== $country ) {
			$policy['returnPolicyCountry'] = strtoupper( $country );
		}

		self::addEnum( $policy, 'returnMethod', 'method', $options, 'seopress_woocommerce_return_policy_method', $ssl );
		self::addEnum( $policy, 'returnFees', 'fees', $options, 'seopress_woocommerce_return_policy_fees', $ssl );
		self::addEnum( $policy, 'refundType', 'refund', $options, 'seopress_woocommerce_return_policy_refund_type', $ssl );
		self::addEnum( $policy, 'itemCondition', 'condition', $options, 'seopress_woocommerce_return_policy_item_condition', $ssl );

		// returnShippingFeesAmount is meaningful only when the customer pays the
		// return shipping.
		$fees = self::readScalar( $options, 'seopress_woocommerce_return_policy_fees' );
		if ( 'shipping' === $fees ) {
			$amount = isset( $options['seopress_woocommerce_return_policy_fees_amount'] ) ? $options['seopress_woocommerce_return_policy_fees_amount'] : '';
			if ( is_numeric( $amount ) ) {
				$policy['returnShippingFeesAmount'] = array(
					'@type'    => 'MonetaryAmount',
					'value'    => (float) $amount,
					'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				);
			}
		}

		return apply_filters( 'seopress_pro_merchant_return_policy', $policy, $options );
	}

	/**
	 * Add a schema.org enumeration value to the policy when the option is set.
	 *
	 * @param array  $policy   The policy array (by reference).
	 * @param string $prop     The schema property name.
	 * @param string $map      The MAPS sub-key.
	 * @param array  $options  The plugin options.
	 * @param string $opt_key  The option key holding the stored value.
	 * @param string $ssl      The scheme prefix.
	 *
	 * @return void
	 */
	private static function addEnum( &$policy, $prop, $map, $options, $opt_key, $ssl ) {
		$value = self::readScalar( $options, $opt_key );
		if ( ! empty( self::MAPS[ $map ][ $value ] ) ) {
			$policy[ $prop ] = $ssl . 'schema.org/' . self::MAPS[ $map ][ $value ];
		}
	}

	/**
	 * Read a stored option as a string.
	 *
	 * The seopress_pro_option_name array is restored as-is by the settings importer, so a
	 * malformed export can hold an array or an object where a select value is expected.
	 * Returning '' for those keeps the value out of array offsets and string functions,
	 * which are a TypeError on PHP 8.
	 *
	 * @param array  $options The plugin options.
	 * @param string $key     The option key to read.
	 *
	 * @return string
	 */
	private static function readScalar( $options, $key ) {
		if ( ! isset( $options[ $key ] ) || ! is_scalar( $options[ $key ] ) ) {
			return '';
		}

		return (string) $options[ $key ];
	}

	/**
	 * Normalize the stored applicable countries into a clean array of ISO codes.
	 *
	 * Accepts the array stored by the country picker, or a legacy
	 * comma-separated string.
	 *
	 * @param mixed $raw The stored value (array or comma-separated string).
	 *
	 * @return array
	 */
	private static function parseCountries( $raw ) {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$list = explode( ',', $raw );
		} else {
			return array();
		}

		$codes = array_map(
			function ( $code ) {
				// A nested array here would raise "Array to string conversion"; the regex
				// below discards the empty string anyway.
				return is_scalar( $code ) ? strtoupper( trim( (string) $code ) ) : '';
			},
			$list
		);

		// Keep only plausible two-letter codes, capped at Google's 50 limit.
		$codes = array_values(
			array_unique(
				array_filter(
					$codes,
					function ( $code ) {
						return (bool) preg_match( '/^[A-Z]{2}$/', $code );
					}
				)
			)
		);

		return array_slice( $codes, 0, 50 );
	}
}
