<?php
/**
 * Explicitly approved WordPress knowledge ingestion and lexical lookup.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable Generic.Formatting.MultipleStatementAlignment,WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,WordPress.Arrays.MultipleStatementAlignment,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Security and lifecycle boundaries are documented at service level.

use DirectoristAIPro\Capabilities\Context;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\ContractException;

/** Approved native documents remain evidence; their text is never executable authority. */
final class KnowledgeService {
	private const GROUP = 'directorist-ai-pro';
	private const AUTHORIZED_CAP = 200;
	private const RAW_SCAN_CAP = 1000;
	private KnowledgeStore $store;

	/** Bind the owned knowledge projection. */
	public function __construct( KnowledgeStore $store ) {
		$this->store = $store;
	}

	/** Register immediate invalidation, recovery and background generation callbacks. */
	public function hooks(): void {
		add_action( 'init', array( $this, 'ensure_rebuilds' ), 45 );
		add_action( 'save_post', array( $this, 'saved' ), 100, 3 );
		add_action( 'before_delete_post', array( $this, 'deleting' ), 100, 2 );
		add_action( 'delete_term', array( $this, 'directory_deleted' ), 100, 3 );
		add_action( 'directorist_ai_pro_knowledge_rebuild', array( $this, 'handle_rebuild' ), 10, 1 );
	}

	/** Explicitly approve one WordPress post/page under live administrator authority. */
	public function approve( Context $context, string $source_key, int $post_id, string $scope, ?int $directory_id = null ): array {
		$this->assert_administrator( $context );
		$snapshot = $this->snapshot( $post_id, $scope );
		if ( ! $snapshot['supported'] ) {
			throw new ContractException( 'invalid_input', 'Only supported site documents can be approved as knowledge.' );
		}
		if ( ! $snapshot['native_eligible'] ) {
			throw new ContractException( 'invalid_input', 'The document is not eligible for the selected knowledge scope.' );
		}
		if ( null !== $directory_id && ! $this->directory_exists( $directory_id ) ) {
			throw new ContractException( 'invalid_input', 'Knowledge directory scope is unavailable.' );
		}
		try {
			$source = $this->store->approve( $source_key, $post_id, $scope, $directory_id, $snapshot['revision'], $context->actor()->user_id() );
		} catch ( StorageException $error ) {
			throw new ContractException( 'request_conflict', 'The document already has an approved source alias for this scope.' );
		}
		if ( ! $this->schedule( $source ) ) {
			throw new ContractException( 'unsupported_dependency', 'Knowledge rebuild scheduling is unavailable. Recovery remains pending.' );
		}
		return $source;
	}

	/** Explicitly revoke one approved source before queued cleanup. */
	public function revoke( Context $context, string $source_key ): bool {
		$this->assert_administrator( $context );
		return $this->store->revoke( $source_key, $context->actor()->user_id() );
	}

	/** Fence every approval tied to a changed native post before rebuilding. */
	public function saved( int $post_id, \WP_Post $post, bool $update ): void {
		unset( $post, $update );
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		foreach ( $this->all_source_keys( 'post', $post_id ) as $source_key ) {
			$source   = $this->store->source( $source_key );
			$snapshot = $this->snapshot( $post_id, $source['scope'] );
			$eligible = $snapshot['supported'] && $snapshot['native_eligible'] && ( null === $source['directory_id'] || $this->directory_exists( $source['directory_id'] ) );
			$observed = $this->store->observe( $source_key, $snapshot['revision'], $eligible );
			if ( $observed && $eligible ) {
				$this->schedule( $observed );
			}
		}
	}

	/** Fence approved content before native deletion makes it unavailable. */
	public function deleting( int $post_id, \WP_Post $post ): void {
		unset( $post );
		foreach ( $this->all_source_keys( 'post', $post_id ) as $source_key ) {
			$this->store->observe( $source_key, hash( 'sha256', 'deleted:' . $post_id . ':' . microtime( true ) ), false );
		}
	}

	/** Fence directory-bound sources as part of native term deletion. */
	public function directory_deleted( int $term_id, int $term_taxonomy_id, string $taxonomy ): void {
		unset( $term_taxonomy_id );
		if ( ! defined( 'ATBDP_DIRECTORY_TYPE' ) || ATBDP_DIRECTORY_TYPE !== $taxonomy ) {
			return;
		}
		foreach ( $this->all_source_keys( 'directory', $term_id ) as $source_key ) {
			$source = $this->store->source( $source_key );
			$this->store->observe( $source_key, $source['source_revision'], false );
		}
	}

