<?php
/**
 * Elementor manager controls to strict effective catalog normalization.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag -- Compact native normalizer.

use DirectoristAIPro\Validation\ContractException;

/** Reads bounded manager output; integration code owns only semantic relationships. */
final class ElementorControlNormalizer {
	public const REVISION              = 'p5-08-v7';
	private const PUBLIC_CONTROL_BYTES = 20480;
	private const PUBLIC_CONTROL_COUNT = 200;
	/**
	 * Exact trusted private-draft policies.
	 *
	 * @var BuilderPlanningPolicyRegistry|null
	 */
	private ?BuilderPlanningPolicyRegistry $planning_policies = null;
	/**
	 * Whether this reader has loaded its trusted registry.
	 *
	 * @var bool
	 */
	private bool $planning_policies_loaded = false;

	public function normalize( array $native, array $semantic, array $document_kinds ): ComponentDeclaration {
		$expected = array( 'availability', 'child_types', 'controls', 'id', 'kind', 'source_ref' );
		$keys     = array_keys( $native );
		sort( $keys, SORT_STRING );
		if ( $keys !== $expected || ! is_string( $native['id'] ) || ! ProviderRegistration::valid_token( $native['id'] ) || ! in_array( $native['kind'], array( 'element', 'widget' ), true ) || ! in_array( $native['availability'], array( 'available', 'editor_only', 'unavailable' ), true ) || ! is_array( $native['controls'] ) || ! is_array( $native['child_types'] ) ) {
			throw new ContractException( 'unsupported_contract', 'Elementor native descriptor is invalid.' );
		}
		$source_provider = $semantic['source_provider_id'] ?? null;
		if ( ! is_string( $source_provider ) || ! CatalogEnvironment::valid_id( $source_provider ) ) {
			throw new ContractException( 'unsupported_contract', 'Elementor source provider is invalid.' );
		}
		$priority_paths = null;
		if ( array_key_exists( 'planning_control_paths', $semantic ) ) {
			$priority_paths = $semantic['planning_control_paths'];
			if ( ! is_array( $priority_paths ) || count( $priority_paths ) > 200 ) {
				throw new ContractException( 'unsupported_contract', 'Native planning discovery hints are invalid.' );
			}
			foreach ( $priority_paths as $path ) {
				if ( ! is_string( $path ) || strlen( $path ) > 256 || ! preg_match( '/^_*[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]*$/D', $path ) ) {
					throw new ContractException( 'unsupported_contract', 'Native planning discovery path is invalid.' );
				}
			}
		}
		$public_paths = $semantic['public_control_paths'] ?? null;
		if ( array_key_exists( 'public_control_paths', $semantic ) ) {
			if ( ! is_array( $public_paths ) || count( $public_paths ) > 200 ) {
				throw new ContractException( 'unsupported_contract', 'Native public discovery paths are invalid.' );
			}
			foreach ( $public_paths as $path ) {
				if ( ! is_string( $path ) || strlen( $path ) > 256 || ! preg_match( '/^_*[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]*$/D', $path ) ) {
					throw new ContractException( 'unsupported_contract', 'Native public discovery path is invalid.' );
				}
			}
		}
		$public_allowlist = null === $public_paths ? null : array_fill_keys( $public_paths, true );
		// Validate and fingerprint every raw definition, including private and
		// omitted controls, without retaining another expanded registry copy.
		$native_hash = $this->native_controls_hash( $native['controls'] );
		$priority    = array_fill_keys( $priority_paths ?? $this->planning_controls( $source_provider, $native['id'] ), true );
		$names       = array_keys( $native['controls'] );
		usort( $names, static fn( string $left, string $right ): int => isset( $priority[ $left ] ) === isset( $priority[ $right ] ) ? strcmp( $left, $right ) : ( (int) isset( $priority[ $right ] ) <=> (int) isset( $priority[ $left ] ) ) );
		$properties          = new \stdClass();
		$defaults            = new \stdClass();
		$controls            = new \stdClass();
		$unsupported_by_name = array();
		$omitted             = array();
		$public_count        = 0;
		$bytes               = 0;
		$count               = 0;
		foreach ( $names as $name ) {
			$control = $native['controls'][ $name ];
			if ( self::is_private_control( $name ) ) {
				$unsupported_by_name[ $name ] = 'private-storage:' . $name;
				continue;
			}
			$schema = $this->schema( $control );
			if ( null === $schema ) {
				$unsupported_by_name[ $name ] = 'control:' . $name;
				continue;
			}
			++$public_count;
			if ( null !== $public_allowlist && ! isset( $public_allowlist[ $name ] ) ) {
				$omitted[] = 'projected-control:' . $name;
				continue;
			}
			if ( $count >= self::PUBLIC_CONTROL_COUNT ) {
				$omitted[] = 'projected-control:' . $name;
				continue;
			}
			$default  = array_key_exists( 'default', $control ) ? $this->default_value( $control['default'], $schema ) : array( false, null );
			$metadata = (object) array(
				'type'       => (string) ( $control['type'] ?? 'unknown' ),
				'label'      => isset( $control['label'] ) && is_string( $control['label'] ) ? wp_strip_all_tags( $control['label'] ) : null,
				'condition'  => $this->json_value( isset( $control['condition'] ) && is_array( $control['condition'] ) ? $control['condition'] : array() ),
				'conditions' => $this->json_value( isset( $control['conditions'] ) && is_array( $control['conditions'] ) ? $control['conditions'] : array() ),
				'responsive' => ! empty( $control['responsive'] ),
				'options'    => $this->option_keys( $control['options'] ?? array() ),
				'selectors'  => $this->option_keys( $control['selectors'] ?? array() ),
			);
			$size     = strlen(
				json_encode(
					array(
						'name'        => $name,
						'schema'      => $schema,
						'metadata'    => $metadata,
						'has_default' => $default[0],
						'default'     => $default[1],
					),
					JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
				)
			);
			if ( $bytes + $size > self::PUBLIC_CONTROL_BYTES ) {
				$omitted[] = 'projected-control:' . $name;
				continue;
			}
			$properties->{$name} = $schema;
			$controls->{$name}   = $metadata;
			if ( $default[0] ) {
				$defaults->{$name} = $default[1]; }
			$bytes += $size;
			++$count;
		}
		foreach ( array( 'properties', 'defaults', 'controls' ) as $field ) {
			$ordered = get_object_vars( ${$field} );
			ksort( $ordered, SORT_STRING );
			${$field} = (object) $ordered;
		}
		$projection = array(
			'unsupported' => $omitted,
			'summary'     => (object) array(
				'state'                   => $omitted ? 'partial' : 'complete',
				'exposed_controls'        => $count,
				'total_public_controls'   => $public_count,
				'byte_limit'              => self::PUBLIC_CONTROL_BYTES,
				'omitted_controls'        => count( $omitted ),
				'full_native_fingerprint' => $native_hash,
			),
		);
		if ( null !== $public_allowlist ) {
			$projection['summary']->profile = 'native_declared_controls'; }
		$child_types = ProviderRegistration::tokens( $native['child_types'] );
		$slots       = (array) ( $semantic['slots'] ?? array() );
		if ( $child_types ) {
			$slots[] = 'children'; }
		$semantic_controls                        = (array) ( $semantic['controls'] ?? array() );
		$semantic_controls['allowed_child_types'] = $child_types;
		$native_identity                          = $native;
		unset( $native_identity['controls'] );
		$hash = hash( 'sha256', json_encode( array( self::REVISION, $native_identity, $native_hash, $semantic, $document_kinds, $properties, $defaults ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		ksort( $unsupported_by_name, SORT_STRING );
		$unsupported    = array_values( array_unique( array_merge( array_values( $unsupported_by_name ), $omitted, (array) ( $semantic['unsupported_controls'] ?? array() ) ) ) );
		$private_labels = array_values( array_filter( $unsupported, static fn( string $label ): bool => str_starts_with( $label, 'private-storage:' ) ) );
		$other_labels   = array_values( array_filter( $unsupported, static fn( string $label ): bool => ! str_starts_with( $label, 'private-storage:' ) ) );
		$unsupported    = array_merge( $private_labels, $omitted ? array( 'native-control-projection-omitted:' . count( $omitted ) ) : array(), $other_labels );
		if ( count( $unsupported ) > 100 ) {
			$omitted_count = count( $unsupported ) - 99;
			$unsupported   = array_slice( $unsupported, 0, 99 );
			$unsupported[] = 'additional-unsupported-controls:' . $omitted_count;
		}
		return new ComponentDeclaration(
			array(
				'source_provider_id'   => $source_provider,
				'native_id'            => $native['id'],
				'availability'         => $native['availability'],
				'document_kinds'       => $document_kinds,
				'attributes_schema'    => (object) array(
					'type'                 => 'object',
					'properties'           => $properties,
					'additionalProperties' => false,
				),
				'defaults'             => $defaults,
				'controls'             => (object) array(
					'native'     => $controls,
					'projection' => $projection['summary'],
					'kind'       => $native['kind'],
					'semantic'   => $this->json_value( $semantic_controls ),
				),
				'slots'                => array_values( array_unique( $slots ) ),
				'declared_context'     => (array) ( $semantic['declared_context'] ?? array() ),
				'semantic_rule_refs'   => (array) ( $semantic['semantic_rule_refs'] ?? array() ),
				'resolver_refs'        => (array) ( $semantic['resolver_refs'] ?? array() ),
				'dependencies'         => (array) ( $semantic['dependencies'] ?? array() ),
				'directory_fields'     => (array) ( $semantic['directory_fields'] ?? array() ),
				'source_fingerprint'   => $hash,
				'provenance'           => array(
					array(
						'kind'       => 'native_registry',
						'source_ref' => $native['source_ref'],
					),
					array(
						'kind'       => 'normalizer',
						'source_ref' => 'elementor:' . self::REVISION,
					),
				),
				'features'             => array_map(
					static fn( string $kind ): array => array(
						'operation'       => 'inspect',
						'document_kind'   => $kind,
						'requires_editor' => 'editor_only' === $native['availability'],
					),
					$document_kinds
				),
				'unsupported_controls' => $unsupported,
			)
		);
	}


	/** Fingerprint the original native JSON object one control at a time. */
	private function native_controls_hash( array $controls ): string {
		if ( ! $controls ) {
			return hash( 'sha256', '[]' ); }
		$hash = hash_init( 'sha256' );
		hash_update( $hash, '{' );
		$first = true;
		foreach ( $controls as $name => $control ) {
			if ( ! is_string( $name ) || ! self::valid_control_id( $name ) || ! is_array( $control ) ) {
				throw new ContractException( 'unsupported_contract', 'Elementor control descriptor is invalid.' );
			}
			if ( ! $first ) {
				hash_update( $hash, ',' ); }
			$first = false;
			hash_update( $hash, json_encode( $name, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) . ':' );
			hash_update( $hash, json_encode( $control, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		}
		hash_update( $hash, '}' );
		return hash_final( $hash );
	}

	/** Exact trusted private-draft policy keys are retained before extra discovery. */
	private function planning_controls( string $provider, string $native ): array {
		if ( ! $this->planning_policies_loaded ) {
			$this->planning_policies_loaded = true;
			if ( function_exists( 'do_action' ) ) {
				$this->planning_policies = new BuilderPlanningPolicyRegistry();
				do_action( 'directorist_ai_pro_register_builder_planning_policies', $this->planning_policies );
				$this->planning_policies->freeze();
			}
		}
		$policy = null === $this->planning_policies ? null : $this->planning_policies->component( $provider, $native );
		if ( null === $policy ) {
			return array();
		}
		$paths      = array_map( static fn( object $control ): string => explode( '.', $control->path )[0], array_filter( $policy->controls, static fn( object $control ): bool => ! $control->private ) );
		$properties = $policy->insert_attributes_schema->properties ?? new \stdClass();
		return array_values( array_unique( array_merge( $paths, array_keys( get_object_vars( $properties ) ), $policy->insert_attributes_schema->required ?? array() ) ) );
	}

	private function schema( array $control ): ?object {
		$type    = (string) ( $control['type'] ?? '' );
		$default = $control['default'] ?? null;
		if ( in_array( $type, array( 'alert', 'divider', 'heading', 'popover_toggle', 'raw_html', 'section', 'tab', 'tabs' ), true ) ) {
			return null;
		}
		if ( in_array( $type, array( 'number', 'slider' ), true ) ) {
			return (object) array( 'type' => 'slider' === $type ? 'object' : 'number' );
		}
		if ( 'switcher' === $type ) {
			if ( is_bool( $default ) ) {
				return (object) array( 'type' => 'boolean' );
			}
			return (object) array(
				'type' => 'string',
				'enum' => array( '', 'yes' ),
			);
		}
		if ( 'gallery' === $type ) {
			return (object) array( 'type' => 'array' );
		}
		if ( in_array( $type, array( 'media', 'icons', 'dimensions', 'color', 'url' ), true ) ) {
			return (object) array( 'type' => is_string( $default ) ? 'string' : 'object' );
		}
		if ( 'repeater' === $type ) {
			return (object) array( 'type' => 'array' );
		}
		if ( is_bool( $default ) ) {
			return (object) array( 'type' => 'boolean' );
		}
		if ( is_int( $default ) || is_float( $default ) ) {
			return (object) array( 'type' => 'number' );
		}
		if ( is_array( $default ) ) {
			return (object) array( 'type' => $this->is_list( $default ) ? 'array' : 'object' );
		}
		if ( '' !== $type ) {
			return (object) array( 'type' => 'string' );
		}
		return null;
	}

	private function default_value( $value, object $schema ): array {
		if ( null === $value ) {
			return array( false, null );
		}
		if ( 'number' === ( $schema->type ?? null ) && is_string( $value ) ) {
			if ( '' === trim( $value ) || ! is_numeric( $value ) ) {
				return array( false, null );
			}
			$value = false === strpos( $value, '.' ) ? (int) $value : (float) $value;
		}
		if ( 'object' === ( $schema->type ?? null ) && is_array( $value ) && ! $value ) {
			$value = new \stdClass();
		}
		$value = $this->json_value( $value );
		if ( ! $this->default_matches( $value, $schema ) ) {
			return array( false, null );
		}
		return array( true, $value );
	}

	private function default_matches( $value, object $schema ): bool {
		$type = $schema->type ?? null;
		if ( 'string' === $type ) {
			return is_string( $value ) && ( ! isset( $schema->enum ) || in_array( $value, $schema->enum, true ) );
		}
		if ( 'number' === $type ) {
			return is_int( $value ) || is_float( $value );
		}
		if ( 'boolean' === $type ) {
			return is_bool( $value );
		}
		if ( 'array' === $type ) {
			return is_array( $value );
		}
		if ( 'object' === $type ) {
			return $value instanceof \stdClass;
		}
		return false;
	}

	private function option_keys( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys, SORT_STRING );
		return array_slice( $keys, 0, 200 );
	}

	private function json_value( $value ) {
		return json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
	}

	private function is_list( array $value ): bool {
		return ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function valid_control_id( string $value ): bool {
		return strlen( $value ) <= 256 && 1 === preg_match( '/^_*[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]*$/D', $value );
	}

	private static function is_private_control( string $name ): bool {
		return in_array( $name, array( '_attributes', '_element_id', '_title', 'active_search_template_key', 'active_template_key', 'branch_key', 'custom_css', 'scoped_search_templates', 'scoped_templates' ), true );
	}
}
