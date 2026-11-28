<?php
/**
 * Deterministic source-backed customer response workflow.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Response safety rules are easier to audit together.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Domain\ListingQueryService;
use DirectoristAIPro\Validation\ContractException;

/**
 * Convert a typed model plan into fixed prose and independently sourced data.
 *
 * Natural-language planning is replaceable. This boundary never accepts a
 * free-form factual claim from a model, and old results never authorize or
 * supply facts to a later response.
 */
final class GroundedResponseService {
	private ListingQueryService $queries;
	private ListingComparisonService $comparisons;
	private KnowledgeService $knowledge;
	private GroundedContextToken $tokens;
	private ?Gateway $gateway = null;
	private ?\DirectoristAIPro\Storage\Database $db;

	/** Bind the same strict operation registry used by the runtime. */
	public function bind_gateway( Gateway $gateway ): void {
		$this->gateway = $gateway;
	}

	/** Bind current retrieval services and structural follow-up signing. */
	public function __construct( ListingQueryService $queries, ListingComparisonService $comparisons, KnowledgeService $knowledge, GroundedContextToken $tokens, ?\DirectoristAIPro\Storage\Database $db = null ) {
		$this->queries     = $queries;
		$this->comparisons = $comparisons;
		$this->knowledge   = $knowledge;
		$this->tokens      = $tokens;
		$this->db          = $db;
	}

	/** Prepare and immediately deliver one response through the live recheck. */
	public function respond( Context $context, object $input ): object {
		$prepared = $this->prepare( $context, $input );
		do_action( 'directorist_ai_pro_grounded_prepared', $context );
		return $this->present( $context, $prepared );
	}

	/** Re-execute one exact durable, producer-sealed result without re-admitting its old turn. */
	public function replay( Context $context, object $input, array $run, array $message ): object {
		return $this->replay_mode( $context, $input, $run, $message, false );
	}

	/** A separate internal history seam; never registered as a model or public operation. */
	public function replay_clock_history( Context $context, object $input, array $run, array $message ): object {
		$content = json_decode( $message['content_json'] ?? '' );
		if ( ! $content instanceof \stdClass || ! GroundedHistoryClock::verifies( $run, (int) ( $message['sequence'] ?? 0 ), $content ) ) {
			throw new ContractException( 'stale_evidence', 'Historical clock source proof is unavailable.' );
		}
		return $this->replay_mode( $context, $input, $run, $message, true );
	}

	/** Both modes retain the exact original seal and every fresh native execution fence. */
	private function replay_mode( Context $context, object $input, array $run, array $message, bool $history ): object {
		$checkpoint = json_decode( $run['checkpoint_json'] ?? '' );
		$content    = json_decode( $message['content_json'] ?? '' );
		if ( null === $this->gateway || 'succeeded' !== ( $run['state'] ?? null ) || $context->run_id() !== ( $run['public_id'] ?? null ) || $context->conversation_id() !== ( $run['conversation_public_id'] ?? null ) || $context->profile() !== ( $run['profile'] ?? null ) || $context->actor()->owner()->reference() !== ( $run['owner_ref'] ?? null ) || (int) ( $message['conversation_id'] ?? 0 ) !== (int) ( $run['conversation_id'] ?? -1 ) || (int) ( $message['turn_id'] ?? 0 ) !== (int) ( $run['trigger_turn_id'] ?? -1 ) || 'assistant' !== ( $message['role'] ?? null ) || array() !== json_decode( $message['source_refs_json'] ?? '', true ) || ! $content instanceof \stdClass || ! $checkpoint instanceof \stdClass || ! isset( $checkpoint->grounded_input ) || wp_json_encode( $input ) !== wp_json_encode( $checkpoint->grounded_input ) || ! ( new GroundedMessageProvenance( $this->gateway ) )->verifies( $run, (int) ( $message['sequence'] ?? 0 ), $content, $checkpoint ) ) {
			throw new ContractException( 'invalid_input', 'Stored grounded replay requires its exact producer attestation.' );
		}
		$prior = 'follow_up' === $input->intent ? $this->tokens->decode_for_replay( $input->context_token ) : null;
		$searches = ! empty( $input->searches ) ? $input->searches : ( null !== $input->search ? array( $input->search ) : array() );
		if ( ! $searches && null !== $prior && 'search' === $prior->plan->kind ) {
			$searches = $prior->plan->payload->queries ?? array( $prior->plan->payload );
		}
		$cursors = array();
		foreach ( $searches as $search ) {
			if ( is_string( $search->cursor ?? null ) ) {
				$cursors[] = $search->cursor;
			}
		}
		return \DirectoristAIPro\Domain\ListingQueryCursor::with_replay_tokens(
			array_values( array_unique( $cursors ) ),
			function () use ( $context, $input, $content, $checkpoint, $history ): object {
				if ( $history && ! GroundedHistoryClock::sources_current( $this->gateway, $context, $content ) ) {
					throw new ContractException( 'stale_evidence', 'Historical native sources changed.' );
				}
				$prepared = $this->prepare_current( $context, $input, true );
				do_action( 'directorist_ai_pro_grounded_prepared', $context );
				$current = $this->present( $context, $prepared );
				$exact = hash_equals( $checkpoint->grounded_hash, $current->trace->delivered_hash ) && hash_equals( $content->grounded->trace->question_hash, $current->trace->question_hash ) && hash_equals( $content->grounded->trace->plan_hash, $current->trace->plan_hash ) && hash_equals( $content->grounded->trace->delivered_hash, $current->trace->delivered_hash );
				if ( $history ? ( $exact || ! GroundedHistoryClock::current( $this->gateway, $context, $content, $current ) ) : ! $exact ) {
					throw new ContractException( 'stale_evidence', 'Stored grounded evidence changed.' );
				}
				$current = $this->renew_replay_context( $current );
				return $current;
			}
		);
	}

