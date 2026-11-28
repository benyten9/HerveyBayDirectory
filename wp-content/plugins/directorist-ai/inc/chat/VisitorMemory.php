<?php
/** Owned, freshly verified result references for natural visitor follow-ups.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Chat;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Bounded read-only conversation memory.
use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Capabilities\Gateway;
use DirectoristAIPro\Retrieval\GroundedRunResult;
use DirectoristAIPro\Retrieval\GroundedMessageProvenance;
use DirectoristAIPro\Retrieval\GroundedConversationHistoryVerifier;
use DirectoristAIPro\Storage\Database;
/** Resolve conversational references only from freshly verified owned results. */
final class VisitorMemory {
	public static function results( array $run, Context $context, Gateway $gateway, Database $db ): array {
		return self::references( $run, $context, $gateway, $db )['results'];
	}

	/** Keep action focus separate from the ordering of the last search results. */
	public static function references( array $run, Context $context, Gateway $gateway, Database $db ): array {
		$results             = null;
		$fallback_results    = null;
		$focus               = null;
		$focus_resolved      = false;
		$verified_candidates = 0;
		$catalog_changed     = false;
		$requested_directory = self::requested_directory( $run );
		// Look past source-free help turns without multiplying native revalidation.
		$rows = $db->rows( 'SELECT r.*,c.profile,c.public_id AS conversation_public_id FROM ' . $db->table( 'runs' ) . ' r JOIN ' . $db->table( 'conversations' ) . " c ON c.id=r.conversation_id WHERE r.conversation_id=%d AND r.owner_ref=%s AND c.owner_ref=r.owner_ref AND c.erasure_requested=0 AND r.id<%d AND r.state='succeeded' ORDER BY r.id DESC LIMIT 24", array( (int) $run['conversation_id'], $run['owner_ref'], (int) $run['id'] ) );
		foreach ( $rows as $row ) {
			$cp = json_decode( $row['checkpoint_json'] );
			if ( ! isset( $cp->grounded_input, $cp->grounded_hash ) ) {
				continue;
			}
			// The original attested trace is server-owned; never take result IDs from a model summary.
			$messages = $db->rows( 'SELECT * FROM ' . $db->table( 'messages' ) . " WHERE conversation_id=%d AND role='assistant' AND turn_id=%d ORDER BY sequence DESC LIMIT 1", array( (int) $run['conversation_id'], (int) $row['trigger_turn_id'] ) );
			$saved    = json_decode( $messages[0]['content_json'] ?? '' );
			if ( ! isset( $saved->grounded->trace ) ) {
				continue;
			}
			if ( null !== $requested_directory ) {
				$directories = array_column( $saved->grounded->cards ?? array(), 'directory_id' );
				$sets        = $saved->grounded->comparisons ?? array();
				if ( isset( $saved->grounded->comparison ) ) {
					$sets[] = $saved->grounded->comparison; }
				foreach ( $sets as $set ) {
					if ( isset( $set->directory_id ) ) {
						$directories[] = $set->directory_id; }
				}
				if ( isset( $saved->grounded->native_actions->directory_id ) ) {
					$directories[] = $saved->grounded->native_actions->directory_id; }
				if ( $directories && ! in_array( $requested_directory, array_map( 'intval', $directories ), true ) ) {
					continue; }
			}
			// A detail answer changes focus, not the order of the preceding choices.
			// This read-only classification can skip an unused older detail, but it
			// cannot make any identity public or bypass the resolver below.
			if ( $focus_resolved && null !== $fallback_results && 'compare' === ( $saved->grounded->task->kind ?? null ) ) {
				$sets = $saved->grounded->comparisons ?? array();
				if ( isset( $saved->grounded->comparison ) ) {
					$sets[] = $saved->grounded->comparison;
				}
				$ids = array();
				foreach ( $sets as $set ) {
					$ids = array_merge( $ids, $set->listing_ids ?? array() );
				}
				if ( count( array_unique( $ids ) ) <= 1 ) {
					continue;
				}
			}
			if ( ++$verified_candidates > 6 ) {
				break;
			}
			// Memory needs freshly public identities and titles, never an old
			// search's facts, total or ranking. Reuse the existing attested native
			// identity reader before considering a complete historical replay.
			$identities = self::identity_references( $context, $gateway, $db, $row, $messages[0], $saved );
			$current = null !== $identities ? (object) array( 'status' => 'available', 'response' => $identities ) : ( new GroundedRunResult( $db, $gateway ) )->resolve( $context->actor()->owner(), $row['public_id'], $saved->grounded->trace );
			$catalog_changed = $catalog_changed || true === ( $identities->catalog_changed ?? false );
			if ( 'available' !== $current->status ) {
				$fresh = self::dated_references( $context, $gateway, $db, $row, $messages[0], $saved );
				$fresh = $fresh ?? self::identity_references( $context, $gateway, $db, $row, $messages[0], $saved );
				if ( null === $fresh ) {
					continue;
				}
				$current = (object) array( 'response' => $fresh );
			}
			if ( ! $focus_resolved && isset( $current->response->native_actions ) ) {
				$action = $current->response->native_actions;
				$scope  = json_decode( $run['context_json'] );
				if ( VisitorScope::global( $scope ) || (string) ( $scope->directory_id ?? '' ) === (string) $action->directory_id ) {
					$focus          = (object) array(
						'id'           => $action->listing_id,
						'directory_id' => $action->directory_id,
						'title'        => mb_substr( $action->title, 0, 160 ),
					);
					$focus_resolved = true;
				}
			}
			if ( 'search' === ( $current->response->task->kind ?? null ) && 'missing' === $current->response->status ) {
				$results = array();
				if ( ! $focus_resolved ) {
					$focus = null;
				}
				break;
			}
			$out        = array();
			$seen       = array();
			$identities = $current->response->cards ?? array();
			$sets       = $current->response->comparisons ?? array();
			if ( isset( $current->response->comparison ) ) {
				$sets[] = $current->response->comparison;
			}
			foreach ( $sets as $set ) {
				foreach ( $set->listing_ids as $id ) {
					$facts = array();
					foreach ( $set->rows as $row ) {
						if ( 'title' === $row->field_id ) {
							foreach ( $row->cells as $cell ) {
								if ( $id === $cell->listing_id ) {
									$facts[] = (object) array(
										'field_id' => 'title',
										'state'    => $cell->state,
										'value'    => $cell->value,
									);
								}
							}
						}
					}
					$identities[] = (object) array(
						'listing_id'   => $id,
						'directory_id' => $set->directory_id,
						'facts'        => $facts,
					);
				}
			}
			foreach ( array_slice( $identities, 0, 50 ) as $card ) {
				$scope = json_decode( $run['context_json'] );
				if ( ! VisitorScope::global( $scope ) && (string) ( $scope->directory_id ?? '' ) !== (string) $card->directory_id ) {
					continue; }
				$key = $card->directory_id . ':' . $card->listing_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$title        = '';
				foreach ( $card->facts as $fact ) {
					if ( 'title' === $fact->field_id && 'known' === $fact->state ) {
									$title = (string) $fact->value;
					}
				}$out[] = (object) array(
					'position'     => count( $out ) + 1,
					'id'           => $card->listing_id,
					'directory_id' => $card->directory_id,
					'title'        => mb_substr( $title, 0, 160 ),
				);}
			if ( $out ) {
				if ( ! $focus_resolved ) {
					$focus          = 1 === count( $out ) ? (object) array(
						'id'           => $out[0]->id,
						'directory_id' => $out[0]->directory_id,
						'title'        => $out[0]->title,
					) : null;
					$focus_resolved = true;
				}
				if ( 'search' === ( $current->response->task->kind ?? null ) || count( $out ) > 1 ) {
					$results = $out;
				} else {
					$fallback_results = $fallback_results ?? $out;
				}
			}
			if ( null !== $results && $focus_resolved ) {
				break;
			}
		}
		return array(
			'results' => $results ?? $fallback_results ?? array(),
			'focus'   => $focus,
			'catalog_changed' => $catalog_changed,
		);
	}

