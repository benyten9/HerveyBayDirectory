<?php
/**
 * The field types of DoubleScale's own forms. Each type is a class in Native/Fields
 * (extending Fields\FieldType); this is the registry that finds them.
 *
 * The renderer (src/renderer/forms/field-types) and the builder (src/client/pages/form-builder/lib/
 * field-types.ts) list the same types; a type added here needs both.
 *
 * @package DoubleScale\Modules\Forms\Native
 */

namespace DoubleScale\Modules\Forms\Native;

use DoubleScale\Modules\Forms\Native\Fields;
use DoubleScale\Modules\Forms\Native\Fields\FieldType;

defined( 'ABSPATH' ) || exit;

/**
 * Field type registry.
 */
final class FieldTypes {

	/**
	 * Types by slug, once built.
	 *
	 * @var array<string, FieldType>|null
	 */
	private static $types = null;

	/**
	 * Every type, by slug.
	 *
	 * @return array<string, FieldType>
	 */
	public static function types(): array {
		if ( null === self::$types ) {
			$built = array(
				new Fields\TextField(),
				new Fields\TextareaField(),
				new Fields\EmailField(),
				new Fields\PhoneField(),
				new Fields\NumberField(),
				new Fields\UrlField(),
				new Fields\DateField(),
				new Fields\SelectField(),
				new Fields\RadioField(),
				new Fields\CheckboxField(),
				new Fields\ConsentField(),
				new Fields\RatingField(),
				new Fields\NameField(),
				new Fields\AddressField(),
				new Fields\HiddenField(),
				// DoubleScale Pro replaces this with its own class (same slug).
				new Fields\ProField( 'file', __( 'File upload', 'doublescale' ), true ),
				new Fields\HeadingField(),
				new Fields\ParagraphField(),
				new Fields\HtmlField(),
				new Fields\ImageField(),
				new Fields\DividerField(),
			);

			/**
			 * The field types of DoubleScale forms. Add a type by adding an object that extends
			 * \DoubleScale\Modules\Forms\Native\Fields\FieldType (the renderer and builder need it too).
			 * A type later in the list replaces an earlier one with the same slug.
			 *
			 * @param FieldType[] $built Field types.
			 */
			$built = apply_filters( 'doublescale_native_form_field_type_classes', $built );

			self::$types = array();
			foreach ( (array) $built as $type ) {
				if ( $type instanceof FieldType ) {
					self::$types[ $type->type() ] = $type;
				}
			}
		}
		return self::$types;
	}

	/**
	 * The type of a field (a one-line text for anything unknown).
	 *
	 * @param string|array $field Type slug or field definition.
	 * @return FieldType
	 */
	public static function get( $field ): FieldType {
		$slug  = is_array( $field ) ? (string) ( $field['type'] ?? 'text' ) : (string) $field;
		$types = self::types();
		return $types[ $slug ] ?? $types['text'];
	}

	/**
	 * Every type, with its label and flags (for the admin and REST).
	 *
	 * @return array<string, array{label: string, pro: bool, display: bool}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( self::types() as $slug => $type ) {
			$out[ $slug ] = array(
				'label'   => $type->label(),
				'pro'     => $type->is_pro(),
				'display' => $type->is_display(),
			);
		}
		return apply_filters( 'doublescale_native_form_field_types', $out );
	}

	/**
	 * Whether the type exists.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function exists( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	/**
	 * Clean an answer for the field's type. Returns null for "no answer".
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw answer.
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		return self::get( $field )->sanitize( $field, $value );
	}

	/**
	 * Check a cleaned answer (not null). Returns an error message, or '' when it is fine.
	 * Required answers are checked by the caller (the field may be hidden by logic).
	 *
	 * @param array $field    Field definition.
	 * @param mixed $value    Cleaned answer.
	 * @param array $messages The form's own wording (labels.messages).
	 * @return string
	 */
	public static function validate( array $field, $value, array $messages = array() ): string {
		return self::get( $field )->validate( $field, $value, $messages );
	}

	/**
	 * The answer as one line of text, for emails, the timeline and CSV exports.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored answer.
	 * @return string
	 */
	public static function readable( array $field, $value ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		return self::get( $field )->readable( $field, $value );
	}
}
