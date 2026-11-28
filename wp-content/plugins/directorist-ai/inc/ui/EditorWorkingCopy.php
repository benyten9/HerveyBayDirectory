<?php
/**
 * Native editor working copies and approved scoped results.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Builder\BuilderEditorSelection;
use DirectoristAIPro\Builder\BuilderDraftResultApi;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.FunctionComment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Narrow native editor boundary.

/** Copies current editor state without saving or assigning the source document. */
final class EditorWorkingCopy {

	private const META           = '_directorist_ai_editor_working_source';
	public const INTERNAL_STATUS = 'dai-editor-work';
	private const SESSION_META   = '_directorist_ai_editor_session';
	private const EXPIRES_META   = '_directorist_ai_editor_expires';
	/**
	 * Optional isolated runtime connection; native posts remain site-owned.
	 *
	 * @var Database|null
	 */
	private ?Database $db;

	public function __construct( ?Database $db = null ) {
		$this->db = $db;
	}

	public function hooks(): void {
		add_action( 'init', array( $this, 'status' ) );
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	/** Internal snapshots have no normal page list, public URL or native publication. */
	public function status(): void {
		register_post_status( self::INTERNAL_STATUS, array( 'label' => 'AI editor snapshot', 'internal' => true, 'public' => false, 'private' => false, 'protected' => true, 'exclude_from_search' => true, 'show_in_admin_all_list' => false, 'show_in_admin_status_list' => false ) );
		if ( ! has_filter( 'pre_get_block_template', array( self::class, 'internal_template' ) ) ) {
			add_filter( 'pre_get_block_template', array( self::class, 'internal_template' ), 10, 3 );
		}
		if ( ! has_filter( 'wp_revisions_to_keep', array( self::class, 'internal_revisions' ) ) ) {
			add_filter( 'wp_revisions_to_keep', array( self::class, 'internal_revisions' ), 10, 2 );
		}
	}

	public function register(): void {
		( new \DirectoristAIPro\Builder\BuilderEditorCandidateApi() )->register();
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-working-copy',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'capture' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-area-result/(?P<operation_id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'result' ),
			)
		);
	}

	public function capture( \WP_REST_Request $request ) {
		try {
			$input = ( new JsonDecoder( 3145728, 48 ) )->decode( $request->get_body() );
			if ( $input instanceof \stdClass && property_exists( $input, 'editor_session' ) ) {
				return $this->capture_editor( $input );
			}
			if ( ! $input instanceof \stdClass || ! ( $input->document ?? null ) instanceof \stdClass || ! ( $input->selection ?? null ) instanceof \stdClass || ! ( $input->snapshot ?? null ) instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'A current document, selected area and native editor snapshot are required.' );
			}
			$document = $input->document;
			$context  = $this->context( $document );
			$target   = $context['currentDocument'];
			$expected = (object) array( 'builder' => $target['builder'], 'native_kind' => $target['nativeKind'], 'native_id' => $target['nativeId'] );
			if ( ( new JsonCanonicalizer() )->encode( $expected ) !== ( new JsonCanonicalizer() )->encode( $document ) ) {
				throw new ContractException( 'scope_conflict', 'The snapshot does not belong to this native document.' );
			}
			$selection = (object) array(
				'native_node_id' => $input->selection->native_node_id ?? null,
				'slot_path'      => $input->selection->slot_path ?? null,
				'label'          => $input->selection->label ?? '',
				'source_stamp'   => $input->source_stamp ?? null,
			);
			BuilderEditorSelection::assert_source( $selection, $document );
			// Installed integrations validate and serialize their own native state.
			$copy = apply_filters( 'directorist_ai_pro_editor_working_copy', null, $document, $input->snapshot );
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
			$id   = is_array( $copy ) ? ( $copy['post_id'] ?? null ) : null;
			$post = is_int( $id ) ? get_post( $id ) : null;
			if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status || get_current_user_id() !== (int) $post->post_author || ! current_user_can( 'edit_post', $id ) || ! is_string( $copy['native_id'] ?? null ) || $copy['native_id'] === $document->native_id ) {
				throw new ContractException( 'unsupported_dependency', 'The installed builder cannot preserve this editor state as a private working copy.' );
			}
			// Recheck source authority after native serialization. Never save the source.
			BuilderEditorSelection::assert_source( $selection, $document );
			$source_id           = preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) ? (int) $match[1] : 0;
			$origin              = $source_id ? self::source_context( $source_id ) : null;
			$record              = array(
				'post_id'            => $id,
				'site_id'            => get_current_blog_id(),
				'user_id'            => get_current_user_id(),
				'document'           => null === $origin ? $document : $origin['document'],
				'source_id'          => null === $origin ? $source_id : $origin['source_id'],
				'source_stamp'       => null === $origin ? $selection->source_stamp : $origin['source_stamp'],
				'baseline_document'  => $document,
				'baseline_stamp'     => $selection->source_stamp,
				'baseline_selection' => (object) array( 'native_node_id' => $selection->native_node_id, 'slot_path' => $selection->slot_path, 'label' => $selection->label ),
			);
			$record['signature'] = self::signature( $record );
			update_post_meta( $id, self::META, $record );
			$next_document            = clone $document;
			$next_document->native_id = $copy['native_id'];
			$next_context             = $this->context( $next_document );
			$selection->source_stamp  = $next_context['selectionSourceStamp'];
			$response                 = rest_ensure_response( array( 'context' => $next_context, 'selection' => $selection, 'working_post_id' => $id, 'original_document' => $document ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( ContractException $error ) {
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 422 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'working_copy_unavailable', $error->getMessage(), array( 'status' => 422 ) );
		}
	}

	/** Signed lineage supplies template meaning, never publication or assignment. */
	public static function source_context( int $post_id ): ?array {
		$record = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $record ) || get_current_user_id() !== ( $record['user_id'] ?? null ) || ( self::INTERNAL_STATUS === get_post_status( $post_id ) && ( $record['expires_at'] ?? 0 ) <= time() ) ) {
			return null;
		}
		$family = self::family_context( $post_id );
		if ( null === $family ) {
			return null; }
		try {
			// A current-editor candidate is compiled against its immutable hidden
			// capture. Native Save/autosave may normalize the original while the
			// response is running; the browser still fences the complete canvas
			// preimage before an undoable apply. Preserve native identity/family,
			// permissions and all signed capture guards, without treating original
			// saved bytes as a second copy of that unsaved canvas.
			if ( self::INTERNAL_STATUS === get_post_status( $post_id ) && 'editor_candidate' === ( $record['output_mode'] ?? null ) && 'page' === ( $family['document']->native_kind ?? null ) ) {
				$document = $family['document'];
				$probe    = new \WP_REST_Request( 'GET' );
				$probe->set_param( 'builder', $document->builder );
				$probe->set_param( 'post_id', (int) substr( $document->native_id, 5 ) );
				$current = ( new EditorAssistant() )->current_document( $probe );
				return is_array( $current ) && ( $current['builder'] ?? null ) === $document->builder && ( $current['nativeKind'] ?? null ) === $document->native_kind && ( $current['nativeId'] ?? null ) === $document->native_id ? $family : null;
			}
			return hash_equals( (string) $family['source_stamp'], BuilderEditorSelection::source_stamp( $family['document'] ) ) ? $family : null;
		} catch ( \Throwable $error ) {
			return null; }
	}

	/** Authorized native editors may recognize signed lineage without reading its conversation. */
	public static function family_context( int $post_id ): ?array {
		$record = get_post_meta( $post_id, self::META, true );
		$post   = get_post( $post_id );
		if ( ! is_array( $record ) || ! $post instanceof \WP_Post || ! in_array( $post->post_status, array( 'draft', self::INTERNAL_STATUS ), true ) || ( self::INTERNAL_STATUS === $post->post_status && ( ! is_string( $record['editor_session'] ?? null ) || ( $record['user_id'] ?? null ) !== get_current_user_id() ) ) || ( $record['post_id'] ?? null ) !== $post_id || get_current_blog_id() !== ( $record['site_id'] ?? null ) || ( $record['user_id'] ?? null ) !== (int) $post->post_author || ! current_user_can( 'edit_post', $post_id ) || ! self::source_editable( $record ) || ! ( $record['document'] ?? null ) instanceof \stdClass || ! is_string( $record['signature'] ?? null ) ) {
			return null;
		}
		$signature = $record['signature'];
		unset( $record['signature'] );
		if ( ! hash_equals( self::signature( $record ), $signature ) ) {
			return null;
		}
		return array( 'document' => $record['document'], 'source_id' => $record['source_id'], 'source_stamp' => $record['source_stamp'] );
	}

	/** Native template identities are references, including file-backed sources. */
	private static function source_editable( array $record ): bool {
		$document = $record['document'] ?? null;
		if ( 'gutenberg' === ( $document->builder ?? null ) && preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document->native_id ?? '', $match ) ) {
			$template = \DirectoristAIPro\Builder\GutenbergTemplateSource::read( $document->native_id );
			return $template instanceof \WP_Block_Template && $template->type === $match[1] && $template->id === $match[2] . '//' . $match[3] && ( empty( $template->wp_id ) || current_user_can( 'edit_post', (int) $template->wp_id ) );
		}
		return current_user_can( 'edit_post', (int) ( $record['source_id'] ?? 0 ) );
	}

	/** Read the signed immediate source for private discussion grouping, not apply authority. */
	public static function area_origin( int $post_id ): ?array {
		$record = get_post_meta( $post_id, self::META, true );
		if ( ! is_array( $record ) || ( $record['user_id'] ?? null ) !== get_current_user_id() || ! ( $record['baseline_selection'] ?? null ) instanceof \stdClass || null === self::family_context( $post_id ) ) {
			return null;
		}
		return $record;
	}

	/** Return native candidate bytes only for an owned, current, scoped approved draft. */
	public function result( \WP_REST_Request $request ) {
		global $wpdb;
		try {
			$db     = new Database( $wpdb );
			$result = ( new BuilderDraftResultApi( $db ) )->read( $request );
			if ( is_wp_error( $result ) || empty( $result['reviewed_content_current'] ) ) {
				throw new ContractException( 'stale_version', 'The approved private draft is unavailable or has changed.' );
			}
			$artifacts = $db->table( 'artifacts' );
			$runs      = $db->table( 'runs' );
			$convos    = $db->table( 'conversations' );
			$rows      = $db->rows( "SELECT a.payload_json,a.content_hash,c.context_json FROM $artifacts a INNER JOIN $runs r ON r.public_id=JSON_UNQUOTE(JSON_EXTRACT(a.payload_json,'$.run_id')) AND r.owner_ref=a.owner_ref INNER JOIN $convos c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE a.public_id=%s AND a.owner_ref=%s AND a.kind='builder_draft' AND c.erasure_requested=0 LIMIT 2", array( (string) $request['operation_id'], 'user:' . get_current_user_id() ) );
			if ( 1 !== count( $rows ) || ! hash_equals( (string) $rows[0]['content_hash'], hash( 'sha256', $rows[0]['payload_json'] ) ) ) {
				throw new ContractException( 'scope_conflict', 'This draft has no exact scoped editor request.' );
			}
			$stage   = ( new JsonDecoder( 5242880, 32 ) )->decode( $rows[0]['payload_json'] );
			$binding = json_decode( $rows[0]['context_json'] );
			if ( ! ( $binding->builder_selection ?? null ) instanceof \stdClass || ! is_string( $stage->native_content ?? null ) || ! hash_equals( hash( 'sha256', $stage->native_content ), (string) ( $stage->native_content_hash ?? '' ) ) || ! in_array( $stage->source_document->builder ?? null, array( 'gutenberg', 'elementor' ), true ) ) {
				throw new ContractException( 'scope_conflict', 'Only a scoped, reviewed native draft supports editor application.' );
			}
			$response = rest_ensure_response( array( 'candidate' => $stage->native_content, 'source_document' => $stage->source_document, 'selection' => $binding->builder_selection, 'draft_post_id' => $result['draft_post_id'], 'operation_id' => $result['operation_id'] ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'area_result_unavailable', $error->getMessage(), array( 'status' => 409 ) );
		}
	}

	private function context( object $document ): array {
		$probe = new \WP_REST_Request( 'GET' );
		$probe->set_param( 'builder', $document->builder ?? '' );
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ) {
			$probe->set_param( 'post_id', (int) $match[1] );
		} else {
			$probe->set_param( 'template_ref', $document->native_id ?? '' );
		}
		$response = ( new EditorAssistant() )->context( $probe );
		if ( is_wp_error( $response ) ) {
			throw new ContractException( 'scope_conflict', $response->get_error_message() );
		}
		return $response->get_data();
	}

	/** Capture one tab's latest canvas into its one hidden, integration-validated source. */
	private function capture_editor( object $input ) {
		global $wpdb;
		if ( ! ( $input->document ?? null ) instanceof \stdClass || ! ( $input->snapshot ?? null ) instanceof \stdClass || ! is_string( $input->editor_session ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->editor_session ) || ( isset( $input->output_mode ) && ! in_array( $input->output_mode, array( 'editor_candidate', 'private_draft' ), true ) ) || ( null !== ( $input->selection ?? null ) && ! $input->selection instanceof \stdClass ) ) {
			throw new ContractException( 'invalid_input', 'A current native canvas and editor session are required.' );
		}
		if ( property_exists( $input, 'history_thread' ) && ( ! ( $input->selection ?? null ) instanceof \stdClass || ! is_string( $input->history_thread ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->history_thread ) ) ) {
			throw new ContractException( 'invalid_input', 'An area discussion requires its exact selected element and history identity.' );
		}
		if ( property_exists( $input, 'history_element_id' ) && ( ! ( $input->selection ?? null ) instanceof \stdClass || ! in_array( $input->document->builder ?? null, array( 'gutenberg', 'divi' ), true ) || ! is_string( $input->history_element_id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $input->history_element_id ) || ( $input->history_thread ?? null ) !== $input->history_element_id ) ) {
			throw new ContractException( 'invalid_input', 'An element history marker must match this selected discussion.' );
		}
		$this->status();
		$document = $input->document;
		$context  = $this->context( $document );
		$target   = $context['currentDocument'];
		$expected = (object) array( 'builder' => $target['builder'], 'native_kind' => $target['nativeKind'], 'native_id' => $target['nativeId'] );
		if ( ( new JsonCanonicalizer() )->encode( $expected ) !== ( new JsonCanonicalizer() )->encode( $document ) || ! is_string( $input->source_stamp ?? null ) || ! hash_equals( BuilderEditorSelection::source_stamp( $document ), $input->source_stamp ) || self::editor_context( $document ) ) {
			throw new ContractException( 'stale_version', 'Refresh the original document before reading this canvas.' );
		}
		$selection = null;
		if ( null !== ( $input->selection ?? null ) ) {
			$selection = (object) array( 'native_node_id' => $input->selection->native_node_id ?? null, 'slot_path' => $input->selection->slot_path ?? null, 'label' => $input->selection->label ?? '', 'source_stamp' => $input->source_stamp );
			BuilderEditorSelection::assert_source( $selection, $document );
		}
		if ( property_exists( $input, 'history_element_id' ) ) {
			EditorElementIdentity::assert_capture( $document, $input->snapshot, $input->selection, $input->history_element_id );
		}
		$key        = hash( 'sha256', get_current_blog_id() . '|' . get_current_user_id() . '|' . ( new JsonCanonicalizer() )->encode( $document ) . '|' . $input->editor_session );
		$lock       = '_dai_editor_capture_' . $key;
		$lock_value = bin2hex( random_bytes( 16 ) );
		if ( ! add_option( $lock, array( 'token' => $lock_value, 'expires' => time() + 120 ), '', false ) ) {
			$old = get_option( $lock );
			if ( is_array( $old ) && ( $old['expires'] ?? PHP_INT_MAX ) < time() ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $lock, 'option_value' => maybe_serialize( $old ) ) );
			}
			throw new ContractException( 'conflict', 'This editor snapshot is already being captured. Try again shortly.' );
		}
		$slot_db = $this->db ?? new Database( $wpdb );
		$slots   = new EditorCaptureSlots( $slot_db );
		try {
			$slots->acquire();
			$this->prune_expired();
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND m.meta_value=%s LIMIT 2", self::SESSION_META, get_current_user_id(), self::INTERNAL_STATUS, $key ) );
			if ( count( $ids ) > 1 ) {
				throw new ContractException( 'scope_conflict', 'This editor session has ambiguous native storage.' );
			}
			$id        = $ids ? (int) $ids[0] : 0;
			$frozen_id = 0;
			$token     = $input->working_copy_token ?? null;
			if ( $id ) {
				$record = null !== self::family_context( $id ) ? get_post_meta( $id, self::META, true ) : null;
				if ( ! is_array( $record ) || ! is_string( $token ) || ! hash_equals( $record['token_hash'], hash( 'sha256', $token ) ) || $record['editor_session'] !== $input->editor_session || ( new JsonCanonicalizer() )->encode( $record['document'] ) !== ( new JsonCanonicalizer() )->encode( $document ) ) {
					throw new ContractException( 'unauthorized', 'This native editor session is unavailable.' );
				}
				$db     = $this->db ?? new Database( $wpdb );
				$convos = $db->table( 'conversations' );
				if ( $db->scalar( "SELECT id FROM $convos WHERE owner_ref=%s AND erasure_requested=0 AND active_run_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.builder_document.native_id'))=%s LIMIT 1", array( 'user:' . get_current_user_id(), self::document_for_post( $document, $id )->native_id ) ) ) {
					throw new ContractException( 'conflict', 'Finish or stop the current AI response before capturing another change.' );
				}
				// An explicitly created native page retains its immutable hidden baseline.
				// A later canvas uses a fresh slot, so its historical source cannot be rewritten.
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type<>'revision' LIMIT 1", $id ) ) ) {
					$frozen_id = $id;
					$id        = 0;
				}
			} elseif ( null !== $token ) {
				throw new ContractException( 'editor_session_expired', 'This editor snapshot expired. Check its saved activity before starting another session.' );
			} else {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s INNER JOIN {$wpdb->postmeta} e ON e.post_id=p.ID AND e.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND CAST(e.meta_value AS UNSIGNED)>%d", self::SESSION_META, self::EXPIRES_META, get_current_user_id(), self::INTERNAL_STATUS, time() ) );
				while ( $count >= 8 && $this->release_resolved_slot( $slot_db ) ) {
					--$count;
				}
				$document_count = 0;
				if ( $count >= 8 ) {
					$active_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s INNER JOIN {$wpdb->postmeta} e ON e.post_id=p.ID AND e.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND CAST(e.meta_value AS UNSIGNED)>%d LIMIT 33", self::SESSION_META, self::EXPIRES_META, get_current_user_id(), self::INTERNAL_STATUS, time() ) );
					foreach ( $active_ids as $active_id ) {
						$active_record = get_post_meta( $active_id, self::META, true );
						if ( is_array( $active_record ) && ( new JsonCanonicalizer() )->encode( $active_record['document'] ?? null ) === ( new JsonCanonicalizer() )->encode( $document ) ) { ++$document_count; }
					}
				}
				if ( $count >= 32 || $document_count >= 8 ) {
					throw new ContractException( 'needs_narrowing', $document_count >= 8 ? 'This page has eight editor sessions awaiting completion. Finish or cancel an earlier request on this page, then try again. Your history is retained.' : 'Your active editor sessions are full. Finish or cancel an earlier request, then try again. Your history is retained.' );
				}
				$token = bin2hex( random_bytes( 32 ) );
			}
			$copy = apply_filters( 'directorist_ai_pro_editor_working_copy', null, $document, $input->snapshot, array( 'post_status' => self::INTERNAL_STATUS, 'post_id' => $id ) );
			if ( is_wp_error( $copy ) ) {
				return $copy;
			}
			$next_id = is_array( $copy ) ? ( $copy['post_id'] ?? null ) : null;
			$post    = is_int( $next_id ) ? get_post( $next_id ) : null;
			if ( ! $post instanceof \WP_Post || self::INTERNAL_STATUS !== $post->post_status || get_current_user_id() !== (int) $post->post_author || ! current_user_can( 'edit_post', $next_id ) || ( $id && $id !== $next_id ) || ! is_string( $copy['native_id'] ?? null ) || $copy['native_id'] === $document->native_id ) {
				throw new ContractException( 'unsupported_dependency', 'The installed builder cannot capture this canvas privately.' );
			}
			if ( ! hash_equals( $input->source_stamp, BuilderEditorSelection::source_stamp( $document ) ) ) {
				throw new ContractException( 'stale_version', 'The original document changed while capturing the canvas.' );
			}
			$record = array(
				'post_id'                => $next_id,
				'site_id'                => get_current_blog_id(),
				'user_id'                => get_current_user_id(),
				'document'               => $document,
				'source_id'              => preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) ? (int) $match[1] : 0,
				'source_stamp'           => $input->source_stamp,
				'baseline_document'      => $document,
				'baseline_stamp'         => $input->source_stamp,
				'baseline_selection'     => $input->selection ?? null,
				'history_thread'         => $input->history_thread ?? null,
				'editor_session'         => $input->editor_session,
				'token_hash'             => hash( 'sha256', $token ),
				'capture_id'             => bin2hex( random_bytes( 16 ) ),
				'captured_snapshot_hash' => hash( 'sha256', ( new JsonCanonicalizer() )->encode( $input->snapshot ) ),
				'expires_at'             => time() + DAY_IN_SECONDS,
				'output_mode'            => 'private_draft' === ( $input->output_mode ?? null ) ? 'private_draft' : 'editor_candidate',
				'working_document'       => (object) array( 'builder' => $document->builder, 'native_kind' => $document->native_kind, 'native_id' => $copy['native_id'] ),
			);
			if ( property_exists( $input, 'history_element_id' ) ) {
				$record['history_element_id'] = $input->history_element_id;
			}
			$record['signature'] = self::signature( $record );
			update_post_meta( $next_id, self::META, $record );
			update_post_meta( $next_id, self::SESSION_META, $key );
			update_post_meta( $next_id, self::EXPIRES_META, $record['expires_at'] );
			$next_context = $this->context( $record['working_document'] );
			if ( $frozen_id ) {
				delete_post_meta( $frozen_id, self::SESSION_META );
			}
			if ( null !== $selection ) {
				if ( 'divi' === $document->builder && true === ( $copy['wrap_selection_path'] ?? false ) && is_array( $selection->slot_path ) && 'content' === ( $selection->slot_path[0] ?? null ) ) {
					$selection->slot_path = array_merge( array( 'content', 'i0', 'innerBlocks' ), array_slice( $selection->slot_path, 1 ) );
				}
				$selection->source_stamp = $next_context['selectionSourceStamp'];
			}
			$response = rest_ensure_response( array( 'context' => $next_context, 'selection' => $selection, 'working_post_id' => $next_id, 'original_document' => $document, 'editor_session' => $input->editor_session, 'working_copy_token' => $token, 'capture_id' => $record['capture_id'], 'captured_snapshot_hash' => $record['captured_snapshot_hash'], 'output_mode' => $record['output_mode'] ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} finally {
			try {
				$slots->release();
			} finally {
				$current = get_option( $lock );
				if ( is_array( $current ) && hash_equals( $lock_value, (string) ( $current['token'] ?? '' ) ) ) {
					delete_option( $lock );
				}
			}
		}
	}

	/** Re-read signed page lineage and source permissions without request-cached metadata. */
	public static function fresh_page_editor_context( object $document ): ?array {
		if ( 'page' !== ( $document->native_kind ?? null ) || ! preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ) {
			return null;
		}
		$id = (int) $match[1];
		wp_cache_delete( $id, 'posts' );
		wp_cache_delete( $id, 'post_meta' );
		$record = get_post_meta( $id, self::META, true );
		if ( ! is_array( $record ) || get_current_user_id() !== ( $record['user_id'] ?? null ) || null === self::family_context( $id ) ) {
			return null;
		}
		$source_id = $record['source_id'] ?? null;
		if ( is_int( $source_id ) && $source_id > 0 ) {
			wp_cache_delete( $source_id, 'posts' );
			wp_cache_delete( $source_id, 'post_meta' );
		}
		return self::editor_context( $document );
	}

	/** Exact signed canvas lineage; ordinary native Save may update the original. */
	public static function editor_context( object $document ): ?array {
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ) {
			$id = (int) $match[1];
		} elseif ( preg_match( '/^template:(wp_template|wp_template_part):([A-Za-z0-9_.\/-]+):([A-Za-z0-9_.-]+)$/D', $document->native_id ?? '', $match ) ) {
			$template = \DirectoristAIPro\Builder\GutenbergTemplateSource::read( $document->native_id );
			$id       = $template instanceof \WP_Block_Template ? (int) $template->wp_id : 0;
		} else {
			return null;
		}
		if ( $id < 1 || self::INTERNAL_STATUS !== get_post_status( $id ) || null === self::source_context( $id ) ) {
			return null;
		}
		$record = get_post_meta( $id, self::META, true );
		return is_array( $record ) && ( $record['user_id'] ?? null ) === get_current_user_id() && ( $record['working_document'] ?? null ) instanceof \stdClass && ( new JsonCanonicalizer() )->encode( $record['working_document'] ) === ( new JsonCanonicalizer() )->encode( $document ) ? $record : null;
	}

	private static function document_for_post( object $document, int $id ): object {
		$post            = get_post( $id );
		$next            = clone $document;
		$next->native_id = $post instanceof \WP_Post && in_array( $post->post_type, array( 'wp_template', 'wp_template_part' ), true ) ? 'template:' . $post->post_type . ':' . get_stylesheet() . ':' . $post->post_name : 'post:' . $id;
		return $next;
	}

	/** New AI work requires a live slot; old signed history remains readable. */
	public static function assert_active_slot( object $document, ?object $origin = null ): void {
		global $wpdb;
		if ( ! preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) ) {
			throw new ContractException( 'editor_session_expired', 'Refresh this editor snapshot before starting another request.' );
		}
		$id = (int) $match[1];
		wp_cache_delete( $id, 'post_meta' );
		$record = get_post_meta( $id, self::META, true );
		if ( ! is_array( $record ) || ( $record['post_id'] ?? null ) !== $id || ( $record['user_id'] ?? null ) !== get_current_user_id() || ( $record['site_id'] ?? null ) !== get_current_blog_id() || ! is_string( $record['editor_session'] ?? null ) || ! ( $record['document'] ?? null ) instanceof \stdClass || ! ( $record['working_document'] ?? null ) instanceof \stdClass || ! is_string( $record['signature'] ?? null ) || ! is_int( $record['expires_at'] ?? null ) || $record['expires_at'] <= time() || ! current_user_can( 'edit_post', $id ) || ! self::source_editable( $record ) ) {
			throw new ContractException( 'editor_session_expired', 'This editor snapshot is no longer current. Prepare a fresh request from this page.' );
		}
		$signature = $record['signature'];
		unset( $record['signature'] );
		$key   = hash( 'sha256', get_current_blog_id() . '|' . get_current_user_id() . '|' . ( new JsonCanonicalizer() )->encode( $record['document'] ) . '|' . $record['editor_session'] );
		$slots = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 2", $id, self::SESSION_META ) );
		if ( ! hash_equals( self::signature( $record ), $signature ) || ( new JsonCanonicalizer() )->encode( $record['working_document'] ) !== ( new JsonCanonicalizer() )->encode( $document ) || array( $key ) !== $slots || ( null !== $origin && ( ( $origin->capture_id ?? null ) !== ( $record['capture_id'] ?? null ) || ( $origin->captured_snapshot_hash ?? null ) !== ( $record['captured_snapshot_hash'] ?? null ) || ( $origin->editor_session ?? null ) !== $record['editor_session'] ) ) ) {
			throw new ContractException( 'editor_session_expired', 'This editor session has finished. Prepare a fresh request from this page.' );
		}
	}

	/** Release one inactive slot index, preserving its signed source and history. */
	private function release_resolved_slot( Database $db ): bool {
		global $wpdb;
		$ids       = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s INNER JOIN {$wpdb->postmeta} e ON e.post_id=p.ID AND e.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND CAST(e.meta_value AS UNSIGNED)>%d ORDER BY p.ID ASC LIMIT 32", self::SESSION_META, self::EXPIRES_META, get_current_user_id(), self::INTERNAL_STATUS, time() ) );
		$convos    = $db->table( 'conversations' );
		$runs      = $db->table( 'runs' );
		$steps     = $db->table( 'steps' );
		$ops       = $db->table( 'operations' );
		$approvals = $db->table( 'approvals' );
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$record = get_post_meta( $id, self::META, true );
			if ( ! is_array( $record ) || ! ( $record['working_document'] ?? null ) instanceof \stdClass ) {
				continue;
			}
			try {
				self::assert_active_slot( $record['working_document'] );
			} catch ( ContractException $error ) {
				continue;
			}
			$rows     = $db->rows( "SELECT id,active_run_id,context_json,UNIX_TIMESTAMP(created_at) AS created_epoch FROM $convos WHERE owner_ref=%s AND (JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.builder_document.native_id'))=%s OR JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.target'))=%s)", array( 'user:' . get_current_user_id(), $record['working_document']->native_id, $record['working_document']->native_id ) );
			$resolved = true;
			$used     = false;
			foreach ( $rows as $row ) {
				if ( null !== $row['active_run_id'] || $db->scalar( "SELECT id FROM $runs WHERE conversation_id=%d AND (state NOT IN ('succeeded','failed','cancelled') OR JSON_UNQUOTE(JSON_EXTRACT(checkpoint_json,'$.error')) IN ('managed_pending','managed_uncertain','managed_transport_uncertain','unknown_outcome') OR (lease_owner IS NOT NULL AND (lease_until IS NULL OR lease_until>UTC_TIMESTAMP()))) LIMIT 1", array( (int) $row['id'] ) ) ) {
					$resolved = false;
					break;
				}
				$has_run   = (bool) $db->scalar( "SELECT id FROM $runs WHERE conversation_id=%d LIMIT 1", array( (int) $row['id'] ) );
				$context   = json_decode( $row['context_json'] );
				$cancelled = isset( $context->builder_preparation_cancelled );
				if ( ! $has_run && ! $cancelled && (int) $row['created_epoch'] > time() - 15 * MINUTE_IN_SECONDS ) {
					$resolved = false;
					break;
				}
				if ( ( $has_run || $cancelled ) && ( $context->builder_editor_origin->capture_id ?? null ) === ( $record['capture_id'] ?? null ) ) {
					$used = true;
				}
				if ( $db->scalar( "SELECT s.id FROM $steps s INNER JOIN $runs r ON r.id=s.run_id WHERE r.conversation_id=%d AND s.state NOT IN ('completed','succeeded','failed','cancelled','skipped') LIMIT 1", array( (int) $row['id'] ) ) || $db->scalar( "SELECT o.id FROM $ops o INNER JOIN $runs r ON r.id=o.run_id WHERE r.conversation_id=%d AND o.state NOT IN ('succeeded','failed','cancelled') LIMIT 1", array( (int) $row['id'] ) ) || $db->scalar( "SELECT a.id FROM $approvals a INNER JOIN $runs r ON r.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(a.review_json,'$.plan.run_id')) AS UNSIGNED) WHERE r.conversation_id=%d AND a.actor_ref=%s AND (a.state='reserved' OR (a.state IN ('staged','pending','approved') AND a.expires_at>UTC_TIMESTAMP())) LIMIT 1", array( (int) $row['id'], 'user:' . get_current_user_id() ) ) ) {
					$resolved = false;
					break;
				}
			}
			// An unsubmitted canvas keeps a preparation lease while the browser
			// may still be reading it. Later admission rechecks its slot index.
			if ( ! $resolved || ( ! $used && $record['expires_at'] - DAY_IN_SECONDS > time() - 15 * MINUTE_IN_SECONDS ) ) {
				continue;
			}
			$key = get_post_meta( $id, self::SESSION_META, true );
			if ( delete_post_meta( $id, self::SESSION_META, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/** Installed native serializers accept updates only to this exact owned hidden source. */
	public static function native_capture_options( object $document, ?array $options ): array {
		if ( null === $options ) {
			return array( 'post_status' => 'draft', 'post_id' => 0 );
		}
		$id = $options['post_id'] ?? null;
		if ( self::INTERNAL_STATUS !== ( $options['post_status'] ?? null ) || ! is_int( $id ) || $id < 0 ) {
			throw new ContractException( 'invalid_input', 'The native editor snapshot destination is invalid.' );
		}
		if ( $id ) {
			$record = null !== self::family_context( $id ) ? get_post_meta( $id, self::META, true ) : null;
			if ( ! is_array( $record ) || ( $record['user_id'] ?? null ) !== get_current_user_id() || self::INTERNAL_STATUS !== get_post_status( $id ) || ( new JsonCanonicalizer() )->encode( $record['document'] ) !== ( new JsonCanonicalizer() )->encode( $document ) ) {
				throw new ContractException( 'unauthorized', 'The native editor snapshot destination is unavailable.' );
			}
		}
		return array( 'post_status' => self::INTERNAL_STATUS, 'post_id' => $id );
	}

	/** A staged canvas result must still name the same signed original and exact capture. */
	public static function assert_candidate_source( object $payload ): array {
		$record = ( $payload->source_document ?? null ) instanceof \stdClass ? self::editor_context( $payload->source_document ) : null;
		if ( null === $record || 'editor_candidate' !== ( $record['output_mode'] ?? null ) || 'editor_candidate' !== ( $payload->output_mode ?? null ) || ( $payload->capture_id ?? null ) !== $record['capture_id'] || ( $payload->captured_snapshot_hash ?? null ) !== $record['captured_snapshot_hash'] || ( new JsonCanonicalizer() )->encode( $payload->original_document ?? null ) !== ( new JsonCanonicalizer() )->encode( $record['document'] ) ) {
			throw new ContractException( 'stale_version', 'This AI change belongs to an earlier editor canvas. Prepare it again from the current canvas.' );
		}
		// A signed identity alone does not prove the hidden native bytes remained
		// immutable after execution. Use the same saved-source inventory as the
		// installed native reader, including Elementor settings and metadata.
		$source = get_post_field( 'post_content', $record['post_id'], 'raw' );
		if ( 'elementor' === $payload->source_document->builder ) {
			$providers = new \DirectoristAIPro\Builder\BuilderGraphRegistry();
			do_action( 'directorist_ai_pro_register_builder_graph_providers', $providers );
			$providers->freeze();
			$reader = $providers->provider( 'elementor' );
			if ( null === $reader || ! is_callable( array( $reader, 'read_saved_bundle_for_post' ) ) ) {
				throw new ContractException( 'unsupported_dependency', 'The native editor source inventory is unavailable.' );
			}
			$source = $reader->read_saved_bundle_for_post( $record['post_id'] );
		}
		if ( ! is_string( $source ) || ! is_string( $payload->source_hash ?? null ) || ! hash_equals( $payload->source_hash, hash( 'sha256', $source ) ) ) {
			throw new ContractException( 'stale_version', 'The captured native editor source changed after review.' );
		}
		return $record;
	}

	/** Core omits internal templates from ordinary queries; resolve only exact owned references. */
	public static function internal_template( $result, string $id, string $type ) {
		global $wpdb;
		if ( null !== $result || ! current_user_can( 'edit_theme_options' ) || ! in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) || ! preg_match( '/^([^\/]+)\/\/(directorist-ai-working-[a-f0-9]{24})$/D', $id, $match ) || get_stylesheet() !== $match[1] || ! function_exists( '_build_block_template_result_from_post' ) ) {
			return $result;
		}
		$post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_type=%s AND post_status=%s AND post_author=%d LIMIT 1", $match[2], $type, self::INTERNAL_STATUS, get_current_user_id() ) );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || null === self::family_context( $post_id ) ) {
			return $result;
		}
		$template = _build_block_template_result_from_post( $post );
		return $template instanceof \WP_Block_Template ? $template : $result;
	}

	/** The browser owns Undo; internal storage needs only its one current native snapshot. */
	public static function internal_revisions( int $keep, \WP_Post $post ): int {
		return self::INTERNAL_STATUS === $post->post_status ? 0 : $keep;
	}

	/** Bounded housekeeping removes only expired signed sources with no active conversation. */
	private function prune_expired(): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND CAST(m.meta_value AS UNSIGNED)<=%d AND NOT EXISTS (SELECT child.ID FROM {$wpdb->posts} child WHERE child.post_parent=p.ID AND child.post_type<>'revision') ORDER BY p.ID ASC LIMIT 20", self::EXPIRES_META, get_current_user_id(), self::INTERNAL_STATUS, time() ) );
		if ( ! $ids ) {
			return;
		}
		$db     = $this->db ?? new Database( $wpdb );
		$convos = $db->table( 'conversations' );
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$record = get_post_meta( $id, self::META, true );
			if ( ! is_array( $record ) || ( $record['post_id'] ?? null ) !== $id || ( $record['site_id'] ?? null ) !== get_current_blog_id() || ( $record['user_id'] ?? null ) !== get_current_user_id() || ! is_int( $record['expires_at'] ?? null ) || $record['expires_at'] > time() || ! is_string( $record['signature'] ?? null ) || ! ( $record['working_document'] ?? null ) instanceof \stdClass ) {
				continue;
			}
			$signature = $record['signature'];
			unset( $record['signature'] );
			if ( ! hash_equals( self::signature( $record ), $signature ) || $db->scalar( "SELECT id FROM $convos WHERE owner_ref=%s AND active_run_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.builder_document.native_id'))=%s LIMIT 1", array( 'user:' . get_current_user_id(), $record['working_document']->native_id ) ) ) {
				continue;
			}
			wp_delete_post( $id, true );
		}
	}

	private static function signature( array $record ): string {
		return hash_hmac( 'sha256', ( new JsonCanonicalizer() )->encode( (object) $record ), wp_salt( 'auth' ) );
	}
}
