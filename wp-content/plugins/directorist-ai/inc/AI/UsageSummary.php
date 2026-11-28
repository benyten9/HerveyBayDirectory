<?php
/**
 * Summary-only reporting over independently retained local AI tasks.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

// phpcs:disable Squiz.Commenting.FunctionComment,WordPress.WP.CapitalPDangit -- Closed projection of persisted API identifiers.

/** Logical tasks are counted once; provider telemetry is never a credit balance. */
final class UsageSummary {

	/**
	 * Read every retained task in a bounded period, in small keyset pages.
	 *
	 * @throws ContractException Invalid reporting filters.
	 */
	public static function read( Database $db, array $input ): array {
		$source = self::choice( $input, 'source', array( 'wordpress', 'managed', 'all' ), 'wordpress' );
		$type   = self::choice( $input, 'type', array( 'chat', 'listing', 'layout', 'image', 'inspection', 'all' ), 'all' );
		$group  = self::choice( $input, 'group_by', array( 'date', 'task' ), 'task' );
		$zone   = $input['timezone'] ?? 'UTC';
		if ( ! is_string( $zone ) || ! in_array( $zone, timezone_identifiers_list(), true ) ) {
			throw new ContractException( 'invalid_input', 'Choose a valid reporting timezone.' );
		}
		$timezone          = new \DateTimeZone( $zone );
		list( $from, $to ) = self::window( $input, $timezone );
		$utc               = new \DateTimeZone( 'UTC' );
		$report            = array(
			'totals'         => self::empty_totals(),
			'by_type'        => array(),
			'by_date'        => array(),
			'by_model'       => array(),
			'from'           => $from->format( DATE_ATOM ),
			'to'             => $to->format( DATE_ATOM ),
			'timezone'       => $zone,
			'group_by'       => $group,
			'authoritative'  => false,
			'scope'          => 'directorist_ai_pro',
			'billing_source' => $source,
			'coverage'       => 'All independently retained AI tasks admitted in the selected period. Repairs remain within their task. Explicit image tasks are separate. Tokens include reported calls only. These local observations are not provider billing or service credits.',
		);
		if ( ! $db->readiness()->status()['effects_enabled'] ) {
			$report['unavailable'] = true;
			return $report;
		}
		$where = 'uses_ai=1 AND created_at>=%s AND created_at<%s';
		$args  = array( $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		if ( 'all' !== $source ) {
			$where .= ' AND source=%s';
			$args[] = $source;
		}
		$table   = $db->table( 'task_receipts' );
		$ceiling = (int) $db->scalar( "SELECT MAX(id) FROM $table WHERE $where", $args );
		$cursor  = 0;
		$types   = array();
		$dates   = array();
		$models  = array();
		do {
			$rows = $db->rows( "SELECT id,source,logical_task,state,receipt_json,created_at FROM $table WHERE $where AND id>%d AND id<=%d ORDER BY id ASC LIMIT 250", array_merge( $args, array( $cursor, $ceiling ) ) );
			foreach ( $rows as $row ) {
				$cursor    = (int) $row['id'];
				$task_type = self::task_type( $row['logical_task'] );
				if ( null === $task_type || ( 'all' !== $type && $task_type !== $type ) ) {
					continue;
				}
				$date          = ( new \DateTimeImmutable( $row['created_at'], $utc ) )->setTimezone( $timezone )->format( 'Y-m-d' );
				$item          = self::empty_totals();
				$item['tasks'] = 1;
				$item[ 'completed' === $row['state'] ? 'completed' : ( 'released' === $row['state'] ? 'failed' : 'pending' ) ] = 1;
				$data  = json_decode( $row['receipt_json'], true );
				$calls = is_array( $data['calls'] ?? null ) ? $data['calls'] : array();
				if ( ! is_array( $data ) ) {
					$item['unknown_usage'] = 1;
				}
				foreach ( $calls as $call ) {
					$meta     = is_array( $call['metadata'] ?? null ) ? $call['metadata'] : array();
					$reported = true === ( $meta['usage_reported'] ?? false );
					++$item['model_calls'];
					$item['unknown_usage'] += $reported ? 0 : 1;
					$item['input_tokens']  += $reported ? max( 0, (int) ( $meta['input_tokens'] ?? 0 ) ) : 0;
					$item['output_tokens'] += $reported ? max( 0, (int) ( $meta['output_tokens'] ?? 0 ) ) : 0;
					$item['duration_ms']   += max( 0, (int) ( $meta['duration_ms'] ?? 0 ) );
					$identity               = array(
						'provider_id'    => 'managed' === $row['source'] ? null : self::identity( $meta['provider_id'] ?? null ),
						'model_id'       => 'managed' === $row['source'] ? null : self::identity( $meta['model_id'] ?? null ),
						'reported_model' => 'managed' === $row['source'] ? null : self::identity( $meta['reported_model'] ?? null ),
					);
					$key                    = wp_json_encode( $identity );
					if ( ! isset( $models[ $key ] ) ) {
						$models[ $key ] = $identity + array(
							'model_calls'   => 0,
							'input_tokens'  => 0,
							'output_tokens' => 0,
							'unknown_usage' => 0,
						);
					}
					++$models[ $key ]['model_calls'];
					$models[ $key ]['input_tokens']  += $reported ? max( 0, (int) ( $meta['input_tokens'] ?? 0 ) ) : 0;
					$models[ $key ]['output_tokens'] += $reported ? max( 0, (int) ( $meta['output_tokens'] ?? 0 ) ) : 0;
					$models[ $key ]['unknown_usage'] += $reported ? 0 : 1;
				}
				self::add( $report['totals'], $item );
				$types[ $task_type ] = $types[ $task_type ] ?? ( array( 'type' => $task_type ) + self::empty_totals() );
				$dates[ $date ]      = $dates[ $date ] ?? ( array( 'date' => $date ) + self::empty_totals() );
				self::add( $types[ $task_type ], $item );
				self::add( $dates[ $date ], $item );
			}
		} while ( count( $rows ) === 250 );
		ksort( $dates );
		$report['totals']   = self::reported( $report['totals'] );
		$report['by_type']  = array_values( array_map( array( self::class, 'reported' ), $types ) );
		$report['by_date']  = array_values( array_map( array( self::class, 'reported' ), $dates ) );
		$report['by_model'] = array_values( array_map( array( self::class, 'reported' ), $models ) );
		return $report;
	}

	/**
	 * Parse local calendar dates; the upper reporting boundary is exclusive.
	 *
	 * @throws ContractException Invalid reporting dates.
	 */
	private static function window( array $input, \DateTimeZone $zone ): array {
		if ( isset( $input['from'] ) || isset( $input['to'] ) ) {
			$dates = array();
			foreach ( array( 'from', 'to' ) as $key ) {
				$value = $input[ $key ] ?? null;
				$date  = is_string( $value ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $zone ) : false;
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
					throw new ContractException( 'invalid_input', 'Choose valid start and end dates.' );
				}
				$dates[] = $date;
			}
			$dates[1] = $dates[1]->modify( '+1 day' );
			if ( $dates[1] <= $dates[0] || (int) $dates[0]->diff( $dates[1] )->days > 90 ) {
				throw new ContractException( 'invalid_input', 'Choose a reporting period of up to 90 days.' );
			}
			return $dates;
		}
		$days = $input['days'] ?? '30';
		if ( ! is_scalar( $days ) || ! preg_match( '/^(7|30|90)$/D', (string) $days ) ) {
			throw new ContractException( 'invalid_input', 'Choose a 7, 30 or 90 day reporting period.' );
		}
		$end = new \DateTimeImmutable( 'now', $zone );
		return array( $end->modify( '-' . $days . ' days' ), $end );
	}

