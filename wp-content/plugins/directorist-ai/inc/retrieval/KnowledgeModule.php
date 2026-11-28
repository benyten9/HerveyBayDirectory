<?php
/**
 * Approved-knowledge operation and lifecycle registration.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment -- Compact strict schemas are easier to audit together.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Operation;
use DirectoristAIPro\Capabilities\Registry;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\SchemaRegistry;

/** Publish one evidence-only read operation over explicitly approved sources. */
final class KnowledgeModule {
	/**
	 * Trusted source management and lookup service.
	 *
	 * @var KnowledgeService
	 */
	private KnowledgeService $service;

	/**
	 * Authoritative runtime storage shared by operation availability.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind isolated site storage.
	 *
	 * @param Database $db Runtime-owned storage.
	 */
	public function __construct( Database $db ) {
		$this->db      = $db;
		$this->service = new KnowledgeService( new KnowledgeStore( $db ) );
	}

	/** Register before Runtime freezes operations, then install source hooks. */
	public function hooks(): void {
		add_action( 'directorist_ai_pro_register_operations', array( $this, 'register' ), 10, 2 );
		$this->service->hooks();
	}

	/**
	 * Register the closed knowledge.search contract.
	 *
	 * @param Registry       $registry Shared operation registry.
	 * @param SchemaRegistry $schemas Shared strict schema registry.
	 */
	public function register( Registry $registry, SchemaRegistry $schemas ): void {
		$schemas->register( 'knowledge.search.input', wp_json_encode( $this->input_schema() ) );
		$schemas->register( 'knowledge.search.output', wp_json_encode( $this->output_schema() ) );
		$service = $this->service;
		$registry->register(
			new Operation(
				array(
					'id'            => 'knowledge.search',
					'revision'      => '1',
					'description'   => 'Retrieve current approved site knowledge as untrusted evidence with source citations.',
					'input_schema'  => 'knowledge.search.input',
					'output_schema' => 'knowledge.search.output',
					'effect'        => 'read',
					'profiles'      => array( 'admin', 'owner', 'visitor', 'builder', 'external' ),
					'allow_guest'   => true,
				),
				static fn(): bool => true,
				static fn(): bool => true,
				fn(): bool => $this->db->readiness()->status()['effects_enabled'],
				static fn( object $input, Context $context ): object => $service->search( $input, $context )
			)
		);
	}

	/** Expose the trusted source-management service to the later admin settings UI. */
	public function service(): KnowledgeService {
		return $this->service;
	}

	/** Strict public operation input. */
	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'        => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 500 ),
				'directory_id' => array( 'type' => array( 'integer', 'null' ), 'minimum' => 1 ),
				'source_ids'   => array(
					'type'        => 'array',
					'maxItems'    => 20,
					'uniqueItems' => true,
					'items'       => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_.:-]{0,127}$' ),
				),
				'limit'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ),
			),
			'required'             => array( 'query', 'directory_id', 'source_ids', 'limit' ),
			'additionalProperties' => false,
		);
	}

	/** Strict evidence-only result with no operation/approval-shaped fields. */
	private function output_schema(): array {
		$hash = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'contract'        => array( 'const' => 'directorist-ai/knowledge-v1' ),
				'status'          => array( 'enum' => array( 'evidence', 'no_evidence', 'conflict' ) ),
				'items'           => array(
					'type'     => 'array',
					'maxItems' => 10,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'text'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 4000 ),
							'score'    => array( 'type' => 'integer', 'minimum' => 1 ),
							'citation' => array(
								'type'                 => 'object',
								'properties'           => array(
									'source_id' => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_.:-]{0,127}$' ),
									'version'   => $hash,
									'title'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
									'section'   => array( 'type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_.:-]{0,127}$' ),
									'section_title' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
									'scope'     => array( 'enum' => array( 'public', 'private' ) ),
								),
								'required'             => array( 'source_id', 'version', 'title', 'section', 'section_title', 'scope' ),
								'additionalProperties' => false,
							),
						),
						'required'             => array( 'text', 'score', 'citation' ),
						'additionalProperties' => false,
					),
				),
				'diagnostics'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'candidate_count' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 200 ),
						'matched_count'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 10 ),
						'reason'          => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'current_sources_disagree', 'no_current_eligible_evidence', null ) ),
					),
					'required'             => array( 'candidate_count', 'matched_count', 'reason' ),
					'additionalProperties' => false,
				),
				'evidence_policy' => array(
					'type'                 => 'object',
					'properties'           => array(
						'content_role'       => array( 'const' => 'untrusted_evidence' ),
						'can_grant_tools'    => array( 'const' => false ),
						'can_grant_approval' => array( 'const' => false ),
					),
					'required'             => array( 'content_role', 'can_grant_tools', 'can_grant_approval' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'contract', 'status', 'items', 'diagnostics', 'evidence_policy' ),
			'additionalProperties' => false,
		);
	}
}
