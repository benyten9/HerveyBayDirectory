<?php
/**
 * Canonical public native-control hints; never new authoring authority.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Exact external JSON Schema property names.

use DirectoristAIPro\Builder\{BuilderAuthoringContract, BuilderDraftAdapterRegistry, BuilderPlanningPolicyRegistry};
use DirectoristAIPro\Validation\{ContractException, ContractValidator, JsonDecoder, SchemaRegistry};

/** Reads installed metadata without graph reads, provider requests or writes. */
final class BuilderFactoryControlDiagnostics {

	/**
	 * Resolve exact public NEW controls from the installed component policies.
	 *
	 * @param object $document Bound native document.
	 * @param object $scope Bound native scope.
	 * @param array  $native_ids Requested component identities, matched to native policies.
	 * @return array Canonical identities and their exact public creation schemas.
	 */
	public static function contracts( object $document, object $scope, array $native_ids, array $requested_paths = array() ): array {
		$native_ids = array_values( array_unique( array_filter( $native_ids, 'is_string' ) ) );
		if ( ! in_array( $document->builder ?? null, array( 'gutenberg', 'elementor' ), true ) || count( $native_ids ) > 20 ) {
			return array();
		}
		$adapters = new BuilderDraftAdapterRegistry();
		do_action( 'directorist_ai_pro_register_builder_draft_adapters', $adapters );
		$adapters->freeze();
		$adapter = $adapters->adapter( $document->builder );
		if ( 'elementor' === $document->builder ) {
			if ( null === $adapter || ! is_callable( array( $adapter->compiler, 'creation_control_schema_for_component' ) ) ) { return array(); }
			$result = array();
			foreach ( $native_ids as $native_id ) {
				$paths = $requested_paths[ $native_id ] ?? array();
				if ( ! is_array( $paths ) || ! $paths || count( $paths ) > 200 ) { continue; }
				$identity = (object) array( 'provider_id' => 'directorist-elementor', 'native_id' => $native_id );
				$native = $adapter->compiler->creation_control_schema_for_component( $document, $scope, $identity, $paths );
				if ( ( $native->provider_id ?? null ) !== $identity->provider_id || ( $native->native_id ?? null ) !== $native_id || ! is_string( $native->source_hash ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $native->source_hash ) ) { continue; }
				$controls = array();
				foreach ( $native->controls ?? array() as $control ) {
					if ( false === ( $control->private ?? null ) && true !== ( $control->read_only ?? false ) && false !== ( $control->writable ?? true ) && empty( $control->field_types ) && in_array( $control->path ?? null, $paths, true ) && ( $control->value_schema ?? null ) instanceof \stdClass && self::public_schema( $control->value_schema ) ) {
						$controls[ $control->path ] = $control;
					}
				}
				if ( $controls ) { $result[ $native_id ] = (object) array( 'component' => $identity, 'controls' => $controls, 'source_hash' => $native->source_hash ); }
			}
			return $result;
		}
		if ( null === $adapter || ! is_callable( array( $adapter->compiler, 'creation_control_schema_for_component' ) ) ) {
			return array();
		}
		$identities = BuilderAuthoringContract::insertion_identities_for_document( $adapter->compiler, $document ) ?? array();
		$policies   = new BuilderPlanningPolicyRegistry();
		do_action( 'directorist_ai_pro_register_builder_planning_policies', $policies );
		$policies->freeze();
		$result = array();
		foreach ( $native_ids as $native_id ) {
			if ( ! is_string( $native_id ) || ! isset( $identities[ 'directorist-gb-integration|' . $native_id ] ) ) {
				continue;
			}
			$policy = $policies->component( 'directorist-gb-integration', $native_id );
			$paths  = $policy->insert_attributes_schema->properties->initial_controls->items->properties->control_path->enum ?? array();
			$paths  = array_values( array_filter( $paths, static fn( $path ): bool => is_string( $path ) && str_starts_with( $path, 'native_initial_style.' ) ) );
			if ( ! $paths ) {
				continue;
			}
			$identity = (object) array(
				'provider_id' => $policy->provider_id,
				'native_id'   => $policy->native_id,
			);
			$native   = $adapter->compiler->creation_control_schema_for_component( $document, $scope, $identity, $paths );
			$controls = array();
			foreach ( $native->controls ?? array() as $control ) {
				if ( false === ( $control->private ?? null ) && empty( $control->field_types ) && in_array( $control->path ?? null, $paths, true ) && ( $control->value_schema ?? null ) instanceof \stdClass ) {
					$controls[ $control->path ] = $control;
				}
			}
			$result[ $policy->native_id ] = (object) array(
				'component' => $identity,
				'controls'  => $controls,
			);
		}
		return $result;
	}

