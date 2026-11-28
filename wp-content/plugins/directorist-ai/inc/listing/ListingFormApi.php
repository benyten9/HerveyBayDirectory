<?php
/**
 * Authenticated native-form capture and owned draft result delivery.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Listing;

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\AI\FailureNotice;
use DirectoristAIPro\AI\Settings;
use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectLedger;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Storage\StorageUnavailableException;
use DirectoristAIPro\Approvals\ApprovalService;
use DirectoristAIPro\Chat\Operations;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Storage\ConversationStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Native listing integration using the shared runtime. */
final class ListingFormApi {
	/**
	 * Bind the site runtime and native listing services.
	 *
	 * @param Database           $db Site-scoped runtime database.
	 * @param ListingFormService $service Native form service.
	 */
	public function __construct( private Database $db, private ListingFormService $service ) {}

	/**
	 * Register bounded native listing tools with the common gateway.
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/listing/term-actions',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'term_action' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/term-actions/recover',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'recover_term_action' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/history',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'history' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/history/messages/(?P<id>[a-zA-Z0-9_.:-]{1,128})/(?P<sequence>[1-9][0-9]{0,9})',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'history_message' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/forms/recover',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'recover' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/forms',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'capture' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/form-runs/(?P<id>[a-f0-9]{32})/reconcile',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'reconcile' ),
			)
		);
		register_rest_route(
			'directorist-ai/v1',
			'/listing/form-runs/(?P<id>[a-f0-9]{32})',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( $this, 'permission' ),
				'callback'            => array( $this, 'result' ),
			)
		);
	}

	/**
	 * Require an authenticated nonce and active site AI operations.
	 *
	 * @param \WP_REST_Request $request Authenticated REST request.
	 * @return bool
	 */
	public function permission( \WP_REST_Request $request ): bool {
		return is_user_logged_in() && wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) && ! get_option( 'directorist_ai_pro_emergency_stop', false );
	}

	/**
	 * Create an owned conversation from a validated form snapshot.
	 *
	 * @param \WP_REST_Request $request Authenticated REST request.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return mixed
	 */
	public function capture( \WP_REST_Request $request ) {
		return $this->capture_form( $request, false );
	}

	/**
	 * Bind the administrator's explicit one-click taxonomy instruction.
	 *
	 * @param \WP_REST_Request $request Authenticated native field request.
	 * @return mixed
	 */
	public function term_action( \WP_REST_Request $request ) {
		return $this->capture_form( $request, true );
	}

	/**
	 * Capture form facts; only the dedicated action grants automatic taxonomy execution.
	 *
	 * @param \WP_REST_Request $request Authenticated native form request.
	 * @param bool             $automatic Whether the click selects or creates terms.
	 * @return mixed
	 * @throws ContractException When the action scope is unavailable.
	 */
	private function capture_form( \WP_REST_Request $request, bool $automatic ) {
		try {
			$input = json_decode( $request->get_body() );
			if ( ! $input instanceof \stdClass || strlen( $request->get_body() ) > 65536 ) {
				throw new ContractException( 'invalid_input', 'The listing capture is invalid.' );
			}
			$keys = array_keys( get_object_vars( $input ) );
			$keys = array_values( array_diff( $keys, array( 'media', 'term_field', 'history_area', 'history_session', 'recovery_session' ) ) );
			sort( $keys );
			if ( array( 'directory_id', 'facts', 'field_ids', 'listing_id', 'mode', 'request_id', 'values' ) !== $keys || ! is_int( $input->directory_id ) || $input->directory_id < 1 || ! is_int( $input->listing_id ) || $input->listing_id < 0 || ! is_string( $input->request_id ) || ! is_string( $input->mode ) ) {
				throw new ContractException( 'invalid_input', 'The listing capture is invalid.' );
			}
			$profile = current_user_can( 'manage_options' ) ? 'admin' : 'owner';
			$context = Context::current( $profile, 'ui' );
			$form    = $this->service->bind( $context, $input );
			if ( $automatic ) {
				if ( 'admin' !== $profile || 'edit_terms' !== $form->mode || ! isset( $form->term_field ) ) {
					throw new ContractException( 'unauthorized', 'Automatic assistance is limited to this category or tag field.' );
				}
				$copy = array_diff_key( get_object_vars( $form->values ), array( $form->term_field => true ) );
				if ( mb_strlen( trim( wp_strip_all_tags( implode( ' ', $copy ) ) ) ) < 20 ) {
					throw new ContractException( 'invalid_input', 'Add a business description before choosing categories or tags with AI.' );
				}
				$form->term_action = 'choose_or_create';
			}
			$session = $input->recovery_session ?? null;
			if ( null !== $session && ( ! is_string( $session ) || ! preg_match( '/^[a-f0-9]{32}$/D', $session ) ) ) {
				throw new ContractException( 'invalid_input', 'The listing recovery session is invalid.' );
			}
			$memory       = ListingHistory::scope( $form, $input );
			$task_context = (object) array(
				'directory_id'    => (string) $form->directory_id,
				'target'          => $form->listing_id ? 'listing:' . $form->listing_id : 'listing:new',
				'listing_form'    => $form,
				'listing_history' => $memory,
			);
			if ( null !== $session ) {
				$task_context->listing_recovery_session = $session;
			}
			$row = ( new ConversationStore( $this->db ) )->create(
				$context->actor()->owner(),
				ListingHistory::prefix( ListingHistory::scope( $form, $input ) ) . substr( hash( 'sha256', $input->request_id ), 0, 24 ),
				$profile,
				$task_context,
				Operations::settings()['user_retention_days'] * DAY_IN_SECONDS
			);
			return new \WP_REST_Response(
				array(
					'id'      => $row['public_id'],
					'profile' => $profile,
				),
				201
			);
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Read retained discussion under fresh native form and actor authority.
	 *
	 * @param \WP_REST_Request $request Current form observations.
	 * @return mixed Owned historical text, never an executable review.
	 * @throws ContractException When the current discussion scope is invalid.
	 */
	public function history( \WP_REST_Request $request ) {
		try {
			list( $context, $input, $scope ) = $this->history_context( $request );
			$before                          = $input->history_before ?? 0;
			if ( ! is_int( $before ) || $before < 0 ) {
				throw new ContractException( 'invalid_input', 'The discussion cursor is invalid.' );
			}
			$response = new \WP_REST_Response( ( new ListingHistory( $this->db ) )->lookup( $context, $scope, $before ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Read one full message under the same current form and area authority as history.
	 *
	 * @param \WP_REST_Request $request Current form observations and message identity.
	 * @return mixed Retained prose only.
	 * @throws ContractException Invalid message reference or unavailable native area.
	 */
	public function history_message( \WP_REST_Request $request ) {
		try {
			list( $context, $input, $scope ) = $this->history_context( $request );
			$id                              = $request['id'];
			$sequence                        = $request['sequence'];
			if ( ! is_string( $id ) || ! preg_match( '/^[a-zA-Z0-9_.:-]{1,128}$/D', $id ) || ! preg_match( '/^[1-9][0-9]{0,9}$/D', (string) $sequence ) ) {
				throw new ContractException( 'invalid_input', 'The retained message reference is invalid.' );
			}
			$response = new \WP_REST_Response( ( new ListingHistory( $this->db ) )->message( $context, $scope, $id, (int) $sequence ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Validate a read-only history request against the current native form.
	 *
	 * @param \WP_REST_Request $request Current form observations.
	 * @return array Context, input and scoped identity.
	 * @throws ContractException Invalid input or unavailable native area.
	 */
	private function history_context( \WP_REST_Request $request ): array {
		$input = json_decode( $request->get_body() );
		if ( ! $input instanceof \stdClass || strlen( $request->get_body() ) > 65536 || ! is_int( $input->listing_id ?? null ) || ! is_int( $input->directory_id ?? null ) || ! is_string( $input->request_id ?? null ) || ! is_string( $input->mode ?? null ) || ! ( $input->values ?? null ) instanceof \stdClass || ! is_array( $input->field_ids ?? null ) || array_diff( array_keys( get_object_vars( $input ) ), array( 'listing_id', 'directory_id', 'field_ids', 'mode', 'values', 'facts', 'request_id', 'media', 'term_field', 'history_area', 'history_session', 'history_before', 'recovery_session' ) ) ) {
			throw new ContractException( 'invalid_input', 'The listing discussion context is invalid.' );
		}
		$context = Context::current( current_user_can( 'manage_options' ) ? 'admin' : 'owner', 'ui' );
		$form    = $this->service->bind( $context, $input );
		return array( $context, $input, ListingHistory::scope( $form, $input ) );
	}

	/**
	 * Deliver the exact owned review or native form outcome.
	 *
	 * @param \WP_REST_Request $request Authenticated REST request.
	 * @throws ContractException When the native workflow cannot be verified.
	 * @return mixed
	 */
	public function result( \WP_REST_Request $request ) {
		try {
			$context      = Context::current( current_user_can( 'manage_options' ) ? 'admin' : 'owner', 'ui' );
			$run          = ( new RunStore( $this->db ) )->get( $context->actor()->owner(), $request['id'] );
			$conversation = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $run['conversation_public_id'] ?? $this->conversation_id( $run ) );
			if ( $conversation['profile'] !== $context->profile() ) {
				throw new ContractException( 'unauthorized', 'Listing conversation access changed.' );
			}
			$form = json_decode( $conversation['context_json'] )->listing_form ?? null;
			if ( ! $form ) {
				throw new ContractException( 'unauthorized', 'This is not a listing form run.' );
			}
			$this->service->assert_form( $context, $form );
			$checkpoint   = json_decode( $run['checkpoint_json'] );
			$capability   = $checkpoint->review->capability ?? '';
			$data         = array(
				'outcome_kind'     => 'waiting_input' === $run['state'] ? 'clarification' : 'pending',
				'receipt_verified' => false,
				'recovery'         => null,
				'failure_code'     => null,
				'failure_notice'   => null,
				'ai_source'        => Settings::for_task( json_decode( wp_json_encode( $checkpoint->ai_route ?? array() ), true ), isset( $form->media ) ? 'image' : 'text' )['source'] ?? null,
				'directory_id'     => (int) $form->directory_id,
				'listing_id'       => (int) $form->listing_id,
				'state'            => $run['state'],
				'state_version'    => (int) $run['state_version'],
				'conversation_id'  => $conversation['public_id'],
			);
			$legacy_image = ( new ListingImageRecovery( $this->db ) )->view( $run );
			if ( null !== $legacy_image ) {
				$data = array_merge( $data, $legacy_image ); }
			if ( 'waiting_approval' === $run['state'] && in_array( $capability, array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create', 'listing.media_import' ), true ) ) {
				$review = ( new ApprovalService( $this->db ) )->get( $context->actor(), $checkpoint->review->id );
				if ( 'pending' === $review['state'] ) {
					$data['review']       = $review;
					$data['outcome_kind'] = 'review';
				} elseif ( in_array( $review['state'], array( 'revoked', 'expired', 'rejected' ), true ) ) {
					$data['outcome_kind']   = 'review_unavailable';
					$data['review_state']   = $review['state'];
					$data['failure_code']   = 'expired' === $review['state'] ? 'approval_expired' : 'approval_replaced';
					$data['failure_notice'] = 'This review expired or was replaced. Describe what to keep or change to prepare a new preview. A draft cannot be created from the earlier review.';
					$data['recovery']       = 'refresh_review';
				} else {
					$data['outcome_kind']   = 'unconfirmed';
					$data['failure_code']   = 'result_unconfirmed';
					$data['failure_notice'] = 'The original review decision needs checking. Check the request status before starting another generation.';
					$data['recovery']       = 'check_status';
				}
			}
			if ( 'succeeded' === $run['state'] ) {
				try {
					$data = array_merge( $data, $this->verified_result( $context, $run, $form, $checkpoint, $capability ) );
				} catch ( ContractException $error ) {
					$data['outcome_kind']   = 'unconfirmed';
					$data['failure_code']   = 'result_unconfirmed';
					$data['failure_notice'] = 'The completed request has no verified result available. Check its status before starting another generation.';
					$data['recovery']       = 'check_status';
				}
			} elseif ( in_array( $run['state'], array( 'failed', 'unknown', 'partial' ), true ) ) {
				$data = array_merge( $data, $this->failure( $checkpoint, $run['state'], (int) $run['id'] ) );
			} elseif ( 'cancelled' === $run['state'] ) {
				$data['outcome_kind'] = 'cancelled';
			}
			$response = new \WP_REST_Response( $data );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}




	/**
	 * Recover only this tab's exact owned area without admitting a turn or effect.
	 *
	 * @param \WP_REST_Request $request Current form and retained random tab session.
	 * @return mixed Owned latest workflow pointer and its immutable untrusted capture.
	 * @throws ContractException Invalid session or unavailable current native scope.
	 */
	public function recover( \WP_REST_Request $request ) {
		return $this->recover_form( $request, false );
	}

	/**
	 * Recover automatic taxonomy work without granting new execution authority.
	 *
	 * @param \WP_REST_Request $request Current owned native form observations.
	 * @return mixed Read-only original automatic request identity.
	 */
	public function recover_term_action( \WP_REST_Request $request ) {
		return $this->recover_form( $request, true );
	}

	/**
	 * Keep reviewed and automatic field workflows in their original authority lane.
	 *
	 * @param \WP_REST_Request $request Current native form observations.
	 * @param bool             $automatic Whether to recover automatic taxonomy work.
	 * @return mixed Original retained work, never a new admission or effect.
	 * @throws ContractException When the current automatic scope is unavailable.
	 */
	private function recover_form( \WP_REST_Request $request, bool $automatic ) {
		try {
			list( $context, $input, $scope ) = $this->history_context( $request );
			$session                         = $input->recovery_session ?? null;
			if ( ! is_string( $session ) || ! preg_match( '/^[a-f0-9]{32}$/D', $session ) ) {
				throw new ContractException( 'invalid_input', 'The original listing tab session is required.' );
			}
			$now = $this->service->bind( $context, $input );
			if ( $automatic ) {
				if ( 'admin' !== $context->profile() || 'edit_terms' !== $now->mode || ! isset( $now->term_field ) ) {
					throw new ContractException( 'unauthorized', 'Automatic assistance is limited to this category or tag field.' );
				}
				$now->term_action = 'choose_or_create';
			}

			$conversations = $this->db->table( 'conversations' );
			$runs          = $this->db->table( 'runs' );
			$rows          = $this->db->rows( "SELECT c.public_id AS conversation_public_id,c.context_json,r.id,r.public_id,r.state,r.state_version,r.checkpoint_json,r.trigger_turn_id AS turn_id FROM $conversations c INNER JOIN $runs r ON r.conversation_id=c.id AND r.owner_ref=c.owner_ref WHERE c.owner_ref=%s AND c.profile=%s AND c.erasure_requested=0 AND (c.expires_at>UTC_TIMESTAMP() OR c.active_run_id IS NOT NULL) AND JSON_UNQUOTE(JSON_EXTRACT(c.context_json,'$.listing_recovery_session'))=%s ORDER BY r.id DESC LIMIT 50", array( $context->actor()->owner()->reference(), $context->profile(), $session ) );
			$found         = null;
			$canonical     = new JsonCanonicalizer();
			foreach ( $rows as $row ) {
				$stored = json_decode( $row['context_json'] );
				$form   = $stored->listing_form ?? null;
				if ( ! $form instanceof \stdClass || (int) $form->directory_id !== $scope->directory_id || (int) $form->listing_id !== $scope->listing_id || ! isset( $stored->listing_history ) || $canonical->encode( $stored->listing_history ) !== $canonical->encode( $scope ) ) {
					continue;
				}
				if ( ( 'choose_or_create' === ( $form->term_action ?? null ) ) !== $automatic ) {
					continue;
				}
				// Rejected/cancelled work is not resurrected, nor replaced by an older request.
				if ( 'cancelled' === $row['state'] ) {
					break;
				}
				// Native Save and WordPress draft autosave can retire a completed capture.
				// Its retained prose remains history, never current execution authority.
				if ( 'succeeded' === $row['state'] && ( ! hash_equals( $form->schema_hash, $now->schema_hash ) || ! hash_equals( $form->source_hash, $now->source_hash ) ) ) {
					break;
				}
				$checkpoint = json_decode( $row['checkpoint_json'] );
				if ( 'succeeded' === $row['state'] && 'create_draft' === $form->mode ) {
					try {
						$completed = $this->verified_result( $context, $row, $form, $checkpoint, $checkpoint->review->capability ?? '', true );
						if ( 'historical_draft' === $completed['outcome_kind'] ) {
							break;
						}
					} catch ( ContractException $error ) {
						// An uncertain or tampered receipt still requires explicit recovery.
					}
				}
				if ( 'failed' === $row['state'] && 'check_status' !== $this->failure( $checkpoint, $row['state'], (int) $this->db->scalar( "SELECT id FROM $runs WHERE public_id=%s", array( $row['public_id'] ) ) )['recovery'] ) {
					break;
				}
				$text    = $this->db->scalar( 'SELECT input_json FROM ' . $this->db->table( 'turns' ) . ' WHERE id=%d', array( (int) $row['turn_id'] ) );
				$capture = (object) array(
					'directory_id'     => (int) $form->directory_id,
					'listing_id'       => (int) $form->listing_id,
					'mode'             => $form->mode,
					'field_ids'        => $form->field_ids,
					'values'           => $form->values,
					'facts'            => $form->facts ?? new \stdClass(),
					'history_area'     => $scope->area,
					'recovery_session' => $session,
					'request_id'       => 'recovered-' . $row['public_id'],
				);
				if ( $scope->session ) {
					$capture->history_session = $scope->session;
				}
				if ( isset( $form->media ) ) {
					$capture->media = $form->media;
				}
				if ( isset( $form->term_field ) ) {
					$capture->term_field = $form->term_field;
				}
				$found = array(
					'automatic_terms' => $automatic,
					'run'             => array(
						'id'             => $row['public_id'],
						'conversationId' => $row['conversation_public_id'],
						'state'          => $row['state'],
						'version'        => (int) $row['state_version'],
					),
					'capture'         => $capture,
					'text'            => json_decode( $text )->text ?? '',
					'schema_hash'     => $form->schema_hash,
					'source_hash'     => $form->source_hash,
					'matches_current' => $canonical->encode( $form ) === $canonical->encode( $now ),
				);
				break;
			}
			$response = new \WP_REST_Response( array( 'request' => $found ) );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Read back an existing approved effect without executing it again.
	 *
	 * @param \WP_REST_Request $request Owned run and expected state version.
	 * @return mixed Fresh verified result, or retained unresolved status.
	 * @throws ContractException Reconciliation identity or authority changed.
	 */
	public function reconcile( \WP_REST_Request $request ) {
		try {
			$input = json_decode( $request->get_body() );
			if ( ! $input instanceof \stdClass || array( 'expected_run_version' ) !== array_keys( get_object_vars( $input ) ) || ! is_int( $input->expected_run_version ) || $input->expected_run_version < 1 ) {
				throw new ContractException( 'invalid_input', 'The request status version is invalid.' );
			}
			$context      = Context::current( current_user_can( 'manage_options' ) ? 'admin' : 'owner', 'ui' );
			$store        = new RunStore( $this->db );
			$run          = $store->get( $context->actor()->owner(), $request['id'] );
			$conversation = ( new ConversationStore( $this->db ) )->get( $context->actor()->owner(), $this->conversation_id( $run ) );
			$form         = json_decode( $conversation['context_json'] )->listing_form ?? null;
			$checkpoint   = json_decode( $run['checkpoint_json'] );
			$review       = $checkpoint->review ?? null;
			if ( $conversation['profile'] !== $context->profile() || ! $form instanceof \stdClass || ! in_array( $run['state'], array( 'unknown', 'partial', 'failed', 'succeeded' ), true ) || (int) $run['state_version'] !== $input->expected_run_version ) {
				throw new ContractException( 'stale_version', 'The request status changed. Refresh it before checking the receipt.' );
			}
			$this->service->assert_form( $context, $form );
			if ( ! $review instanceof \stdClass ) {
				return $this->result( $request );
			}
			$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'operations' ) . ' WHERE public_id=%s AND actor_ref=%s AND run_id=%d LIMIT 2', array( $review->operation_id ?? '', $context->actor()->owner()->reference(), (int) $run['id'] ) );
			if ( 1 !== count( $rows ) || ! in_array( $rows[0]['state'], array( 'unknown', 'running', 'succeeded', 'partial', 'failed' ), true ) ) {
				// A provider-only uncertainty has no native effect to replay or reconcile here.
				return $this->result( $request );
			}
			$receipt = json_decode( $rows[0]['receipt_json'] );
			$data    = $receipt->plan ?? null;
			if ( ! $data instanceof \stdClass || ! is_array( $data->targets ?? null ) || 1 !== count( $data->targets ) || ! in_array( $data->capability ?? null, array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create', 'listing.media_import' ), true ) ) {
				throw new ContractException( 'result_unconfirmed', 'The existing listing receipt cannot be checked.' );
			}
			$target       = $data->targets[0];
			$plan         = new EffectPlan( $rows[0]['public_id'], $context->actor()->owner()->reference(), $data->capability, '1', $data->proposal_hash, array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), (int) $run['id'], (int) $rows[0]['approval_id'] );
			$approval     = ( new ApprovalService( $this->db ) )->get( $context->actor(), $review->id );
			$approval_ids = $this->db->rows( 'SELECT public_id FROM ' . $this->db->table( 'approvals' ) . ' WHERE id=%d AND actor_ref=%s', array( $plan->approval_id, $plan->actor_ref ) );
			$approved     = $approval['review']->plan;
			$canonical    = new JsonCanonicalizer();
			if ( $canonical->encode( $data ) !== $canonical->encode( (object) $plan->data() ) || ( $receipt->operation_id ?? null ) !== $plan->operation_id || ( $receipt->plan_hash ?? null ) !== $plan->hash() || ( $receipt->proposal_hash ?? null ) !== $plan->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $plan->target_set_hash() || $rows[0]['reconciliation_key'] !== $plan->hash() || $rows[0]['proposal_hash'] !== $plan->proposal_hash || $rows[0]['target_set_hash'] !== $plan->target_set_hash() || $review->capability !== $plan->capability || $review->review_hash !== $approval['review_hash'] || ! in_array( $approval['state'], array( 'reserved', 'consumed' ), true ) || ( $approval_ids[0]['public_id'] ?? null ) !== $review->id || $approval['reserved_operation_id'] !== $plan->operation_id || $approved->operation_id !== $plan->operation_id || $approved->actor_ref !== $plan->actor_ref || $approved->capability !== $plan->capability || $approved->run_id !== $plan->run_id || $approved->proposal_hash !== $plan->proposal_hash || $canonical->encode( $approved->targets ) !== $canonical->encode( $data->targets ) ) {
				throw new ContractException( 'result_unconfirmed', 'The original listing approval or receipt changed.' );
			}
			$handler = 'listing.media_import' === $plan->capability ? new ListingMediaEffect( $this->db, $this->service, new ListingMediaService( $this->db, $this->service ) ) : new ListingFormEffect( $this->db, $this->service );
			$bound   = new Context( $context->actor(), $context->profile(), 'ui', $conversation['public_id'], $run['public_id'] );
			if ( ! $handler->permitted( $plan, $bound ) ) {
				throw new ContractException( 'unauthorized', 'The native listing authority changed.' );
			}
			( new EffectLedger( $this->db ) )->reconcile( $plan, $handler );
			$store->reconcile_effect_receipt( $bound, $run['public_id'], $input->expected_run_version, $plan->operation_id );
			return $this->result( $request );
		} catch ( \Throwable $error ) {
			return $this->error( $error );
		}
	}

	/**
	 * Deliver success only from the exact owned operation and current native result.
	 *
	 * @param Context $context Verified native actor.
	 * @param array   $run Owned run row.
	 * @param object  $form Bound form scope.
	 * @param object  $checkpoint Durable workflow state.
	 * @param string  $capability Reviewed listing capability.
	 * @param bool    $historical Permit only non-executable retirement of a later native-edited draft.
	 * @return array Verified public result.
	 * @throws ContractException Missing or mismatched effect receipt.
	 */
	private function verified_result( Context $context, array $run, object $form, object $checkpoint, string $capability, bool $historical = false ): array {
		$receipt = $checkpoint->receipt ?? null;
		$plan    = $receipt->plan ?? null;
		$targets = ( $receipt->targets ?? null ) instanceof \stdClass ? array_values( get_object_vars( $receipt->targets ) ) : array();
		$target  = 1 === count( $targets ) ? $targets[0] : null;
		$key     = 'listingform:' . $context->actor()->user_id() . ':' . $form->directory_id . ':' . $form->listing_id;
		$allowed = array( 'listing.form_fill', 'listing.term_fill', 'listing.draft_create', 'listing.media_import' );
		if ( ! in_array( $capability, $allowed, true ) || ! $plan instanceof \stdClass || ! $target instanceof \stdClass || 'succeeded' !== ( $receipt->state ?? null ) || 'succeeded' !== ( $target->status ?? null ) || ( $plan->actor_ref ?? null ) !== $context->actor()->owner()->reference() || ( $plan->capability ?? null ) !== $capability || ( $plan->capability_revision ?? null ) !== '1' || ( $plan->run_id ?? null ) !== (int) $run['id'] || ( $plan->operation_id ?? null ) !== ( $receipt->operation_id ?? null ) || ! is_array( $plan->targets ?? null ) || 1 !== count( $plan->targets ) || ( $plan->targets[0]->key ?? null ) !== $key || ( $target->key ?? null ) !== $key || ! ( $target->effects ?? null ) instanceof \stdClass ) {
			throw new ContractException( 'result_unconfirmed', 'The listing receipt is unavailable.' );
		}
		$exact = new EffectPlan( $plan->operation_id, $plan->actor_ref, $capability, '1', $plan->proposal_hash, array( new EffectTarget( $key, $plan->targets[0]->expected_version, $plan->targets[0]->effect_hash ) ), $plan->run_id, $plan->approval_id );
		if ( ( new JsonCanonicalizer() )->encode( $plan ) !== ( new JsonCanonicalizer() )->encode( (object) $exact->data() ) || ( $receipt->plan_hash ?? null ) !== $exact->hash() || ( $receipt->proposal_hash ?? null ) !== $exact->proposal_hash || ( $receipt->target_set_hash ?? null ) !== $exact->target_set_hash() || ( $target->expected_version ?? null ) !== $exact->targets[0]->expected_version || ( $target->before_version ?? null ) !== $exact->targets[0]->expected_version || ( $target->effect_hash ?? null ) !== $exact->targets[0]->effect_hash || ! is_string( $target->after_version ?? null ) || ! is_int( $target->fence ?? null ) || $target->fence < 1 ) {
			throw new ContractException( 'result_unconfirmed', 'The listing receipt changed.' );
		}
		$rows = $this->db->rows( 'SELECT receipt_json FROM ' . $this->db->table( 'operations' ) . " WHERE public_id=%s AND actor_ref=%s AND run_id=%d AND capability=%s AND state='succeeded' LIMIT 2", array( $exact->operation_id, $exact->actor_ref, (int) $run['id'], $capability ) );
		if ( 1 !== count( $rows ) || ( new JsonCanonicalizer() )->encode( json_decode( $rows[0]['receipt_json'] ) ) !== ( new JsonCanonicalizer() )->encode( $receipt ) ) {
			throw new ContractException( 'result_unconfirmed', 'The durable listing receipt is unavailable.' );
		}
		$effects = $target->effects;
		$data    = array(
			'effects'          => $effects,
			'receipt_verified' => true,
			'operation_id'     => $exact->operation_id,
		);
		if ( 'listing.draft_create' === $capability ) {
			$id   = $effects->listing_id ?? null;
			$post = is_int( $id ) && $id > 0 ? get_post( $id ) : null;
			$link = $post && current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id, 'raw' ) : null;
			if ( 'create_draft' !== $form->mode || ! $post || ATBDP_POST_TYPE !== $post->post_type || (int) $post->post_author !== $context->actor()->user_id() || (int) directorist_get_listing_directory( $id ) !== (int) $form->directory_id || get_post_meta( $id, '_directorist_ai_form_operation', true ) !== $exact->operation_id || get_post_meta( $id, '_directorist_ai_form_hash', true ) !== $exact->proposal_hash || ! is_string( $link ) || '' === $link ) {
				throw new ContractException( 'result_unconfirmed', 'The listing draft cannot be verified.' );
			}
			if ( 'draft' !== $post->post_status || ! hash_equals( $target->after_version, ListingFormService::source_hash( $id ) ) ) {
				if ( $historical ) {
					return array(
						'outcome_kind'     => 'historical_draft',
						'receipt_verified' => false,
					);
				}
				throw new ContractException( 'result_unconfirmed', 'The listing draft cannot be verified.' );
			}
			$data['outcome_kind'] = 'draft_created';
			$data['edit_url']     = $link;
		} else {
			if ( ( $effects->listing_id ?? null ) !== (int) $form->listing_id || false !== ( $effects->saved ?? null ) || ( 'listing.media_import' === $capability ? ! is_int( $effects->attachment_id ?? null ) || ! wp_attachment_is_image( $effects->attachment_id ) : ! is_array( $effects->diff ?? null ) || ! $effects->diff ) ) {
				throw new ContractException( 'result_unconfirmed', 'The native form result cannot be verified.' );
			}
			$data['outcome_kind'] = 'listing.media_import' === $capability ? 'media_ready' : ( 'listing.term_fill' === $capability ? 'terms_ready' : 'form_ready' );
		}
		return $data;
	}

	/**
	 * Classify safe recovery without exposing raw provider errors or suggesting duplicate work.
	 *
	 * @param object $checkpoint Durable runtime state.
	 * @param string $state Terminal run state.
	 * @param int    $run_id Owned run database identity.
	 * @return array Safe failure and next action.
	 */
	private function failure( object $checkpoint, string $state, int $run_id ): array {
		$allowed   = array( 'provider_unavailable', 'managed_unavailable', 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'managed_failed', 'managed_request_rejected', 'invalid_token', 'insufficient_balance', 'unsupported_provider_capability', 'provider_capability_unknown', 'provider_configuration_changed', 'budget_exhausted', 'output_truncated', 'usage_bound_exceeded', 'invalid_output', 'evidence_stale', 'stale_evidence', 'storage_unavailable', 'context_unavailable', 'unsupported_dependency', 'invalid_input', 'generation_stalled', 'input_context_too_large', 'scope_mismatch' );
		$code      = in_array( $checkpoint->error ?? null, $allowed, true ) ? $checkpoint->error : 'request_failed';
		$existing  = (int) $this->db->scalar( 'SELECT COUNT(*) FROM ' . $this->db->table( 'operations' ) . " WHERE run_id=%d AND state IN ('running','unknown','partial','succeeded')", array( $run_id ) );
		$uncertain = $existing > 0 || in_array( $state, array( 'unknown', 'partial' ), true ) || in_array( $code, array( 'managed_pending', 'managed_uncertain', 'managed_transport_uncertain', 'storage_unavailable' ), true ) || isset( $checkpoint->receipt );
		$notice    = $uncertain ? 'The request outcome needs checking. Its original request and receipt are retained. Check the status before starting another generation.' : FailureNotice::listing_message( $code, $checkpoint );
		return array(
			'outcome_kind'   => $uncertain ? 'unconfirmed' : 'failed',
			'failure_code'   => $code,
			'failure_notice' => $notice,
			'recovery'       => $uncertain ? 'check_status' : 'new_request',
		);
	}

	/**
	 * Resolve a run’s owned conversation identity.
	 *
	 * @param array $run Owned durable run.
	 * @return string
	 */
	private function conversation_id( array $run ): string {
		$table = $this->db->table( 'conversations' );
		return $this->db->rows( "SELECT public_id FROM $table WHERE id=%d", array( $run['conversation_id'] ) )[0]['public_id'];
	}

	/**
	 * Return a redacted public listing error.
	 *
	 * @param \Throwable $error Internal failure to redact.
	 * @return \WP_Error
	 */
	private function error( \Throwable $error ): \WP_Error {
		return new \WP_Error( $error instanceof StorageUnavailableException ? 'storage_unavailable' : ( $error instanceof ContractException ? $error->error_code() : 'listing_unavailable' ), $error instanceof StorageUnavailableException ? 'Listing status is temporarily unavailable. The original request was retained. Check its status before starting another generation.' : ( $error instanceof ContractException ? $error->getMessage() : 'Listing assistance is unavailable.' ), array( 'status' => $error instanceof StorageUnavailableException ? 503 : 409 ) );
	}
}