	/** Retry durable wake-required sources after transient scheduler failure. */
	public function ensure_rebuilds(): void {
		foreach ( $this->store->wake_required() as $source ) {
			$this->schedule( $source );
		}
	}

	/** Execute one queue wake; failures throw so Action Scheduler can retry. */
	public function handle_rebuild( string $source_key ): bool {
		$source = $this->store->source( $source_key );
		if ( ! $source || ! $source['approved'] ) {
			return true;
		}
		$snapshot = $this->snapshot( $source['post_id'], $source['scope'] );
		$eligible = $snapshot['supported'] && $snapshot['native_eligible'] && ( null === $source['directory_id'] || $this->directory_exists( $source['directory_id'] ) );
		if ( ! $eligible ) {
			$this->store->observe( $source_key, $snapshot['revision'], false );
			return true;
		}
		if ( ! hash_equals( $source['source_revision'], $snapshot['revision'] ) ) {
			$source = $this->store->observe( $source_key, $snapshot['revision'], true );
		}
		if ( 'active' === $source['state'] && $source['eligible'] && ! $source['wake_required'] && null === $source['building_generation'] ) {
			return true;
		}
		$result = $this->rebuild( $source_key, 25, $source['approval_epoch'], $source['source_revision'] );
		if ( ! $result['complete'] ) {
			$source = $this->store->source( $source_key );
			if ( ! $source || ! $this->schedule( $source, 1 ) ) {
				throw new StorageException( 'Knowledge rebuild continuation could not be scheduled.' );
			}
		}
		return true;
	}

	/** Build one bounded portion of a hidden source generation. */
	public function rebuild( string $source_key, int $batch_size = 25, ?int $expected_epoch = null, ?string $expected_revision = null ): array {
		if ( $batch_size < 1 || $batch_size > 100 ) {
			throw new ContractException( 'invalid_input', 'Knowledge rebuild batch is invalid.' );
		}
		$source = $this->store->source( $source_key );
		if ( ! $source || ! $source['approved'] ) {
			throw new ContractException( 'not_found', 'Knowledge source is unavailable.' );
		}
		if ( ( null !== $expected_epoch && $source['approval_epoch'] !== $expected_epoch ) || ( null !== $expected_revision && ! hash_equals( $source['source_revision'], $expected_revision ) ) ) {
			throw new ContractException( 'stale_version', 'Knowledge rebuild source fence is stale.' );
		}
		$snapshot = $this->snapshot( $source['post_id'], $source['scope'] );
		$eligible = $snapshot['supported'] && $snapshot['native_eligible'] && ( null === $source['directory_id'] || $this->directory_exists( $source['directory_id'] ) );
		if ( ! $eligible ) {
			$this->store->observe( $source_key, $snapshot['revision'], false );
			throw new ContractException( 'evidence_stale', 'Knowledge source is no longer eligible.' );
		}
		if ( ! hash_equals( $source['source_revision'], $snapshot['revision'] ) ) {
			$source = $this->store->observe( $source_key, $snapshot['revision'], true );
			if ( null !== $expected_epoch || null !== $expected_revision ) {
				$this->schedule( $source );
				throw new ContractException( 'stale_version', 'Knowledge source changed before rebuild.' );
			}
		}
		$chunks = $this->chunks( $snapshot['title'], $snapshot['content'] );
		if ( ! $chunks ) {
			$this->store->observe( $source_key, $snapshot['revision'], false );
			throw new ContractException( 'invalid_input', 'Knowledge source has no meaningful textual evidence.' );
		}
		$generation = $this->store->begin( $source_key, $source['approval_epoch'], $source['source_revision'] );
		$current    = $this->store->source( $source_key );
		$offset     = $current['cursor_position'];
		$batch      = array_slice( $chunks, $offset, $batch_size );
		$staged     = 0;
		foreach ( $batch as $chunk ) {
			if ( ! $this->store->stage( $source_key, $source['approval_epoch'], $source['source_revision'], $generation, $chunk ) ) {
				throw new ContractException( 'stale_version', 'Knowledge source changed during rebuild.' );
			}
			++$offset;
			++$staged;
			if ( ! $this->store->checkpoint( $source_key, $source['approval_epoch'], $source['source_revision'], $generation, $offset ) ) {
				throw new ContractException( 'stale_version', 'Knowledge rebuild lost its generation fence.' );
			}
		}
		$complete = $offset >= count( $chunks );
		if ( $complete && ! $this->store->publish( $source_key, $source['approval_epoch'], $source['source_revision'], $generation, count( $chunks ) ) ) {
			throw new ContractException( 'stale_version', 'Knowledge generation could not be published.' );
		}
		return array( 'generation' => $generation, 'cursor_position' => $offset, 'complete' => $complete, 'staged' => $staged, 'total' => count( $chunks ) );
	}

