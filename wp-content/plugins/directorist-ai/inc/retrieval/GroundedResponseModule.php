<?php
/**
 * Grounded customer retrieval registration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Compact strict schemas are easier to review together.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Domain\DirectoristInspector;
use DirectoristAIPro\Domain\ListingQueryCatalog;
use DirectoristAIPro\Domain\ListingQueryCursor;
use DirectoristAIPro\Domain\ListingQueryService;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;
use DirectoristAIPro\Validation\ContractValidator;

/** Publish current public comparison and retain the safe response workflow. */
final class GroundedResponseModule {
	private ListingComparisonService $comparisons;
	private GroundedResponseService $responses;
	private Database $db;

	/** Build one site-scoped workflow from existing trusted adapters. */
	public function __construct( Database $db, KnowledgeService $knowledge ) {
		$this->db          = $db;
		$inspector         = new DirectoristInspector();
		$catalog           = new ListingQueryCatalog( $inspector );
		$this->comparisons = new ListingComparisonService( $inspector, $catalog );
		$this->responses   = new GroundedResponseService(
			new ListingQueryService( $inspector, $catalog, new ListingQueryCursor( wp_salt( 'auth' ) ), 1000, null, $db ),
			$this->comparisons,
			$knowledge,
			new GroundedContextToken( wp_salt( 'auth' ) ),
			$db
		);
	}

