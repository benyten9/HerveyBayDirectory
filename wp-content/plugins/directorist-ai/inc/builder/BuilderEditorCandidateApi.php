<?php
/**
 * Owned, approved native bytes for one current unsaved editor canvas.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Effects\EffectPlan;
use DirectoristAIPro\Effects\EffectTarget;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\UI\EditorWorkingCopy;
use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonCanonicalizer;
use DirectoristAIPro\Validation\JsonDecoder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Narrow authenticated native boundary.

/** A completed candidate is not a saved page and has no public URL. */
final class BuilderEditorCandidateApi {
	/**
	 * Optional isolated runtime connection for native integration fixtures.
	 *
	 * @var Database|null
	 */
	private ?Database $db;

	public function __construct( ?Database $db = null ) {
		$this->db = $db;
	}

	public function register(): void {
		register_rest_route(
			'directorist-ai/v1',
			'/builder/editor-candidate/(?P<candidate_id>[A-Za-z0-9_.:-]{1,128})',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( $this, 'read' ),
			)
		);
	}

	public function read( \WP_REST_Request $request ) {
		global $wpdb;
		try {
			$id    = (string) $request['candidate_id'];
			$token = $request->get_header( 'X-Directorist-Editor-Token' );
			if ( ! is_string( $token ) || '' === $token ) {
				$token = $request->get_param( 'editor_token' );
			}
			if ( get_current_user_id() < 1 || ! preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/D', $id ) || ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) {
				throw new ContractException( 'unauthorized', 'The native editor candidate is unavailable.' );
			}
			$db         = $this->db ?? new Database( $wpdb );
			$operations = $db->table( 'operations' );
			$approvals  = $db->table( 'approvals' );
			$rows       = $db->rows( "SELECT o.*,a.review_json,a.state AS approval_state FROM $operations o INNER JOIN $approvals a ON a.id=o.approval_id AND a.actor_ref=o.actor_ref AND a.capability=o.capability AND a.proposal_hash=o.proposal_hash WHERE o.public_id=%s AND o.actor_ref=%s AND o.capability='builder.stage' AND o.capability_revision='1' AND o.state='succeeded' LIMIT 2", array( $id, 'user:' . get_current_user_id() ) );
			if ( 1 !== count( $rows ) || 'consumed' !== $rows[0]['approval_state'] ) {
				throw new ContractException( 'approval_required', 'This native editor candidate has no completed exact approval.' );
			}
			$row     = $rows[0];
			$review  = ( new JsonDecoder( 1048576, 32 ) )->decode( $row['review_json'] );
			$receipt = ( new JsonDecoder( 1048576, 32 ) )->decode( $row['receipt_json'] );
			$data    = $review->plan ?? null;
			$targets = $data->targets ?? null;
			if ( ! $data instanceof \stdClass || ! is_array( $targets ) || 1 !== count( $targets ) || 'succeeded' !== ( $receipt->state ?? null ) || ! hash_equals( $id, (string) ( $receipt->operation_id ?? '' ) ) ) {
				throw new ContractException( 'evidence_stale', 'This native editor candidate has no exact receipt.' );
			}
			$target    = $targets[0];
			$plan      = new EffectPlan( $id, 'user:' . get_current_user_id(), 'builder.stage', '1', $row['proposal_hash'], array( new EffectTarget( $target->key, $target->expected_version, $target->effect_hash ) ), (int) $row['run_id'], (int) $row['approval_id'] );
			$canonical = new JsonCanonicalizer();
			if ( ! hash_equals( $plan->hash(), (string) ( $receipt->plan_hash ?? '' ) ) || ! hash_equals( $plan->target_set_hash(), $row['target_set_hash'] ) || ! hash_equals( $plan->proposal_hash, (string) ( $receipt->proposal_hash ?? '' ) ) || $canonical->encode( $plan->data() ) !== $canonical->encode( $receipt->plan ?? null ) || ( $data->operation_id ?? null ) !== $id || ( $data->actor_ref ?? null ) !== $plan->actor_ref || ( $data->capability ?? null ) !== 'builder.stage' || ( $data->capability_revision ?? null ) !== '1' || ( $data->run_id ?? null ) !== $plan->run_id || ( $data->proposal_hash ?? null ) !== $plan->proposal_hash ) {
				throw new ContractException( 'evidence_stale', 'This native editor candidate receipt differs from its review.' );
			}
			$stage  = ( new BuilderDraftStageArtifactReader( $db ) )->for_plan( $plan )->payload;
			$record = EditorWorkingCopy::assert_candidate_source( $stage );
			if ( ! hash_equals( $record['token_hash'], hash( 'sha256', $token ) ) ) {
				throw new ContractException( 'unauthorized', 'This candidate belongs to another editor session.' );
			}
			if ( 'POST' === $request->get_method() ) {
				$input = ( new JsonDecoder( 2097152, 48 ) )->decode( $request->get_body() );
				if ( ! $input instanceof \stdClass || array( 'snapshot' ) !== array_keys( get_object_vars( $input ) ) || ! ( $input->snapshot ?? null ) instanceof \stdClass || ! hash_equals( $stage->captured_snapshot_hash, hash( 'sha256', $canonical->encode( $input->snapshot ) ) ) ) {
					throw new ContractException( 'stale_version', 'The reloaded editor canvas differs from the captured source.' );
				}
			}
			$outcomes = ( $receipt->targets ?? null ) instanceof \stdClass ? get_object_vars( $receipt->targets ) : array();
			$outcome  = $outcomes[ $target->key ] ?? null;
			$effects  = $outcome->effects ?? null;
			$impact   = $review->impact ?? null;
			if ( 1 !== count( $outcomes ) || 'succeeded' !== ( $outcome->status ?? null ) || ( $outcome->before_version ?? null ) !== $stage->source_hash || ( $outcome->after_version ?? null ) !== $stage->native_content_hash || 'editor_candidate' !== ( $effects->output_mode ?? null ) || false !== ( $effects->saved ?? null ) || ( $effects->candidate_id ?? null ) !== $id || ( $effects->native_content_hash ?? null ) !== $stage->native_content_hash || ( $effects->capture_id ?? null ) !== $stage->capture_id || ( $effects->captured_snapshot_hash ?? null ) !== $stage->captured_snapshot_hash || $canonical->encode( $effects->original_document ?? null ) !== $canonical->encode( $stage->original_document ) || 'editor_candidate' !== ( $impact->output_mode ?? null ) || ( $impact->candidate_id ?? null ) !== $id || ( $impact->capture_id ?? null ) !== $stage->capture_id || ( $impact->candidate_hash ?? null ) !== $stage->native_content_hash || false !== ( $impact->saved ?? null ) || $canonical->encode( $impact->original_document ?? null ) !== $canonical->encode( $stage->original_document ) ) {
				throw new ContractException( 'evidence_stale', 'This native editor candidate is not the reviewed completed result.' );
			}
			$query_hint = null;
			if ( isset( $stage->native_directory_query ) ) {
				$query_hint = json_decode( wp_json_encode( (object) array( 'target' => $stage->native_directory_query->target, 'before' => $stage->native_directory_query->before, 'after' => $stage->native_directory_query->after ) ) );
				// An approved targeting hint uses the original editor identity. The
				// stored executable proof retains its exact internal native locator.
				$query_hint->target->document = $stage->original_document;
			}
			$response = rest_ensure_response(
				array(
					'id'                     => $id,
					'candidate_id'           => $id,
					'builder'                => $stage->source_document->builder,
					'original_document'      => $stage->original_document,
					'documentId'             => $stage->original_document->native_id,
					'capture_id'             => $stage->capture_id,
					'captured_snapshot_hash' => $stage->captured_snapshot_hash,
					'native_content_hash'    => $stage->native_content_hash,
					'payload'                => self::payload( $stage->source_document->builder, $stage->native_content ),
					'saved'                  => false,
					'directory_query'        => $query_hint,
					'completion_notes'       => BuilderCompletionNotes::validate( $stage->completion_notes ?? array() ),
				)
			);
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'editor_candidate_unavailable', 'This AI change is unavailable for the current editor canvas. Prepare it again from the current page.', array( 'status' => 409 ) );
		}
	}

	/** Convert only already compiled native content to each installed editor's snapshot shape. */
	public static function payload( string $builder, string $content ): object {
		if ( 'gutenberg' === $builder ) {
			return (object) array( 'content' => $content );
		}
		if ( 'elementor' === $builder ) {
			$elements = ( new JsonDecoder( 2097152, 48 ) )->decode( $content );
			if ( ! is_array( $elements ) ) {
				throw new ContractException( 'native_invalid', 'The compiled Elementor candidate is invalid.' );
			}
			return (object) array( 'elements' => $elements );
		}
		if ( 'divi' !== $builder ) {
			throw new ContractException( 'unsupported_dependency', 'This native editor candidate is unavailable.' );
		}
		$blocks = parse_blocks( $content );
		if ( serialize_blocks( $blocks ) !== $content ) {
			throw new ContractException( 'native_invalid', 'The compiled Divi candidate is invalid.' );
		}
		$blocks = array_values( array_filter( $blocks, static fn( array $block ): bool => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] ) ) );
		if ( 1 === count( $blocks ) && 'divi/placeholder' === $blocks[0]['blockName'] ) {
			$blocks = $blocks[0]['innerBlocks'];
		}
		$count = 0;
		$visit = static function ( array $nodes, int $depth ) use ( &$visit, &$count ): array {
			$result = array();
			if ( $depth > 24 ) {
				throw new ContractException( 'native_invalid', 'The compiled Divi candidate exceeds its native bound.' );
			}
			foreach ( $nodes as $node ) {
				if ( null === $node['blockName'] && '' === trim( $node['innerHTML'] ) ) {
					continue;
				}
				if ( ++$count > 500 || ! is_string( $node['blockName'] ) || ! preg_match( '/^(divi|directorist)\/[a-z0-9-]+$/D', $node['blockName'] ) ) {
					throw new ContractException( 'native_invalid', 'The compiled Divi candidate contains an unsupported native node.' );
				}
				$result[] = (object) array( 'name' => $node['blockName'], 'attrs' => (object) $node['attrs'], 'children' => $visit( $node['innerBlocks'], $depth + 1 ) );
			}
			return $result;
		};
		return (object) array( 'modules' => $visit( $blocks, 0 ) );
	}
}