	/** Renew only outgoing structural context after the original native evidence matched. */
	private function renew_replay_context( object $current ): object {
		if ( ! is_string( $current->context_token ) ) {
			return $current;
		}
		$payload = $this->tokens->decode_for_replay( $current->context_token );
		if ( 'search' === $payload->plan->kind ) {
			$searches = $payload->plan->payload->queries ?? array( $payload->plan->payload );
			foreach ( $searches as $search ) {
				if ( is_string( $search->cursor ?? null ) ) {
					$renewed = \DirectoristAIPro\Domain\ListingQueryCursor::renewed_replay_token( $search->cursor );
					if ( null !== $renewed ) {
						$search->cursor = $renewed;
					}
				}
			}
		}
		// Same lifetime as the existing follow_up_token issuer; originals stay unchanged.
		$payload->expires = time() + 1800;
		$current->context_token = $this->tokens->encode( (array) $payload );
		return $current;
	}

	/**
	 * Validate a deterministic planner result and capture initial evidence.
	 *
	 * @throws ContractException Invalid, stale or cross-scope plans.
	 */
	public function prepare( Context $context, object $input ): PreparedGroundedResponse {
		return $this->prepare_current( $context, $input, false );
	}

	/** Only the attested replay entrypoint can change continuation expiration handling. */
	private function prepare_current( Context $context, object $input, bool $replay ): PreparedGroundedResponse {
		if ( null === $this->gateway ) {
			throw new ContractException( 'unsupported_dependency', 'Grounded retrieval requires the shared gateway.' );
		}
		$input = $this->gateway->validate_input( 'conversation.respond', wp_json_encode( $input ) );
		$input = $this->global_comparison( $context, $input );
		$this->validate_input( $input );
		$scope = $this->scope_hash( $context );
		if ( 'follow_up' === $input->intent ) {
			$prior = $replay ? $this->tokens->decode_for_replay( $input->context_token ) : $this->tokens->decode( $input->context_token );
			if ( get_current_blog_id() !== $prior->site || ! hash_equals( $scope, $prior->scope ) ) {
				throw new ContractException( 'scope_conflict', 'Follow-up context belongs to another task scope.' );
			}
			if ( 'ambiguous' === $input->resolution ) {
				$plan   = (object) array( 'kind' => 'ambiguous', 'payload' => $prior->plan, 'family' => $prior->family, 'directory_id' => $prior->directory_id, 'dataset' => $prior->dataset, 'context_token' => $input->context_token );
				$result = (object) array( 'status' => 'needs_input' );
				return $this->prepared( $input->question, $plan, $result, $scope );
			}
			$plan = $this->resolved_plan( $input );
			if ( null === $plan ) {
				$plan = $prior->plan;
			}
			$this->assert_follow_up_scope( $prior, $plan );
		} elseif ( 'unsupported' === $input->resolution ) {
			$plan   = (object) array( 'kind' => 'unsupported', 'payload' => (object) array(), 'family' => 'listing', 'directory_id' => null, 'dataset' => null );
			$result = (object) array( 'status' => 'unsupported' );
			return $this->prepared( $input->question, $plan, $result, $scope );
		} else {
			$plan = $this->resolved_plan( $input );
			if ( null === $plan ) {
				throw new ContractException( 'invalid_input', 'Resolved response requires one typed plan.' );
			}
		}

		if ( isset( $input->result_limit ) ) {
			if ( 'search' !== $plan->kind ) {
				throw new ContractException( 'invalid_input', 'A total result limit requires a search plan.' ); }
			$plan = clone $plan;
			$plan->payload = clone $plan->payload;
			if ( isset( $plan->payload->queries ) ) {
				$plan->payload->result_limit = $input->result_limit; } else {
				$plan->payload->limit = min( $plan->payload->limit, $input->result_limit ); }
		}

		try {
			if ( 'visitor' === $context->profile() && 'listing' === $plan->family && null !== $this->db ) {
				foreach ( $this->plan_directories( $plan ) as $directory ) {
					\DirectoristAIPro\Chat\ListingActions::assert_scope( $context, $directory, $this->db );
				}
			}
			$result = $this->execute( $context, $plan );
		} catch ( ContractException $error ) {
			if ( ! $this->unsupported_error( $error ) ) {
				throw $error;
			}
			$result = (object) array( 'status' => 'unsupported', 'reason' => $error->error_code() );
		}
		return $this->prepared( $input->question, $plan, $result, $scope );
	}

