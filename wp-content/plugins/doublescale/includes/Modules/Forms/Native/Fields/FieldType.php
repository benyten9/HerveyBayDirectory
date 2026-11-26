<?php
/**
 * A field type of DoubleScale's own forms: what it stores, how an answer is cleaned, checked
 * and shown. Each type is one class extending this one; FieldTypes is the registry.
 *
 * The renderer (src/renderer/forms/field-types) and the builder (src/client/pages/form-builder/lib/
 * field-types.ts) have the same types; a type added here needs both.
 *
 * @package DoubleScale\Modules\Forms\Native\Fields
 */

namespace DoubleScale\Modules\Forms\Native\Fields;

use DoubleScale\Modules\Forms\Native\Texts;

defined( 'ABSPATH' ) || exit;

/**
 * Base field type: a one-line text answer.
 */
abstract class FieldType {

	/**
	 * Type slug, as stored in the form schema.
	 *
	 * @return string
	 */
	abstract public function type(): string;

	/**
	 * Name shown to managers.
	 *
	 * @return string
	 */
	abstract public function label(): string;

	/**
	 * Shows something but takes no answer (heading, image…).
	 *
	 * @return bool
	 */
	public function is_display(): bool {
		return false;
	}

	/**
	 * Needs DoubleScale Pro.
	 *
	 * @return bool
	 */
	public function is_pro(): bool {
		return false;
	}

	/**
	 * Has a list of options to choose from.
	 *
	 * @return bool
	 */
	public function has_options(): bool {
		return false;
	}

	/**
	 * May have a default answer.
	 *
	 * @return bool
	 */
	public function takes_default(): bool {
		return ! $this->is_display();
	}

	/**
	 * Uses its default when the browser sent nothing (a hidden field).
	 *
	 * @return bool
	 */
	public function default_when_empty(): bool {
		return false;
	}

	/**
	 * Logic can hide it (and then its answer is dropped).
	 *
	 * @return bool
	 */
	public function hidden_by_logic(): bool {
		return true;
	}

	/**
	 * Its answer can be written to the contact.
	 *
	 * @return bool
	 */
	public function saves_to_contact(): bool {
		return true;
	}

	/**
	 * Saved with an unfinished entry, and handed back when someone resumes it.
	 *
	 * @return bool
	 */
	public function saved_in_partial(): bool {
		return true;
	}

	/**
	 * Its answer is uploaded files (tokens from the upload endpoint).
	 *
	 * @return bool
	 */
	public function is_upload(): bool {
		return false;
	}

	/**
	 * Listed with the other answers ({{form:all_answers}}, emails).
	 *
	 * @return bool
	 */
	public function in_answers_list(): bool {
		return true;
	}

	/**
	 * Quoted in the contact's timeline entry.
	 *
	 * @return bool
	 */
	public function in_timeline(): bool {
		return $this->in_answers_list();
	}

	/**
	 * Type-specific settings, after the generic cleaning in Schema.
	 *
	 * @param array $props Props, already cleaned as text, numbers, booleans and lists.
	 * @param array $raw   Props as sent.
	 * @return array
	 */
	public function props( array $props, array $raw ): array {
		return $props;
	}

	/**
	 * Clean an answer. Returns null for "no answer".
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw answer.
	 * @return mixed
	 */
	public function sanitize( array $field, $value ) {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		return '' === $value ? null : self::cut( $value, 1000 );
	}

	/**
	 * Check a cleaned answer (not null). Returns the error, or '' when it is fine.
	 * Required answers are checked by the caller (logic may hide the field).
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Cleaned answer.
	 * @param array $texts The form's own wording (labels.messages).
	 * @return string
	 */
	public function validate( array $field, $value, array $texts ): string {
		return '';
	}

	/**
	 * The answer as one line of text, for emails, the timeline and CSV exports.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored answer (not empty).
	 * @return string
	 */
	public function readable( array $field, $value ): string {
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * The answer written to a built-in contact field.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Stored answer.
	 * @return string
	 */
	public function contact_text( array $field, $value ): string {
		return is_scalar( $value ) ? (string) $value : $this->readable( $field, $value );
	}

	/**
	 * The contact field this answer fills when the form maps none for it (email, phone, name).
	 *
	 * @return string Contact field key, or ''.
	 */
	public function contact_fallback(): string {
		return '';
	}

	/**
	 * Contact columns for an answer made of parts (first/last name, address lines), whatever
	 * field it is mapped to. Null when the answer is one value.
	 *
	 * @param mixed $value Stored answer.
	 * @return array<string, string>|null
	 */
	public function contact_parts( $value ): ?array {
		return null;
	}

	/**
	 * A text in the form's wording (or the default).
	 *
	 * @param array  $texts   The form's own wording.
	 * @param string $key     Text key.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	protected function say( array $texts, string $key, ...$args ): string {
		return Texts::say( $texts, $key, ...$args );
	}

	/**
	 * Cut a string to a length.
	 *
	 * @param string $value  Text.
	 * @param int    $length Most characters.
	 * @return string
	 */
	protected static function cut( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/**
	 * A list answer (one value or several) as clean strings.
	 *
	 * @param mixed $value Raw answer.
	 * @return array
	 */
	protected static function as_list( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		return is_scalar( $value ) && '' !== (string) $value ? array( $value ) : array();
	}
}
