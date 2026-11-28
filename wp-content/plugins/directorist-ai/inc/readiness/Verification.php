<?php
/** Bounded, browser-reported HTTP observations checked against current native facts.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Readiness;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.VariableComment -- Closed HTTP receipt contract.

/** Receipts retain hashes/findings, never response bodies or private listing values. */
final class Verification {
	public const MAX_REQUEST_BYTES = 6291456;

	private static function relation( string $header, string $expected, string $relation, string $base, string $type = '' ): bool {
		foreach ( preg_split( '/,(?=\s*<)/', $header ) as $part ) {
			if ( ! preg_match( '/^\s*<([^>]+)>\s*;(.+)$/', $part, $match ) || self::absolute( $match[1], $base ) !== $expected ) {
				continue;
			}
			preg_match( '/(?:^|;)\s*rel\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $match[2], $rel );
			preg_match( '/(?:^|;)\s*type\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $match[2], $mime );
			if ( in_array( $relation, preg_split( '/\s+/', strtolower( ! empty( $rel[1] ) ? $rel[1] : ( $rel[2] ?? '' ) ) ), true ) && ( ! $type || strtolower( ! empty( $mime[1] ) ? $mime[1] : ( $mime[2] ?? '' ) ) === $type ) ) {
				return true;
			}
		}
		return false;
	}

	private static function absolute( string $url, string $base ): string {
		return class_exists( '\WP_Http' ) ? \WP_Http::make_absolute_url( $url, $base ) : ( preg_match( '~^https?://~', $url ) ? $url : '' );
	}

	private static function html_relation( string $html, string $expected, string $relation, string $base, string $type = '' ): bool {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return false;
		}
		$parser    = new \WP_HTML_Tag_Processor( $html );
		$head      = false;
		$inert     = 0;
		$seen_base = false;
		while ( $parser->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag    = $parser->get_tag();
			$closer = $parser->is_tag_closer();
			if ( 'HEAD' === $tag ) {
				if ( $closer ) {
					break;
				}
				$head = true;
				continue;
			}
			if ( ! $head ) {
				continue;
			}
			if ( in_array( $tag, array( 'TEMPLATE', 'NOSCRIPT' ), true ) ) {
				$inert = max( 0, $inert + ( $closer ? -1 : 1 ) );
				continue;
			}
			if ( $inert || $closer ) {
				continue;
			}
			if ( 'BASE' === $tag && ! $seen_base && is_string( $parser->get_attribute( 'href' ) ) ) {
				$base      = self::absolute( $parser->get_attribute( 'href' ), $base );
				$seen_base = true;
			}
			if ( 'LINK' === $tag && is_string( $parser->get_attribute( 'href' ) ) && self::absolute( $parser->get_attribute( 'href' ), $base ) === $expected && in_array( $relation, preg_split( '/\s+/', strtolower( (string) $parser->get_attribute( 'rel' ) ) ), true ) && ( ! $type || strtolower( (string) $parser->get_attribute( 'type' ) ) === $type ) ) {
				return true;
			}
		}
		return false;
	}

	public static function token( int $directory ): string {
		$record = DirectoryReview::record( $directory );
		return hash( 'sha256', serialize( array( $record['verification'] ?? null, $record['last_attempt'] ?? null ) ) );
	}

	public static function status( int $directory ): array {
		$record  = DirectoryReview::record( $directory );
		$receipt = $record['verification'] ?? null;
		$attempt = $record['last_attempt'] ?? null;
		if ( is_array( $attempt ) && hash_equals( (string) ( $attempt['policy_hash'] ?? '' ), DirectoryReview::policy_hash( $directory ) ) && DirectoryReview::fresh( $directory ) ) {
			try {
				$audit = ( new AdminInspector() )->listing( (int) $attempt['listing_id'] );
			} catch ( \InvalidArgumentException $error ) {
				$audit = array(); }
			if ( ! empty( $audit['published'] ) && hash_equals( (string) $attempt['source_body_sha256'], (string) $audit['body_sha256'] ) ) {
				return array(
					'state'        => 'needs_attention',
					'reason'       => 'transport_failed',
					'receipt'      => $receipt,
					'last_attempt' => $attempt,
				); }
			return array(
				'state'        => 'check_needed',
				'reason'       => 'check_stale',
				'receipt'      => $receipt,
				'last_attempt' => $attempt,
			);
		}
		if ( ! is_array( $receipt ) ) {
			return array(
				'state'   => 'check_needed',
				'reason'  => 'not_checked',
				'receipt' => null,
			);
		}
		$current = hash_equals( (string) $receipt['policy_hash'], DirectoryReview::policy_hash( $directory ) ) && DirectoryReview::fresh( $directory );
		if ( $current ) {
			try {
				$audit = ( new AdminInspector() )->listing( (int) $receipt['listing_id'] ); } catch ( \InvalidArgumentException $error ) {
				return array(
					'state'   => 'check_needed',
					'reason'  => 'listing_unavailable',
					'receipt' => $receipt,
				); }
				$entry     = ( new Publication() )->discovery();
				$current   = $audit['published'] && hash_equals( (string) $receipt['source_body_sha256'], (string) $audit['body_sha256'] ) && 200 === $entry['status'] && hash_equals( (string) $receipt['entry_body_sha256'], hash( 'sha256', $entry['body'] ) );
				$targets   = array_column( $receipt['targets'], null, 'kind' );
				$discovery = ( new Publication() )->discovery( max( 0, $directory - 1 ) );
				$current   = $current && 200 === $discovery['status'] && hash_equals( (string) ( $targets['directory_discovery']['body_sha256'] ?? '' ), hash( 'sha256', $discovery['body'] ) );
				// Membership changes can affect the old directory without rotating its marker.
				$index   = ( new Publication() )->index( $directory, 1, $audit['index_cursor'] );
				$current = $current && 200 === $index['status'] && hash_equals( (string) ( $targets['index']['body_sha256'] ?? '' ), hash( 'sha256', $index['body'] ) );
		}
		return array(
			'state'   => ! $current ? 'check_needed' : ( $receipt['passed'] ? 'ready' : 'needs_attention' ),
			'reason'  => ! $current ? 'check_stale' : ( $receipt['passed'] ? 'checked' : 'findings_missing' ),
			'receipt' => $receipt,
		);
	}

	public static function save( int $directory, array $input ): array {
		if ( array_key_exists( 'failure', $input ) ) {
			return self::failure( $directory, $input ); }
		if ( strlen( serialize( $input ) ) > self::MAX_REQUEST_BYTES || array_diff( array_keys( $input ), array( 'listing_id', 'policy_hash', 'schema_hash', 'source_body_sha256', 'results' ) ) || ! is_int( $input['listing_id'] ?? null ) || ! is_array( $input['results'] ?? null ) || 5 !== count( $input['results'] ) ) {
			throw new \InvalidArgumentException( 'Run the complete check for a selected public listing.' );
		}
		$previous = DirectoryReview::record( $directory );
		$state    = DirectoryReview::state( $directory );
		$audit    = ( new AdminInspector() )->listing( $input['listing_id'] );
		foreach ( array(
			'policy_hash'        => $state['policy_hash'],
			'schema_hash'        => $state['schema_hash'],
			'source_body_sha256' => $audit['body_sha256'] ?? '',
		) as $key => $expected ) {
			if ( ! is_string( $input[ $key ] ?? null ) || ! hash_equals( $expected, $input[ $key ] ) ) {
				throw new \DomainException( 'Public facts or saved choices changed. Refresh and check again.' );
			}
		}
		if ( $state['reason'] || ! $audit['published'] || $audit['directory_id'] !== $directory ) {
			throw new \DomainException( 'This directory needs attention before its public output can be checked.' );
		}
		$generation  = DirectoryReview::generation( $directory );
		$publication = new Publication();
		$expected    = array(
			'directory_discovery' => array(
				'url'      => $audit['directory_discovery_url'],
				'response' => $publication->discovery( max( 0, $directory - 1 ) ),
			),
			'entry'               => array(
				'url'      => Publication::entry_url(),
				'response' => $publication->discovery(),
			),
			'index'               => array(
				'url'      => $audit['index_url'],
				'response' => $publication->index( $directory, 1, $audit['index_cursor'] ),
			),
			'listing'             => array(
				'url'      => $audit['listing_url'],
				'response' => $publication->listing( $audit['id'] ),
			),
			'canonical'           => array( 'url' => $audit['canonical_url'] ),
		);
		$findings    = array();
		$all_passed  = true;
		$entry_sha   = '';
		foreach ( $input['results'] as $result ) {
			if ( ! is_array( $result ) || array_diff( array_keys( $result ), array( 'kind', 'url', 'status', 'contentType', 'cache', 'link', 'body', 'checkedAt' ) ) || ! is_string( $result['kind'] ?? null ) || ! isset( $expected[ $result['kind'] ] ) || isset( $findings[ $result['kind'] ] ) || ( $result['url'] ?? '' ) !== $expected[ $result['kind'] ]['url'] || ! is_int( $result['status'] ?? null ) || $result['status'] < 100 || $result['status'] > 599 ) {
				throw new \InvalidArgumentException( 'Only the current native check targets can be recorded.' );
			}
			$kind = $result['kind'];
			foreach ( array(
				'body'        => 'canonical' === $kind ? 4194304 : Publication::MAX_DOCUMENT_BYTES,
				'contentType' => 200,
				'cache'       => 2000,
				'link'        => 8192,
				'checkedAt'   => 40,
			) as $key => $limit ) {
				if ( ! is_string( $result[ $key ] ?? null ) || strlen( $result[ $key ] ) > $limit || 1 !== preg_match( '//u', $result[ $key ] ) ) {
					throw new \InvalidArgumentException( 'The observed response is outside the safe check bounds.' );
				}
			}
			$observed = strtotime( $result['checkedAt'] );
			if ( ! $observed || abs( time() - $observed ) > 120 ) {
				throw new \DomainException( 'This observation is no longer current. Run the check again.' );
			}
			$checks = array( 'reachable' => 200 === $result['status'] );
			if ( 'canonical' === $kind ) {
				$checks['content_type']   = (bool) preg_match( '~^text/html(?:\s*;|$)~i', $result['contentType'] );
				$checks['listing_link']   = self::relation( $result['link'], $audit['listing_url'], 'alternate', $result['url'], 'text/markdown' ) || self::html_relation( $result['body'], $audit['listing_url'], 'alternate', $result['url'], 'text/markdown' );
				$checks['discovery_link'] = self::relation( $result['link'], Publication::entry_url(), 'describedby', $result['url'] ) || self::html_relation( $result['body'], Publication::entry_url(), 'describedby', $result['url'] );
			} else {
				$checks['content_type'] = (bool) preg_match( '~^text/markdown(?:\s*;|$)~i', $result['contentType'] );
				$checks['cache_policy'] = (bool) preg_match( '/(?:^|[,\s])no-store(?:$|[,\s])/i', $result['cache'] );
				$checks['current_body'] = 200 === $expected[ $kind ]['response']['status'] && hash_equals( hash( 'sha256', $expected[ $kind ]['response']['body'] ), hash( 'sha256', $result['body'] ) );
				if ( 'listing' === $kind ) {
					$checks['canonical_link'] = self::relation( $result['link'], $audit['canonical_url'], 'canonical', $result['url'] );
				} elseif ( 'index' === $kind ) {
					$checks['listing_link'] = str_contains( $result['body'], '](' . $audit['listing_url'] . ')' );
				} elseif ( 'directory_discovery' === $kind ) {
					$checks['directory_link'] = str_contains( $result['body'], '](' . Publication::directory_url( $directory ) . ')' );
				} else {
					$checks['directory_or_continuation'] = str_contains( $result['body'], '](' . Publication::directory_url( $directory ) . ')' ) || (bool) preg_match( '~\[More directories\]\(https?://[^\s)]+/directorist/discovery/after/[1-9][0-9]{0,9}\.md\)~', $result['body'] );
					$entry_sha                           = hash( 'sha256', $result['body'] );
				}
			}
			$passed            = count( array_filter( $checks ) );
			$all_passed        = $all_passed && count( $checks ) === $passed;
			$findings[ $kind ] = array(
				'kind'            => $kind,
				'url'             => $result['url'],
				'status'          => $result['status'],
				'passed'          => $passed,
				'not_established' => count( $checks ) - $passed,
				'checks'          => $checks,
				'observed_at'     => gmdate( DATE_RFC3339, $observed ),
				'body_sha256'     => hash( 'sha256', $result['body'] ),
			);
		}
		$final = ( new AdminInspector() )->listing( $audit['id'] );
		if ( ! $final['published'] || ! hash_equals( $audit['body_sha256'], $final['body_sha256'] ) || ! hash_equals( $state['policy_hash'], DirectoryReview::policy_hash( $directory ) ) || ! hash_equals( $generation, DirectoryReview::generation( $directory ) ) ) {
			throw new \DomainException( 'Public facts changed during the check. Refresh and try again.' );
		}
		$receipt = array(
			'version'            => 1,
			'evidence'           => 'browser_reported',
			'observation_id'     => bin2hex( random_bytes( 16 ) ),
			'directory_id'       => $directory,
			'listing_id'         => $audit['id'],
			'policy_hash'        => $state['policy_hash'],
			'schema_hash'        => $state['schema_hash'],
			'source_body_sha256' => $audit['body_sha256'],
			'generation'         => $generation,
			'entry_body_sha256'  => $entry_sha,
			'checked_at'         => gmdate( DATE_RFC3339 ),
			'checked_by'         => get_current_user_id(),
			'passed'             => $all_passed,
			'coverage'           => 'selected_listing_and_directory_discovery',
			'targets'            => array_values( $findings ),
		);
		$next    = array_merge( $previous, array( 'verification' => $receipt ) );
		unset( $next['last_attempt'] );
		DirectoryReview::replace( $directory, $previous, $next );
		return self::status( $directory );
	}
	private static function failure( int $directory, array $input ): array {
		$previous = DirectoryReview::record( $directory );
		if ( strlen( serialize( $input ) ) > 2048 || array_diff( array_keys( $input ), array( 'listing_id', 'policy_hash', 'schema_hash', 'source_body_sha256', 'expected_verification_token', 'failure' ) ) || ! is_int( $input['listing_id'] ?? null ) || ! is_array( $input['failure'] ?? null ) || array_diff( array_keys( $input['failure'] ), array( 'kind', 'code' ) ) || ! in_array( $input['failure']['kind'] ?? '', array( 'entry', 'directory_discovery', 'index', 'listing', 'canonical' ), true ) || ! in_array( $input['failure']['code'] ?? '', array( 'network', 'timeout', 'oversize', 'redirect', 'unsupported_browser' ), true ) ) {
			throw new \InvalidArgumentException( 'Only a bounded anonymous transport failure can be recorded.' );
		}
		if ( ! is_string( $input['expected_verification_token'] ?? null ) || ! hash_equals( self::token( $directory ), $input['expected_verification_token'] ) ) {
			throw new \DomainException( 'A newer check exists. This older failure was not recorded.' ); }
		$state = DirectoryReview::state( $directory );
		$audit = ( new AdminInspector() )->listing( $input['listing_id'] );
		foreach ( array(
			'policy_hash'        => $state['policy_hash'],
			'schema_hash'        => $state['schema_hash'],
			'source_body_sha256' => $audit['body_sha256'] ?? '',
		) as $key => $expected ) {
			if ( ! is_string( $input[ $key ] ?? null ) || ! hash_equals( $expected, $input[ $key ] ) ) {
				throw new \DomainException( 'Public facts changed. This older attempt was not recorded.' ); }
		}
		if ( $state['reason'] || ! $audit['published'] || $audit['directory_id'] !== $directory ) {
			throw new \DomainException( 'Current public facts are unavailable for this check.' ); }
		$urls    = array(
			'entry'               => Publication::entry_url(),
			'directory_discovery' => $audit['directory_discovery_url'],
			'index'               => $audit['index_url'],
			'listing'             => $audit['listing_url'],
			'canonical'           => $audit['canonical_url'],
		);
		$attempt = array(
			'version'            => 1,
			'evidence'           => 'browser_reported_failure',
			'observation_id'     => bin2hex( random_bytes( 16 ) ),
			'directory_id'       => $directory,
			'listing_id'         => $audit['id'],
			'policy_hash'        => $state['policy_hash'],
			'schema_hash'        => $state['schema_hash'],
			'source_body_sha256' => $audit['body_sha256'],
			'kind'               => $input['failure']['kind'],
			'code'               => $input['failure']['code'],
			'url'                => $urls[ $input['failure']['kind'] ],
			'checked_at'         => gmdate( DATE_RFC3339 ),
			'checked_by'         => get_current_user_id(),
			'coverage'           => 'selected_listing_and_directory_discovery',
		);
		$final   = ( new AdminInspector() )->listing( $audit['id'] );
		if ( ! $final['published'] || ! hash_equals( $audit['body_sha256'], $final['body_sha256'] ) || ! hash_equals( $state['policy_hash'], DirectoryReview::policy_hash( $directory ) ) ) {
			throw new \DomainException( 'Public facts changed. This older attempt was not recorded.' ); }
		DirectoryReview::replace( $directory, $previous, array_merge( $previous, array( 'last_attempt' => $attempt ) ) );
		return self::status( $directory );
	}
}
