<?php
/**
 * Shared subscription event handling for Contact Subscribed / Unsubscribed triggers.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Traits;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Modules\Automations\Support\ContactSubscriptionSettings;
use DoubleScale\Modules\Contacts\Models\ContactModel;

defined( 'ABSPATH' ) || exit;

/**
 * ContactSubscriptionTriggerTrait
 */
trait ContactSubscriptionTriggerTrait {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	protected function get_subscription_fields(): array {
		return ContactSubscriptionSettings::fields();
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function get_subscription_schema(): array {
		return ContactSubscriptionSettings::schema();
	}

	/**
	 * @param ContactModel         $contact Contact model.
	 * @param string               $type    Subscription type.
	 * @param array<string, mixed> $extra   Optional extra payload.
	 * @return void
	 */
	protected function dispatch_subscription_event( ContactModel $contact, string $type, array $extra = array() ): void {
		$this->process(
			array(
				'contact' => $contact,
				'data'    => array_merge(
					array(
						'subscription_type' => $type,
					),
					$extra
				),
			)
		);
	}

	/**
	 * @param AutomationModel      $automation Automation model.
	 * @param array<string, mixed> $event      Event data.
	 * @return bool
	 */
	protected function matches_subscription_filter( AutomationModel $automation, array $event ): bool {
		return ContactSubscriptionSettings::matches(
			$automation->get_setting( 'subscription_type', 'any' ),
			$event
		);
	}

	/**
	 * @param string|mixed         $subscription_type Configured type.
	 * @param array<string, mixed> $event             Event payload.
	 * @return bool
	 */
	public static function matches_subscription_settings( $subscription_type, array $event ): bool {
		return ContactSubscriptionSettings::matches( $subscription_type, $event );
	}
}
