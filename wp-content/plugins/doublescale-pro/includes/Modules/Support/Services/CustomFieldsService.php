<?php
/**
 * Support ticket custom field definitions and validation.
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Modules\Support
 */

namespace DoubleScale\Pro\Modules\Support\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\Settings\Settings;
use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;

/**
 * CustomFieldsService class.
 */
class CustomFieldsService {

	/**
	 * Built-in field types (integration plugins may add more via filter).
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date' );

	/**
	 * Allowed scopes.
	 *
	 * @var string[]
	 */
	public const SCOPES = array( 'admin', 'portal', 'both' );

	/**
	 * Condition sources for conditional logic.
	 *
	 * @var string[]
	 */
	public const CONDITION_SOURCES = array(
		'ticket_title',
		'ticket_content',
		'ticket_priority',
		'custom_field',
	);

	/**
	 * Condition operators.
	 *
	 * @var string[]
	 */
	public const CONDITION_OPERATORS = array(
		'contains',
		'not_contains',
		'equals',
		'not_equals',
		'starts_with',
		'ends_with',
	);

	/**
	 * Operators for discrete choice sources (priority, select/radio/checkbox values).
	 *
	 * @var string[]
	 */
	public const CHOICE_CONDITION_OPERATORS = array(
		'equals',
		'not_equals',
	);

	/**
	 * Allowed operators for a conditional-logic source.
	 *
	 * @param string               $source          Condition source slug.
	 * @param array<string, mixed> $referenced_field Optional custom field definition when source is custom_field.
	 * @return string[]
	 */
	public function get_condition_operators_for_source( string $source, array $referenced_field = array() ): array {
		if ( 'ticket_priority' === $source ) {
			return self::CHOICE_CONDITION_OPERATORS;
		}

		if ( 'custom_field' === $source && $this->field_uses_choice_operators( $referenced_field ) ) {
			return self::CHOICE_CONDITION_OPERATORS;
		}

		return self::CONDITION_OPERATORS;
	}

	/**
	 * @param array<string, mixed> $field Custom field definition.
	 * @return bool
	 */
	private function field_uses_choice_operators( array $field ): bool {
		if ( empty( $field ) ) {
			return false;
		}
		$type = (string) ( $field['type'] ?? '' );
		if ( ! in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
			return false;
		}
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		return ! empty( $options );
	}

