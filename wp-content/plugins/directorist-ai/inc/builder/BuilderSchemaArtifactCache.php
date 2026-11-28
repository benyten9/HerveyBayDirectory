<?php
/** Detached, installation-signed JSON for pure native schema transformations. */
namespace DirectoristAIPro\Builder;

use DirectoristAIPro\Validation\JsonDecoder;

/** Inputs are freshly read native descriptors, never page content or authority. */
final class BuilderSchemaArtifactCache {

	private const FORMAT = 'builder-native-schema-v1';
	private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
	private const MAX_BYTES = 1048576;
	private static array $memory = array();
	private static array $counts = array( 'calls' => 0, 'builds' => 0, 'memory_hits' => 0, 'object_hits' => 0, 'file_hits' => 0, 'publications' => 0, 'fallbacks' => 0 );

	public static function diagnostics(): array { return self::$counts; }

	/**
	 * Cache only a pure transformation of the complete supplied native inputs.
	 * The installed adapter must freshly read those inputs before every call.
	 * Native compilation, current permissions and apply validation remain outside.
	 */
	public static function remember( string $slot, $inputs, array $sources, callable $build ): object {
		++self::$counts['calls'];
		$store = null;
		$locked = false;
		$before = null;
		try {
			if ( ! defined( 'DIRECTORIST_AI_NATIVE_SCHEMA_CACHE' ) || false !== DIRECTORIST_AI_NATIVE_SCHEMA_CACHE ) {
				$before = self::identity( $slot, $inputs, $sources );
			}
			if ( null !== $before ) {
				$store = new BuilderSchemaArtifactStore( $slot, $before['partition'] );
				foreach ( array( 'memory', 'object', 'file' ) as $backend ) {
					$raw = 'memory' === $backend ? ( self::$memory[ $before['key'] ] ?? null ) : ( 'object' === $backend ? $store->object_get( $before['revision'] ) : $store->file_get() );
					$record = is_string( $raw ) ? self::verify( $raw, $before ) : null;
					if ( null !== $record && $before === self::identity( $slot, $inputs, $sources ) ) {
						self::retain( $before['key'], $raw );
						if ( 'file' === $backend ) { $store->object_put( $before['revision'], $raw, $record->expires_at - time() ); }
						++self::$counts[ $backend . '_hits' ];
						return json_decode( $record->schema, false, 64, JSON_THROW_ON_ERROR );
					}
				}
				$locked = $store->acquire();
			}
		} catch ( \Throwable $error ) {
			$before = null;
		}
		++self::$counts['builds'];
		if ( null === $before ) { ++self::$counts['fallbacks']; }
		try {
			// Native exceptions are never swallowed or retried by optional caching.
			$result = $build();
			try {
				if ( null !== $before && $before === self::identity( $slot, $inputs, $sources ) ) {
					( new JsonDecoder( self::MAX_BYTES, 48 ) )->check( $result );
					$schema = json_encode( $result, self::FLAGS );
					$record = (object) array( 'format' => self::FORMAT, 'partition' => $before['partition'], 'revision' => $before['revision'], 'built_at' => time(), 'expires_at' => time() + DAY_IN_SECONDS, 'schema' => $schema, 'schema_sha256' => hash( 'sha256', $schema ) );
					$record->mac = self::sign( $record );
					$raw = json_encode( $record, self::FLAGS );
					self::retain( $before['key'], $raw );
					if ( $locked && $store->publish( $before['revision'], $raw ) ) { ++self::$counts['publications']; }
				}
			} catch ( \Throwable $error ) {
				// Optional certification or storage cannot alter the native result.
			}
			return $result;
		} finally {
			if ( $locked && null !== $store ) { $store->release(); }
		}
	}