	/** Exact admitted/current identity for optional schema-only repair feedback. */
	public static function matches_factory( object $input, object $authority, object $snapshot, array $identity, array $capture, string $catalog_revision ): bool {
		try {
			$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
			BuilderFactoryTransport::assert_snapshot( $snapshot );
			$bound = \DirectoristAIPro\Builder\BuilderFactoryAuthority::from_binding( $snapshot->binding, $input );
			if ( $canonical->encode( $bound ) !== $canonical->encode( $authority ) || ! hash_equals( $authority->catalog_revision, $catalog_revision ) ) { return false; }
			foreach ( array( 'owner_ref', 'site_id', 'run_id', 'conversation_id' ) as $key ) {
				if ( ! array_key_exists( $key, $identity ) || ( $authority->{$key} ?? null ) !== $identity[ $key ] ) { return false; }
			}
			$expected = array( 'capture_id' => $capture['capture_id'] ?? null, 'captured_snapshot_hash' => $capture['captured_snapshot_hash'] ?? null, 'original_document' => $capture['document'] ?? null, 'working_document' => $capture['working_document'] ?? null, 'output_mode' => $capture['output_mode'] ?? null, 'signature_hash' => hash( 'sha256', $capture['signature'] ?? '' ) );
			return $canonical->encode( (object) $expected ) === $canonical->encode( $authority->capture );
		} catch ( \Throwable $error ) { return false; }
	}

	/** Only exact paths of failed generated targets in the admitted slot schema. */
	public static function requested_paths( object $input, object $snapshot ): array {
		if ( 'elementor' !== ( $input->document->builder ?? null ) ) { return array(); }
		BuilderFactoryTransport::assert_snapshot( $snapshot );
		$result = array();
		foreach ( $snapshot->binding->slots as $slot ) {
			$declared_nodes = array_values( array_filter( $snapshot->binding->template->first_loop->subtree->nodes, static fn( object $node ): bool => $node->client_id === $slot->client_id ) );
			if ( 1 !== count( $declared_nodes ) ) { continue; }
			$declared = $declared_nodes[0];
			$registry = new SchemaRegistry();
			$registry->register( 'feedback.slot', json_encode( $slot->attributes_schema, JSON_THROW_ON_ERROR ) );
			$schema = $registry->get( 'feedback.slot' );
			foreach ( $input->changes ?? array() as $change ) {
				foreach ( $change->subtree->nodes ?? array() as $node ) {
					if ( ( $node->client_id ?? null ) !== $slot->client_id || ( $node->provider_id ?? null ) !== $declared->provider_id || ( $node->native_id ?? null ) !== $declared->native_id ) { continue; }
					foreach ( $node->attributes->initial_component_controls ?? array() as $group ) {
						foreach ( $schema->properties->initial_component_controls->items->oneOf ?? array() as $target ) {
							$type = $target->properties->native_id ?? null;
							if ( ! $type instanceof \stdClass || ! in_array( $group->native_id ?? null, $type->enum ?? ( isset( $type->const ) ? array( $type->const ) : array() ), true ) ) { continue; }
							$path_schema = self::path_schema( $target->properties->controls->items ?? null );
							if ( null === $path_schema ) { continue; }
							$paths = new SchemaRegistry();
							$paths->register( 'feedback.path', json_encode( $path_schema, JSON_THROW_ON_ERROR ) );
							foreach ( $group->controls ?? array() as $edit ) {
								if ( ! is_string( $edit->control_path ?? null ) ) { continue; }
								try { ( new ContractValidator( $paths ) )->validate( $edit->control_path, 'feedback.path' ); }
								catch ( ContractException $error ) { continue; }
								$result[ $group->native_id ][] = $edit->control_path;
								// A declared standalone control may have been misplaced inside
								// a native object group. Resolve its exact same-target schema,
								// never an inferred alias or an undeclared submitted property.
								if ( str_starts_with( $edit->control_path, 'native_controls.' ) && ( $edit->value ?? null ) instanceof \stdClass ) {
									foreach ( array_keys( get_object_vars( $edit->value ) ) as $member ) {
										try { ( new ContractValidator( $paths ) )->validate( $member, 'feedback.path' ); }
										catch ( ContractException $error ) { continue; }
										$result[ $group->native_id ][] = $member;
									}
								}
							}
						}
					}
				}
			}
		}
		foreach ( $result as &$paths ) { $paths = array_values( array_unique( $paths ) ); }
		return $result;
	}