	/**
	 * Re-execute the plan under current authority immediately before rendering.
	 *
	 * @throws ContractException Stale principal, site or plan.
	 */
	public function present( Context $context, PreparedGroundedResponse $prepared ): object {
		$context->actor()->refresh();
		$context->assert_execution_identity();
		$scope = $this->scope_hash( $context );
		if ( get_current_blog_id() !== $prepared->site_id || ! hash_equals( $scope, $prepared->scope_hash ) ) {
			throw new ContractException( 'scope_conflict', 'Prepared response belongs to another task scope.' );
		}
		$plan = $prepared->plan;
		if ( ! hash_equals( $prepared->plan_hash, $this->hash( $plan ) ) ) {
			throw new ContractException( 'invalid_input', 'Prepared response plan changed after validation.' );
		}
		if ( 'ambiguous' === $plan->kind ) {
			return $this->response( 'needs_input', 'Please clarify the requested filter or comparison before I continue.', $plan, array(), null, array(), array(), $prepared, $prepared->initial_result, $plan->context_token );
		}
		if ( 'unsupported' === $plan->kind || 'unsupported' === ( $prepared->initial_result->status ?? null ) ) {
			return $this->response( 'unsupported', 'That request cannot be answered from the supported current fields and sources.', $plan, array(), null, array(), array(), $prepared, $prepared->initial_result, null );
		}
		try {
			if ( 'visitor' === $context->profile() && 'listing' === $plan->family && null !== $this->db ) {
				foreach ( $this->plan_directories( $plan ) as $directory ) {
					\DirectoristAIPro\Chat\ListingActions::assert_scope( $context, $directory, $this->db );
				}
			}
			$current = $this->execute( $context, $plan );
		} catch ( ContractException $error ) {
			if ( $this->unsupported_error( $error ) ) {
				$current = (object) array( 'status' => 'unsupported', 'reason' => $error->error_code() );
			} else {
				throw $error;
			}
		}
		return $this->render( $plan, $current, $prepared );
	}

