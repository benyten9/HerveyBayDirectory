<?php

/**
 * WooCommerce Abandoned Cart Created Trigger
 * This trigger will be fired when an abandoned cart is created.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Woocommerce\Cart;

use DoubleScale\Modules\Automations\Abstracts\Trigger;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\AbandonedCart\CheckoutFieldResolver;
use DoubleScale\Core\PluginKernel;

/**
 * Abandoned Cart Created Trigger
 */
class AbandonedCartCreated extends Trigger
{

	/**
	 * Trigger Name
	 *
	 * @var string
	 */
	public $name = 'Abandoned Cart';

	/**
	 * Trigger Slug
	 *
	 * @var string
	 */
	public $slug = 'wc_abandoned_cart_created';

	/**
	 * Trigger Description
	 *
	 * @var string
	 */
	public $description = 'This trigger will be fired when an abandoned cart is created.';

	/**
	 * Trigger Attributes
	 *
	 * @var array
	 */
	public $attributes = array();

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 */
	public $group = 'cart';

	/**
	 * Load Hooks
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks()
	{
		add_action('doublescale_abandoned_cart_create', array($this, 'trigger'), 10, 1);
	}

	/**
	 * Trigger
	 *
	 * @since 1.0.0
	 *
	 * @param AbandonedCartModel $cart
	 *
	 * @return void
	 */
	public function trigger($cart)
	{
		if ( ! $cart instanceof AbandonedCartModel ) {
			return;
		}

		$email   = $cart->email;
		$fields  = is_array( $cart->fields ) ? $cart->fields : array();
		$first_name = $fields['billing_first_name'] ?? '';
		$last_name  = $fields['billing_last_name'] ?? '';
		$phone      = CheckoutFieldResolver::resolve_phone( $fields );
		$country    = trim( (string) ( $fields['billing_country'] ?? $fields['shipping_country'] ?? '' ) );

		$data = array(
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'email'      => $email,
			'phone'      => $phone,
			'country'    => $country,
			'data'       => array(
				'cart_id' => $cart->id,
			),
		);

		$this->process($data);
	}

	/**
	 * Process automations
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments
	 *
	 * @return void
	 */
	public function process($args)
	{
		try {
			$automations = AutomationModel::get_automations_by_trigger($this->slug);
			if (count($automations) === 0) {
				$data = isset($args['data']) && is_array($args['data']) ? $args['data'] : array();
				do_action('doublescale_abandoned_cart_skipped', $data['cart_id'] ?? null);
				return;
			}

			foreach ($automations as $automation) {
				if (! $this->is_processable($automation, $args)) {
					continue;
				}

				PluginKernel::instance()->automations_tasks->enqueue_sync('process_automations', $automation, $args);
			}
		} catch (\Exception $e) {
			error_log('Error processing Abandoned Cart Created Trigger: ' . $e->getMessage());
		}
	}
}
