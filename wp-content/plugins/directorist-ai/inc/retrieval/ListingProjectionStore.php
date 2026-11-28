<?php
/**
 * Generation-controlled listing search projection storage.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Retrieval;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Storage invariants are documented at each boundary.

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Storage\StorageException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Native state remains authoritative; projections are rebuildable and fail stale. */
final class ListingProjectionStore {
	/** Owned database connection. @var Database */
	private Database $db;
	/** Bounded JSON guard. @var JsonDecoder */
	private JsonDecoder $decoder;

	/** Bind owned storage. */
	public function __construct( Database $db ) {
		$this->db      = $db;
		$this->decoder = new JsonDecoder( 1048576, 24 );
	}

	/**
	 * Invalidate every cached scope for a native source and advance its fence.
	 *
	 * @param int    $listing_id Native listing ID.
	 * @param bool   $public_eligible Current public eligibility.
	 * @param string $source_hash Exact current native source hash.
	 * @return array{epoch:int,public_eligible:bool,source_hash:string}
	 */
	public function touch( int $listing_id, bool $public_eligible, string $source_hash ): array {
		$this->listing_id( $listing_id );
		$this->hash( $source_hash );
		return $this->db->transaction(
			function ( Database $db ) use ( $listing_id, $public_eligible, $source_hash ): array {
				$table = $db->table( 'projection_sources' );
				$db->query(
					"INSERT INTO $table (listing_id,epoch,public_eligible,source_hash,updated_at) VALUES (%d,1,%d,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE epoch=epoch+1,public_eligible=VALUES(public_eligible),source_hash=VALUES(source_hash),updated_at=UTC_TIMESTAMP()",
					array( $listing_id, $public_eligible ? 1 : 0, $source_hash )
				);
				$row = $db->rows( "SELECT epoch,public_eligible,source_hash FROM $table WHERE listing_id=%d FOR UPDATE", array( $listing_id ) )[0];
				return array( 'epoch' => (int) $row['epoch'], 'public_eligible' => (bool) $row['public_eligible'], 'source_hash' => $row['source_hash'] );
			}
		);
	}

	/** Read the current source fence, or null before first observation. */
	public function source( int $listing_id ): ?array {
		$this->listing_id( $listing_id );
		$rows = $this->db->rows( 'SELECT epoch,public_eligible,source_hash FROM ' . $this->db->table( 'projection_sources' ) . ' WHERE listing_id=%d', array( $listing_id ) );
		return $rows ? array( 'epoch' => (int) $rows[0]['epoch'], 'public_eligible' => (bool) $rows[0]['public_eligible'], 'source_hash' => $rows[0]['source_hash'] ) : null;
	}

	/**
	 * Start or resume one hidden generation without replacing the active head.
	 *
	 * @param string $scope_key Trusted actor/dataset scope.
	 * @param int    $directory_id Directory identity.
	 * @param string $catalog_revision Current logical catalog revision.
	 */
	public function begin( string $scope_key, int $directory_id, string $catalog_revision ): int {
		$this->scope( $scope_key );
		$this->listing_id( $directory_id );
		$this->hash( $catalog_revision );
		return $this->db->transaction(
			function ( Database $db ) use ( $scope_key, $directory_id, $catalog_revision ): int {
				$table = $db->table( 'projection_heads' );
				$rows  = $db->rows( "SELECT * FROM $table WHERE scope_key=%s AND directory_id=%d FOR UPDATE", array( $scope_key, $directory_id ) );
				if ( $rows && 'building' === $rows[0]['state'] && hash_equals( $rows[0]['building_catalog_revision'], $catalog_revision ) ) {
					return (int) $rows[0]['building_generation'];
				}
				$active     = $rows && null !== $rows[0]['active_generation'] ? (int) $rows[0]['active_generation'] : 0;
				$building   = $rows && null !== $rows[0]['building_generation'] ? (int) $rows[0]['building_generation'] : 0;
				$generation = max( $active, $building ) + 1;
				$db->query(
					"INSERT INTO $table (scope_key,directory_id,active_generation,building_generation,active_catalog_revision,building_catalog_revision,state,cursor_id,updated_at) VALUES (%s,%d,NULL,%d,NULL,%s,'building',0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE building_generation=VALUES(building_generation),building_catalog_revision=VALUES(building_catalog_revision),state='building',cursor_id=0,updated_at=UTC_TIMESTAMP()",
					array( $scope_key, $directory_id, $generation, $catalog_revision )
				);
				return $generation;
			}
		);
	}