	private static function identity( string $slot, $inputs, array $sources ): ?array {
		if ( ! preg_match( '/^[a-z][a-z0-9-]{0,95}$/D', $slot ) || ! $sources || has_filter( 'salt' ) || has_filter( 'all' ) ) { return null; }
		$nodes = 0;
		if ( ! self::plain( $inputs, 0, $nodes ) || strlen( json_encode( $inputs, self::FLAGS ) ) > 4194304 ) { return null; }
		$hashes = array( 'cache' => hash_file( 'sha256', __FILE__ ), 'store' => hash_file( 'sha256', __DIR__ . '/BuilderSchemaArtifactStore.php' ) );
		foreach ( $sources as $label => $file ) {
			if ( ! is_string( $label ) || ! is_string( $file ) || ! is_file( $file ) || ! is_readable( $file ) ) { return null; }
			$hashes[ $label ] = hash_file( 'sha256', $file );
		}
		if ( in_array( false, $hashes, true ) ) { return null; }
		$partition = BuilderSchemaArtifactStore::partition();
		$versions = array( 'wordpress' => $GLOBALS['wp_version'] ?? null );
		foreach ( array( 'ATBDP_VERSION', 'ELEMENTOR_VERSION', 'DIRECTORIST_GUTENBERG_VERSION', 'DIRECTORIST_ELEMENTOR_VERSION', 'DIRECTORIST_AI_PRO_VERSION' ) as $constant ) { $versions[ $constant ] = defined( $constant ) ? constant( $constant ) : null; }
		$revision = hash( 'sha256', json_encode( array( self::FORMAT, $slot, $partition, PHP_VERSION_ID, ini_get( 'precision' ), ini_get( 'serialize_precision' ), $GLOBALS['locale'] ?? null, $versions, $hashes, $inputs ), self::FLAGS ) );
		return array( 'partition' => $partition, 'revision' => $revision, 'key' => $slot . ':' . $partition . ':' . $revision );
	}

	private static function verify( string $raw, array $identity ): ?object {
		try {
			if ( strlen( $raw ) > 2 * self::MAX_BYTES ) { return null; }
			$r = json_decode( $raw, false, 64, JSON_THROW_ON_ERROR );
			if ( ! $r instanceof \stdClass || array_keys( get_object_vars( $r ) ) !== array( 'format', 'partition', 'revision', 'built_at', 'expires_at', 'schema', 'schema_sha256', 'mac' ) || self::FORMAT !== $r->format || $identity['partition'] !== $r->partition || $identity['revision'] !== $r->revision || ! is_int( $r->built_at ) || ! is_int( $r->expires_at ) || $r->built_at > time() || $r->expires_at <= time() || $r->expires_at <= $r->built_at || $r->expires_at - $r->built_at > DAY_IN_SECONDS || ! is_string( $r->schema ) || strlen( $r->schema ) > self::MAX_BYTES || ! is_string( $r->schema_sha256 ) || ! hash_equals( hash( 'sha256', $r->schema ), $r->schema_sha256 ) || ! is_string( $r->mac ) ) { return null; }
			$p = clone $r; unset( $p->mac );
			if ( ! hash_equals( self::sign( $p ), $r->mac ) ) { return null; }
			$schema = json_decode( $r->schema, false, 64, JSON_THROW_ON_ERROR );
			return $schema instanceof \stdClass && json_encode( $schema, self::FLAGS ) === $r->schema ? $r : null;
		} catch ( \Throwable $error ) { return null; }
	}

	private static function sign( object $record ): string { return hash_hmac( 'sha256', json_encode( $record, self::FLAGS ), wp_salt( 'auth' ) ); }

	/** Native PHP metadata includes associative maps; reject custom objects/references. */
	private static function plain( $value, int $depth, int &$nodes ): bool {
		if ( $depth > 48 || ++$nodes > 150000 ) { return false; }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( null !== \ReflectionReference::fromArrayElement( $value, $key ) || ! self::plain( $child, $depth + 1, $nodes ) ) { return false; }
			}
			return true;
		}
		if ( $value instanceof \stdClass && get_class( $value ) === \stdClass::class ) {
			foreach ( get_object_vars( $value ) as $child ) { if ( ! self::plain( $child, $depth + 1, $nodes ) ) { return false; } }
			return true;
		}
		return null === $value || is_string( $value ) || is_int( $value ) || is_bool( $value ) || is_float( $value ) && is_finite( $value );
	}

	private static function retain( string $key, string $raw ): void {
		if ( strlen( $raw ) > 2 * self::MAX_BYTES ) { return; }
		unset( self::$memory[ $key ] );
		while ( self::$memory && ( count( self::$memory ) >= 16 || array_sum( array_map( 'strlen', self::$memory ) ) + strlen( $raw ) > 4194304 ) ) { array_shift( self::$memory ); }
		self::$memory[ $key ] = $raw;
	}
}