	/**
	 * Validate a closed, non-coerced query choice.
	 *
	 * @throws ContractException Invalid reporting choice.
	 */
	private static function choice( array $input, string $key, array $allowed, string $fallback ): string {
		$value = $input[ $key ] ?? $fallback;
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			throw new ContractException( 'invalid_input', 'Choose a supported usage filter.' );
		}
		return $value;
	}

	/** Map persisted task policy to the same public task types as the service. */
	private static function task_type( string $kind ): ?string {
		return array(
			'chat_turn'        => 'chat',
			'directory_create' => 'chat',
			'directory_edit'   => 'chat',
			'listing_full'     => 'listing',
			'listing_improve'  => 'listing',
			'listing_field'    => 'listing',
			'layout_full'      => 'layout',
			'layout_refine'    => 'layout',
			'image_generation' => 'image',
			'inspection'       => 'inspection',
		)[ $kind ] ?? null;
	}

	/** Numeric-only counters contain no prompt, request, owner or credential. */
	private static function empty_totals(): array {
		return array(
			'tasks'         => 0,
			'completed'     => 0,
			'failed'        => 0,
			'pending'       => 0,
			'model_calls'   => 0,
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'unknown_usage' => 0,
			'duration_ms'   => 0,
		);
	}

	/** Add task/call counters without adding identities. */
	private static function add( array &$to, array $item ): void {
		foreach ( self::empty_totals() as $key => $zero ) {
			$to[ $key ] += $item[ $key ];
		}
	}

	/** Unknown-only telemetry remains unknown instead of claiming zero tokens. */
	private static function reported( array $item ): array {
		if ( $item['unknown_usage'] > 0 && $item['unknown_usage'] >= $item['model_calls'] ) {
			$item['input_tokens']  = null;
			$item['output_tokens'] = null;
		}
		return $item;
	}

	/** Reject arbitrary model text rather than returning it in the dashboard. */
	private static function identity( $value ): ?string {
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D', $value ) ? $value : null;
	}
}