	/** Render only fixed prose and values that still carry current evidence. */
	private function render( object $plan, object $result, PreparedGroundedResponse $prepared ): object {
		$token = $this->follow_up_token( $prepared->scope_hash, $plan );
		if ( 'action' === $plan->kind ) {
			$available = ! empty( $result->actions );
			$message = isset( $result->workflow ) ? 'Review the request below. Submission requires your explicit confirmation.' : ( $available ? ( 'booking' === $plan->payload->action_id ? 'Open booking to choose your dates, check availability and review the cost. No reservation or payment has been made in this chat.' : 'Use the listing action below to continue in its native workflow.' ) : 'That action is not available for this listing. You can open the listing to see its current options.' );
			return $this->response( $available ? 'results' : 'unsupported', $message, $plan, array(), null, array(), array(), $prepared, $result, $token, array(), array(), $result );
		}
		if ( 'unsupported' === ( $result->status ?? null ) ) {
			return $this->response( 'unsupported', 'That request cannot be answered from the supported current fields and sources.', $plan, array(), null, array(), array(), $prepared, $result, $token );
		}
		if ( isset( $plan->payload->queries ) ) {
			$cards = array();
			$limitations = array();
			foreach ( $result->results as $entry ) {
				foreach ( $entry->result->items as $listing ) {
					$cards[] = $this->card( $entry->directory_id, $listing ); }
				$limitations = array_merge( $limitations, $entry->result->limitations );
			}
			$cards = $this->ordered_cards( $cards, $plan->payload->queries );
			if ( isset( $plan->payload->result_limit ) ) {
				$cards = array_slice( $cards, 0, $plan->payload->result_limit ); }
			$current = $result;
			$message = is_int( $result->count ?? null ) ? sprintf( 'I found %d public listing%s across the relevant directories. Showing %d.', $result->count, 1 === $result->count ? '' : 's', count( $cards ) ) : ( $cards ? sprintf( 'Here %s %d listing%s from the relevant directories.', 1 === count( $cards ) ? 'is' : 'are', count( $cards ), 1 === count( $cards ) ? '' : 's' ) : 'I could not find a listing for those filters.' );
			$order_fields = array_column( $plan->payload->queries[0]->order, 'field' );
			if ( $cards && array_intersect( array( 'rating', 'review_count' ), $order_fields ) && ! array_filter(
				$cards,
				static function ( object $card ): bool {
					foreach ( $card->facts as $fact ) {
						if ( in_array( $fact->field_id, array( 'rating', 'review_count' ), true ) && 'known' === $fact->state && is_numeric( $fact->value ) && $fact->value > 0 ) {
							return true;
						}
					}
					return false;
				}
			) ) {
				$message .= ' These listings have no published ratings or reviews to establish popularity. Do not describe them as popular or top rated.';
				$limitations[] = 'no_published_popularity_signal';
			}
			return $this->response( $cards ? 'results' : 'missing', $message, $plan, $cards, null, array(), $this->listing_assertions( $cards ), $prepared, $current, $token, $limitations );
		}
		if ( isset( $plan->payload->comparisons ) ) {
			$sets = array();
			$assertions = array();
			$limitations = array();
			foreach ( $result->results as $entry ) {
				$sets[] = $entry->result;
				$limitations = array_merge( $limitations, $entry->result->limitations );
				foreach ( $entry->result->rows as $row ) {
					foreach ( $row->cells as $cell ) {
						if ( 'known' === $cell->state ) {
							$assertions[] = (object) array( 'subject' => 'listing:' . $cell->listing_id, 'predicate' => $row->field_id, 'value' => $cell->value, 'source' => $cell->source, 'version' => $cell->version ); }
					}
				}
			}
			$output = $this->response( $assertions ? 'results' : 'unknown', $assertions ? 'Here is what I found for these listings.' : 'That information is not available for these listings.', $plan, array(), null, array(), $assertions, $prepared, $result, $token, $limitations );
			$output->comparisons = $sets;
			return $this->bounded_output( $output );
		}
		if ( 'search' === $plan->kind ) {
			$cards      = array_map( fn( object $listing ): object => $this->card( $plan->directory_id, $listing ), $result->items );
			$assertions = $this->listing_assertions( $cards );
			$status     = $cards ? 'results' : 'missing';
			$count      = $plan->payload->include_count ? $result->count : null;
			if ( $cards ) {
				$message = null === $count ? sprintf( 'Here are %d listing%s.', count( $cards ), 1 === count( $cards ) ? '' : 's' ) : sprintf( 'I found %d listing%s. Showing %d.', $count, 1 === $count ? '' : 's', count( $cards ) );
			} else {
				$message = null === $count ? ( null === $plan->payload->cursor ? 'I could not find a listing for those filters.' : 'There are no more listings on this page.' ) : sprintf( 'I found %d listing%s.%s', $count, 1 === $count ? '' : 's', $count > 0 ? ' There are no more on this page.' : '' );
			}
			return $this->response( $status, $message, $plan, $cards, null, array(), $assertions, $prepared, $result, $token, $result->limitations );
		}
		if ( 'compare' === $plan->kind ) {
			if ( 'unsupported' === $result->status ) {
				return $this->response( 'unsupported', 'One or more requested comparison fields are unavailable.', $plan, array(), null, array(), array(), $prepared, $result, $token, $result->limitations );
			}
			$assertions = array();
			$has_known  = false;
			foreach ( $result->rows as $row ) {
				foreach ( $row->cells as $cell ) {
					if ( 'known' === $cell->state ) {
						$has_known    = true;
						$assertions[] = (object) array( 'subject' => 'listing:' . $cell->listing_id, 'predicate' => $row->field_id, 'value' => $cell->value, 'source' => $cell->source, 'version' => $cell->version );
					}
				}
			}
			$status  = $has_known ? 'results' : 'unknown';
			$message = $has_known ? ( 1 === count( $result->listing_ids ) ? 'Here is what I found for this listing.' : 'Here is how these listings compare.' ) : 'That information is not available for these listings.';
			return $this->response( $status, $message, $plan, array(), $result, array(), $assertions, $prepared, $result, $token, $result->limitations );
		}

		$evidence = $result->items;
		$citations = array_map( static fn( object $item ): object => $item->citation, $evidence );
		if ( 'conflict' === $result->status ) {
			return $this->response( 'conflict', 'Current approved sources disagree, so I cannot present one policy as the answer.', $plan, array(), null, $evidence, array(), $prepared, $result, $token, array( 'current_sources_disagree' ), $citations );
		}
		if ( 'evidence' !== $result->status || ! $evidence ) {
			return $this->response( 'missing', 'I could not find current approved evidence for that question.', $plan, array(), null, array(), array(), $prepared, $result, $token, array( 'no_current_eligible_evidence' ) );
		}
		return $this->response( 'results', 'I found current approved evidence for this question.', $plan, array(), null, $evidence, array(), $prepared, $result, $token, array(), $citations );
	}