	/** Register before the shared runtime freezes operation definitions. */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		add_action( 'directorist_ai_pro_gateway_ready', array( $this->responses, 'bind_gateway' ) );
	}

	/** Register the closed public-only listing.compare operation. */
	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		\DirectoristAIPro\Chat\ListingActions::register_operations( $registry, $schemas, $this->db );
		$schemas->register( 'listing.compare.input', wp_json_encode( $this->input_schema() ) );
		$schemas->register( 'listing.compare.output', wp_json_encode( $this->output_schema() ) );
		$comparisons = $this->comparisons;
		$registry->register(
			new Operation(
				array(
					'id'            => 'listing.compare',
					'revision'      => '2',
					'description'   => 'Read requested public fields of one listing or compare up to five, preserving unknown stored facts. Distance requires an origin-based listing.search near query; it is not a comparable published field.',
					'input_schema'  => 'listing.compare.input',
					'output_schema' => 'listing.compare.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'owner', 'visitor', 'external' ),
					'allow_guest'   => true,
				),
				static fn( object $input, Context $context ): bool => ( new ListingQueryCatalog( new DirectoristInspector() ) )->permitted( $context, $input->directory_id, 'public' ),
				static fn(): bool => true,
				fn(): bool => ( new DirectoristInspector() )->available() && $this->db->readiness()->status()['effects_enabled'],
				static fn( object $input, Context $context ): object => $comparisons->compare( $context, $input )
			)
		);
		GroundedResponseContracts::register( $schemas );
		$this->responses->bind_gateway( new Gateway( $registry, new ContractValidator( $schemas ) ) );
		$registry->register(
			new Operation(
				array( 'id' => 'conversation.respond', 'revision' => '1', 'description' => 'Finish with exactly one current search, comparison, approved knowledge or native action plan. Native actions open workflows without completing transactions. For a total-count question, set search.include_count=true. Otherwise count is unavailable. Supply no answer prose, facts, authority or provenance. Use signed context_token for follow-ups. Mark ambiguous references ambiguous.', 'input_schema' => 'conversation.respond.input', 'output_schema' => 'conversation.respond.output', 'effect' => 'read', 'profiles' => array( 'admin', 'owner', 'visitor' ), 'allow_guest' => true ),
				fn( object $input, Context $context ): bool => $this->bound_run( $context ),
				static fn(): bool => true,
				fn(): bool => $this->db->readiness()->status()['effects_enabled'],
				fn( object $input, Context $context ): object => $this->responses->respond( $context, $input )
			)
		);
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$schemas->register( 'conversation.result.input', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'run_id' => array( 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_.:-]{1,128}$' ), 'question_hash' => $hash, 'plan_hash' => $hash, 'evidence_hash' => $hash ), 'required' => array( 'run_id', 'question_hash', 'plan_hash', 'evidence_hash' ), 'additionalProperties' => false ) ) );
		$response_schema       = $schemas->get( 'conversation.respond.output' );
		$response_schema->type = array( 'object', 'null' );
		$schemas->register( 'conversation.result.output', wp_json_encode( array( 'type' => 'object', 'properties' => array( 'status' => array( 'enum' => array( 'available', 'unsupported' ) ), 'reason' => array( 'enum' => array( null, 'current_evidence_unavailable' ) ), 'response' => $response_schema ), 'required' => array( 'status', 'reason', 'response' ), 'additionalProperties' => false ) ) );
		$registry->register(
			new Operation(
				array( 'id' => 'conversation.result', 'revision' => '1', 'description' => 'Revalidate the exact owned run result for current-source event delivery.', 'input_schema' => 'conversation.result.input', 'output_schema' => 'conversation.result.output', 'effect' => 'read', 'profiles' => array( 'admin', 'owner', 'visitor' ), 'allow_guest' => true ),
				function ( object $input, Context $context ): bool {
					if ( $input->run_id !== $context->run_id() || 'worker' !== $context->transport() || null === $context->conversation_id() ) {
						return false;
					}
					$runs          = $this->db->table( 'runs' );
					$conversations = $this->db->table( 'conversations' );
					return null !== $this->db->scalar( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.public_id=%s AND c.owner_ref=r.owner_ref AND c.profile=%s AND c.erasure_requested=0", array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
				},
				static fn(): bool => false,
				fn(): bool => $this->db->readiness()->status()['effects_enabled'],
				fn( object $input, Context $context ): object => $this->current_result( $input, $context )
			)
		);
	}

	/** A model may select a plan only inside its exact server-owned running task. */
	private function bound_run( Context $context ): bool {
		if ( 'worker' !== $context->transport() || null === $context->conversation_id() || null === $context->run_id() ) {
			return false;
		}
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		return null !== $this->db->scalar( "SELECT r.id FROM $runs r INNER JOIN $conversations c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND r.state='running' AND c.public_id=%s AND c.owner_ref=r.owner_ref AND c.profile=%s AND c.erasure_requested=0", array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
	}

	/** Revalidate only the server-produced plan, never a caller-supplied final payload. */
	private function current_result( object $input, Context $context ): object {
		$withheld = (object) array( 'status' => 'unsupported', 'reason' => 'current_evidence_unavailable', 'response' => null );
		$run      = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.public_id=%s AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.public_id=%s AND c.profile=%s AND c.erasure_requested=0', array( $context->run_id(), $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
		if ( 1 !== count( $run ) ) {
			return $withheld;
		}
		$checkpoint = json_decode( $run[0]['checkpoint_json'] );
		if ( ! isset( $checkpoint->grounded_input, $checkpoint->grounded_hash ) ) {
			return $withheld;
		}
		try {
			$messages = 'succeeded' === $run[0]['state'] ? $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . " WHERE conversation_id=%d AND turn_id=%d AND role='assistant' ORDER BY sequence LIMIT 21", array( (int) $run[0]['conversation_id'], (int) $run[0]['trigger_turn_id'] ) ) : array();
			$attested = null;
			if ( count( $messages ) <= 20 ) {
				foreach ( $messages as $message ) {
					$content = json_decode( $message['content_json'] );
					$trace   = $content->grounded->trace ?? null;
					if ( $trace instanceof \stdClass && ( $content->provenance->run_id ?? null ) === $run[0]['public_id'] && ( $trace->question_hash ?? null ) === $input->question_hash && ( $trace->plan_hash ?? null ) === $input->plan_hash && ( $trace->delivered_hash ?? null ) === $input->evidence_hash ) {
						$attested = $message;
						break;
					}
				}
			}
			$current = null !== $attested ? $this->responses->replay( $context, $checkpoint->grounded_input, $run[0], $attested ) : $this->responses->respond( $context, $checkpoint->grounded_input );
			$matches = hash_equals( $checkpoint->grounded_hash, $current->trace->delivered_hash ) && hash_equals( $input->evidence_hash, $current->trace->delivered_hash ) && hash_equals( $input->question_hash, $current->trace->question_hash ) && hash_equals( $input->plan_hash, $current->trace->plan_hash );
			if ( $matches && null !== $attested ) {
				$fresh_run     = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.public_id=%s AND c.profile=%s AND c.erasure_requested=0', array( (int) $run[0]['id'], $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
				$fresh_message = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . ' WHERE id=%d', array( (int) $attested['id'] ) );
				$matches       = 1 === count( $fresh_run ) && $fresh_run[0] === $run[0] && 1 === count( $fresh_message ) && $fresh_message[0] === $attested;
			}
			if ( $matches && isset( $checkpoint->visitor_reply ) ) {
				$current = \DirectoristAIPro\Orchestration\VisitorReply::apply( $run[0], $current, $checkpoint->visitor_reply ); }
			return $matches ? (object) array( 'status' => 'available', 'reason' => null, 'response' => $current ) : $withheld;
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return $withheld;
		}
	}

	/** Expose the in-process prepare/recheck/render boundary to later chat UI. */
	public function service(): GroundedResponseService {
		return $this->responses;
	}

	/** Exact comparison selector; public eligibility is server-owned. */
	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'listing_ids' => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'uniqueItems' => true, 'items' => array( 'type' => 'integer', 'minimum' => 1 ) ),
				'directory_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'fields'      => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'uniqueItems' => true, 'items' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9_-]{0,63}$' ) ),
			),
			'required'             => array( 'listing_ids', 'directory_id', 'fields' ),
			'additionalProperties' => false,
		);
	}

	/** Strict comparison facts with nullable values only for explicit states. */
	private function output_schema(): array {
		$source = array(
			'type'                 => array( 'object', 'null' ),
			'properties'           => array(
				'kind'        => array( 'enum' => array( 'post', 'meta', 'term' ) ),
				'object_id'   => array( 'type' => 'string' ),
				'key'         => array( 'type' => 'string' ),
				'observed_at' => array( 'type' => 'string' ),
			),
			'required'             => array( 'kind', 'object_id', 'key', 'observed_at' ),
			'additionalProperties' => false,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'         => array( 'const' => 'directorist-ai/compare-v1' ),
				'status'           => array( 'enum' => array( 'comparison', 'unsupported' ) ),
				'directory_id'     => array( 'type' => 'integer' ),
				'catalog_revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'listing_ids'      => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 5, 'uniqueItems' => true, 'items' => array( 'type' => 'integer' ) ),
				'rows'             => array(
					'type'     => 'array',
					'maxItems' => 12,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'field_id' => array( 'type' => 'string' ),
							'cells'    => array(
								'type'     => 'array',
								'maxItems' => 5,
								'items'    => array(
									'type'                 => 'object',
									'properties'           => array(
										'listing_id' => array( 'type' => 'integer' ),
										'state'      => array( 'enum' => array( 'known', 'unknown', 'missing' ) ),
										'value'      => true,
										'source'     => $source,
										'version'    => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
									),
									'required'             => array( 'listing_id', 'state', 'value', 'source', 'version' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'field_id', 'cells' ),
						'additionalProperties' => false,
					),
				),
				'limitations'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'contract', 'status', 'directory_id', 'catalog_revision', 'listing_ids', 'rows', 'limitations' ),
			'additionalProperties' => false,
		);
	}
}