	/**
	 * Registered field types for the settings UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_field_types(): array {
		$types = array(
			'text'     => array(
				'label'       => __( 'Single Line Text', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => false,
			),
			'textarea' => array(
				'label'       => __( 'Multi-Line Text', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => false,
			),
			'number'   => array(
				'label'       => __( 'Numeric Field', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => false,
			),
			'select'   => array(
				'label'       => __( 'Select Choice', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => true,
			),
			'radio'    => array(
				'label'       => __( 'Radio Choice', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => true,
			),
			'checkbox' => array(
				'label'       => __( 'Checkboxes', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => true,
			),
			'date'     => array(
				'label'       => __( 'Date', 'doublescale' ),
				'group'       => 'standard',
				'has_options' => false,
			),
		);

		/**
		 * Register additional support ticket custom field types (e.g. WooCommerce products).
		 *
		 * @param array<string, array<string, mixed>> $types Field type metadata keyed by type slug.
		 */
		return (array) apply_filters( 'doublescale_support_custom_field_types', $types );
	}

	/**
	 * Read field definitions, optionally filtered by scope.
	 *
	 * @param string $scope admin|portal.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_definitions( string $scope = 'admin' ): array {
		$support  = Settings::get( 'support', array() );
		$defs     = isset( $support['custom_fields'] ) && is_array( $support['custom_fields'] ) ? $support['custom_fields'] : array();
		$filtered = array();

		foreach ( $defs as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$field_scope = isset( $field['scope'] ) ? (string) $field['scope'] : 'both';
			if ( 'both' !== $field_scope && $field_scope !== $scope ) {
				continue;
			}
			$filtered[] = $this->normalize_definition( $field );
		}

		/**
		 * Filter support ticket custom field definitions.
		 *
		 * @param array  $filtered Definitions for the requested scope.
		 * @param string $scope    Requested scope.
		 */
		return (array) apply_filters( 'doublescale_support_custom_fields', $filtered, $scope );
	}

	/**
	 * Filter definitions to those visible for the given ticket/form context.
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions.
	 * @param array<string, mixed>             $context     ticket_title, ticket_content, etc.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter_visible( array $definitions, array $context ): array {
		$sorted  = $this->sort_fields_by_dependency( $definitions );
		$visible = array();
		$changed = true;
		$guard   = 0;

		while ( $changed && $guard < count( $sorted ) + 1 ) {
			++$guard;
			$changed = false;
			$next    = array();
			$visible_keys = array_flip( array_map( static fn( $f ) => (string) $f['key'], $visible ) );

			foreach ( $sorted as $field ) {
				if ( ! $this->is_field_visible( $field, $context ) ) {
					continue;
				}
				$deps_met = true;
				foreach ( $this->get_custom_field_dependencies( $field ) as $dep ) {
					if ( ! isset( $visible_keys[ $dep ] ) ) {
						$deps_met = false;
						break;
					}
				}
				if ( ! $deps_met ) {
					continue;
				}
				$next[] = $field;
			}

			$next_keys = array_map( static fn( $f ) => (string) $f['key'], $next );
			sort( $next_keys );
			$prev_keys = array_map( static fn( $f ) => (string) $f['key'], $visible );
			sort( $prev_keys );

			if ( $next_keys !== $prev_keys ) {
				$changed = true;
				$visible = $next;
			}
		}

		return $visible;
	}

	/**
	 * @param array<string, mixed> $field Field definition.
	 * @return string[]
	 */
	public function get_custom_field_dependencies( array $field ): array {
		$logic = $this->get_field_conditional_logic( $field );
		if ( empty( $logic['enabled'] ) ) {
			return array();
		}
		$deps = array();
		foreach ( $this->collect_conditions( $logic ) as $row ) {
			if ( ! is_array( $row ) || 'custom_field' !== ( $row['source'] ?? '' ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['field_key'] ?? '' ) );
			if ( '' !== $key ) {
				$deps[] = $key;
			}
		}
		return array_values( array_unique( $deps ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $definitions Field definitions.
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_fields_by_dependency( array $definitions ): array {
		$by_key  = array();
		foreach ( $definitions as $field ) {
			$by_key[ (string) $field['key'] ] = $field;
		}
		$sorted  = array();
		$visited = array();

		$visit = function ( $field ) use ( &$visit, &$sorted, &$visited, $by_key ) {
			$key = (string) $field['key'];
			if ( isset( $visited[ $key ] ) ) {
				return;
			}
			$visited[ $key ] = true;
			foreach ( $this->get_custom_field_dependencies( $field ) as $dep ) {
				if ( isset( $by_key[ $dep ] ) ) {
					$visit( $by_key[ $dep ] );
				}
			}
			$sorted[] = $field;
		};

		foreach ( $definitions as $field ) {
			$visit( $field );
		}

		return $sorted;
	}

	/**
	 * Whether a field passes its conditional logic rules.
	 *
	 * @param array<string, mixed> $field   Normalized field definition.
	 * @param array<string, mixed> $context Evaluation context.
	 * @return bool
	 */
	public function is_field_visible( array $field, array $context ): bool {
		$logic = $this->get_field_conditional_logic( $field );

		if ( empty( $logic['enabled'] ) ) {
			return true;
		}

		$groups = $this->get_condition_groups( $logic );
		if ( empty( $groups ) ) {
			return true;
		}

		foreach ( $groups as $group ) {
			if ( $this->evaluate_condition_group( $group, $context ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate submitted values against definitions for a scope.
	 *
	 * @param array<string, mixed> $values  Raw values keyed by field key.
	 * @param string               $scope   admin|portal.
	 * @param array<string, mixed> $context Optional form context for conditional logic.
	 * @return array<string, mixed>|WP_Error Sanitized values.
	 */
	public function validate( array $values, string $scope = 'admin', array $context = array() ) {
		$definitions     = $this->get_definitions( $scope );
		$context         = $this->normalize_context( $context, $values );
		$visible         = $this->filter_visible( $definitions, $context );
		$visible_by_key  = array();
		foreach ( $visible as $field ) {
			$visible_by_key[ (string) $field['key'] ] = $field;
		}

		$sanitized = array();

		foreach ( $visible as $field ) {
			$key      = (string) $field['key'];
			$required = ! empty( $field['required'] );
			$has_key  = array_key_exists( $key, $values );
			$raw      = $has_key ? $values[ $key ] : null;

			if ( $required && ( ! $has_key || $this->is_empty_value( $raw, (string) $field['type'] ) ) ) {
				return new WP_Error(
					'custom_field_required',
					sprintf(
						/* translators: %s: field label */
						__( 'The field "%s" is required.', 'doublescale' ),
						$this->get_display_label( $field, $scope )
					),
					array( 'status' => 400 )
				);
			}

			if ( ! $has_key || $this->is_empty_value( $raw, (string) $field['type'] ) ) {
				continue;
			}

			$normalized = $this->normalize_value( $raw, $field );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}
			$sanitized[ $key ] = $normalized;
		}

		return $sanitized;
	}

	/**
	 * Merge validated visible values with existing data, dropping hidden fields.
	 *
	 * @param array<string, mixed> $values   Submitted values.
	 * @param string               $scope    admin|portal.
	 * @param array<string, mixed> $context  Form context.
	 * @param array<string, mixed> $existing Stored custom_data.
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare_for_save( array $values, string $scope, array $context, array $existing = array() ) {
		$definitions = $this->get_definitions( $scope );
		$context     = $this->normalize_context( $context, array_merge( $existing, $values ) );
		$visible     = $this->filter_visible( $definitions, $context );
		$visible_keys = array();
		foreach ( $visible as $field ) {
			$visible_keys[ (string) $field['key'] ] = true;
		}

		$validated = $this->validate( $values, $scope, $context );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$result = array();
		foreach ( $existing as $key => $val ) {
			$key = (string) $key;
			if ( isset( $visible_keys[ $key ] ) ) {
				$result[ $key ] = $val;
			}
		}
		foreach ( $validated as $key => $val ) {
			$result[ (string) $key ] = $val;
		}
		foreach ( $visible as $field ) {
			$key = (string) $field['key'];
			if ( array_key_exists( $key, $values ) && $this->is_empty_value( $values[ $key ], (string) $field['type'] ) ) {
				unset( $result[ $key ] );
			}
		}

		return $result;
	}

	/**
	 * Format stored values for display alongside definitions.
	 *
	 * @param array<string, mixed> $values Stored custom_data.
	 * @param string               $scope  admin|portal label scope.
	 * @return array<string, mixed>
	 */
	public function render( array $values, string $scope = 'admin' ): array {
		$definitions = $this->get_definitions( 'admin' );
		$rendered    = array();

		foreach ( $definitions as $field ) {
			$key = (string) $field['key'];
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$rendered[ $key ] = array(
				'label' => $this->get_display_label( $field, $scope ),
				'type'  => (string) $field['type'],
				'value' => $this->format_display_value( $values[ $key ], $field ),
			);
		}

		return $rendered;
	}

	/**
	 * Persist definitions (admin settings UI).
	 *
	 * @param array<int, array<string, mixed>> $definitions Field definitions.
	 * @return bool|WP_Error
	 */
	public function save_definitions( array $definitions ) {
		if ( ! Permissions::can_access_support_settings() ) {
			return new WP_Error( 'not_allowed', __( 'You do not have permission to manage support settings.', 'doublescale' ), array( 'status' => 403 ) );
		}

		$prepared = array();
		$seen_keys = array();
		foreach ( $definitions as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = $this->resolve_definition_key( $field, $seen_keys );
			$field['key']     = $key;
			$seen_keys[ $key ] = true;
			$prepared[]       = $field;
		}

		$definitions_by_key = array();
		foreach ( $prepared as $field ) {
			$definitions_by_key[ (string) $field['key'] ] = $field;
		}

		$normalized = array();
		foreach ( $prepared as $field ) {
			$row = $this->normalize_definition( $field, $definitions_by_key );
			$key = (string) $row['key'];
			if ( '' === $key ) {
				continue;
			}
			$normalized[] = $row;
		}

		$support = Settings::get( 'support', array() );
		if ( ! is_array( $support ) ) {
			$support = array();
		}
		$support['custom_fields'] = $normalized;
		return Settings::update( 'support', $support );
	}

	/**
	 * Whether a sanitized key is usable (has at least one alphanumeric char).
	 *
	 * Underscore-only keys from non-Latin labels (e.g. "رقم الطلب" → "_")
	 * are treated as unusable so we can generate a stable fallback.
	 *
	 * @param string $key Sanitized key.
	 * @return bool
	 */
	private function is_usable_field_key( string $key ): bool {
		return '' !== $key && 1 === preg_match( '/[a-z0-9]/', $key );
	}

	/**
	 * Resolve a unique field key for persistence.
	 *
	 * Non-Latin public labels cannot be slugified via sanitize_key(); without a
	 * fallback those definitions were silently dropped on save.
	 *
	 * @param array<string, mixed> $field     Raw field definition.
	 * @param array<string, bool>  $seen_keys Keys already claimed in this save.
	 * @return string
	 */
	private function resolve_definition_key( array $field, array $seen_keys = array() ): string {
		$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
		if ( ! $this->is_usable_field_key( $key ) ) {
			$public_label = (string) ( $field['public_label'] ?? $field['label'] ?? '' );
			if ( '' !== $public_label ) {
				$key = sanitize_key( str_replace( ' ', '_', strtolower( $public_label ) ) );
			}
		}
		if ( ! $this->is_usable_field_key( $key ) ) {
			$key = 'field_' . strtolower( wp_generate_password( 8, false, false ) );
		}

		$base = $key;
		$i    = 2;
		while ( isset( $seen_keys[ $key ] ) ) {
			$key = $base . '_' . $i;
			++$i;
		}

		return $key;
	}

	/**
	 * Display label for a field in the given scope.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param string               $scope admin|portal.
	 * @return string
	 */
	public function get_display_label( array $field, string $scope = 'admin' ): string {
		$public = (string) ( $field['public_label'] ?? $field['label'] ?? $field['key'] ?? '' );
		if ( 'portal' === $scope ) {
			return $public;
		}
		$admin = trim( (string) ( $field['admin_label'] ?? '' ) );
		return '' !== $admin ? $admin : $public;
	}

	/**
	 * @param array<string, mixed>              $field              Raw definition.
	 * @param array<string, array<string,mixed>> $definitions_by_key Sibling definitions for custom_field refs.
	 * @return array<string, mixed>
	 */
	private function normalize_definition( array $field, array $definitions_by_key = array() ): array {
		$types_meta = $this->get_field_types();
		$type       = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		if ( ! array_key_exists( $type, $types_meta ) && ! in_array( $type, self::TYPES, true ) ) {
			$type = 'text';
		}

		$scope = isset( $field['scope'] ) ? (string) $field['scope'] : 'both';
		if ( ! empty( $field['agent_only'] ) ) {
			$scope = 'admin';
		}
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			$scope = 'both';
		}

		$public_label = sanitize_text_field(
			(string) ( $field['public_label'] ?? $field['label'] ?? $field['key'] ?? '' )
		);
		$admin_label  = sanitize_text_field( (string) ( $field['admin_label'] ?? '' ) );
		$key          = sanitize_key( (string) ( $field['key'] ?? '' ) );
		if ( ! $this->is_usable_field_key( $key ) && '' !== $public_label ) {
			$key = sanitize_key( str_replace( ' ', '_', strtolower( $public_label ) ) );
		}
		if ( ! $this->is_usable_field_key( $key ) ) {
			$key = '';
		}

		$options = array();
		if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
			foreach ( $field['options'] as $option ) {
				$option = sanitize_text_field( (string) $option );
				if ( '' !== $option ) {
					$options[] = $option;
				}
			}
		}

		$conditional = $this->normalize_conditional_logic(
			$field['conditional_logic'] ?? array(),
			array()
		);

		return array(
			'key'               => $key,
			'public_label'      => $public_label,
			'admin_label'       => $admin_label,
			'placeholder'       => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
			'type'              => $type,
			'options'           => $options,
			'required'          => ! empty( $field['required'] ),
			'scope'             => $scope,
			'agent_only'        => 'admin' === $scope,
			'conditional_logic' => $conditional,
		);
	}

	/**
	 * @param mixed                              $logic              Raw conditional logic payload.
	 * @param array<string, array<string,mixed>> $definitions_by_key Sibling definitions for operator coercion.
	 * @return array<string, mixed>
	 */
	private function normalize_conditional_logic( $logic, array $definitions_by_key = array() ): array {
		if ( ! is_array( $logic ) ) {
			return array(
				'enabled'    => false,
				'match'      => 'all',
				'conditions' => array(),
				'groups'     => array(),
			);
		}

		$groups = array();
		if ( isset( $logic['groups'] ) && is_array( $logic['groups'] ) ) {
			foreach ( $logic['groups'] as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				$normalized_group = $this->normalize_condition_rows( $group, $definitions_by_key );
				if ( ! empty( $normalized_group ) ) {
					$groups[] = $normalized_group;
				}
			}
		}

		if ( empty( $groups ) && isset( $logic['conditions'] ) && is_array( $logic['conditions'] ) ) {
			$conditions = $this->normalize_condition_rows( $logic['conditions'], $definitions_by_key );
			if ( ! empty( $conditions ) ) {
				$groups = $this->legacy_conditions_to_groups( $conditions, $this->resolve_match_mode( $logic ) );
			}
		}

		return array(
			'enabled'    => ! empty( $logic['enabled'] ),
			'match'      => $this->resolve_match_mode( $logic ),
			'conditions' => $this->flatten_condition_groups( $groups ),
			'groups'     => $groups,
		);
	}

	/**
	 * @param array<int, mixed>                  $rows               Raw condition rows.
	 * @param array<string, array<string,mixed>> $definitions_by_key Sibling definitions for operator coercion.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_condition_rows( array $rows, array $definitions_by_key = array() ): array {
		$conditions = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source = isset( $row['source'] ) ? (string) $row['source'] : '';
			if ( ! in_array( $source, self::CONDITION_SOURCES, true ) ) {
				continue;
			}
			$referenced = array();
			if ( 'custom_field' === $source ) {
				$ref_key = sanitize_key( (string) ( $row['field_key'] ?? '' ) );
				if ( '' !== $ref_key && isset( $definitions_by_key[ $ref_key ] ) ) {
					$referenced = $definitions_by_key[ $ref_key ];
				}
			}
			$allowed  = $this->get_condition_operators_for_source( $source, $referenced );
			$operator = isset( $row['operator'] ) ? (string) $row['operator'] : 'equals';
			if ( ! in_array( $operator, $allowed, true ) ) {
				$operator = $allowed[0];
			}
			$condition = array(
				'source'   => $source,
				'operator' => $operator,
				'value'    => sanitize_text_field( (string) ( $row['value'] ?? '' ) ),
			);
			if ( 'custom_field' === $source ) {
				$condition['field_key'] = sanitize_key( (string) ( $row['field_key'] ?? '' ) );
			}
			$conditions[] = $condition;
		}

		return $conditions;
	}

	/**
	 * @param array<string, mixed> $logic Conditional logic payload.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function get_condition_groups( array $logic ): array {
		if ( isset( $logic['groups'] ) && is_array( $logic['groups'] ) && ! empty( $logic['groups'] ) ) {
			$groups = array();
			foreach ( $logic['groups'] as $group ) {
				if ( ! is_array( $group ) || empty( $group ) ) {
					continue;
				}
				$groups[] = $group;
			}
			return $groups;
		}

		$conditions = isset( $logic['conditions'] ) && is_array( $logic['conditions'] ) ? $logic['conditions'] : array();
		if ( empty( $conditions ) ) {
			return array();
		}

		return $this->legacy_conditions_to_groups( $conditions, $this->resolve_match_mode( $logic ) );
	}

	/**
	 * @param array<string, mixed> $logic Conditional logic payload.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect_conditions( array $logic ): array {
		return $this->flatten_condition_groups( $this->get_condition_groups( $logic ) );
	}

	/**
	 * @param array<string, mixed> $field Field definition.
	 * @return array<string, mixed>
	 */
	private function get_field_conditional_logic( array $field ): array {
		return isset( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] )
			? $field['conditional_logic']
			: array();
	}

	/**
	 * @param array<string, mixed> $logic Conditional logic payload.
	 * @return string all|any
	 */
	private function resolve_match_mode( array $logic ): string {
		return isset( $logic['match'] ) && 'any' === $logic['match'] ? 'any' : 'all';
	}

	/**
	 * Convert legacy flat conditions + match mode into OR groups of AND rows.
	 *
	 * @param array<int, array<string, mixed>> $conditions Normalized condition rows.
	 * @param string                           $match      all|any.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private function legacy_conditions_to_groups( array $conditions, string $match ): array {
		if ( empty( $conditions ) ) {
			return array();
		}

		if ( 'any' === $match ) {
			$groups = array();
			foreach ( $conditions as $condition ) {
				if ( is_array( $condition ) ) {
					$groups[] = array( $condition );
				}
			}
			return $groups;
		}

		return array( $conditions );
	}

	/**
	 * @param array<int, array<int, array<string, mixed>>> $groups OR groups.
	 * @return array<int, array<string, mixed>>
	 */
	private function flatten_condition_groups( array $groups ): array {
		$flat = array();
		foreach ( $groups as $group ) {
			foreach ( $group as $condition ) {
				if ( is_array( $condition ) ) {
					$flat[] = $condition;
				}
			}
		}
		return $flat;
	}

	/**
	 * @param array<int, array<string, mixed>> $group   AND group.
	 * @param array<string, mixed>             $context Evaluation context.
	 * @return bool
	 */
	private function evaluate_condition_group( array $group, array $context ): bool {
		if ( empty( $group ) ) {
			return true;
		}

		foreach ( $group as $condition ) {
			if ( ! is_array( $condition ) || ! $this->evaluate_condition( $condition, $context ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $context Form/ticket context.
	 * @param array<string, mixed> $values  Submitted custom field values.
	 * @return array<string, mixed>
	 */
	private function normalize_context( array $context, array $values ): array {
		$context['custom_data'] = isset( $context['custom_data'] ) && is_array( $context['custom_data'] )
			? $context['custom_data']
			: $values;
		return $context;
	}

	/**
	 * @param array<string, mixed> $condition Condition row.
	 * @param array<string, mixed> $context   Evaluation context.
	 * @return bool
	 */
	private function evaluate_condition( array $condition, array $context ): bool {
		$source   = (string) ( $condition['source'] ?? '' );
		$operator = (string) ( $condition['operator'] ?? 'equals' );
		$expected = (string) ( $condition['value'] ?? '' );

		if ( 'custom_field' === $source && '' === sanitize_key( (string) ( $condition['field_key'] ?? '' ) ) ) {
			return false;
		}

		$actual = '';

		switch ( $source ) {
			case 'ticket_title':
				$actual = (string) ( $context['ticket_title'] ?? '' );
				break;
			case 'ticket_content':
				$actual = (string) ( $context['ticket_content'] ?? '' );
				break;
			case 'ticket_priority':
				$actual = (string) ( $context['ticket_priority'] ?? '' );
				break;
			case 'custom_field':
				$key = sanitize_key( (string) ( $condition['field_key'] ?? '' ) );
				$raw = $this->read_custom_data_value( $context['custom_data'] ?? array(), $key );
				if ( is_array( $raw ) ) {
					$actual = implode( ',', array_map( 'strval', $raw ) );
				} else {
					$actual = (string) $raw;
				}
				break;
		}

		return $this->compare_values( $actual, $expected, $operator );
	}

	/**
	 * @param array<string, mixed> $custom_data Stored/submitted values.
	 * @param string               $key         Field key.
	 * @return mixed
	 */
	private function read_custom_data_value( array $custom_data, string $key ) {
		if ( '' === $key || empty( $custom_data ) ) {
			return '';
		}
		if ( array_key_exists( $key, $custom_data ) ) {
			return $custom_data[ $key ];
		}
		foreach ( $custom_data as $stored_key => $value ) {
			if ( strtolower( (string) $stored_key ) === strtolower( $key ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @param string $actual   Left-hand value.
	 * @param string $expected Right-hand value.
	 * @param string $operator Comparison operator.
	 * @return bool
	 */
	private function compare_values( string $actual, string $expected, string $operator ): bool {
		$actual_lc   = strtolower( $actual );
		$expected_lc = strtolower( $expected );

		switch ( $operator ) {
			case 'contains':
				return str_contains( $actual_lc, $expected_lc );
			case 'not_contains':
				return ! str_contains( $actual_lc, $expected_lc );
			case 'equals':
				return $actual_lc === $expected_lc;
			case 'not_equals':
				return $actual_lc !== $expected_lc;
			case 'starts_with':
				return str_starts_with( $actual_lc, $expected_lc );
			case 'ends_with':
				return str_ends_with( $actual_lc, $expected_lc );
			default:
				return false;
		}
	}

	/**
	 * @param mixed                $raw   Submitted value.
	 * @param array<string, mixed> $field Field definition.
	 * @return mixed|WP_Error
	 */
	private function normalize_value( $raw, array $field ) {
		$type = (string) $field['type'];
		switch ( $type ) {
			case 'checkbox':
				if ( ! is_array( $raw ) ) {
					$raw = array( $raw );
				}
				$allowed = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$values  = array();
				foreach ( $raw as $item ) {
					$item = sanitize_text_field( (string) $item );
					if ( in_array( $item, $allowed, true ) ) {
						$values[] = $item;
					}
				}
				return array_values( array_unique( $values ) );
			case 'select':
			case 'radio':
				$value   = sanitize_text_field( (string) $raw );
				$allowed = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				if ( ! in_array( $value, $allowed, true ) ) {
					return new WP_Error(
						'custom_field_invalid',
						sprintf(
							/* translators: %s: field label */
							__( 'Invalid value for "%s".', 'doublescale' ),
							$this->get_display_label( $field )
						),
						array( 'status' => 400 )
					);
				}
				return $value;
			case 'number':
				if ( ! is_numeric( $raw ) ) {
					return new WP_Error(
						'custom_field_invalid',
						sprintf(
							/* translators: %s: field label */
							__( 'Invalid number for "%s".', 'doublescale' ),
							$this->get_display_label( $field )
						),
						array( 'status' => 400 )
					);
				}
				return (string) $raw;
			case 'date':
				$value = sanitize_text_field( (string) $raw );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
					return new WP_Error(
						'custom_field_invalid',
						sprintf(
							/* translators: %s: field label */
							__( 'Invalid date for "%s".', 'doublescale' ),
							$this->get_display_label( $field )
						),
						array( 'status' => 400 )
					);
				}
				return $value;
			case 'textarea':
				return sanitize_textarea_field( (string) $raw );
			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * @param mixed  $value Raw value.
	 * @param string $type  Field type.
	 * @return bool
	 */
	private function is_empty_value( $value, string $type ): bool {
		if ( 'checkbox' === $type ) {
			return ! is_array( $value ) || empty( $value );
		}
		if ( null === $value ) {
			return true;
		}
		return '' === trim( (string) $value );
	}

	/**
	 * @param mixed                $value Stored value.
	 * @param array<string, mixed> $field Field definition.
	 * @return string|array<int, string>
	 */
	private function format_display_value( $value, array $field ) {
		if ( 'checkbox' === $field['type'] && is_array( $value ) ) {
			return array_map( 'strval', $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