	/** Build a closed response shared by chat and later renderers. */
	private function response( string $status, string $message, object $plan, array $cards, ?object $comparison, array $evidence, array $assertions, PreparedGroundedResponse $prepared, object $current, ?string $token, array $limitations = array(), array $citations = array(), ?object $native_actions = null ): object {
		$reason = $current->reason ?? $prepared->initial_result->reason ?? null;
		if ( 'unsupported' === $status && in_array( $reason, array( 'query_too_broad', 'stale_catalog', 'invalid_cursor', 'needs_expansion', 'invalid_query' ), true ) ) {
			$limitations[] = 'retrieval_unavailable:' . $reason;
			$message = 'This search could not be completed. No empty result or listing total was verified. Ask for a more specific category, area or business name; do not say no listings matched.';
		}
		$output = (object) array(
			'contract'          => 'directorist-ai/grounded-response-v1',
			'status'            => $status,
			'message'           => $message,
			'task'              => (object) array( 'kind' => $plan->kind, 'family' => $plan->family, 'directory_id' => $plan->directory_id, 'dataset' => $plan->dataset ),
			'count'             => 'search' === $plan->kind && ( isset( $plan->payload->queries ) || ! empty( $plan->payload->include_count ) ) && isset( $current->count ) && is_int( $current->count ) ? $current->count : null,
			'cards'             => $cards,
			'comparison'        => $comparison,
			'evidence'          => $evidence,
			'citations'         => $citations,
			'assertions'        => $assertions,
			'limitations'       => array_values( array_unique( $limitations ) ),
			'context_token'     => $token,
			'trace'             => (object) array(
				'question_hash' => $prepared->question_hash,
				'plan_hash'     => $prepared->plan_hash,
				'initial_hash'  => $this->evidence_hash( $prepared->initial_result ),
				'delivered_hash' => $this->evidence_hash( $current ),
				'refreshed'     => ! hash_equals( $this->evidence_hash( $prepared->initial_result ), $this->evidence_hash( $current ) ),
			),
			'evidence_policy'   => (object) array( 'content_role' => 'untrusted_evidence', 'claims_from_model' => false, 'can_grant_tools' => false, 'can_grant_approval' => false ),
		);
		if ( null !== $native_actions ) {
			$output->native_actions = $native_actions;
		}
		// Facts remain exact. Omit an oversized display field or result instead of truncating its value.
		$limited = false;
		foreach ( $output->cards as $card ) {
			$card->facts = array_values(
				array_filter(
					$card->facts,
					static function ( $fact ) use ( &$limited ): bool {
						if ( strlen( wp_json_encode( $fact ) ) > 8192 ) {
							$limited = true;
							return false;
						} return true;
					}
				)
			);
		}
		if ( $limited ) {
			$output->assertions = $this->listing_assertions( $output->cards ); }
		while ( strlen( wp_json_encode( $output ) ) > 196608 && count( $output->cards ) > 1 ) {
			array_pop( $output->cards );
			$output->assertions = $this->listing_assertions( $output->cards );
			$limited = true; }
		if ( $limited ) {
			$output->limitations[] = 'display_results_limited';
			$output->message = sprintf( 'Showing %d current matching listings.', count( $output->cards ) ); }
		return $this->bounded_output( $output );
	}

	/** Preserve an explicit display boundary for large comparisons, documents or action manifests. */
	private function bounded_output( object $output ): object {
		if ( strlen( wp_json_encode( $output ) ) > 196608 ) {
			$output->status = 'unsupported';
			$output->message = 'These details are too long to display in chat. Open the listing to read the full information, or ask for a smaller set of details.';
			$output->cards = array();
			$output->comparison = null;
			if ( isset( $output->comparisons ) ) {
				$output->comparisons = array(); }
			$output->evidence = array();
			$output->citations = array();
			$output->assertions = array();
			unset( $output->native_actions );
			$output->limitations[] = 'response_too_large_for_chat';
		}
		return $this->gateway->validate_output( 'conversation.respond', $output );
	}

	/** Merge equally ordered directory pages before exposing positions to chat memory. */
	private function ordered_cards( array $cards, array $queries ): array {
		$order = $queries[0]->order ?? array();
		foreach ( $queries as $query ) {
			if ( wp_json_encode( $order ) !== wp_json_encode( $query->order ) ) {
				return array_slice( $cards, 0, 50 );
			}
		}
		if ( ! $order ) {
			return array_slice( $cards, 0, 50 );
		}
		$value = static function ( object $card, string $field ) {
			foreach ( $card->facts as $fact ) {
				if ( $field === $fact->field_id && 'known' === $fact->state && is_scalar( $fact->value ) ) {
					return $fact->value;
				}
			}
			return null;
		};
		usort(
			$cards,
			static function ( object $left, object $right ) use ( $order, $value ): int {
				foreach ( $order as $clause ) {
					$a = $value( $left, $clause->field );
					$b = $value( $right, $clause->field );
					if ( null === $a || null === $b ) {
						if ( $a !== $b ) {
							return null === $a ? 1 : -1;
						}
						continue;
					}
					$comparison = is_string( $a ) && is_string( $b ) ? strcmp( mb_strtolower( $a ), mb_strtolower( $b ) ) : ( $a <=> $b );
					if ( 0 !== $comparison ) {
						return 'desc' === $clause->direction ? -$comparison : $comparison;
					}
				}
				return $left->listing_id <=> $right->listing_id;
			}
		);
		// Each native query fetched this many candidates. A larger global page could skip unseen cheaper matches.
		$limit = min( array_map( static fn( object $query ): int => $query->limit, $queries ) );
		return array_slice( $cards, 0, min( 50, $limit ) );
	}

