<?php
/**
 * Authenticated and verified-guest conversation transport.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Runtime;

use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\GuestSessions;
use DirectoristAIPro\Chat\Operations;
use DirectoristAIPro\Storage\ArtifactStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Storage\StorageConflictException;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\ContractValidator;
use DirectoristAIPro\Validation\JsonDecoder;
use DirectoristAIPro\Validation\SchemaRegistry;

/** HTTP views durable state; explicit POST wakes confer no worker authority. */
final class ConversationApi {
	/**
	 * Runtime database.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Strict request validator.
	 *
	 * @var ContractValidator
	 */
	private ContractValidator $validator;
	/**
	 * Owner/scope-bound cursor codec.
	 *
	 * @var EventCursor
	 */
	private EventCursor $cursors;
	/**
	 * Optional durable dispatcher; committed turns remain recoverable if absent.
	 *
	 * @var Dispatcher|null
	 */
	private ?Dispatcher $dispatcher;
	/**
	 * Review lifecycle when enabled.
	 *
	 * @var ApprovalService|null
	 */
	private ?ApprovalService $approvals;
	/**
	 * Optional current-source resolver for grounded event markers.
	 *
	 * @var RunResultResolver|null
	 */
	private ?RunResultResolver $results;
	/**
	 * Trusted assistant replay verifier; unknown provenance fails closed.
	 *
	 * @var ConversationHistoryVerifier
	 */
	private ConversationHistoryVerifier $history_verifier;
	/** Request-local response completion; never grants run or native authority. */
	private GenerationResponseDispatch $generation_dispatch;

	/**
	 * Bind runtime services; preserve both published fifth-argument variants.
	 *
	 * @param Database                                           $db Runtime database.
	 * @param EventCursor|null                                   $cursors Test cursor codec.
	 * @param Dispatcher|null                                    $dispatcher Durable coordinator.
	 * @param ApprovalService|null                               $approvals Review lifecycle.
	 * @param RunResultResolver|ConversationHistoryVerifier|null $results Prior optional seam.
	 * @param ConversationHistoryVerifier|null                   $history_verifier Explicit replay policy.
	 * @param GenerationResponseDispatch|null                    $generation_dispatch Trusted response transport seam.
	 */
	public function __construct( Database $db, ?EventCursor $cursors = null, ?Dispatcher $dispatcher = null, ?ApprovalService $approvals = null, RunResultResolver|ConversationHistoryVerifier|null $results = null, ?ConversationHistoryVerifier $history_verifier = null, ?GenerationResponseDispatch $generation_dispatch = null ) {
		$this->db               = $db;
		$this->cursors          = $cursors ?? new EventCursor( wp_salt( 'auth' ) );
		$this->dispatcher       = $dispatcher;
		$this->approvals        = $approvals;
		$this->results          = $results instanceof RunResultResolver ? $results : null;
		$this->history_verifier = $history_verifier ?? ( $results instanceof ConversationHistoryVerifier ? $results : new NativeConversationHistoryVerifier( $db ) );
		$this->generation_dispatch = $generation_dispatch ?? new GenerationResponseDispatch();
		$schemas                = new SchemaRegistry();
		$schemas->register( 'conversation.create', '{"type":"object","properties":{"request_id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[a-zA-Z0-9_.:-]+$"},"profile":{"enum":["admin","owner","visitor","builder"]},"directory_id":{"type":["string","null"],"maxLength":128},"target":{"type":["string","null"],"maxLength":128},"refinement_source":{"type":"object","properties":{"operation_id":{"type":"string","pattern":"^[A-Za-z0-9_.:-]{1,128}$"},"native_version":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["operation_id","native_version"],"additionalProperties":false},"builder_scope":{"type":"object","properties":{"directory_id":{"type":["string","null"],"pattern":"^[1-9][0-9]{0,127}$"},"view":{"enum":[null,"grid","list","map","single"]},"breakpoint_id":{"type":"null"},"interaction":{"enum":[null,"normal","hover"]}},"required":["directory_id","view","breakpoint_id","interaction"],"additionalProperties":false},"builder_document":{"type":"object","properties":{"builder":{"enum":["gutenberg","elementor","divi"]},"native_kind":{"type":"string","pattern":"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$"},"native_id":{"type":"string","pattern":"^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$"}},"required":["builder","native_kind","native_id"],"additionalProperties":false},"builder_area_memory":{"type":"object"},"builder_selection":{"type":"object","properties":{"native_node_id":{"type":["string","null"],"minLength":1,"maxLength":128},"slot_path":{"type":["array","null"],"minItems":1,"maxItems":32,"items":{"type":"string","minLength":1,"maxLength":128}},"label":{"type":"string","maxLength":160},"source_stamp":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["native_node_id","slot_path","label","source_stamp"],"additionalProperties":false}},"required":["request_id","profile","directory_id","target"],"additionalProperties":false}' );
		$schemas->register( 'visitor.clear', '{"type":"object","properties":{"conversation_id":{"type":"string","pattern":"^[A-Za-z0-9_.:-]{1,128}$"}},"required":["conversation_id"],"additionalProperties":false}' );
		$schemas->register( 'turn.submit', '{"type":"object","properties":{"request_id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[a-zA-Z0-9_.:-]+$"},"input":{"type":"object"}},"required":["request_id","input"],"additionalProperties":false}' );
		$schemas->register( 'run.cancel', '{"type":"object","properties":{"request_id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[a-zA-Z0-9_.:-]+$"},"expected_run_version":{"type":"integer","minimum":1}},"required":["request_id","expected_run_version"],"additionalProperties":false}' );
		$schemas->register( 'run.advance', '{"type":"object","properties":{},"additionalProperties":false}' );
		$schemas->register( 'review.decision', '{"type":"object","properties":{"request_id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[a-zA-Z0-9_.:-]+$"},"decision":{"enum":["approve","reject"]},"review_hash":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["request_id","decision","review_hash"],"additionalProperties":false}' );
		$schemas->register( 'run.review', '{"type":"object","properties":{"request_id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[a-zA-Z0-9_.:-]+$"},"expected_run_version":{"type":"integer","minimum":1},"approval_id":{"type":"string","minLength":1,"maxLength":128},"review_hash":{"type":"string","pattern":"^[a-f0-9]{64}$"},"decision":{"enum":["approve","reject"]},"accepted_terms":{"type":"boolean"},"accepted_privacy":{"type":"boolean"},"accepted_media_hash":{"type":"string","pattern":"^[a-f0-9]{64}$"}},"required":["request_id","expected_run_version","approval_id","review_hash","decision"],"additionalProperties":false}' );
		$this->validator = new ContractValidator( $schemas );
	}