	/** A deliberate return to an earlier public directory chooses its result anchor. */
	private static function requested_directory( array $run ): ?int {
		$checkpoint = $run['checkpoint'] ?? json_decode( $run['checkpoint_json'] ?? '{}' );
		$text       = ( $checkpoint->reply ?? $checkpoint->intent ?? null )->text ?? '';
		if ( ! is_string( $text ) || ! preg_match( '/\b(?:back to|earlier|previously|before|started with)\b/i', $text ) ) {
			return null; }
		$normalize = static fn( string $value ): string => ' ' . trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $value ) ) ) . ' ';
		$text      = $normalize( $text );
		$names     = array();
		$suffixes  = array();
		foreach ( $checkpoint->visitor_site->directories ?? array() as $directory ) {
			$directory = (object) $directory;
			if ( ! isset( $directory->id, $directory->name ) || ! is_string( $directory->name ) || ! ctype_digit( (string) $directory->id ) || (int) $directory->id < 1 ) {
				continue; }
			$name = trim( $normalize( $directory->name ) );
			if ( '' === $name ) {
				continue; }
			$parts                         = explode( ' ', $name );
			$suffix                        = end( $parts );
			$names[ (int) $directory->id ] = $name;
			$suffixes[ $suffix ][]         = (int) $directory->id;
		}
		$matches = array();
		foreach ( $names as $id => $name ) {
			if ( str_contains( $text, ' ' . $name . ' ' ) ) {
				$matches[] = $id; }
		}
		if ( ! $matches ) {
			foreach ( $suffixes as $suffix => $ids ) {
				if ( mb_strlen( $suffix ) >= 4 && 1 === count( $ids ) && str_contains( $text, ' ' . $suffix . ' ' ) ) {
					$matches[] = $ids[0]; }
			}
		}
		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/** Retain authenticated listing identities after value drift, never old prose or facts. */
	private static function identity_references( Context $context, Gateway $gateway, Database $db, array $run, array $message, object $saved ): ?object {
		try {
			if ( 'visitor' !== ( $run['profile'] ?? null ) || $context->actor()->owner()->reference() !== ( $run['owner_ref'] ?? null ) || ! isset( $saved->provenance, $message['sequence'] ) || 'results' !== ( $saved->grounded->status ?? null ) || 'listing' !== ( $saved->grounded->task->family ?? null ) || ! in_array( $saved->grounded->task->kind ?? null, array( 'search', 'compare' ), true ) ) {
				return null;
			}
			$checkpoint = json_decode( $run['checkpoint_json'] );
			if ( ! ( new GroundedMessageProvenance( $gateway, $db ) )->verifies( $run, (int) $message['sequence'], $saved, $checkpoint ) ) {
				return null;
			}
			$catalogs   = array();
			$identities = array();
			$bind       = static function ( object $query ) use ( &$catalogs ): void {
				$directory = $query->directory_id ?? null;
				$revision  = $query->catalog_revision ?? null;
				if ( ! is_int( $directory ) || $directory < 1 || ! is_string( $revision ) || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) || ( isset( $catalogs[ $directory ] ) && $catalogs[ $directory ] !== $revision ) ) {
					throw new \DirectoristAIPro\Validation\ContractException( 'stale_evidence', 'Reference catalog is unavailable.' );
				}
				$catalogs[ $directory ] = $revision;
			};
			$queries    = $saved->grounded->queries ?? array();
			if ( isset( $saved->grounded->query ) ) {
				$queries[] = $saved->grounded->query; }
			foreach ( $queries as $query ) {
				$bind( $query ); }
			// Current grounded DTOs keep the effective search selectors inside the
			// signed structural token, rather than public query/query-list members.
			// Authenticate that exact producer token; never invent a current catalog
			// revision for an old result whose original schema cannot be established.
			if ( 'search' === $saved->grounded->task->kind && is_string( $saved->grounded->context_token ?? null ) ) {
				$payload = ( new \DirectoristAIPro\Retrieval\GroundedContextToken( wp_salt( 'auth' ) ) )->decode_for_replay( $saved->grounded->context_token );
				$scope = hash( 'sha256', $run['owner_ref'] . '|visitor|' . $run['conversation_public_id'] );
				if ( get_current_blog_id() !== $payload->site || 'listing' !== $payload->family || 'public' !== $payload->dataset || 'search' !== ( $payload->plan->kind ?? null ) || ! hash_equals( $scope, $payload->scope ) ) {
					return null;
				}
				foreach ( $payload->plan->payload->queries ?? array( $payload->plan->payload ) as $query ) {
					$bind( $query );
				}
			}
			foreach ( $saved->grounded->cards ?? array() as $card ) {
				$identities[ $card->directory_id . ':' . $card->listing_id ] = array( $card->directory_id, $card->listing_id ); }
			$sets = $saved->grounded->comparisons ?? array();
			if ( isset( $saved->grounded->comparison ) ) {
				$sets[] = $saved->grounded->comparison; }
			foreach ( $sets as $set ) {
				$bind( $set );
				foreach ( $set->listing_ids as $id ) {
					$identities[ $set->directory_id . ':' . $id ] = array( $set->directory_id, $id ); }
			}
			if ( ! $identities || count( $identities ) > 50 ) {
				return null; }
			$cards = array(); $catalog_changed = false;
			foreach ( $identities as list( $directory, $id ) ) {
				if ( ! isset( $catalogs[ $directory ] ) ) {
					return null; }
				ListingActions::assert_scope( $context, $directory, $db );
				$fresh = $gateway->execute(
					'listing.compare',
					wp_json_encode(
						array(
							'directory_id' => $directory,
							'listing_ids'  => array( $id ),
							'fields'       => array( 'title' ),
						)
					),
					$context
				);
				$cell  = $fresh->rows[0]->cells[0] ?? null;
				if ( 'comparison' !== $fresh->status || 1 !== count( $fresh->rows ) || 'title' !== $fresh->rows[0]->field_id || 1 !== count( $fresh->rows[0]->cells ) || ( $cell->listing_id ?? null ) !== $id || 'known' !== $cell->state || ! is_string( $cell->value ) ) {
					return null; }
				// Schema changes invalidate old facts/filters, not an authenticated
				// identity that is independently public in the same current directory.
				$catalog_changed = $catalog_changed || ! hash_equals( $catalogs[ $directory ], $fresh->catalog_revision );
				$cards[] = (object) array(
					'listing_id'   => $id,
					'directory_id' => $directory,
					'facts'        => array(
						(object) array(
							'field_id' => 'title',
							'state'    => 'known',
							'value'    => $cell->value,
						),
					),
				);
			}
			return (object) array(
				'status' => 'results',
				'task'   => (object) array( 'kind' => $saved->grounded->task->kind ),
				'cards'  => $cards,
				'catalog_changed' => $catalog_changed,
			);
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return null;
		}
	}

	/** Dated facts never enter memory; re-authorize and re-read only public identities/titles. */
	private static function dated_references( Context $context, Gateway $gateway, Database $db, array $run, array $message, object $saved ): ?object {
		try {
			$resolver = new GroundedRunResult( $db, $gateway );
			$verifier = new GroundedConversationHistoryVerifier( $db, new GroundedMessageProvenance( $gateway, $db ), $resolver );
			$history  = $verifier->resolve( $context, $message, $saved, json_decode( $message['source_refs_json'] ), (int) $run['conversation_id'] );
			if ( ! isset( $history->history_clock ) ) {
				return null;
			}
			$identities = array();
			foreach ( $history->grounded->cards ?? array() as $card ) {
				$identities[] = array( $card->directory_id, $card->listing_id );
			}
			$sets = $history->grounded->comparisons ?? array();
			if ( isset( $history->grounded->comparison ) ) {
				$sets[] = $history->grounded->comparison;
			}
			foreach ( $sets as $set ) {
				foreach ( $set->listing_ids as $id ) {
					$identities[] = array( $set->directory_id, $id );
				}
			}
			$cards = array();
			foreach ( $identities as list( $directory, $id ) ) {
				ListingActions::assert_scope( $context, $directory, $db );
				$fresh = $gateway->execute(
					'listing.compare',
					wp_json_encode(
						array(
							'directory_id' => $directory,
							'listing_ids'  => array( $id ),
							'fields'       => array( 'title' ),
						)
					),
					$context
				);
				$cell  = $fresh->rows[0]->cells[0] ?? null;
				if ( 'comparison' !== $fresh->status || 1 !== count( $fresh->rows ) || 'title' !== $fresh->rows[0]->field_id || 1 !== count( $fresh->rows[0]->cells ) || ( $cell->listing_id ?? null ) !== $id || 'known' !== $cell->state || ! is_string( $cell->value ) ) {
					return null;
				}
				$cards[] = (object) array(
					'listing_id'   => $id,
					'directory_id' => $directory,
					'facts'        => array(
						(object) array(
							'field_id' => 'title',
							'state'    => 'known',
							'value'    => $cell->value,
						),
					),
				);
			}
			return (object) array(
				'status' => 'results',
				'task'   => (object) array( 'kind' => $history->grounded->task->kind ),
				'cards'  => $cards,
			);
		} catch ( \DirectoristAIPro\Validation\ContractException $error ) {
			return null;
		}
	}
}
