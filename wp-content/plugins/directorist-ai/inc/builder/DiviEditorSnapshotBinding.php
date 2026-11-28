<?php
/**
 * Server-bound Divi iframe registry and source/view evidence.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Compact immutable evidence boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Binds current iframe facts to one actor/site/document/editor epoch; inspect only. */
final class DiviEditorSnapshotBinding implements CatalogEditorSnapshot {
	public const CONTRACT_REVISION = 'divi-editor-v1';
	private const CHALLENGE_PREFIX = 'dai_dv_editor_challenge_';
	private const BINDING_PREFIX   = 'dai_dv_editor_binding_';
	private const EPOCH_PREFIX     = 'dai_dv_editor_epoch_';

	private array $challenge;
	private object $snapshot;
	private ?object $graph_source;
	private string $fingerprint;

	public static function challenge( Context $context, string $document_kind, ?int $directory_id, string $document_ref, string $origin ): object {
		$context->assert_execution_identity();
		$post_id = self::canonical_post_id( $document_ref );
		if ( ! ProviderRegistration::valid_token( $document_kind ) || ( null !== $directory_id && $directory_id < 1 ) || $post_id < 1 || ! self::same_origin( $origin ) || ! current_user_can( 'edit_post', $post_id ) ) {
			throw new ContractException( 'unauthorized', 'Divi editor challenge is unavailable.' );
		}
		$document  = self::document_context( $post_id, $document_kind, $directory_id );
		$id        = 'dv-' . bin2hex( random_bytes( 16 ) );
		$challenge = array(
			'binding_id'           => $id,
			'site_id'              => get_current_blog_id(),
			'user_id'              => (int) $context->actor()->user_id(),
			'document_kind'        => $document_kind,
			'directory_id'         => $directory_id,
			'document_ref'         => $document_ref,
			'document_type'        => $document['document_type'],
			'assignment_revision'  => $document['assignment_revision'],
			'origin'               => $origin,
			'epoch'                => hash( 'sha256', random_bytes( 32 ) ),
			'base_native_revision' => self::document_revision( $post_id, $document['assignment_revision'] ),
			'provider_revision'    => self::provider_revision(),
			'contract_revision'    => self::CONTRACT_REVISION,
			'expires_at'           => time() + 300,
		);
		set_transient( self::CHALLENGE_PREFIX . hash( 'sha256', $id ), $challenge, 300 );
		set_transient( self::epoch_key( $challenge ), $challenge['epoch'], 900 );
		return (object) array(
			'binding_id'        => $id,
			'nonce'             => wp_create_nonce( 'directorist_ai_divi_editor_snapshot:' . $id ),
			'expires_at'        => $challenge['expires_at'],
			'document_type'     => $challenge['document_type'],
			'provider_revision' => $challenge['provider_revision'],
			'contract_revision' => self::CONTRACT_REVISION,
		);
	}

