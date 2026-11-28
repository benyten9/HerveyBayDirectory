<?php
/**
 * Numeric resource measurements for private builder diagnostics.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

/** Does not enable query logging or retain query text. */
final class BuilderGenerationMetrics {

	/** Capture process CPU and the current database counters. */
	public static function start(): array {
		global $wpdb;
		return array(
			'cpu'     => self::cpu(),
			'queries' => isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : null,
			'offset'  => defined( 'SAVEQUERIES' ) && SAVEQUERIES && is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : null,
		);
	}

	/**
	 * Read only the new numeric durations if query logging was already enabled.
	 *
	 * @param array $before Initial numeric counters and log offset.
	 */
	public static function finish( array $before ): array {
		global $wpdb;
		$cpu     = self::cpu();
		$queries = null !== $before['queries'] && isset( $wpdb->num_queries ) && $wpdb->num_queries >= $before['queries'] ? min( 1000000, (int) $wpdb->num_queries - $before['queries'] ) : null;
		$sql     = null;
		if ( null !== $before['offset'] && null !== $queries && $queries <= 10000 && defined( 'SAVEQUERIES' ) && SAVEQUERIES && is_array( $wpdb->queries ?? null ) && count( $wpdb->queries ) - $before['offset'] === $queries ) {
			$sql = 0.0;
			for ( $i = $before['offset']; $i < count( $wpdb->queries ); ++$i ) {
				$seconds = $wpdb->queries[ $i ][1] ?? null;
				if ( ! is_numeric( $seconds ) || ! is_finite( (float) $seconds ) || $seconds < 0 ) {
					$sql = null;
					break;
				}
				$sql += (float) $seconds * 1000;
			}
		}
		return array(
			'cpu_ms'      => null !== $before['cpu'] && null !== $cpu && $cpu >= $before['cpu'] ? min( 3600000, (int) round( ( $cpu - $before['cpu'] ) / 1000 ) ) : null,
			'sql_queries' => $queries,
			'sql_ms'      => null === $sql ? null : min( 3600000, (int) round( $sql ) ),
		);
	}

	/**
	 * Add available samples without presenting unavailable measurements as zero.
	 *
	 * @param object $entry Existing private phase aggregate.
	 * @param array  $sample Available numeric resource deltas.
	 */
	public static function accumulate( object $entry, array $sample ): void {
		foreach ( $sample as $name => $value ) {
			$entry->{$name}  ??= null;
			$count             = $name . '_samples';
			$entry->{$count} ??= 0;
			if ( null !== $value ) {
				$entry->{$name}  = min( 36000000, ( $entry->{$name} ?? 0 ) + $value );
				$entry->{$count} = min( 1000, $entry->{$count} + 1 );
			}
		}
	}

	/** Process user plus system CPU in microseconds, where supported. */
	private static function cpu(): ?int {
		if ( ! function_exists( 'getrusage' ) ) {
			return null;
		}
		$usage = getrusage();
		if ( ! is_array( $usage ) ) {
			return null;
		}
		return (int) ( ( $usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec'] ) * 1000000 + $usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec'] );
	}
}