	/** Retrieve current lexical evidence with source citations and limitations. */
	public function search( object $input, Context $context ): object {
		if ( null !== $input->directory_id && ! $this->directory_exists( $input->directory_id ) ) {
			throw new ContractException( 'not_found', 'Knowledge directory scope is unavailable.' );
		}
		$tokens = $this->tokens( $input->query );
		if ( ! $tokens ) {
			throw new ContractException( 'invalid_input', 'Knowledge query has no searchable terms.' );
		}
		$verified = array();
		$sources  = array();
		$after    = null;
		$scanned  = 0;
		$authorized = 0;
		while ( true ) {
			try {
				$page = $this->store->candidate_page( $tokens, $input->directory_id, $input->source_ids, $after, 100 );
			} catch ( StorageException $error ) {
				throw new ContractException( 'invalid_input', 'Knowledge source selection is invalid.' );
			}
			if ( ! $page ) {
				break;
			}
			foreach ( $page as $candidate ) {
				++$scanned;
				if ( $scanned > self::RAW_SCAN_CAP ) {
					throw new ContractException( 'needs_expansion', 'Knowledge candidate scan must be narrowed.' );
				}
				$key = $candidate['source_key'];
				if ( ! array_key_exists( $key, $sources ) ) {
					$sources[ $key ] = $this->live_source( $candidate, $context );
				}
				if ( ! $sources[ $key ] ) {
					continue;
				}
				++$authorized;
				if ( $authorized > self::AUTHORIZED_CAP ) {
					throw new ContractException( 'needs_expansion', 'Knowledge candidate set must be narrowed.' );
				}
				$score = $this->score( $candidate, $tokens );
				if ( $score > 0 ) {
					$candidate['score'] = $score;
					$verified[]         = $candidate;
				}
			}
			$last  = end( $page );
			$after = array( 'source_key' => $last['source_key'], 'position' => (int) $last['position'] );
			if ( count( $page ) < 100 ) {
				break;
			}
		}
		$this->sort_rows( $verified );
		$selected = $this->select_rows( $verified, $input->limit );
		do_action( 'directorist_ai_pro_knowledge_before_output', array_values( array_unique( array_column( $selected, 'source_key' ) ) ) );
		$context->actor()->refresh();
		$final = array();
		foreach ( $selected as $row ) {
			if ( $this->live_source( $row, $context ) ) {
				$final[] = $row;
			}
		}
		$pair   = $this->conflict_pair( $final );
		$status = $final ? ( $pair ? 'conflict' : 'evidence' ) : 'no_evidence';
		$items  = array_map( static fn( array $row ): object => (object) array( 'text' => $row['chunk_text'], 'score' => $row['score'], 'citation' => (object) array( 'source_id' => $row['source_key'], 'version' => $row['source_revision'], 'title' => $row['document_title'], 'section' => $row['section_key'], 'section_title' => $row['section_title'], 'scope' => $row['scope_key'] ) ), $final );
		return (object) array(
			'contract'     => 'directorist-ai/knowledge-v1',
			'status'       => $status,
			'items'        => $items,
			'diagnostics'  => (object) array( 'candidate_count' => count( $final ), 'matched_count' => count( $items ), 'reason' => 'conflict' === $status ? 'current_sources_disagree' : ( 'no_evidence' === $status ? 'no_current_eligible_evidence' : null ) ),
			'evidence_policy' => (object) array( 'content_role' => 'untrusted_evidence', 'can_grant_tools' => false, 'can_grant_approval' => false ),
		);
	}

	/** Ensure selection/revocation comes from a current native administrator. */
	private function assert_administrator( Context $context ): void {
		$context->assert_execution_identity();
		if ( $context->actor()->is_guest() || ! $context->actor()->can( 'manage_options' ) ) {
			throw new ContractException( 'unauthorized', 'Knowledge approval requires current administrator authority.' );
		}
	}

