<?php
/**
 * Shared subscription event settings and matching for automations.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Support;

defined( 'ABSPATH' ) || exit;

/**
 * ContactSubscriptionSettings
 */
class ContactSubscriptionSettings {

	/**
	 * @return array<string, string>
	 */
	public static function type_options(): array {
		return array(
			'any'      => __( 'Any (Email, SMS, or WhatsApp)', 'doublescale' ),
			'email'    => __( 'Email', 'doublescale' ),
			'sms'      => __( 'SMS', 'doublescale' ),
			'whatsapp' => __( 'WhatsApp', 'doublescale' ),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'subscription_type' => array(
				'label'         => __( 'Subscription type', 'doublescale' ),
				'type'          => 'select',
				'options'       => self::type_options(),
				'default-value' => 'any',
				'description'   => __( 'Choose which subscribe or unsubscribe event should start this automation.', 'doublescale' ),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'subscription_type' => array(
					'type' => 'string',
					'enum' => array( 'any', 'email', 'sms', 'whatsapp' ),
				),
			),
		);
	}

	/**
	 * @param string|mixed         $subscription_type Configured type.
	 * @param array<string, mixed> $event             Event payload.
	 * @return bool
	 */
	public static function matches( $subscription_type, array $event ): bool {
		$configured_type = is_string( $subscription_type ) ? $subscription_type : '';
		if ( '' === $configured_type ) {
			$configured_type = 'any';
		}

		$event_type = (string) ( $event['subscription_type'] ?? '' );

		if ( 'any' !== $configured_type && $configured_type !== $event_type ) {
			return false;
		}

		return true;
	}
}
