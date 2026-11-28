<?php
/**
 * Atomic generation storage for effective builder catalogs.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Storage invariants are documented at each boundary.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Native sources remain authoritative; stale payloads are never returned. */
final class CatalogCacheStore {
	private const PREWARM_META  = 'catalog_prewarm_recent_v1';
	private const PREWARM_LIMIT = 32;
	private const PREWARM_BYTES = 32768;
	private const PREWARM_AGE   = 86400;
	private Database $db;
	private JsonDecoder $decoder;
	private EffectiveCatalogContract $contract;
	private int $site_id;

	public function __construct( Database $db, ?EffectiveCatalogContract $contract = null ) {
		$this->db       = $db;
		$this->decoder  = new JsonDecoder( 5242880, 32 );
		$this->contract = $contract ?? new EffectiveCatalogContract();
		$this->site_id  = get_current_blog_id();
	}

	/** Read only an atomically completed generation with the exact current key. */
	public function read( CatalogCacheContext $context ): array {
		$this->assert_site( $context );
		$rows = $this->db->rows(
			'SELECT h.state,h.failure_code,h.active_generation,h.active_key_hash,h.building_generation,h.building_key_hash,g.key_hash generation_key_hash,g.state generation_state,g.catalog_revision,g.payload_hash,g.payload_json FROM ' . $this->db->table( 'catalog_cache_heads' ) . ' h LEFT JOIN ' . $this->db->table( 'catalog_cache_generations' ) . ' g ON g.context_key=h.context_key AND g.generation=h.active_generation WHERE h.context_key=%s',
			array( $context->context_key() )
		);
		if ( ! $rows ) {
			return array(
				'state'        => 'cold',
				'generation'   => null,
				'catalog'      => null,
				'failure_code' => null,
			);
		}
		$row = $rows[0];
		if ( 'ready' === $row['state'] && null !== $row['active_generation'] && hash_equals( (string) $row['active_key_hash'], $context->key_hash() ) && 'complete' === $row['generation_state'] && is_string( $row['payload_json'] ) ) {
			$expected_payload_hash = $this->payload_hash( $context, (int) $row['active_generation'], $row['payload_json'] );
			if ( ! is_string( $row['generation_key_hash'] ) || ! hash_equals( $context->key_hash(), $row['generation_key_hash'] ) || ! is_string( $row['payload_hash'] ) || ! hash_equals( $expected_payload_hash, $row['payload_hash'] ) ) {
				$this->corrupt( $context, (int) $row['active_generation'] );
				return array(
					'state'        => 'unavailable',
					'generation'   => null,
					'catalog'      => null,
					'failure_code' => 'cache_corrupt',
				);
			}
			try {
				$catalog = $this->decoder->decode( $row['payload_json'] );
				if ( ! is_object( $catalog ) ) {
					throw new \UnexpectedValueException( 'Catalog payload is not an object.' );
				}
				$this->contract->validate( $catalog );
			} catch ( \Throwable $error ) {
				$this->corrupt( $context, (int) $row['active_generation'] );
				return array(
					'state'        => 'unavailable',
					'generation'   => null,
					'catalog'      => null,
					'failure_code' => 'cache_corrupt',
				);
			}
			if ( ! is_string( $row['catalog_revision'] ) || ! hash_equals( $catalog->revision, $row['catalog_revision'] ) || ! $this->valid_catalog( $context, $catalog ) ) {
				$this->corrupt( $context, (int) $row['active_generation'] );
				return array(
					'state'        => 'unavailable',
					'generation'   => null,
					'catalog'      => null,
					'failure_code' => 'cache_corrupt',
				);
			}
			$this->db->query( 'UPDATE ' . $this->db->table( 'catalog_cache_heads' ) . ' SET updated_at=UTC_TIMESTAMP() WHERE context_key=%s AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)', array( $context->context_key() ) );
			return array(
				'state'        => 'warm',
				'generation'   => (int) $row['active_generation'],
				'catalog'      => $catalog,
				'failure_code' => null,
			);
		}
		if ( null !== $row['active_generation'] ) {
			$this->db->query( 'UPDATE ' . $this->db->table( 'catalog_cache_heads' ) . " SET state='stale',updated_at=UTC_TIMESTAMP() WHERE context_key=%s AND active_generation=%d", array( $context->context_key(), (int) $row['active_generation'] ) );
			return array(
				'state'        => 'stale',
				'generation'   => (int) $row['active_generation'],
				'catalog'      => null,
				'failure_code' => $row['failure_code'],
			);
		}
		return array(
			'state'        => 'unavailable',
			'generation'   => null,
			'catalog'      => null,
			'failure_code' => $row['failure_code'],
		);
	}

