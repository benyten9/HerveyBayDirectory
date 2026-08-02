<?php
/**
 * Shared setting: treat a contact phone number as their WhatsApp number.
 *
 * @package DoubleScale
 */

namespace DoubleScale\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Phone-as-WhatsApp toggle used by WooCommerce automations and imports.
 */
final class PhoneAsWhatsappSetting {

	/**
	 * Setting key stored on automations and import requests.
	 */
	public const SETTING_KEY = 'phone_is_whatsapp';

	/**
	 * Field definition for automation trigger and importer UIs.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_field(): array {
		return array(
			'label'      => __( 'Phone number is also WhatsApp number', 'doublescale' ),
			'type'       => 'switch',
			'default'    => true,
			'helperText' => __( 'When enabled, the WooCommerce phone number will also be saved as the contact WhatsApp number (E.164 format).', 'doublescale' ),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields_entry(): array {
		return array(
			self::SETTING_KEY => self::get_field(),
		);
	}

	/**
	 * Resolve whether phone should be copied to whatsapp_phone.
	 *
	 * @param mixed $value Raw setting value or automation model.
	 * @param bool  $default Default when unset.
	 */
	public static function is_enabled( $value, bool $default = true ): bool {
		if ( is_object( $value ) && method_exists( $value, 'get_setting' ) ) {
			$value = $value->get_setting( self::SETTING_KEY, $default );
		}

		if ( null === $value || '' === $value ) {
			return $default;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
