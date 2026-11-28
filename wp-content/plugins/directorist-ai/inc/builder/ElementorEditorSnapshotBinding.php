<?php
/**
 * Server-bound Elementor editor manager evidence.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Compact immutable evidence boundary.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** One current actor/site/document/editor epoch; inspect evidence only. */
final class ElementorEditorSnapshotBinding implements CatalogEditorSnapshot {
	public const CONTRACT_REVISION = 'elementor-editor-v2';
	private const CHALLENGE_PREFIX = 'dai_el_editor_challenge_';
	private const BINDING_PREFIX   = 'dai_el_editor_binding_';
	private const EPOCH_PREFIX     = 'dai_el_editor_epoch_';

	private array $challenge;
	private object $snapshot;
	private string $fingerprint;
	private static ?BuilderDraftResultApi $private_draft_results = null;
	private static string $private_draft_prefix                  = '';

	public static function challenge( Context $context, string $document_kind, ?int $directory_id, string $document_ref, string $origin, string $session_hash, int $expected_generation ): object {
		$context->assert_execution_identity();
		if ( ! ProviderRegistration::valid_token( $document_kind ) || ( null !== $directory_id && $directory_id < 1 ) || ! preg_match( '/^post:(\d+)$/D', $document_ref, $matches ) || ! self::same_origin( $origin ) || ! current_user_can( 'edit_post', (int) $matches[1] ) || ! self::document_matches( (int) $matches[1], $document_kind, $directory_id ) ) {
			throw new ContractException( 'unauthorized', 'Elementor editor challenge is unavailable.' );
		}
		list( $record, $pointer ) = ( new ElementorEditorSession() )->current( $context, $session_hash );
		if ( $record['main'] !== (int) $matches[1] || $record['kind'] !== $document_kind || ( null !== $record['directory_id'] && $record['directory_id'] !== $directory_id ) || $record['origin'] !== $origin || $pointer['generation'] !== $expected_generation ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening differs from the initial document.' );
		}
		$document_type = self::document_type( (int) $matches[1] );
		$provider_lane = self::provider_lane_signature();
		$scope_state   = ( new ElementorScopedStorageInspector() )->inspect( (int) $matches[1] );
		$id            = 'el-' . bin2hex( random_bytes( 16 ) );
		$pending       = ( new ElementorEditorSessionStore() )->begin( $record, $expected_generation, $id );
		$challenge     = array(
			'binding_id'           => $id,
			'site_id'              => get_current_blog_id(),
			'user_id'              => (int) $context->actor()->user_id(),
			'document_kind'        => $document_kind,
			'directory_id'         => $directory_id,
			'document_ref'         => $document_ref,
			'document_type'        => $document_type,
			'origin'               => $origin,
			'epoch'                => $record['epoch'],
			'session_hash'         => $record['session_hash'],
			'session_generation'   => $pending['generation'],
			'base_native_revision' => $pointer['saved_revision'],
			'provider_lane'        => $provider_lane,
			'expires_at'           => time() + 300,
			'contract_revision'    => self::CONTRACT_REVISION,
			'scope_revision'       => $scope_state->revision,
			'scope_status'         => $scope_state->status,
		);
		set_transient( self::CHALLENGE_PREFIX . hash( 'sha256', $id ), $challenge, 300 );
		set_transient( self::epoch_key( $challenge ), $challenge['epoch'], 900 );
		return (object) array(
			'binding_id'        => $id,
			'nonce'             => wp_create_nonce( 'directorist_ai_elementor_editor_snapshot:' . $id ),
			'expires_at'        => $challenge['expires_at'],
			'document_type'     => $document_type,
			'provider_lane'     => $provider_lane['id'],
			'contract_revision' => self::CONTRACT_REVISION,
			'scope_status'      => $scope_state->status,
			'session'           => $record['session_hash'],
			'generation'        => $pending['generation'],
		);
	}

