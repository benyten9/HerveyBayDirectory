<?php
/**
 * Rehydrate server-produced grounded run results for owned event delivery.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

use DirectoristAIPro\Capabilities\Actor;
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Runtime\RunResultResolver;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\Owner;
use DirectoristAIPro\Validation\ContractException;

/** Stored plans are selectors; transcripts require owned seals and current public sources. */
final class GroundedRunResult implements RunResultResolver {
	/**
	 * Site runtime storage.
	 *
	 * @var Database
	 */
	private Database $db;
	/**
	 * Shared strict execution boundary.
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;

	/**
	 * Bind the same site storage and gateway as owned event polling.
	 *
	 * @param Database $db Runtime storage.
	 * @param Gateway  $gateway Shared strict gateway.
	 */
	public function __construct( Database $db, Gateway $gateway ) {
		$this->db      = $db;
		$this->gateway = $gateway;
	}

	/**
	 * Recheck signed LLM helper prose against the current native inventory or receipt.
	 *
	 * @param array   $run Owned run and conversation identity.
	 * @param Context $context Current reader.
	 * @param object  $content Attested helper message.
	 * @return bool Whether the facts still match.
	 */
	public function helper_current( array $run, Context $context, object $content ): bool {
		try {
			if ( $run['owner_ref'] !== $context->actor()->owner()->reference() ) {
				return false;
			}
			$checkpoint = json_decode( $run['checkpoint_json'] );
			$version    = $checkpoint->visitor_reply->helper_evidence_version ?? null;
			if ( null !== $version && \DirectoristAIPro\Chat\VisitorHelp::EVIDENCE_VERSION !== $version ) {
				return false;
			}

			$worker = new Context( $context->actor(), 'visitor', 'worker', $run['conversation_public_id'], $run['public_id'] );
			$fresh  = \DirectoristAIPro\Chat\VisitorHelp::answer( $content->provenance->topic, $worker, $checkpoint->target_context, $this->gateway, $this->db );
			if ( \DirectoristAIPro\Orchestration\VisitorReply::helper_current( $run, $content->text, $fresh ) ) {
				return true;
			}
			// Only historical unversioned count helpers may use the exact old native formatter.
			if ( null !== $version || ! in_array( $content->provenance->topic, array( 'directories', 'listing_count' ), true ) || ! \DirectoristAIPro\Chat\VisitorHelp::legacy_inventory_current( $run, $checkpoint->target_context ) ) {
				return false;
			}
			$legacy = \DirectoristAIPro\Chat\VisitorHelp::answer( $content->provenance->topic, $worker, $checkpoint->target_context, $this->gateway, $this->db, 1 );
			return \DirectoristAIPro\Orchestration\VisitorReply::helper_current( $run, $content->text, $legacy );
		} catch ( ContractException $error ) {
			return false;
		}
	}

