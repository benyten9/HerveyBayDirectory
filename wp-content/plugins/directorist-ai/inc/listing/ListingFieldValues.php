<?php
/**
 * Native typed listing values. Tool values remain bounded strings.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Closed native field codecs for review, unsaved form copying and draft persistence. */
final class ListingFieldValues {

	/** Check the bounded native type and exclusion flags.
	 *
	 * @param array $props Native field settings.
	 * @return bool
	 */
	public static function supported( array $props ): bool {
		foreach ( array( 'readonly', 'read_only', 'disabled', 'private', 'exclude_from_ai' ) as $flag ) {
			if ( ! empty( $props[ $flag ] ) ) {
				return false;
			}
		}
		return in_array( $props['widget_name'] ?? '', array( 'title', 'description', 'excerpt', 'tagline', 'text', 'textarea', 'email', 'phone', 'phone2', 'fax', 'website', 'url', 'address', 'zip', 'number', 'select', 'radio', 'checkbox', 'date', 'time', 'switch', 'color', 'category', 'tag', 'location', 'pricing' ), true );
	}

	/** Resolve the native taxonomy for one installed field.
	 *
	 * @param array $props Native field settings.
	 * @return string|null
	 */
	public static function taxonomy( array $props ): ?string {
		return array(
			'category' => ATBDP_CATEGORY,
			'tag'      => ATBDP_TAGS,
			'location' => ATBDP_LOCATION,
		)[ $props['widget_name'] ?? '' ] ?? null;
	}

	/** Identify multi-value transport fields.
	 *
	 * @param array $props Native field settings.
	 * @return bool
	 */
	public static function is_array( array $props ): bool {
		return 'checkbox' === ( $props['widget_name'] ?? '' ) || null !== self::taxonomy( $props );
	}

