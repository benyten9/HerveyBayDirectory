<?php
/** Explicit public placement and supported field disclosure policy.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Bounded native disclosure adapter.

/** Admin-only is one exclusion; a verified public placement is also required. */
final class DisclosurePolicy {
	public const WIDGETS = array( 'title', 'description', 'excerpt', 'text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'time', 'url', 'color_picker', 'category', 'location', 'tag', 'address', 'zip', 'phone', 'phone2', 'fax', 'email', 'website', 'pricing', 'map' );

	public static function placements( array $native, array $layout, array $header ): array {
		$result = array();
		foreach ( (array) ( $layout['groups'] ?? array() ) as $group ) {
			if ( ! is_array( $group ) || 'general_group' !== ( $group['type'] ?? '' ) || ! self::public_definition( $group ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? array() ) as $key ) {
				$field = is_scalar( $key ) ? ( $layout['fields'][ $key ] ?? null ) : null;
				if ( ! is_array( $field ) || ! self::public_definition( $field ) ) {
					continue;
				}
				$source = $field['original_widget_key'] ?? null;
				if ( is_scalar( $source ) && isset( $native[ $source ] ) && self::public_definition( $native[ $source ] ) && ( $field['widget_name'] ?? '' ) === ( $native[ $source ]['widget_name'] ?? '' ) ) {
					$result[ $source ] = array( $group, $field );
				}
			}
		}
		foreach ( $header as $group ) {
			if ( ! is_array( $group ) || ! self::public_definition( $group ) ) {
				continue;
			}
			$placeholder = $group['placeholderKey'] ?? '';
			if ( ! in_array( $placeholder, array( 'listing-title-placeholder', 'more-widgets-placeholder' ), true ) ) {
				continue;
			}
			foreach ( (array) ( $group['selectedWidgets'] ?? array() ) as $widget ) {
				if ( ! is_array( $widget ) || ! self::public_definition( $widget ) ) {
					continue;
				}
				$name = $widget['widget_name'] ?? '';
				$name = 'price' === $name ? 'pricing' : $name;
				if ( ! in_array( $name, array( 'title', 'pricing', 'category' ), true ) || ( 'title' === $name && 'listing-title-placeholder' !== $placeholder ) ) {
					continue;
				}
				foreach ( $native as $source => $field ) {
					if ( is_array( $field ) && ( $field['widget_name'] ?? '' ) === $name && self::public_definition( $field ) ) {
						$result[ $source ] = array( $group, $widget );
					}
				}
			}
		}
		return $result;
	}

	public static function public_definition( array $field ): bool {
		foreach ( array( 'only_for_admin', 'hidden', 'hide', 'logged_in_only', 'members_only' ) as $key ) {
			if ( ! empty( $field[ $key ] ) ) {
				return false;
			}
		}
		foreach ( array( 'visibility', 'display_for', 'show_for' ) as $key ) {
			if ( isset( $field[ $key ] ) && ! in_array( $field[ $key ], array( '', 'all', 'public', 'everyone' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function condition( array $field ): ?array {
		$value = $field['conditional_logic_data'] ?? $field['options']['conditional_logic']['value'] ?? $field['options']['conditional_logic'] ?? $field['conditional_logic'] ?? null;
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) && in_array( $value['enabled'] ?? false, array( true, 1, '1', 'true' ), true ) ? $value : null;
	}

	/** Malformed or unavailable dependencies never rely on the evaluator's permissive default. */
	public static function visible( array $field, array $posted ): bool {
		if ( ! self::public_definition( $field ) ) {
			return false;
		}
		$condition = self::condition( $field );
		if ( null === $condition ) {
			// Unknown nonempty conditional data cannot establish public visibility.
			foreach ( array( 'conditional_logic_data', 'conditional_logic' ) as $key ) {
				if ( ! empty( $field[ $key ] ) && ! is_array( $field[ $key ] ) && ! is_array( json_decode( (string) $field[ $key ], true ) ) ) {
					return false;
				}
			}
			return true;
		}
		if ( ! class_exists( '\Directorist\Fields\Conditional_Logic' ) || empty( $condition['groups'] ) || ! is_array( $condition['groups'] ) ) {
			return false;
		}
		$operators = array( 'is', '==', '=', 'is not', '!=', 'not', 'contains', 'does not contain', 'greater than', '>', 'less than', '<', 'greater than or equal', '>=', 'less than or equal', '<=', 'starts with', 'ends with', 'empty', 'is empty', 'not empty', 'is not empty' );
		foreach ( $condition['groups'] as $group ) {
			if ( ! is_array( $group ) || empty( $group['conditions'] ) || ! is_array( $group['conditions'] ) ) {
				return false;
			}
			foreach ( $group['conditions'] as $rule ) {
				$key = $rule['field'] ?? null;
				if ( ! is_string( $key ) || ! array_key_exists( $key, $posted ) || ! in_array( strtolower( trim( (string) ( $rule['operator'] ?? '' ) ) ), $operators, true ) ) {
					return false;
				}
			}
		}
		return \Directorist\Fields\Conditional_Logic::should_show_field( $field, $posted );
	}
}
