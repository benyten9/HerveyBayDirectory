<?php
/**
 * One native Elementor opening and its short-lived, server-owned dirty document authority.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Native hook and session contract uses compact methods.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Validation\ContractException;

/** Native boot, save fencing and request-time session checks share one SQL CAS store. */
final class ElementorEditorSession {
	private ElementorEditorSessionStore $store;
	private ?array $boot         = null;
	private ?array $native_save  = null;
	private ?array $save_receipt = null;

	public function __construct( ?ElementorEditorSessionStore $store = null ) {
		$this->store = $store ?? new ElementorEditorSessionStore();
	}

	public function hooks(): void {
		add_action( 'elementor/editor/init', array( $this, 'boot_native' ), 100 );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue' ), 100 );
		add_action( 'elementor/document/before_save', array( $this, 'before_save' ), 10, 2 );
		add_action( 'elementor/editor/after_save', array( $this, 'saved_elements' ), 10, 2 );
		add_action( 'elementor/document/after_save', array( $this, 'after_save' ), 10, 2 );
		add_filter( 'elementor/documents/ajax_save/return_data', array( $this, 'ajax_receipt' ), 100, 2 );
		add_action( 'directorist_ai_elementor_editor_session_expire', array( $this->store, 'expire' ), 10, 1 );
	}