	/** Project only declared path constraints, never value schemas or annotations. */
	private static function path_schema( $item, bool $finite_required = true ): ?object {
		if ( ! $item instanceof \stdClass ) { return null; }
		$parts = array();
		if ( ( $item->properties->control_path ?? null ) instanceof \stdClass ) {
			$path = $item->properties->control_path;
			$parts[] = $path;
		}
		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $operator ) {
			$children = array();
			foreach ( $item->{$operator} ?? array() as $child ) {
				$projected = self::path_schema( $child, false );
				if ( null !== $projected ) { $children[] = $projected; }
				elseif ( 'allOf' !== $operator ) { return null; }
			}
			if ( $children ) { $parts[] = (object) array( $operator => $children ); }
		}
		if ( ! $parts ) { return null; }
		$projected = count( $parts ) === 1 ? $parts[0] : (object) array( 'allOf' => $parts );
		return ! $finite_required || self::finite_paths( $projected ) ? $projected : null;
	}

	private static function finite_paths( object $schema ): bool {
		if ( isset( $schema->enum ) || isset( $schema->const ) ) { return true; }
		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $operator ) {
			foreach ( $schema->{$operator} ?? array() as $child ) {
				if ( $child instanceof \stdClass && self::finite_paths( $child ) ) { return true; }
			}
		}
		return false;
	}

	/** Deny protected schema branches instead of exposing a partial native group. */
	private static function public_schema( object $schema ): bool {
		if ( true === ( $schema->readOnly ?? false ) || true === ( $schema->writeOnly ?? false ) || true === ( $schema->private ?? false ) || false === ( $schema->writable ?? true ) ) { return false; }
		foreach ( array( 'properties', 'patternProperties', 'definitions', '$defs' ) as $map ) {
			foreach ( $schema->{$map} ?? array() as $child ) { if ( $child instanceof \stdClass && ! self::public_schema( $child ) ) { return false; } }
		}
		foreach ( array( 'allOf', 'oneOf', 'anyOf' ) as $group ) {
			foreach ( $schema->{$group} ?? array() as $child ) { if ( $child instanceof \stdClass && ! self::public_schema( $child ) ) { return false; } }
		}
		foreach ( array( 'items', 'additionalProperties', 'not', 'if', 'then', 'else' ) as $key ) {
			if ( ( $schema->{$key} ?? null ) instanceof \stdClass && ! self::public_schema( $schema->{$key} ) ) { return false; }
		}
		return true;
	}

	/**
	 * Find component identities in the unchanged factory's declared target schema.
	 *
	 * @param array $slots Server-bound factory slot descriptors.
	 * @return array Native identities declared by those exact schemas.
	 */
	public static function component_ids( array $slots ): array {
		$ids = array();
		foreach ( $slots as $slot ) {
			$registry = new SchemaRegistry();
			$registry->register( 'native.slot', json_encode( $slot->attributes_schema, JSON_THROW_ON_ERROR ) );
			$schema = $registry->get( 'native.slot' );

			foreach ( $schema->properties->initial_component_controls->items->oneOf ?? array() as $target ) {
				$type = $target->properties->native_id ?? null;
				foreach ( $type->enum ?? ( isset( $type->const ) ? array( $type->const ) : array() ) as $id ) {
					if ( is_string( $id ) ) {
						$ids[] = $id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Provide small component-specific color hints from exact declared schemas.
	 *
	 * @param array $contracts Trusted current native creation contracts.
	 * @return array Partial advisory index; omitted paths still require inspection.
	 */
	public static function hints( array $contracts ): array {
		$result = array();
		foreach ( $contracts as $contract ) {
			$controls = array();
			foreach ( $contract->controls as $control ) {
				if ( ! str_ends_with( $control->path, '.color' ) ) {
					continue;
				}
				$controls[] = (object) array(
					'control_path'       => $control->path,
					'command_properties' => self::command_properties( $control->value_schema ),
				);
			}
			if ( ! $controls ) {
				continue;
			}
			$row = (object) array(
				'component'      => $contract->component,
				'color_controls' => $controls,
			);
			if ( strlen( json_encode( array_merge( $result, array( $row ) ), JSON_THROW_ON_ERROR ) ) <= 8192 ) {
				$result[] = $row;
			}
		}
		return $result;
	}

	/**
	 * Explain already rejected values without reflecting submitted strings/values.
	 *
	 * @param object $input Original strictly decoded factory proposal.
	 * @param array  $contracts Trusted current native creation contracts.
	 * @return array Bounded canonical mismatch hints, never replacement arguments.
	 */
	public static function rejected( object $input, array $contracts, ?string $source_hash = null ): array {
		$result = array();
		foreach ( $input->changes ?? array() as $change ) {
			foreach ( $change->subtree->nodes ?? array() as $node ) {
				foreach ( $node->attributes->initial_component_controls ?? array() as $group ) {
					$contract = $contracts[ $group->native_id ?? '' ] ?? null;
					if ( null === $contract ) {
						continue;
					}
					if ( 'directorist-elementor' === ( $contract->component->provider_id ?? null ) && ( null === $source_hash || ! preg_match( '/^[a-f0-9]{64}$/D', $source_hash ) || ( $contract->source_hash ?? null ) !== $source_hash ) ) { continue; }
					foreach ( $group->controls ?? array() as $index => $item ) {
						$elementor = 'directorist-elementor' === ( $contract->component->provider_id ?? null );
						if ( ! is_string( $item->control_path ?? null ) || ( ! $elementor && ! str_starts_with( $item->control_path, 'native_initial_style.' ) ) ) {
							continue;
						}
						$control = $contract->controls[ $item->control_path ?? '' ] ?? null;
						$row     = (object) array(
							'component'     => $contract->component,
							'control_index' => $index,
						);
						if ( null === $control ) {
							if ( $elementor ) { continue; }
							$row->code                  = 'factory_native_control_path';
							$row->allowed_control_paths = array_keys( $contract->controls );
						} else {
							$registry = new SchemaRegistry();
							$registry->register( 'native.value', json_encode( $control->value_schema, JSON_THROW_ON_ERROR ) );
							try {
								( new ContractValidator( $registry ) )->validate( $item->value, 'native.value' );
								continue;
							} catch ( ContractException $error ) {
								if ( 'invalid_input' !== $error->error_code() ) {
									continue;
								}
							}
							$row->code                       = 'factory_native_control_value';
							$row->control_path               = $control->path;
							$row->allowed_command_properties = self::command_properties( $control->value_schema );
							$row->value_schema               = ( new BuilderPromptSchema() )->compact( $control->value_schema );
							if ( $elementor ) {
								if ( true !== self::public_schema( $control->value_schema ) || false !== ( $control->private ?? null ) || true === ( $control->read_only ?? false ) || false === ( $control->writable ?? true ) || ! empty( $control->field_types ) ) { continue; }
								unset( $row->allowed_command_properties );
								$row->expected_types = is_string( $control->value_schema->type ?? null ) ? array( $control->value_schema->type ) : ( $control->value_schema->type ?? array() );
								$row->public_properties = array_slice( array_keys( (array) ( $control->value_schema->properties ?? new \stdClass() ) ), 0, 16 );
								$row->schema_sha256 = ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->digest( $control->value_schema );
								if ( str_starts_with( $control->path, 'native_controls.' ) && $item->value instanceof \stdClass ) {
									$standalone = array();
									foreach ( array_keys( get_object_vars( $item->value ) ) as $member ) {
										$sibling = $contract->controls[ $member ] ?? null;
										if ( property_exists( $control->value_schema->properties ?? new \stdClass(), $member ) || null === $sibling || false !== ( $sibling->private ?? null ) || true === ( $sibling->read_only ?? false ) || false === ( $sibling->writable ?? true ) || ! empty( $sibling->field_types ) || ! self::public_schema( $sibling->value_schema ) ) { continue; }
										$standalone[] = (object) array( 'control_path' => $sibling->path, 'value_schema' => ( new BuilderPromptSchema() )->compact( $sibling->value_schema ) );
										if ( count( $standalone ) >= 8 ) { break; }
									}
									if ( $standalone ) {
										$row->standalone_controls = $standalone;
										$row->standalone_instruction = 'Use these as separate control_path entries for this same component, never as members of the rejected object group.';
									}
								}
							}
						}
						if ( count( $result ) < 6 && strlen( json_encode( array_merge( $result, array( $row ) ), JSON_THROW_ON_ERROR ) ) <= 12288 ) {
							$result[] = $row;
						}
					}
				}
			}
		}
		( new JsonDecoder( 12288, 32 ) )->check( $result );
		return $result;
	}

	/**
	 * Report current native public preset facts, without inferring role permission.
	 *
	 * @param object $facts Current permission-filtered directory facts.
	 * @param array  $native_fields Current native submission configuration.
	 * @return object|null Canonical configuration evidence, or no complete facts.
	 */
	public static function field_configuration( object $facts, array $native_fields ): ?object {
		if ( true !== ( $facts->complete ?? null ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) ( $facts->directory_id ?? '' ) ) || ! preg_match( '/^[a-f0-9]{64}$/D', $facts->revision ?? '' ) || ! is_array( $facts->fields ?? null ) ) {
			return null;
		}
		$presets = array();
		foreach ( $native_fields as $field ) {
			if ( is_array( $field ) && 'preset' === ( $field['widget_group'] ?? null ) && empty( $field['only_for_admin'] ) && is_string( $field['widget_name'] ?? null ) && is_string( $field['type'] ?? null ) ) {
				$presets[ $field['widget_name'] . '|' . $field['type'] ] = true;
			}
		}
		$fields = array();
		foreach ( $facts->fields as $field ) {
			if ( $field instanceof \stdClass && false === ( $field->admin_only ?? null ) && null === ( $field->conditional_logic ?? null ) && is_string( $field->field_id ?? null ) && is_string( $field->widget ?? null ) && is_string( $field->type ?? null ) && isset( $presets[ $field->widget . '|' . $field->type ] ) ) {
				$fields[] = (object) array(
					'field_id' => $field->field_id,
					'widget'   => $field->widget,
					'type'     => $field->type,
				);
			}
		}
		return (object) array(
			'directory_id'                 => (string) $facts->directory_id,
			'schema_revision'              => $facts->revision,
			'public_unconditional_presets' => $fields,
			'note'                         => 'Current matching native preset/widget/type facts only; no role or write permission is inferred. Every requested directory must support a card role. Title is required. Description is not Excerpt. Conditional, admin-only, custom and mismatched native types are excluded. Native component/review availability and the full target-role validator remain authoritative.',
		);
	}

	/**
	 * Collect canonical command property names from a native value schema.
	 *
	 * @param object $schema Exact registered native command-list schema.
	 * @return array Schema-authored property names; no submitted values.
	 */
	private static function command_properties( object $schema ): array {
		$registry = new SchemaRegistry();
		$registry->register( 'native.command', json_encode( $schema, JSON_THROW_ON_ERROR ) );
		$result = array();
		$visit  = static function ( $node ) use ( &$visit, &$result ): void {
			if ( $node instanceof \stdClass ) {
				$property = $node->properties->property ?? null;
				foreach ( $property->enum ?? ( isset( $property->const ) ? array( $property->const ) : array() ) as $name ) {
					if ( is_string( $name ) && preg_match( '/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $name ) ) {
						$result[ $name ] = true;
					}
				}
				foreach ( array( 'items', 'allOf', 'oneOf', 'anyOf' ) as $keyword ) {
					if ( isset( $node->{$keyword} ) ) {
						$visit( $node->{$keyword} );
					}
				}
			} elseif ( is_array( $node ) ) {
				foreach ( $node as $child ) {
					$visit( $child );
				}
			}
		};
		$visit( $registry->get( 'native.command' ) );
		return array_keys( $result );
	}
}