	/**
	 * Return current or dated producer-attested history after current public-source checks.
	 *
	 * @param Context $reader Current verified history reader.
	 * @param array   $run Exact owned stored task.
	 * @param array   $message Exact sealed assistant row.
	 * @param object  $content Original decoded sealed content.
	 * @return object|null Current or qualified dated display, otherwise withheld.
	 */
	public function history( Context $reader, array $run, array $message, object $content ): ?object {
		// A transcript is the answer recorded for that turn, not a new directory search.
		// Only source-certified static listing replies qualify. New requests and native
		// actions still execute their full current gateway checks independently.
		if ( self::static_listing_reply( $content->grounded ?? null ) && self::static_listing_plan( $content->grounded ) ) {
			$certified = $this->static_listing_history( $reader, $run, $message, $content );
			if ( null !== $certified ) {
				return $certified;
			}
			// Unsupported/dynamic shapes retain the existing complete replay path.
		}
		// An owned transcript records the earlier turn. A changed catalog is not
		// itself a revocation of its displayed public facts. This separate view
		// never renews the old query, cursor or action authority.
		if ( self::static_listing_reply( $content->grounded ?? null, true ) ) {
			$archived = $this->static_listing_history( $reader, $run, $message, $content, true );
			if ( null !== $archived ) {
				return $archived;
			}
		}
		$current = $this->resolve( $reader->actor()->owner(), $run['public_id'], $content->grounded->trace );
		if ( 'available' === $current->status ) {
			return (object) array(
				'text'     => $current->response->message,
				'grounded' => $current->response,
			);
		}
		try {
			if ( 'visitor' !== $reader->profile() || $run['owner_ref'] !== $reader->actor()->owner()->reference() || ! GroundedHistoryClock::verifies( $run, (int) $message['sequence'], $content ) ) {
				return null;
			}
			// API final hydration supplies only stable message fields; recover its exact owned row.
			$stored = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . ' WHERE conversation_id=%d AND sequence=%d AND turn_id=%d AND role=%s', array( (int) $run['conversation_id'], (int) $message['sequence'], (int) $run['trigger_turn_id'], 'assistant' ) );
			if ( 1 !== count( $stored ) ) {
				return null;
			}
			foreach ( array( 'sequence', 'turn_id', 'role', 'content_json', 'source_refs_json' ) as $key ) {
				if ( ! array_key_exists( $key, $message ) || $stored[0][ $key ] !== $message[ $key ] ) {
					return null;
				}
			}
			$message    = $stored[0];
			$checkpoint = json_decode( $run['checkpoint_json'] );
			$context    = new Context( $reader->actor(), $reader->profile(), 'worker', $run['conversation_public_id'], $run['public_id'] );
			$service    = ( new GroundedResponseModule( $this->db, new KnowledgeService( new KnowledgeStore( $this->db ) ) ) )->service();
			$service->bind_gateway( $this->gateway );
			$static = null;
			if ( 'compare' === ( $checkpoint->grounded_input->intent ?? null ) && self::static_comparison( $content->grounded ) ) {
				// Native listing versions include unrequested opening-time calculations.
				// Preserve the original certificate readers: their source is part of old seals.
				if ( ! ( new GroundedMessageProvenance( $this->gateway ) )->verifies( $run, (int) $message['sequence'], $content, $checkpoint ) || ! GroundedHistoryClock::sources_current( $this->gateway, $context, $content ) ) {
					return null;
				}
				$fresh = $service->respond( $context, $checkpoint->grounded_input );
				if ( ! self::same_static_comparison( $content->grounded, $fresh ) ) {
					return null;
				}
				$fresh->message = $content->text;
				if ( isset( $content->grounded->reply_origin ) ) {
					$fresh->reply_origin = $content->grounded->reply_origin;
				}
				$this->gateway->validate_output( 'conversation.respond', $fresh );
				$static = (object) array(
					'text'     => $content->text,
					'grounded' => $fresh,
				);
			} else {
				$service->replay_clock_history( $context, $checkpoint->grounded_input, $run, $message );
			}
			$fresh_run     = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.public_id=%s AND c.profile=%s AND c.erasure_requested=0', array( (int) $run['id'], $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
			$fresh_message = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . ' WHERE id=%d', array( (int) $message['id'] ) );
			$reader->actor()->refresh();
			$reader->assert_execution_identity();
			if ( 1 !== count( $fresh_run ) || $fresh_run[0] !== $run || 1 !== count( $fresh_message ) || $fresh_message[0] !== $message || ! GroundedHistoryClock::sources_current( $this->gateway, $context, $content ) ) {
				return null;
			}
			if ( null !== $static ) {
				return $static;
			}
			// The sealed response is cloned intact; this dated display note is outside its proof.
			return (object) array(
				'text'          => $content->text,
				'grounded'      => json_decode( wp_json_encode( $content->grounded ) ),
				'history_clock' => (object) array(
					'as_of'          => $content->provenance->history_clock->observed_at,
					'current_status' => 'changed',
				),
			);
		} catch ( ContractException $error ) {
			return null;
		}
	}

	/** Static listing transcripts may be checked by their bounded returned sources. */
	private static function static_listing_reply( $response, bool $include_clock = false ): bool {
		if ( ! $response instanceof \stdClass || 'directorist-ai/grounded-response-v1' !== ( $response->contract ?? null ) || 'results' !== ( $response->status ?? null ) || 'listing' !== ( $response->task->family ?? null ) || 'public' !== ( $response->task->dataset ?? null ) || ! in_array( $response->task->kind ?? null, array( 'search', 'compare' ), true ) || ! empty( $response->evidence ) || ! empty( $response->citations ) || isset( $response->native_actions ) ) {
			return false;
		}
		$fields = array();
		$found  = false;
		foreach ( $response->cards ?? array() as $card ) {
			$found = true;
			foreach ( $card->facts ?? array() as $fact ) {
				$fields[] = $fact->field_id ?? null;
			}
		}
		$sets = $response->comparisons ?? array();
		if ( isset( $response->comparison ) ) {
			$sets[] = $response->comparison;
		}
		foreach ( $sets as $set ) {
			$found = $found || ! empty( $set->listing_ids );
			foreach ( $set->rows ?? array() as $row ) {
				$fields[] = $row->field_id ?? null;
			}
		}
		foreach ( $response->assertions ?? array() as $assertion ) {
			$fields[] = $assertion->predicate ?? null;
		}
		return $found && ( $include_clock || ! array_intersect( array( 'hours', 'open_now' ), $fields ) );
	}

	/** A static card can still belong to a clock-sensitive search or refinement. */
	private static function static_listing_plan( object $response ): bool {
		try {
			if ( ! is_string( $response->context_token ?? null ) ) {
				return false;
			}
			$payload = ( new GroundedContextToken( wp_salt( 'auth' ) ) )->decode_for_replay( $response->context_token );
			$clock = static function ( $value ) use ( &$clock ): bool {
				if ( is_string( $value ) ) {
					return in_array( $value, array( 'hours', 'open_now' ), true );
				}
				if ( is_array( $value ) || $value instanceof \stdClass ) {
					foreach ( $value as $child ) {
						if ( $clock( $child ) ) {
							return true;
						}
					}
				}
				return false;
			};
			return in_array( $payload->plan->kind ?? null, array( 'search', 'compare' ), true ) && ! $clock( $payload->plan );
		} catch ( ContractException $error ) {
			return false;
		}
	}

	/**
	 * Verify the exact immutable message and freshly public native sources without
	 * executing its old search again. Counts and ordering remain observations of
	 * the recorded turn. This does not supply evidence or permission to a new turn.
	 */
	private function static_listing_history( Context $reader, array $run, array $message, object $content, bool $archive = false ): ?object {
		$previous_actor = get_current_user_id();
		try {
			$reader->actor()->refresh();
			$reader->assert_current();
			self::set_history_actor( $reader->actor()->user_id() );
			$reader->assert_execution_identity();
			if ( 'visitor' !== $reader->profile() || 'visitor' !== ( $run['profile'] ?? null ) || 'succeeded' !== ( $run['state'] ?? null ) || $run['owner_ref'] !== $reader->actor()->owner()->reference() ) {
				return null;
			}
			$stored = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . ' WHERE conversation_id=%d AND sequence=%d AND turn_id=%d AND role=%s', array( (int) $run['conversation_id'], (int) $message['sequence'], (int) $run['trigger_turn_id'], 'assistant' ) );
			if ( 1 !== count( $stored ) ) {
				return null;
			}
			foreach ( array( 'sequence', 'turn_id', 'role', 'content_json', 'source_refs_json' ) as $key ) {
				if ( ! array_key_exists( $key, $message ) || $stored[0][ $key ] !== $message[ $key ] ) {
					return null;
				}
			}
			$message    = $stored[0];
			$checkpoint = json_decode( $run['checkpoint_json'] );
			if ( ! ( new GroundedMessageProvenance( $this->gateway ) )->verifies( $run, (int) $message['sequence'], $content, $checkpoint ) ) {
				return null;
			}
			$context = new Context( $reader->actor(), 'visitor', 'worker', $run['conversation_public_id'], $run['public_id'] );
			$payload = ( new GroundedContextToken( wp_salt( 'auth' ) ) )->decode_for_replay( $content->grounded->context_token );
			$expected_scope = hash( 'sha256', $context->actor()->owner()->reference() . '|visitor|' . $context->conversation_id() );
			if ( get_current_blog_id() !== $payload->site || 'listing' !== $payload->family || 'public' !== $payload->dataset || ! hash_equals( $expected_scope, $payload->scope ) || ( $payload->plan->kind ?? null ) !== $content->grounded->task->kind ) {
				return null;
			}
			$refs = self::static_listing_references( $content->grounded );
			$catalogs = self::static_listing_catalogs( $content->grounded, $payload );
			// Existing certificates include native reader files. After an upgrade,
			// an exact whole-public version can independently attest the old display
			// without re-running the directory search. V1 messages use this same
			// strict path. Only a still-current raw certificate permits clock drift
			// in unrequested fields; displayed facts always match current public facts.
			$raw_current = false;
			if ( GroundedHistoryClock::verifies( $run, (int) $message['sequence'], $content ) ) {
				try { $raw_current = GroundedHistoryClock::sources_current( $this->gateway, $context, $content ); } catch ( ContractException $ignored ) { /* Use exact public versions, never a partial raw certificate. */ }
			}
			$observed = null;
			for ( $pass = 0; $pass < 2; ++$pass ) {
				$fingerprint = $this->verify_static_listing_facts( $context, $content->grounded, $refs, $catalogs, ! $raw_current && ! $archive, $archive );
				if ( null !== $observed && ! hash_equals( $observed, $fingerprint ) ) {
					throw new ContractException( 'stale_evidence', 'Historical public sources changed during restoration.' );
				}
				$observed = $fingerprint;
			}
			$fresh_run = $this->db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $this->db->table( 'runs' ) . ' r JOIN ' . $this->db->table( 'conversations' ) . ' c ON c.id=r.conversation_id WHERE r.id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.public_id=%s AND c.profile=%s AND c.erasure_requested=0', array( (int) $run['id'], $context->actor()->owner()->reference(), $context->conversation_id(), $context->profile() ) );
			$fresh_message = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'messages' ) . ' WHERE id=%d', array( (int) $message['id'] ) );
			$reader->actor()->refresh();
			$reader->assert_execution_identity();
			if ( 1 !== count( $fresh_run ) || $fresh_run[0] !== $run || 1 !== count( $fresh_message ) || $fresh_message[0] !== $message || ( $raw_current && ! GroundedHistoryClock::sources_current( $this->gateway, $context, $content ) ) ) {
				return null;
			}
			$response = json_decode( wp_json_encode( $content->grounded ) );
			if ( $archive ) {
				// New requests rebuild current filters and facts from public identity
				// memory. A dated transcript cannot resurrect an obsolete plan.
				$response->context_token = null;
				$this->gateway->validate_output( 'conversation.respond', $response );
				$recorded = strtotime( ( $message['created_at'] ?? $run['created_at'] ) . ' UTC' );
				if ( false === $recorded ) { return null; }
				return (object) array(
					'text'          => $content->text,
					'grounded'      => $response,
					'history_clock' => (object) array( 'as_of' => gmdate( 'Y-m-d\TH:i:s\Z', $recorded ), 'current_status' => 'changed' ),
				);
			}
			if ( is_string( $response->context_token ?? null ) ) {
				$tokens  = new GroundedContextToken( wp_salt( 'auth' ) );
				$payload = $tokens->decode_for_replay( $response->context_token );
				$expected_scope = hash( 'sha256', $context->actor()->owner()->reference() . '|visitor|' . $context->conversation_id() );
				if ( get_current_blog_id() !== $payload->site || 'listing' !== $payload->family || 'public' !== $payload->dataset || ! hash_equals( $expected_scope, $payload->scope ) ) {
					return null;
				}
				// Renew only exact sealed structural cursors. Native query execution
				// still rechecks catalog, predicates, actor and directory on the next turn.
				if ( 'search' === $payload->plan->kind ) {
					$searches = $payload->plan->payload->queries ?? array( $payload->plan->payload );
					$cursors = array();
					foreach ( $searches as $search ) {
						if ( is_string( $search->cursor ?? null ) ) {
							$cursors[] = $search->cursor;
						}
					}
					\DirectoristAIPro\Domain\ListingQueryCursor::with_replay_tokens( $cursors, static function () use ( $searches ): void {
						$decoder = new \DirectoristAIPro\Domain\ListingQueryCursor( wp_salt( 'auth' ) );
						foreach ( $searches as $search ) {
							if ( is_string( $search->cursor ?? null ) ) {
								$decoder->decode( $search->cursor );
								$search->cursor = \DirectoristAIPro\Domain\ListingQueryCursor::renewed_replay_token( $search->cursor );
							}
						}
					} );
				}
				$payload->expires = time() + 1800;
				$response->context_token = $tokens->encode( (array) $payload );
			}
			$this->gateway->validate_output( 'conversation.respond', $response );
			return (object) array( 'text' => $content->text, 'grounded' => $response );
		} catch ( ContractException $error ) {
			return null;
		} finally {
			self::set_history_actor( $previous_actor );
		}
	}

	/** Native public readers see the initiating actor, including guest workers. */
	private static function set_history_actor( int $user_id ): void {
		if ( $user_id ) { clean_user_cache( $user_id ); }
		unset( $GLOBALS['current_user'] );
		wp_set_current_user( $user_id );
	}

	/** Original whole-public versions must agree for every displayed listing. */
	private static function static_listing_references( object $response ): array {
		$refs = array(); $owners = array();
		$add = static function ( int $directory, int $id, string $version ) use ( &$refs, &$owners ): void {
			if ( $directory < 1 || $id < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) || ( isset( $owners[ $id ] ) && $owners[ $id ] !== $directory ) || ( isset( $refs[ $directory ][ $id ] ) && ! hash_equals( $refs[ $directory ][ $id ], $version ) ) ) { throw new ContractException( 'stale_evidence', 'Historical listing versions disagree.' ); }
			$owners[ $id ] = $directory; $refs[ $directory ][ $id ] = $version;
		};
		foreach ( $response->cards ?? array() as $card ) { $add( $card->directory_id, $card->listing_id, $card->version ); }
		$sets = $response->comparisons ?? array(); if ( isset( $response->comparison ) ) { $sets[] = $response->comparison; }
		foreach ( $sets as $set ) {
			foreach ( $set->listing_ids as $id ) {
				$found = false;
				foreach ( $set->rows as $row ) { foreach ( $row->cells as $cell ) { if ( $cell->listing_id === $id ) { $add( $set->directory_id, $id, $cell->version ); $found = true; } } }
				if ( ! $found ) { throw new ContractException( 'stale_evidence', 'Historical comparison identity is unavailable.' ); }
			}
		}
		// A global result may have one listing per directory. Bound native work
		// by the existing twenty-listing total, not the comparison group count.
		if ( ! $refs || array_sum( array_map( 'count', $refs ) ) > 20 ) { throw new ContractException( 'stale_evidence', 'Historical listing observation is bounded.' ); }
		return $refs;
	}

	/** Read only original producer-signed search catalogs or native comparison catalogs. */
	private static function static_listing_catalogs( object $response, object $payload ): array {
		$catalogs = array();
		$bind = static function ( $directory, $revision ) use ( &$catalogs ): void {
			if ( ! is_int( $directory ) || $directory < 1 || ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) || ( isset( $catalogs[ $directory ] ) && ! hash_equals( $catalogs[ $directory ], $revision ) ) ) { throw new ContractException( 'stale_evidence', 'Historical public catalog is unavailable.' ); }
			$catalogs[ $directory ] = $revision;
		};
		if ( 'search' === $response->task->kind ) {
			if ( ! ( $payload->plan->payload ?? null ) instanceof \stdClass ) { throw new ContractException( 'stale_evidence', 'Historical search plan is unavailable.' ); }
			$queries = $payload->plan->payload->queries ?? array( $payload->plan->payload );
			if ( ! is_array( $queries ) || ! $queries || count( $queries ) > 100 ) { throw new ContractException( 'stale_evidence', 'Historical search scope is unavailable.' ); }
			foreach ( $queries as $query ) {
				if ( ! $query instanceof \stdClass || 'public' !== ( $query->dataset ?? null ) ) { throw new ContractException( 'stale_evidence', 'Historical search dataset is unavailable.' ); }
				$bind( $query->directory_id ?? null, $query->catalog_revision ?? null );
			}
		}
		$sets = $response->comparisons ?? array(); if ( isset( $response->comparison ) ) { $sets[] = $response->comparison; }
		foreach ( $sets as $set ) { $bind( $set->directory_id, $set->catalog_revision ); }
		return $catalogs;
	}

	/** Two fresh, bounded native observations validate every displayed fact and source. */
	private function verify_static_listing_facts( Context $context, object $response, array $refs, array $catalogs, bool $require_versions, bool $archive = false ): string {
		$inspector = new \DirectoristAIPro\Domain\DirectoristInspector(); $native = array(); $observed = array();
		foreach ( $refs as $directory => $versions ) {
			\DirectoristAIPro\Chat\ListingActions::assert_scope( $context, $directory, $this->db );
			$before = $this->gateway->execute( 'listing.catalog', wp_json_encode( array( 'directory_id' => $directory, 'dataset' => 'public' ) ), $context );
			if ( ! isset( $catalogs[ $directory ] ) || ( ! $archive && ! hash_equals( $catalogs[ $directory ], $before->revision ) ) ) { throw new ContractException( 'stale_evidence', 'Historical public catalog changed.' ); }
			$facts = $inspector->query_facts( $context, $directory, 'public', array_keys( $versions ) );
			$descriptors = null;
			if ( $archive ) {
				$description = $inspector->describe( $context, $directory, 'public_search' );
				if ( ! $facts->complete || ! hash_equals( $before->schema_hash, $description->schema_hash ) ) { throw new ContractException( 'stale_evidence', 'Historical public permissions could not be confirmed.' ); }
				$descriptors = array_column( $description->fields, null, 'field_id' );
			}
			$after = $this->gateway->execute( 'listing.catalog', wp_json_encode( array( 'directory_id' => $directory, 'dataset' => 'public' ) ), $context );
			if ( ! hash_equals( $before->revision, $after->revision ) || ! hash_equals( $before->schema_hash, $facts->schema_hash ) || array_keys( $versions ) !== array_column( $facts->listings, 'id' ) ) { throw new ContractException( 'stale_evidence', 'Historical public source changed during observation.' ); }
			$observed[ $directory ] = array( 'catalog' => $before->revision, 'listings' => array() );
			foreach ( $facts->listings as $listing ) {
				if ( $archive && ! $listing->complete ) { throw new ContractException( 'stale_evidence', 'Historical public plan permissions could not be confirmed.' ); }
				if ( $require_versions && ! hash_equals( $versions[ $listing->id ], $listing->version->content_hash ) ) { throw new ContractException( 'stale_evidence', 'Historical public facts changed.' ); }
				$native[ $listing->id ] = array( 'directory' => $directory, 'version' => $versions[ $listing->id ], 'fields' => array_column( $listing->fields, null, 'field_id' ), 'descriptors' => $descriptors );
				$observed[ $directory ]['listings'][ $listing->id ] = $listing->version->content_hash;
			}
		}
		foreach ( $response->cards ?? array() as $card ) { foreach ( $card->facts as $fact ) { self::verify_static_fact( $native, $card->listing_id, $fact->field_id, $fact->state, $fact->value, $fact->source, $archive ); } }
		$sets = $response->comparisons ?? array(); if ( isset( $response->comparison ) ) { $sets[] = $response->comparison; }
		foreach ( $sets as $set ) { foreach ( $set->rows as $row ) { foreach ( $row->cells as $cell ) {
			if ( ! in_array( $cell->listing_id, $set->listing_ids, true ) || ( $native[ $cell->listing_id ]['directory'] ?? null ) !== $set->directory_id ) { throw new ContractException( 'stale_evidence', 'Historical comparison ownership changed.' ); }
			self::verify_static_fact( $native, $cell->listing_id, $row->field_id, $cell->state, $cell->value, $cell->source, $archive );
		} } }
		foreach ( $response->assertions ?? array() as $assertion ) {
			if ( ! preg_match( '/^listing:([1-9][0-9]*)$/D', $assertion->subject, $match ) || ! isset( $native[ (int) $match[1] ] ) || ! hash_equals( $native[ (int) $match[1] ]['version'], $assertion->version ) ) { throw new ContractException( 'stale_evidence', 'Historical assertion ownership changed.' ); }
			self::verify_static_fact( $native, (int) $match[1], $assertion->predicate, 'known', $assertion->value, $assertion->source, $archive );
		}
		return ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->digest( $observed );
	}

	/** The current full public native map, not just queryable fields, permits display. */
	private static function verify_static_fact( array $native, int $id, string $field, string $state, $value, $source, bool $archive = false ): void {
		$fact = $native[ $id ]['fields'][ $field ] ?? null;
		if ( null === $fact && 'distance' === $field && 'unknown' === $state && null === $value && $source instanceof \stdClass && 'meta' === ( $source->kind ?? null ) && (string) $id === ( $source->object_id ?? null ) && 'distance' === ( $source->key ?? null ) ) { return; }
		if ( null === $fact || ( $fact->known ? 'known' : 'unknown' ) !== $state ) { throw new ContractException( 'stale_evidence', 'Historical field is no longer publicly readable.' ); }
		if ( $archive && isset( $native[ $id ]['descriptors'] ) ) {
			$descriptor = $native[ $id ]['descriptors'][ $field ] ?? null;
			if ( null === $descriptor || ! empty( $descriptor->admin_only ) || null !== ( $descriptor->conditional_logic ?? null ) ) { throw new ContractException( 'stale_evidence', 'Historical conditional field visibility could not be confirmed.' ); }
		}
		$canonical = new \DirectoristAIPro\Validation\JsonCanonicalizer();
		$old_source = $source instanceof \stdClass ? clone $source : $source; $current_source = $fact->source instanceof \stdClass ? clone $fact->source : $fact->source;
		if ( $old_source instanceof \stdClass ) { unset( $old_source->observed_at ); } if ( $current_source instanceof \stdClass ) { unset( $current_source->observed_at ); }
		if ( $canonical->encode( $old_source ) !== $canonical->encode( $current_source ) ) { throw new ContractException( 'stale_evidence', 'Historical field value or source changed.' ); }
		$current_value = $fact->known ? $fact->value : null;
		if ( $canonical->encode( $current_value ) === $canonical->encode( $value ) ) { return; }
		if ( 'pricing' === $field && is_object( $value ) && is_object( $current_value ) && in_array( $value->type ?? null, array( 'price', 'range' ), true )
			&& property_exists( $value, $value->type ) && property_exists( $current_value, $value->type )
			&& $canonical->encode( \DirectoristAIPro\Chat\Workflows\PublicListingFacts::active_pricing( $value ) ) === $canonical->encode( \DirectoristAIPro\Chat\Workflows\PublicListingFacts::active_pricing( $current_value ) ) ) {
			// Legacy public objects also carried inactive form state. Only the
			// identical active native mode/value qualifies for this compatibility.
			return;
		}
		if ( $archive && self::compatible_historical_value( $field, $value, $current_value ) ) { return; }
		throw new ContractException( 'stale_evidence', 'Historical field value or source changed.' );
	}

	/** Only explicit presentation compatibility and dated price/hour observations. */
	private static function compatible_historical_value( string $field, $old, $current ): bool {
		if ( 'images' === $field && $old instanceof \stdClass && $current instanceof \stdClass
			&& array( 'preview_id', 'gallery_ids' ) === array_keys( get_object_vars( $old ) )
			&& array( 'preview_id', 'gallery_ids', 'preview' ) === array_keys( get_object_vars( $current ) )
			&& $old->preview_id === $current->preview_id && $old->gallery_ids === $current->gallery_ids ) {
			// The legacy shape had no preview object. Its exact media identities
			// must remain unchanged and any nonempty preview must still be public.
			return 0 === $old->preview_id ? null === $current->preview : is_object( $current->preview ) && $current->preview->id === $old->preview_id;
		}
		if ( in_array( $field, array( 'price', 'pricing', 'hours', 'open_now' ), true ) ) {
			// Both values were typed by the native public producer and their exact
			// source and known-state match above. They are displayed only as dated
			// observations, never supplied to a new request or native action.
			return null !== $old && null !== $current && gettype( $old ) === gettype( $current );
		}
		return false;
	}

	/**
	 * Static public comparison facts have no displayed opening-time exception.
	 *
	 * @param object $response Validated native response.
	 */
	private static function static_comparison( object $response ): bool {
		if ( 'directorist-ai/grounded-response-v1' !== ( $response->contract ?? null ) || 'results' !== ( $response->status ?? null ) || 'compare' !== ( $response->task->kind ?? null ) || 'listing' !== ( $response->task->family ?? null ) || 'public' !== ( $response->task->dataset ?? null ) || ! empty( $response->cards ) || ! empty( $response->evidence ) || ! empty( $response->citations ) || isset( $response->native_actions ) ) {
			return false;
		}
		$sets = $response->comparisons ?? array();
		if ( isset( $response->comparison ) ) {
			$sets[] = $response->comparison;
		}
		if ( ! $sets ) {
			return false;
		}
		foreach ( $sets as $set ) {
			foreach ( $set->rows as $row ) {
				if ( in_array( $row->field_id, array( 'hours', 'open_now' ), true ) ) {
					return false;
				}
			}
		}
		foreach ( $response->assertions as $assertion ) {
			if ( in_array( $assertion->predicate, array( 'hours', 'open_now' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Exact displayed facts, schema, ordering and states; only observation metadata differs.
	 *
	 * @param object $old Producer-attested response.
	 * @param object $fresh Fresh native response.
	 */
	private static function same_static_comparison( object $old, object $fresh ): bool {
		if ( ! self::static_comparison( $old ) || ! self::static_comparison( $fresh ) ) {
			return false;
		}
		$projection = static function ( object $response ): string {
			$value = json_decode( wp_json_encode( $response ) );
			unset( $value->message, $value->context_token, $value->trace, $value->reply_origin );
			$sets = $value->comparisons ?? array();
			if ( isset( $value->comparison ) ) {
				$sets[] = $value->comparison;
			}
			$observed = static function ( object $fact ): void {
				$fact->version = 'current-source-certified';
				if ( ( $fact->source ?? null ) instanceof \stdClass ) {
					unset( $fact->source->observed_at );
				}
			};
			foreach ( $sets as $set ) {
				foreach ( $set->rows as $row ) {
					foreach ( $row->cells as $cell ) {
						$observed( $cell );
					}
				}
			}
			foreach ( $value->assertions as $assertion ) {
				$observed( $assertion );
			}
			return ( new \DirectoristAIPro\Validation\JsonCanonicalizer() )->encode( $value );
		};
		return $projection( $old ) === $projection( $fresh );
	}

	/**
	 * Return only current evidence matching the exact server-produced delivery.
	 *
	 * @param Owner  $owner Verified polling actor.
	 * @param string $run_id Owned run identity.
	 * @param object $trace Server-produced event trace selector.
	 * @return object Current result or withheld state.
	 */
	public function resolve( Owner $owner, string $run_id, object $trace ): object {
		$run          = ( new RunStore( $this->db ) )->get( $owner, $run_id );
		$checkpoint   = json_decode( $run['checkpoint_json'] );
		$conversation = $this->db->rows( 'SELECT public_id,profile FROM ' . $this->db->table( 'conversations' ) . ' WHERE id=%d AND owner_ref=%s AND erasure_requested=0', array( (int) $run['conversation_id'], $owner->reference() ) );
		if ( ! $conversation ) {
			return (object) array(
				'status'   => 'unsupported',
				'reason'   => 'current_evidence_unavailable',
				'response' => null,
			);
		}
		try {
			$actor   = null !== $owner->user_id() ? Actor::user( $owner->user_id() ) : Actor::verified_guest( $owner->guest_hash(), (int) ( $checkpoint->guest_expiry ?? 0 ) );
			$context = new Context( $actor, $conversation[0]['profile'], 'worker', $conversation[0]['public_id'], $run_id );
			return $this->gateway->execute(
				'conversation.result',
				wp_json_encode(
					array(
						'run_id'        => $run_id,
						'question_hash' => $trace->question_hash,
						'plan_hash'     => $trace->plan_hash,
						'evidence_hash' => $trace->delivered_hash,
					)
				),
				$context
			);
		} catch ( ContractException $error ) {
			return (object) array(
				'status'   => 'unsupported',
				'reason'   => 'current_evidence_unavailable',
				'response' => null,
			);
		}
	}
}
