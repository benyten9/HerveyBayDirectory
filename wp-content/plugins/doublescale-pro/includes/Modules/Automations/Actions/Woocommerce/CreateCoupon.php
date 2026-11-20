<?php

/**
 * Create Coupon Action
 *
 * This action will create a coupon.
 *
 * @since 1.0.0
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Actions\Woocommerce;

use DoubleScale\Modules\Automations\Abstracts\Action;
use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Models\AutomationStepModel;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;

/**
 * Create Coupon Action
 */
class CreateCoupon extends Action
{
	/**
	 * Action Name
	 *
	 * @var string
	 */
	public $name = 'Create Coupon';

	/**
	 * Action Slug
	 *
	 * @var string
	 */
	public $slug = 'create_coupon';

	/**
	 * Action Description
	 *
	 * @var string
	 */
	public $description = 'This action will create a coupon.';

	/**
	 * Source
	 *
	 * @var string
	 */
	public $source = 'woocommerce';

	/**
	 * Tigger Group
	 *
	 * @var string
	 */
	public $group = 'coupon';

	/**
	 * Process Action
	 *
	 * @since 1.0.0
	 *
	 * @param AutomationModel         $automation Automation Model.
	 * @param AutomationStepModel    $step Automation Step Model.
	 * @param AutomationContactModel $contact Contact Model.
	 *
	 * @return bool
	 */
	public function process_action(AutomationModel $automation, AutomationStepModel $step, AutomationContactModel $automation_contact)
	{
		try {
			// Check if WooCommerce classes exist
			if (! class_exists('WC_Coupon')) {
				doublescale_get_logger()->error(
					__('WooCommerce plugin is not active. Cannot create coupon.', 'doublescale'),
					array(
						'code'          => 'woocommerce_plugin_inactive',
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
					)
				);
				return false;
			}

			$coupon_expiry_date     = $step->get_setting('coupon_expiry_date');
			$discount_type          = $step->get_setting('discount_type');
			$coupon_prefix          = $step->get_setting('coupon_prefix') ?? '';
			$is_free_shipping       = $step->get_setting('is_free_shipping') ?? false;
			$title                  = $step->get_setting('title') ?? '';
			$usage_limit_per_coupon = $step->get_setting('usage_limit_per_coupon') ?? 0;
			$limit_usage_to_x_items = $step->get_setting('limit_usage_to_x_items') ?? 0;
			$usage_limit_per_user   = $step->get_setting('usage_limit_per_user') ?? 0;
			$individual_use         = wc_string_to_bool($step->get_setting('individual_use') ?? false);
			$exclude_sale_items     = wc_string_to_bool($step->get_setting('exclude_sale_items') ?? false);
			$restrict_to_contact    = wc_string_to_bool($step->get_setting('restrict_to_contact_email') ?? false);

			if (empty($discount_type)) {
				doublescale_get_logger()->info(
					__('Discount type not configured for coupon creation', 'doublescale'),
					array(
						'code'          => 'woocommerce_discount_type_missing',
						'automation_id' => $automation->id,
						'step_id'       => $step->id,
					)
				);
				return false;
			}

			// A coupon meant for one person must never be created open to
			// everyone, so a contact without an email stops the step here.
			$allowed_emails = array();
			if ($restrict_to_contact) {
				$contact_email = $this->get_contact_email($automation_contact);
				if ('' === $contact_email) {
					return array(
						'success' => false,
						'message' => __('The coupon is restricted to the contact\'s email, but this contact has no email address.', 'doublescale'),
					);
				}
				$allowed_emails[] = $contact_email;
			}

			$coupon_code              = $this->generate_dynamic_coupon_code($coupon_prefix);
			$discount_type_and_amount = $this->get_discount_type_and_amount($discount_type['type'], $discount_type['amount']);
			$expiry_date              = $this->get_expiry_date($coupon_expiry_date['type'], $coupon_expiry_date['value']);

			$coupon = new \WC_Coupon();
			$coupon->set_code($coupon_code);
			$coupon->set_amount($discount_type_and_amount['amount']);
			$coupon->set_discount_type($discount_type_and_amount['type']);
			$coupon->set_date_expires($expiry_date);
			$coupon->set_description($title);
			$coupon->set_free_shipping($is_free_shipping);
			$coupon->set_usage_limit($usage_limit_per_coupon);
			$coupon->set_limit_usage_to_x_items($limit_usage_to_x_items);
			$coupon->set_usage_limit_per_user($usage_limit_per_user);
			$coupon->set_individual_use($individual_use);
			$coupon->set_exclude_sale_items($exclude_sale_items);
			$coupon->set_email_restrictions($allowed_emails);
			$coupon->save();

			// automation contact data
			$existing_coupons   = $automation_contact->get_data('coupon_codes', array());
			$existing_coupons[] = array(
				'code'    => $coupon_code,
				'step_id' => $step->id,
			);

			$automation_contact->set_data(array('coupon_codes' => $existing_coupons));

			doublescale_get_logger()->info(
				__('WooCommerce coupon created successfully', 'doublescale'),
				array(
					'code'          => 'woocommerce_coupon_created',
					'coupon_code'   => $coupon_code,
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);

			return true;
		} catch (\Exception $e) {
			doublescale_get_logger()->error(
				__('Failed to create WooCommerce coupon', 'doublescale'),
				array(
					'code'          => 'woocommerce_coupon_creation_failed',
					'error'         => $e->getMessage(),
					'automation_id' => $automation->id,
					'step_id'       => $step->id,
				)
			);
			return false;
		}
	}


	/**
	 * The enrolled contact's email, or '' when it has none worth restricting to.
	 *
	 * @param AutomationContactModel $automation_contact Enrollment row.
	 *
	 * @return string
	 */
	protected function get_contact_email(AutomationContactModel $automation_contact)
	{
		$contact = $automation_contact->contact;
		$email   = is_object($contact) ? sanitize_email((string) ($contact->email ?? '')) : '';

		// WC_Coupon lowercases restrictions itself, as checkout compares lowercased.
		return is_email($email) ? $email : '';
	}

	/**
	 * Generate dynamic coupon code
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Prefix.
	 *
	 * @return string
	 */
	public function generate_dynamic_coupon_code($prefix = '')
	{

		$random_code = strtoupper(wp_generate_password(6, false));

		$coupon_code = $prefix . $random_code;

		return $coupon_code;
	}

	/**
	 * Get expiry date
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Type.
	 * @param string $value Value.
	 *
	 * @return WC_DateTime|string
	 */
	public function get_expiry_date($type, $value)
	{
		switch ($type) {
			case 'never':
				return '';
			case 'days':
				return date('Y-m-d', strtotime("+{$value} days"));
			case 'date':
				return new \WC_DateTime($value);
		}

		return '';
	}

	/**
	 * Get discount type and amount
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Type.
	 * @param string $amount Amount.
	 */
	public function get_discount_type_and_amount($type, $amount)
	{

		if (empty($type) || empty($amount)) {
			return array(
				'type'   => 'fixed_cart',
				'amount' => 0,
			);
		}

		if (! is_numeric($amount)) {
			return array(
				'type'   => 'fixed_cart',
				'amount' => 0,
			);
		}

		if ($amount < 0) {
			return array(
				'type'   => 'fixed_cart',
				'amount' => 0,
			);
		}

		if ($type === 'fixed_cart') {
			return array(
				'type'   => 'fixed_cart',
				'amount' => $amount,
			);
		}

		if ($type === 'fixed_product') {
			return array(
				'type'   => 'fixed_product',
				'amount' => $amount,
			);
		}

		if ($type === 'percent') {
			return array(
				'type'   => 'percent',
				'amount' => $amount > 100 ? 100 : $amount,
			);
		}
	}

	/**
	 * Get fields
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_fields()
	{
		return array(
			'general' => array(
				'type'   => 'group',
				'label'  => __('General', 'doublescale'),
				'fields' => array(
					'title'              => array(
						'type'        => 'text',
						'label'       => __('Coupon Title', 'doublescale'),
						'helperText'  => __('This dynamic coupon can be used in emails or other actions using merge tag: {{coupon:dynamic_id_STEP_ID}}', 'doublescale'),
						'description' => __('After creating this action step, you can use the generated merge tag to reference this coupon in emails and other actions.', 'doublescale'),
					),
					'coupon_prefix'      => array(
						'type'  => 'text',
						'label' => __('Coupon Prefix', 'doublescale'),
					),
					'discount_type'      => array(
						'type'    => 'discount_type_with_amount',
						'label'   => __('Discount Type', 'doublescale'),
						'options' => array(
							'fixed_cart'    => __('Fixed cart discount', 'doublescale'),
							'fixed_product' => __('Fixed product discount', 'doublescale'),
							'percent'       => __('Percentage discount', 'doublescale'),
						),
					),
					'coupon_expiry_date' => array(
						'type'  => 'coupon_expiry_date',
						'label' => __('Coupon Expiry Date', 'doublescale'),
					),
					// 'switch', not 'checkbox': a checkbox field without options
					// renders no input at all, so this was never settable.
					'is_free_shipping'   => array(
						'type'  => 'switch',
						'label' => __('Is Free Shipping', 'doublescale'),
					),
				),
			),
			'limit'   => array(
				'type'   => 'group',
				'label'  => __('Limit', 'doublescale'),
				'fields' => array(
					'usage_limit_per_coupon' => array(
						'type'  => 'number',
						'label' => __('Usage Limit Per Coupon', 'doublescale'),
					),
					'limit_usage_to_x_items' => array(
						'type'  => 'number',
						'label' => __('Limit usage to X items', 'doublescale'),
					),
					'usage_limit_per_user'   => array(
						'type'  => 'number',
						'label' => __('Usage Limit Per User', 'doublescale'),
					),
				),
			),
			'restriction' => array(
				'type'   => 'group',
				'label'  => __('Usage Restriction', 'doublescale'),
				'fields' => array(
					'individual_use'            => array(
						'type'       => 'switch',
						'label'      => __('Individual use only', 'doublescale'),
						'helperText' => __('Turn this on if the coupon cannot be used in conjunction with other coupons.', 'doublescale'),
					),
					'exclude_sale_items'        => array(
						'type'       => 'switch',
						'label'      => __('Exclude sale items', 'doublescale'),
						'helperText' => __('Turn this on if the coupon should not apply to items on sale. Per-item coupons will only work if the item is not on sale. Per-cart coupons will only work if there are items in the cart that are not on sale.', 'doublescale'),
					),
					'restrict_to_contact_email' => array(
						'type'       => 'switch',
						'label'      => __('Restrict to the contact\'s email', 'doublescale'),
						'helperText' => __('Only the contact who received this coupon can use it: WooCommerce checks the billing email at checkout.', 'doublescale'),
					),
				),
			),
		);
	}

	/**
	 * Get attributes schema
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_attributes_schema()
	{
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'                  => array(
					'type'     => 'string',
					'required' => false,
				),
				'discount_type'          => array(
					'type'       => 'object',
					'properties' => array(
						'type'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'amount' => array(
							'type'     => 'number',
							'required' => true,
						),
					),
				),
				'coupon_prefix'          => array(
					'type'     => 'string',
					'required' => false,
				),
				'coupon_expiry_date'     => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array(
							'type'     => 'string',
							'required' => true,
						),
						'type'  => array(
							'type'     => 'string',
							'required' => true,
						),
					),
					'required'   => true,
				),
				'is_free_shipping'       => array(
					'type' => 'boolean',
				),
				'usage_limit_per_coupon' => array(
					'type' => 'string',
				),
				'limit_usage_to_x_items' => array(
					'type' => 'string',
				),
				'usage_limit_per_user'   => array(
					'type' => 'string',
				),
				'individual_use'            => array(
					'type' => 'boolean',
				),
				'exclude_sale_items'        => array(
					'type' => 'boolean',
				),
				'restrict_to_contact_email' => array(
					'type' => 'boolean',
				),
			),
		);
	}
}
