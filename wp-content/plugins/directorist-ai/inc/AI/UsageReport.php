<?php
/**
 * Bounded, administrator-only reporting from this plugin's durable call steps.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\AI;

// phpcs:disable WordPress.WP.CapitalPDangit -- Persisted source identifiers are lower-case API values.

use DirectoristAIPro\Storage\Database;

/** Local limits, provider usage and future service credits are separate facts. */
final class UsageReport {

	/**
	 * Read safe metadata for the requested retained window, never prompt bodies.
	 *
	 * @param Database $db Runtime database.
	 * @param int      $days Retained reporting window.
	 * @param string   $source Source filter.
	 * @param string   $feature Feature filter.
	 */
	public static function read( Database $db, int $days, string $source, string $feature ): array {
		$totals = array(
			'requests'         => 0,
			'succeeded'        => 0,
			'failed'           => 0,
			'running'          => 0,
			'input_tokens'     => 0,
			'output_tokens'    => 0,
			'unreported_usage' => 0,
		);
		$report = array(
			'scope'     => 'directorist_ai_pro',
			'days'      => $days,
			'totals'    => $totals,
			'by_model'  => array(),
			'activity'  => array(),
			'truncated' => false,
			'managed'   => ManagedService::status(),
		);
		if ( ! $db->readiness()->status()['effects_enabled'] ) {
			$report['unavailable'] = true;
			return $report;
		}
		$report['tasks'] = TaskReport::read( $db, $days, $source, $feature );
		$logical_tasks   = array();
		foreach ( $report['tasks']['activity'] as $task_receipt ) {
			if ( null === $task_receipt['parent_task_id'] ) {
				$logical_tasks[ $task_receipt['run_id'] ] = $task_receipt['logical_task'];
			}
		}
		$steps               = $db->table( 'steps' );
		$runs                = $db->table( 'runs' );
		$conversations       = $db->table( 'conversations' );
		$turns               = $db->table( 'turns' );
		$operations          = $db->table( 'operations' );
		$rows                = $db->rows(
			"SELECT s.id,s.attempt,s.state,s.data_json,r.public_id AS run_id,r.state AS run_state,r.created_at,c.profile,c.context_json,t.sequence AS turn_sequence,EXISTS(SELECT 1 FROM $operations o WHERE o.run_id=r.id AND o.state='succeeded') AS native_effect FROM $steps s INNER JOIN $runs r ON r.id=s.run_id INNER JOIN $conversations c ON c.id=r.conversation_id LEFT JOIN $turns t ON t.id=r.trigger_turn_id WHERE s.kind='model' AND r.created_at >= DATE_SUB(UTC_TIMESTAMP(),INTERVAL %d DAY) AND c.erasure_requested=0 ORDER BY s.id DESC LIMIT 1001",
			array( $days )
		);
		$report['truncated'] = count( $rows ) > 1000;
		$groups              = array();
		foreach ( array_slice( $rows, 0, 1000 ) as $row ) {
			$data = json_decode( $row['data_json'], true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}
			$inferred = Settings::feature( $row['profile'], json_decode( $row['context_json'] ) );
			$history  = is_array( $data['previous_attempts'] ?? null ) ? $data['previous_attempts'] : array();
			$current  = $data;
			unset( $current['previous_attempts'] );
			$current['state']   = $row['state'];
			$current['attempt'] = (int) $row['attempt'];
			$history[]          = $current;
			$missing            = max( 0, (int) $row['attempt'] - count( $history ) );
			for ( $index = 0; $index < $missing; ++$index ) {
				array_unshift(
					$history,
					array(
						'state'   => 'unreported',
						'attempt' => $missing - $index,
						'source'  => $data['source'] ?? 'wordpress',
						'feature' => $data['feature'] ?? $inferred,
					)
				);
			}
			foreach ( array_reverse( $history ) as $attempt ) {
				$item                 = self::activity( $row, $attempt, $inferred );
				$item['logical_task'] = $logical_tasks[ $item['run_id'] ] ?? null;
				if ( 'managed' === $item['source'] ) {
					foreach ( array( 'requested_provider', 'requested_model', 'provider_id', 'model_id', 'reported_model' ) as $identity ) {
						$item[ $identity ] = null;
					}
				}
				if ( ( 'all' !== $source && $item['source'] !== $source ) || ( 'all' !== $feature && $item['feature'] !== $feature ) ) {
					continue;
				}
				++$report['totals']['requests'];
				if ( isset( $report['totals'][ $item['state'] ] ) && in_array( $item['state'], array( 'succeeded', 'failed', 'running' ), true ) ) {
					++$report['totals'][ $item['state'] ];
				}
				if ( ! $item['usage_reported'] ) {
					++$report['totals']['unreported_usage'];
				}
				$report['totals']['input_tokens']  += $item['input_tokens'] ?? 0;
				$report['totals']['output_tokens'] += $item['output_tokens'] ?? 0;
				$key                                = wp_json_encode( array( $item['source'], $item['provider_id'], $item['requested_model'], $item['model_id'], $item['reported_model'], $item['feature'] ) );
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array_intersect_key( $item, array_flip( array( 'source', 'provider_id', 'requested_model', 'model_id', 'reported_model', 'feature' ) ) ) + array(
						'requests'         => 0,
						'input_tokens'     => 0,
						'output_tokens'    => 0,
						'unreported_usage' => 0,
					);
				}
				++$groups[ $key ]['requests'];
				$groups[ $key ]['input_tokens']     += $item['input_tokens'] ?? 0;
				$groups[ $key ]['output_tokens']    += $item['output_tokens'] ?? 0;
				$groups[ $key ]['unreported_usage'] += $item['usage_reported'] ? 0 : 1;
				if ( count( $report['activity'] ) < 500 ) {
					$report['activity'][] = $item;
				}
			}
		}
		$report['by_model'] = array_values( $groups );
		foreach ( $report['by_model'] as &$group ) {
			if ( $group['requests'] === $group['unreported_usage'] ) {
				$group['input_tokens']  = null;
				$group['output_tokens'] = null;
			}
		}
		unset( $group );
		$report['activity_truncated'] = $report['totals']['requests'] > count( $report['activity'] );
		$report['coverage']           = 'Latest 1,000 retained model steps in the selected run-admission window. Activity is limited to 500 attempts. Token totals include only reported usage. Earlier retried attempts without telemetry are marked unreported.';
		if ( $report['totals']['requests'] === $report['totals']['unreported_usage'] && $report['totals']['requests'] > 0 ) {
			$report['totals']['input_tokens']  = null;
			$report['totals']['output_tokens'] = null;
		}
		return $report;
	}

	/**
	 * Project allowlisted fields; no owner, prompt, content or secret is exposed.
	 *
	 * @param array  $row Durable model step and run metadata.
	 * @param array  $data Redacted call metadata.
	 * @param string $inferred Legacy feature classification.
	 */
	private static function activity( array $row, array $data, string $inferred ): array {
		$reported = $data['usage_reported'] ?? ( isset( $data['input_tokens'], $data['output_tokens'] ) && ( $data['input_tokens'] > 0 || $data['output_tokens'] > 0 ) );
		$state    = in_array( $data['state'] ?? '', array( 'succeeded', 'failed', 'running', 'unreported' ), true ) ? $data['state'] : 'unreported';
		$source   = in_array( $data['source'] ?? '', array( 'wordpress', 'managed' ), true ) ? $data['source'] : 'wordpress';
		$feature  = in_array( $data['feature'] ?? '', array( 'listing', 'builder', 'visitor', 'admin' ), true ) ? $data['feature'] : $inferred;
		$outcome  = 'failed' === $row['run_state'] ? 'failed' : ( 'waiting_approval' === $row['run_state'] ? 'review_required' : ( 'waiting_browser' === $row['run_state'] ? 'editor_check_required' : ( 'succeeded' === $row['run_state'] ? ( $row['native_effect'] ? 'native_operation_completed' : 'response_completed' ) : $row['run_state'] ) ) );
		return array(
			'id'                 => $row['id'] . '-' . ( $data['attempt'] ?? 1 ),
			'run_id'             => $row['run_id'],
			'turn_id'            => null === $row['turn_sequence'] ? null : (int) $row['turn_sequence'],
			'feature'            => $feature,
			'source'             => $source,
			'task'               => 'image' === ( $data['task'] ?? $data['modality'] ?? '' ) ? 'image' : 'text',
			'state'              => $state,
			'attempts'           => (int) $row['attempt'],
			'attempt'            => (int) ( $data['attempt'] ?? 1 ),
			'requested_provider' => self::identity( $data['requested_provider'] ?? null, true ),
			'requested_model'    => self::identity( $data['requested_model'] ?? null ),
			'provider_id'        => self::identity( $data['provider_id'] ?? null, true ),
			'model_id'           => self::identity( $data['model_id'] ?? null ),
			'reported_model'     => self::identity( $data['reported_model'] ?? null ),
			'usage_reported'     => true === $reported,
			'input_tokens'       => true === $reported ? max( 0, (int) ( $data['input_tokens'] ?? 0 ) ) : null,
			'output_tokens'      => true === $reported ? max( 0, (int) ( $data['output_tokens'] ?? 0 ) ) : null,
			'duration_ms'        => isset( $data['duration_ms'] ) ? max( 0, (int) $data['duration_ms'] ) : null,
			'outcome'            => $outcome,
			'created_at'         => isset( $data['started_at'] ) && is_string( $data['started_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $data['started_at'] ) ? $data['started_at'] : $row['created_at'],
		);
	}

	/**
	 * Accept an identifier without repairing arbitrary provider text.
	 *
	 * @param mixed $value Untrusted identifier.
	 * @param bool  $provider Whether the provider pattern applies.
	 */
	private static function identity( $value, bool $provider = false ): ?string {
		$pattern = $provider ? '/^[a-z0-9_-]{1,128}$/D' : '/^[A-Za-z0-9._:\\/@+-]{1,191}$/D';
		return is_string( $value ) && preg_match( $pattern, $value ) ? $value : null;
	}
}
