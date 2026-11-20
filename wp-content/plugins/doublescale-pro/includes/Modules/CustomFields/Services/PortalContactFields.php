<?php
/**
 * Contact custom fields on the client's portal profile.
 *
 * Each contact field has a Client portal setting (attributes.portal): hidden
 * (default), visible, or editable. The free plugin's portal profile endpoint
 * asks for the fields through `doublescale_portal_contact_custom_fields`,
 * checks submitted values through `doublescale_portal_contact_custom_fields_prepare`
 * and saves them through `doublescale_portal_contact_custom_fields_save`.
 *
 * @package DoubleScale\Pro\Modules\CustomFields
 */

namespace DoubleScale\Pro\Modules\CustomFields\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

final class PortalContactFields {

	/**
	 * Longest text a client may type into a single-line or multi-line field.
	 */
	const MAX_TEXT = 1000;
	const MAX_TEXTAREA = 5000;

	/**
	 * Hook into the portal profile endpoint.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'doublescale_portal_contact_custom_fields', array( self::class, 'fields_for' ), 10, 3 );
		add_filter( 'doublescale_portal_contact_custom_fields_prepare', array( self::class, 'prepare' ), 10, 3 );
		add_action( 'doublescale_portal_contact_custom_fields_save', array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Contact fields the admin shows in the portal, in their usual order.
	 *
	 * @return CustomFieldModel[]
	 */
	private static function portal_fields() {
		$fields = CustomFieldModel::query()->where( 'scope', 'contact' )->ordered()->get();

		return array_values(
			array_filter(
				$fields->all(),
				static function ( CustomFieldModel $field ) {
					return CustomFieldModel::PORTAL_HIDDEN !== $field->get_portal_access();
				}
			)
		);
	}

	/**
	 * The contact's current values, by field id.
	 *
	 * @param object $contact Contact model.
	 * @return array<int, string>
	 */
	private static function current_values( $contact ) {
		$values   = array();
		$relation = method_exists( $contact, 'custom_fields' ) ? $contact->custom_fields() : null;
		if ( ! $relation ) {
			return $values;
		}
		foreach ( $relation->get() as $field ) {
			$values[ (int) $field->id ] = (string) ( $field->pivot->value ?? '' );
		}

		return $values;
	}

	/**
	 * Fields for the client's profile.
	 *
	 * @param array  $fields   Fields so far.
	 * @param object $contact  The client's contact.
	 * @param bool   $can_edit Whether the client may edit their details at all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields_for( $fields, $contact, $can_edit = true ) {
		$fields = is_array( $fields ) ? $fields : array();
		if ( ! $contact ) {
			return $fields;
		}

		$values = self::current_values( $contact );
		foreach ( self::portal_fields() as $field ) {
			$value    = $values[ (int) $field->id ] ?? '';
			$fields[] = array(
				'id'       => (int) $field->id,
				'label'    => (string) $field->name,
				'type'     => (string) $field->type,
				'value'    => in_array( $field->type, array( 'multiselect', 'checkbox' ), true )
					? array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) )
					: $value,
				'options'  => $field->get_option_values(),
				'required' => $field->is_required_field(),
				'editable' => $can_edit && CustomFieldModel::PORTAL_EDITABLE === $field->get_portal_access(),
			);
		}

		return $fields;
	}

	/**
	 * Plain text only: values reach emails (merge tags) and documents.
	 *
	 * @param CustomFieldModel $field Field.
	 * @param mixed            $value Submitted value.
	 * @return mixed
	 */
	private static function clean( CustomFieldModel $field, $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ), 'strlen' ) );
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		$value = (string) $value;

		return 'textarea' === $field->type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	/**
	 * Check what the client sent: only editable fields, each validated like the
	 * team's own form. Nothing is saved here.
	 *
	 * @param array|null $prepared Earlier result.
	 * @param object     $contact  The client's contact.
	 * @param array      $custom   Submitted { field id: value }.
	 * @return array{values: array<int, mixed>, errors: array<int, string>, changes: array<string, array{old: string, new: string}>, labels: array<string, string>}
	 */
	public static function prepare( $prepared, $contact, $custom ) {
		$result = array(
			'values'  => array(),
			'errors'  => array(),
			'changes' => array(),
			'labels'  => array(),
		);
		if ( ! $contact || ! is_array( $custom ) ) {
			return $result;
		}

		$editable = array();
		foreach ( self::portal_fields() as $field ) {
			if ( CustomFieldModel::PORTAL_EDITABLE === $field->get_portal_access() ) {
				$editable[ (int) $field->id ] = $field;
			}
		}
		$current = self::current_values( $contact );

		foreach ( $custom as $field_id => $value ) {
			$field_id = (int) $field_id;
			$field    = $editable[ $field_id ] ?? null;
			if ( ! $field ) {
				$result['errors'][ $field_id ] = __( 'This detail can only be changed by our team.', 'doublescale' );
				continue;
			}

			$value = self::clean( $field, $value );
			$max   = 'textarea' === $field->type ? self::MAX_TEXTAREA : self::MAX_TEXT;
			if ( is_string( $value ) && mb_strlen( $value ) > $max ) {
				/* translators: %d: most characters the field takes */
				$result['errors'][ $field_id ] = sprintf( __( 'Use at most %d characters.', 'doublescale' ), $max );
				continue;
			}

			$valid = $field->validate_submission_value( $value );
			if ( is_wp_error( $valid ) ) {
				$result['errors'][ $field_id ] = 'custom_field_required' === $valid->get_error_code()
					? __( 'This field is required.', 'doublescale' )
					: __( 'Please check this value.', 'doublescale' );
				continue;
			}

			$stored = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			$old    = $current[ $field_id ] ?? '';
			if ( $stored === $old ) {
				continue;
			}

			$key                          = 'custom_field_' . $field_id;
			$result['values'][ $field_id ] = $field->is_empty_value( $value ) ? '' : $stored;
			$result['changes'][ $key ]     = array(
				'old' => $old,
				'new' => $stored,
			);
			$result['labels'][ $key ]      = (string) $field->name;
		}

		return $result;
	}

	/**
	 * Save checked values without touching the contact's other fields.
	 *
	 * @param object $contact The client's contact.
	 * @param array  $values  { field id: value } that changed ('' clears).
	 * @return void
	 */
	public static function save( $contact, $values ) {
		if ( ! $contact || ! is_array( $values ) || ! method_exists( $contact, 'custom_fields' ) ) {
			return;
		}
		$relation = $contact->custom_fields();
		if ( ! $relation ) {
			return;
		}

		foreach ( $values as $field_id => $value ) {
			if ( '' === (string) $value ) {
				$relation->detach( (int) $field_id );
				continue;
			}
			$relation->syncWithoutDetaching(
				array(
					(int) $field_id => array(
						'value'       => (string) $value,
						'entity_type' => 'contact',
					),
				)
			);
		}
	}
}
