<?php
/**
 * Custom field abilities for contact records.
 *
 * @package DoubleScale\Pro\Modules\CustomFields
 */

namespace DoubleScale\Pro\Modules\CustomFields\Abilities;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Abilities\AbilityCategories;
use DoubleScale\Core\Abilities\AbilityInput;
use DoubleScale\Core\Abilities\AbilityResult;
use DoubleScale\Core\UserRoles\Permissions;
use DoubleScale\Modules\Contacts\Abilities\ContactAbilities;
use DoubleScale\Modules\Contacts\Models\ContactModel;
use DoubleScale\Pro\Modules\CustomFields\Models\CustomFieldModel;

/**
 * Read field definitions and get/set values on contacts.
 *
 * Creating or deleting field definitions stays in the admin UI — an agent
 * rewriting the schema would break forms and merge tags across the site.
 */
final class CustomFieldAbilities {

	/**
	 * Ability definitions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$read = array( Permissions::class, 'can_read_contacts' );

		return array(
			'doublescale/list-custom-fields'         => array(
				'module_slug'      => 'custom-fields',
				'label'            => __( 'List custom field definitions', 'doublescale' ),
				'description'      => __( 'Contact custom field definitions with id, name, slug, type, and options. Call this to resolve a field name to an id before get-contact-custom-fields or set-contact-custom-fields. Does not return stored values.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $read,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
				'execute_callback' => array( self::class, 'list_custom_fields' ),
			),

			'doublescale/get-contact-custom-fields'  => array(
				'module_slug'      => 'custom-fields',
				'label'            => __( 'Get custom field values for a contact', 'doublescale' ),
				'description'      => __( 'Stored custom field values on one contact. Fields with no value are omitted.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => $read,
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact id.',
						),
					),
					'required'   => array( 'contact_id' ),
				),
				'execute_callback' => array( self::class, 'get_contact_custom_fields' ),
			),

			'doublescale/set-contact-custom-fields'  => array(
				'module_slug'      => 'custom-fields',
				'label'            => __( 'Set custom field values on a contact', 'doublescale' ),
				'description'      => __( 'Set custom field values on one contact. Only the fields you pass are changed; other values on the contact are left alone. Does not create or delete field definitions. Empty values clear that field.', 'doublescale' ),
				'category'         => AbilityCategories::CONTACTS,
				'permission'       => array( ContactAbilities::class, 'can_write_contacts' ),
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array(
						'contact_id' => array(
							'type'        => 'integer',
							'description' => 'Contact id.',
						),
						'fields'     => array(
							'type'        => 'array',
							'description' => 'One object per field. Each accepts: id (or custom_field_id) and value.',
						),
					),
					'required'   => array( 'contact_id', 'fields' ),
				),
				'meta'             => array(
					'annotations' => array(
						'readonly'      => false,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
				'execute_callback' => array( self::class, 'set_contact_custom_fields' ),
			),
		);
	}

	/**
	 * List contact-scoped field definitions.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function list_custom_fields( array $input ): array {
		$limit  = AbilityResult::limit( $input );
		$offset = AbilityResult::offset( $input );

		$query = CustomFieldModel::query()->where( 'scope', 'contact' );
		$total = (int) $query->count();

		$rows = CustomFieldModel::query()
			->where( 'scope', 'contact' )
			->orderBy( 'id' )
			->skip( $offset )
			->take( $limit )
			->get();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::shape_definition( $row );
		}

		return AbilityResult::collection( $items, $total, $limit, $offset );
	}

	/**
	 * Stored values on one contact.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get_contact_custom_fields( array $input ) {
		$contact = self::load_contact( $input );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$contact->load( 'custom_fields' );

		$items = array();
		foreach ( $contact->custom_fields as $field ) {
			$items[] = array(
				'id'    => (int) $field->id,
				'name'  => (string) $field->name,
				'slug'  => (string) $field->slug,
				'type'  => (string) $field->type,
				'value' => $field->pivot->value ?? null,
			);
		}

		return array(
			'contact_id' => (int) $contact->id,
			'fields'     => $items,
		);
	}

	/**
	 * Write values without replacing fields that were not supplied.
	 *
	 * REST `sync()` would detach every other field on the contact. That is a
	 * footgun for an agent that only meant to set one value.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function set_contact_custom_fields( array $input ) {
		$contact = self::load_contact( $input );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		if ( ! isset( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return new \WP_Error(
				'doublescale_missing_field',
				__( 'Provide fields as a list of {id, value} objects.', 'doublescale' ),
				array(
					'status' => 400,
					'field'  => 'fields',
				)
			);
		}

		$normalized = CustomFieldModel::normalize_submission( $input['fields'] );
		if ( array() === $normalized ) {
			return new \WP_Error(
				'doublescale_empty_batch',
				__( 'No custom field values were recognised in fields.', 'doublescale' ),
				array( 'status' => 400 )
			);
		}

		$changed = array();
		$cleared = array();

		foreach ( $normalized as $field_id => $value ) {
			$field = CustomFieldModel::query()
				->where( 'id', (int) $field_id )
				->where( 'scope', 'contact' )
				->first();

			if ( ! $field ) {
				return new \WP_Error(
					'doublescale_unknown_ids',
					sprintf(
						/* translators: %d: custom field id */
						__( 'Unknown contact custom field id: %d. Call list-custom-fields for ids that exist.', 'doublescale' ),
						(int) $field_id
					),
					array(
						'status' => 400,
						'field'  => 'fields',
					)
				);
			}

			$validated = $field->validate_submission_value( $value );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			if ( $field->is_empty_value( $value ) ) {
				$contact->custom_fields()->detach( (int) $field_id );
				$cleared[] = (int) $field_id;
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ',', $value );
			}

			$contact->custom_fields()->syncWithoutDetaching(
				array(
					(int) $field_id => array(
						'value'       => $value,
						'entity_type' => 'contact',
					),
				)
			);
			$changed[] = (int) $field_id;
		}

		return array(
			'updated'    => array() !== $changed || array() !== $cleared,
			'contact_id' => (int) $contact->id,
			'set'        => $changed,
			'cleared'    => $cleared,
		);
	}

	/**
	 * Load a contact by id from input.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return ContactModel|\WP_Error
	 */
	private static function load_contact( array $input ) {
		$invalid = AbilityInput::first_error(
			array(
				AbilityInput::required( $input, array( 'contact_id' ) ),
				AbilityInput::id( $input['contact_id'] ?? null, 'contact_id' ),
			)
		);
		if ( $invalid ) {
			return $invalid;
		}

		$contact = ContactModel::query()->where( 'id', (int) $input['contact_id'] )->first();
		if ( ! $contact ) {
			return AbilityResult::not_found( __( 'No contact found with that id.', 'doublescale' ) );
		}

		return $contact;
	}

	/**
	 * Shape a field definition.
	 *
	 * @since 1.0.0
	 *
	 * @param CustomFieldModel $field Field.
	 * @return array<string, mixed>
	 */
	private static function shape_definition( CustomFieldModel $field ): array {
		$meta = $field->get_attributes_meta();

		return array(
			'id'       => (int) $field->id,
			'name'     => (string) $field->name,
			'slug'     => (string) $field->slug,
			'type'     => (string) $field->type,
			'required' => ! empty( $meta['required'] ),
			'options'  => $meta['options'],
		);
	}
}