	/** Start or resume one hidden generation while preserving any older active row. */
	public function begin( CatalogCacheContext $context ): int {
		$this->assert_site( $context );
		return $this->db->transaction(
			function ( Database $db ) use ( $context ): int {
				$heads       = $db->table( 'catalog_cache_heads' );
				$generations = $db->table( 'catalog_cache_generations' );
				if ( ! hash_equals( $context->invalidation_fingerprint(), $this->epoch_values( $db, $context->directory_id(), true ) ) ) {
					throw new StorageException( 'Catalog invalidation fence changed before generation start.' );
				}
				$rows = $db->rows( "SELECT * FROM $heads WHERE context_key=%s FOR UPDATE", array( $context->context_key() ) );
				if ( $rows && 'ready' === $rows[0]['state'] && null !== $rows[0]['active_generation'] && hash_equals( (string) $rows[0]['active_key_hash'], $context->key_hash() ) ) {
					return (int) $rows[0]['active_generation'];
				}
				$active     = $rows && null !== $rows[0]['active_generation'] ? (int) $rows[0]['active_generation'] : 0;
				$building   = $rows && null !== $rows[0]['building_generation'] ? (int) $rows[0]['building_generation'] : 0;
				$counter    = $rows ? (int) $rows[0]['generation_counter'] : 0;
				$stored_max = (int) $db->scalar( "SELECT COALESCE(MAX(generation),0) FROM $generations WHERE context_key=%s", array( $context->context_key() ) );
				$generation = max( $active, $building, $counter, $stored_max ) + 1;
				if ( $building ) {
					$db->query( "UPDATE $generations SET state='failed',payload_hash=NULL,payload_json=NULL,completed_at=UTC_TIMESTAMP() WHERE context_key=%s AND generation=%d AND state='building'", array( $context->context_key(), $building ) );
				}
				if ( ! $rows ) {
					$db->query( "INSERT INTO $heads (context_key,scope_hash,builder,document_kind,directory_id,editor_mode,generation_counter,active_generation,active_key_hash,building_generation,building_key_hash,state,failure_code,updated_at) VALUES (%s,%s,%s,%s,%d,%d,%d,NULL,NULL,%d,%s,'building',NULL,UTC_TIMESTAMP())", array( $context->context_key(), $context->scope_hash(), $context->builder(), $context->document_kind(), $context->directory_id() ?? 0, $context->editor_mode() ? 1 : 0, $generation, $generation, $context->key_hash() ) );
				} else {
					$db->query( "UPDATE $heads SET generation_counter=GREATEST(generation_counter,%d),building_generation=%d,building_key_hash=%s,state='building',failure_code=NULL,updated_at=UTC_TIMESTAMP() WHERE context_key=%s", array( $generation, $generation, $context->key_hash(), $context->context_key() ) );
				}
				$db->query( "INSERT INTO $generations (context_key,generation,key_hash,catalog_revision,payload_hash,state,payload_json,created_at,completed_at) VALUES (%s,%d,%s,NULL,NULL,'building',NULL,UTC_TIMESTAMP(),NULL)", array( $context->context_key(), $generation, $context->key_hash() ) );
				return $generation;
			}
		);
	}

