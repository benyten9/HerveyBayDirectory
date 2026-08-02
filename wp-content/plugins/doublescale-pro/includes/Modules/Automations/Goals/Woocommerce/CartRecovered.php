<?php

/**
 * Cart Recovered Goal
 *
 * This goal is achieved when a contact recovers an abandoned cart by completing their purchase.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Goals\Woocommerce;

use DoubleScale\Modules\Automations\Abstracts\GoalPro;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AbandonedCartModel;

/**
 * Cart Recovered Goal class
 */
class CartRecovered extends GoalPro {

	/**
	 * Allowed triggers
	 *
	 * This goal should only complete automations started by the abandoned cart trigger.
	 *
	 * @var array
	 */
	protected $allowed_triggers = array( 'wc_abandoned_cart_created' );

	/**
	 * Goal Name
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $name = 'Cart Recovered';

	/**
	 * Goal Slug
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $slug = 'wc_cart_recovered_goal';

	/**
	 * Goal Description
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $description = 'This goal is achieved when a contact recovers an abandoned cart by completing their purchase.';

	/**
	 * Source
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $source = 'woocommerce';

	/**
	 * Group
	 *
	 * @var string
	 *
	 * @since 1.0.0
	 */
	public $group = 'cart';

	/**
	 * Load Hooks
	 *
	 * Registers the WordPress action that will trigger this goal.
	 * The hook 'doublescale_abandoned_cart_recover' is fired in:
	 * includes/abandoned-cart/class-abandoned-cart.php:228
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_hooks() {
		add_action( 'doublescale_abandoned_cart_recover', array( $this, 'cart_recovered' ), 10, 1 );
	}

	/**
	 * Cart Recovered Handler
	 *
	 * Called when a cart is recovered. Extracts contact from the abandoned cart
	 * model and passes it to the goal processing system.
	 *
	 * @since 1.0.0
	 *
	 * @param AbandonedCartModel $abandoned_cart The recovered cart model.
	 *
	 * @return void
	 */
	public function cart_recovered( $abandoned_cart ) {
		// Validate we have an abandoned cart model with email
		if ( ! $abandoned_cart || empty( $abandoned_cart->email ) ) {
			doublescale_get_logger()->debug(
				__( 'Cart Recovered Goal: Invalid abandoned cart or missing email', 'doublescale'),
				array(
					'code'    => 'cart_recovered_goal_invalid_cart',
					'cart_id' => $abandoned_cart->id ?? null,
				)
			);
			return;
		}

		// Get the contact by email (abandoned_carts table links via email, not contact_id)
		$contact = ContactModel::get_by_email( $abandoned_cart->email );
		if ( ! $contact ) {
			doublescale_get_logger()->debug(
				__( 'Cart Recovered Goal: Contact not found for recovered cart', 'doublescale'),
				array(
					'code'    => 'cart_recovered_goal_contact_not_found',
					'cart_id' => $abandoned_cart->id,
					'email'   => $abandoned_cart->email,
				)
			);
			return;
		}

		// Prepare data to pass to is_completed()
		$data = array(
			'cart_id'    => $abandoned_cart->id,
			'cart_hash'  => $abandoned_cart->cart_hash ?? null,
			'cart_total' => $abandoned_cart->total ?? 0,
			'cart_items' => $abandoned_cart->items ?? array(),
			'order_id'   => $abandoned_cart->order_id ?? null,
		);

		// Process the goal - this will check all waiting automation contacts
		$this->process( $contact, $data );
	}

	/**
	 * Check if the goal is completed
	 *
	 * This method determines if the goal criteria are met. For cart recovery,
	 * we verify the recovered cart matches the one that triggered the automation
	 * and support optional minimum cart value filtering.
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationContactModel $automation_contact The automation contact record.
	 * @param array                    $data               Cart recovery data.
	 *
	 * @return bool True if goal is completed, false otherwise.
	 */
	public function is_completed( AutomationContactModel $automation_contact, $data ) {
		$current_step = AutomationStepModel::find( $automation_contact->current_step );

		if ( ! $current_step ) {
			return false;
		}

		// Verify this automation was triggered by the abandoned cart trigger
		$automation = $automation_contact->automation;
		if ( $automation ) {
			// Note: 'trigger' is a direct column on automation, not in settings
			$trigger_slug = $automation->trigger;
			if ( ! in_array( $trigger_slug, $this->allowed_triggers, true ) ) {
				// This automation wasn't started by an abandoned cart trigger,
				// so this goal shouldn't complete it
				return false;
			}
		}

		// Get goal settings
		$match_specific_cart = $current_step->get_setting( 'match_specific_cart', true );
		$min_cart_value      = $current_step->get_setting( 'min_cart_value', 0 );
		$cart_total          = $data['cart_total'] ?? 0;

		// Check if the recovered cart is the same one that triggered the automation
		if ( $match_specific_cart ) {
			$trigger_cart_id   = $automation_contact->get_data( 'cart_id' );
			$recovered_cart_id = $data['cart_id'] ?? null;

			if ( $trigger_cart_id && $recovered_cart_id && (int) $trigger_cart_id !== (int) $recovered_cart_id ) {
				// Different cart was recovered - not the one that triggered this automation
				return false;
			}
		}

		// If minimum cart value is set, check if recovered cart meets it
		if ( $min_cart_value > 0 && $cart_total < $min_cart_value ) {
			return false;
		}

		// Goal completed - cart was recovered and meets criteria
		return true;
	}

	/**
	 * Get fields
	 *
	 * Returns the configuration fields shown in the UI when setting up this goal.
	 *
	 * @since 1.0.0
	 *
	 * @return array Field configuration array.
	 */
	public function get_fields() {
		return array(
			'match_specific_cart' => array(
				'label'   => __( 'Match Specific Cart', 'doublescale'),
				'type'    => 'toggle',
				'default' => true,
				'help'    => __( 'When enabled, the goal only completes if the specific cart that triggered this automation is recovered. Disable to complete when any cart is recovered.', 'doublescale'),
			),
			'min_cart_value'      => array(
				'label'       => __( 'Minimum Cart Value', 'doublescale'),
				'type'        => 'number',
				'placeholder' => '0.00',
				'help'        => __( 'Optional: Only complete goal if recovered cart value is at least this amount. Leave at 0 for any cart value.', 'doublescale'),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * Returns the JSON schema for validating goal settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array JSON schema array.
	 */
	public function get_attributes_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'match_specific_cart' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'min_cart_value'      => array(
					'type'    => 'number',
					'minimum' => 0,
				),
			),
		);
	}
}