	/** This hook runs after Elementor has selected the initial main document and before it prints its editor. */
	public function boot_native(): void {
		try {
			if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
				return;
			}
			$plugin = \Elementor\Plugin::instance();
			if ( ! is_object( $plugin ) || ! isset( $plugin->documents, $plugin->editor ) || ! method_exists( $plugin->documents, 'get_current' ) || ! method_exists( $plugin->documents, 'get_doc_or_auto_save' ) || ! method_exists( $plugin->editor, 'get_post_id' ) ) {
				return;
			}
			$main      = $plugin->documents->get_current();
			$editor_id = (int) $plugin->editor->get_post_id();
			if ( ! $main instanceof \Elementor\Core\Base\Document || $editor_id < 1 || $main->get_main_id() !== $editor_id || ! $main->is_editable_by_current_user() ) {
				return;
			}
			$source = $plugin->documents->get_doc_or_auto_save( $editor_id );
			if ( ! $source instanceof \Elementor\Core\Base\Document || $source->get_main_id() !== $editor_id || ! $source->is_editable_by_current_user() ) {
				return;
			}
			$context    = Context::current( 'builder', 'ui' );
			$scope      = self::scope( $context, $editor_id );
			$this->boot = $this->open( $context, $editor_id, (int) $source->get_post()->ID, $scope['kind'], $scope['directory_id'], home_url( '/' ) );
		} catch ( \Throwable $error ) {
			$this->boot = null;
		}
	}

	/** Only the initial server-selected native document receives the collector. */
	public function enqueue(): void {
		if ( null === $this->boot ) {
			return;
		}
		$path = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/elementor-editor-snapshot.js';
		if ( ! is_readable( $path ) ) {
			return;
		}
		$handle = 'directorist-ai-elementor-editor-snapshot';
		wp_enqueue_script( $handle, plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/elementor-editor-snapshot.js', array( 'elementor-editor' ), hash_file( 'sha256', $path ), true );
		wp_add_inline_script( $handle, 'window.directoristAIElementorBoot = ' . wp_json_encode( $this->boot ) . ';', 'before' );
	}

	/** Trusted PHP boot is also the explicit entry used by native fixtures. */
	public function open( Context $context, int $main, int $source, string $kind, ?int $directory_id, string $origin ): array {
		$context->assert_execution_identity();
		$revision_parent = wp_is_post_revision( $source );
		if ( $main < 1 || $source < 1 || ! self::same_origin( $origin ) || $main !== (int) ( $revision_parent ? $revision_parent : $source ) ) {
			throw new ContractException( 'unauthorized', 'Elementor editor opening is unavailable.' );
		}
		try {
			$facts = ElementorEditorSnapshotBinding::current_document( $context, 'post:' . $main, $kind, $directory_id );
		} catch ( ContractException $error ) {
			throw new ContractException( 'unauthorized', 'Elementor editor opening is unavailable.' );
		}
		$source_revision = self::fresh_revision( $source );
		$main_revision   = self::fresh_revision( $main );
		$now             = time();
		$record          = array(
			'v'             => 2,
			'site'          => get_current_blog_id(),
			'user'          => (int) $context->actor()->user_id(),
			'main'          => $main,
			'source'        => $source,
			'kind'          => $kind,
			'directory_id'  => $directory_id,
			'document_type' => $facts->document_type,
			'origin'        => $origin,
			'session_hash'  => hash( 'sha256', random_bytes( 32 ) ),
			'epoch'         => hash( 'sha256', random_bytes( 32 ) ),
			'boot_revision' => $source_revision,
			'main_revision' => $main_revision,
			'provider'      => self::provider_fingerprint(),
			'created'       => $now,
			'expires'       => $now + 900,
		);
		$this->store->open( $record );
		if ( ! wp_schedule_single_event( $record['expires'] + 1, 'directorist_ai_elementor_editor_session_expire', array( $record['session_hash'] ) ) ) {
			$this->store->close( $record, 0 );
			$this->store->discard( $record['session_hash'] );
			throw new ContractException( 'storage_unavailable', 'Elementor editor opening is unavailable.' );
		}
		return array(
			'v'             => 2,
			'session'       => $record['session_hash'],
			'epoch'         => $record['epoch'],
			'main'          => $main,
			'source'        => $source,
			'kind'          => $kind,
			'directory_id'  => $directory_id,
			'document_type' => $facts->document_type,
			'generation'    => 0,
			'expires'       => $record['expires'],
			'endpoint'      => rest_url( 'directorist-ai/v1/builder/elementor' ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
		);
	}

	/** Every capture, load and close starts with fresh SQL authority and fresh native source facts. */
	public function current( Context $context, string $session_hash ): array {
		$context->assert_execution_identity();
		list( $record, $pointer ) = $this->store->current( $session_hash );
		if ( $record['site'] !== get_current_blog_id() || $record['user'] !== (int) $context->actor()->user_id() || ! self::same_origin( $record['origin'] ) || ! current_user_can( 'edit_post', $record['main'] ) || $record['provider'] !== self::provider_fingerprint() || ! hash_equals( $pointer['saved_revision'], self::fresh_revision( $record['main'] ) ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening is stale.' );
		}
		$facts = ElementorEditorSnapshotBinding::current_document( $context, 'post:' . $record['main'], $record['kind'], $record['directory_id'] );
		if ( $facts->document_type !== $record['document_type'] || ( 0 === $pointer['generation'] && ! hash_equals( $record['boot_revision'], self::fresh_revision( $record['source'] ) ) ) || ( $record['source'] !== $record['main'] && ! hash_equals( $record['boot_revision'], self::fresh_revision( $record['source'] ) ) ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor source is stale.' );
		}
		return array( $record, $pointer );
	}

	/** Server-side native save start closes old captures even when the save later fails. */
	public function before_save( $document, $data ): void {
		$this->native_save  = null;
		$this->save_receipt = null;
		try {
			if ( ! $document instanceof \Elementor\Core\Base\Document || ! is_array( $data ) || ! $document->is_editable_by_current_user() ) {
				return;
			}
			$main           = (int) $document->get_main_id();
			$source         = (int) $document->get_post()->ID;
			$autosave       = 'autosave' === ( $data['settings']['post_status'] ?? null );
			$context        = Context::current( 'builder', 'ui' );
			list( $record ) = $this->store->owner_current( get_current_blog_id(), (int) $context->actor()->user_id(), $main );
		} catch ( \Throwable $error ) {
			// No current opening can expose dirty evidence on this save request.
			return;
		}
		try {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				list( $record, $pointer ) = $this->store->owner_current( get_current_blog_id(), (int) $context->actor()->user_id(), $main );
				if ( 'closed' === $pointer['phase'] || 'saving' === $pointer['phase'] ) {
					return;
				}
				$paired = $source === $record['source'];
				if ( $paired ) {
					try {
						$this->current( $context, $record['session_hash'] );
					} catch ( ContractException $error ) {
						$paired = false;
					}
				}
				$save_id = 'save-' . bin2hex( random_bytes( 16 ) );
				try {
					$next = $this->store->begin_save( $record, $save_id, $autosave && $paired );
					if ( $autosave && $paired ) {
						$this->native_save = array(
							'record'     => $record,
							'generation' => $next['generation'],
							'save_id'    => $save_id,
							'object_id'  => spl_object_id( $document ),
							'source'     => $source,
							'main'       => $main,
							'data_hash'  => null,
							'verified'   => false,
						);
					}
					return;
				} catch ( ContractException $error ) {
					if ( 'evidence_stale' !== $error->error_code() ) {
						throw $error;
					}
				}
			}
		} catch ( \Throwable $error ) {
			// A failed evidence fence must not interrupt Elementor's native save.
			$this->native_save = null;
		}
		if ( isset( $record, $pointer ) ) {
			try {
				$this->store->close( $record, $pointer['generation'] );
			} catch ( \Throwable $error ) {
				// Fresh source revision checks reject an old binding after the write.
			}
		}
	}

	/** Elementor emits this after the actual sanitized elements metadata update. */
	public function saved_elements( $post_id, $editor_data ): void {
		try {
			if ( null === $this->native_save || (int) $post_id !== $this->native_save['source'] || ! is_array( $editor_data ) ) {
				return;
			}
			$wire = wp_json_encode( $editor_data );
			if ( ! is_string( $wire ) || strlen( $wire ) > 2097152 ) {
				return;
			}
			wp_cache_delete( $post_id, 'post_meta' );
			$stored = get_post_meta( $post_id, '_elementor_data', false );
			if ( is_array( $stored ) && 1 === count( $stored ) && is_string( $stored[0] ) && hash_equals( hash( 'sha256', $wire ), hash( 'sha256', $stored[0] ) ) ) {
				$this->native_save['data_hash'] = hash( 'sha256', $wire );
				$this->native_save['verified']  = true;
			}
		} catch ( \Throwable $error ) {
			$this->native_save = null;
		}
	}

	/** Only a paired, verified native autosave may re-base the current saved revision. */
	public function after_save( $document, $data ): void {
		try {
			if ( null === $this->native_save || ! $document instanceof \Elementor\Core\Base\Document || ! is_array( $data ) || spl_object_id( $document ) !== $this->native_save['object_id'] || ! $this->native_save['verified'] || 'autosave' !== ( $data['settings']['post_status'] ?? null ) || (int) $document->get_post()->ID !== $this->native_save['source'] || (int) $document->get_main_id() !== $this->native_save['main'] ) {
				return;
			}
			$record = $this->native_save['record'];
			if ( $record['provider'] !== self::provider_fingerprint() || $record['source'] !== $record['main'] ) {
				return;
			}
			$revision           = self::fresh_revision( $record['main'] );
			$next               = $this->store->finish_autosave( $record, $this->native_save['generation'], $this->native_save['save_id'], $revision );
			$this->save_receipt = array(
				'session'        => $record['session_hash'],
				'epoch'          => $record['epoch'],
				'generation'     => $next['generation'],
				'main'           => $record['main'],
				'source'         => $record['source'],
				'saved_revision' => $revision,
				'data_hash'      => $this->native_save['data_hash'],
			);
		} catch ( \Throwable $error ) {
			$this->save_receipt = null;
		} finally {
			$this->native_save = null;
		}
	}

	/** Ajax response has no trusted document argument in JS; pair it to this request's exact successful save. */
	public function ajax_receipt( $return_data, $document ) {
		try {
			if ( ! is_array( $return_data ) || ! $document instanceof \Elementor\Core\Base\Document || null === $this->save_receipt || (int) $document->get_main_id() !== $this->save_receipt['main'] || (int) $document->get_post()->ID !== $this->save_receipt['source'] ) {
				return $return_data;
			}
			$context                  = Context::current( 'builder', 'ui' );
			list( $record, $pointer ) = $this->current( $context, $this->save_receipt['session'] );
			if ( $pointer['phase'] !== 'idle' || $pointer['generation'] !== $this->save_receipt['generation'] || $record['epoch'] !== $this->save_receipt['epoch'] || $pointer['saved_revision'] !== $this->save_receipt['saved_revision'] ) {
				return $return_data;
			}
			wp_cache_delete( $record['source'], 'post_meta' );
			$stored = get_post_meta( $record['source'], '_elementor_data', false );
			if ( ! is_array( $stored ) || 1 !== count( $stored ) || ! is_string( $stored[0] ) || ! hash_equals( $this->save_receipt['data_hash'], hash( 'sha256', $stored[0] ) ) ) {
				return $return_data;
			}
			$return_data['directorist_ai_editor_receipt'] = array(
				'v'          => 2,
				'session'    => $record['session_hash'],
				'epoch'      => $record['epoch'],
				'generation' => $pointer['generation'],
				'main'       => $record['main'],
				'source'     => $record['source'],
			);
		} catch ( \Throwable $error ) {
			return $return_data;
		} finally {
			$this->save_receipt = null;
		}
		return $return_data;
	}

	private static function scope( Context $context, int $main ): array {
		$post = get_post( $main );
		if ( ! $post instanceof \WP_Post ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor document is unavailable.' );
		}
		if ( 'page' === $post->post_type ) {
			$kinds = array( 'template:archive_listing', 'page' );
		} elseif ( 'elementor_library' === $post->post_type ) {
			$kinds = array( 'template:single_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' );
		} else {
			throw new ContractException( 'evidence_stale', 'Elementor editor document is unsupported.' );
		}
		foreach ( $kinds as $kind ) {
			$directory_id = self::directory_id( $kind, $main );
			try {
				ElementorEditorSnapshotBinding::current_document( $context, 'post:' . $main, $kind, $directory_id );
				return array(
					'kind'         => $kind,
					'directory_id' => $directory_id,
				);
			} catch ( ContractException $error ) {
				continue;
			}
		}
		throw new ContractException( 'evidence_stale', 'Elementor editor document is unsupported.' );
	}

	private static function directory_id( string $kind, ?int $main = null ): ?int {
		if ( 'template:single_listing' !== $kind ) {
			return null;
		}
		if ( null === $main ) {
			throw new ContractException( 'evidence_stale', 'Elementor directory scope is unavailable.' );
		}
		$type = get_post_meta( $main, '_elementor_template_type', true );
		return is_string( $type ) && preg_match( '/^directorist-single-listing-directory-([1-9][0-9]*)$/D', $type, $match ) && strlen( $match[1] ) < 19 ? (int) $match[1] : null;
	}

	private static function same_origin( string $origin ): bool {
		$expected = wp_parse_url( home_url( '/' ) );
		$actual   = wp_parse_url( $origin );
		return is_array( $expected ) && is_array( $actual ) && strtolower( (string) ( $expected['scheme'] ?? '' ) ) === strtolower( (string) ( $actual['scheme'] ?? '' ) ) && strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) ( $actual['host'] ?? '' ) ) && (int) ( $expected['port'] ?? 0 ) === (int) ( $actual['port'] ?? 0 );
	}

	private static function fresh_revision( int $post_id ): string {
		clean_post_cache( $post_id );
		wp_cache_delete( $post_id, 'post_meta' );
		return ElementorEditorSnapshotBinding::persisted_revision( $post_id );
	}

	private static function provider_fingerprint(): string {
		$classes = array( self::class, ElementorEditorSessionStore::class, ElementorEditorSnapshotBinding::class, '\\Elementor\\Core\\Base\\Document', '\\DirectoristElementor\\AI\\ElementorCatalogProvider', '\\DirectoristElementor\\AI\\NativeElementorGraphProvider', '\\DirectoristElementor\\AI\\ElementorSemanticManifest', '\\DirectoristElementor\\AI\\NativeElementorActiveScopeComparator', '\\DirectoristElementor\\AI\\ElementorGraphSemanticRules' );
		$parts   = array( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null, defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null );
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				throw new ContractException( 'unsupported_dependency', 'Elementor source is unavailable.' );
			}
			$file = ( new \ReflectionClass( $class ) )->getFileName();
			if ( ! is_string( $file ) || ! is_readable( $file ) || filesize( $file ) > 2097152 ) {
				throw new ContractException( 'unsupported_dependency', 'Elementor source is unavailable.' );
			}
			$parts[] = hash_file( 'sha256', $file );
		}
		$collector = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . 'inc/assets/js/elementor-editor-snapshot.js';
		if ( ! is_readable( $collector ) || filesize( $collector ) > 2097152 ) {
			throw new ContractException( 'unsupported_dependency', 'Elementor collector source is unavailable.' );
		}
		$parts[] = hash_file( 'sha256', $collector );
		$parts[] = \DirectoristElementor\AI\ElementorSemanticManifest::fingerprint();
		return hash( 'sha256', wp_json_encode( $parts ) );
	}
}