	/** Publish payload and head in one transaction only for the current build fence. */
	public function publish( CatalogCacheContext $context, int $generation, object $catalog ): bool {
		$this->assert_site( $context );
		if ( $generation < 1 || ! $this->valid_catalog( $context, $catalog ) ) {
			throw new StorageException( 'Catalog generation result is invalid.' );
		}
		try {
			$payload = $this->contract->encode( $catalog );
		} catch ( \Throwable $error ) {
			throw new StorageException( 'Catalog generation result is invalid.' );
		}
		$payload_hash = $this->payload_hash( $context, $generation, $payload );
		return $this->db->transaction(
			function ( Database $db ) use ( $context, $generation, $catalog, $payload, $payload_hash ): bool {
				$heads       = $db->table( 'catalog_cache_heads' );
				$generations = $db->table( 'catalog_cache_generations' );
				if ( ! hash_equals( $context->invalidation_fingerprint(), $this->epoch_values( $db, $context->directory_id(), true ) ) ) {
					return false;
				}
				$rows = $db->rows( "SELECT building_generation,building_key_hash FROM $heads WHERE context_key=%s FOR UPDATE", array( $context->context_key() ) );
				if ( ! $rows || (int) $rows[0]['building_generation'] !== $generation || ! hash_equals( (string) $rows[0]['building_key_hash'], $context->key_hash() ) ) {
					return false;
				}
				$db->query( "UPDATE $generations SET catalog_revision=%s,payload_hash=%s,state='complete',payload_json=%s,completed_at=UTC_TIMESTAMP() WHERE context_key=%s AND generation=%d AND key_hash=%s AND state='building'", array( $catalog->revision, $payload_hash, $payload, $context->context_key(), $generation, $context->key_hash() ) );
				if ( 1 !== $db->affected() ) {
					return false;
				}
				$db->query( "UPDATE $heads SET active_generation=%d,active_key_hash=%s,building_generation=NULL,building_key_hash=NULL,state='ready',failure_code=NULL,updated_at=UTC_TIMESTAMP() WHERE context_key=%s", array( $generation, $context->key_hash(), $context->context_key() ) );
				return true;
			}
		);
	}

	/** Fence malformed stored JSON and make the exact context rebuildable. */
	private function corrupt( CatalogCacheContext $context, int $generation ): void {
		$this->db->transaction(
			function ( Database $db ) use ( $context, $generation ): void {
				$heads       = $db->table( 'catalog_cache_heads' );
				$generations = $db->table( 'catalog_cache_generations' );
				$rows        = $db->rows( "SELECT active_generation FROM $heads WHERE context_key=%s FOR UPDATE", array( $context->context_key() ) );
				if ( ! $rows || (int) $rows[0]['active_generation'] !== $generation ) {
					return;
				}
				$db->query( "UPDATE $generations SET state='failed',payload_hash=NULL,payload_json=NULL,completed_at=UTC_TIMESTAMP() WHERE context_key=%s AND generation=%d", array( $context->context_key(), $generation ) );
				$db->query( "UPDATE $heads SET active_generation=NULL,active_key_hash=NULL,state='unavailable',failure_code='cache_corrupt',updated_at=UTC_TIMESTAMP() WHERE context_key=%s", array( $context->context_key() ) );
			}
		);
	}

	/** Verify cached payload identity before returning it to a caller. */
	private function valid_catalog( CatalogCacheContext $context, object $catalog ): bool {
		return isset( $catalog->revision, $catalog->builder, $catalog->document_kind ) && is_string( $catalog->revision ) && preg_match( '/^[a-f0-9]{64}$/D', $catalog->revision ) && $catalog->builder === $context->builder() && $catalog->document_kind === $context->document_kind() && ( $catalog->directory_id ?? null ) === $context->directory_id() && ( $catalog->directory_revision ?? null ) === $context->directory_revision() && ( $catalog->editor_fingerprint ?? null ) === $context->editor_fingerprint();
	}

