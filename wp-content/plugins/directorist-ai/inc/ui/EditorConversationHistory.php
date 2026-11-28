<?php
/**
 * Owned task references for one original native editor document.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\UI;

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;

/** History metadata never grants candidate, approval, token or native-write authority. */
final class EditorConversationHistory {

	/**
	 * Optional isolated runtime connection.
	 *
	 * @var Database|null
	 */
	private ?Database $db;

	/**
	 * Bind the read-only runtime repository.
	 *
	 * @param Database|null $db Runtime connection.
	 */
	public function __construct( ?Database $db = null ) {
		$this->db = $db;
	}

	/** Register an authenticated, read-only original-document lookup. */
	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-history',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'read' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-history/cancel-preparation',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'cancel_preparation' ),
			)
		);
	}

	/**
	 * Close only an owned empty prepared conversation before its first admission.
	 *
	 * The exact turn marker and the admission allocator share the conversation
	 * row lock. Existing runs are returned as metadata and never cancelled here.
	 *
	 * @param \WP_REST_Request $request Exact owned preparation cancellation.
	 * @return \WP_REST_Response|\WP_Error Minimal cancellation or admitted identity.
	 * @throws ContractException Invalid ownership, document or preparation identity.
	 */
	public function cancel_preparation( \WP_REST_Request $request ) {
		global $wpdb;
		try {
			if ( get_current_user_id() < 1 || ! wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) {
				throw new ContractException( 'unauthorized', 'Sign in to cancel this editor preparation.' );
			}
			$input = ( new \DirectoristAIPro\Validation\JsonDecoder( 4096, 8 ) )->decode( $request->get_body() );
			$keys  = $input instanceof \stdClass ? array_keys( get_object_vars( $input ) ) : array();
			sort( $keys );
			if ( array( 'conversation_id', 'document', 'turn_request_id' ) !== $keys || ! self::valid_document( $input->document ) || ! is_string( $input->conversation_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $input->conversation_id ) || ! is_string( $input->turn_request_id ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $input->turn_request_id ) ) {
				throw new ContractException( 'invalid_input', 'The exact original document and prepared request are required.' );
			}
			$this->assert_original( $input->document );
			$db = $this->db ?? new Database( $wpdb );
			$db->readiness()->assert_ready();
			$owner    = 'user:' . get_current_user_id();
			$result   = $db->transaction(
				static function ( Database $db ) use ( $input, $owner ): array {
					$conversations = $db->table( 'conversations' );
					$turns         = $db->table( 'turns' );
					$runs          = $db->table( 'runs' );
					$messages      = $db->table( 'messages' );
					$rows          = $db->rows( "SELECT * FROM $conversations WHERE public_id=%s AND owner_ref=%s AND profile='builder' AND erasure_requested=0 AND (expires_at>UTC_TIMESTAMP() OR active_run_id IS NOT NULL) FOR UPDATE", array( $input->conversation_id, $owner ) );
					$row           = $rows[0] ?? null;
					$context       = null === $row ? null : json_decode( $row['context_json'] );
					$origin        = $context->builder_editor_origin ?? null;
					if ( null === $row || ! self::valid_origin( $origin ) || ! self::same_document( $origin->original_document, $input->document ) || ! self::same_document( $origin->working_document, $context->builder_document ?? null ) ) {
						throw new ContractException( 'unauthorized', 'This original editor preparation is unavailable.' );
					}
					// The conversation lock already serializes admission. An absent
					// turn must not acquire an unnecessary cross-conversation gap lock.
					$existing = $db->rows( "SELECT * FROM $turns WHERE conversation_id=%d AND request_id=%s", array( (int) $row['id'], $input->turn_request_id ) );
					$turn     = $existing[0] ?? null;
					if ( null !== ( $context->builder_preparation_cancelled ?? null ) ) {
						if ( ( $context->builder_preparation_cancelled->request_id ?? null ) !== $input->turn_request_id || null === $turn || 'cancelled_preparation' !== $turn['state'] || null !== $turn['run_id'] ) {
							throw new ContractException( 'request_conflict', 'This preparation was closed for another request.' );
						}
						return array( 'status' => 'cancelled_preparation' );
					}
					if ( null !== $turn && 1 === (int) $turn['sequence'] && null !== $turn['run_id'] ) {
						$accepted = $db->rows( "SELECT public_id,state,state_version FROM $runs WHERE id=%d AND conversation_id=%d AND owner_ref=%s AND trigger_turn_id=%d", array( (int) $turn['run_id'], (int) $row['id'], $owner, (int) $turn['id'] ) );
						if ( $accepted ) {
							return array(
								'status'  => 'already_admitted',
								'runId'   => $accepted[0]['public_id'],
								'state'   => $accepted[0]['state'],
								'version' => (int) $accepted[0]['state_version'],
							);
						}
					}
					if ( null !== $row['active_run_id'] || 1 !== (int) $row['next_turn_seq'] || 1 !== (int) $row['next_message_seq'] || $db->scalar( "SELECT id FROM $turns WHERE conversation_id=%d LIMIT 1", array( (int) $row['id'] ) ) || $db->scalar( "SELECT id FROM $runs WHERE conversation_id=%d LIMIT 1", array( (int) $row['id'] ) ) || $db->scalar( "SELECT id FROM $messages WHERE conversation_id=%d LIMIT 1", array( (int) $row['id'] ) ) ) {
						throw new ContractException( 'request_conflict', 'This conversation already contains another request. Check its existing outcome.' );
					}
					$marker = wp_json_encode(
						(object) array(
							'kind'       => 'editor_preparation_cancelled',
							'request_id' => $input->turn_request_id,
						)
					);
					$db->query( "INSERT INTO $turns (conversation_id,sequence,request_id,input_hash,state,input_json,created_at) VALUES (%d,1,%s,%s,'cancelled_preparation',%s,UTC_TIMESTAMP())", array( (int) $row['id'], $input->turn_request_id, hash( 'sha256', $marker ), $marker ) );
					$context->builder_preparation_cancelled = (object) array(
						'request_id' => $input->turn_request_id,
						'version'    => 1,
					);
					$db->query( "UPDATE $conversations SET context_json=%s,next_turn_seq=2,state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( wp_json_encode( $context ), (int) $row['id'] ) );
					return array( 'status' => 'cancelled_preparation' );
				}
			);
			$response = rest_ensure_response( $result );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( ContractException $error ) {
			$status = 'unauthorized' === $error->error_code() ? 403 : ( 'request_conflict' === $error->error_code() ? 409 : 422 );
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => $status ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'editor_preparation_unavailable', 'This preparation could not be checked. Keep its saved request and try again.', array( 'status' => 503 ) );
		}
	}

	/**
	 * Retain only server-derived, signed capture identity in a new conversation.
	 *
	 * @param object $document Bound native working document.
	 * @return object|null Private origin metadata, without any working token.
	 * @throws ContractException An owned hidden snapshot cannot accept a new request.
	 */
	public static function bind_origin( object $document ): ?object {
		$record = EditorWorkingCopy::editor_context( $document );
		if ( null !== $record ) {
			$origin = self::origin_from_record( $record, $document );
			if ( null === $origin ) {
				throw new ContractException( 'unauthorized', 'This signed editor history identity is unavailable.' );
			}
			return $origin;
		}
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id ?? '', $match ) && EditorWorkingCopy::INTERNAL_STATUS === get_post_status( (int) $match[1] ) ) {
			$stored = get_post_meta( (int) $match[1], '_directorist_ai_editor_working_source', true );
			if ( is_array( $stored ) && ( $stored['user_id'] ?? null ) === get_current_user_id() && null !== EditorWorkingCopy::family_context( (int) $match[1] ) ) {
				throw new ContractException( 'editor_session_expired', 'This editor snapshot is no longer current. Check its saved activity before starting another session.' );
			}
			throw new ContractException( 'unauthorized', 'This native editor snapshot is unavailable.' );
		}
		return null;
	}

	/**
	 * Return matching references only; browser recovery must never auto-apply them.
	 *
	 * @param \WP_REST_Request $request Exact original document and optional tab session.
	 * @return \WP_REST_Response|\WP_Error Read-only owned task metadata.
	 * @throws ContractException Invalid original document or lost edit authority.
	 */
	public function read( \WP_REST_Request $request ) {
		global $wpdb;
		try {
			if ( get_current_user_id() < 1 ) {
				throw new ContractException( 'unauthorized', 'Sign in to read your editor activity.' );
			}
			$document = (object) array(
				'builder'     => $request->get_param( 'builder' ),
				'native_kind' => $request->get_param( 'native_kind' ),
				'native_id'   => $request->get_param( 'native_id' ),
			);
			$session  = $request->get_param( 'editor_session' );
			if ( ! self::valid_document( $document ) || ( null !== $session && ( ! is_string( $session ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $session ) ) ) ) {
				throw new ContractException( 'invalid_input', 'An exact original editor document is required.' );
			}
			$history = $this->history_filter( $request );
			$this->assert_original( $document );
			$db     = $this->db ?? new Database( $wpdb );
			$before = $request->get_param( 'before' );
			$cursor = null;
			if ( null !== $before ) {
				if ( ! is_string( $before ) || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $before ) ) {
					throw new ContractException( 'invalid_input', 'The earlier conversation cursor is invalid.' );
				}
				$anchors = $db->rows( 'SELECT id FROM ' . $db->table( 'conversations' ) . " WHERE public_id=%s AND owner_ref=%s AND profile='builder' AND erasure_requested=0 LIMIT 1", array( $before, 'user:' . get_current_user_id() ) );
				if ( ! $anchors ) {
					throw new ContractException( 'unauthorized', 'This earlier conversation cursor is unavailable.' );
				}
				$cursor = (int) $anchors[0]['id'];
			}
			$lineage = $this->legacy_lineage( $document );
			$recent  = $this->rows( $db, $document, $lineage['origins'], $session, array(), false, $history, $cursor );
			$window  = array_slice( $recent, 0, 20 );
			$tasks   = array();
			foreach ( $window as $row ) {
				$task = $this->task( $row, $document, $lineage['origins'] );
				if ( null !== $task && $this->matches_history( $task, $history ) ) {
					$tasks[] = $task;
				}
			}
			// Older unresolved work must not disappear behind the history window or
			// an optional visible-session filter and accidentally permit a new start.
			$blocking_rows = $this->rows( $db, $document, $lineage['origins'], null, array_column( $tasks, 'conversationId' ), true );
			$blocking      = array();
			$foreign       = array();
			foreach ( array_slice( $blocking_rows, 0, 20 ) as $row ) {
				$task = $this->task( $row, $document, $lineage['origins'] );
				if ( null !== $task ) {
					if ( $this->matches_history( $task, $history ) && ( null === $history || null === $session || $task['editorSession'] === $session ) ) {
						$blocking[] = $task;
					} else {
						$foreign[] = array(
							'conversationId'     => $task['conversationId'],
							'runId'              => $task['runId'],
							'state'              => $task['state'],
							'version'            => $task['version'],
							'requiresResolution' => true,
							'historyScope'       => $task['historyScope'],
							'areaLabel'          => $task['areaLabel'],
							'reason'             => $this->matches_history( $task, $history ) ? 'other_session' : 'other_scope',
						);
					}
				}
			}
			$unresolved = (bool) $blocking_rows || (bool) array_filter( $tasks, static fn( array $task ): bool => $task['requiresResolution'] );
			$this->assert_original( $document );
			$response = rest_ensure_response(
				array(
					'document'             => $document,
					'tasks'                => array_reverse( $tasks ),
					'blockingTasks'        => array_reverse( $blocking ),
					'foreignBlockingTasks' => array_reverse( $foreign ),
					'historyScope'         => $history['scope'] ?? 'document',
					'hasMore'              => count( $recent ) > 20,
					'nextCursor'           => count( $recent ) > 20 && $window ? end( $window )['public_id'] : null,
					'blockingHasMore'      => count( $blocking_rows ) > 20,
					'historyComplete'      => $lineage['complete'],
					'hasUnresolved'        => $unresolved,
					'canStartNewSession'   => ! $unresolved && $lineage['complete'],
				)
			);
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( ContractException $error ) {
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => 'unauthorized' === $error->error_code() ? 403 : 422 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'editor_history_unavailable', 'Your editor activity could not be checked. Check again before starting another request.', array( 'status' => 503 ) );
		}
	}

	/**
	 * Parse a history-only filter; native ordinals never identify a discussion.
	 *
	 * @param \WP_REST_Request $request Original-document history request.
	 * @return array|null Exact history identity or the legacy document window.
	 * @throws ContractException Invalid history identity.
	 */
	private function history_filter( \WP_REST_Request $request ): ?array {
		$scope     = $request->get_param( 'history_scope' );
		$thread    = $request->get_param( 'history_thread' );
		$selection = $request->get_param( 'history_selection' );
		if ( null === $scope && null === $thread && null === $selection ) {
			return null;
		}
		if ( ! in_array( $scope, array( 'page', 'area' ), true ) || ( null !== $thread && ( ! is_string( $thread ) || ! preg_match( '/^[a-f0-9]{32}$/D', $thread ) ) ) || ( 'page' === $scope && ( null !== $thread || null !== $selection ) ) ) {
			throw new ContractException( 'invalid_input', 'An exact page or selected-area history identity is required.' );
		}
		if ( is_string( $selection ) ) {
			$selection = ( new \DirectoristAIPro\Validation\JsonDecoder( 16384, 8 ) )->decode( $selection );
		}
		$identity = null === $selection ? null : self::selection_identity( $selection );
		if ( null !== $selection ) {
			$keys = $selection instanceof \stdClass ? array_keys( get_object_vars( $selection ) ) : array();
			if ( null === $identity || array_diff( $keys, array( 'native_node_id', 'slot_path' ) ) ) {
				throw new ContractException( 'invalid_input', 'The selected-area history filter is invalid.' );
			}
		}
		if ( 'area' === $scope && null === $thread && null === ( $identity->native_node_id ?? null ) ) {
			throw new ContractException( 'invalid_input', 'Selected-area history requires its independent discussion identity or exact native element ID.' );
		}
		return array(
			'scope'     => $scope,
			'thread'    => $thread,
			'native_id' => $identity->native_node_id ?? null,
		);
	}

	/**
	 * Match only signed area threads or stable native IDs, never slot paths.
	 *
	 * @param array      $task Owned non-executable task metadata.
	 * @param array|null $history Optional history-only identity.
	 * @return bool Whether this task belongs to the visible discussion.
	 */
	private function matches_history( array $task, ?array $history ): bool {
		if ( null === $history ) {
			return true;
		}
		if ( $history['scope'] !== $task['historyScope'] ) {
			return false;
		}
		return 'page' === $history['scope'] || ( ( null === $history['thread'] || $history['thread'] === $task['historyThread'] ) && ( null === $history['native_id'] || ( $task['originalSelection']->native_node_id ?? null ) === $history['native_id'] ) );
	}

	/**
	 * Verify current edit rights and native family without requiring its old bytes.
	 *
	 * @param object $document Exact original native document.
	 * @throws ContractException Unavailable original document.
	 */
	private function assert_original( object $document ): void {
		$probe = new \WP_REST_Request( 'GET' );
		$probe->set_param( 'builder', $document->builder );
		if ( preg_match( '/^post:([1-9][0-9]*)$/D', $document->native_id, $match ) ) {
			if ( EditorWorkingCopy::INTERNAL_STATUS === get_post_status( (int) $match[1] ) ) {
				throw new ContractException( 'unauthorized', 'Open activity from its original native document.' );
			}
			$probe->set_param( 'post_id', (int) $match[1] );
		} else {
			$probe->set_param( 'template_ref', $document->native_id );
		}
		$resolved = ( new EditorAssistant() )->context( $probe );
		$target   = $resolved instanceof \WP_REST_Response ? ( $resolved->get_data()['currentDocument'] ?? null ) : null;
		if ( ! is_array( $target ) || ( $target['builder'] ?? null ) !== $document->builder || ( $target['nativeKind'] ?? null ) !== $document->native_kind || ( $target['nativeId'] ?? null ) !== $document->native_id ) {
			throw new ContractException( 'unauthorized', 'The original native document is unavailable.' );
		}
	}

	/**
	 * Recognize old conversations only through their existing signed hidden lineage.
	 *
	 * @param object $document Current original document.
	 * @return array Bounded verified legacy origins and completeness.
	 */
	private function legacy_lineage( object $document ): array {
		global $wpdb;
		$needle = '%"' . $wpdb->esc_like( $document->native_id ) . '"%';
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key=%s WHERE p.post_author=%d AND p.post_status=%s AND m.meta_value LIKE %s ORDER BY p.ID DESC LIMIT 501", '_directorist_ai_editor_working_source', get_current_user_id(), EditorWorkingCopy::INTERNAL_STATUS, $needle ) );
		$result = array();
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			$id     = (int) $id;
			$family = EditorWorkingCopy::family_context( $id );
			$record = null !== $family ? get_post_meta( $id, '_directorist_ai_editor_working_source', true ) : null;
			if ( ! is_array( $record ) || ( $record['user_id'] ?? null ) !== get_current_user_id() || ! ( $record['working_document'] ?? null ) instanceof \stdClass || ! self::same_document( $record['document'] ?? null, $document ) ) {
				continue;
			}
			$origin = self::origin_from_record( $record, $record['working_document'] );
			if ( null !== $origin ) {
				$result[ $origin->working_document->native_id ] = $origin;
			}
		}
		return array(
			'origins'  => $result,
			'complete' => count( $ids ) < 501,
		);
	}

	/**
	 * Query a bounded latest window or unresolved records outside that window.
	 *
	 * @param Database    $db Runtime database.
	 * @param object      $document Exact original document.
	 * @param array       $legacy Signed old hidden lineage.
	 * @param string|null $session Optional history-only session filter.
	 * @param array       $exclude Already returned conversation identities.
	 * @param bool        $blocking Limit to unresolved work.
	 * @param array|null  $history Optional visible discussion identity.
	 * @param int|null    $before Exclusive owned conversation cursor for older pages.
	 * @return array Owned raw metadata rows, never returned to the browser.
	 */
	private function rows( Database $db, object $document, array $legacy, ?string $session, array $exclude, bool $blocking, ?array $history = null, ?int $before = null ): array {
		$convos    = $db->table( 'conversations' );
		$runs      = $db->table( 'runs' );
		$ops       = $db->table( 'operations' );
		$ids       = array_merge( array( $document->native_id ), array_keys( $legacy ) );
		$args      = array( 'user:' . get_current_user_id(), $document->builder, $document->native_kind, $document->native_id, $document->builder, $document->native_kind );
		$bound     = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$args      = array_merge( $args, $ids );
		$uncertain = "(r.id IS NOT NULL AND (r.state NOT IN ('succeeded','failed','cancelled') OR (r.state='failed' AND (EXISTS(SELECT 1 FROM $ops o WHERE o.run_id=r.id AND o.actor_ref=c.owner_ref AND o.state IN ('running','unknown','partial','succeeded')) OR JSON_UNQUOTE(JSON_EXTRACT(r.checkpoint_json,'$.error')) IN ('managed_pending','managed_uncertain','managed_transport_uncertain','storage_unavailable') OR JSON_EXTRACT(r.checkpoint_json,'$.receipt') IS NOT NULL)) OR (r.state='cancelled' AND EXISTS(SELECT 1 FROM $ops o WHERE o.run_id=r.id AND o.actor_ref=c.owner_ref AND o.state IN ('running','unknown','partial')))))";
		// Expiry must not turn an unconfirmed operation into permission to start
		// again. Retain only its non-executable metadata until it is resolved.
		$where = "c.owner_ref=%s AND c.profile='builder' AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL OR $uncertain) AND ((JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.original_document.builder'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.original_document.native_kind'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.original_document.native_id'))=%s) OR (JSON_EXTRACT(c.context_json,'$.builder_editor_origin') IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_document.builder'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_document.native_kind'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_document.native_id')) IN ($bound)))";
		if ( null !== $session ) {
			$sessions = array_keys( array_filter( $legacy, static fn( object $origin ): bool => $origin->editor_session === $session ) );
			$where   .= " AND (JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.editor_session'))=%s";
			$args[]   = $session;
			if ( $sessions ) {
				$where .= " OR (JSON_EXTRACT(c.context_json,'$.builder_editor_origin') IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_document.native_id')) IN (" . implode( ',', array_fill( 0, count( $sessions ), '%s' ) ) . '))';
				$args   = array_merge( $args, $sessions );
			}
			$where .= ')';
		}
		if ( null !== $history ) {
			$selection = "JSON_EXTRACT(c.context_json,'$.builder_selection')";
			if ( 'page' === $history['scope'] ) {
				$where .= " AND ($selection IS NULL OR JSON_TYPE($selection)='NULL')";
			} else {
				$where .= " AND JSON_TYPE($selection)='OBJECT'";
				if ( null !== $history['thread'] ) {
					$where .= " AND JSON_EXTRACT(c.context_json,'$.builder_editor_origin.origin_version')=2 AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.history_thread'))=%s";
					$args[] = $history['thread'];
				}
				if ( null !== $history['native_id'] ) {
					$where .= " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_editor_origin.original_selection.native_node_id')),JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.builder_selection.native_node_id')))=%s";
					$args[] = $history['native_id'];
				}
			}
		}
		if ( $exclude ) {
			$where .= ' AND c.public_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')';
			$args   = array_merge( $args, $exclude );
		}
		if ( $blocking ) {
			$where .= ' AND ' . $uncertain;
		}
		if ( null !== $before && ! $blocking ) {
			$where .= ' AND c.id<%d';
			$args[] = $before;
		}
		$events = $db->table( 'events' );
		// A historical browser acknowledgement is display metadata. It never
		// establishes the current canvas, a native Save or permission to replay.
		$reported = "EXISTS(SELECT e.id FROM $events e WHERE e.run_id=r.id AND e.type='diagnostic' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.data.kind'))='editor_application' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.data.code'))='native_applied' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.data.outcome'))='applied' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.data.reported_by_browser'))='true' AND JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.data.candidate_id'))=JSON_UNQUOTE(JSON_EXTRACT(r.checkpoint_json,'$.review.operation_id')))";
		$sql      = "SELECT c.id AS conversation_key,c.owner_ref,c.public_id,c.context_json,c.created_at,c.updated_at,r.id AS run_key,r.public_id AS run_public_id,r.state,r.state_version,r.cancel_requested,r.checkpoint_json,$reported AS application_reported,$uncertain AS unresolved FROM $convos c LEFT JOIN $runs r ON r.id=(SELECT MAX(latest.id) FROM $runs latest WHERE latest.conversation_id=c.id AND latest.owner_ref=c.owner_ref) WHERE $where";
		if ( $blocking ) {
			return self::scan_blocking_rows(
				static function ( ?int $cursor, int $limit ) use ( $db, $sql, $args ): array {
					$page_sql  = $sql;
					$page_args = $args;
					if ( null !== $cursor ) {
						$page_sql   .= ' AND c.id<%d';
						$page_args[] = $cursor;
					}
					return $db->rows( $page_sql . " ORDER BY c.id DESC LIMIT $limit", $page_args );
				},
				fn( array $row ): bool => $this->known_failed_candidate( $db, $row )
			);
		}
		$rows = $db->rows( $sql . ' ORDER BY c.id DESC LIMIT 21', $args );
		foreach ( $rows as &$row ) {
			if ( $row['unresolved'] && $this->known_failed_candidate( $db, $row ) ) {
				$row['unresolved'] = false;
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Scan small descending pages without keeping certified safe checkpoints.
	 *
	 * @param callable $fetch Read-only page fetcher accepting an exclusive cursor and limit.
	 * @param callable $known_safe Exact failed-candidate classifier; uncertainty returns false.
	 * @return array At most 21 unresolved rows, including a conservative saturation boundary.
	 * @throws ContractException Invalid or non-descending storage results.
	 */
	private static function scan_blocking_rows( callable $fetch, callable $known_safe ): array {
		$blockers = array();
		$cursor   = null;
		$scanned  = 0;
		$boundary = null;
		while ( $scanned < 501 ) {
			$limit = min( 21, 501 - $scanned );
			$page  = $fetch( $cursor, $limit );
			if ( count( $page ) > $limit ) {
				throw new ContractException( 'storage_unavailable', 'Editor history returned an invalid bounded window.' );
			}
			foreach ( $page as $row ) {
				$key = $row['conversation_key'] ?? null;
				if ( ( ! is_string( $key ) && ! is_int( $key ) ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $key ) || (string) (int) $key !== (string) $key || ( null !== $cursor && (int) $key >= $cursor ) ) {
					throw new ContractException( 'storage_unavailable', 'Editor history returned an invalid descending window.' );
				}
				$cursor = (int) $key;
				++$scanned;
				$boundary = $row;
				if ( ! $known_safe( $row ) ) {
					$row['unresolved'] = true;
					$blockers[]        = $row;
					if ( 21 === count( $blockers ) ) {
						return $blockers;
					}
				}
			}
			$complete = count( $page ) < $limit;
			unset( $page, $row );
			if ( $complete ) {
				return $blockers;
			}
		}
		return self::blocking_window( $blockers, $boundary );
	}

	/**
	 * Preserve any older unknown work behind a bounded conservative scan.
	 *
	 * @param array      $rows Classified rows or the retained true blockers.
	 * @param array|null $boundary Last raw row when the 501-row scan was saturated.
	 * @return array At most 21 blockers, including a saturation boundary when needed.
	 */
	private static function blocking_window( array $rows, ?array $boundary = null ): array {
		$result = array_values( array_filter( $rows, static fn( array $row ): bool => (bool) $row['unresolved'] ) );
		// Reaching the scan boundary never certifies that all older work is safe.
		if ( null === $boundary && 501 === count( $rows ) ) {
			$boundary = $rows[500];
		}
		if ( null !== $boundary && count( $result ) < 21 && ! in_array( $boundary['run_key'], array_column( $result, 'run_key' ), true ) ) {
			$boundary['unresolved'] = true;
			$result[]               = $boundary;
		}
		return array_slice( $result, 0, 21 );
	}

	/**
	 * Recognize only a fully observed failed canvas candidate, never a native write.
	 *
	 * @param Database $db Read-only runtime connection.
	 * @param array    $row Exact owned conversation and latest run.
	 * @return bool Whether the complete no-write evidence is current.
	 */
	private function known_failed_candidate( Database $db, array $row ): bool {
		try {
			$context    = ( new \DirectoristAIPro\Validation\JsonDecoder( 1048576, 32 ) )->decode( $row['context_json'] );
			$checkpoint = ( new \DirectoristAIPro\Validation\JsonDecoder( 1048576, 32 ) )->decode( $row['checkpoint_json'] );
			$origin     = $context->builder_editor_origin ?? null;
			if ( ! self::valid_origin( $origin ) || 'editor_candidate' !== $origin->output_mode || ! self::same_document( $origin->working_document, $context->builder_document ?? null ) ) {
				return false;
			}
			$operations = $db->rows( 'SELECT * FROM ' . $db->table( 'operations' ) . ' WHERE run_id=%d ORDER BY id LIMIT 2', array( (int) $row['run_key'] ) );
			$plan       = self::failed_candidate_plan( $row, $origin, $checkpoint, $operations );
			if ( null === $plan ) {
				return false;
			}
			$loaded = ( new \DirectoristAIPro\Builder\BuilderDraftStageArtifactReader( $db ) )->historical_candidate_metadata( $plan );
			return $loaded->artifact->version === $checkpoint->review->artifact->version && self::same_failed_candidate_stage( $row, $origin, $plan, $loaded->metadata );
		} catch ( \Throwable $error ) {
			// Missing, stale or malformed evidence never becomes permission to retry.
			return false;
		}
	}

	/**
	 * Validate the complete immutable receipt before reading its reviewed artifact.
	 *
	 * @param array  $row Exact owned conversation and latest run.
	 * @param object $origin Verified signed editor origin.
	 * @param object $checkpoint Original terminal run checkpoint.
	 * @param array  $operations All operations for this run, including foreign actors.
	 * @return \DirectoristAIPro\Effects\EffectPlan|null Exact read-only plan or no classification.
	 */
	private static function failed_candidate_plan( array $row, object $origin, object $checkpoint, array $operations ): ?\DirectoristAIPro\Effects\EffectPlan {
		if ( 'failed' !== ( $row['state'] ?? null ) || 'completed' !== ( $checkpoint->step ?? null ) || 1 !== count( $operations ) || in_array( $checkpoint->error ?? null, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'storage_unavailable' ), true ) ) {
			return null;
		}
		$operation = $operations[0];
		$receipt   = $checkpoint->receipt ?? null;
		$plan      = $receipt->plan ?? null;
		$review    = $checkpoint->review ?? null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Compare immutable evidence identities without coercion.
		if ( ! self::exact_members( $receipt, array( 'operation_id', 'plan_hash', 'proposal_hash', 'target_set_hash', 'state', 'plan', 'targets', 'external_effects', 'observed_at' ) ) || ! self::exact_members( $plan, array( 'operation_id', 'actor_ref', 'capability', 'capability_revision', 'proposal_hash', 'run_id', 'approval_id', 'targets' ) ) || 'failed' !== $receipt->state || 'failed' !== ( $operation['state'] ?? null ) || 'builder.stage' !== $plan->capability || '1' !== $plan->capability_revision || ! is_array( $plan->targets ) || 1 !== count( $plan->targets ) || ! $receipt->targets instanceof \stdClass || array() !== $receipt->external_effects || ! is_string( $receipt->observed_at ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $receipt->observed_at ) || ! is_int( $plan->run_id ) || $plan->run_id < 1 || (string) $plan->run_id !== (string) ( $row['run_key'] ?? '' ) || ! is_int( $plan->approval_id ) || $plan->approval_id < 1 || $plan->actor_ref !== ( $row['owner_ref'] ?? null ) || $plan->actor_ref !== ( $origin->actor_ref ?? null ) || 'editor_candidate' !== ( $origin->output_mode ?? null ) ) {
			return null;
		}
		$target = $plan->targets[0];
		if ( ! self::exact_members( $target, array( 'key', 'expected_version', 'effect_hash' ) ) || ! is_string( $target->key ) || ! is_string( $target->expected_version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $target->expected_version ) || ! is_string( $target->effect_hash ) ) {
			return null;
		}
		$outcomes = get_object_vars( $receipt->targets );
		$outcome  = $outcomes[ $target->key ] ?? null;
		if ( 1 !== count( $outcomes ) || ! self::exact_members( $outcome, array( 'key', 'expected_version', 'effect_hash', 'fence', 'status', 'before_version', 'after_version', 'effects', 'external_effects', 'diagnostic' ) ) || ! in_array( $outcome->status, array( 'failed', 'no_effect' ), true ) || $outcome->key !== $target->key || $outcome->expected_version !== $target->expected_version || $outcome->effect_hash !== $target->effect_hash || $outcome->before_version !== $target->expected_version || $outcome->after_version !== $target->expected_version || ! $outcome->effects instanceof \stdClass || array() !== get_object_vars( $outcome->effects ) || array() !== $outcome->external_effects || ! is_int( $outcome->fence ) || $outcome->fence < 1 || ( null !== $outcome->diagnostic && ( ! is_string( $outcome->diagnostic ) || strlen( $outcome->diagnostic ) > 256 ) ) ) {
			return null;
		}
		$trusted        = new \DirectoristAIPro\Effects\EffectPlan( $plan->operation_id, $plan->actor_ref, $plan->capability, $plan->capability_revision, $plan->proposal_hash, array( new \DirectoristAIPro\Effects\EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), $plan->run_id, $plan->approval_id );
		$canonical      = new JsonCanonicalizer();
		$stored_receipt = ( new \DirectoristAIPro\Validation\JsonDecoder( 1048576, 32 ) )->decode( $operation['receipt_json'] ?? 'null' );
		if ( $canonical->encode( $stored_receipt ) !== $canonical->encode( $receipt ) || $canonical->encode( $checkpoint->target_context->builder_editor_origin ?? null ) !== $canonical->encode( $origin ) || $canonical->encode( $trusted->data() ) !== $canonical->encode( $plan ) || $receipt->operation_id !== $trusted->operation_id || $receipt->proposal_hash !== $trusted->proposal_hash || $receipt->plan_hash !== $trusted->hash() || $receipt->target_set_hash !== $trusted->target_set_hash() || ( $review->capability ?? null ) !== 'builder.stage' || ( $review->operation_id ?? null ) !== $trusted->operation_id || ( $review->artifact->id ?? null ) !== $trusted->operation_id || ( $review->artifact->hash ?? null ) !== $trusted->proposal_hash || ! is_int( $review->artifact->version ?? null ) || $review->artifact->version < 1 ) {
			return null;
		}
		foreach ( array(
			'public_id'           => $trusted->operation_id,
			'run_id'              => $trusted->run_id,
			'actor_ref'           => $trusted->actor_ref,
			'capability'          => $trusted->capability,
			'capability_revision' => $trusted->capability_revision,
			'proposal_hash'       => $trusted->proposal_hash,
			'target_set_hash'     => $trusted->target_set_hash(),
			'approval_id'         => $trusted->approval_id,
			'reconciliation_key'  => $trusted->hash(),
		) as $key => $value ) {
			if ( (string) ( $operation[ $key ] ?? '' ) !== (string) $value ) {
				return null;
			}
		}
		return $trusted;
	}

	/**
	 * Bind a verified no-write canvas artifact to the exact history grouping.
	 *
	 * @param array                                $row Exact owned history row.
	 * @param object                               $origin Verified signed origin.
	 * @param \DirectoristAIPro\Effects\EffectPlan $plan Verified single-target plan.
	 * @param object                               $stage Exact reviewed stage payload.
	 * @return bool Whether all immutable grouping identities match.
	 */
	private static function same_failed_candidate_stage( array $row, object $origin, \DirectoristAIPro\Effects\EffectPlan $plan, object $stage ): bool {
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both operands are immutable independently verified identities.
		return 'editor_candidate' === ( $stage->output_mode ?? null ) && false === ( $stage->saved ?? null ) && $plan->operation_id === ( $stage->candidate_id ?? null ) && $row['public_id'] === ( $stage->conversation_id ?? null ) && $row['run_public_id'] === ( $stage->run_id ?? null ) && $plan->targets[0]->expected_version === ( $stage->source_hash ?? null ) && $origin->capture_id === ( $stage->capture_id ?? null ) && $origin->captured_snapshot_hash === ( $stage->captured_snapshot_hash ?? null ) && self::same_document( $origin->original_document, $stage->original_document ?? null ) && self::same_document( $origin->working_document, $stage->source_document ?? null );
	}

	/**
	 * Require closed evidence objects, including explicitly empty effect objects.
	 *
	 * @param mixed $value Candidate evidence object.
	 * @param array $expected Exact accepted property names.
	 * @return bool Whether every property is present without extras.
	 */
	private static function exact_members( $value, array $expected ): bool {
		if ( ! $value instanceof \stdClass ) {
			return false;
		}
		$keys = array_keys( get_object_vars( $value ) );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}

	/**
	 * Project a trusted exact grouping into explicitly non-executable metadata.
	 *
	 * @param array  $row Owned conversation and latest run.
	 * @param object $document Exact original document.
	 * @param array  $legacy Existing signed lineage.
	 * @return array|null Public task metadata or rejected grouping.
	 */
	private function task( array $row, object $document, array $legacy ): ?array {
		$stored  = json_decode( $row['context_json'] );
		$working = $stored->builder_document ?? null;
		$origin  = $stored->builder_editor_origin ?? null;
		$durable = null !== $origin;
		if ( null !== $origin ) {
			if ( ! self::valid_origin( $origin ) || ! self::same_document( $origin->original_document, $document ) || ! self::same_document( $origin->working_document, $working ) ) {
				return null;
			}
		} elseif ( isset( $legacy[ $working->native_id ?? '' ] ) ) {
			$origin = $legacy[ $working->native_id ];
		} elseif ( self::same_document( $working, $document ) ) {
			$origin = (object) array(
				'original_document'      => $document,
				'working_document'       => $document,
				'capture_id'             => null,
				'captured_snapshot_hash' => null,
				'output_mode'            => 'private_draft',
				'editor_session'         => null,
			);
		} else {
			return null;
		}
		if ( ! self::same_document( $origin->original_document, $document ) || ! self::same_document( $origin->working_document, $working ) ) {
			return null;
		}
		$selected = self::selection_identity( $stored->builder_selection ?? null );
		$original = $durable && 2 === ( $origin->origin_version ?? null ) ? ( $origin->original_selection ?? null ) : null;
		$thread   = null !== $selected && $durable && 2 === ( $origin->origin_version ?? null ) ? $origin->history_thread : null;
		// Legacy native IDs remain usable read-only identities. Legacy ordinal
		// selections cannot borrow the most recently recaptured hidden source's
		// thread or path: that source may now belong to another native element.
		if ( null !== $selected && null === $original && null !== $selected->native_node_id ) {
			$original = $selected;
		}
		if ( null !== $original && null !== ( $original->native_node_id ?? null ) && ( $selected->native_node_id ?? null ) !== $original->native_node_id ) {
			$original = null;
			$thread   = null;
		}
		$scope         = null === ( $stored->builder_selection ?? null ) ? 'page' : ( null !== $selected && ( null !== $thread || null !== ( $original->native_node_id ?? null ) ) ? 'area' : 'unknown' );
		$checkpoint    = json_decode( $row['checkpoint_json'] ?? 'null' );
		$request_label = $checkpoint->intent->text ?? null;
		if ( is_string( $request_label ) && str_starts_with( $request_label, 'Prepare native editor changes.' ) ) {
			$request_label = preg_match( '/\nUser request: (.*?)(?:\nEarlier conversation|$)/s', $request_label, $match ) ? $match[1] : null;
		}
		$request_label = is_string( $request_label ) ? mb_substr( trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $request_label ) ) ), 0, 160 ) : null;
		$code          = $checkpoint instanceof \stdClass ? \DirectoristAIPro\Runtime\RunStore::generation_failure_code( $checkpoint ) : null;
		$allowed       = array( 'provider_unavailable', 'managed_unavailable', 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'managed_failed', 'managed_request_rejected', 'unsupported_provider_capability', 'provider_configuration_changed', 'budget_exhausted', 'input_context_too_large', 'builder_layout_unavailable', 'builder_preparation_interrupted', 'output_truncated', 'usage_bound_exceeded', 'invalid_output', 'grounded_response_required', 'stale_evidence', 'evidence_stale', 'stale_version', 'storage_unavailable', 'context_unavailable', 'unsupported_dependency', 'unsupported_contract', 'unsupported_operation', 'scope_conflict', 'native_invalid', 'unauthorized', 'not_found', 'unknown_outcome', 'builder_field_unavailable', 'builder_composition_unavailable' );
		$candidate     = 'editor_candidate' === $origin->output_mode && 'builder.stage' === ( $checkpoint->review->capability ?? null ) ? ( $checkpoint->review->operation_id ?? null ) : null;
		return array(
			'conversationId'      => $row['public_id'],
			'runId'               => $row['run_public_id'],
			'state'               => $row['state'] ?? 'not_started',
			'version'             => null === $row['state_version'] ? 0 : (int) $row['state_version'],
			'cancelRequested'     => ! empty( $row['cancel_requested'] ),
			'failureCode'         => is_string( $code ) && in_array( $code, $allowed, true ) ? $code : null,
			'originalDocument'    => $origin->original_document,
			'workingDocument'     => $origin->working_document,
			'captureId'           => $origin->capture_id,
			'snapshotHash'        => $origin->captured_snapshot_hash,
			'outputMode'          => $origin->output_mode,
			'editorSession'       => $origin->editor_session,
			'candidateId'         => is_string( $candidate ) && 1 === preg_match( '/^builder-stage-[A-Za-z0-9_.:-]{1,112}$/D', $candidate ) ? $candidate : null,
			'applicationReported' => 'succeeded' === ( $row['state'] ?? null ) && is_string( $candidate ) && ! empty( $row['application_reported'] ),
			'historyScope'        => $scope,
			'requestLabel'        => $request_label,
			// Display-only historical label, never a locator or editing grant.
			'areaLabel'           => 'area' === $scope && is_string( $stored->builder_selection->label ?? null ) ? mb_substr( trim( wp_strip_all_tags( $stored->builder_selection->label ) ), 0, 160 ) : null,
			'historyThread'       => 'area' === $scope ? $thread : null,
			'originalSelection'   => 'area' === $scope ? $original : null,
			'recovered'           => true,
			'requiresResolution'  => (bool) $row['unresolved'],
			'createdAt'           => $row['created_at'],
			'updatedAt'           => $row['updated_at'],
		);
	}

	/**
	 * Copy a validated signed native record into a separate durable grouping proof.
	 *
	 * @param array  $record Existing verified native capture record.
	 * @param object $working Exact bound working document.
	 * @return object|null Closed server-only origin record.
	 */
	private static function origin_from_record( array $record, object $working ): ?object {
		if ( ( $record['site_id'] ?? null ) !== get_current_blog_id() || ( $record['user_id'] ?? null ) !== get_current_user_id() || ! self::valid_document( $record['document'] ?? null ) || ! self::same_document( $record['working_document'] ?? null, $working ) || ! is_string( $record['capture_id'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $record['capture_id'] ) || ! is_string( $record['captured_snapshot_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['captured_snapshot_hash'] ) || ! is_string( $record['editor_session'] ?? null ) || ! preg_match( '/^[a-f0-9]{32}$/D', $record['editor_session'] ) || ! in_array( $record['output_mode'] ?? null, array( 'editor_candidate', 'private_draft' ), true ) ) {
			return null;
		}
		$selection = self::selection_identity( $record['baseline_selection'] ?? null );
		$thread    = $record['history_thread'] ?? null;
		if ( null !== $thread && ( null === $selection || ! is_string( $thread ) || ! preg_match( '/^[a-f0-9]{32}$/D', $thread ) ) ) {
			return null;
		}
		if ( array_key_exists( 'history_element_id', $record ) && ( null === $selection || ! in_array( $record['document']->builder, array( 'gutenberg', 'divi' ), true ) || ! is_string( $record['history_element_id'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $record['history_element_id'] ) || $thread !== $record['history_element_id'] ) ) {
			return null;
		}
		$origin            = (object) array(
			'origin_version'         => 2,
			'site_id'                => get_current_blog_id(),
			'actor_ref'              => 'user:' . get_current_user_id(),
			'original_document'      => $record['document'],
			'working_document'       => $record['working_document'],
			'capture_id'             => $record['capture_id'],
			'captured_snapshot_hash' => $record['captured_snapshot_hash'],
			'output_mode'            => $record['output_mode'],
			'editor_session'         => $record['editor_session'],
			'original_selection'     => $selection,
			'history_thread'         => $thread,
		);
		$origin->signature = self::signature( $origin );
		return $origin;
	}

	/**
	 * Reject forged, cross-site or cross-owner durable origin metadata.
	 *
	 * @param mixed $origin Stored origin.
	 * @return bool Whether its closed server-only signature is current.
	 */
	private static function valid_origin( $origin ): bool {
		if ( ! $origin instanceof \stdClass || ! is_string( $origin->signature ?? null ) ) {
			return false;
		}
		$unsigned = clone $origin;
		unset( $unsigned->signature );
		$expected = array( 'origin_version', 'site_id', 'actor_ref', 'original_document', 'working_document', 'capture_id', 'captured_snapshot_hash', 'output_mode', 'editor_session' );
		$version  = $origin->origin_version ?? null;
		if ( 2 === $version ) {
			$expected[] = 'original_selection';
			$expected[] = 'history_thread';
			$selection  = null === ( $origin->original_selection ?? null ) ? null : self::selection_identity( $origin->original_selection );
			if ( ( null !== ( $origin->original_selection ?? null ) && ( null === $selection || ( new JsonCanonicalizer() )->encode( $selection ) !== ( new JsonCanonicalizer() )->encode( $origin->original_selection ) ) ) || ( null !== ( $origin->history_thread ?? null ) && ( null === $selection || ! is_string( $origin->history_thread ) || ! preg_match( '/^[a-f0-9]{32}$/D', $origin->history_thread ) ) ) ) {
				return false;
			}
		}
		$keys = array_keys( get_object_vars( $unsigned ) );
		sort( $expected );
		sort( $keys );
		return $keys === $expected && in_array( $version, array( 1, 2 ), true ) && get_current_blog_id() === ( $origin->site_id ?? null ) && 'user:' . get_current_user_id() === ( $origin->actor_ref ?? null ) && self::valid_document( $origin->original_document ?? null ) && self::valid_document( $origin->working_document ?? null ) && is_string( $origin->capture_id ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $origin->capture_id ) && is_string( $origin->captured_snapshot_hash ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $origin->captured_snapshot_hash ) && is_string( $origin->editor_session ?? null ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $origin->editor_session ) && in_array( $origin->output_mode ?? null, array( 'editor_candidate', 'private_draft' ), true ) && hash_equals( self::signature( $unsigned ), $origin->signature );
	}

	/**
	 * Keep only bounded native selection identity, without labels or authority.
	 *
	 * @param mixed $selection Native browser or stored selection.
	 * @return object|null Exact native identity or unsupported selection.
	 */
	private static function selection_identity( $selection ): ?object {
		if ( ! $selection instanceof \stdClass ) {
			return null;
		}
		$id   = $selection->native_node_id ?? null;
		$path = $selection->slot_path ?? null;
		if ( ( null !== $id && ( ! is_string( $id ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $id ) ) ) || ( null !== $path && ( ! is_array( $path ) || ! $path || count( $path ) > 32 ) ) || ( null === $id && null === $path ) ) {
			return null;
		}
		foreach ( $path ?? array() as $part ) {
			if ( ! is_string( $part ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $part ) ) {
				return null;
			}
		}
		return (object) array(
			'native_node_id' => $id,
			'slot_path'      => $path,
		);
	}

	/**
	 * Sign private grouping metadata without retaining a working token.
	 *
	 * @param object $origin Closed unsigned metadata.
	 * @return string Site-secret proof.
	 */
	private static function signature( object $origin ): string {
		return hash_hmac( 'sha256', ( new JsonCanonicalizer() )->encode( $origin ), wp_salt( 'auth' ) );
	}

	/**
	 * Validate the exact native descriptor vocabulary.
	 *
	 * @param mixed $document Native document descriptor.
	 * @return bool Exact closed shape.
	 */
	private static function valid_document( $document ): bool {
		if ( ! $document instanceof \stdClass ) {
			return false;
		}
		$keys = array_keys( get_object_vars( $document ) );
		sort( $keys );
		return array( 'builder', 'native_id', 'native_kind' ) === $keys && in_array( $document->builder, array( 'gutenberg', 'elementor', 'divi' ), true ) && is_string( $document->native_id ) && is_string( $document->native_kind ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $document->native_id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/D', $document->native_kind );
	}

	/**
	 * Compare two closed native descriptor identities.
	 *
	 * @param mixed $left First descriptor.
	 * @param mixed $right Second descriptor.
	 * @return bool Exact identity.
	 */
	private static function same_document( $left, $right ): bool {
		return self::valid_document( $left ) && self::valid_document( $right ) && ( new JsonCanonicalizer() )->encode( $left ) === ( new JsonCanonicalizer() )->encode( $right );
	}
}