	/** Stage one entry only while its source fence and building generation remain current. */
	public function stage( string $scope_key, int $directory_id, int $generation, int $listing_id, int $source_epoch, string $source_hash, object $facts, bool $eligible = true ): bool {
		$this->scope( $scope_key );
		$this->listing_id( $directory_id );
		$this->listing_id( $listing_id );
		$this->positive( $generation );
		$this->positive( $source_epoch );
		$this->hash( $source_hash );
		$this->decoder->check( $facts );
		$facts_json   = json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		$content_hash = $this->content_hash( $facts_json );
		return $this->db->transaction(
			function ( Database $db ) use ( $scope_key, $directory_id, $generation, $listing_id, $source_epoch, $source_hash, $facts_json, $content_hash, $eligible ): bool {
				$heads   = $db->table( 'projection_heads' );
				$sources = $db->table( 'projection_sources' );
				$entries = $db->table( 'projection_entries' );
				$current = $db->rows( "SELECT h.building_generation,h.state,s.epoch,s.source_hash FROM $heads h JOIN $sources s ON s.listing_id=%d WHERE h.scope_key=%s AND h.directory_id=%d FOR UPDATE", array( $listing_id, $scope_key, $directory_id ) );
				if ( ! $current || 'building' !== $current[0]['state'] || (int) $current[0]['building_generation'] !== $generation || (int) $current[0]['epoch'] !== $source_epoch || ! hash_equals( $current[0]['source_hash'], $source_hash ) ) {
					return false;
				}
				$db->query(
					"INSERT INTO $entries (scope_key,directory_id,generation,listing_id,source_epoch,source_hash,content_hash,eligible,facts_json,updated_at) VALUES (%s,%d,%d,%d,%d,%s,%s,%d,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_epoch=VALUES(source_epoch),source_hash=VALUES(source_hash),content_hash=VALUES(content_hash),eligible=VALUES(eligible),facts_json=VALUES(facts_json),updated_at=UTC_TIMESTAMP()",
					array( $scope_key, $directory_id, $generation, $listing_id, $source_epoch, $source_hash, $content_hash, $eligible ? 1 : 0, $facts_json )
				);
				return true;
			}
		);
	}

	/** Advance a resumable rebuild cursor monotonically. */
	public function checkpoint( string $scope_key, int $directory_id, int $generation, int $cursor_id ): bool {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$this->positive( $generation );
		if ( $cursor_id < 0 ) {
			throw new StorageException( 'Invalid projection cursor.' );
		}
		$heads = $this->db->table( 'projection_heads' );
		$this->db->query( "UPDATE $heads SET cursor_id=GREATEST(cursor_id,%d),updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND directory_id=%d AND state='building' AND building_generation=%d", array( $cursor_id, $scope_key, $directory_id, $generation ) );
		return 1 === $this->db->affected();
	}

	/** Atomically make a complete generation visible. */
	public function publish( string $scope_key, int $directory_id, int $generation ): bool {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$this->positive( $generation );
		$heads = $this->db->table( 'projection_heads' );
		$this->db->query( "UPDATE $heads SET active_generation=building_generation,active_catalog_revision=building_catalog_revision,building_generation=NULL,building_catalog_revision=NULL,state='active',updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND directory_id=%d AND state='building' AND building_generation=%d", array( $scope_key, $directory_id, $generation ) );
		return 1 === $this->db->affected();
	}

