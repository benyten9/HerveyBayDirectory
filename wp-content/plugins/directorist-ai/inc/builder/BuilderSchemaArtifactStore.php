<?php
/**
 * Optional storage for signed native schema certificates.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Files.FileName,Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment -- Internal storage boundary invariants are documented inline.

use DirectoristAIPro\Storage\Database;

/** Storage is an accelerator. The caller verifies every returned certificate. */
final class BuilderSchemaArtifactStore {

	private const GROUP     = 'directorist_ai_native_schema';
	private const MAX_BYTES = 2097152;
	/** Dedicated connection owns the mutex until release or connection teardown. */
	private ?Database $db = null;
	/** Current connection-bound lock name. */
	private ?string $lock = null;
	/** Publication lease bounds slow/stalled native builds. */
	private int $until = 0;
	/** Opaque context used for both the mutex and the file slot. */
	private string $partition;
	private string $slot;

	/** Bind an opaque site/actor storage partition. */
	public function __construct( string $slot, string $partition ) {
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,95}$/D', $slot ) ) { throw new \InvalidArgumentException( 'Invalid native schema slot.' ); }
		$this->slot = $slot;
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $partition ) ) {
			throw new \InvalidArgumentException( 'Invalid native schema partition.' );
		}
		$this->partition = $partition;
	}

	/** Current site and actor are always resolved freshly, never from a certificate. */
	public static function partition(): string {
		global $wpdb;
		return hash_hmac( 'sha256', json_encode( array( $wpdb->dbname, $wpdb->prefix, get_current_blog_id(), get_current_user_id(), current_user_can( 'manage_options' ) ), JSON_THROW_ON_ERROR ), wp_salt( 'auth' ) );
	}

	/** Generated files never modify the packaged artifact or plugin source. */
	public static function directory(): string {
		return WP_CONTENT_DIR . '/cache/directorist-ai-schema';
	}

	/** One replaceable file slot per site/actor partition, independent of revisions. */
	public function path(): string {
		return self::directory() . '/' . $this->slot . '-' . $this->partition . '.json';
	}

	/** Read persistent WordPress cache only when a drop-in is configured. */
	public function object_get( string $revision ): ?string {
		try {
			if ( ! wp_using_ext_object_cache() ) {
				return null; }
			$found = false;
			$value = wp_cache_get( $this->key( $revision ), self::GROUP, false, $found );
			return $found && is_string( $value ) && strlen( $value ) <= self::MAX_BYTES ? $value : null;
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** A missing, unreadable, replaced or oversized file behaves as a cache miss. */
	public function file_get(): ?string {
		$path = $this->path();
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) || filesize( $path ) > self::MAX_BYTES ) {
			return null; }
		$value = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional file may disappear between stat and read.
		return is_string( $value ) && strlen( $value ) <= self::MAX_BYTES ? $value : null;
	}

	/** Populate only a certificate already verified by the caller. */
	public function object_put( string $revision, string $certificate, int $ttl ): bool {
		try {
			return strlen( $certificate ) <= self::MAX_BYTES && $ttl > 0 && wp_using_ext_object_cache() && wp_cache_set( $this->key( $revision ), $certificate, self::GROUP, min( DAY_IN_SECONDS, $ttl ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Coordinate builders across workers/nodes using the existing shared database.
	 *
	 * Admission never waits for another build. Contention uses native generation
	 * without publishing; only the connection owning this mutex may publish.
	 */
	public function acquire(): bool {
		try {
			if ( null !== $this->lock ) {
				return false; }
			global $wpdb;
			$this->db = new Database( $wpdb );
			$key      = 'dai-schema-' . substr( hash( 'sha256', $this->slot . $this->partition ), 0, 48 );
			if ( '1' !== (string) $this->db->scalar( 'SELECT GET_LOCK(%s,0)', array( $key ) ) ) {
				$this->db = null;
				return false;
			}
			$this->lock  = $key;
			$this->until = time() + 30;
			return true;
		} catch ( \Throwable $error ) {
			$this->db = null;
			return false;
		}
	}

	/** An expired build or lost database connection has no publication authority. */
	public function owns(): bool {
		try {
			return null !== $this->db && null !== $this->lock && $this->until > time() && '1' === (string) $this->db->scalar( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', array( $this->lock ) );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Atomically publish complete JSON; failed storage never changes native output. */
	public function publish( string $revision, string $certificate ): bool {
		if ( strlen( $certificate ) > self::MAX_BYTES || ! $this->owns() ) {
			return false; }
		$written   = false;
		$temporary = null;
		try {
			$directory = self::directory();
			if ( ! is_link( $directory ) && ( is_dir( $directory ) || wp_mkdir_p( $directory ) ) && is_writable( $directory ) ) {
				$temporary = $this->path() . '.' . bin2hex( random_bytes( 12 ) ) . '.tmp';
				$handle    = @fopen( $temporary, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional cache storage.
				if ( false !== $handle ) {
					try {
						$complete = strlen( $certificate ) === fwrite( $handle, $certificate ) && fflush( $handle );
					} finally {
						fclose( $handle );
					}
					if ( $complete && $this->owns() ) {
						@chmod( $temporary, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Host may control permissions.
						$written = @rename( $temporary, $this->path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Optional atomic cache replacement.
					}
				}
			}
		} catch ( \Throwable $error ) {
			$written = false;
		} finally {
			if ( null !== $temporary && is_file( $temporary ) ) {
				@unlink( $temporary ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Remove only this writer's incomplete file.
		}
		// Redis can still cache a proven result on a read-only filesystem.
		return $this->owns() && ( $this->object_put( $revision, $certificate, DAY_IN_SECONDS ) || $written );
	}

	/** Always release on the connection that acquired the mutex. */
	public function release(): void {
		try {
			if ( null !== $this->db && null !== $this->lock ) {
				$this->db->scalar( 'SELECT RELEASE_LOCK(%s)', array( $this->lock ) );
			}
		} catch ( \Throwable $error ) {
			// Dropping the dedicated connection also releases its advisory locks.
		} finally {
			$this->db    = null;
			$this->lock  = null;
			$this->until = 0;
		}
	}

	/** A revision change naturally misses old object-cache entries. */
	private function key( string $revision ): string {
		return 'native-v1:' . $this->slot . ':' . $this->partition . ':' . $revision;
	}
}