	public static function issue( Context $context, array $data ): self {
		$context->assert_execution_identity();
		$required = array( 'allowed_elements', 'binding_id', 'breakpoints', 'contract_revision', 'document_type', 'elements', 'nonce', 'origin' );
		$keys     = array_keys( $data );
		sort( $keys, SORT_STRING );
		$with_document   = $required;
		$with_document[] = 'document_elements';
		sort( $with_document, SORT_STRING );
		if ( ( $keys !== $required && $keys !== $with_document ) || ! is_string( $data['binding_id'] ) || ! ProviderRegistration::valid_token( $data['binding_id'] ) || ! is_string( $data['nonce'] ) || ! is_string( $data['origin'] ) || self::CONTRACT_REVISION !== $data['contract_revision'] ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor evidence is invalid.' );
		}
		$key       = self::CHALLENGE_PREFIX . hash( 'sha256', $data['binding_id'] );
		$challenge = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $challenge ) || $challenge['binding_id'] !== $data['binding_id'] || (int) $challenge['site_id'] !== get_current_blog_id() || (int) $challenge['user_id'] !== (int) $context->actor()->user_id() || (int) $challenge['expires_at'] <= time() || ! hash_equals( $challenge['origin'], $data['origin'] ) || ! self::same_origin( $data['origin'] ) || ! wp_verify_nonce( $data['nonce'], 'directorist_ai_elementor_editor_snapshot:' . $data['binding_id'] ) || ! preg_match( '/^post:(\d+)$/D', $challenge['document_ref'], $matches ) || ! current_user_can( 'edit_post', (int) $matches[1] ) || ! self::document_matches( (int) $matches[1], $challenge['document_kind'], $challenge['directory_id'] ) || ! hash_equals( $challenge['base_native_revision'], self::document_revision( (int) $matches[1] ) ) || $challenge['provider_lane'] !== self::provider_lane_signature() || ! is_string( $data['document_type'] ) || ! hash_equals( $challenge['document_type'], $data['document_type'] ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor challenge is stale or consumed.' );
		}
		list( $session_record, $session_pointer ) = ( new ElementorEditorSession() )->current( $context, $challenge['session_hash'] );
		if ( $session_record['epoch'] !== $challenge['epoch'] || $session_pointer['generation'] !== $challenge['session_generation'] || $session_pointer['phase'] !== 'pending' || $session_pointer['capture_id'] !== $challenge['binding_id'] ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor challenge is stale or consumed.' );
		}
		( new ElementorEditorSessionStore() )->claim( $session_record, $challenge['session_generation'], $challenge['binding_id'] );
		$elements = self::elements( $data['elements'] );
		$payload  = (object) array(
			'allowed_elements'     => self::allowed_elements( $data['allowed_elements'] ),
			'base_native_revision' => $challenge['base_native_revision'],
			'breakpoints'          => self::breakpoints( $data['breakpoints'] ),
			'contract_revision'    => self::CONTRACT_REVISION,
			'document_ref'         => $challenge['document_ref'],
			'document_type'        => self::token( $challenge['document_type'] ),
			'elements'             => $elements,
			'epoch'                => $challenge['epoch'],
			'session_hash'         => $challenge['session_hash'],
			'session_generation'   => $challenge['session_generation'],
			'provider_lane'        => $challenge['provider_lane']['id'],
			'provider_revision'    => $challenge['provider_lane']['revision'],
			'scope_revision'       => $challenge['scope_revision'],
			'scope_status'         => $challenge['scope_status'],
		);
		if ( array_key_exists( 'document_elements', $data ) ) {
			if ( ! is_array( $data['document_elements'] ) || count( $data['document_elements'] ) > 1000 || ( $data['document_elements'] && array_keys( $data['document_elements'] ) !== range( 0, count( $data['document_elements'] ) - 1 ) ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor dirty document tree is invalid.' );
			}
			try {
				$encoded_tree = json_encode( $data['document_elements'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
			} catch ( \JsonException $error ) {
				throw new ContractException( 'evidence_stale', 'Elementor dirty document tree is invalid JSON.' );
			}
			if ( strlen( $encoded_tree ) > 2097152 ) {
				throw new ContractException( 'needs_expansion', 'Elementor dirty document tree exceeds its byte bound.' );
			}
			try {
				$tree = json_decode( $encoded_tree, false, 40, JSON_THROW_ON_ERROR );
			} catch ( \JsonException $error ) {
				throw new ContractException( 'needs_expansion', 'Elementor dirty document tree exceeds its nesting bound.' );
			}
			( new JsonDecoder( 2097152, 40 ) )->check( (object) array( 'elements' => $tree ) );
			$payload->document_elements = $data['document_elements'];
		}
		$payload           = json_decode( json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 40, JSON_THROW_ON_ERROR );
		$payload->revision = hash( 'sha256', json_encode( $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
		( new JsonDecoder( 2097152, 40 ) )->check( $payload );
		$binding = new self( $challenge, $payload );
		$stored  = set_transient(
			self::BINDING_PREFIX . hash( 'sha256', $data['binding_id'] ),
			array(
				'challenge' => $challenge,
				'snapshot'  => $payload,
			),
			300
		);
		if ( ! $stored ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor binding is unavailable.' );
		}
		$verified = get_transient( self::BINDING_PREFIX . hash( 'sha256', $data['binding_id'] ) );
		if ( ! is_array( $verified ) || ! is_array( $verified['challenge'] ?? null ) || ! ( $verified['snapshot'] ?? null ) instanceof \stdClass || $verified['challenge'] !== $challenge || ( new self( $verified['challenge'], $verified['snapshot'] ) )->fingerprint() !== $binding->fingerprint() ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor binding is unavailable.' );
		}
		( new ElementorEditorSessionStore() )->ready( $session_record, $challenge['session_generation'], $challenge['binding_id'], $binding->fingerprint() );
		return $binding;
	}

	public static function load( Context $context, string $binding_id ): self {
		$stored = get_transient( self::BINDING_PREFIX . hash( 'sha256', $binding_id ) );
		if ( ! is_array( $stored ) || ! isset( $stored['challenge'], $stored['snapshot'] ) || ! is_array( $stored['challenge'] ) || ! $stored['snapshot'] instanceof \stdClass || $stored['challenge']['binding_id'] !== $binding_id || (int) $stored['challenge']['site_id'] !== get_current_blog_id() || (int) $stored['challenge']['user_id'] !== (int) $context->actor()->user_id() ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor binding is unavailable.' );
		}
		$binding = new self( $stored['challenge'], $stored['snapshot'] );
		$binding->assert_current( $context, 'elementor' );
		return $binding;
	}

	private function __construct( array $challenge, object $snapshot ) {
		$this->challenge   = $challenge;
		$this->snapshot    = $snapshot;
		$this->fingerprint = hash( 'sha256', json_encode( array( $challenge['binding_id'], $challenge['site_id'], $challenge['user_id'], $challenge['document_kind'], $challenge['directory_id'], $challenge['expires_at'], $snapshot ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}

	public function assert_current( Context $context, string $builder ): void {
		$context->assert_execution_identity();
		if ( ! is_string( $this->challenge['session_hash'] ?? null ) || ! is_int( $this->challenge['session_generation'] ?? null ) || ! is_string( $this->snapshot->session_hash ?? null ) || ! is_int( $this->snapshot->session_generation ?? null ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session is unavailable.' );
		}
		list( $record, $pointer ) = ( new ElementorEditorSession() )->current( $context, $this->challenge['session_hash'] );
		if ( 'ready' !== $pointer['phase'] || $pointer['capture_id'] !== $this->challenge['binding_id'] || $pointer['generation'] !== $this->challenge['session_generation'] || $pointer['fingerprint'] !== $this->fingerprint || $this->snapshot->session_hash !== $record['session_hash'] || $this->snapshot->session_generation !== $pointer['generation'] ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor evidence is stale.' );
		}
		$current_epoch = get_transient( self::epoch_key( $this->challenge ) );
		if ( 'elementor' !== $builder || get_current_blog_id() !== (int) $this->challenge['site_id'] || (int) $context->actor()->user_id() !== (int) $this->challenge['user_id'] || time() >= (int) $this->challenge['expires_at'] || ! is_string( $current_epoch ) || ! hash_equals( $this->snapshot->epoch, $current_epoch ) || ! self::same_origin( $this->challenge['origin'] ) || ! preg_match( '/^post:(\d+)$/D', $this->challenge['document_ref'], $matches ) || ! current_user_can( 'edit_post', (int) $matches[1] ) || ! self::document_matches( (int) $matches[1], $this->challenge['document_kind'], $this->challenge['directory_id'] ) || ! hash_equals( $this->challenge['base_native_revision'], self::document_revision( (int) $matches[1] ) ) || $this->challenge['provider_lane'] !== self::provider_lane_signature() || ! hash_equals( $this->challenge['document_type'], self::document_type( (int) $matches[1] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor evidence is stale.' );
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
		return json_decode( json_encode( $this->snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 40, JSON_THROW_ON_ERROR ); }

	/** Native post, Theme Builder conditions and exact saved revision for graph readers. */
	public static function current_document( Context $context, string $document_ref, string $kind, ?int $directory_id ): object {
		$context->assert_execution_identity();
		if ( ! preg_match( '/^post:(\d+)$/D', $document_ref, $matches ) || ! current_user_can( 'edit_post', (int) $matches[1] ) || ! self::document_matches( (int) $matches[1], $kind, $directory_id ) ) {
			throw new ContractException( 'not_found', 'Elementor graph document is unavailable.' );
		}
		return (object) array(
			'post_id'       => (int) $matches[1],
			'document_type' => self::document_type( (int) $matches[1] ),
			'revision'      => self::document_revision( (int) $matches[1] ),
		);
	}

	/** Exact persisted source revision used by the native session's fresh-read fence. */
	public static function persisted_revision( int $post_id ): string {
		return self::document_revision( $post_id );
	}

	/** Share the source reader's exact native document and condition binding at staging. */
	public static function matches_saved_document( int $post_id, string $kind, ?int $directory_id ): bool {
		return current_user_can( 'edit_post', $post_id ) && self::document_matches( $post_id, $kind, $directory_id );
	}

	private static function document_matches( int $post_id, string $kind, ?int $directory_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$working = \DirectoristAIPro\UI\EditorWorkingCopy::family_context( $post_id );
		if ( null !== $working && 'elementor' === $working['document']->builder && $kind === $working['document']->native_kind && $working['source_id'] > 0 && $working['source_id'] !== $post_id && $post->post_type === get_post_type( $working['source_id'] ) && self::document_type( $post_id ) === self::document_type( $working['source_id'] ) && empty( get_post_meta( $post_id, '_elementor_conditions', true ) ) ) {
			return self::document_matches( $working['source_id'], $kind, $directory_id );
		}
		if ( 'page' === $kind ) {
			return 'page' === $post->post_type;
		}
		if ( 'template:archive_listing' === $kind ) {
			return 'page' === $post->post_type && function_exists( 'directorist_get_page_id' ) && $post_id === (int) directorist_get_page_id( 'listings' );
		}
		if ( 'elementor_library' !== $post->post_type ) {
			return false;
		}
		$type = self::document_type( $post_id );
		if ( ! self::template_runtime_available( $type ) ) {
			return false;
		}
		$exact = array(
			'template:taxonomy_category' => 'directorist-listing-category-archive',
			'template:taxonomy_location' => 'directorist-listing-location-archive',
			'template:taxonomy_tag'      => 'directorist-listing-tag-archive',
			'template:author_listing'    => 'directorist-listing-author-archive',
			'template:home_search'       => 'directorist-home-search-result',
		);
		if ( 'template:single_listing' === $kind ) {
			return null !== $directory_id && 'directorist-single-listing-directory-' . $directory_id === $type && self::template_conditions_match( $post_id, $kind, $directory_id );
		}
		return isset( $exact[ $kind ] ) && hash_equals( $exact[ $kind ], $type ) && self::template_conditions_match( $post_id, $kind, $directory_id );
	}

	private static function template_runtime_available( string $type ): bool {
		if ( 'free' === self::provider_lane_signature()['id'] || ! class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::instance();
		return isset( $plugin->documents ) && method_exists( $plugin->documents, 'get_document_type' ) && false !== $plugin->documents->get_document_type( $type, '' );
	}

	private static function template_conditions_match( int $post_id, string $kind, ?int $directory_id, int $depth = 0 ): bool {
		if ( $depth >= 8 ) {
			return false;
		}
		// Approved component drafts have an unassigned working copy as their
		// immediate parent. Follow its verified lineage for family recognition;
		// this does not inherit conditions or authorize a template assignment.
		$working = \DirectoristAIPro\UI\EditorWorkingCopy::family_context( $post_id );
		if ( null !== $working && 'elementor' === $working['document']->builder && $kind === $working['document']->native_kind && $working['source_id'] > 0 && $working['source_id'] !== $post_id && empty( get_post_meta( $post_id, '_elementor_conditions', true ) ) && self::document_type( $post_id ) === self::document_type( $working['source_id'] ) ) {
			return self::template_conditions_match( $working['source_id'], $kind, $directory_id, $depth + 1 );
		}
		$roots    = array(
			'template:taxonomy_category' => array( 'include', 'directorist_listing_category_archives' ),
			'template:taxonomy_location' => array( 'include', 'directorist_listing_location_archives' ),
			'template:taxonomy_tag'      => array( 'include', 'directorist_listing_tag_archives' ),
			'template:author_listing'    => array( 'include', 'directorist_listing_author_archive' ),
			'template:home_search'       => array( 'include', 'directorist_home_search_result' ),
		);
		$expected = 'template:single_listing' === $kind && null !== $directory_id
			? array( 'include', 'directorist_listing', 'directorist_listing_directory', (string) $directory_id )
			: ( $roots[ $kind ] ?? array() );
		if ( ! $expected ) {
			return false;
		}
		$theme_builder = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		$manager       = is_object( $theme_builder ) && method_exists( $theme_builder, 'get_conditions_manager' ) ? $theme_builder->get_conditions_manager() : null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_condition' ) || ! $manager->get_condition( $expected[1] ) ) {
			return false;
		}
		$conditions = get_post_meta( $post_id, '_elementor_conditions', true );
		if ( empty( $conditions ) ) {
			return self::owned_template_family( $post_id, $kind, $directory_id, $depth );
		}
		if ( ! is_array( $conditions ) ) {
			return false;
		}
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				$condition = array_values( array_filter( explode( '/', trim( $condition, '/' ) ), 'strlen' ) );
			}
			if ( is_array( $condition ) && array_map( 'strval', $condition ) === $expected ) {
				return true;
			}
		}
		return false;
	}

	/** An unassigned private copy inherits only its owned, committed source family. */
	private static function owned_template_family( int $post_id, string $kind, ?int $directory_id, int $depth ): bool {
		if ( $depth >= 8 || get_post_meta( $post_id, '_directorist_ai_elementor_draft_kind', true ) !== $kind ) {
			return false;
		}
		$post        = get_post( $post_id );
		$parent_id   = (int) get_post_meta( $post_id, '_directorist_ai_elementor_draft_source_page', true );
		$parent      = get_post( $parent_id );
		$operation   = get_post_meta( $post_id, '_directorist_ai_elementor_draft_operation', true );
		$source_hash = get_post_meta( $post_id, '_directorist_ai_elementor_draft_source_hash', true );
		// Native family recognition reads current source identity; historical content
		// equality belongs to proposal/apply fences, not template classification.
		$current_hash = apply_filters( 'directorist_ai_elementor_saved_source_hash', null, $parent_id );
		if ( ! $post instanceof \WP_Post || ! $parent instanceof \WP_Post || $parent_id === $post_id || $parent_id !== (int) $post->post_parent || 'elementor_library' !== $parent->post_type || ! current_user_can( 'edit_post', $parent_id ) || self::document_type( $post_id ) !== self::document_type( $parent_id ) || ! is_string( $current_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $current_hash ) || ! is_string( $source_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $source_hash ) || ! is_string( $operation ) ) {
			return false;
		}
		global $wpdb;
		// Repeated native final fences must not open a new MySQL connection each time.
		// Reuse only the connection/API; every read still checks current owned state.
		if ( null === self::$private_draft_results || self::$private_draft_prefix !== $wpdb->prefix ) {
			self::$private_draft_results = new BuilderDraftResultApi( new \DirectoristAIPro\Storage\Database( $wpdb ) );
			self::$private_draft_prefix  = $wpdb->prefix;
		}
		return self::$private_draft_results->has_elementor_family_receipt( $post_id, $kind, $parent_id, $directory_id ) && self::template_conditions_match( $parent_id, $kind, $directory_id, $depth + 1 );
	}

	private static function document_type( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ContractException( 'evidence_stale', 'Elementor document is unavailable.' );
		}
		if ( 'elementor_library' === $post->post_type ) {
			return self::token( get_post_meta( $post_id, '_elementor_template_type', true ) );
		}
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id, false );
			if ( is_object( $document ) && method_exists( $document, 'get_name' ) ) {
				return self::token( $document->get_name() );
			}
		}
		return 'wp-' . $post->post_type;
	}

	private static function document_revision( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new ContractException( 'evidence_stale', 'Elementor document is unavailable.' );
		}
		$data = get_post_meta( $post_id, '_elementor_data', true );
		return hash( 'sha256', json_encode( array( $post->ID, $post->post_type, $post->post_modified_gmt, hash( 'sha256', is_string( $data ) ? $data : wp_json_encode( $data ) ), get_post_meta( $post_id, '_elementor_version', true ), get_post_meta( $post_id, '_elementor_template_type', true ), get_post_meta( $post_id, '_elementor_conditions', true ) ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	private static function elements( $elements ): array {
		if ( ! is_array( $elements ) || count( $elements ) > 150 ) {
			throw new ContractException( 'evidence_stale', 'Elementor element evidence is invalid.' );
		}
		$result = array();
		$ids    = array();
		foreach ( $elements as $element ) {
			$element_keys = is_array( $element ) ? array_keys( $element ) : array();
			sort( $element_keys, SORT_STRING );
			if ( ! is_array( $element ) || array( 'child_types', 'controls', 'id', 'kind' ) !== $element_keys || ! is_string( $element['id'] ) || ! ProviderRegistration::valid_token( $element['id'] ) || isset( $ids[ $element['id'] ] ) || ! in_array( $element['kind'], array( 'element', 'widget' ), true ) || ! is_array( $element['controls'] ) || ! is_array( $element['child_types'] ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor element evidence is invalid.' );
			}
			$live = self::live_element( $element['id'] );
			if ( null === $live || $live[1] !== $element['kind'] || ! method_exists( $live[0], 'get_controls' ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor element evidence is not registered by the server.' );
			}
			$ids[ $element['id'] ] = true;
			$controls              = self::controls( $element['controls'], $live[0]->get_controls() );
			$child_types           = ProviderRegistration::tokens( $element['child_types'] );
			foreach ( $child_types as $child_type ) {
				if ( null === self::live_element( $child_type ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor child evidence is not registered by the server.' );
				}
			}
			$result[] = (object) array(
				'child_types' => $child_types,
				'controls'    => $controls,
				'id'          => $element['id'],
				'kind'        => $element['kind'],
			);
		}
		usort( $result, static fn( object $a, object $b ): int => $a->id <=> $b->id );
		return $result;
	}

	private static function controls( array $controls, array $server_controls ): object {
		if ( count( $controls ) > 500 ) {
			throw new ContractException( 'evidence_stale', 'Elementor control evidence is invalid.' );
		}
		$result  = new \stdClass();
		$allowed = array( 'condition', 'conditions', 'default', 'label', 'options', 'responsive', 'selectors', 'type' );
		ksort( $controls, SORT_STRING );
		foreach ( $controls as $id => $control ) {
			if ( ! is_string( $id ) || ! self::valid_control_id( $id ) || ! is_array( $control ) || ! isset( $server_controls[ $id ] ) || ! is_array( $server_controls[ $id ] ) || array_diff( array_keys( $control ), $allowed ) || ! isset( $control['type'] ) || ! is_string( $control['type'] ) || ! ProviderRegistration::valid_token( $control['type'] ) || ( isset( $control['label'] ) && ( ! is_string( $control['label'] ) || strlen( $control['label'] ) > 500 ) ) || ( isset( $control['responsive'] ) && ! is_bool( $control['responsive'] ) ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor control evidence is invalid.' );
			}
			foreach ( array( 'condition', 'conditions', 'options', 'selectors' ) as $map ) {
				if ( isset( $control[ $map ] ) && ( ! is_array( $control[ $map ] ) || count( $control[ $map ] ) > 200 ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor control evidence is invalid.' );
				}
			}
			foreach ( $control as $field => $value ) {
				$server_value = self::json_safe( $server_controls[ $id ][ $field ] ?? null );
				if ( ! array_key_exists( $field, $server_controls[ $id ] ) || self::canonical_json( self::json_safe( $value ) ) !== self::canonical_json( $server_value ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor control evidence differs from the server registration.' );
				}
			}
			$normalized = json_decode( json_encode( $control, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ), false, 20, JSON_THROW_ON_ERROR );
			( new JsonDecoder( 262144, 20 ) )->check( $normalized );
			$result->{$id} = $normalized;
		}
		return $result;
	}

	private static function live_element( string $identity ): ?array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		$plugin = \Elementor\Plugin::instance();
		if ( isset( $plugin->elements_manager ) && method_exists( $plugin->elements_manager, 'get_element_types' ) ) {
			$element = $plugin->elements_manager->get_element_types( $identity );
			if ( is_object( $element ) && method_exists( $element, 'get_name' ) && $identity === (string) $element->get_name() ) {
				return array( $element, 'element' );
			}
		}
		if ( isset( $plugin->widgets_manager ) && method_exists( $plugin->widgets_manager, 'get_widget_types' ) ) {
			$widget = $plugin->widgets_manager->get_widget_types( $identity );
			if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && $identity === (string) $widget->get_name() ) {
				return array( $widget, 'widget' );
			}
		}
		return null;
	}

	private static function json_safe( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$result = array();
		foreach ( $value as $key => $child ) {
			$safe = self::json_safe( $child );
			if ( null !== $safe || null === $child ) {
				$result[ $key ] = $safe;
			}
		}
		return $result;
	}

	private static function canonical_json( $value ): string {
		if ( is_array( $value ) && ( ! $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
			ksort( $value, SORT_STRING );
		}
		if ( is_array( $value ) ) {
			$value = array_map( static fn( $child ) => json_decode( self::canonical_json( $child ), true, 32, JSON_THROW_ON_ERROR ), $value );
		}
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	}

	private static function breakpoints( $items ): array {
		if ( ! is_array( $items ) || count( $items ) > 20 ) {
			throw new ContractException( 'evidence_stale', 'Elementor breakpoint evidence is invalid.' );
		}
		$result = array();
		$ids    = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'direction', 'id', 'is_enabled', 'label', 'value' ) ) || ! isset( $item['id'], $item['value'] ) || ! is_string( $item['id'] ) || ! ProviderRegistration::valid_token( $item['id'] ) || isset( $ids[ $item['id'] ] ) || ! is_numeric( $item['value'] ) || (float) $item['value'] < 0 || (float) $item['value'] > 10000 || ( isset( $item['label'] ) && ( ! is_string( $item['label'] ) || strlen( $item['label'] ) > 100 ) ) || ( isset( $item['direction'] ) && ! in_array( $item['direction'], array( 'max', 'min' ), true ) ) || ( isset( $item['is_enabled'] ) && ! is_bool( $item['is_enabled'] ) ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor breakpoint evidence is invalid.' );
			}
			$ids[ $item['id'] ] = true;
			$result[]           = (object) array_filter(
				array(
					'direction'  => $item['direction'] ?? null,
					'id'         => $item['id'],
					'is_enabled' => $item['is_enabled'] ?? null,
					'label'      => $item['label'] ?? null,
					'value'      => (float) $item['value'],
				),
				static fn( $value ): bool => null !== $value
			);
		}
		usort( $result, static fn( object $left, object $right ): int => $left->id <=> $right->id );
		return $result;
	}

	/** Validate the native editor's complete registry without widening provider declarations. */
	private static function allowed_elements( $values ): array {
		if ( true === $values ) {
			return array( '*' );
		}
		if ( false === $values ) {
			return array();
		}
		if ( ! is_array( $values ) || count( $values ) > 500 || ( $values && array_keys( $values ) !== range( 0, count( $values ) - 1 ) ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor allowlist is invalid.' );
		}
		$seen = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || ! ProviderRegistration::valid_token( $value ) || isset( $seen[ $value ] ) ) {
				throw new ContractException( 'evidence_stale', 'Elementor allowlist is invalid.' );
			}
			$seen[ $value ] = true;
		}
		sort( $values, SORT_STRING );
		return $values;
	}

	private static function token( $value ): string {
		if ( ! is_string( $value ) || ! ProviderRegistration::valid_token( $value ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor document type is invalid.' );
		}
		return $value;
	}

	private static function valid_control_id( string $value ): bool {
		return strlen( $value ) <= 256 && 1 === preg_match( '/^_*[A-Za-z0-9][A-Za-z0-9._:\/\[\]-]*$/D', $value );
	}

	private static function provider_lane_signature(): array {
		if ( defined( 'IS_PRO_ELEMENTS' ) ) {
			$id = 'pro-elements';
		} elseif ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			$id = 'elementor-pro';
		} else {
			$id = 'free';
		}
		return array(
			'id'       => $id,
			'revision' => hash( 'sha256', implode( ':', array( $id, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'missing', defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : 'missing' ) ) ),
		);
	}

	private static function same_origin( string $origin ): bool {
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $origin );
		return is_array( $expected ) && is_array( $actual ) && strtolower( (string) ( $expected['scheme'] ?? '' ) ) === strtolower( (string) ( $actual['scheme'] ?? '' ) ) && strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) ( $actual['host'] ?? '' ) ) && (int) ( $expected['port'] ?? 0 ) === (int) ( $actual['port'] ?? 0 );
	}

	private static function epoch_key( array $challenge ): string {
		return self::EPOCH_PREFIX . hash( 'sha256', $challenge['site_id'] . ':' . $challenge['user_id'] . ':' . $challenge['document_ref'] );
	}
}