	/** Register routes under the versioned product namespace. */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'private_transport' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_event_stream' ), 10, 4 );
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/conversations/(?P<id>[a-zA-Z0-9_.:-]{1,128})/updates',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'visitor_updates' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/runs/(?P<id>[a-zA-Z0-9_.:-]{1,128})/advance',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'visitor_advance' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/listing-actions',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'visitor_listing_actions' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/guest-request',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'guest_request' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/visitor/conversation',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function ( \WP_REST_Request $request ) {
						$data = $this->visitor_current( $request );
						if ( is_wp_error( $data ) ) {
							return $data;
						}
						$response = new \WP_REST_Response( $data );
						$response->header( 'Cache-Control', 'private, no-store' );
						return $response;
					},
				),
				array(
					'methods'             => 'PATCH',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'visitor_language' ),
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => '__return_true',
					'callback'            => array( $this, 'visitor_clear' ),
				),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/builder/area-history',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'area_history' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/conversations',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'create' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/conversations/(?P<id>[a-zA-Z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'history' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/conversations/(?P<id>[a-zA-Z0-9_.:-]{1,128})/turns',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'turn' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/runs/(?P<id>[a-zA-Z0-9_.:-]{1,128})/events',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'events' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/runs/(?P<id>[a-zA-Z0-9_.:-]{1,128})/cancel',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'cancel' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/artifacts/(?P<id>[a-zA-Z0-9_.:-]{1,128})/versions/(?P<version>[1-9][0-9]*)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'artifact' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/reviews/(?P<id>[a-zA-Z0-9_.:-]{1,128})',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'review' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/reviews/(?P<id>[a-zA-Z0-9_.:-]{1,128})/decision',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'decision' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/runs/(?P<id>[a-zA-Z0-9_.:-]{1,128})/review',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'run_review' ),
			)
		);
	}

	/**
	 * Prevent shared caches from retaining guest histories, actions or failures.
	 *
	 * @param mixed            $response Dispatched response.
	 * @param \WP_REST_Server  $server Current REST server.
	 * @param \WP_REST_Request $request Current route.
	 * @return mixed Original response with its private transport policy.
	 */
	public function private_transport( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( $response instanceof \WP_HTTP_Response && preg_match( '#^/directorist-ai/v1/(?:visitor(?:/|$)|conversations(?:/|$)|runs(?:/|$)|guest-session(?:/|$)|builder/area-history(?:/|$))#D', $request->get_route() ) ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	/**
	 * Lookup private area discussions; this grants no ability or review authority.
	 *
	 * @param \WP_REST_Request $request Current area observations.
	 * @throws ContractException Missing or invalid area context.
	 */
	public function area_history( \WP_REST_Request $request ) {
		try {
			$context = $this->context( $request, 'builder' );
			$this->check_profile( $context );
			$input = ( new JsonDecoder( 8192, 16 ) )->decode( $request->get_body() );
			if ( ! $input instanceof \stdClass ) {
				throw new ContractException( 'invalid_input', 'An area discussion context is required.' );
			}
			$response = new \WP_REST_Response( ( new \DirectoristAIPro\Builder\BuilderAreaHistory( $this->db ) )->lookup( $input ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Create an owned conversation without invoking a model.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ContractException Invalid builder scope or unavailable profile.
	 */
	public function create( \WP_REST_Request $request ) {
		try {
			if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
				throw new ContractException( 'provider_unavailable', 'AI work is paused by the site administrator.' );
			}
			$input   = $this->validator->decode( $request->get_body(), 'conversation.create' );
			$context = $this->context( $request, $input->profile );
			$this->check_profile( $context );
			if ( 'visitor' === $input->profile ) {
				$this->assert_visitor_directory( $input->directory_id );
			}
			if ( isset( $input->builder_scope ) && ( 'builder' !== $input->profile || $input->directory_id !== $input->builder_scope->directory_id ) ) {
				throw new ContractException( 'invalid_input', 'Builder scope differs from its conversation directory.' );
			}
			if ( isset( $input->builder_document ) && ( 'builder' !== $input->profile || ! isset( $input->builder_scope ) || $input->target !== $input->builder_document->native_id ) ) {
				throw new ContractException( 'invalid_input', 'Builder document differs from its conversation target.' );
			}
			$task_context = (object) array(
				'directory_id' => $input->directory_id,
				'target'       => $input->target,
			);
			if ( 'visitor' === $input->profile && null === $input->directory_id ) {
				$task_context->global_search = true;
			}
			if ( isset( $input->builder_scope ) ) {
				$task_context->builder_scope = $input->builder_scope;
			}
			if ( isset( $input->builder_document ) ) {
				$task_context->builder_document = $input->builder_document;
				$editor_origin                  = \DirectoristAIPro\UI\EditorConversationHistory::bind_origin( $input->builder_document );
				if ( null !== $editor_origin ) {
					$task_context->builder_editor_origin = $editor_origin;
				}
			}
			if ( isset( $input->builder_selection ) ) {
				if ( 'builder' !== $input->profile || ! isset( $input->builder_document ) ) {
					throw new ContractException( 'invalid_input', 'A selected area requires its native builder document.' );
				}
				\DirectoristAIPro\Builder\BuilderEditorSelection::assert_source( $input->builder_selection, $input->builder_document );
				$task_context->builder_selection = $input->builder_selection;
			}
			if ( isset( $input->refinement_source ) ) {
				$task_context->builder_parent = ( new \DirectoristAIPro\Builder\BuilderDraftResultApi( $this->db ) )->bind_refinement( $input );
			}
			if ( isset( $input->builder_area_memory ) ) {
				if ( 'builder' !== $input->profile || ! isset( $task_context->builder_document, $task_context->builder_selection ) ) {
					throw new ContractException( 'invalid_input', 'Area discussions require a fresh scoped builder capture.' );
				}
				$memory                            = new \DirectoristAIPro\Builder\BuilderAreaHistory( $this->db );
				$task_context->builder_area_memory = $memory->bind( $input->builder_area_memory, $task_context->builder_document, $task_context->builder_selection, $task_context->builder_scope );
				$input->request_id                 = $memory->request_id( $task_context->builder_area_memory, $input->request_id );
			}
			$repository = 'visitor' === $input->profile ? new \DirectoristAIPro\Chat\VisitorConversations( $this->db ) : new ConversationStore( $this->db );
			$arguments  = array(
				$context->actor()->owner(),
				$input->request_id,
				$task_context,
				( $context->actor()->is_guest() ? Operations::settings()['guest_retention_days'] : Operations::settings()['user_retention_days'] ) * DAY_IN_SECONDS,
			);
			if ( 'visitor' !== $input->profile ) {
				array_splice( $arguments, 2, 0, array( $input->profile ) );
			}
			$row = $repository->create( ...$arguments );
			return new \WP_REST_Response(
				array(
					'id'            => $row['public_id'],
					'profile'       => $row['profile'],
					'state_version' => (int) $row['state_version'],
				),
				201
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Return an owned visible-message page with a signed continuation cursor.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 * @throws ContractException Caught and converted when replay authority changes.
	 */
	public function history( \WP_REST_Request $request ) {
		try {
			$actor  = $this->actor( $request );
			$id     = (string) $request['id'];
			$cursor = $request->get_param( 'cursor' );
			$before = $request->get_param( 'before' );
			$tail   = '1' === $request->get_param( 'tail' );
			if ( $tail && is_string( $before ) && '' !== $before ) {
				$cursor = $before;
			}
			$after        = is_string( $cursor ) && '' !== $cursor ? $this->cursors->decode( $actor->owner(), 'conversation:' . $id, $cursor ) : 0;
			$parameter    = $request->get_param( 'limit' );
			$limit        = min( 100, max( 1, (int) ( $parameter ? $parameter : 50 ) ) );
			$store        = new ConversationStore( $this->db );
			$conversation = $store->get( $actor->owner(), $id );
			$page         = $store->history( $actor->owner(), $id, $after, $limit, $tail );
			$context      = new Context( $actor, $page['profile'], 'ui' );
			$this->check_profile( $context );
			$accepted = array();
			foreach ( $page['messages'] as &$message ) {
				$raw     = $message;
				$visible = $this->history_content( $context, $raw, (int) $conversation['id'] );
				if ( 'assistant' === $raw['role'] && $visible['available'] ) {
					$accepted[ (int) $raw['sequence'] ] = $raw;
				}
				if ( 'visitor' === $page['profile'] ) {
					$turn_input              = json_decode( $raw['input_json'] );
					$message['directory_id'] = $turn_input->directory_id ?? json_decode( $conversation['context_json'] )->directory_id ?? null;
				}
				$message['content']         = $visible['content'];
				$message['source_refs']     = $visible['source_refs'];
				$message['content_status']  = $visible['available'] ? 'available' : 'withheld';
				$message['evidence_status'] = $visible['evidence_status'] ?? null;
				$message['sequence']        = (int) $raw['sequence'];
				unset( $message['content_json'], $message['source_refs_json'], $message['turn_id'], $message['input_json'] );
			}
			unset( $message );
			do_action( 'directorist_ai_pro_history_before_delivery', $id );
			$actor->refresh();
			$context->assert_execution_identity();
			$this->check_profile( $context );
			$fresh_conversation = $store->get( $actor->owner(), $id );
			if ( (int) $fresh_conversation['id'] !== (int) $conversation['id'] || $fresh_conversation['profile'] !== $page['profile'] ) {
				throw new ContractException( 'evidence_stale', 'Conversation changed during history delivery.' );
			}
			foreach ( $page['messages'] as &$message ) {
				$sequence = (int) $message['sequence'];
				if ( ! isset( $accepted[ $sequence ] ) ) {
					continue;
				}
				$rows          = $this->db->rows( 'SELECT sequence,turn_id,role,content_json,source_refs_json FROM ' . $this->db->table( 'messages' ) . ' WHERE conversation_id=%d AND sequence=%d', array( (int) $conversation['id'], $sequence ) );
				$before        = $accepted[ $sequence ];
				$still_current = 1 === count( $rows ) && $rows[0]['turn_id'] === $before['turn_id'] && $rows[0]['role'] === $before['role'] && $rows[0]['content_json'] === $before['content_json'] && $rows[0]['source_refs_json'] === $before['source_refs_json'];
				$visible       = $still_current ? $this->history_content( $context, $rows[0], (int) $conversation['id'] ) : array( 'available' => false );
				if ( ! $visible['available'] ) {
					$message['content']         = $visible['content'] ?? (object) array( 'text' => 'This earlier assistant message is unavailable.' );
					$message['source_refs']     = array();
					$message['content_status']  = 'withheld';
					$message['evidence_status'] = $visible['evidence_status'] ?? null;
				} else {
					$message['content']         = $visible['content'];
					$message['source_refs']     = $visible['source_refs'];
					$message['evidence_status'] = $visible['evidence_status'] ?? null;
				}
			}
			unset( $message );
			$runs          = $this->db->table( 'runs' );
			$conversations = $this->db->table( 'conversations' );
			$active        = $this->db->rows( "SELECT c.state_version,c.active_run_id,r.public_id AS active_run_public_id,r.state AS active_run_state,r.state_version AS active_run_version,r.trigger_turn_id AS active_trigger_turn_id FROM $conversations c LEFT JOIN $runs r ON r.id=c.active_run_id AND r.conversation_id=c.id AND r.owner_ref=c.owner_ref WHERE c.id=%d AND c.owner_ref=%s AND c.erasure_requested=0", array( (int) $fresh_conversation['id'], $actor->owner()->reference() ) );
			if ( ! $active || ( null !== $active[0]['active_run_id'] && null === $active[0]['active_run_public_id'] ) ) {
				throw new ContractException( 'evidence_stale', 'Active run changed during history delivery.' );
			}
			if ( 'visitor' === $page['profile'] ) {
				// Add advice only after the stored message and its native evidence have passed verification.
				foreach ( array_reverse( array_keys( $page['messages'] ) ) as $index ) {
					$last = $page['messages'][ $index ];
					if ( 'assistant' !== $last['role'] ) { continue; }
					if ( 'available' !== ( $last['content_status'] ?? null ) || ! isset( $accepted[ $last['sequence'] ] ) ) { break; }
					$advice_run = $this->db->rows( "SELECT checkpoint_json FROM $runs WHERE conversation_id=%d AND owner_ref=%s AND trigger_turn_id=%d AND state IN ('succeeded','waiting_input') ORDER BY id DESC LIMIT 1", array( (int) $fresh_conversation['id'], $actor->owner()->reference(), (int) $accepted[ $last['sequence'] ]['turn_id'] ) );
					$advice_checkpoint = $advice_run ? json_decode( $advice_run[0]['checkpoint_json'] ) : null;
					$proof = $advice_checkpoint->visitor_reply ?? null;
					if ( is_string( $proof->message ?? null ) && hash_equals( $proof->message, $last['content']->text ) ) {
						$page['messages'][ $index ]['content']->suggestions = \DirectoristAIPro\Orchestration\FollowUpSuggestions::visitor( $proof->suggestions ?? array(), $proof->listing_ids ?? array(), ! empty( $last['content']->grounded->native_actions->actions ) );
						// Presentation hint from this reply's captured run, never the latest
						// preference. It grants no evidence, scope or native action authority.
						$advice_language = $advice_checkpoint->response_language->reply->code ?? null;
						if ( is_string( $advice_language ) && strlen( $advice_language ) <= 35 && preg_match( '/^[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8}){0,3}$/D', $advice_language ) ) {
							$page['messages'][ $index ]['suggestions_language'] = str_replace( '_', '-', $advice_language );
						}
					}
					break;
				}
			}
			if ( 'builder' === $page['profile'] ) {
				$latest                      = $this->db->rows( "SELECT public_id,state,state_version,checkpoint_json FROM $runs WHERE conversation_id=%d AND owner_ref=%s ORDER BY id DESC LIMIT 1", array( (int) $fresh_conversation['id'], $actor->owner()->reference() ) );
				$memory                      = json_decode( $fresh_conversation['context_json'] )->builder_area_memory ?? null;
				$checkpoint                  = $latest ? json_decode( $latest[0]['checkpoint_json'] ) : null;
				$failure_code                = $checkpoint instanceof \stdClass ? RunStore::generation_failure_code( $checkpoint ) : null;
				$allowed_failures            = array( 'generation_stalled', 'provider_unavailable', 'managed_unavailable', 'provider_configuration_changed', 'unsupported_provider_capability', 'budget_exhausted', 'output_truncated', 'usage_bound_exceeded', 'invalid_output', 'grounded_response_required', 'stale_evidence', 'storage_unavailable', 'context_unavailable', 'unsupported_dependency', 'evidence_stale', 'stale_version', 'scope_conflict', 'unauthorized', 'not_found', 'unknown_outcome', 'builder_preparation_interrupted', 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'managed_failed', 'managed_request_rejected', 'builder_field_unavailable', 'builder_composition_unavailable' );
				$page['builder_suggestions'] = \DirectoristAIPro\Builder\BuilderAreaHistory::suggestions( wp_json_encode( (object) array( 'suggestions' => $checkpoint->builder_suggestions ?? array() ) ), $memory instanceof \stdClass ? $memory->capabilities : \DirectoristAIPro\Builder\BuilderAreaHistory::CAPABILITIES );
				$page['builder_task']        = $latest ? array(
					'run_id'        => $latest[0]['public_id'],
					'state'         => $latest[0]['state'],
					'state_version' => (int) $latest[0]['state_version'],
					'failure_code'  => is_string( $failure_code ) && in_array( $failure_code, $allowed_failures, true ) ? $failure_code : null,
				) : null;
			}
			$page['state_version']        = (int) $active[0]['state_version'];
			$page['active_run_id']        = null === $active[0]['active_run_id'] ? null : (int) $active[0]['active_run_id'];
			$page['active_run_public_id'] = $active[0]['active_run_public_id'];
			$page['continuation']         = null;
			if ( 'waiting_input' === $active[0]['active_run_state'] && null !== $active[0]['active_trigger_turn_id'] ) {
				$question_rows = $this->db->rows( 'SELECT sequence,turn_id,role,content_json,source_refs_json FROM ' . $this->db->table( 'messages' ) . ' WHERE conversation_id=%d AND turn_id=%d AND role=%s ORDER BY sequence DESC LIMIT 1', array( (int) $conversation['id'], (int) $active[0]['active_trigger_turn_id'], 'assistant' ) );
				if ( 1 === count( $question_rows ) ) {
					$question_content = json_decode( $question_rows[0]['content_json'] );
					$question_refs    = json_decode( $question_rows[0]['source_refs_json'] );
					if ( $question_content instanceof \stdClass && is_array( $question_refs ) && ( new NativeConversationHistoryVerifier( $this->db ) )->allows( $context, $question_rows[0], $question_content, $question_refs, (int) $conversation['id'] ) && $this->history_content( $context, $question_rows[0], (int) $conversation['id'] )['available'] ) {
						$page['continuation'] = (object) array(
							'kind'                 => 'clarification',
							'run_id'               => $active[0]['active_run_public_id'],
							'expected_run_version' => (int) $active[0]['active_run_version'],
							'message_sequence'     => (int) $question_rows[0]['sequence'],
						);
					}
				}
			}
			$page['next_cursor']     = $this->cursors->encode( $actor->owner(), 'conversation:' . $id, $page['next_sequence'] );
			$page['previous_cursor'] = $page['has_earlier'] ? $this->cursors->encode( $actor->owner(), 'conversation:' . $id, $page['previous_sequence'] ) : null;
			if ( 'visitor' === $page['profile'] ) {
				$latest               = $this->db->rows( "SELECT checkpoint_json FROM $runs WHERE conversation_id=%d AND owner_ref=%s ORDER BY id DESC LIMIT 1", array( (int) $fresh_conversation['id'], $actor->owner()->reference() ) );
				$latest_checkpoint = $latest ? json_decode( $latest[0]['checkpoint_json'] ) : null;
				$replacement = $latest_checkpoint->visitor_delivery_replacement ?? null;
				if ( is_object( $replacement ) && ! empty( $latest_checkpoint->visitor_recovering_delivery ) ) {
					$page['messages'] = array_values( array_filter( $page['messages'], static fn( $message ): bool => (int) $message['sequence'] !== (int) $replacement->sequence ) );
				}
				$scope                = $latest_checkpoint->target_context ?? null;
				$scope                = $scope ?? json_decode( $fresh_conversation['context_json'] );
				$page['directory_id'] = is_string( $scope->directory_id ?? null ) ? $scope->directory_id : null;
			}
			return $page;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Restore the verified owner's single frontend conversation.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error Descriptor, optionally with verified restoration data, never a guest credential.
	 */
	/** Explicit conversation-only language selection; no model work is dispatched. */
	public function visitor_language( \WP_REST_Request $request ) {
		try {
			$actor = $this->actor( $request );
			$this->check_profile( new Context( $actor, 'visitor', 'ui' ) );
			$input = json_decode( $request->get_body(), true );
			if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'conversation_id', 'chat_language' ) ) || ! is_string( $input['conversation_id'] ?? null ) ) {
				throw new ContractException( 'invalid_input', 'Choose a language for this conversation.' );
			}
			$preference = \DirectoristAIPro\AI\LanguagePolicy::preference( $input['chat_language'] ?? null );
			return new \WP_REST_Response( ( new \DirectoristAIPro\Chat\VisitorConversations( $this->db ) )->language( $actor->owner(), $input['conversation_id'], $preference ) );
		} catch ( \Throwable $error ) { return $this->error( $error ); }
	}

	public function visitor_current( \WP_REST_Request $request ) {
		try {
			$actor = $this->actor( $request );
			$row   = ( new \DirectoristAIPro\Chat\VisitorConversations( $this->db ) )->current( $actor->owner() );
			// A non-authorizing cache identity keeps tab drafts separate across account/guest changes.
			$owner_key = hash_hmac( 'sha256', 'visitor-ui|' . $actor->owner()->reference(), wp_salt( 'auth' ) );
			$browser_cache = \DirectoristAIPro\Chat\BrowserSnapshot::for_actor( $actor, (string) ( $request->get_header( 'x-directorist-ai-guest' ) ?: ( $_COOKIE['directorist_ai_guest'] ?? '' ) ) );
			if ( null === $row ) {
				return array(
					'owner_key'               => $owner_key,
					'browser_cache'           => $browser_cache,
					'catalog'                 => \DirectoristAIPro\Chat\VisitorSiteContext::catalog(),
					'conversation'            => null,
					'guest_request_available' => null !== $this->guest_conversation( $actor ),
				);
			}
			$runs       = $this->db->table( 'runs' );
			$latest     = $this->db->rows( "SELECT public_id,state,state_version,checkpoint_json FROM $runs WHERE conversation_id=%d AND owner_ref=%s ORDER BY id DESC LIMIT 1", array( (int) $row['id'], $actor->owner()->reference() ) );
			$scope      = $latest ? ( json_decode( $latest[0]['checkpoint_json'] )->target_context ?? null ) : null;
			$scope      = $scope ?? json_decode( $row['context_json'] );
			$last_input = $latest ? ( json_decode( $latest[0]['checkpoint_json'] )->reply ?? json_decode( $latest[0]['checkpoint_json'] )->intent ?? null ) : null;
			$language_state = \DirectoristAIPro\AI\LanguagePolicy::conversation_state( json_decode( $row['context_json'] ) );
			$data = array(
				'owner_key'               => $owner_key,
				'browser_cache'           => $browser_cache,
				'guest_request_available' => null !== $this->guest_conversation( $actor ),
				'conversation'            => array(
					'id'                   => $row['public_id'],
					'profile'              => 'visitor',
					'chat_language'        => $language_state['chat_language'],
					'chat_language_confirmed' => $language_state['chat_language_confirmed'],
					'chat_language_resolved' => $language_state['chat_language_resolved'],
					'chat_language_source' => $language_state['chat_language_source'],
					'directory_id'         => $scope->directory_id ?? null,
					'run_id'               => $latest[0]['public_id'] ?? null,
					'selected_listing_ids' => $last_input->selected_listing_ids ?? array(),
					'state'                => $latest[0]['state'] ?? null,
					'state_version'        => isset( $latest[0]['state_version'] ) ? (int) $latest[0]['state_version'] : null,
				),
			);
			// One explicit restoration read includes the same verified packet used by
			// normal updates. Begin with recent messages; older history keeps its
			// verified cursor. Reading never wakes or advances the saved run.
			if ( '1' === $request->get_param( 'restore' ) ) {
				$restore = clone $request;
				$restore->set_param( 'id', $row['public_id'] );
				$restore->set_param( 'version', '-1' );
				$restore->set_param( 'workflows', '1' );
				$packet = $this->visitor_updates( $restore, 20 );
				if ( is_wp_error( $packet ) ) {
					return $packet;
				}
				$data['updates'] = $packet->get_data();
				$data['catalog'] = $data['updates']['catalog'];
			} else {
				$data['catalog'] = \DirectoristAIPro\Chat\VisitorSiteContext::catalog();
			}
			return $data;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Recover only the browser's current guest request for explicit account reuse.
	 *
	 * @param \WP_REST_Request $request Authenticated native request.
	 * @return array|\WP_Error Draft; never submits work or transfers authority.
	 * @throws ContractException Caught when guest ownership or directory is unavailable.
	 */
	public function guest_request( \WP_REST_Request $request ) {
		try {
			$actor = $this->actor( $request );
			$guest = $this->guest_conversation( $actor );
			if ( null === $guest ) {
				throw new ContractException( 'unauthorized', 'The earlier guest request is unavailable.' );
			}
			$turns     = $this->db->table( 'turns' );
			$input     = json_decode( (string) $this->db->scalar( "SELECT input_json FROM $turns WHERE conversation_id=%d ORDER BY sequence DESC LIMIT 1", array( (int) $guest['id'] ) ) );
			$directory = $input->directory_id ?? json_decode( $guest['context_json'] )->directory_id ?? null;
			$this->assert_visitor_directory( $directory );
			$context  = new Context( $actor, 'visitor', 'ui' );
			$ids      = $input->selected_listing_ids ?? array();
			$selected = null === $directory ? \DirectoristAIPro\Chat\VisitorScope::selection( $context, $ids ) : \DirectoristAIPro\Chat\ListingActions::selection( $context, (int) $directory, $ids );
			return array(
				'text'                 => mb_substr( (string) ( $input->text ?? '' ), 0, 2000 ),
				'directory_id'         => $directory,
				'selected_listing_ids' => array_map( static fn( $listing ): int => (int) $listing->id, $selected->listings ?? array() ),
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * An account never inherits a guest transcript merely because it logs in.
	 *
	 * @param \DirectoristAIPro\Capabilities\Actor $actor Verified account.
	 * @return array|null Current guest conversation with a request.
	 */
	private function guest_conversation( \DirectoristAIPro\Capabilities\Actor $actor ): ?array {
		if ( $actor->is_guest() ) {
			return null;
		}
		$token = isset( $_COOKIE['directorist_ai_guest'] ) && is_string( $_COOKIE['directorist_ai_guest'] ) ? $_COOKIE['directorist_ai_guest'] : '';
		try {
			$guest = ( new \DirectoristAIPro\Capabilities\GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( $token );
			$row   = ( new \DirectoristAIPro\Chat\VisitorConversations( $this->db ) )->current( $guest->owner() );
			return $row && (int) $row['next_turn_seq'] > 1 ? $row : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/**
	 * Hide content immediately and request cancellation through the lifecycle.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error Clear receipt.
	 */
	public function visitor_clear( \WP_REST_Request $request ) {
		try {
			$input = $this->validator->decode( $request->get_body(), 'visitor.clear' );
			return ( new \DirectoristAIPro\Chat\VisitorConversations( $this->db ) )->clear( $this->actor( $request )->owner(), $input->conversation_id );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Decode one row without exposing unverifiable assistant prose or IDs.
	 *
	 * @param Context $context Current request authority.
	 * @param array   $message Exact stored row.
	 * @param int     $conversation_id Owned conversation identity.
	 * @return array Safe decoded content and reference state.
	 */
	private function history_content( Context $context, array $message, int $conversation_id ): array {
		$withheld = array(
			'available'   => false,
			'content'     => (object) array( 'text' => 'This earlier assistant message is unavailable.' ),
			'source_refs' => array(),
		);
		try {
			$content = ( new JsonDecoder( 262144, 24 ) )->decode( $message['content_json'] );
			if ( ! $content instanceof \stdClass || ! isset( $content->text ) || ! is_string( $content->text ) ) {
				return $withheld;
			}
			if ( 'user' === $message['role'] ) {
				return array(
					'available'   => true,
					'content'     => $content,
					'source_refs' => array(),
				);
			}
			$references = ( new JsonDecoder( 65536, 16 ) )->decode( $message['source_refs_json'] );
			if ( 'assistant' !== $message['role'] || ! is_array( $references ) || count( $references ) > 20 ) {
				return $withheld;
			}
			if ( 'visitor' === $context->profile() ) {
				$question_runs = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.conversation_id=%d AND r.owner_ref=%s AND r.trigger_turn_id=%d AND c.erasure_requested=0', array( $conversation_id, $context->actor()->owner()->reference(), (int) $message['turn_id'] ) );
				foreach ( $question_runs as $question_run ) {
					$question_checkpoint = json_decode( $question_run['checkpoint_json'] );
					foreach ( $question_checkpoint->visitor_recovery_questions ?? array() as $stamp ) {
						$question_rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . " WHERE id=%d AND conversation_id=%d AND turn_id=%d AND sequence=%d AND role='assistant'", array( (int) $stamp->message_id, $conversation_id, (int) $message['turn_id'], (int) $message['sequence'] ) );
						if ( 1 === count( $question_rows ) && $question_rows[0]['content_json'] === $message['content_json'] && \DirectoristAIPro\Chat\VisitorEvidenceRecovery::completed_question( $question_run, $question_rows[0], $stamp ) ) { return array( 'available' => true, 'content' => (object) array( 'text' => 'This clarification is complete.' ), 'source_refs' => array(), 'evidence_status' => 'completed_clarification' ); }
					}
				}
			}
			if ( array( 'text', 'grounded', 'provenance' ) === array_keys( get_object_vars( $content ) ) && $this->history_verifier instanceof StructuredConversationHistoryVerifier ) {
				$context->assert_execution_identity();
				try { $resolved = $this->history_verifier->resolve( $context, $message, $content, $references, $conversation_id ); } catch ( ContractException $error ) { $resolved = null; }
				if ( null !== $resolved && 'results' === ( $resolved->grounded->status ?? null ) && is_array( $resolved->grounded->cards ?? null ) ) {
					foreach ( $resolved->grounded->cards as $card ) {
						if ( ! isset( $card->listing_id, $card->directory_id ) || ! is_int( $card->listing_id ) || ! is_int( $card->directory_id ) ) {
							continue;
						}
						$post = get_post( $card->listing_id );
						if ( $post instanceof \WP_Post && ATBDP_POST_TYPE === $post->post_type && 'publish' === $post->post_status && (int) directorist_get_listing_directory( $post->ID ) === $card->directory_id ) {
							$card->url = get_permalink( $post );
						}
					}
				}
				if ( null !== $resolved ) { return array( 'available' => true, 'content' => $resolved, 'source_refs' => array() ); }
				// An authentic old producer record may explain withdrawal, but no
				// retained prose, values, cards or signed context cross this boundary.
				if ( 'visitor' === $context->profile() ) {
					$rows = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.public_id=%s AND r.owner_ref=%s AND r.conversation_id=%d AND r.trigger_turn_id=%d AND c.erasure_requested=0 AND c.profile=%s', array( $content->provenance->run_id ?? '', $context->actor()->owner()->reference(), $conversation_id, (int) $message['turn_id'], 'visitor' ) );
					if ( 1 === count( $rows ) && \DirectoristAIPro\Retrieval\GroundedMessageProvenance::attested( $rows[0], (int) $message['sequence'], $content, json_decode( $rows[0]['checkpoint_json'] ) ) ) { $withheld['content'] = (object) array( 'text' => 'Some information in this earlier answer is no longer publicly available.' ); $withheld['evidence_status'] = 'withdrawn'; }
				}
				return $withheld;
			}
			$keys = array_keys( get_object_vars( $content ) );
			if ( array( 'text' ) !== $keys && array( 'text', 'provenance' ) !== $keys ) {
				return $withheld;
			}
			foreach ( $references as $reference ) {
				if ( ! $this->valid_history_reference( $reference ) ) {
					return $withheld;
				}
			}
			$context->assert_execution_identity();
			if ( $this->history_verifier instanceof PlainConversationHistoryVerifier ) {
				$plain = $this->history_verifier->resolve_plain( $context, $message, $content, $references, $conversation_id );
				if ( null !== $plain ) { return array( 'available' => true, 'content' => $plain['content'], 'source_refs' => array(), 'evidence_status' => $plain['evidence_status'] ); }
			}
			if ( $this->history_verifier->allows( $context, $message, $content, $references, $conversation_id ) ) {
				return array(
					'available'       => true,
					'content'         => (object) array( 'text' => $content->text ),
					'source_refs'     => $references,
					'evidence_status' => array( 'text', 'provenance' ) === $keys ? ( 'directorist-ai/source-free-message-v1' === ( $content->provenance->contract ?? null ) ? 'source_free' : ( 'directorist-ai/visitor-help-v1' === ( $content->provenance->contract ?? null ) ? 'native_help' : null ) ) : null,
				);
			}
		} catch ( \Throwable $error ) {
			return $withheld;
		}
		return $withheld;
	}

	/**
	 * Admit only closed, safe citation identities before trusted verification.
	 *
	 * @param mixed $reference Decoded stored citation.
	 * @return bool Whether the reference has a safe closed shape.
	 */
	private function valid_history_reference( $reference ): bool {
		if ( ! $reference instanceof \stdClass || ! isset( $reference->kind ) || ! is_string( $reference->kind ) ) {
			return false;
		}
		$keys = array_keys( get_object_vars( $reference ) );
		sort( $keys, SORT_STRING );
		$identifier = static fn( $value ): bool => is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $value );
		$hash       = static fn( $value ): bool => is_string( $value ) && (bool) preg_match( '/^[a-f0-9]{64}$/D', $value );
		switch ( $reference->kind ) {
			case 'review':
				return array( 'id', 'kind' ) === $keys && $identifier( $reference->id );
			case 'artifact':
				return array( 'id', 'kind', 'version' ) === $keys && $identifier( $reference->id ) && is_int( $reference->version ) && $reference->version > 0;
			case 'operation':
				return array( 'id', 'kind', 'state' ) === $keys && $identifier( $reference->id ) && in_array( $reference->state, array( 'succeeded', 'failed', 'partial', 'unknown' ), true );
			case 'knowledge':
				return array( 'kind', 'source_id', 'version' ) === $keys && is_string( $reference->source_id ) && (bool) preg_match( '/^[a-z0-9][a-z0-9_.:-]{0,127}$/D', $reference->source_id ) && $hash( $reference->version );
			case 'listing':
				return array( 'directory_id', 'id', 'kind', 'version' ) === $keys && is_int( $reference->directory_id ) && $reference->directory_id > 0 && is_int( $reference->id ) && $reference->id > 0 && $hash( $reference->version );
		}
		return false;
	}

	/**
	 * Atomically accept one turn and its run/event state.
	 *
	 * @throws ContractException When operations are paused or a guarded input is invalid.
	 * @param \WP_REST_Request $request Native request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function turn( \WP_REST_Request $request ) {
		try {
			if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
				throw new ContractException( 'provider_unavailable', 'AI work is paused by the site administrator.' );
			}
			$input        = $this->validator->decode( $request->get_body(), 'turn.submit' );
			$actor        = $this->actor( $request );
			$conversation = ( new ConversationStore( $this->db ) )->get( $actor->owner(), (string) $request['id'] );
			$context      = new Context( $actor, $conversation['profile'], 'ui' );
			if ( 'visitor' === $conversation['profile'] ) {
				$scope = json_decode( $conversation['context_json'] );
				$this->assert_visitor_directory( property_exists( $input->input, 'directory_id' ) ? $input->input->directory_id : ( $scope->directory_id ?? null ) );
			}
			$result   = ( new RunStore( $this->db, $this->cursors ) )->accept( $context, $conversation['public_id'], $input->request_id, wp_json_encode( $input->input ) );
			$dispatch = 'pending_recovery';
			if ( $this->dispatcher ) {
				try {
					$dispatch = $this->dispatcher->schedule( $result['run']['public_id'] ) ? 'scheduled' : 'pending_recovery';
				} catch ( DispatchException $error ) {
					// The committed database run remains recoverable.
				}
			}
			if ( $this->dispatcher && 'visitor' === $conversation['profile'] && null !== \DirectoristAIPro\Chat\VisitorHelp::topic( $input->input->text ?? '' ) ) {
				$this->dispatcher->handle( $result['run']['public_id'] );
				$result['run'] = ( new RunStore( $this->db ) )->get( $actor->owner(), $result['run']['public_id'] );
			}
			$payload = array(
				'turn_id'       => (int) $result['turn']['id'],
				'run_id'        => $result['run']['public_id'],
				'state'         => $result['run']['state'],
				'state_version' => (int) $result['run']['state_version'],
				'dispatch'      => $dispatch,
			);
			if ( 'visitor' === $conversation['profile'] && '1' === $request->get_param( 'updates' ) ) {
				$updates = clone $request;
				$updates->set_url_params( array( 'id' => $conversation['public_id'] ) );
				$snapshot = $this->visitor_updates( $updates, 2 );
				if ( ! is_wp_error( $snapshot ) ) {
					$payload['updates'] = $snapshot->get_data(); }
			}
			return new \WP_REST_Response( $payload, 202 );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Batch public action manifests while keeping each listing's own native scope checks.
	 *
	 * @param \WP_REST_Request $request Verified visitor request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ContractException Invalid ownership or visitor scope.
	 */
	public function visitor_listing_actions( \WP_REST_Request $request ) {
		try {
			$actor = $this->actor( $request );
			$raw   = $request->get_param( 'ids' );
			if ( ! is_string( $raw ) || ! preg_match( '/^[1-9][0-9]*(?:,[1-9][0-9]*){0,19}$/D', $raw ) ) {
				throw new ContractException( 'invalid_input', 'Choose up to twenty current listings.' );
			}
			$context = new Context( $actor, 'visitor', 'ui' );
			$actions = array();
			foreach ( array_unique( array_map( 'intval', explode( ',', $raw ) ) ) as $id ) {
				try {
					$directory = \DirectoristAIPro\Chat\ListingActions::directory( (string) directorist_get_listing_directory( $id ) );
					$actions[] = \DirectoristAIPro\Chat\ListingActions::describe( $context, $directory, $id, true );
				} catch ( ContractException $error ) {
					// Removed/private listings never enter the public result batch.
				}
			}
			$response = new \WP_REST_Response( array( 'actions' => $actions ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Return changed history once, alongside a lightweight current run descriptor.
	 *
	 * @param \WP_REST_Request $request Verified visitor request.
	 * @param int              $history_limit Internal accepted-turn window. Ordinary updates retain full verification.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ContractException Invalid ownership or visitor scope.
	 */
	public function visitor_updates( \WP_REST_Request $request, int $history_limit = 50 ) {
		try {
			$actor = $this->actor( $request );
			$row   = ( new ConversationStore( $this->db ) )->get( $actor->owner(), (string) $request['id'] );
			if ( 'visitor' !== $row['profile'] ) {
				throw new ContractException( 'unauthorized', 'This visitor conversation is unavailable.' );
			}
			$this->check_profile( new Context( $actor, 'visitor', 'ui' ) );
			$runs            = $this->db->rows( 'SELECT public_id,state,state_version,checkpoint_json FROM ' . $this->db->table( 'runs' ) . ' WHERE conversation_id=%d AND owner_ref=%s ORDER BY (id=%d) DESC,id DESC LIMIT 1', array( (int) $row['id'], $actor->owner()->reference(), (int) ( $row['active_run_id'] ?? 0 ) ) );
			$run             = $runs[0] ?? null;
			$code            = $run ? ( json_decode( $run['checkpoint_json'] )->error ?? null ) : null;
			$language_state = \DirectoristAIPro\AI\LanguagePolicy::conversation_state( json_decode( $row['context_json'] ) );
			$data            = array(
				'conversation_id' => $row['public_id'],
				'chat_language' => $language_state['chat_language'],
				'chat_language_confirmed' => $language_state['chat_language_confirmed'],
				'chat_language_resolved' => $language_state['chat_language_resolved'],
				'chat_language_source' => $language_state['chat_language_source'],
				'language_offer' => $run && 'waiting_input' === $run['state'] ? ( json_decode( $run['checkpoint_json'] )->language_offer ?? null ) : null,
				'catalog'         => \DirectoristAIPro\Chat\VisitorSiteContext::catalog(),
				'version'         => (int) $row['state_version'],
				'run'             => $run ? array(
					'id'             => $run['public_id'],
					'state'          => $run['state'],
					'version'        => (int) $run['state_version'],
					'failure_code'   => is_string( $code ) && preg_match( '/^[a-z_]{1,64}$/D', $code ) ? $code : null,
					'retry_question' => RunStore::retry_question( $run ),
					'progress' => \DirectoristAIPro\Chat\VisitorProgress::present( $run ),
				) : null,
			);
			$catalog_changed = null !== $request->get_param( 'catalog_revision' ) && $data['catalog']['revision'] !== $request->get_param( 'catalog_revision' );
			if ( $catalog_changed || (string) $row['state_version'] !== (string) $request->get_param( 'version' ) ) {
				$history = clone $request;
				$history->set_param( 'tail', '1' );
				$history->set_param( 'limit', $history_limit );
				$data['history'] = $this->history( $history );
				if ( is_wp_error( $data['history'] ) ) {
					return $data['history'];
				}
				if ( $history_limit < 50 ) {
					$data['history_mode'] = 'append';
				}
			}
			if ( '1' === $request->get_param( 'workflows' ) ) {
				$data['workflows'] = ( new \DirectoristAIPro\Chat\VisitorWorkflowService( $this->db ) )->list_current( new Context( $actor, 'visitor', 'ui' ), $row['public_id'] );
			}
			$response = new \WP_REST_Response( $data );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Drive an owned visitor run or wake one authenticated generation step.
	 *
	 * @param \WP_REST_Request $request Verified visitor request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws ContractException Invalid ownership or visitor scope.
	 */
	public function visitor_advance( \WP_REST_Request $request ) {
		try {
			$actor = $this->actor( $request );
			$run   = ( new RunStore( $this->db ) )->get( $actor->owner(), (string) $request['id'] );
			$row   = ( new ConversationStore( $this->db ) )->get( $actor->owner(), $run['conversation_public_id'] );
			if ( 'visitor' !== $row['profile'] ) {
				return $this->generation_advance( $request, $actor, $run, $row );
			}
			if ( 'visitor' !== $row['profile'] || ! $this->dispatcher ) {
				throw new ContractException( 'unauthorized', 'This visitor request is unavailable.' );
			}
			$this->check_profile( new Context( $actor, 'visitor', 'ui' ) );
			if ( 'POST' !== $request->get_method() ) { throw new ContractException( 'unauthorized', 'This visitor progress request is unavailable.' ); }
			$body = $request->get_body();
			$this->validator->decode( '' === $body ? '{}' : $body, 'run.advance' );
			if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
				throw new ContractException( 'provider_unavailable', 'Chat is paused by the site owner. Your question is saved.' );
			}
			// Serialize the current phase before attempting one already-admitted step.
			// Long native retrieval must not block the visitor's only status channel.
			$expired = 'running' === $run['state'] && null !== $run['lease_until'] && strtotime( $run['lease_until'] . ' UTC' ) < time();
			if ( ( 'queued' === $run['state'] || $expired ) && ! (int) $run['cancel_requested'] && (int) $row['active_run_id'] === (int) $run['id'] && strtotime( $run['available_at'] . ' UTC' ) <= time() ) {
				$this->generation_dispatch->defer( $this->dispatcher, $run['public_id'] );
			}
			$updates = clone $request;
			$updates->set_url_params( array( 'id' => $row['public_id'] ) );
			$packet = $this->visitor_updates( $updates );
			return $this->visitor_delivery( $actor, $run['public_id'], $updates, $packet );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/** Final POST delivery can recover a stale newest answer; GET stays read-only. */
	private function visitor_delivery( Actor $actor, string $requested_run, \WP_REST_Request $updates, $packet ) {
		if ( ! $packet instanceof \WP_REST_Response ) { return $packet; }
		$data = $packet->get_data();
		if ( 'succeeded' !== ( $data['run']['state'] ?? null ) || $requested_run !== ( $data['run']['id'] ?? null ) || ! isset( $data['history']['messages'] ) ) { return $packet; }
		$run = ( new RunStore( $this->db ) )->get( $actor->owner(), $requested_run );
		$checkpoint = json_decode( $run['checkpoint_json'] );
		if ( ! \DirectoristAIPro\Chat\VisitorEvidenceRecovery::eligible( $run, $checkpoint ) ) { return $packet; }
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . " WHERE conversation_id=%d AND turn_id=%d AND role='assistant' ORDER BY sequence DESC LIMIT 1", array( (int) $run['conversation_id'], (int) $run['trigger_turn_id'] ) );
		if ( 1 !== count( $rows ) ) { return $packet; }
		$message = $rows[0]; $content = json_decode( $message['content_json'] ); $visible = null;
		foreach ( $data['history']['messages'] as $item ) { if ( (int) $item['sequence'] === (int) $message['sequence'] ) { $visible = $item; break; } }
		if ( null === $visible || ! is_object( $content ) ) { return $packet; }
		$attested = 'directorist-ai/visitor-help-v1' === ( $content->provenance->contract ?? null ) ? \DirectoristAIPro\Chat\VisitorHelp::verifies( $run, (int) $message['sequence'], $content ) && \DirectoristAIPro\Orchestration\VisitorReply::helper_attested( $run, $content->text ) : \DirectoristAIPro\Retrieval\GroundedMessageProvenance::attested( $run, (int) $message['sequence'], $content, $checkpoint );
		if ( ! $attested ) { return $packet; }
		$stale = 'available' !== ( $visible['content_status'] ?? null ) || in_array( $visible['evidence_status'] ?? null, array( 'dated_native_help', 'withdrawn' ), true );
		$recovered = ( new \DirectoristAIPro\Chat\VisitorDeliveryRecoveryStore( $this->db ) )->delivery( new Context( $actor, 'visitor', 'ui' ), $run, $message, $stale );
		if ( ! $recovered ) {
			$current = ( new RunStore( $this->db ) )->get( $actor->owner(), $requested_run );
			if ( in_array( $current['state'], array( 'queued', 'running' ), true ) && true === ( json_decode( $current['checkpoint_json'] )->visitor_recovering_delivery ?? false ) ) { $updates->set_param( 'version', '-1' ); return $this->visitor_updates( $updates ); }
			return $packet;
		}
		$this->generation_dispatch->defer( $this->dispatcher, $run['public_id'] );
		$updates->set_param( 'version', '-1' );
		return $this->visitor_updates( $updates );
	}

	/**
	 * Wake one due, owned generation step without changing its admitted task.
	 *
	 * @param \WP_REST_Request $request Authenticated empty-body progress request.
	 * @param Actor            $actor Current owner resolved by transport.
	 * @param array            $run Owned run, never an admission from the browser.
	 * @param array            $row Owned conversation with its captured target.
	 * @return \WP_REST_Response Compact state; existing GET routes deliver reviews.
	 * @throws ContractException Unavailable native authority or generation scope.
	 */
	private function generation_advance( \WP_REST_Request $request, Actor $actor, array $run, array $row ): \WP_REST_Response {
		if ( 'POST' !== $request->get_method() || $actor->is_guest() || ! $this->dispatcher || ! wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ) {
			throw new ContractException( 'unauthorized', 'This generation request is unavailable.' );
		}
		$body = $request->get_body();
		$this->validator->decode( '' === $body ? '{}' : $body, 'run.advance' );
		$actor->refresh();
		$context = new Context( $actor, $row['profile'], 'ui', $row['public_id'], $run['public_id'] );
		$context->assert_execution_identity();
		$this->check_profile( $context );
		$stored = ( new JsonDecoder( 65536, 12 ) )->decode( $row['context_json'] );
		if ( 'builder' === $row['profile'] ) {
			$document = $stored->builder_document ?? null;
			$ref      = $document->native_id ?? null;
			if ( ! $document instanceof \stdClass || ! is_string( $ref ) || ( $stored->target ?? null ) !== $ref ) {
				throw new ContractException( 'unauthorized', 'This builder request has no bound native document.' );
			}
			$can_edit = preg_match( '/^post:([1-9][0-9]*)$/D', $ref, $match )
				? $actor->can( 'edit_post', (int) $match[1] )
				: ( 'gutenberg' === ( $document->builder ?? null ) && preg_match( '/^template:(wp_template|wp_template_part):[A-Za-z0-9_.\/-]+:[A-Za-z0-9_.-]+$/D', $ref ) && $actor->can( 'edit_theme_options' ) );
			if ( ! $can_edit ) {
				throw new ContractException( 'unauthorized', 'The selected native document is unavailable.' );
			}
		} elseif ( in_array( $row['profile'], array( 'admin', 'owner' ), true ) ) {
			$form    = $stored->listing_form ?? null;
			$profile = $actor->can( 'manage_options' ) ? 'admin' : 'owner';
			if ( $profile !== $row['profile'] || ! $form instanceof \stdClass || ! is_int( $form->listing_id ?? null ) || ( $stored->target ?? null ) !== ( $form->listing_id ? 'listing:' . $form->listing_id : 'listing:new' ) ) {
				throw new ContractException( 'unauthorized', 'This is not a current listing form request.' );
			}
			( new \DirectoristAIPro\Listing\ListingFormService( $this->db ) )->assert_form( $context, $form );
		} else {
			throw new ContractException( 'unauthorized', 'This generation profile is unavailable.' );
		}
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'provider_unavailable', 'AI work is paused by the site administrator.' );
		}
		// One deferred claim only, after the REST packet is serialized and finished.
		// The dispatcher rechecks active ownership, due time and cancellation;
		// the normal worker/gateway retain all actor, source and effect fences.
		$expired = 'running' === $run['state'] && null !== $run['lease_until'] && strtotime( $run['lease_until'] . ' UTC' ) < time();
		if ( ( 'queued' === $run['state'] || $expired ) && ! (int) $run['cancel_requested'] && (int) $row['active_run_id'] === (int) $run['id'] && strtotime( $run['available_at'] . ' UTC' ) <= time() ) {
			$this->generation_dispatch->defer( $this->dispatcher, $run['public_id'] );
		}
		$run      = ( new RunStore( $this->db ) )->get( $actor->owner(), $run['public_id'] );
		$response = new \WP_REST_Response(
			array(
				'run_id'          => $run['public_id'],
				'conversation_id' => $row['public_id'],
				'state'           => $run['state'],
				'state_version'   => (int) $run['state_version'],
				'progress'        => RunStore::generation_progress( $run ),
			)
		);
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Replay durable run events by an owner/run-bound cursor.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 */
	public function events( \WP_REST_Request $request ) {
		try {
			$actor     = $this->actor( $request );
			$cursor    = $request->get_param( 'cursor' );
			$parameter = $request->get_param( 'limit' );
			$limit     = min( 100, max( 1, (int) ( $parameter ? $parameter : 100 ) ) );
			$page      = ( new RunStore( $this->db, $this->cursors ) )->replay( $actor->owner(), (string) $request['id'], is_string( $cursor ) && '' !== $cursor ? $cursor : null, $limit );
			foreach ( $page['events'] as &$event ) {
				$event['payload'] = json_decode( $event['payload_json'] );
				if ( 'grounded' === ( $event['payload']->data->result_kind ?? null ) ) {
					$event['payload']->data->result = null !== $this->results ? $this->results->resolve( $actor->owner(), (string) $request['id'], $event['payload']->data->trace ) : (object) array(
						'status'   => 'unsupported',
						'reason'   => 'current_evidence_unavailable',
						'response' => null,
					);
				}
				unset( $event['payload_json'] );
			}
			unset( $event );
			if ( '1' === $request->get_param( 'stream' ) ) {
				return new \WP_REST_Response( $page, 200, array( 'X-Directorist-Event-Stream' => '1' ) );
			}
			return $page;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}
	/** Bounded, owner-checked observation. Reading a stream never wakes a worker. */
	public function serve_event_stream( bool $served, $result, $request, $server ): bool {
		if ( $served || ! $result instanceof \WP_REST_Response || '1' !== ( $result->get_headers()['X-Directorist-Event-Stream'] ?? null ) || 'GET' !== $request->get_method() || ! preg_match( '#^/directorist-ai/v1/runs/[A-Za-z0-9_.:-]+/events$#D', $request->get_route() ) ) { return $served; }
		// Limit PHP occupancy. Unsupported/busy hosts receive the ordinary JSON fallback.
		$slot = null; $token = bin2hex( random_bytes( 12 ) );
		for ( $index = 0; $index < 2; ++$index ) {
			$key = 'directorist_ai_stream_slot_' . $index;
			$previous = get_option( $key );
			if ( is_array( $previous ) && ( $previous['expires'] ?? PHP_INT_MAX ) < time() ) { delete_option( $key ); }
			if ( add_option( $key, array( 'token' => $token, 'expires' => time() + 25 ), '', false ) ) { $slot = $key; break; }
		}
		if ( null === $slot || headers_sent() ) { if ( $slot ) { delete_option( $slot ); } return false; }
		$emit = static function( string $event, array $data ): void {
			echo 'event: ' . $event . "\n" . 'data: ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\n";
			flush();
		};
		try {
			header( 'Content-Type: text/event-stream; charset=utf-8' ); header( 'Cache-Control: private, no-store' ); header( 'X-Accel-Buffering: no' );
			while ( ob_get_level() ) { if ( ! @ob_end_flush() ) { break; } }
			$page = $result->get_data(); $signature = null; $start = microtime( true );
			do {
				if ( is_wp_error( $page ) ) { $emit( 'end', array( 'reason' => 'unavailable' ) ); break; }
				$next = hash( 'sha256', wp_json_encode( array( $page['state_version'] ?? null, $page['next_sequence'] ?? null, $page['advance_ready'] ?? false ) ) );
				if ( $next !== $signature ) { $emit( 'snapshot', $page ); $signature = $next; }
				if ( ! in_array( $page['state'] ?? '', array( 'queued', 'running' ), true ) ) { $emit( 'end', array( 'reason' => 'settled' ) ); break; }
				if ( connection_aborted() ) { break; }
				echo ": heartbeat\n\n"; flush();
				usleep( 1000000 );
				// Existing read performs ownership, retention, cursor and current evidence checks.
				if ( is_string( $page['next_cursor'] ?? null ) ) { $request->set_param( 'cursor', $page['next_cursor'] ); }
				$read = clone $request; $read->set_param( 'stream', null ); $page = $this->events( $read );
			} while ( microtime( true ) - $start < 15 );
		} finally {
			if ( ( get_option( $slot )['token'] ?? null ) === $token ) { delete_option( $slot ); }
		}
		return true;
	}

	/**
	 * Request cancellation without claiming rollback of completed effects.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 * @throws DispatchException Caught and converted when coordinator is absent.
	 */
	public function cancel( \WP_REST_Request $request ) {
		try {
			if ( ! $this->dispatcher ) {
				throw new DispatchException( 'Cancellation coordinator is unavailable.' );
			}
			$input = $this->validator->decode( $request->get_body(), 'run.cancel' );
			// Request ID is validated/auditable; run version owns idempotency.
			return $this->dispatcher->cancel( $this->actor( $request )->owner(), (string) $request['id'], $input->expected_run_version );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Read an exact immutable artifact version through its owner.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 */
	public function artifact( \WP_REST_Request $request ) {
		try {
			$row = ( new ArtifactStore( $this->db ) )->get( $this->actor( $request )->owner(), (string) $request['id'], (int) $request['version'] );
			if ( 'native_snapshot' === $row['kind'] ) {
				return $this->error( new StorageException( 'Artifact is unavailable.' ) );
			}
			return array(
				'id'      => $row['public_id'],
				'version' => (int) $row['version'],
				'kind'    => $row['kind'],
				'hash'    => $row['content_hash'],
				'payload' => json_decode( $row['payload_json'] ),
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Return an exact owned human-review payload.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 * @throws ContractException Converted to a REST error before escaping this method.
	 */
	public function review( \WP_REST_Request $request ) {
		try {
			if ( ! $this->approvals ) {
				throw new ContractException( 'unsupported_dependency', 'Approval service is unavailable.' );
			}
			return $this->approvals->get( $this->actor( $request ), (string) $request['id'] );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Record an authenticated exact approve or reject decision.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 * @throws ContractException Converted to a REST error before escaping this method.
	 */
	public function decision( \WP_REST_Request $request ) {
		try {
			if ( ! $this->approvals ) {
				throw new ContractException( 'unsupported_dependency', 'Approval service is unavailable.' );
			}
			$input = $this->validator->decode( $request->get_body(), 'review.decision' );
			return $this->approvals->decide( $this->actor( $request ), (string) $request['id'], $input->review_hash, $input->decision );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Decide and either queue or cancel one waiting approval run.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return array|\WP_Error
	 * @throws ContractException Converted to a REST error before escaping.
	 */
	public function run_review( \WP_REST_Request $request ) {
		try {
			if ( ! $this->approvals || ! $this->dispatcher ) {
				throw new ContractException( 'unsupported_dependency', 'Approval execution is unavailable.' );
			}
			$input = $this->validator->decode( $request->get_body(), 'run.review' );
			$actor = $this->actor( $request );
			if ( 'approve' === $input->decision ) {
				$review = $this->approvals->get( $actor, $input->approval_id );
				$impact = $review['review']->impact ?? null;
				if ( 'listing_creation' === ( $impact->kind ?? null ) && ( ( ! empty( $impact->requires_terms ) && true !== ( $input->accepted_terms ?? null ) ) || ( ! empty( $impact->requires_privacy ) && true !== ( $input->accepted_privacy ?? null ) ) ) ) {
					throw new ContractException( 'approval_required', 'Accept the required directory terms and privacy policy before submitting.' );
				}
				if ( in_array( $impact->kind ?? null, array( 'media_import', 'media_form_import' ), true ) && ( ! isset( $input->accepted_media_hash, $impact->source_hash ) || ! hash_equals( $impact->source_hash, $input->accepted_media_hash ) ) ) {
					throw new ContractException( 'approval_required', 'Review and accept the exact image before import.' );
				}
			}
			return $this->dispatcher->decide_review( $actor, (string) $request['id'], $input->expected_run_version, $input->approval_id, $input->review_hash, $input->decision );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Resolve a native user or a signed guest header.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @return Actor Verified transport actor.
	 */
	private function actor( \WP_REST_Request $request ): Actor {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return Actor::user( $user_id );
		}
		$header = (string) $request->get_header( 'x-directorist-ai-guest' );
		$cookie = isset( $_COOKIE['directorist_ai_guest'] ) && is_string( $_COOKIE['directorist_ai_guest'] ) ? $_COOKIE['directorist_ai_guest'] : '';
		return ( new GuestSessions( wp_salt( 'auth' ), get_current_blog_id() ) )->resolve( '' !== $header ? $header : $cookie );
	}

	/**
	 * Build a profile-bound UI context from verified transport identity.
	 *
	 * @param \WP_REST_Request $request Native request.
	 * @param string           $profile Requested surface restriction.
	 * @return Context Server-owned authority context.
	 */
	private function context( \WP_REST_Request $request, string $profile ): Context {
		return new Context( $this->actor( $request ), $profile, 'ui' );
	}

	/**
	 * Resolve the public directory allowlist freshly before admitting visitor work.
	 *
	 * @param mixed $directory_id Conversation directory identity.
	 * @return void
	 * @throws ContractException Missing or unavailable directory.
	 */
	private function assert_visitor_directory( $directory_id ): void {
		$allowed = array_map( static fn( array $directory ): string => (string) $directory['id'], Operations::public_directories() );
		if ( null === $directory_id && $allowed ) {
			return;
		}
		if ( ! is_string( $directory_id ) || ! in_array( $directory_id, $allowed, true ) ) {
			throw new ContractException( 'invalid_input', 'No searchable directories are available for this chat.' );
		}
	}

	/**
	 * Reject profile surfaces unavailable to the actual actor.
	 *
	 * @param Context $context Server-owned authority context.
	 * @return void
	 * @throws ContractException Unavailable profile.
	 */
	private function check_profile( Context $context ): void {
		$actor = $context->actor();
		if ( ( 'admin' === $context->profile() && ! $actor->can( 'manage_options' ) ) || ( 'builder' === $context->profile() && ! $actor->can( 'edit_pages' ) ) || ( $actor->is_guest() && 'visitor' !== $context->profile() ) ) {
			throw new ContractException( 'unauthorized', 'Conversation profile is unavailable.' );
		}
	}

	/**
	 * Convert expected boundary failures to non-disclosing REST errors.
	 *
	 * @param \Throwable $error Boundary failure.
	 * @return \WP_Error Safe REST error.
	 */
	private function error( \Throwable $error ): \WP_Error {
		if ( $error instanceof ContractException ) {
			if ( 'storage_unavailable' === $error->error_code() ) {
				return new \WP_Error( 'storage_unavailable', 'Chat is temporarily unavailable. Please try again later.', array( 'status' => 503 ) );
			}
			$status = in_array( $error->error_code(), array( 'stale_version', 'scope_conflict', 'request_conflict', 'approval_expired', 'approval_mismatch' ), true ) ? 409 : ( 'busy' === $error->error_code() ? 429 : ( in_array( $error->error_code(), array( 'unauthorized', 'approval_required' ), true ) ? 403 : 400 ) );
			return new \WP_Error( $error->error_code(), $error->getMessage(), array( 'status' => $status ) );
		}
		if ( $error instanceof StorageConflictException ) {
			return new \WP_Error( 'request_conflict', 'Request identity conflicts with existing runtime state.', array( 'status' => 409 ) );
		}
		if ( $error instanceof \DirectoristAIPro\Storage\StorageUnavailableException ) {
			return new \WP_Error( 'runtime_unavailable', 'Your chat could not be checked right now. Please try again.', array( 'status' => 503 ) );
		}
		if ( $error instanceof StorageException ) {
			return new \WP_Error( 'not_found', 'Requested runtime state is unavailable.', array( 'status' => 404 ) );
		}
		if ( $error instanceof DispatchException ) {
			return new \WP_Error( 'runtime_unavailable', 'Background execution is unavailable. Committed work remains pending recovery.', array( 'status' => 503 ) );
		}
		return new \WP_Error( 'runtime_unavailable', 'Runtime state could not be processed.', array( 'status' => 500 ) );
	}
}