	/** Execute only one recognized typed plan. */
	private function execute( Context $context, object $plan ): object {
		if ( null === $this->gateway ) {
			throw new ContractException( 'unsupported_dependency', 'Grounded retrieval requires the shared gateway.' );
		}
		$operations = array( 'search' => 'listing.search', 'compare' => 'listing.compare', 'knowledge' => 'knowledge.search', 'action' => 'listing.actions' );
		if ( ! isset( $operations[ $plan->kind ] ) ) {
			throw new ContractException( 'invalid_input', 'Grounded response plan is unavailable.' );
		}
		if ( isset( $plan->payload->queries ) || isset( $plan->payload->comparisons ) ) {
			$results = array();
			$count = 0;
			$counted = isset( $plan->payload->queries );
			$queries = $plan->payload->queries ?? $plan->payload->comparisons;
			foreach ( $queries as $query ) {
				$current = $this->gateway->execute( $operations[ $plan->kind ], wp_json_encode( $query ), $context );
				$counted = $counted && true === ( $query->include_count ?? false ) && is_int( $current->count ?? null );
				$count += $current->count ?? 0;
				$results[] = (object) array( 'directory_id' => $query->directory_id, 'result' => $current );
			}
			$batch = (object) array( 'status' => 'batch', 'results' => $results, 'count' => $counted ? $count : null );
			if ( isset( $plan->payload->queries ) && ! empty( $queries[0]->order ) ) {
				// Old directory-by-directory positions cannot attest newly merged global positions.
				$batch->presentation_revision = 'global_order_v1';
			}
			if ( isset( $plan->payload->result_limit ) ) {
				$batch->presentation_revision = empty( $queries[0]->order ) ? 'global_result_limit_v1' : 'global_order_limit_v2';
			}
			return $batch;
		}
		$payload = clone $plan->payload;
		if ( 'knowledge' === $plan->kind ) {
			$payload->limit = 10; // Conflict detection must inspect the complete supported result window.
		}
		return $this->gateway->execute( $operations[ $plan->kind ], wp_json_encode( $payload ), $context );
	}

	/**
	 * Route global comparison IDs using current native ownership, never model scope.
	 *
	 * @param Context $context Current owned visitor worker.
	 * @param object  $input Validated planner envelope.
	 * @return object Planner envelope with freshly derived directory groups.
	 */
	private function global_comparison( Context $context, object $input ): object {
		if ( null === $this->db || ! in_array( $input->intent, array( 'compare', 'follow_up' ), true ) || null === $input->compare || ! empty( $input->comparisons ) || null !== $input->search || null !== $input->knowledge || ! empty( $input->searches ) || null !== ( $input->action ?? null ) ) {
			return $input;
		}
		$scope = \DirectoristAIPro\Chat\VisitorScope::current( $context, $this->db );
		if ( null === $scope || ! \DirectoristAIPro\Chat\VisitorScope::global( $scope ) ) {
			return $input;
		}
		$selection = \DirectoristAIPro\Chat\VisitorScope::selection( $context, $input->compare->listing_ids );
		$groups = array();
		foreach ( $selection->listings as $listing ) {
			$groups[ $listing->directory_id ][] = $listing->id;
		}
		$comparisons = array();
		foreach ( $groups as $directory => $ids ) {
			$comparison = clone $input->compare;
			$comparison->directory_id = $directory;
			$comparison->listing_ids = $ids;
			$comparisons[] = $comparison;
		}
		if ( count( $comparisons ) === 1 ) {
			$input->compare = $comparisons[0];
		} else {
			$input->compare = null;
			$input->comparisons = $comparisons;
		}
		return $input;
	}