	/** Bind exact canonical bytes to the context and fingerprint key. */
	private function payload_hash( CatalogCacheContext $context, int $generation, string $payload ): string {
		return hash( 'sha256', $context->context_key() . ':' . $context->key_hash() . ':' . $generation . ':' . $payload );
	}

	/** Mark one current build unavailable without exposing its partial payload. */
	public function fail( CatalogCacheContext $context, int $generation, string $code ): bool {
		$this->assert_site( $context );
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code ) ) {
			$code = 'catalog_unavailable';
		}
		return $this->db->transaction(
			function ( Database $db ) use ( $context, $generation, $code ): bool {
				$heads       = $db->table( 'catalog_cache_heads' );
				$generations = $db->table( 'catalog_cache_generations' );
				$rows        = $db->rows( "SELECT active_generation,building_generation,building_key_hash FROM $heads WHERE context_key=%s FOR UPDATE", array( $context->context_key() ) );
				if ( ! $rows || (int) $rows[0]['building_generation'] !== $generation || ! hash_equals( (string) $rows[0]['building_key_hash'], $context->key_hash() ) ) {
					return false;
				}
				$db->query( "UPDATE $generations SET state='failed',payload_hash=NULL,payload_json=NULL,completed_at=UTC_TIMESTAMP() WHERE context_key=%s AND generation=%d AND state='building'", array( $context->context_key(), $generation ) );
				$state = null === $rows[0]['active_generation'] ? 'unavailable' : 'stale';
				$db->query( "UPDATE $heads SET building_generation=NULL,building_key_hash=NULL,state=%s,failure_code=%s,updated_at=UTC_TIMESTAMP() WHERE context_key=%s", array( $state, $code, $context->context_key() ) );
				return true;
			}
		);
	}

	/** Final delivery fence for epochs, key, generation and catalog revision. */
	public function current( CatalogCacheContext $context, int $generation, string $revision ): bool {
		$this->assert_site( $context );
		if ( $generation < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) {
			return false;
		}
		return $this->db->transaction(
			function ( Database $db ) use ( $context, $generation, $revision ): bool {
				if ( ! hash_equals( $context->invalidation_fingerprint(), $this->epoch_values( $db, $context->directory_id(), true ) ) ) {
					return false;
				}
				$heads       = $db->table( 'catalog_cache_heads' );
				$generations = $db->table( 'catalog_cache_generations' );
				$rows        = $db->rows( "SELECT h.state,h.active_generation,h.active_key_hash,g.state generation_state,g.catalog_revision FROM $heads h JOIN $generations g ON g.context_key=h.context_key AND g.generation=h.active_generation WHERE h.context_key=%s FOR UPDATE", array( $context->context_key() ) );
				return $rows && 'ready' === $rows[0]['state'] && (int) $rows[0]['active_generation'] === $generation && hash_equals( $context->key_hash(), $rows[0]['active_key_hash'] ) && 'complete' === $rows[0]['generation_state'] && hash_equals( $revision, $rows[0]['catalog_revision'] );
			}
		);
	}

	/** Fence all current builders after a broad plugin/theme/rule change. */
	public function invalidate_all( string $code = 'source_changed' ): int {
		$this->assert_site();
		return $this->bump_epoch( null );
	}

	/** Fence all actor scopes for one changed directory schema. */
	public function invalidate_directory( int $directory_id, string $code = 'directory_changed' ): int {
		$this->assert_site();
		if ( $directory_id < 1 ) {
			return 0;
		}
		return $this->bump_epoch( $directory_id );
	}

	/** Current O(1) invalidation fence included in every catalog key. */
	public function epoch_fingerprint( ?int $directory_id ): string {
		$this->assert_site();
		return $this->epoch_values( $this->db, $directory_id, false );
	}

	/** Reject switched-blog access before a bound connection can touch its original tables. */
	private function assert_site( ?CatalogCacheContext $context = null ): void {
		$current = get_current_blog_id();
		if ( $current !== $this->site_id || ( null !== $context && $context->site_id() !== $this->site_id ) ) {
			throw new StorageException( 'Catalog cache connection is bound to another site.' );
		}
	}

	/** Atomically advance one small meta row; lock order is global then directory. */
	private function bump_epoch( ?int $directory_id ): int {
		return $this->db->transaction(
			function ( Database $db ) use ( $directory_id ): int {
				$table = $db->table( 'meta' );
				$this->epoch_values( $db, $directory_id, true );
				$name = null === $directory_id ? 'catalog_global_epoch' : 'catalog_directory_epoch_' . $directory_id;
				$db->query( "UPDATE $table SET value=CAST(value AS UNSIGNED)+1 WHERE name=%s", array( $name ) );
				return (int) $db->scalar( "SELECT value FROM $table WHERE name=%s", array( $name ) );
			}
		);
	}

	/** Read or lock global then directory epochs in one stable order. */
	private function epoch_values( Database $db, ?int $directory_id, bool $lock ): string {
		$table = $db->table( 'meta' );
		if ( $lock ) {
			$db->query( "INSERT IGNORE INTO $table (name,value) VALUES ('catalog_global_epoch','0')" );
		}
		$suffix    = $lock ? ' FOR UPDATE' : '';
		$global    = (int) $db->scalar( "SELECT value FROM $table WHERE name='catalog_global_epoch'" . $suffix );
		$directory = 0;
		if ( null !== $directory_id ) {
			$name = 'catalog_directory_epoch_' . $directory_id;
			if ( $lock ) {
				$db->query( "INSERT IGNORE INTO $table (name,value) VALUES (%s,'0')", array( $name ) );
			}
			$directory = (int) $db->scalar( "SELECT value FROM $table WHERE name=%s" . $suffix, array( $name ) );
		}
		return hash( 'sha256', $global . ':' . $directory );
	}

	/** Remember bounded private coordinates, never a catalog/editor payload or authority grant. */
	public function remember_prewarm( array $descriptor, int $now ): void {
		$this->assert_site();
		if ( ! $this->valid_prewarm_descriptor( $descriptor ) ) {
			throw new StorageException( 'Catalog prewarm coordinates are invalid.' );
		}
		$this->db->transaction(
			function ( Database $db ) use ( $descriptor, $now ): void {
				$state = $this->prewarm_state( $db, $now );
				$key   = $descriptor['context_key'];
				$old   = $state['contexts'][ $key ] ?? null;
				if ( $old && $old['descriptor'] === $descriptor && $old['last_used'] > $now - 900 ) {
					return;
				}
				$state['contexts'][ $key ] = array(
					'descriptor' => $descriptor,
					'last_used'  => $now,
					'dirty'      => (bool) ( $old['dirty'] ?? false ),
				);
				uasort( $state['contexts'], static fn( array $left, array $right ): int => $right['last_used'] <=> $left['last_used'] );
				$state['contexts'] = array_slice( $state['contexts'], 0, self::PREWARM_LIMIT, true );
				$this->write_prewarm_state( $db, $state );
			}
		);
	}

	/** One opaque ticket coalesces a configuration burst across owners. */
	public function request_prewarm( ?int $directory_id, int $now ): ?string {
		$this->assert_site();
		return $this->db->transaction(
			function ( Database $db ) use ( $directory_id, $now ): ?string {
				$state = $this->prewarm_state( $db, $now );
				foreach ( $state['contexts'] as &$entry ) {
					if ( null === $directory_id || $entry['descriptor']['directory_id'] === $directory_id ) {
						$entry['dirty'] = true;
					}
				}
				unset( $entry );
				$ticket = $this->prewarm_ticket( $state );
				$this->write_prewarm_state( $db, $state );
				return $ticket;
			}
		);
	}

	/** Claim one context only; duplicate/stale actions cannot perform work. */
	public function claim_prewarm( string $ticket, int $now ): ?array {
		$this->assert_site();
		return $this->db->transaction(
			function ( Database $db ) use ( $ticket, $now ): ?array {
				$state = $this->prewarm_state( $db, $now );
				if ( ! preg_match( '/^[a-f0-9]{32}$/D', $ticket ) || $state['ticket'] !== $ticket ) {
					return null;
				}
				if ( null !== $state['lease'] && $state['lease']['until'] > $now ) {
					$state['ticket'] = bin2hex( random_bytes( 16 ) );
					$this->write_prewarm_state( $db, $state );
					return array(
						'retry_ticket' => $state['ticket'],
						'retry_at'     => $state['lease']['until'] + 1,
					);
				}
				$state['ticket'] = null;
				foreach ( $state['contexts'] as $key => &$entry ) {
					if ( ! $entry['dirty'] ) {
						continue;
					}
					$entry['dirty'] = false;
					$state['lease'] = array(
						'token'       => bin2hex( random_bytes( 16 ) ),
						'until'       => $now + 120,
						'context_key' => $key,
					);
					$result         = array(
						'descriptor'  => $entry['descriptor'],
						'lease_token' => $state['lease']['token'],
					);
					unset( $entry );
					$this->write_prewarm_state( $db, $state );
					return $result;
				}
				unset( $entry );
				$state['lease'] = null;
				$this->write_prewarm_state( $db, $state );
				return null;
			}
		);
	}

	/** Release only the current claim; invalidations during its work stay pending. */
	public function finish_prewarm( string $token, int $now, bool $forget = false ): ?string {
		$this->assert_site();
		return $this->db->transaction(
			function ( Database $db ) use ( $token, $now, $forget ): ?string {
				$state = $this->prewarm_state( $db, $now );
				if ( null === $state['lease'] || ! hash_equals( $state['lease']['token'], $token ) ) {
					return null;
				}
				if ( $forget ) {
					unset( $state['contexts'][ $state['lease']['context_key'] ] );
				}
				$state['lease']  = null;
				$state['ticket'] = null;
				$ticket          = $this->prewarm_ticket( $state );
				$this->write_prewarm_state( $db, $state );
				return $ticket;
			}
		);
	}

	private function prewarm_ticket( array &$state ): ?string {
		foreach ( $state['contexts'] as $entry ) {
			if ( $entry['dirty'] ) {
				$state['ticket'] = $state['ticket'] ?? bin2hex( random_bytes( 16 ) );
				return $state['ticket'];
			}
		}
		$state['ticket'] = null;
		return null;
	}

	/** All metadata transitions lock one small row, independently of catalog generations. */
	private function prewarm_state( Database $db, int $now ): array {
		$table = $db->table( 'meta' );
		$db->query( "INSERT IGNORE INTO $table (name,value) VALUES (%s,'{}')", array( self::PREWARM_META ) );
		$json  = $db->scalar( "SELECT value FROM $table WHERE name=%s FOR UPDATE", array( self::PREWARM_META ) );
		$empty = array(
			'contexts' => array(),
			'ticket'   => null,
			'lease'    => null,
		);
		if ( ! is_string( $json ) || strlen( $json ) > self::PREWARM_BYTES ) {
			return $empty;
		}
		try {
			$stored = json_decode( $json, true, 12, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $error ) {
			return $empty;
		}
		if ( ! is_array( $stored ) || ! is_array( $stored['contexts'] ?? null ) || count( $stored['contexts'] ) > self::PREWARM_LIMIT ) {
			return $empty;
		}
		$state = $empty;
		foreach ( $stored['contexts'] as $key => $entry ) {
			if ( is_array( $entry ) && is_array( $entry['descriptor'] ?? null ) && $this->valid_prewarm_descriptor( $entry['descriptor'] ) && $key === $entry['descriptor']['context_key'] && is_int( $entry['last_used'] ?? null ) && $entry['last_used'] >= $now - self::PREWARM_AGE && $entry['last_used'] <= $now && is_bool( $entry['dirty'] ?? null ) ) {
				$state['contexts'][ $key ] = $entry;
			}
		}
		if ( is_string( $stored['ticket'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $stored['ticket'] ) ) {
			$state['ticket'] = $stored['ticket'];
		}
		$lease = $stored['lease'] ?? null;
		if ( is_array( $lease ) && is_string( $lease['token'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $lease['token'] ) && is_int( $lease['until'] ?? null ) && $lease['until'] > $now && $lease['until'] <= $now + 120 && is_string( $lease['context_key'] ?? null ) ) {
			$state['lease'] = $lease;
		}
		return $state;
	}

	private function valid_prewarm_descriptor( array $descriptor ): bool {
		$keys     = array_keys( $descriptor );
		$expected = array( 'site_id', 'owner_id', 'profile', 'builder', 'document_kind', 'directory_id', 'scope_hash', 'context_key' );
		sort( $keys );
		sort( $expected );
		return $keys === $expected && $descriptor['site_id'] === $this->site_id && is_int( $descriptor['owner_id'] ) && $descriptor['owner_id'] > 0 && in_array( $descriptor['profile'], array( 'admin', 'builder' ), true ) && in_array( $descriptor['builder'], array( 'gutenberg', 'elementor', 'divi' ), true ) && is_string( $descriptor['document_kind'] ) && ProviderRegistration::valid_token( $descriptor['document_kind'] ) && ( null === $descriptor['directory_id'] || ( is_int( $descriptor['directory_id'] ) && $descriptor['directory_id'] > 0 ) ) && is_string( $descriptor['scope_hash'] ) && preg_match( '/^[a-f0-9]{64}$/D', $descriptor['scope_hash'] ) && is_string( $descriptor['context_key'] ) && preg_match( '/^[a-f0-9]{64}$/D', $descriptor['context_key'] );
	}

	private function write_prewarm_state( Database $db, array $state ): void {
		$json = json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		if ( strlen( $json ) > self::PREWARM_BYTES ) {
			throw new StorageException( 'Catalog prewarm coordinates exceed their bounded storage.' );
		}
		$db->query( 'UPDATE ' . $db->table( 'meta' ) . ' SET value=%s WHERE name=%s', array( $json, self::PREWARM_META ) );
	}

	/** Remove only inactive generations; heads and current builds are preserved. */
	public function prune( int $limit = 100 ): int {
		$this->assert_site();
		$limit       = max( 1, min( 1000, $limit ) );
		$generations = $this->db->table( 'catalog_cache_generations' );
		$heads       = $this->db->table( 'catalog_cache_heads' );
		$expired     = $this->db->transaction(
			function ( Database $db ) use ( $heads, $generations, $limit ): int {
				$rows = $db->rows( "SELECT context_key FROM $heads WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY updated_at LIMIT $limit FOR UPDATE" );
				foreach ( $rows as $row ) {
					$db->query( "DELETE FROM $generations WHERE context_key=%s", array( $row['context_key'] ) );
					$db->query( "DELETE FROM $heads WHERE context_key=%s", array( $row['context_key'] ) );
				}
				return count( $rows );
			}
		);
		$this->db->query( "DELETE FROM $generations WHERE completed_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) AND NOT EXISTS (SELECT 1 FROM $heads h WHERE h.context_key=$generations.context_key AND (h.active_generation=$generations.generation OR h.building_generation=$generations.generation)) LIMIT $limit" );
		return $expired + $this->db->affected();
	}
}
