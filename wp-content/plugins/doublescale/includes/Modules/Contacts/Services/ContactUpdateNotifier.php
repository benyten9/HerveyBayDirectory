<?php
/**
 * Fires the contact-update action when profile information changes.
 *
 * @package DoubleScale\Modules\Contacts
 */

namespace DoubleScale\Modules\Contacts\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * ContactUpdateNotifier.
 */
final class ContactUpdateNotifier {

	/**
	 * Profile columns that qualify as "contact information" for automations.
	 *
	 * @var string[]
	 */
	public const PROFILE_FIELDS = array(
		'first_name',
		'last_name',
		'email',
		'phone',
		'whatsapp_phone',
		'address_1',
		'address_2',
		'city',
		'state',
		'country',
		'zip',
		'email_status',
		'sms_status',
		'whatsapp_status',
	);

	/**
	 * Notify listeners that contact information was updated.
	 *
	 * @param ContactModel|int $contact Contact model or ID.
	 * @param array            $context {
	 *     @type string $updated_by Who performed the update: `admin` or `contact`.
	 *     @type array  $changes    Map of field => [ old, new ].
	 * }
	 */
	public static function fire( $contact, array $context = array() ): void {
		if ( is_numeric( $contact ) ) {
			$contact = ContactModel::find( (int) $contact );
		}

		if ( ! $contact instanceof ContactModel || ! $contact->exists ) {
			return;
		}

		$context = wp_parse_args(
			$context,
			array(
				'updated_by' => 'admin',
				'changes'    => array(),
			)
		);

		/**
		 * Fires after a contact's profile information is updated.
		 *
		 * @param ContactModel $contact Updated contact.
		 * @param array        $context Update context (updated_by, changes).
		 */
		do_action( 'doublescale_contact_update', $contact, $context );
	}

	/**
	 * Diff incoming profile data against the current contact row.
	 *
	 * @param ContactModel        $contact  Current contact.
	 * @param array<string,mixed> $new_data Incoming field values.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	public static function collect_field_changes( ContactModel $contact, array $new_data ): array {
		$changes = array();

		foreach ( $new_data as $key => $new_value ) {
			if ( ! in_array( $key, self::PROFILE_FIELDS, true ) ) {
				continue;
			}

			$old_value = $contact->getAttribute( $key );
			if ( (string) $old_value === (string) $new_value ) {
				continue;
			}

			$changes[ $key ] = array(
				'old' => $old_value,
				'new' => $new_value,
			);
		}

		return $changes;
	}

	/**
	 * Merge custom-field diffs into a changes map.
	 *
	 * @param ContactModel        $contact         Contact with custom_fields loaded.
	 * @param array<string,mixed> $submitted       Normalized field_id => value map.
	 * @param array<string, array{old: mixed, new: mixed}> $changes Existing changes.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	public static function merge_custom_field_changes( ContactModel $contact, array $submitted, array $changes = array() ): array {
		if ( empty( $submitted ) ) {
			return $changes;
		}

		$existing = array();
		if ( $contact->relationLoaded( 'custom_fields' ) ) {
			foreach ( $contact->custom_fields as $field ) {
				$existing[ (string) $field->id ] = $field->pivot->value ?? '';
			}
		}

		foreach ( $submitted as $field_id => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}

			$field_key = (string) $field_id;
			$old_value = $existing[ $field_key ] ?? '';
			if ( (string) $old_value === (string) $value ) {
				continue;
			}

			$changes[ 'custom_field_' . $field_key ] = array(
				'old' => $old_value,
				'new' => $value,
			);
		}

		return $changes;
	}
}
