<?php
/**
 * Native editor entry points and verified document context.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Builder\ElementorEditorSnapshotBinding;

/** Keeps authoring beside the current native document. */
final class EditorAssistant {
	/** Attach editor entry points. */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor' ), 120 );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'elementor' ), 120 );
		add_action( 'wp_enqueue_scripts', array( $this, 'divi' ), 120 );
	}

	/** Register the authenticated context route. */
	public function register(): void {
		( new EditorConversationHistory() )->register();
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-application',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in(),
				'callback'            => array( $this, 'application' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-context',
			array(
				'methods'             => 'GET',
				'permission_callback' => static fn(): bool => is_user_logged_in() && ( current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ) ),
				'callback'            => array( $this, 'context' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-selection',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in() && ( current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ) ),
				'callback'            => array( $this, 'selection' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/preflight',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => is_user_logged_in() && ( current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' ) ),
				'callback'            => array( $this, 'preflight' ),
			)
		);
	}

	/**
	 * Record an owner's bounded browser outcome as diagnostics, never apply authority.
	 *
	 * @param \WP_REST_Request $request Exact owned run, candidate and outcome.
	 * @return \WP_REST_Response|\WP_Error Non-authorizing diagnostic acknowledgement.
	 * @throws \RuntimeException Invalid or unavailable owned outcome, converted below.
	 */
	public function application( \WP_REST_Request $request ) {
		try {
			if ( ! wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) {
				throw new \RuntimeException( 'Sign in again before reporting this editor outcome.' );
			}
			$input = ( new \DirectoristAIPro\Validation\JsonDecoder( 4096, 8 ) )->decode( $request->get_body() );
			$keys  = $input instanceof \stdClass ? array_keys( get_object_vars( $input ) ) : array();
			sort( $keys );
			if ( array( 'candidate_id', 'capture_id', 'code', 'outcome', 'run_id' ) !== $keys || ! is_string( $input->run_id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->run_id ) || ! is_string( $input->candidate_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $input->candidate_id ) || ! is_string( $input->capture_id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->capture_id ) || ! in_array( $input->outcome, array( 'applied', 'failed' ), true ) || ! in_array( $input->code, array( 'native_applied', 'native_state_changed', 'session_unavailable', 'candidate_mismatch', 'native_result_unavailable', 'native_apply_failed', 'native_command_failed', 'native_page_settings_changed', 'native_normalization_mismatch' ), true ) || ( 'applied' === $input->outcome ) !== ( 'native_applied' === $input->code ) ) {
				throw new \RuntimeException( 'The editor outcome is invalid.' );
			}
			global $wpdb;
			$db    = new \DirectoristAIPro\Storage\Database( $wpdb );
			$owner = 'user:' . get_current_user_id();
			$db->transaction(
				static function ( $db ) use ( $input, $owner ): void {
					$rows       = $db->rows( 'SELECT r.id,r.checkpoint_json,c.context_json FROM ' . $db->table( 'runs' ) . ' r INNER JOIN ' . $db->table( 'conversations' ) . " c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND r.owner_ref=%s AND c.profile='builder' AND c.erasure_requested=0 AND r.state='succeeded' FOR UPDATE", array( $input->run_id, $owner ) );
					$row        = $rows[0] ?? null;
					$checkpoint = json_decode( $row['checkpoint_json'] ?? '{}' );
					$context    = json_decode( $row['context_json'] ?? '{}' );
					$effects    = null;
					foreach ( $checkpoint->receipt->targets ?? array() as $target ) {
						if ( ( $target->effects->candidate_id ?? null ) === $input->candidate_id ) {
							$effects = $target->effects; }
					}
					$original = $context->builder_editor_origin->original_document->native_id ?? '';
					$can_edit = preg_match( '/^post:([1-9][0-9]*)$/D', $original, $match ) ? current_user_can( 'edit_post', (int) $match[1] ) : current_user_can( 'edit_theme_options' );
					if ( null === $row || ! $can_edit || ( $checkpoint->review->operation_id ?? null ) !== $input->candidate_id || ( $effects->output_mode ?? null ) !== 'editor_candidate' || ( $effects->capture_id ?? null ) !== $input->capture_id || ( $effects->original_document->native_id ?? null ) !== $original ) {
						throw new \RuntimeException( 'This editor outcome is unavailable.' );
					}
					$events = $db->table( 'events' );
					if ( $db->scalar( "SELECT id FROM $events WHERE run_id=%d AND type='diagnostic' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.data.kind'))='editor_application' AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.data.code'))=%s LIMIT 1", array( (int) $row['id'], $input->code ) ) ) {
						return; }
					( new \DirectoristAIPro\Runtime\RunStore( $db ) )->event_in_transaction(
						(int) $row['id'],
						'diagnostic',
						(object) array(
							'kind'                => 'editor_application',
							'candidate_id'        => $input->candidate_id,
							'outcome'             => $input->outcome,
							'code'                => $input->code,
							'reported_by_browser' => true,
						)
					);
				}
			);
			return rest_ensure_response( array( 'recorded' => true ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'editor_outcome_unavailable', 'This editor outcome could not be recorded.', array( 'status' => 403 ) );
		}
	}

	/**
	 * Check the current saved layout before offering a paid generation.
	 *
	 * @param \WP_REST_Request $request Exact document, native selection and scope.
	 * @throws \DirectoristAIPro\Validation\ContractException Invalid saved document or scope.
	 */
	public function preflight( \WP_REST_Request $request ) {
		$restore_timer = static function (): void {};
		try {
			$input = ( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 16 ) )->decode( $request->get_body() );
			if ( ! $input instanceof \stdClass || ! ( $input->document ?? null ) instanceof \stdClass || ! ( $input->scope ?? null ) instanceof \stdClass || ( null !== ( $input->selection ?? null ) && ! $input->selection instanceof \stdClass ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'A saved document and layout scope are required.' );
			}
			$document = $input->document;
			if ( ! is_string( $document->native_id ?? null ) || ! is_string( $document->builder ?? null ) || ! is_string( $document->native_kind ?? null ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'The saved document identity is invalid.' );
			}
			$probe = new \WP_REST_Request( 'GET' );
			$probe->set_param( 'builder', $document->builder );
			if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) ) {
				$probe->set_param( 'post_id', (int) $match[1] );
			} else {
				$probe->set_param( 'template_ref', $document->native_id );
			}
			$resolved = $this->context( $probe );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$target = $resolved->get_data()['currentDocument'];
			if ( $document->builder !== $target['builder'] || $document->native_kind !== $target['nativeKind'] || $document->native_id !== $target['nativeId'] ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'scope_conflict', 'The document family changed. Refresh this editor context.' );
			}
			if ( ! empty( $target['directoryId'] ) && ( $input->scope->directory_id ?? null ) !== (string) $target['directoryId'] ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'scope_conflict', 'Use the directory assigned to this native template.' );
			}
			if ( $resolved->get_data()['requiresSave'] ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'source_unsaved', 'Save this document in its native editor before preparing a draft.' );
			}
			$intent = $input->intent ?? 'refine-layout';
			if ( ! in_array( $intent, array( 'create-layout', 'refine-layout', 'refine-area', 'page-content', 'change-directories' ), true ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'The requested layout action is invalid.' );
			}
			self::assert_preparation_scope( $input->scope );
			if ( null !== ( $input->selection ?? null ) ) {
				\DirectoristAIPro\Builder\BuilderEditorSelection::assert_source( $input->selection, $document );
			}
			$restore_timer = self::native_preparation_timer();
			$result   = ( new BuilderPreflight() )->inspect( $document, $input->scope, $input->selection ?? null, $intent );
			$response = rest_ensure_response( $result );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			$details = array( 'status' => 422 );
			if ( 'stale_version' === $error->error_code() ) {
				$details['reason'] = 'stale_version';
			}
			return new \WP_Error( 'builder_preflight_unavailable', $error->getMessage(), $details );
		} finally {
			$restore_timer();
		}
	}

	/**
	 * Resolve a selected native area to its actual saved graph scope.
	 *
	 * @param \WP_REST_Request $request Native document and selection.
	 * @throws \DirectoristAIPro\Validation\ContractException Invalid native context.
	 */
	public function selection( \WP_REST_Request $request ) {
		$restore_timer = static function (): void {};
		try {
			$input = json_decode( $request->get_body() );
			( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 12 ) )->check( $input );
			if ( ! $input instanceof \stdClass || ! ( $input->document ?? null ) instanceof \stdClass || ! ( $input->selection ?? null ) instanceof \stdClass || ! ( $input->scope ?? null ) instanceof \stdClass ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'A native document and selection are required.' );
			}
			$document = $input->document;
			if ( ! is_string( $document->native_id ?? null ) || ! is_string( $document->builder ?? null ) || ! is_string( $document->native_kind ?? null ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Native document identity is invalid.' );
			}

			$probe = new \WP_REST_Request( 'GET' );
			$probe->set_param( 'builder', $document->builder ?? '' );
			if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ) {
				$probe->set_param( 'post_id', (int) $match[1] );
			} else {
				$probe->set_param( 'template_ref', $document->native_id ?? '' );
			}
			$resolved = $this->context( $probe );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$target = $resolved->get_data()['currentDocument'];
			$native = (object) array(
				'builder'     => $target['builder'],
				'native_kind' => $target['nativeKind'],
				'native_id'   => $target['nativeId'],
			);
			if ( ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $document ) !== ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $native ) ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'scope_conflict', 'Selection differs from the current native document family.' );
			}
			\DirectoristAIPro\Builder\BuilderEditorSelection::assert_source( $input->selection, $native );
			self::assert_preparation_scope( $input->scope );
			$restore_timer = self::native_preparation_timer();
			$context      = \DirectoristAIPro\Capabilities\Context::current( 'builder', 'ui' );
			$integrations = new \DirectoristAIPro\Builder\IntegrationRegistry();
			do_action( 'directorist_ai_pro_register_builder_providers', $integrations );
			$integrations->freeze();
			$environment = new \DirectoristAIPro\Builder\CatalogEnvironmentReader( $integrations );
			$providers   = new \DirectoristAIPro\Builder\BuilderGraphRegistry();
			do_action( 'directorist_ai_pro_register_builder_graph_providers', $providers );
			$providers->freeze();
			$reader = $providers->provider( $native->builder );
			if ( null === $reader ) {
				throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_dependency', 'Native selection reader is unavailable.' );
			}
			$directories = new \DirectoristAIPro\Builder\DirectoristDirectoryFactsReader( new \DirectoristAIPro\Domain\DirectoristInspector() );
			$scope       = $input->scope;
			$facts       = null === ( $scope->directory_id ?? null ) ? null : $directories->read( $context, (int) $scope->directory_id );
			$inspection  = new \DirectoristAIPro\Builder\BuilderGraphInspectionRequest( $context, $native, $scope, array(), $facts, null, $environment );
			$graph       = $reader->inspect( $inspection )->graph();
			if ( 'gutenberg' === $native->builder ) {
				$input->selection = \DirectoristAIPro\Builder\BuilderEditorSelection::saved_gutenberg_selection( $input->selection, $native );
			}
			$node = \DirectoristAIPro\Builder\BuilderEditorSelection::resolve_node( $input->selection, $graph );
			// Derive card/view/directory scope from the native node, never its display label.
			$scope              = $node->scope;
			$directory_required = null === $scope->directory_id && (bool) array_filter(
				$graph->omissions,
				static fn( $omission ): bool => 'directory_scope_unselected' === ( $omission->code ?? null )
			);
			if ( null !== $scope->directory_id ) {
				$directories->read( $context, (int) $scope->directory_id );
			}
			\DirectoristAIPro\Builder\BuilderEditorSelection::assert_source( $input->selection, $native );
			$response = rest_ensure_response(
				array(
					'selection'          => $input->selection,
					'scope'              => $scope,
					'component'          => $node->native_id,
					'directory_required' => $directory_required,
				)
			);
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return new \WP_Error( 'selection_unavailable', $error->getMessage(), array( 'status' => 422 ) );
		} finally {
			$restore_timer();
		}
	}

	/** Reject malformed untrusted scope before extending any native read allowance. */
	private static function assert_preparation_scope( object $scope ): void {
		$keys = array_keys( get_object_vars( $scope ) );
		sort( $keys );
		if ( array( 'breakpoint_id', 'directory_id', 'interaction', 'view' ) !== $keys || ( null !== $scope->directory_id && ( ! is_string( $scope->directory_id ) || ! preg_match( '/^[1-9][0-9]{0,127}$/D', $scope->directory_id ) ) ) || ! in_array( $scope->view, array( null, 'grid', 'list', 'map', 'single' ), true ) || ! in_array( $scope->interaction, array( null, 'normal', 'hover' ), true ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'invalid_input', 'Builder graph inspection scope is invalid.' );
		}
		if ( null !== $scope->breakpoint_id ) {
			\DirectoristAIPro\Builder\GraphVersion::identifier( $scope->breakpoint_id );
		}
	}

	/**
	 * Extend only this authenticated native preparation, after its input checks.
	 * The caller restores the prior PHP timer on success and exception paths.
	 */
	private static function native_preparation_timer(): \Closure {
		$previous = (int) ini_get( 'max_execution_time' );
		if ( $previous <= 0 || $previous >= 120 ) {
			return static function (): void {};
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hosts may forbid setting their process timer.
		if ( ! function_exists( 'set_time_limit' ) || ! @set_time_limit( 120 ) ) {
			throw new \DirectoristAIPro\Validation\ContractException( 'unsupported_dependency', 'This host requires a dedicated background worker for AI requests.' );
		}
		return static function () use ( $previous ): void {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Restore the original timer for subsequent unrelated callbacks.
			@set_time_limit( $previous );
		};
	}

	/** Load only the native page or Site Editor. */
	public function block_editor(): void {
		$screen = get_current_screen();
		global $post;
		if ( $screen && 'site-editor' === $screen->base && current_user_can( 'edit_theme_options' ) ) {
			$this->enqueue( 'gutenberg', 0, 'site-editor' );
		} elseif ( $screen && 'post' === $screen->base && $post instanceof \WP_Post && 'page' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$this->enqueue( 'gutenberg', $post->ID, 'page-editor' );
		}
	}

	/** Load in the actual Elementor editor. */
	public function elementor(): void {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return; }
		$plugin = \Elementor\Plugin::instance();
		$id     = isset( $plugin->editor ) ? (int) $plugin->editor->get_post_id() : 0;
		if ( $id > 0 && current_user_can( 'edit_post', $id ) ) {
			$this->enqueue( 'elementor', $id, 'elementor-editor' ); }
	}

	/** Load once in the outer Divi editor. */
	public function divi(): void {
		if ( ! is_user_logged_in() || ! isset( $_GET['et_fb'] ) || '1' !== $_GET['et_fb'] || 'Divi' !== get_template() ) {
			return; }
		// Mount in the outer editor; never repeat it inside the canvas iframe.
		if ( isset( $_GET['app_window'] ) && '1' === $_GET['app_window'] ) {
			return; }
		$id = (int) get_queried_object_id();
		if ( $id > 0 && current_user_can( 'edit_post', $id ) ) {
			$this->enqueue( 'divi', $id, 'divi-editor' ); }
	}

	/**
	 * Enqueue the shared native assistant.
	 *
	 * @param string $builder Active builder.
	 * @param int    $post_id Current native post.
	 * @param string $surface Native editor surface.
	 */
	private function enqueue( string $builder, int $post_id, string $surface ): void {
		$relative = 'apps/dashboard/build/';
		$path     = plugin_dir_path( DIRECTORIST_AI_PRO_FILE ) . $relative;
		if ( ! is_file( $path . 'editor-assistant.asset.php' ) || ! is_file( $path . 'editor-assistant.js' ) ) {
			return; }
		$asset        = require $path . 'editor-assistant.asset.php';
		$dependencies = array_merge( $asset['dependencies'], array( 'wp-data', 'wp-plugins', 'wp-editor' ) );
		if ( 'gutenberg' === $builder ) {
			$dependencies[] = 'wp-edit-post'; }
		wp_enqueue_script( 'directorist-ai-editor-assistant', plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . $relative . 'editor-assistant.js', array_unique( $dependencies ), $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'directorist-ai-editor-assistant', plugin_dir_url( DIRECTORIST_AI_PRO_FILE ) . $relative . 'editor-assistant.css', array( 'wp-components' ), $asset['version'] );
		wp_add_inline_script(
			'directorist-ai-editor-assistant',
			'window.directoristAIEditorAssistant=' . wp_json_encode(
				array(
					'builder'        => $builder,
					'postId'         => $post_id,
					'surface'        => $surface,
					'apiPath'        => '/directorist-ai/v1',
					'apiUrl'         => rest_url( 'directorist-ai/v1' ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'userId'         => get_current_user_id(),
					'siteId'         => get_current_blog_id(),
					'isMultisite'    => is_multisite(),
					'initialContext' => $this->initial_context( $builder, $post_id ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Seed display-only context in the authenticated editor, without startup API calls.
	 *
	 * @param string $builder Installed native editor.
	 * @param int    $post_id Current authorized source.
	 * @return array|null Bounded visible context and history, with no capture credentials.
	 */
	private function initial_context( string $builder, int $post_id ): ?array {
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}
		try {
			$request = new \WP_REST_Request( 'GET' );
			$request->set_param( 'builder', $builder );
			$request->set_param( 'post_id', $post_id );
			$response = $this->context( $request );
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$data = $response->get_data();
			if ( 'page' !== ( $data['currentDocument']['nativeKind'] ?? null ) ) {
				return $data;
			}
			$request->set_param( 'native_kind', 'page' );
			$request->set_param( 'native_id', 'post:' . $post_id );
			$request->set_param( 'history_scope', 'page' );
			$history = ( new EditorConversationHistory() )->read( $request );
			if ( is_wp_error( $history ) ) {
				return $data;
			}
			$data['initialHistory']  = $history->get_data();
			$data['initialMessages'] = array();
			global $wpdb;
			$api = new \DirectoristAIPro\Runtime\ConversationApi( new \DirectoristAIPro\Storage\Database( $wpdb ) );
			foreach ( array_slice( $data['initialHistory']['tasks'], -10 ) as $task ) {
				$read = new \WP_REST_Request( 'GET' );
				$read->set_param( 'id', $task['conversationId'] );
				$read->set_param( 'limit', 20 );
				$read->set_param( 'tail', '1' );
				$page = $api->history( $read );
				if ( ! is_wp_error( $page ) && is_array( $page['messages'] ?? null ) ) {
					$messages                            = $data['initialMessages'];
					$messages[ $task['conversationId'] ] = $page['messages'];
					if ( strlen( wp_json_encode( $messages ) ) > 65536 ) {
						break;
					}
					$data['initialMessages'] = $messages;
				}
			}
			return $data;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Resolve browser identity against current integration-owned document contracts.
	 *
	 * @param \WP_REST_Request $request Native document identity.
	 */
	public function context( \WP_REST_Request $request ) {
		return $this->resolve_context( $request, false );
	}

	/**
	 * Read the same native identity without constructing unused editor UI choices.
	 *
	 * @param \WP_REST_Request $request Current native document reference.
	 * @return array|\WP_Error Exact authorized current document, or original error.
	 */
	public function current_document( \WP_REST_Request $request ) {
		return $this->resolve_context( $request, true );
	}

	/**
	 * Share every native identity and source-availability check between readers.
	 *
	 * @param \WP_REST_Request $request Current document reference.
	 * @param bool             $identity_only Internal read mode, never a REST parameter.
	 * @return array|\WP_REST_Response|\WP_Error Native identity or complete editor UI.
	 */
	private function resolve_context( \WP_REST_Request $request, bool $identity_only ) {
		$builder = (string) $request->get_param( 'builder' );
		$id      = absint( $request->get_param( 'post_id' ) );
		$ref     = (string) $request->get_param( 'template_ref' );
		if ( ! in_array( $builder, array( 'gutenberg', 'elementor', 'divi' ), true ) ) {
			return new \WP_Error( 'invalid_builder', 'Unsupported editor.', array( 'status' => 400 ) ); }
		$post = $id > 0 ? get_post( $id ) : null;
		if ( $id > 0 && ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $id ) ) ) {
			return new \WP_Error( 'document_unavailable', 'The current document is unavailable.', array( 'status' => 403 ) ); }
		$pages   = $post instanceof \WP_Post && 'page' === $post->post_type ? array(
			array(
				'id'     => $id,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			),
		) : array();
		$targets = apply_filters( 'directorist_ai_pro_builder_source_targets', array(), $pages );
		$target  = null;
		foreach ( is_array( $targets ) ? $targets : array() as $candidate ) {
			if ( is_array( $candidate ) && ( $candidate['builder'] ?? null ) === $builder && ( $candidate['nativeId'] ?? null ) === ( '' !== $ref ? $ref : 'post:' . $id ) ) {
				$target = $candidate;
				break; }
		}
		// Native template lists omit private drafts. Resolve only the exact open
		// draft through the integration's receipt-bound family reader.
		if ( null === $target && 'gutenberg' === $builder && current_user_can( 'edit_theme_options' ) && preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):(directorist-ai-(?:draft|working)-[a-f0-9]{24})$/D', $ref, $parts ) && get_stylesheet() === $parts[2] ) {
			$family   = apply_filters( 'directorist_ai_pro_gutenberg_native_template_context', null, $ref );
			$template = get_block_template( $parts[2] . '//' . $parts[3], $parts[1] );
			if ( is_array( $family ) && is_string( $family['native_kind'] ?? null ) && array_key_exists( 'directory_id', $family ) && $template instanceof \WP_Block_Template && $template->id === $parts[2] . '//' . $parts[3] && $parts[1] === $template->type && ! empty( $template->wp_id ) && current_user_can( 'edit_post', (int) $template->wp_id ) && \DirectoristAIPro\Builder\GutenbergEditorSnapshotBinding::matches_saved_document( $ref, $family['native_kind'], $family['directory_id'], null ) ) {
				$target = array(
					'builder'     => $builder,
					'nativeKind'  => $family['native_kind'],
					'nativeId'    => $ref,
					'title'       => $template->title,
					'status'      => $template->status,
					'directoryId' => $family['directory_id'],
				);
			}
		}
		if ( 'elementor' === $builder && $post instanceof \WP_Post ) {
			$type      = get_post_meta( $id, '_elementor_template_type', true );
			$directory = is_string( $type ) && preg_match( '/^directorist-single-listing-directory-([1-9][0-9]*)$/D', $type, $match ) ? (int) $match[1] : null;
			$kinds     = 'page' === $post->post_type ? array( 'template:archive_listing', 'page' ) : array( 'template:single_listing', 'template:taxonomy_category', 'template:taxonomy_location', 'template:taxonomy_tag', 'template:author_listing', 'template:home_search' );
			$target    = null;
			foreach ( $kinds as $kind ) {
				if ( ElementorEditorSnapshotBinding::matches_saved_document( $id, $kind, 'template:single_listing' === $kind ? $directory : null ) ) {
					$target = array(
						'builder'     => $builder,
						'nativeKind'  => $kind,
						'nativeId'    => 'post:' . $id,
						'title'       => $post->post_title,
						'status'      => $post->post_status,
						'directoryId' => 'template:single_listing' === $kind ? $directory : null,
					);
					break;
				}
			}
		}
		if ( 'divi' === $builder && $post instanceof \WP_Post ) {
			$facts = apply_filters( 'directorist_ai_divi_document_context', null, $id );
			if ( is_array( $facts ) && is_string( $facts['document_kind'] ?? null ) ) {
				$target = array(
					'builder'     => $builder,
					'nativeKind'  => $facts['document_kind'],
					'nativeId'    => 'post:' . $id,
					'title'       => $post->post_title,
					'status'      => $post->post_status,
					'directoryId' => $facts['directory_id'] ?? null,
				);
			}
		}
		if ( null === $target ) {
			return new \WP_Error( 'unsupported_document', 'This document has no supported Directorist layout context. Open a page or an assigned Directorist template. Other Theme Builder template families are not supported yet.', array( 'status' => 422 ) ); }
		if ( $identity_only ) {
			try {
				// The full context also validates current saved-source availability.
				// Retain that check even though this caller consumes no source stamp.
				\DirectoristAIPro\Builder\BuilderEditorSelection::source_stamp(
					(object) array(
						'builder'     => $builder,
						'native_kind' => $target['nativeKind'],
						'native_id'   => $target['nativeId'],
					)
				);
			} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
				return new \WP_Error( 'document_unavailable', $error->getMessage(), array( 'status' => 422 ) );
			}
			return $target;
		}
		// A single existing provider suggests context; an ambiguous document asks instead.
		$suggested_directory = $post instanceof \WP_Post ? $this->suggested_directory( $builder, $post ) : null;
		$terms               = get_terms(
			array(
				'taxonomy'   => defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types',
				'hide_empty' => false,
				'number'     => 100,
			)
		);
		$directories         = is_wp_error( $terms ) ? array() : array_map(
			static fn( \WP_Term $term ): array => array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			),
			$terms
		);
		$default_directory   = function_exists( 'directorist_get_default_directory' ) ? (int) directorist_get_default_directory() : 0;
		if ( ! in_array( $default_directory, array_column( $directories, 'id' ), true ) ) {
			$default_directory = null;
		}
		try {
			$selection_stamp = \DirectoristAIPro\Builder\BuilderEditorSelection::source_stamp(
				(object) array(
					'builder'     => $builder,
					'native_kind' => $target['nativeKind'],
					'native_id'   => $target['nativeId'],
				)
			);
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return new \WP_Error( 'document_unavailable', $error->getMessage(), array( 'status' => 422 ) );
		}
		$response = rest_ensure_response(
			array(
				'apiPath'              => '/directorist-ai/v1',
				'apiUrl'               => rest_url( 'directorist-ai/v1' ),
				'userId'               => get_current_user_id(),
				'siteId'               => get_current_blog_id(),
				'isMultisite'          => is_multisite(),
				'builderTargets'       => array( $target ),
				'builderDirectories'   => $directories,
				'currentDocument'      => $target,
				'suggestedDirectoryId' => $suggested_directory,
				'defaultDirectoryId'   => $default_directory,
				'sourceViews'          => $post instanceof \WP_Post ? $this->source_views( $builder, $post ) : array(),
				'directorySourceViews' => $post instanceof \WP_Post ? $this->directory_source_views( $builder, $post ) : new \stdClass(),
				'requiresSave'         => 'auto-draft' === ( $target['status'] ?? null ),
				'shared'               => 'page' !== $target['nativeKind'],
				'selectionSourceStamp' => $selection_stamp,
				'templateFamily'       => $target['nativeKind'],
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Derive a hint from named native listing providers, never arbitrary attributes.
	 *
	 * @param string   $builder Active builder.
	 * @param \WP_Post $post Current saved document.
	 */
	private function suggested_directory( string $builder, \WP_Post $post ): ?int {
		$ids     = array();
		$nodes   = 0;
		$collect = static function ( $value ) use ( &$ids ): void {
			foreach ( is_array( $value ) ? $value : array( $value ) as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$ids[ (int) $id ] = true; }
			}
		};
		if ( 'elementor' === $builder ) {
			$raw = get_post_meta( $post->ID, '_elementor_data', true );
			if ( ! is_string( $raw ) || strlen( $raw ) > 2097152 ) {
				return null; }
			$walk = function ( array $items ) use ( &$walk, &$nodes, $collect ): void {
				foreach ( $items as $item ) {
					if ( ++$nodes > 2000 || ! is_array( $item ) ) {
						return; }
					if ( in_array( $item['elType'] ?? $item['widgetType'] ?? null, array( 'directorist_listings_loop', 'directorist_homepage_search' ), true ) ) {
						$settings = $item['settings'] ?? array();
						$collect( $settings['directory_type_ids'] ?? $settings['active_directory_type_id'] ?? null );
					}
					$walk( is_array( $item['elements'] ?? null ) ? $item['elements'] : array() );
				}
			};
			$data = json_decode( $raw, true, 50 );
			$walk( is_array( $data ) ? $data : array() );
		} elseif ( strlen( $post->post_content ) <= 2097152 ) {
			$walk = function ( array $blocks ) use ( &$walk, &$nodes, $collect ): void {
				foreach ( $blocks as $block ) {
					if ( ++$nodes > 2000 ) {
						return; }
					$name  = $block['blockName'] ?? '';
					$attrs = $block['attrs'] ?? array();
					if ( in_array( $name, array( 'directorist-gb-integration/listings-loop', 'directorist-gb-integration/homepage-search-loop' ), true ) ) {
						$collect( $attrs['directoryTypeIds'] ?? $attrs['directoryTypeId'] ?? null );
					} elseif ( in_array( $name, array( 'directorist/listings-archive', 'directorist/homepage-search' ), true ) ) {
						$slug = $attrs['directory_type']['innerContent']['desktop']['value'] ?? null;
						$term = is_string( $slug ) ? get_term_by( 'slug', $slug, defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types' ) : null;
						if ( $term instanceof \WP_Term ) {
							$collect( $term->term_id ); }
					}
					$walk( $block['innerBlocks'] ?? array() );
				}
			};
			$walk( parse_blocks( $post->post_content ) );
		}
		if ( $nodes > 2000 || 1 !== count( $ids ) ) {
			return null; }
		$id   = (int) array_key_first( $ids );
		$term = get_term( $id, defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types' );
		return $term instanceof \WP_Term ? $id : null;
	}

	/**
	 * Offer existing view regions; layout purpose is separate from source scope.
	 *
	 * @param string   $builder Current builder.
	 * @param \WP_Post $post Saved source.
	 */
	private function source_views( string $builder, \WP_Post $post ): array {
		$views   = array();
		$count   = 0;
		$collect = static function ( $view ) use ( &$views ): void {
			if ( in_array( $view, array( 'grid', 'list', 'map' ), true ) ) {
				$views[ $view ] = true; }
		};
		if ( 'elementor' === $builder ) {
			$raw = get_post_meta( $post->ID, '_elementor_data', true );
			if ( ! is_string( $raw ) || strlen( $raw ) > 2097152 ) {
				return array(); }
			$data = json_decode( $raw, true, 50 );
			$walk = function ( array $items ) use ( &$walk, &$count, $collect ): void {
				foreach ( $items as $item ) {
					if ( ++$count > 2000 || ! is_array( $item ) ) {
						return; }
					if ( 'directorist_listings_loop' === ( $item['elType'] ?? null ) ) {
						$collect( $item['settings']['view_type'] ?? null ); }
					$walk( is_array( $item['elements'] ?? null ) ? $item['elements'] : array() );
				}
			};
			$walk( is_array( $data ) ? $data : array() );
		} elseif ( 'gutenberg' === $builder && strlen( $post->post_content ) <= 2097152 ) {
			$walk = function ( array $blocks ) use ( &$walk, &$count, $collect ): void {
				foreach ( $blocks as $block ) {
					if ( ++$count > 2000 ) {
						return; }
					if ( 'directorist-gb-integration/listing-card-template' === ( $block['blockName'] ?? null ) ) {
						$attrs = $block['attrs'] ?? array();
						foreach ( array(
							'gridTemplate' => 'grid',
							'listTemplate' => 'list',
							'mapTemplate'  => 'map',
						) as $key => $view ) {
							if ( is_string( $attrs[ $key ] ?? null ) ) {
								$collect( $view ); }
						}
						foreach ( is_array( $attrs['directoryTemplates'] ?? null ) ? $attrs['directoryTemplates'] : array() as $templates ) {
							foreach ( is_array( $templates ) ? $templates : array() as $view => $markup ) {
								if ( is_string( $markup ) ) {
									$collect( $view ); }
							}
						}
					}
					$walk( $block['innerBlocks'] ?? array() );
				}
			};
			$walk( parse_blocks( $post->post_content ) );
		}
		return $count > 2000 ? array() : array_keys( $views );
	}

	/**
	 * Read each directory's active views independently from other page sections.
	 *
	 * @param string   $builder Current builder.
	 * @param \WP_Post $post Saved or captured native source.
	 */
	private function directory_source_views( string $builder, \WP_Post $post ): object {
		$views   = array();
		$count   = 0;
		$collect = static function ( $ids, $view ) use ( &$views ): void {
			if ( ! in_array( $view, array( 'grid', 'list', 'map' ), true ) ) {
				return;
			}
			foreach ( is_array( $ids ) ? $ids : array( $ids ) as $id ) {
				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$views[ (string) (int) $id ][ $view ] = true;
				}
			}
		};
		if ( 'elementor' === $builder ) {
			$raw = get_post_meta( $post->ID, '_elementor_data', true );
			if ( ! is_string( $raw ) || strlen( $raw ) > 2097152 ) {
				return new \stdClass();
			}
			$walk = function ( array $items ) use ( &$walk, &$count, $collect ): void {
				foreach ( $items as $item ) {
					if ( ++$count > 2000 || ! is_array( $item ) ) {
						return;
					}
					if ( in_array( $item['elType'] ?? '', array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) || in_array( $item['widgetType'] ?? '', array( 'directorist_listings_loop', 'directorist_homepage_search_loop' ), true ) ) {
						$settings = $item['settings'] ?? array();
						$collect( $settings['directory_type_ids'] ?? $settings['active_directory_type_id'] ?? $settings['default_directory_type_id'] ?? array(), $settings['view_type'] ?? 'grid' );
					}
					$walk( is_array( $item['elements'] ?? null ) ? $item['elements'] : array() );
				}
			};
			$data = json_decode( $raw, true, 50 );
			$walk( is_array( $data ) ? $data : array() );
		} elseif ( in_array( $builder, array( 'gutenberg', 'divi' ), true ) && strlen( $post->post_content ) <= 2097152 ) {
			$walk = function ( array $blocks ) use ( &$walk, &$count, $collect ): void {
				foreach ( $blocks as $block ) {
					if ( ++$count > 2000 || ! is_array( $block ) ) {
						return;
					}
					if ( 'directorist-gb-integration/listing-card-template' === ( $block['blockName'] ?? null ) ) {
						$attrs = $block['attrs'] ?? array();
						$collect( $attrs['directory_type_id'] ?? array(), $attrs['view'] ?? 'grid' );
					}
					if ( in_array( $block['blockName'] ?? null, array( 'directorist-gb-integration/listings-loop', 'directorist-gb-integration/homepage-search-loop' ), true ) ) {
						$attrs = $block['attrs'] ?? array();
						$collect( $attrs['directoryTypeIds'] ?? $attrs['directory_type_ids'] ?? $attrs['directoryTypeId'] ?? array(), $attrs['defaultView'] ?? 'grid' );
					}
					if ( in_array( $block['blockName'] ?? null, array( 'directorist/listings-archive', 'directorist/homepage-search-loop' ), true ) ) {
						$slug = $block['attrs']['directory_type']['innerContent']['desktop']['value'] ?? null;
						foreach ( is_string( $slug ) ? explode( ',', $slug ) : array() as $name ) {
							$term = get_term_by( 'slug', trim( $name ), defined( 'ATBDP_DIRECTORY_TYPE' ) ? ATBDP_DIRECTORY_TYPE : 'atbdp_listing_types' );
							if ( $term instanceof \WP_Term ) {
								$collect( $term->term_id, 'grid' );
							}
						}
					}
					$walk( $block['innerBlocks'] ?? array() );
				}
			};
			$walk( parse_blocks( $post->post_content ) );
		}
		return $count > 2000 ? new \stdClass() : (object) array_map( 'array_keys', $views );
	}
}
