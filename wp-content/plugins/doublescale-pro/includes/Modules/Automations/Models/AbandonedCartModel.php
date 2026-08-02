<?php
/**
 * Class AbandonedCartModel
 *
 * This class is responsible for handling the Abandoned Cart model
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Models;


defined( 'ABSPATH' ) || exit;

use WPEloquent\Eloquent\Model;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * AbandonedCartModel class
 */
class AbandonedCartModel extends Model {

	/**
	 * Table name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $table = 'doublescale_abandoned_carts';

	/**
	 * Primary key
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	protected $primary_key = 'id';

	/**
	 * Fillable columns
	 *
	 * @var array
	 *
	 * @since 1.0.0
	 */
	protected $fillable = array(
		'hash_key',
		'user_id',
		'email',
		'fields',
		'items',
		'coupons',
		'total',
		'fees',
		'taxes',
		'shipping',
		'currency',
		'order_id',
		'status',
		'created_at',
		'updated_at',
	);

	/**
	 * Timestamps
	 *
	 * @var bool
	 *
	 * @since 1.0.0
	 */
	public $timestamps = true;

	/**
	 * Casts
	 *
	 * @var array
	 */
	protected $casts = array(
		'fields'  => 'array',
		'items'   => 'array',
		'coupons' => 'array',
		'fees'    => 'array',
		'taxes'   => 'array',
	);

	/**
	 * Override the save method to add validation.
	 *
	 * @param array $options
	 * @return bool
	 * @throws \Exception
	 */
	public function save( array $options = array() ) {
		// Check if the email field is empty
		if ( empty( $this->email ) ) {
			throw new \Exception( 'Email field is required.' );
		}

		// Call the parent save method to perform the actual saving
		return parent::save( $options );
	}

	/**
	 * Contact relationship
	 *
	 * @return \DoubleScale\Modules\Contacts\Models\ContactModel
	 */
	public function contact() {
		return $this->belongsTo( ContactModel::class, 'email', 'email' );
	}

	/**
	 * Create or update abandoned cart
	 *
	 * @param array $fields Fields.
	 *
	 * @return AbandonedCartModel|false
	 */
	public static function createOrUpdate( $fields ) {
		$cart = self::where( 'email', $fields['email'] )->first();

		if ( ! $cart ) {
			$cart = new self();
		}

		$cart->fill( $fields );
		if ( $cart->save() ) {
			return $cart;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
		throw new \WP_Error( 'failed_to_save_abandoned_cart', __( 'Failed to save the abandoned cart.', 'doublescale') );
	}

	/**
	 * Update abandoned cart by hash key
	 *
	 * @param string $hash_key Hash key.
	 *
	 * @return AbandonedCartModel|false
	 */
	public static function updateByHashKey( $hash_key, $fields ) {
		$cart = self::where( 'hash_key', $hash_key )->first();

		if ( ! $cart ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
			throw new \Exception( __( 'Abandoned cart not found.', 'doublescale') );
		}

		$cart->fill( $fields );
		if ( $cart->save() ) {
			return $cart;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not direct output.
		throw new \Exception( __( 'Failed to save the abandoned cart.', 'doublescale') );
	}

	/**
	 * Get abandoned cart by hash key
	 *
	 * @param string $hash_key Hash key.
	 *
	 * @return AbandonedCartModel|false
	 */
	public static function getByHashKey( $hash_key ) {
		return self::where( 'hash_key', $hash_key )->first();
	}

	/**
	 * boot method
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		parent::boot();

		// Retrieving: attach items product data
		static::retrieved(
			function( $cart ) {
				$items = $cart->items;
				foreach ( $items as $id => $item ) {
					$product = wc_get_product( $item['product_id'] );
					if ( $product ) {
						$product_data = array(
							'id'    => $product->get_id(),
							'name'  => $product->get_name(),
							'price' => $product->get_price() . ' ' . get_woocommerce_currency(),
							'image' => $product->get_image(),
						);

						$items[ $id ]['product'] = $product_data;
					}
				}

				$cart->items = $items;
			}
		);
	}
}