	/** Capture inert source text and a complete native source revision. */
	private function snapshot( int $post_id, string $scope ): array {
		// Re-read native eligibility/content even when an earlier round primed the cache.
		wp_cache_delete( $post_id, 'posts' );
		$post      = get_post( $post_id );
		$supported = $post instanceof \WP_Post && in_array( $post->post_type, $this->supported_post_types(), true ) && ( ! defined( 'ATBDP_POST_TYPE' ) || ATBDP_POST_TYPE !== $post->post_type );
		if ( ! $supported ) {
			return array( 'supported' => false, 'native_eligible' => false, 'revision' => hash( 'sha256', 'missing:' . $post_id ), 'title' => '', 'content' => '' );
		}
		$payload = array( $post->ID, $post->post_type, $post->post_status, $post->post_password, $post->post_title, $post->post_content, $post->post_excerpt, $post->post_modified_gmt );
		$public  = 'publish' === $post->post_status && '' === $post->post_password;
		$private = ! in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) && '' === $post->post_password;
		return array(
			'supported'       => true,
			'native_eligible' => 'public' === $scope ? $public : ( 'private' === $scope && $private ),
			'revision'        => hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) ),
			'title'           => $this->truncate( $this->plain( $post->post_title ), 500 ),
			'content'         => $post->post_content,
		);
	}

	/** Recheck native state, directory and actor access; mismatch schedules recovery. */
	private function live_source( array $candidate, Context $context ): bool {
		$snapshot  = $this->snapshot( (int) $candidate['post_id'], $candidate['scope_key'] );
		$directory = null === $candidate['directory_id'] || $this->directory_exists( (int) $candidate['directory_id'] );
		$eligible  = $snapshot['supported'] && $snapshot['native_eligible'] && $directory;
		$current   = $eligible && hash_equals( $candidate['source_revision'], $snapshot['revision'] );
		if ( ! $current ) {
			$observed = $this->store->observe( $candidate['source_key'], $snapshot['revision'], $eligible );
			if ( $observed && $eligible ) {
				$this->schedule( $observed );
			}
			return false;
		}
		return 'public' === $candidate['scope_key'] || ( ! $context->actor()->is_guest() && $context->actor()->can( 'read_post', (int) $candidate['post_id'] ) );
	}

	/** Split decoded inert content by headings, paragraphs and bounded words. */
	private function chunks( string $document_title, string $content ): array {
		if ( strlen( $content ) > 524288 ) {
			throw new ContractException( 'invalid_input', 'Knowledge source exceeds the supported document size.' );
		}
		$content  = $this->decode_entities( $content );
		$content  = preg_replace( '#<(script|style|template|noscript)[^>]*>.*?</\1>#is', ' ', $content );
		$parts    = preg_split( '#(<h[1-6][^>]*>.*?</h[1-6]>)#is', (string) $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		$heading  = '' !== trim( $document_title ) ? trim( $document_title ) : 'Document';
		$sections = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '#^<h[1-6][^>]*>(.*?)</h[1-6]>$#is', trim( $part ), $match ) ) {
				$heading = $this->truncate( $this->plain( $match[1] ), 500 );
				continue;
			}
			$text = preg_replace( '#</(p|li|blockquote|div|section|article|pre|table|tr)>#i', "\n", $part );
			$text = $this->plain( (string) $text, true );
			if ( '' !== $text ) {
				$sections[] = array( 'heading' => $heading, 'text' => $text );
			}
		}
		$chunks = array();
		$keys   = array();
		foreach ( $sections as $section ) {
			$paragraphs = preg_split( '/\n+/', $section['text'], -1, PREG_SPLIT_NO_EMPTY );
			$batches    = array();
			$current    = '';
			foreach ( $paragraphs as $paragraph ) {
				foreach ( $this->split_text( trim( $paragraph ), 1800 ) as $piece ) {
					if ( '' !== $current && strlen( $current ) + strlen( $piece ) + 1 > 1800 ) {
						$batches[] = $current;
						$current   = '';
					}
					$current = '' === $current ? $piece : $current . "\n" . $piece;
				}
			}
			if ( '' !== $current ) {
				$batches[] = $current;
			}
			$base = $this->section_key( $section['heading'] );
			$count = ( $keys[ $base ] ?? 0 ) + 1;
			$keys[ $base ] = $count;
			foreach ( $batches as $part_index => $text ) {
				$suffix   = count( $batches ) > 1 ? '-part-' . ( $part_index + 1 ) : '';
				$distinct = $count > 1 ? '-' . $count : '';
				$chunks[] = array( 'section_key' => substr( $base . $distinct . $suffix, 0, 128 ), 'position' => count( $chunks ), 'document_title' => $document_title, 'section_title' => $section['heading'], 'text' => $text );
				if ( count( $chunks ) > 500 ) {
					throw new ContractException( 'invalid_input', 'Knowledge source has too many sections.' );
				}
			}
		}
		return $chunks;
	}

	/** Produce a deterministic valid ASCII section key for every Unicode heading. */
	private function section_key( string $heading ): string {
		$ascii = function_exists( 'remove_accents' ) ? remove_accents( $heading ) : $heading;
		$ascii = strtolower( $ascii );
		$ascii = trim( preg_replace( '/[^a-z0-9]+/', '-', $ascii ), '-' );
		if ( '' === $ascii ) {
			return 'section-' . substr( hash( 'sha256', $heading ), 0, 20 );
		}
		return substr( $ascii, 0, 80 );
	}

	/** Decode entities before stripping markup; never execute source rendering. */
	private function plain( string $text, bool $preserve_lines = false ): string {
		$text = $this->decode_entities( $text );
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text, false );
		$text = preg_replace( $preserve_lines ? '/[^\S\r\n]+/' : '/\s+/', ' ', $text );
		$text = $preserve_lines ? preg_replace( '/\s*\n\s*/', "\n", (string) $text ) : $text;
		return trim( (string) $text );
	}

	/** Decode bounded nested entities before any markup stripping. */
	private function decode_entities( string $text ): string {
		for ( $pass = 0; $pass < 3; ++$pass ) {
			$decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		return $text;
	}

	/** Split overlong paragraphs at word boundaries. */
	private function split_text( string $text, int $limit ): array {
		if ( strlen( $text ) <= $limit ) {
			return '' === $text ? array() : array( $text );
		}
		$words  = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$pieces = array();
		$piece  = '';
		foreach ( $words as $word ) {
			if ( strlen( $word ) > $limit ) {
				if ( '' !== $piece ) {
					$pieces[] = $piece;
					$piece    = '';
				}
				while ( '' !== $word ) {
					$segment  = $this->truncate( $word, $limit );
					$pieces[] = $segment;
					$word     = function_exists( 'mb_substr' ) ? mb_substr( $word, mb_strlen( $segment, 'UTF-8' ), null, 'UTF-8' ) : substr( $word, strlen( $segment ) );
				}
				continue;
			}
			if ( '' !== $piece && strlen( $piece ) + strlen( $word ) + 1 > $limit ) {
				$pieces[] = $piece;
				$piece    = '';
			}
			$piece = '' === $piece ? $word : $piece . ' ' . $word;
		}
		if ( '' !== $piece ) {
			$pieces[] = $piece;
		}
		return $pieces;
	}

	/** Truncate a UTF-8 string to a byte-bound without splitting a character. */
	private function truncate( string $text, int $bytes ): string {
		if ( strlen( $text ) <= $bytes ) {
			return $text;
		}
		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $bytes, 'UTF-8' ) : substr( $text, 0, $bytes );
	}

	/** Extract a bounded lexical query vocabulary. */
	private function tokens( string $query ): array {
		$normalized = KnowledgeStore::normalize_text( $query );
		$stop       = array( 'a', 'an', 'and', 'are', 'for', 'how', 'is', 'of', 'or', 'the', 'to', 'what', 'when', 'where', 'who', 'with' );
		$tokens     = array_values( array_unique( array_filter( explode( ' ', $normalized ), static fn( string $token ): bool => strlen( $token ) >= 2 && ! in_array( $token, $stop, true ) ) ) );
		return array_slice( $tokens, 0, 8 );
	}

	/** Rank exact lexical evidence deterministically; scores are not confidence. */
	private function score( array $candidate, array $tokens ): int {
		$title = KnowledgeStore::normalize_text( $candidate['section_title'] );
		$text  = $candidate['search_text'];
		$body  = KnowledgeStore::normalize_text( $candidate['chunk_text'] );
		$score = 0;
		foreach ( $tokens as $token ) {
			$title_exact = substr_count( ' ' . $title . ' ', ' ' . $token . ' ' );
			$text_exact  = substr_count( ' ' . $text . ' ', ' ' . $token . ' ' );
			$score      += 4 * $title_exact + min( 3, $text_exact );
			if ( strlen( $token ) >= 4 ) {
				$prefix = '/(?:^| )' . preg_quote( $token, '/' ) . '\\p{L}{1,3}(?: |$)/u';
				$score += 2 * ( 0 === $title_exact && 1 === preg_match( $prefix, $title ) ? 1 : 0 );
				$score += 0 === $text_exact && 1 === preg_match( $prefix, $body ) ? 1 : 0;
			}
		}
		return $score;
	}

	/** Stable score/source/position ordering. */
	private function sort_rows( array &$rows ): void {
		usort(
			$rows,
			static function ( array $left, array $right ): int {
				$score = $right['score'] <=> $left['score'];
				if ( 0 !== $score ) {
					return $score;
				}
				$source = strcmp( $left['source_key'], $right['source_key'] );
				return 0 !== $source ? $source : (int) $left['position'] <=> (int) $right['position'];
			}
		);
	}

	/** Find the highest-ranked conflicting current section pair. */
	private function conflict_pair( array $rows ): ?array {
		$sections = array();
		foreach ( $rows as $index => $row ) {
			$key = KnowledgeStore::normalize_text( $row['section_title'] );
			if ( isset( $sections[ $key ] ) && $rows[ $sections[ $key ] ]['source_key'] !== $row['source_key'] && ! hash_equals( $rows[ $sections[ $key ] ]['content_hash'], $row['content_hash'] ) ) {
				return array( $sections[ $key ], $index );
			}
			$sections[ $key ] = $index;
		}
		return null;
	}

	/** Select top evidence while preserving a complete conflict pair even at limit one. */
	private function select_rows( array $rows, int $limit ): array {
		$pair = $this->conflict_pair( $rows );
		if ( null === $pair ) {
			return array_slice( $rows, 0, $limit );
		}
		$budget   = max( 2, $limit );
		$selected = array( $rows[ $pair[0] ], $rows[ $pair[1] ] );
		foreach ( $rows as $index => $row ) {
			if ( count( $selected ) >= $budget ) {
				break;
			}
			if ( ! in_array( $index, $pair, true ) ) {
				$selected[] = $row;
			}
		}
		$this->sort_rows( $selected );
		return $selected;
	}

	/** Supported document types stay narrow and filterable by trusted site code. */
	private function supported_post_types(): array {
		$types = apply_filters( 'directorist_ai_pro_knowledge_post_types', array( 'page', 'post' ) );
		return array_values( array_filter( array_unique( is_array( $types ) ? $types : array() ), static fn( $type ): bool => is_string( $type ) && preg_match( '/^[a-z0-9_-]{1,20}$/D', $type ) ) );
	}

	/** Exhaust a bounded storage page without silently truncating invalidation. */
	private function all_source_keys( string $kind, int $id ): array {
		$keys  = array();
		$after = '';
		do {
			$page = 'post' === $kind ? $this->store->keys_for_post( $id, $after ) : $this->store->keys_for_directory( $id, $after );
			$keys = array_merge( $keys, $page );
			if ( $page ) {
				$after = $page[ count( $page ) - 1 ];
			}
		} while ( 100 === count( $page ) );
		return $keys;
	}

	/** Verify a current Directorist directory term. */
	private function directory_exists( int $directory_id ): bool {
		return $directory_id > 0 && function_exists( 'directorist_is_directory' ) && directorist_is_directory( $directory_id );
	}

	/** Schedule one pending source wake and validate persistence. */
	private function schedule( array $source, int $delay = 5 ): bool {
		if ( ! $source['approved'] || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$args    = array( 'source_key' => $source['source_key'] );
		$pending = as_get_scheduled_actions( array( 'hook' => 'directorist_ai_pro_knowledge_rebuild', 'args' => $args, 'group' => self::GROUP, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ) );
		if ( $pending ) {
			return true;
		}
		return 0 < (int) as_schedule_single_action( time() + $delay, 'directorist_ai_pro_knowledge_rebuild', $args, self::GROUP, false, 5 );
	}
}