	/** Upsert one freshly hydrated record into every matching visible or building generation. */
	public function refresh_current( string $scope_key, int $directory_id, int $listing_id, int $source_epoch, string $source_hash, ?string $catalog_revision, object $facts, bool $eligible = true ): bool {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$this->listing_id( $listing_id );
		$this->positive( $source_epoch );
		$this->hash( $source_hash );
		if ( null !== $catalog_revision ) {
			$this->hash( $catalog_revision );
		}
		$this->decoder->check( $facts );
		$facts_json   = json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		$content_hash = $this->content_hash( $facts_json );
		return $this->db->transaction(
			function ( Database $db ) use ( $scope_key, $directory_id, $listing_id, $source_epoch, $source_hash, $catalog_revision, $facts_json, $content_hash, $eligible ): bool {
				$heads   = $db->table( 'projection_heads' );
				$sources = $db->table( 'projection_sources' );
				$entries = $db->table( 'projection_entries' );
				$current = $db->rows( "SELECT h.active_generation,h.building_generation,h.active_catalog_revision,h.building_catalog_revision,h.state,s.epoch,s.source_hash FROM $heads h JOIN $sources s ON s.listing_id=%d WHERE h.scope_key=%s AND h.directory_id=%d FOR UPDATE", array( $listing_id, $scope_key, $directory_id ) );
				if ( ! $current || ! in_array( $current[0]['state'], array( 'active', 'building' ), true ) || (int) $current[0]['epoch'] !== $source_epoch || ! hash_equals( $current[0]['source_hash'], $source_hash ) ) {
					return false;
				}
				$generations = array();
				foreach ( array( 'active', 'building' ) as $kind ) {
					$generation = $current[0][ $kind . '_generation' ];
					$revision   = $current[0][ $kind . '_catalog_revision' ];
					if ( null !== $generation && ( null === $catalog_revision || ( null !== $revision && hash_equals( $revision, $catalog_revision ) ) ) ) {
						$generations[] = (int) $generation;
					}
				}
				foreach ( array_unique( $generations ) as $generation ) {
					$db->query(
						"INSERT INTO $entries (scope_key,directory_id,generation,listing_id,source_epoch,source_hash,content_hash,eligible,facts_json,updated_at) VALUES (%s,%d,%d,%d,%d,%s,%s,%d,%s,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE source_epoch=VALUES(source_epoch),source_hash=VALUES(source_hash),content_hash=VALUES(content_hash),eligible=VALUES(eligible),facts_json=VALUES(facts_json),updated_at=UTC_TIMESTAMP()",
						array( $scope_key, $directory_id, $generation, $listing_id, $source_epoch, $source_hash, $content_hash, $eligible ? 1 : 0, $facts_json )
					);
				}
				if ( ! $generations ) {
					return false;
				}
				return true;
			}
		);
	}

	/** Return only active entries whose source fence still matches current native state. */
	public function active( string $scope_key, int $directory_id, string $catalog_revision, int $after_id = 0, int $limit = 1000 ): array {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$this->hash( $catalog_revision );
		if ( $after_id < 0 || $limit < 1 || $limit > 1000 ) {
			throw new StorageException( 'Invalid projection read bound.' );
		}
		$heads      = $this->db->table( 'projection_heads' );
		$entries    = $this->db->table( 'projection_entries' );
		$sources    = $this->db->table( 'projection_sources' );
		$public_sql = 'public' === $scope_key ? ' AND s.public_eligible=1' : '';
		$rows       = $this->db->rows(
			"SELECT e.listing_id,e.source_epoch,e.source_hash,e.content_hash,e.facts_json FROM $heads h JOIN $entries e ON e.scope_key=h.scope_key AND e.directory_id=h.directory_id AND e.generation=h.active_generation JOIN $sources s ON s.listing_id=e.listing_id AND s.epoch=e.source_epoch AND s.source_hash=e.source_hash WHERE h.scope_key=%s AND h.directory_id=%d AND h.state IN ('active','building') AND h.active_catalog_revision=%s AND e.eligible=1$public_sql AND e.listing_id>%d ORDER BY e.listing_id LIMIT %d",
			array( $scope_key, $directory_id, $catalog_revision, $after_id, $limit )
		);
		return array_map(
			static fn( array $row ): array => array( 'listing_id' => (int) $row['listing_id'], 'source_epoch' => (int) $row['source_epoch'], 'source_hash' => $row['source_hash'], 'content_hash' => $row['content_hash'], 'facts' => json_decode( $row['facts_json'], false, 24, JSON_THROW_ON_ERROR ) ),
			$rows
		);
	}