	public static function issue( Context $context, array $data ): self {
		$context->assert_execution_identity();
		$required = array( 'allowed_modules', 'binding_id', 'contract_revision', 'document_type', 'flat_modules', 'modal_state', 'module_registry', 'nonce', 'origin', 'source_view_key', 'state_by_source_view', 'view_modes' );
		$extended = array_merge( $required, array( 'graph_source' ) );
		sort( $extended, SORT_STRING );
		$keys = array_keys( $data );
		sort( $keys, SORT_STRING );
		if ( ( $keys !== $required && $keys !== $extended ) || ! is_string( $data['binding_id'] ) || ! ProviderRegistration::valid_token( $data['binding_id'] ) || ! is_string( $data['nonce'] ) || ! is_string( $data['origin'] ) || self::CONTRACT_REVISION !== $data['contract_revision'] ) {
			throw new ContractException( 'evidence_stale', 'Divi editor evidence is invalid.' );
		}
		$key       = self::CHALLENGE_PREFIX . hash( 'sha256', $data['binding_id'] );
		$challenge = get_transient( $key );
		delete_transient( $key );
		$post_id = is_array( $challenge ) ? self::canonical_post_id( (string) ( $challenge['document_ref'] ?? '' ) ) : 0;
		if ( ! is_array( $challenge ) || $challenge['binding_id'] !== $data['binding_id'] || (int) $challenge['site_id'] !== get_current_blog_id() || (int) $challenge['user_id'] !== (int) $context->actor()->user_id() || (int) $challenge['expires_at'] <= time() || ! hash_equals( $challenge['origin'], $data['origin'] ) || ! self::same_origin( $data['origin'] ) || ! wp_verify_nonce( $data['nonce'], 'directorist_ai_divi_editor_snapshot:' . $data['binding_id'] ) || $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) || ! is_string( $data['document_type'] ) || ! hash_equals( $challenge['document_type'], $data['document_type'] ) || ! hash_equals( $challenge['provider_revision'], self::provider_revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Divi editor challenge is stale or consumed.' );
		}
		$document = self::document_context( $post_id, $challenge['document_kind'], $challenge['directory_id'] );
		if ( ! hash_equals( $challenge['assignment_revision'], $document['assignment_revision'] ) || ! hash_equals( $challenge['base_native_revision'], self::document_revision( $post_id, $document['assignment_revision'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Divi document authority changed during capture.' );
		}
		$allowed           = self::tokens( $data['allowed_modules'] );
		$registry_source   = 'server_registered' === $data['module_registry'] ? self::registered_modules_for_editor( $allowed ) : $data['module_registry'];
		$registry          = self::module_registry( $registry_source, $allowed );
		$flat_source       = 'server_hash' === $data['flat_modules'] ? self::flat_modules_from_private_source( $data['graph_source'] ?? null ) : $data['flat_modules'];
		$flat              = self::flat_modules( $flat_source, $registry[0], $allowed );
		$current_key       = self::state_key( $data['source_view_key'], true );
		$states            = self::source_states( $data['state_by_source_view'], $flat, $registry[0], $current_key );
		$modal             = self::modal_state( $data['modal_state'], $flat, $states );
		$scope             = self::scope_state( $flat, $states, $current_key, $registry[1] );
		$graph_source      = self::private_graph_source( $data['graph_source'] ?? null, $flat, $states, $data['state_by_source_view'] );
		$payload           = (object) array(
			'allowed_modules'      => $allowed,
			'assignment_revision'  => $challenge['assignment_revision'],
			'base_native_revision' => $challenge['base_native_revision'],
			'contract_revision'    => self::CONTRACT_REVISION,
			'document_ref'         => $challenge['document_ref'],
			'document_type'        => $challenge['document_type'],
			'epoch'                => $challenge['epoch'],
			'flat_modules'         => $flat,
			'modal_state'          => $modal,
			'module_registry'      => $registry[0],
			'provider_revision'    => $challenge['provider_revision'],
			'registry_status'      => $registry[1],
			'scope_revision'       => $scope['revision'],
			'scope_status'         => $scope['status'],
			'source_view_key'      => $current_key,
			'state_by_source_view' => $states,
			'view_modes'           => self::view_modes( $data['view_modes'] ),
		);
		$payload           = json_decode( json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		$payload->revision = hash( 'sha256', json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		( new JsonDecoder( 3145728, 48 ) )->check( $payload );
		$binding = new self( $challenge, $payload, $graph_source );
		set_transient(
			self::BINDING_PREFIX . hash( 'sha256', $data['binding_id'] ),
			array(
				'challenge'    => $challenge,
				'snapshot'     => $payload,
				'graph_source' => $graph_source,
			),
			300
		);
		return $binding;
	}

	public static function load( Context $context, string $binding_id ): self {
		if ( ! ProviderRegistration::valid_token( $binding_id ) || 0 !== strpos( $binding_id, 'dv-' ) ) {
			throw new ContractException( 'evidence_stale', 'Divi editor binding identity is invalid.' );
		}
		$stored = get_transient( self::BINDING_PREFIX . hash( 'sha256', $binding_id ) );
		if ( ! is_array( $stored ) || ! isset( $stored['challenge'], $stored['snapshot'] ) || ! is_array( $stored['challenge'] ) || ! $stored['snapshot'] instanceof \stdClass || $stored['challenge']['binding_id'] !== $binding_id || (int) $stored['challenge']['site_id'] !== get_current_blog_id() || (int) $stored['challenge']['user_id'] !== (int) $context->actor()->user_id() ) {
			throw new ContractException( 'evidence_stale', 'Divi editor binding is unavailable.' );
		}
		$graph_source = $stored['graph_source'] ?? null;
		if ( null !== $graph_source && ! $graph_source instanceof \stdClass ) {
			throw new ContractException( 'evidence_stale', 'Private Divi graph evidence is invalid.' );
		}
		if ( null !== $graph_source ) {
			try {
				$raw      = json_decode( json_encode( $graph_source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), true, 48, JSON_THROW_ON_ERROR );
				$verified = self::private_graph_source( array( 'flat_modules' => $raw['flat_modules'] ), $stored['snapshot']->flat_modules, $stored['snapshot']->state_by_source_view, $raw['state_by_source_view'] );
				if ( self::canonical_json( $verified ) !== self::canonical_json( $graph_source ) ) {
					throw new ContractException( 'evidence_stale', 'Private Divi graph evidence changed after capture.' );
				}
			} catch ( \Throwable $error ) {
				throw new ContractException( 'evidence_stale', 'Private Divi graph evidence changed after capture.' );
			}
		}
		$binding = new self( $stored['challenge'], $stored['snapshot'], $graph_source );
		$binding->assert_current( $context, 'divi' );
		return $binding;
	}

	private function __construct( array $challenge, object $snapshot, ?object $graph_source = null ) {
		$this->challenge    = $challenge;
		$this->snapshot     = $snapshot;
		$this->graph_source = $graph_source;
		$this->fingerprint  = hash( 'sha256', json_encode( array( $challenge['binding_id'], $challenge['site_id'], $challenge['user_id'], $challenge['document_kind'], $challenge['directory_id'], $challenge['expires_at'], $snapshot, null === $graph_source ? null : hash( 'sha256', self::canonical_json( $graph_source ) ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function assert_current( Context $context, string $builder ): void {
		$context->assert_execution_identity();
		$current_epoch = get_transient( self::epoch_key( $this->challenge ) );
		$post_id       = self::canonical_post_id( (string) ( $this->challenge['document_ref'] ?? '' ) );
		if ( 'divi' !== $builder || get_current_blog_id() !== (int) $this->challenge['site_id'] || (int) $context->actor()->user_id() !== (int) $this->challenge['user_id'] || time() >= (int) $this->challenge['expires_at'] || ! is_string( $current_epoch ) || ! hash_equals( $this->snapshot->epoch, $current_epoch ) || ! self::same_origin( $this->challenge['origin'] ) || $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) || ! hash_equals( $this->challenge['provider_revision'], self::provider_revision() ) ) {
			throw new ContractException( 'evidence_stale', 'Divi editor evidence is stale.' );
		}
		$document = self::document_context( $post_id, $this->challenge['document_kind'], $this->challenge['directory_id'] );
		if ( ! hash_equals( $this->challenge['assignment_revision'], $document['assignment_revision'] ) || ! hash_equals( $this->challenge['base_native_revision'], self::document_revision( $post_id, $document['assignment_revision'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Divi document evidence is stale.' );
		}
	}

	public function binding_id(): string {
		return $this->challenge['binding_id']; }
	public function fingerprint(): string {
		return $this->fingerprint; }
	public function document_kind(): string {
		return $this->challenge['document_kind']; }
	public function directory_id(): ?int {
		return $this->challenge['directory_id']; }
	public function snapshot(): object {
		return json_decode( json_encode( $this->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
	}

	/** Execute trusted extraction over bound private bytes without adding them to snapshot/REST output. */
	public function with_graph_source( Context $context, callable $extract ) {
		$this->assert_current( $context, 'divi' );
		if ( null === $this->graph_source ) {
			return null;
		}
		$copy = json_decode( json_encode( $this->graph_source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		return $extract( $copy );
	}

	private static function document_context( int $post_id, string $kind, ?int $directory_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ContractException( 'unauthorized', 'Divi document is unavailable.' );
		}
		if ( 'page' === $kind && 'page' === $post->post_type ) {
			return array(
				'document_type'       => 'page',
				'assignment_revision' => hash( 'sha256', 'page:' . $post_id ),
			);
		}
		$facts = apply_filters( 'directorist_ai_divi_document_context', null, $post_id, $kind, $directory_id );
		if ( is_object( $facts ) ) {
			$facts = get_object_vars( $facts );
		}
		if ( is_array( $facts ) ) {
			ksort( $facts, SORT_STRING );
		}
		$directory_matches = is_array( $facts ) && array_key_exists( 'directory_id', $facts ) && ( 'template:single_listing' === $kind ? null !== $directory_id && $facts['directory_id'] === $directory_id : null === $facts['directory_id'] );
		if ( ! is_array( $facts ) || array( 'assignment_revision', 'directory_id', 'document_kind', 'document_type' ) !== array_keys( $facts ) || $facts['document_kind'] !== $kind || ! $directory_matches || ! is_string( $facts['document_type'] ) || ! ProviderRegistration::valid_token( $facts['document_type'] ) || ! is_string( $facts['assignment_revision'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $facts['assignment_revision'] ) ) {
			throw new ContractException( 'unauthorized', 'Divi template assignment is unavailable.' );
		}
		return array(
			'document_type'       => $facts['document_type'],
			'assignment_revision' => $facts['assignment_revision'],
		);
	}

	private static function document_revision( int $post_id, string $assignment_revision ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ContractException( 'evidence_stale', 'Divi document is unavailable.' );
		}
		return hash( 'sha256', json_encode( array( $post_id, $post->post_type, $post->post_modified_gmt, hash( 'sha256', $post->post_content ), get_post_meta( $post_id, '_et_pb_use_builder', true ), get_post_meta( $post_id, '_et_pb_built_for_post_type', true ), $assignment_revision ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Describe only the active identities supplied by the live editor from the
	 * server's current native registrations. Browser metadata is never authority.
	 *
	 * @param array $identities Active module names in the current editor tree.
	 * @return array Exact module descriptors accepted by issue().
	 */
	private static function registered_modules_for_editor( array $identities ): array {
		$identities = self::tokens( $identities );
		if ( count( $identities ) > 150 || in_array( '*', $identities, true ) ) {
			throw new ContractException( 'evidence_stale', 'Divi editor module identities are invalid.' );
		}
		$result = array();
		foreach ( $identities as $identity ) {
			$server = self::server_module( $identity );
			if ( null === $server ) {
				throw new ContractException( 'evidence_stale', 'Divi editor module is not registered.' );
			}
			$attributes = array();
			$defaults   = array();
			foreach ( (array) $server->attributes as $name => $attribute ) {
				if ( ! is_string( $name ) || ! is_array( $attribute ) ) {
					continue;
				}
				$descriptor = array();
				foreach ( array( 'default', 'default_value', 'selector', 'type' ) as $field ) {
					if ( array_key_exists( $field, $attribute ) ) {
						$descriptor[ $field ] = $attribute[ $field ];
					}
				}
				$attributes[ $name ] = $descriptor;
				if ( array_key_exists( 'default', $attribute ) ) {
					$defaults[ $name ] = $attribute['default'];
				} elseif ( array_key_exists( 'default_value', $attribute ) ) {
					$defaults[ $name ] = $attribute['default_value'];
				}
			}
			$result[] = array(
				'attributes'  => $attributes,
				'child_types' => self::server_children( $server ),
				'defaults'    => $defaults,
				'id'          => $identity,
				'source_ref'  => self::server_source_ref( $identity ),
			);
		}
		return $result;
	}

	private static function module_registry( $items, array $allowed ): array {
		if ( ! is_array( $items ) || count( $items ) > 150 ) {
			throw new ContractException( 'evidence_stale', 'Divi module registry evidence is invalid.' );
		}
		$result   = array();
		$seen     = array();
		$complete = ! in_array( '*', $allowed, true );
		foreach ( $items as $item ) {
			$keys = is_array( $item ) ? array_keys( $item ) : array();
			sort( $keys, SORT_STRING );
			if ( ! is_array( $item ) || array( 'attributes', 'child_types', 'defaults', 'id', 'source_ref' ) !== $keys || ! is_string( $item['id'] ) || ! ProviderRegistration::valid_token( $item['id'] ) || isset( $seen[ $item['id'] ] ) || ! is_array( $item['attributes'] ) || ! is_array( $item['child_types'] ) || ! is_array( $item['defaults'] ) || ! is_string( $item['source_ref'] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi module registry evidence is invalid.' );
			}
			$server = self::server_module( $item['id'] );
			if ( null === $server ) {
				throw new ContractException( 'evidence_stale', 'Divi module is not registered by the server.' );
			}
			$source_ref = self::server_source_ref( $item['id'] );
			if ( $source_ref !== $item['source_ref'] ) {
				throw new ContractException( 'evidence_stale', 'Divi module source attribution differs from the server.' );
			}
			$server_attributes = (array) $server->attributes;
			$attributes        = self::attribute_subset( $item['attributes'], $server_attributes );
			$defaults          = self::default_subset( $item['defaults'], $server_attributes );
			$children          = self::tokens( $item['child_types'] );
			$server_children   = self::server_children( $server );
			if ( $server_children && array_diff( $children, $server_children ) ) {
				throw new ContractException( 'evidence_stale', 'Divi child relationship differs from server metadata.' );
			}
			foreach ( $children as $child ) {
				if ( null === self::server_module( $child ) ) {
					throw new ContractException( 'evidence_stale', 'Divi child module is not registered by the server.' );
				}
			}
			$server_defaults     = array_filter(
				$server_attributes,
				static fn( $attribute ): bool => is_array( $attribute ) && ( array_key_exists( 'default', $attribute ) || array_key_exists( 'default_value', $attribute ) )
			);
			$children_complete   = ! $server_children || $children === $server_children;
			$complete            = $complete && count( get_object_vars( $attributes ) ) === count( $server_attributes ) && count( get_object_vars( $defaults ) ) === count( $server_defaults ) && $children_complete;
			$seen[ $item['id'] ] = true;
			$result[]            = (object) array(
				'attributes'  => $attributes,
				'child_types' => $children,
				'defaults'    => $defaults,
				'id'          => $item['id'],
				'source_ref'  => $source_ref,
			);
		}
		usort( $result, static fn( object $left, object $right ): int => $left->id <=> $right->id );
		$registry_ids = array_map( static fn( object $item ): string => $item->id, $result );
		$complete     = $complete && ! array_diff( $allowed, $registry_ids ) && ! array_diff( $registry_ids, $allowed );
		return array( $result, $complete ? 'complete' : 'incomplete' );
	}

	private static function server_module( string $identity ): ?object {
		$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $identity );
		if ( $registered instanceof \WP_Block_Type ) {
			return $registered;
		}
		if ( 0 === strpos( $identity, 'divi/' ) && class_exists( '\\ET\\Builder\\Packages\\ModuleLibrary\\ModuleRegistration' ) ) {
			$module = \ET\Builder\Packages\ModuleLibrary\ModuleRegistration::get_module_settings( $identity );
			return $module instanceof \WP_Block_Type ? $module : null;
		}
		return null;
	}

	private static function server_source_ref( string $identity ): string {
		return \WP_Block_Type_Registry::get_instance()->get_registered( $identity ) instanceof \WP_Block_Type ? 'divi:wp-block-registry' : 'divi:module-registration';
	}

	private static function server_children( object $module ): array {
		$children = array();
		foreach ( array( 'childrenName', 'allowed_blocks' ) as $field ) {
			if ( isset( $module->{$field} ) && is_array( $module->{$field} ) ) {
				$children = array_merge( $children, $module->{$field} );
			}
		}
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native Divi registrar property.
		if ( isset( $module->childModuleName ) && is_string( $module->childModuleName ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native Divi registrar property.
			$children[] = $module->childModuleName;
		}
		$children = array_values(
			array_filter(
				$children,
				static fn( $child ): bool => is_string( $child ) && null !== self::server_module( $child )
			)
		);
		return self::tokens( $children );
	}

	/** Compute public hashes from the exact private bytes validated later in issue(). */
	private static function flat_modules_from_private_source( $source ): array {
		if ( ! is_array( $source ) || array_keys( $source ) !== array( 'flat_modules' ) || ! is_array( $source['flat_modules'] ) || count( $source['flat_modules'] ) > 2000 ) {
			throw new ContractException( 'evidence_stale', 'Divi editor flat source is invalid.' );
		}
		try {
			$validated = json_decode( json_encode( (object) $source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 48, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Divi editor flat source is invalid.' );
		}
		( new JsonDecoder( 4194304, 48 ) )->check( $validated );
		$result = array();
		foreach ( $source['flat_modules'] as $module ) {
			$keys = is_array( $module ) ? array_keys( $module ) : array();
			sort( $keys, SORT_STRING );
			if ( array( 'attrs', 'children_ids', 'id', 'name', 'parent_id' ) !== $keys || ! is_array( $module['attrs'] ) || ! is_array( $module['children_ids'] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi editor flat module is invalid.' );
			}
			$result[] = array(
				'attrs_hash'   => hash( 'sha256', $module['attrs'] ? self::canonical_json( $module['attrs'] ) : '{}' ),
				'children_ids' => $module['children_ids'],
				'id'           => $module['id'],
				'name'         => $module['name'],
				'parent_id'    => $module['parent_id'],
			);
		}
		return $result;
	}

	private static function attribute_subset( array $client, array $server ): object {
		if ( count( $client ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Divi attribute evidence is invalid.' );
		}
		$result = new \stdClass();
		ksort( $client, SORT_STRING );
		foreach ( $client as $name => $descriptor ) {
			if ( ! is_string( $name ) || ! isset( $server[ $name ] ) || ! is_array( $descriptor ) || ! is_array( $server[ $name ] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi attribute evidence is not server registered.' );
			}
			foreach ( $descriptor as $field => $value ) {
				if ( ! in_array( $field, array( 'default', 'default_value', 'selector', 'type' ), true ) || ! array_key_exists( $field, $server[ $name ] ) || self::canonical_json( $value ) !== self::canonical_json( $server[ $name ][ $field ] ) ) {
					throw new ContractException( 'evidence_stale', 'Divi attribute evidence differs from server metadata.' );
				}
			}
			$result->{$name} = json_decode( json_encode( $descriptor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
		}
		return $result;
	}

	private static function default_subset( array $client, array $server ): object {
		if ( count( $client ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Divi default evidence is invalid.' );
		}
		$result = new \stdClass();
		ksort( $client, SORT_STRING );
		foreach ( $client as $name => $value ) {
			if ( ! is_string( $name ) || ! isset( $server[ $name ] ) || ! is_array( $server[ $name ] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi default evidence is not server registered.' );
			}
			$field = array_key_exists( 'default', $server[ $name ] ) ? 'default' : ( array_key_exists( 'default_value', $server[ $name ] ) ? 'default_value' : null );
			if ( null === $field || self::canonical_json( $value ) !== self::canonical_json( $server[ $name ][ $field ] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi default evidence differs from server metadata.' );
			}
			$result->{$name} = json_decode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 32, JSON_THROW_ON_ERROR );
		}
		return $result;
	}

	private static function flat_modules( $items, array $registry, array $allowed ): array {
		if ( ! is_array( $items ) || count( $items ) > 5000 ) {
			throw new ContractException( 'evidence_stale', 'Divi flat module evidence is invalid.' );
		}
		$result       = array();
		$seen         = array();
		$registry_ids = array_fill_keys( array_map( static fn( object $item ): string => $item->id, $registry ), true );
		$allow_all    = in_array( '*', $allowed, true );
		foreach ( $items as $item ) {
			$keys = is_array( $item ) ? array_keys( $item ) : array();
			sort( $keys, SORT_STRING );
			if ( ! is_array( $item ) || array( 'attrs_hash', 'children_ids', 'id', 'name', 'parent_id' ) !== $keys || ! self::runtime_id( $item['id'] ) || isset( $seen[ $item['id'] ] ) || ! is_string( $item['name'] ) || ! ProviderRegistration::valid_token( $item['name'] ) || ! isset( $registry_ids[ $item['name'] ] ) || ( ! $allow_all && ! in_array( $item['name'], $allowed, true ) ) || ( null !== $item['parent_id'] && ! self::runtime_id( $item['parent_id'] ) ) || ! is_array( $item['children_ids'] ) || ! is_string( $item['attrs_hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $item['attrs_hash'] ) ) {
				throw new ContractException( 'evidence_stale', 'Divi flat module evidence is invalid.' );
			}
			$children            = self::runtime_ids( $item['children_ids'] );
			$seen[ $item['id'] ] = true;
			$result[]            = (object) array(
				'attrs_hash'   => $item['attrs_hash'],
				'children_ids' => $children,
				'id'           => $item['id'],
				'name'         => $item['name'],
				'parent_id'    => $item['parent_id'],
			);
		}
		$by_id = array();
		foreach ( $result as $module ) {
			$by_id[ $module->id ] = $module;
		}
		foreach ( $result as $module ) {
			if ( null !== $module->parent_id && ( ! isset( $by_id[ $module->parent_id ] ) || ! in_array( $module->id, $by_id[ $module->parent_id ]->children_ids, true ) ) ) {
				throw new ContractException( 'evidence_stale', 'Divi parent identity is unavailable.' );
			}
			foreach ( $module->children_ids as $child_id ) {
				if ( $child_id === $module->id || ! isset( $by_id[ $child_id ] ) || $by_id[ $child_id ]->parent_id !== $module->id ) {
					throw new ContractException( 'evidence_stale', 'Divi child identity is unavailable.' );
				}
			}
		}
		$colors = array();
		$visit  = static function ( string $id ) use ( &$visit, &$colors, $by_id ): void {
			if ( 1 === ( $colors[ $id ] ?? 0 ) ) {
				throw new ContractException( 'evidence_stale', 'Divi module hierarchy contains a cycle.' );
			}
			if ( 2 === ( $colors[ $id ] ?? 0 ) ) {
				return;
			}
			$colors[ $id ] = 1;
			foreach ( $by_id[ $id ]->children_ids as $child_id ) {
				$visit( $child_id );
			}
			$colors[ $id ] = 2;
		};
		foreach ( array_keys( $by_id ) as $id ) {
			$visit( $id );
		}
		usort( $result, static fn( object $left, object $right ): int => $left->id <=> $right->id );
		return $result;
	}

	private static function source_states( $items, array $flat, array $registry, string $current_key ): array {
		if ( ! is_array( $items ) || count( $items ) > 100 ) {
			throw new ContractException( 'evidence_stale', 'Divi source/view state is invalid.' );
		}
		$result       = array();
		$keys         = array();
		$flat_by_id   = array();
		$registry_ids = array_fill_keys( array_map( static fn( object $module ): string => $module->id, $registry ), true );
		$total_bytes  = 0;
		foreach ( $flat as $module ) {
			$flat_by_id[ $module->id ] = $module;
		}
		foreach ( $items as $item ) {
			$fields = is_array( $item ) ? array_keys( $item ) : array();
			sort( $fields, SORT_STRING );
			if ( ! is_array( $item ) || array( 'children_ids', 'key', 'owner_id', 'serialized_state', 'source_slug', 'view' ) !== $fields || ! self::runtime_id( $item['owner_id'] ) || ! isset( $flat_by_id[ $item['owner_id'] ] ) || 'directorist/listings-card' !== $flat_by_id[ $item['owner_id'] ]->name ) {
				throw new ContractException( 'evidence_stale', 'Divi source/view owner is invalid.' );
			}
			$key = self::state_key( $item['key'], false );
			if ( isset( $keys[ $item['owner_id'] . ':' . $key ] ) || ! is_string( $item['source_slug'] ) || ! ProviderRegistration::valid_token( $item['source_slug'] ) || ! in_array( $item['view'], array( 'grid', 'list', 'map', 'single' ), true ) || $key !== $item['source_slug'] . '|' . $item['view'] ) {
				throw new ContractException( 'evidence_stale', 'Divi source/view identity is invalid.' );
			}
			$children = self::runtime_ids( $item['children_ids'] );
			$state    = $item['serialized_state'];
			if ( ! is_array( $state ) || ! isset( $state['modules'] ) || ! self::list_array( $state['modules'] ) || count( $state['modules'] ) > 500 ) {
				throw new ContractException( 'evidence_stale', 'Divi serialized source/view state is invalid.' );
			}
			try {
				$canonical = self::canonical_json( $state );
			} catch ( \JsonException $error ) {
				throw new ContractException( 'evidence_stale', 'Divi serialized source/view state is invalid.' );
			}
			$total_bytes += strlen( $canonical );
			if ( strlen( $canonical ) > 262144 || $total_bytes > 2097152 ) {
				throw new ContractException( 'evidence_stale', 'Divi serialized source/view state exceeds the evidence boundary.' );
			}
			$count   = 0;
			$summary = self::serialized_modules( $state['modules'], $registry_ids, $count, 0 );
			$present = 0;
			$owned   = 0;
			foreach ( $children as $child_id ) {
				if ( isset( $flat_by_id[ $child_id ] ) ) {
					++$present;
					if ( $flat_by_id[ $child_id ]->parent_id === $item['owner_id'] ) {
						++$owned;
					}
				}
			}
			$id_status       = ! $children ? 'absent' : ( count( $children ) === $owned ? 'current' : ( 0 === $present ? 'stale' : 'partial' ) );
			$flat_children   = $flat_by_id[ $item['owner_id'] ]->children_ids;
			$linked          = $children === $flat_children && self::serialized_matches_flat( $state['modules'], $flat_children, $flat_by_id );
			$active_relation = $key === $current_key && $linked ? 'matched' : ( $key === $current_key ? 'unreconciled' : 'inactive' );

			$keys[ $item['owner_id'] . ':' . $key ] = true;
			$result[]                               = (object) array(
				'active_flat_status' => $active_relation,
				'children_id_status' => $id_status,
				'complete'           => true,
				'key'                => $key,
				'module_count'       => $count,
				'module_structure'   => $summary,
				'modules_hash'       => hash( 'sha256', $canonical ),
				'owner_id'           => $item['owner_id'],
				'source_slug'        => $item['source_slug'],
				'view'               => $item['view'],
			);
		}
		usort( $result, static fn( object $left, object $right ): int => array( $left->owner_id, $left->key ) <=> array( $right->owner_id, $right->key ) );
		return $result;
	}

	/** Bind raw editor attributes and serialized bytes to the already checked public hashes. */
	private static function private_graph_source( $value, array $flat, array $states, $raw_states ): ?object {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) || array_keys( $value ) !== array( 'flat_modules' ) || ! is_array( $value['flat_modules'] ) || count( $value['flat_modules'] ) !== count( $flat ) || count( $flat ) > 2000 || ! is_array( $raw_states ) || count( $raw_states ) !== count( $states ) ) {
			throw new ContractException( 'evidence_stale', 'Private Divi graph source is incomplete.' );
		}
		$by_id = array();
		foreach ( $flat as $module ) {
			$by_id[ $module->id ] = $module;
		}
		$seen = array();
		foreach ( $value['flat_modules'] as $module ) {
			$keys = is_array( $module ) ? array_keys( $module ) : array();
			sort( $keys, SORT_STRING );
			if ( $keys !== array( 'attrs', 'children_ids', 'id', 'name', 'parent_id' ) || ! isset( $module['id'], $module['name'], $module['attrs'], $module['children_ids'] ) || ! is_array( $module['attrs'] ) || ! is_array( $module['children_ids'] ) || ! isset( $by_id[ $module['id'] ] ) || isset( $seen[ $module['id'] ] ) ) {
				throw new ContractException( 'evidence_stale', 'Private Divi flat module is invalid.' );
			}
			$summary = $by_id[ $module['id'] ];
			try {
				$hash = hash( 'sha256', $module['attrs'] ? self::canonical_json( $module['attrs'] ) : '{}' );
			} catch ( \Throwable $error ) {
				throw new ContractException( 'evidence_stale', 'Private Divi attributes are invalid.' );
			}
			if ( $summary->name !== $module['name'] || $summary->parent_id !== $module['parent_id'] || $summary->children_ids !== $module['children_ids'] || ! hash_equals( $summary->attrs_hash, $hash ) ) {
				throw new ContractException( 'evidence_stale', 'Private Divi flat modules disagree with bound editor facts.' );
			}
			$seen[ $module['id'] ] = true;
		}
		$state_by_key = array();
		foreach ( $states as $state ) {
			$state_by_key[ $state->owner_id . '|' . $state->key ] = $state;
		}
		$seen_states = array();
		foreach ( $raw_states as $state ) {
			$key = is_array( $state ) && isset( $state['owner_id'], $state['key'] ) ? $state['owner_id'] . '|' . $state['key'] : '';
			if ( ! is_array( $state ) || ! isset( $state['owner_id'], $state['key'], $state['serialized_state'] ) || ! is_array( $state['serialized_state'] ) || ! isset( $state_by_key[ $key ] ) || isset( $seen_states[ $key ] ) ) {
				throw new ContractException( 'evidence_stale', 'Private Divi source/view state is invalid.' );
			}
			$seen_states[ $key ] = true;
			try {
				$hash = hash( 'sha256', self::canonical_json( $state['serialized_state'] ) );
			} catch ( \Throwable $error ) {
				throw new ContractException( 'evidence_stale', 'Private Divi serialized state is invalid.' );
			}
			if ( ! hash_equals( $state_by_key[ $key ]->modules_hash, $hash ) ) {
				throw new ContractException( 'evidence_stale', 'Private Divi serialized state disagrees with bound editor facts.' );
			}
		}
		if ( count( $seen_states ) !== count( $state_by_key ) ) {
			throw new ContractException( 'evidence_stale', 'Private Divi source/view state omits a bound state.' );
		}
		try {
			$source = json_decode(
				json_encode(
					(object) array(
						'flat_modules'         => $value['flat_modules'],
						'state_by_source_view' => $raw_states,
					),
					JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
				),
				false,
				48,
				JSON_THROW_ON_ERROR
			);
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Private Divi graph source is invalid.' );
		}
		( new JsonDecoder( 4194304, 48 ) )->check( $source );
		return $source;
	}

	private static function serialized_modules( array $modules, array $registry_ids, int &$count, int $depth ): array {
		if ( $depth > 12 || ! self::list_array( $modules ) || count( $modules ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Divi serialized module tree exceeds the evidence boundary.' );
		}
		$result = array();
		foreach ( $modules as $module ) {
			if ( ! is_array( $module ) || ! isset( $module['name'] ) || ! is_string( $module['name'] ) || ! isset( $registry_ids[ $module['name'] ] ) || ( isset( $module['attrs'] ) && ! is_array( $module['attrs'] ) ) || ( isset( $module['children'] ) && ! self::list_array( $module['children'] ) ) || array_diff( array_keys( $module ), array( 'name', 'attrs', 'children' ) ) ) {
				throw new ContractException( 'evidence_stale', 'Divi serialized module is invalid.' );
			}
			++$count;
			if ( $count > 500 ) {
				throw new ContractException( 'evidence_stale', 'Divi serialized module tree exceeds the evidence boundary.' );
			}
			$result[] = array(
				'name'     => $module['name'],
				'children' => self::serialized_modules( $module['children'] ?? array(), $registry_ids, $count, $depth + 1 ),
			);
		}
		return $result;
	}

	private static function serialized_matches_flat( array $modules, array $ids, array $flat_by_id ): bool {
		if ( count( $modules ) !== count( $ids ) ) {
			return false;
		}
		foreach ( $modules as $index => $module ) {
			$flat       = $flat_by_id[ $ids[ $index ] ] ?? null;
			$attrs      = $module['attrs'] ?? array();
			$attrs_hash = hash( 'sha256', $attrs ? self::canonical_json( $attrs ) : '{}' );
			if ( null === $flat || $flat->name !== $module['name'] || ! hash_equals( $flat->attrs_hash, $attrs_hash ) || ! self::serialized_matches_flat( $module['children'] ?? array(), $flat->children_ids, $flat_by_id ) ) {
				return false;
			}
		}
		return true;
	}

	private static function list_array( $value ): bool {
		return is_array( $value ) && ( ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
	}

	private static function modal_state( $value, array $flat, array $states ): object {
		$keys = is_array( $value ) ? array_keys( $value ) : array();
		sort( $keys, SORT_STRING );
		if ( ! is_array( $value ) || array( 'active', 'owner_id', 'source_view_key' ) !== $keys || ! is_bool( $value['active'] ) || ( null !== $value['owner_id'] && ! self::runtime_id( $value['owner_id'] ) ) || ( null !== $value['source_view_key'] && ! is_string( $value['source_view_key'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Divi modal evidence is invalid.' );
		}
		$flat_ids  = array_fill_keys( array_map( static fn( object $item ): string => $item->id, $flat ), true );
		$state_map = array();
		foreach ( $states as $state ) {
			$state_map[ $state->owner_id . ':' . $state->key ] = $state;
		}
		if ( $value['active'] && ( null === $value['owner_id'] || ! isset( $flat_ids[ $value['owner_id'] ] ) || null === $value['source_view_key'] || ! isset( $state_map[ $value['owner_id'] . ':' . $value['source_view_key'] ] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Active Divi modal lacks its exact owner/source state.' );
		}
		if ( ! $value['active'] && ( null !== $value['owner_id'] || null !== $value['source_view_key'] ) ) {
			throw new ContractException( 'evidence_stale', 'Inactive Divi modal retains stale authority.' );
		}
		return (object) $value;
	}

	private static function scope_state( array $flat, array $states, string $current_key, string $registry_status ): array {
		$card_owners = array();
		foreach ( $flat as $module ) {
			if ( 'directorist/listings-card' === $module->name ) {
				$card_owners[ $module->id ] = false;
			}
		}
		foreach ( $states as $state ) {
			if ( $state->key === $current_key && array_key_exists( $state->owner_id, $card_owners ) ) {
				$card_owners[ $state->owner_id ] = true;
			}
		}
		$has_card     = (bool) $card_owners;
		$unreconciled = array_filter( $states, static fn( object $item ): bool => ! $item->complete || ( $item->key === $current_key && 'matched' !== $item->active_flat_status ) );
		$status       = ! $has_card && ! $states ? 'not_present' : ( 'complete' === $registry_status && $states && '' !== $current_key && ! in_array( false, $card_owners, true ) && ! $unreconciled ? 'complete' : 'incomplete' );
		return array(
			'status'   => $status,
			'revision' => hash( 'sha256', json_encode( array( 'p5-15-scope-v1', $status, $current_key, $states ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) ),
		);
	}

	private static function tokens( $values ): array {
		if ( true === $values ) {
			return array( '*' ); }
		if ( false === $values ) {
			return array(); }
		if ( ! is_array( $values ) || count( $values ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Divi token evidence is invalid.' ); }
		return ProviderRegistration::tokens( $values );
	}

	private static function view_modes( $values ): array {
		if ( ! is_array( $values ) ) {
			throw new ContractException( 'evidence_stale', 'Divi view-mode evidence is invalid.' );
		}
		$values   = ProviderRegistration::tokens( $values, false );
		$expected = array( 'desktop', 'phone', 'tablet' );
		if ( $values !== $expected ) {
			throw new ContractException( 'evidence_stale', 'Divi view-mode evidence is incomplete.' );
		}
		return $values;
	}

	private static function runtime_ids( array $values ): array {
		if ( count( $values ) > 5000 ) {
			throw new ContractException( 'evidence_stale', 'Divi runtime identity list is too large.' ); }
		foreach ( $values as $value ) {
			if ( ! self::runtime_id( $value ) ) {
				throw new ContractException( 'evidence_stale', 'Divi runtime identity is invalid.' ); }
		}
		if ( count( $values ) !== count( array_unique( $values ) ) ) {
			throw new ContractException( 'evidence_stale', 'Divi runtime identity list contains duplicates.' );
		}
		return array_values( $values );
	}

	private static function runtime_id( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $value ); }
	private static function canonical_post_id( string $reference ): int {
		if ( ! preg_match( '/^post:([1-9][0-9]{0,18})$/D', $reference, $matches ) ) {
			return 0;
		}
		$id = (int) $matches[1];
		return $id > 0 && (string) $id === $matches[1] ? $id : 0;
	}
	private static function state_key( $value, bool $allow_empty ): string {
		if ( ! is_string( $value ) || ( '' === $value && ! $allow_empty ) || ( '' !== $value && 1 !== preg_match( '/^[A-Za-z0-9._-]{1,128}\|(grid|list|map|single)$/D', $value ) ) ) {
			throw new ContractException( 'evidence_stale', 'Divi source/view key is invalid.' ); }
		return $value;
	}
	private static function provider_revision(): string {
		$theme = wp_get_theme();
		return hash( 'sha256', implode( ':', array( $theme->get_stylesheet(), (string) $theme->get( 'Version' ), defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : 'missing' ) ) );
	}
	private static function same_origin( string $origin ): bool {
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $origin );
		return is_array( $expected ) && is_array( $actual ) && strtolower( (string) ( $expected['scheme'] ?? '' ) ) === strtolower( (string) ( $actual['scheme'] ?? '' ) ) && strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) ( $actual['host'] ?? '' ) ) && (int) ( $expected['port'] ?? 0 ) === (int) ( $actual['port'] ?? 0 );
	}
	private static function epoch_key( array $challenge ): string {
		return self::EPOCH_PREFIX . hash( 'sha256', $challenge['site_id'] . ':' . $challenge['user_id'] . ':' . $challenge['document_ref'] ); }
	private static function canonical_json( $value ): string {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value ); }
		if ( is_array( $value ) ) {
			$list = ! $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $list ) {
				ksort( $value, SORT_STRING );
			} foreach ( $value as $key => $child ) {
				$value[ $key ] = json_decode( self::canonical_json( $child ), true, 48, JSON_THROW_ON_ERROR ); }
		}
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}
}