	/** Return exact installed choices without claiming a truncated catalog is complete.
	 *
	 * @param array $props Native field settings.
	 * @param int   $directory Bound native directory.
	 * @return array
	 * @throws ContractException Unsupported or ambiguous native choices.
	 */
	public static function choices( array $props, int $directory ): array {
		$taxonomy = self::taxonomy( $props );
		if ( null !== $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 501,
				)
			);
			if ( is_wp_error( $terms ) || count( $terms ) > 500 ) {
				throw new ContractException( 'unsupported_contract', 'This taxonomy needs a narrower native selection.' );
			}
			$result = array();
			foreach ( $terms as $term ) {
				$directories = array_values( array_filter( array_map( 'intval', directorist_get_term_directory( $term->term_id ) ), static fn( int $id ): bool => $id > 0 ) );
				if ( ! in_array( $directory, $directories, true ) && ( ATBDP_TAGS !== $taxonomy || $directories ) ) {
					continue;
				}
				$result[] = (object) array(
					'value' => (int) $term->term_id,
					'label' => $term->name,
				);
			}
			return $result;
		}
		if ( ! in_array( $props['widget_name'] ?? '', array( 'select', 'radio', 'checkbox' ), true ) ) {
			return array();
		}
		$options = $props['options'] ?? array();
		if ( ! is_array( $options ) || count( $options ) > 100 ) {
			throw new ContractException( 'unsupported_contract', 'This native field has too many choices for automatic filling.' );
		}
		$result = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) || ! is_string( $option['option_value'] ?? null ) ) {
				throw new ContractException( 'unsupported_contract', 'A native field choice is invalid.' );
			}
			$value = str_replace( '&lt;', '<', $option['option_value'] );
			if ( isset( $result[ $value ] ) || strlen( $value ) > 512 ) {
				throw new ContractException( 'unsupported_contract', 'Native field choices are ambiguous.' );
			}
			$result[ $value ] = (object) array(
				'value' => $value,
				'label' => wp_strip_all_tags( $option['option_label'] ?? $value ),
			);
		}
		return array_values( $result );
	}

	/** Describe only the supported typed value contract, never settings write authority.
	 *
	 * @param array $props Native field settings.
	 * @param int   $directory Bound native directory.
	 * @return array
	 */
	public static function contract( array $props, int $directory ): array {
		$widget   = $props['widget_name'];
		$taxonomy = self::taxonomy( $props );
		$format   = 'pricing' === $widget ? 'json_object_native_pricing' : ( null !== $taxonomy ? 'json_array_of_existing_term_ids' : ( 'checkbox' === $widget ? 'json_array_of_option_values' : ( 'switch' === $widget ? '0_or_1' : ( 'date' === $widget ? 'YYYY-MM-DD' : ( 'time' === $widget ? 'HH:MM' : 'string' ) ) ) ) );
		return array(
			'widget'       => $widget,
			'value_format' => $format,
			'options'      => self::choices( $props, $directory ),
			'max_items'    => self::is_array( $props ) ? ( 'single' === ( $props['type'] ?? '' ) ? 1 : min( 50, (int) ( $props['max'] ?? 0 ) > 0 ? (int) $props['max'] : 50 ) ) : null,
			'minimum'      => 'number' === $widget && isset( $props['min_value'] ) ? $props['min_value'] : null,
			'maximum'      => 'number' === $widget && (float) ( $props['max_value'] ?? 0 ) > 0 ? $props['max_value'] : null,
			'taxonomy'     => $taxonomy,
			'pricing'      => 'pricing' === $widget ? ListingPricingValues::contract( $props ) : null,
		);
	}

	/** Decode transport syntax before mandatory native validation.
	 *
	 * @param array  $props Native field settings.
	 * @param string $wire Captured or proposed transport value.
	 * @return mixed
	 * @throws ContractException Invalid array syntax or choice identity.
	 */
	public static function decode( array $props, string $wire ): mixed {
		if ( 'pricing' === ( $props['widget_name'] ?? '' ) ) {
			return ListingPricingValues::decode( $wire );
		}
		if ( self::is_array( $props ) ) {
			$value = '' === $wire ? array() : json_decode( $wire );
			if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > 50 ) {
				throw new ContractException( 'invalid_input', 'Use a JSON array within the native selection limit.' );
			}
			$taxonomy = null !== self::taxonomy( $props );
			foreach ( $value as $item ) {
				if ( $taxonomy ? ! is_int( $item ) || $item < 1 : ! is_string( $item ) ) {
					throw new ContractException( 'invalid_input', 'Use only declared native choice values.' );
				}
			}
			if ( count( array_unique( $value, $taxonomy ? SORT_NUMERIC : SORT_STRING ) ) !== count( $value ) ) {
				throw new ContractException( 'invalid_input', 'Native selections must be distinct.' );
			}
			sort( $value, $taxonomy ? SORT_NUMERIC : SORT_STRING );
			return $value;
		}
		return 'switch' === ( $props['widget_name'] ?? '' ) ? '1' === $wire : $wire;
	}

	/** Encode native values consistently across PHP and browser controls.
	 *
	 * @param array $props Native field settings.
	 * @param mixed $value Native sanitized value.
	 * @return string
	 */
	public static function wire( array $props, mixed $value ): string {
		if ( 'pricing' === ( $props['widget_name'] ?? '' ) ) {
			return ListingPricingValues::wire( $value );
		}
		if ( self::is_array( $props ) ) {
			$value    = (array) $value;
			$taxonomy = self::taxonomy( $props );
			$value    = null !== $taxonomy ? array_map( 'intval', $value ) : array_map( 'strval', $value );
			sort( $value, null !== $taxonomy ? SORT_NUMERIC : SORT_STRING );
			return wp_json_encode( array_values( $value ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS );
		}
		return 'switch' === ( $props['widget_name'] ?? '' ) ? ( $value ? '1' : '0' ) : (string) $value;
	}

	/** Validate an exact requested value with native constraints and assignment authority.
	 *
	 * @param array        $props Native field settings.
	 * @param string       $wire Proposed transport value.
	 * @param int          $directory Bound native directory.
	 * @param Context|null $context Current actor for taxonomy assignment.
	 * @return string
	 * @throws ContractException Invalid, unavailable or unauthorized native value.
	 */
	public static function sanitize( array $props, string $wire, int $directory, ?Context $context = null ): string {
		if ( ! self::supported( $props ) || strlen( $wire ) > 8192 ) {
			throw new ContractException( 'invalid_input', 'This native field is not available for automatic filling.' );
		}
		$widget = $props['widget_name'];
		if ( 'pricing' === $widget ) {
			return ListingPricingValues::sanitize( $props, $wire );
		}
		if ( 'switch' === $widget && ! in_array( $wire, array( '0', '1' ), true ) ) {
			throw new ContractException( 'invalid_input', 'Use 0 or 1 for this native switch.' );
		}
		$value = self::decode( $props, $wire );
		if ( ! empty( $props['required'] ) && ( '' === $value || array() === $value || false === $value ) ) {
			throw new ContractException( 'invalid_input', 'A required native field cannot be cleared.' );
		}
		$contract = self::contract( $props, $directory );
		$taxonomy = self::taxonomy( $props );
		if ( null !== $taxonomy || in_array( $widget, array( 'select', 'radio', 'checkbox' ), true ) ) {
			$allowed = array_column( $contract['options'], 'value' );
			foreach ( self::is_array( $props ) ? $value : ( '' === $value && empty( $props['required'] ) ? array() : array( $value ) ) as $item ) {
				if ( ! in_array( $item, $allowed, true ) ) {
					throw new ContractException( 'invalid_input', 'Choose an exact value from the current native options.' );
				}
			}
			if ( self::is_array( $props ) && count( $value ) > $contract['max_items'] ) {
				throw new ContractException( 'invalid_input', 'The suggestion exceeds the native selection limit.' );
			}
		}
		if ( null !== $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			if ( null === $context || ! $object || ! $context->actor()->can( $object->cap->assign_terms ) ) {
				throw new ContractException( 'unauthorized', 'This native taxonomy cannot be assigned.' );
			}
		}
		if ( 'date' === $widget && '' !== $wire ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $wire );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $wire ) {
				throw new ContractException( 'invalid_input', 'Use a valid date in YYYY-MM-DD format.' );
			}
		}
		if ( 'number' === $widget && '' !== $wire && ( ! is_numeric( $wire ) || ! is_finite( (float) $wire ) ) ) {
			throw new ContractException( 'invalid_input', 'Use a finite native number.' );
		}
		$field  = \Directorist\Fields\Fields::create( $props );
		$posted = null !== $taxonomy ? array( 'tax_input' => array( $taxonomy => $value ) ) : array( $field->get_key() => $value );
		$empty  = '' === $value || array() === $value;
		if ( ! $empty && ! $field->validate( $posted ) ) {
			throw new ContractException( 'invalid_input', 'The suggestion does not meet the native field format or limits.' );
		}
		if ( ! self::is_array( $props ) && ! in_array( $widget, array( 'number', 'switch' ), true ) && (int) ( $props['max'] ?? 0 ) > 0 && mb_strlen( wp_strip_all_tags( $wire ) ) > (int) $props['max'] ) {
			throw new ContractException( 'invalid_input', 'A suggestion exceeds the native field limits.' );
		}
		return self::wire( $props, $empty ? $value : ( null !== $taxonomy ? $value : $field->sanitize( $posted ) ) );
	}

	/** Project typed values into the native conditional-logic request shape.
	 *
	 * @param array  $posted Current native condition facts, updated in place.
	 * @param array  $props Native field settings.
	 * @param string $wire Captured or validated value.
	 * @return void
	 */
	public static function posted( array &$posted, array $props, string $wire ): void {
		$value = self::decode( $props, $wire );
		if ( 'pricing' === ( $props['widget_name'] ?? '' ) ) {
			$posted = array_merge( $posted, ListingPricingValues::posted( $value ) );
		}
		$posted[ $props['field_key'] ] = $value;
		$taxonomy                      = self::taxonomy( $props );
		if ( null !== $taxonomy ) {
			$posted['tax_input'][ $taxonomy ] = $value;
			$posted[ $props['widget_name'] ]  = $value;
		}
	}

	/** Render grounded option labels for review without exposing raw term identifiers.
	 *
	 * @param array  $props Native field settings.
	 * @param string $wire Captured or validated value.
	 * @param int    $directory Bound native directory.
	 * @return string
	 */
	public static function display( array $props, string $wire, int $directory ): string {
		if ( 'pricing' === ( $props['widget_name'] ?? '' ) ) {
			return ListingPricingValues::display( $props, $wire );
		}
		$value   = self::decode( $props, $wire );
		$choices = array_column( self::choices( $props, $directory ), 'label', 'value' );
		if ( self::is_array( $props ) ) {
			return implode( ', ', array_map( static fn( $item ): string => $choices[ $item ] ?? (string) $item, $value ) );
		}
		return 'switch' === $props['widget_name'] ? ( $value ? __( 'Yes', 'directorist-ai' ) : __( 'No', 'directorist-ai' ) ) : ( $choices[ $wire ] ?? $wire );
	}
}