	/** Convert exactly one planner payload into an authority-neutral plan. */
	private function resolved_plan( object $input ): ?object {
		$payloads = array_filter( array( 'search' => $input->search, 'compare' => $input->compare, 'knowledge' => $input->knowledge, 'action' => $input->action ?? null, 'searches' => $input->searches ?? null, 'comparisons' => $input->comparisons ?? null ), static fn( $value ): bool => null !== $value && array() !== $value );
		if ( ! $payloads ) {
			return null;
		}
		if ( 1 !== count( $payloads ) ) {
			throw new ContractException( 'invalid_input', 'Grounded response requires exactly one typed plan.' );
		}
		$kind    = array_key_first( $payloads );
		$payload = $payloads[ $kind ];
		if ( in_array( $kind, array( 'searches', 'comparisons' ), true ) ) {
			$ids = array();
			$selected = array();
			foreach ( $payload as $query ) {
				if ( isset( $ids[ $query->directory_id ] ) || ( 'searches' === $kind && 'public' !== $query->dataset ) ) {
					throw new ContractException( 'invalid_input', 'Use one public plan per directory.' ); }
				$ids[ $query->directory_id ] = true;
				$selected = array_merge( $selected, $query->listing_ids ?? array() );
			}
			if ( count( $selected ) > 4 || count( array_unique( $selected ) ) !== count( $selected ) ) {
				throw new ContractException( 'invalid_input', 'Compare up to four distinct listings.' ); }
			$combined = (object) array( 'searches' === $kind ? 'queries' : 'comparisons' => $payload );
			if ( 'searches' === $kind && isset( $input->result_limit ) ) {
				$combined->result_limit = $input->result_limit; }
			return (object) array( 'kind' => 'searches' === $kind ? 'search' : 'compare', 'payload' => $combined, 'family' => 'listing', 'directory_id' => null, 'dataset' => 'public' );
		}
		if ( ! $payload instanceof \stdClass ) {
			throw new ContractException( 'invalid_input', 'Grounded response plan is invalid.' );
		}
		if ( 'search' === $kind ) {
			if ( ! isset( $payload->directory_id, $payload->dataset ) || ! is_int( $payload->directory_id ) || ! is_string( $payload->dataset ) ) {
				throw new ContractException( 'invalid_input', 'Search plan scope is invalid.' );
			}
			if ( isset( $input->result_limit ) ) {
				$payload = clone $payload;
				$payload->limit = min( $payload->limit, $input->result_limit ); }
			return (object) array( 'kind' => $kind, 'payload' => $payload, 'family' => 'listing', 'directory_id' => $payload->directory_id, 'dataset' => $payload->dataset );
		}
		if ( in_array( $kind, array( 'compare', 'action' ), true ) ) {
			if ( ! isset( $payload->directory_id ) || ! is_int( $payload->directory_id ) ) {
				throw new ContractException( 'invalid_input', 'Comparison plan scope is invalid.' );
			}
			return (object) array( 'kind' => $kind, 'payload' => $payload, 'family' => 'listing', 'directory_id' => $payload->directory_id, 'dataset' => 'public' );
		}
		if ( ! property_exists( $payload, 'directory_id' ) || ! property_exists( $payload, 'query' ) || ( null !== $payload->directory_id && ! is_int( $payload->directory_id ) ) ) {
			throw new ContractException( 'invalid_input', 'Knowledge plan scope is invalid.' );
		}
		return (object) array( 'kind' => $kind, 'payload' => $payload, 'family' => 'knowledge', 'directory_id' => $payload->directory_id, 'dataset' => null );
	}

	/** Enumerate every directory that needs a fresh public scope check. */
	private function plan_directories( object $plan ): array {
		$queries = $plan->payload->queries ?? $plan->payload->comparisons ?? null;
		return null === $queries ? array( $plan->directory_id ) : array_map( static fn( object $query ): int => $query->directory_id, $queries );
	}

	/** Follow-ups may refine a task but cannot silently switch its source scope. */
	private function assert_follow_up_scope( object $prior, object $plan ): void {
		if ( $prior->family !== $plan->family || $prior->directory_id !== $plan->directory_id || $prior->dataset !== $plan->dataset ) {
			throw new ContractException( 'scope_conflict', 'Follow-up plan changed its bound task scope.' );
		}
	}

