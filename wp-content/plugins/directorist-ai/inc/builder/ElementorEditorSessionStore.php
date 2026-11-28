<?php
/**
 * Atomic, site-local authority for one native Elementor editor opening.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Builder;

// phpcs:disable Squiz.Commenting.FunctionComment,Squiz.Commenting.FunctionCommentThrowTag,Squiz.Commenting.VariableComment,WordPress.PHP.YodaConditions.NotYoda -- Compact bounded storage boundary.

use DirectoristAIPro\Validation\ContractException;
use DirectoristAIPro\Validation\JsonDecoder;

/** Only the current pointer can authorize a capture or a close. */
final class ElementorEditorSessionStore {
	private const RECORD_PREFIX     = 'dai_el_session_v2_';
	private const CURRENT_PREFIX    = 'dai_el_current_v2_';
	private const MAX_RECORD_BYTES  = 4096;
	private const MAX_POINTER_BYTES = 2048;
	private const MAX_GENERATION    = 10000;
	private \wpdb $db;
	private bool $checked_unique_index = false;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->db = $db ?? $wpdb;
	}

	/** Publish an immutable opening before its pointer. The replaced opening is never authoritative again. */
	public function open( array $record ): array {
		$this->validate_record( $record );
		$record_name = $this->record_name( $record['session_hash'] );
		$record_wire = $this->encode( $record, self::MAX_RECORD_BYTES );
		if ( ! $this->insert( $record_name, $record_wire ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session is unavailable.' );
		}
		$pointer_name = $this->pointer_name( $record );
		$new          = array(
			'v'              => 2,
			'site'           => $record['site'],
			'user'           => $record['user'],
			'main'           => $record['main'],
			'session_hash'   => $record['session_hash'],
			'epoch'          => $record['epoch'],
			'generation'     => 0,
			'phase'          => 'idle',
			'capture_id'     => null,
			'fingerprint'    => null,
			'saved_revision' => $record['main_revision'],
			'expires'        => $record['expires'],
		);
		$new_wire     = $this->encode( $new, self::MAX_POINTER_BYTES );
		$published    = false;
		$previous     = null;
		try {
			for ( $attempt = 0; $attempt < 3; ++$attempt ) {
				$old_wire = $this->read( $pointer_name, self::MAX_POINTER_BYTES );
				if ( null === $old_wire ) {
					if ( $this->insert( $pointer_name, $new_wire ) ) {
						$published = true;
						break;
					}
					continue;
				}
				$previous = $this->decode_pointer( $old_wire );
				if ( $previous['site'] !== $record['site'] || $previous['user'] !== $record['user'] || $previous['main'] !== $record['main'] ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor pointer is invalid.' );
				}
				if ( $this->compare_exchange( $pointer_name, $old_wire, $new_wire ) ) {
					$published = true;
					break;
				}
			}
		} finally {
			if ( ! $published ) {
				$this->delete_exact( $record_name, $record_wire );
			}
		}
		if ( ! $published ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening conflicted.' );
		}
		try {
			list( $verified_record, $verified_pointer ) = $this->current( $record['session_hash'] );
			if ( $verified_record !== $record || $verified_pointer !== $new ) {
				throw new ContractException( 'storage_unavailable', 'Elementor editor opening is unavailable.' );
			}
		} catch ( \Throwable $error ) {
			// Another opening may have replaced us between CAS and readback. Never erase its pointer.
			try {
				$this->delete_exact( $pointer_name, $new_wire );
				$this->delete_exact( $record_name, $record_wire );
				$remaining = $this->read( $record_name, self::MAX_RECORD_BYTES );
			} catch ( \Throwable $cleanup_error ) {
				$remaining = $record_wire;
			}
			if ( null !== $remaining ) {
				try {
					wp_schedule_single_event( $record['expires'] + 1, 'directorist_ai_elementor_editor_session_expire', array( $record['session_hash'] ) );
				} catch ( \Throwable $schedule_error ) {
					// The opening remains unavailable even if cleanup must be retried externally.
				}
			}
			throw new ContractException( 'storage_unavailable', 'Elementor editor opening is unavailable.' );
		}
		if ( null !== $previous && $previous['session_hash'] !== $record['session_hash'] ) {
			$this->delete_old_record( $previous['session_hash'], $record );
			$when = wp_next_scheduled( 'directorist_ai_elementor_editor_session_expire', array( $previous['session_hash'] ) );
			if ( false !== $when ) {
				wp_unschedule_event( $when, 'directorist_ai_elementor_editor_session_expire', array( $previous['session_hash'] ) );
			}
		}
		return $new;
	}

	/** A capture begins only from the exact generation known to the editor. */
	public function begin( array $record, int $expected_generation, string $capture_id ): array {
		return $this->transition(
			$record,
			static function ( array $pointer ) use ( $expected_generation, $capture_id ): array {
				if ( 'idle' !== $pointer['phase'] && 'ready' !== $pointer['phase'] ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor capture is unavailable.' );
				}
				if ( $pointer['generation'] !== $expected_generation || $pointer['generation'] >= self::MAX_GENERATION || ! preg_match( '/^el-[a-f0-9]{32}$/D', $capture_id ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor capture is stale.' );
				}
				$pointer['generation']++;
				$pointer['phase']       = 'pending';
				$pointer['capture_id']  = $capture_id;
				$pointer['fingerprint'] = null;
				return $pointer;
			}
		);
	}

	/** Claim a one-use snapshot before any payload is persisted. */
	public function claim( array $record, int $generation, string $capture_id ): array {
		return $this->capture_transition( $record, $generation, $capture_id, 'pending', 'processing', null );
	}

	/** Ready is published only after the bounded binding payload was stored and verified. */
	public function ready( array $record, int $generation, string $capture_id, string $fingerprint ): array {
		if ( ! self::sha( $fingerprint ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor binding is invalid.' );
		}
		return $this->capture_transition( $record, $generation, $capture_id, 'processing', 'ready', $fingerprint );
	}

	/** Failure never restores an earlier capture. */
	public function fail( array $record, int $generation, string $capture_id ): void {
		try {
			$this->capture_transition( $record, $generation, $capture_id, 'processing', 'idle', null );
		} catch ( ContractException $error ) {
			// A newer opening, close or save is already authoritative.
		}
	}

	/** A native save invalidates all earlier capture generations before its data is written. */
	public function begin_save( array $record, string $save_id, bool $autosave ): array {
		return $this->transition(
			$record,
			static function ( array $pointer ) use ( $save_id, $autosave ): array {
				if ( ! in_array( $pointer['phase'], array( 'idle', 'ready', 'pending', 'processing' ), true ) || $pointer['generation'] >= self::MAX_GENERATION || ! preg_match( '/^save-[a-f0-9]{32}$/D', $save_id ) ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor save is unavailable.' );
				}
				$pointer['generation']++;
				$pointer['phase']       = $autosave ? 'saving' : 'closed';
				$pointer['capture_id']  = $autosave ? $save_id : null;
				$pointer['fingerprint'] = null;
				return $pointer;
			}
		);
	}

	/** A paired, independently verified native autosave re-bases only the mutable saved revision. */
	public function finish_autosave( array $record, int $generation, string $save_id, string $saved_revision ): array {
		if ( ! self::sha( $saved_revision ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor source is invalid.' );
		}
		return $this->transition(
			$record,
			static function ( array $pointer ) use ( $generation, $save_id, $saved_revision ): array {
				if ( 'saving' !== $pointer['phase'] || $pointer['generation'] !== $generation || $pointer['capture_id'] !== $save_id ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor autosave is stale.' );
				}
				$pointer['phase']          = 'idle';
				$pointer['capture_id']     = null;
				$pointer['saved_revision'] = $saved_revision;
				return $pointer;
			}
		);
	}

	/** A missing/failed after-save remains unavailable, including after a failed native write. */
	public function close( array $record, int $generation, ?string $capture_id = null ): bool {
		try {
			$this->transition(
				$record,
				static function ( array $pointer ) use ( $generation, $capture_id ): array {
					if ( $pointer['generation'] !== $generation || null !== $capture_id && $pointer['capture_id'] !== $capture_id ) {
						throw new ContractException( 'evidence_stale', 'Elementor editor close is stale.' );
					}
					$pointer['phase']       = 'closed';
					$pointer['capture_id']  = null;
					$pointer['fingerprint'] = null;
					return $pointer;
				}
			);
			return true;
		} catch ( ContractException $error ) {
			return false;
		}
	}

	/** Both record and current pointer are fetched from SQL, never an option/transient cache. */
	public function current( string $session_hash ): array {
		if ( ! self::sha( $session_hash ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session is invalid.' );
		}
		$wire = $this->read( $this->record_name( $session_hash ), self::MAX_RECORD_BYTES );
		if ( null === $wire ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session is unavailable.' );
		}
		$record       = $this->decode_record( $wire );
		$pointer_wire = $this->read( $this->pointer_name( $record ), self::MAX_POINTER_BYTES );
		if ( null === $pointer_wire ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening is unavailable.' );
		}
		$pointer = $this->decode_pointer( $pointer_wire );
		if ( $record['session_hash'] !== $pointer['session_hash'] || $record['epoch'] !== $pointer['epoch'] || $record['site'] !== $pointer['site'] || $record['user'] !== $pointer['user'] || $record['main'] !== $pointer['main'] || $record['expires'] !== $pointer['expires'] || $record['expires'] <= time() ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening is stale.' );
		}
		return array( $record, $pointer );
	}

	/** Native save hooks have no editor token; resolve the current actor/main pointer only to revoke it. */
	public function owner_current( int $site, int $user, int $main ): array {
		$wire = $this->read(
			$this->pointer_name(
				array(
					'site' => $site,
					'user' => $user,
					'main' => $main,
				)
			),
			self::MAX_POINTER_BYTES
		);
		if ( null === $wire ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening is unavailable.' );
		}
		$pointer = $this->decode_pointer( $wire );
		if ( $pointer['site'] !== $site || $pointer['user'] !== $user || $pointer['main'] !== $main ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor opening is stale.' );
		}
		return $this->current( $pointer['session_hash'] );
	}

	/** Exact matching cleanup; never scan or flush foreign options. */
	public function expire( string $session_hash ): void {
		if ( ! self::sha( $session_hash ) ) {
			return;
		}
		try {
			$record_name = $this->record_name( $session_hash );
			$record_wire = $this->read( $record_name, self::MAX_RECORD_BYTES );
			if ( null === $record_wire ) {
				return;
			}
			$record = $this->decode_record( $record_wire );
			if ( $record['session_hash'] !== $session_hash || $record['expires'] > time() ) {
				return;
			}
			$pointer_name = $this->pointer_name( $record );
			$pointer_wire = $this->read( $pointer_name, self::MAX_POINTER_BYTES );
			if ( null !== $pointer_wire ) {
				$pointer = $this->decode_pointer( $pointer_wire );
				if ( $pointer['session_hash'] === $session_hash && $pointer['epoch'] === $record['epoch'] && $pointer['expires'] === $record['expires'] ) {
					$this->delete_exact( $pointer_name, $pointer_wire );
				}
			}
			$this->delete_exact( $record_name, $record_wire );
		} catch ( ContractException $error ) {
			// Never erase a malformed or ambiguous pointer from an expiry callback.
		}
	}

	/** Exact caller-owned teardown after a failed boot or a confirmed close. */
	public function discard( string $session_hash ): void {
		if ( ! self::sha( $session_hash ) ) {
			return;
		}
		$record_name = $this->record_name( $session_hash );
		$record_wire = $this->read( $record_name, self::MAX_RECORD_BYTES );
		if ( null === $record_wire ) {
			return;
		}
		$record       = $this->decode_record( $record_wire );
		$pointer_name = $this->pointer_name( $record );
		$pointer_wire = $this->read( $pointer_name, self::MAX_POINTER_BYTES );
		if ( null !== $pointer_wire ) {
			$pointer = $this->decode_pointer( $pointer_wire );
			if ( $pointer['session_hash'] === $session_hash && $pointer['epoch'] === $record['epoch'] ) {
				$this->delete_exact( $pointer_name, $pointer_wire );
			}
		}
		$this->delete_exact( $record_name, $record_wire );
	}

	private function capture_transition( array $record, int $generation, string $capture_id, string $expected_phase, string $next_phase, ?string $fingerprint ): array {
		return $this->transition(
			$record,
			static function ( array $pointer ) use ( $generation, $capture_id, $expected_phase, $next_phase, $fingerprint ): array {
				if ( $pointer['phase'] !== $expected_phase || $pointer['generation'] !== $generation || $pointer['capture_id'] !== $capture_id ) {
					throw new ContractException( 'evidence_stale', 'Elementor editor capture is stale.' );
				}
				$pointer['phase']       = $next_phase;
				$pointer['capture_id']  = 'idle' === $next_phase ? null : $capture_id;
				$pointer['fingerprint'] = $fingerprint;
				return $pointer;
			}
		);
	}

	private function transition( array $record, callable $change ): array {
		list( $found, $old ) = $this->current( $record['session_hash'] );
		if ( $found !== $record ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session is stale.' );
		}
		$new = $change( $old );
		$this->validate_pointer( $new );
		if ( ! $this->compare_exchange( $this->pointer_name( $record ), $this->encode( $old, self::MAX_POINTER_BYTES ), $this->encode( $new, self::MAX_POINTER_BYTES ) ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor session changed.' );
		}
		return $new;
	}

	private function read( string $name, int $max ): ?string {
		$table = $this->table();
		$this->require_unique_index( $table );
		$rows = $this->db->get_results( $this->db->prepare( "SELECT IF(OCTET_LENGTH(option_value) <= %d, option_value, NULL) AS value, OCTET_LENGTH(option_value) AS bytes FROM {$table} WHERE option_name = %s LIMIT 2", $max, $name ), ARRAY_A );
		if ( ! is_array( $rows ) || count( $rows ) > 1 || $this->db->last_error ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
		}
		if ( ! $rows ) {
			return null;
		}
		if ( ! isset( $rows[0]['bytes'], $rows[0]['value'] ) || (int) $rows[0]['bytes'] > $max || ! is_string( $rows[0]['value'] ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor record is invalid.' );
		}
		return $rows[0]['value'];
	}

	private function insert( string $name, string $wire ): bool {
		$table = $this->table();
		$this->require_unique_index( $table );
		$rows = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$table} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $wire ) );
		if ( false === $rows || $this->db->last_error ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
		}
		if ( 1 === $rows ) {
			$this->invalidate( $name );
			if ( $this->read( $name, strlen( $wire ) ) !== $wire ) {
				throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
			}
		}
		return 1 === $rows;
	}

	private function compare_exchange( string $name, string $old, string $replacement ): bool {
		$table = $this->table();
		$this->require_unique_index( $table );
		$rows = $this->db->query( $this->db->prepare( "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $replacement, $name, $old ) );
		if ( false === $rows || $this->db->last_error ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
		}
		if ( 1 === $rows ) {
			$this->invalidate( $name );
			if ( $this->read( $name, strlen( $replacement ) ) !== $replacement ) {
				throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
			}
		}
		return 1 === $rows;
	}

	private function delete_exact( string $name, string $wire ): void {
		$table = $this->table();
		$rows  = $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE option_name = %s AND BINARY option_value = BINARY %s", $name, $wire ) );
		if ( false !== $rows && $rows > 0 ) {
			$this->invalidate( $name );
		}
	}

	private function delete_old_record( string $session_hash, array $new_record ): void {
		try {
			$wire = $this->read( $this->record_name( $session_hash ), self::MAX_RECORD_BYTES );
			if ( null !== $wire ) {
				$old = $this->decode_record( $wire );
				if ( $old['site'] === $new_record['site'] && $old['user'] === $new_record['user'] && $old['main'] === $new_record['main'] ) {
					$this->delete_exact( $this->record_name( $session_hash ), $wire );
				}
			}
		} catch ( ContractException $error ) {
			// Current pointer already excludes the old record; its exact expiry hook retries cleanup.
		}
	}

	private function invalidate( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private function table(): string {
		$table = $this->db->options;
		if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
		}
		return '`' . $table . '`';
	}

	private function require_unique_index( string $table ): void {
		if ( $this->checked_unique_index ) {
			return;
		}
		$rows = $this->db->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'option_name'", ARRAY_A );
		if ( ! is_array( $rows ) || 1 !== count( $rows ) || $this->db->last_error || 0 !== (int) ( $rows[0]['Non_unique'] ?? -1 ) || 1 !== (int) ( $rows[0]['Seq_in_index'] ?? -1 ) || 'option_name' !== ( $rows[0]['Column_name'] ?? null ) ) {
			throw new ContractException( 'storage_unavailable', 'Elementor editor storage is unavailable.' );
		}
		$this->checked_unique_index = true;
	}

	private function record_name( string $hash ): string {
		return self::RECORD_PREFIX . $hash; }
	private function pointer_name( array $record ): string {
		return self::CURRENT_PREFIX . hash( 'sha256', $record['site'] . ':' . $record['user'] . ':' . $record['main'] ); }
	private static function sha( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ); }
	private function encode( array $value, int $limit ): string {
		$wire = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		if ( ! is_string( $wire ) || strlen( $wire ) > $limit ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor record exceeds its bound.' );
		}
		return $wire;
	}

	private function decode_record( string $wire ): array {
		$record = $this->decode( $wire, self::MAX_RECORD_BYTES );
		$this->validate_record( $record );
		return $record;
	}
	private function decode_pointer( string $wire ): array {
		$pointer = $this->decode( $wire, self::MAX_POINTER_BYTES );
		$this->validate_pointer( $pointer );
		return $pointer;
	}
	private function decode( string $wire, int $limit ): array {
		try {
			$value = ( new JsonDecoder( $limit, 8 ) )->decode( $wire );
			if ( ! $value instanceof \stdClass ) {
				throw new \RuntimeException( 'Invalid record.' );
			}
			return get_object_vars( $value );
		} catch ( \Throwable $error ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor record is invalid.' );
		}
	}

	private function validate_record( array $record ): void {
		$fields = array( 'v', 'site', 'user', 'main', 'source', 'kind', 'directory_id', 'document_type', 'origin', 'session_hash', 'epoch', 'boot_revision', 'main_revision', 'provider', 'created', 'expires' );
		if ( ! $this->exact_keys( $record, $fields ) || 2 !== $record['v'] || ! $this->positive( $record['site'] ) || ! $this->positive( $record['user'] ) || ! $this->positive( $record['main'] ) || ! $this->positive( $record['source'] ) || ! is_string( $record['kind'] ) || ! ProviderRegistration::valid_token( $record['kind'] ) || ( null !== $record['directory_id'] && ! $this->positive( $record['directory_id'] ) ) || ! is_string( $record['document_type'] ) || ! ProviderRegistration::valid_token( $record['document_type'] ) || ! is_string( $record['origin'] ) || strlen( $record['origin'] ) > 512 || ! self::sha( $record['session_hash'] ) || ! self::sha( $record['epoch'] ) || ! self::sha( $record['boot_revision'] ) || ! self::sha( $record['main_revision'] ) || ! self::sha( $record['provider'] ) || ! $this->positive( $record['created'] ) || ! $this->positive( $record['expires'] ) || $record['created'] > time() || $record['expires'] <= $record['created'] || $record['expires'] - $record['created'] > 900 ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor record is invalid.' );
		}
	}
	private function validate_pointer( array $pointer ): void {
		$fields      = array( 'v', 'site', 'user', 'main', 'session_hash', 'epoch', 'generation', 'phase', 'capture_id', 'fingerprint', 'saved_revision', 'expires' );
		$has_capture = in_array( $pointer['phase'] ?? null, array( 'pending', 'processing', 'ready', 'saving' ), true );
		if ( ! $this->exact_keys( $pointer, $fields ) || 2 !== $pointer['v'] || ! $this->positive( $pointer['site'] ) || ! $this->positive( $pointer['user'] ) || ! $this->positive( $pointer['main'] ) || ! self::sha( $pointer['session_hash'] ) || ! self::sha( $pointer['epoch'] ) || ! is_int( $pointer['generation'] ) || $pointer['generation'] < 0 || $pointer['generation'] > self::MAX_GENERATION || ! in_array( $pointer['phase'], array( 'idle', 'pending', 'processing', 'ready', 'saving', 'closed' ), true ) || ( $has_capture !== ( null !== $pointer['capture_id'] ) ) || ( $has_capture && ( ! is_string( $pointer['capture_id'] ) || ! preg_match( 'saving' === $pointer['phase'] ? '/^save-[a-f0-9]{32}$/D' : '/^el-[a-f0-9]{32}$/D', $pointer['capture_id'] ) ) ) || ( 'ready' === $pointer['phase'] ? ! self::sha( $pointer['fingerprint'] ) : null !== $pointer['fingerprint'] ) || ! self::sha( $pointer['saved_revision'] ) || ! $this->positive( $pointer['expires'] ) ) {
			throw new ContractException( 'evidence_stale', 'Elementor editor pointer is invalid.' );
		}
	}
	private function exact_keys( array $actual, array $expected ): bool {
		$keys = array_keys( $actual );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
	private function positive( $number ): bool {
		return is_int( $number ) && $number > 0; }
}