	/** Inspect a projection head for resumable workers and diagnostics. */
	public function head( string $scope_key, int $directory_id ): ?array {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$rows = $this->db->rows( 'SELECT active_generation,building_generation,active_catalog_revision,building_catalog_revision,state,cursor_id FROM ' . $this->db->table( 'projection_heads' ) . ' WHERE scope_key=%s AND directory_id=%d', array( $scope_key, $directory_id ) );
		if ( ! $rows ) {
			return null;
		}
		return array( 'active_generation' => null === $rows[0]['active_generation'] ? null : (int) $rows[0]['active_generation'], 'building_generation' => null === $rows[0]['building_generation'] ? null : (int) $rows[0]['building_generation'], 'active_catalog_revision' => $rows[0]['active_catalog_revision'], 'building_catalog_revision' => $rows[0]['building_catalog_revision'], 'state' => $rows[0]['state'], 'cursor_id' => (int) $rows[0]['cursor_id'] );
	}

	/** Immediately hide an obsolete catalog and fence its in-progress generation. */
	public function expire_catalog( string $scope_key, int $directory_id ): bool {
		$this->scope( $scope_key );
		$this->positive( $directory_id );
		$heads = $this->db->table( 'projection_heads' );
		$this->db->query( "UPDATE $heads SET active_catalog_revision=NULL,building_generation=NULL,building_catalog_revision=NULL,state='stale',cursor_id=0,updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND directory_id=%d", array( $scope_key, $directory_id ) );
		return 1 === $this->db->affected();
	}

	/** Return the bounded scopes currently known for one directory. */
	public function scopes( int $directory_id ): array {
		$this->positive( $directory_id );
		$rows = $this->db->rows( 'SELECT scope_key FROM ' . $this->db->table( 'projection_heads' ) . ' WHERE directory_id=%d ORDER BY scope_key LIMIT 1000', array( $directory_id ) );
		return array_map( static fn( array $row ): string => $row['scope_key'], $rows );
	}

	/** Remove generations that are neither active nor currently building. */
	public function prune( int $limit = 1000 ): int {
		if ( $limit < 1 || $limit > 5000 ) {
			throw new StorageException( 'Invalid projection prune bound.' );
		}
		$entries = $this->db->table( 'projection_entries' );
		$heads   = $this->db->table( 'projection_heads' );
		$rows    = $this->db->rows( "SELECT e.scope_key,e.directory_id,e.generation,e.listing_id FROM $entries e LEFT JOIN $heads h ON h.scope_key=e.scope_key AND h.directory_id=e.directory_id AND (h.active_generation=e.generation OR h.building_generation=e.generation) WHERE h.scope_key IS NULL ORDER BY e.scope_key,e.directory_id,e.generation,e.listing_id LIMIT %d", array( $limit ) );
		$deleted = 0;
		foreach ( $rows as $row ) {
			$this->db->query( "DELETE FROM $entries WHERE scope_key=%s AND directory_id=%d AND generation=%d AND listing_id=%d", array( $row['scope_key'], (int) $row['directory_id'], (int) $row['generation'], (int) $row['listing_id'] ) );
			$deleted += $this->db->affected();
		}
		return $deleted;
	}

	/** Validate an internal projection scope. */
	private function scope( string $scope_key ): void {
		if ( 'public' !== $scope_key && ! preg_match( '/^user:[1-9][0-9]{0,19}:(own|management)$/D', $scope_key ) ) {
			throw new StorageException( 'Invalid projection scope.' );
		}
	}

	/** Validate a positive database identity. */
	private function listing_id( int $value ): void {
		$this->positive( $value );
	}

	/** Validate a positive integer. */
	private function positive( int $value ): void {
		if ( $value < 1 ) {
			throw new StorageException( 'Invalid projection identity.' );
		}
	}

	/** Validate a SHA-256 binding. */
	private function hash( string $value ): void {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new StorageException( 'Invalid projection hash.' );
		}
	}

	/** Hash logical facts without the intentionally volatile observation timestamp. */
	private function content_hash( string $facts_json ): string {
		$value = json_decode( $facts_json, false, 24, JSON_THROW_ON_ERROR );
		if ( isset( $value->fields ) && is_array( $value->fields ) ) {
			foreach ( $value->fields as $field ) {
				if ( isset( $field->source ) && is_object( $field->source ) ) {
					unset( $field->source->observed_at );
				}
			}
		}
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION ) );
	}
}