	/** Validate the deterministic planner envelope without coercion. */
	private function validate_input( object $input ): void {
		$required = array( 'question', 'resolution', 'intent', 'context_token', 'search', 'compare', 'knowledge' );
		$allowed = array_merge( $required, array( 'action', 'searches', 'comparisons', 'result_limit' ) );
		$values   = get_object_vars( $input );
		if ( array_diff( $required, array_keys( $values ) ) || array_diff( array_keys( $values ), $allowed ) || ! is_string( $input->question ) || '' === trim( $input->question ) || strlen( $input->question ) > 2000 || ! in_array( $input->resolution, array( 'resolved', 'ambiguous', 'unsupported' ), true ) || ! in_array( $input->intent, array( 'search', 'compare', 'knowledge', 'action', 'follow_up' ), true ) || ( null !== $input->context_token && ! is_string( $input->context_token ) ) ) {
			throw new ContractException( 'invalid_input', 'Grounded planner output is invalid.' );
		}
		if ( isset( $input->result_limit ) && ( ! is_int( $input->result_limit ) || $input->result_limit < 1 || $input->result_limit > 20 || ! in_array( $input->intent, array( 'search', 'follow_up' ), true ) ) ) {
			throw new ContractException( 'invalid_input', 'A total result limit requires a public listing search.' );
		}
		if ( 'follow_up' === $input->intent ) {
			if ( null === $input->context_token || 'unsupported' === $input->resolution ) {
				throw new ContractException( 'invalid_input', 'Follow-up requires signed task context.' );
			}
			return;
		}
		if ( null !== $input->context_token || 'ambiguous' === $input->resolution || ( 'resolved' === $input->resolution && null === $input->{$input->intent} && ! ( 'search' === $input->intent && isset( $input->searches ) ) && ! ( 'compare' === $input->intent && isset( $input->comparisons ) ) ) ) {
			throw new ContractException( 'invalid_input', 'Initial grounded task is incomplete.' );
		}
	}

	/** Build one prepared boundary with canonical trace hashes. */
	private function prepared( string $question, object $plan, object $result, string $scope ): PreparedGroundedResponse {
		return new PreparedGroundedResponse( $plan, $result, $scope, get_current_blog_id(), $this->hash( $plan ), hash( 'sha256', $question ) );
	}

	/** Issue structural context only; facts and citations remain outside the token. */
	private function follow_up_token( string $scope, object $plan ): string {
		return $this->tokens->encode(
			array(
				'v'            => 1,
				'scope'        => $scope,
				'site'         => get_current_blog_id(),
				'family'       => $plan->family,
				'directory_id' => $plan->directory_id,
				'dataset'      => $plan->dataset,
				'plan'         => $plan,
				'expires'      => time() + 1800,
			)
		);
	}

	/** Bind context to the actual owner/profile and durable conversation when present. */
	private function scope_hash( Context $context ): string {
		return hash( 'sha256', $context->actor()->owner()->reference() . '|' . $context->profile() . '|' . ( $context->conversation_id() ?? '-' ) );
	}

	/** Convert current listing facts to renderer-neutral evidence cards. */
	private function card( int $directory_id, object $listing ): object {
		$facts = array_map(
			static fn( object $fact ): object => (object) array(
				'field_id' => $fact->field_id,
				'state'    => $fact->known ? 'known' : 'unknown',
				'value'    => $fact->known ? $fact->value : null,
				'source'   => $fact->source,
			),
			$listing->fields
		);
		return (object) array( 'listing_id' => $listing->id, 'directory_id' => $directory_id, 'version' => $listing->version->content_hash, 'facts' => $facts );
	}

	/** Derive assertions exclusively from known current card facts. */
	private function listing_assertions( array $cards ): array {
		$result = array();
		foreach ( $cards as $card ) {
			foreach ( $card->facts as $fact ) {
				if ( 'known' === $fact->state ) {
					$result[] = (object) array( 'subject' => 'listing:' . $card->listing_id, 'predicate' => $fact->field_id, 'value' => $fact->value, 'source' => $fact->source, 'version' => $card->version );
				}
			}
		}
		return $result;
	}

	/** Stable comparison for facts, excluding observation times and renewed output cursors. */
	private function evidence_hash( object $result ): string {
		$scrub = function ( $item ) use ( &$scrub ) {
			if ( is_object( $item ) ) {
				$values = get_object_vars( $item );
				// A search mints its next-page cursor with a fresh expiry on every
				// read. The cursor is not shown as a fact; selected input cursors,
				// cards and counts remain bound by the plan/result comparison.
				unset( $values['observed_at'], $values['next_cursor'] );
				foreach ( $values as &$child ) {
					$child = $scrub( $child );
				}
				return (object) $values;
			}
			return is_array( $item ) ? array_map( $scrub, $item ) : $item;
		};
		return $this->hash( $scrub( $result ) );
	}

	/** Convert bounded query/knowledge limitations into an explicit non-success response. */
	private function unsupported_error( ContractException $error ): bool {
		return in_array( $error->error_code(), array( 'invalid_query', 'stale_catalog', 'query_too_broad', 'invalid_cursor', 'needs_expansion' ), true );
	}

	/** RFC-8785-like ordering sufficient for internal trace equality. */
	private function hash( $value ): string {
		$canonicalize = function ( $item ) use ( &$canonicalize ) {
			if ( is_object( $item ) ) {
				$values = get_object_vars( $item );
				ksort( $values, SORT_STRING );
				foreach ( $values as &$child ) {
					$child = $canonicalize( $child );
				}
				return (object) $values;
			}
			if ( is_array( $item ) ) {
				return array_map( $canonicalize, $item );
			}
			return $item;
		};
		return hash( 'sha256', wp_json_encode( $canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
