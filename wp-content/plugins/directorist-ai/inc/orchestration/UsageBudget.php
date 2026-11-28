<?php
/**
 * Atomic per-run, actor/day and site/day model usage limits.
 *
 * @package DirectoristAIPro
 */

namespace DirectoristAIPro\Orchestration;

use DirectoristAIPro\Runtime\Lease;
use DirectoristAIPro\Runtime\RunStore;
use DirectoristAIPro\Chat\Operations;
use DirectoristAIPro\Chat\UsageLimits;
use DirectoristAIPro\Storage\Database;
use DirectoristAIPro\Validation\ContractException;

/** Conservative reservations make a crash fail closed without losing counters. */
final class UsageBudget {
	/**
	 * Dedicated runtime connection.
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Bind site-scoped usage storage.
	 *
	 * @param Database $db Dedicated runtime connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Fit the next provider output maximum within still-charged run headroom.
	 *
	 * Unknown usage from a failed request remains charged; a retry may use the
	 * remaining output tokens without increasing the accepted run ceiling.
	 * The later atomic reservation is authoritative if the lease or budget moves.
	 *
	 * @param Lease $lease Current fenced run.
	 * @param int   $desired Normal per-request output maximum.
	 * @return int Safe maximum for the next provider request.
	 * @throws ContractException Run authority or output budget is unavailable.
	 */
	public function request_output_limit( Lease $lease, int $desired ): int {
		if ( $desired < 1 || $desired > 16384 ) {
			throw new ContractException( 'budget_exhausted', 'Model request exceeds the bounded request budget.', array( 'budget_scope_request' ) );
		}
		( new ImageUsageAccounting( $this->db ) )->reconcile_completed( $lease );
		$runs          = $this->db->table( 'runs' );
		$conversations = $this->db->table( 'conversations' );
		$rows          = $this->db->rows( "SELECT r.budget_json,c.profile FROM $runs r JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.id=%d AND r.public_id=%s AND r.state='running' AND r.lease_owner=%s AND r.fence=%d AND r.lease_until>=UTC_TIMESTAMP()", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
		if ( ! $rows ) {
			throw new ContractException( 'cancelled', 'Run authority changed before provider reservation.' );
		}
		$budget  = json_decode( $rows[0]['budget_json'], true, 16, JSON_THROW_ON_ERROR );
		$builder = 'builder' === $rows[0]['profile'];
		if ( ! $builder && $desired > 4096 ) {
			throw new ContractException( 'budget_exhausted', 'Model request exceeds this profile output limit.', array( 'budget_scope_request', 'budget_metric_output_tokens' ) );
		}
		$settings = Operations::settings();
		$desired = null === $settings['request_output_tokens'] ? $desired : min( $desired, $settings['request_output_tokens'] );
		$remaining = null === $settings['run_output_tokens'] ? $desired : $settings['run_output_tokens'] - (int) ( $budget['output_tokens'] ?? 0 );
		if ( $remaining < 1 ) {
			throw new ContractException( 'budget_exhausted', 'Run model budget is exhausted.', array( 'budget_scope_run', 'budget_metric_output_tokens' ) );
		}
		return min( $desired, $remaining );
	}

	/**
	 * Reserve a conservative upper bound before any paid request.
	 *
	 * @param Lease $lease Current fenced run.
	 * @param int   $input_tokens Conservative prompt estimate.
	 * @param int   $output_tokens Configured provider maximum.
	 * @return UsageReservation Durable reservation.
	 * @throws ContractException Any run/actor/site limit is exhausted.
	 */
	public function reserve( Lease $lease, int $input_tokens, int $output_tokens, int $input_limit = 64000 ): UsageReservation {
		return $this->reserve_capacity( $lease, $input_tokens, $output_tokens, null, $input_limit );
	}

	/**
	 * Reserve one image under existing round and task limits.
	 *
	 * @param Lease  $lease Current fenced run.
	 * @param string $task_id Server-owned logical image task.
	 * @return UsageReservation Durable image capacity reservation.
	 */
	public function reserve_image( Lease $lease, string $task_id ): UsageReservation {
		return $this->reserve_capacity( $lease, 0, 0, $task_id );
	}

	/**
	 * Record authoritative image completion without inventing text usage.
	 *
	 * @param UsageReservation $reservation Original image capacity reservation.
	 * @param array            $telemetry Server-received image completion metadata.
	 */
	public function settle_image( UsageReservation $reservation, array $telemetry ): void {
		( new ImageUsageAccounting( $this->db ) )->settle( $reservation, $telemetry );
	}

	/**
	 * Atomically reserve text or image capacity under the same existing round limits.
	 *
	 * @param Lease       $lease Current fenced run.
	 * @param int         $input_tokens Text input capacity, zero for image modality.
	 * @param int         $output_tokens Text output capacity, zero for image modality.
	 * @param string|null $image_task Exact logical image task, null for text.
	 * @return UsageReservation Durable original capacity values.
	 * @throws ContractException When scope, authority or configured capacity is unavailable.
	 */
	private function reserve_capacity( Lease $lease, int $input_tokens, int $output_tokens, ?string $image_task = null, int $input_limit = 64000 ): UsageReservation {
		if ( get_option( 'directorist_ai_pro_emergency_stop', false ) ) {
			throw new ContractException( 'provider_unavailable', 'AI work is paused by the site administrator.' );
		}
		if ( null === $image_task && ( $input_limit < 1 || $input_limit > 262144 || $input_tokens < 1 || $input_tokens > $input_limit || $output_tokens < 1 || $output_tokens > 16384 ) ) {
			throw new ContractException( 'budget_exhausted', 'Model request exceeds the bounded request budget.', array( 'budget_scope_request' ) );
		}
		( new ImageUsageAccounting( $this->db ) )->reconcile_completed( $lease );
		$reservation_id = bin2hex( random_bytes( 16 ) );
		return $this->db->transaction(
			function ( Database $db ) use ( $lease, $input_tokens, $output_tokens, $reservation_id, $image_task, $input_limit ): UsageReservation {
				$runs          = $db->table( 'runs' );
				$conversations = $db->table( 'conversations' );
				$rows          = $db->rows( "SELECT r.owner_ref,r.budget_json,c.profile FROM $runs r JOIN $conversations c ON c.id=r.conversation_id AND c.owner_ref=r.owner_ref WHERE r.id=%d AND r.public_id=%s AND r.state='running' AND r.lease_owner=%s AND r.fence=%d AND r.lease_until>=UTC_TIMESTAMP() FOR UPDATE", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
				if ( ! $rows ) {
					throw new ContractException( 'cancelled', 'Run authority changed before provider reservation.' );
				}
				if ( null !== $image_task ) {
					( new ImageUsageAccounting( $db ) )->assert_task( $lease, $rows[0]['owner_ref'], $image_task ); }
				$budget  = json_decode( $rows[0]['budget_json'], true, 16, JSON_THROW_ON_ERROR );
				$budget += array(
					'model_rounds'  => 0,
					'input_tokens'  => 0,
					'output_tokens' => 0,
					'repair_rounds' => 0,
				);
				$builder = 'builder' === $rows[0]['profile'];
				$settings = Operations::settings();
				foreach ( array( 'model_rounds' => array( 'run_model_rounds', 1 ), 'input_tokens' => array( 'run_input_tokens', $input_tokens ), 'output_tokens' => array( 'run_output_tokens', $output_tokens ) ) as $metric => $policy ) {
					if ( UsageLimits::exceeded( $settings[ $policy[0] ], (int) $budget[ $metric ] + $policy[1] ) ) {
						throw new ContractException( 'budget_exhausted', 'This request reached an administrator processing limit.', array( 'budget_scope_run', 'budget_metric_' . $metric ) );
					}
				}
				if ( ( ! $builder && $output_tokens > 4096 ) || UsageLimits::exceeded( $settings['request_output_tokens'], $output_tokens ) ) {
					throw new ContractException( 'budget_exhausted', 'Model output exceeds its current request allowance.', array( 'budget_scope_request', 'budget_metric_output_tokens' ) );
				}
				$window   = gmdate( 'Y-m-d 00:00:00' );
				$settings = Operations::settings();
				$profile  = in_array( $rows[0]['profile'], array( 'admin', 'owner', 'visitor', 'builder' ), true ) ? $rows[0]['profile'] : 'visitor';
				$scopes   = array(
					'actor:' . hash( 'sha256', $rows[0]['owner_ref'] ) => array( $settings['actor_rounds'], $settings['actor_input_tokens'], $settings['actor_output_tokens'] ),
					'profile:' . $profile           => array( $settings[ 'profile_' . $profile . '_rounds' ], $settings['site_input_tokens'], $settings['site_output_tokens'] ),
					'site:' . get_current_blog_id() => array( $settings['site_rounds'], $settings['site_input_tokens'], $settings['site_output_tokens'] ),
				);
				ksort( $scopes, SORT_STRING );
				$table = $db->table( 'usage_windows' );
				foreach ( $scopes as $scope => $limits ) {
					// Acquire the exclusive row lock immediately, avoiding concurrent shared-lock upgrades.
					$db->query( "INSERT INTO $table (scope_key,window_start,model_rounds,input_tokens,output_tokens,updated_at) VALUES (%s,%s,0,0,0,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE scope_key=scope_key", array( $scope, $window ) );
					$current = $db->rows( "SELECT model_rounds,input_tokens,output_tokens FROM $table WHERE scope_key=%s AND window_start=%s FOR UPDATE", array( $scope, $window ) )[0];
					foreach ( array( 'model_rounds' => 1, 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens ) as $metric => $additional ) {
						$index = array_search( $metric, array( 'model_rounds', 'input_tokens', 'output_tokens' ), true );
						if ( UsageLimits::exceeded( $limits[ $index ], (int) $current[ $metric ] + $additional ) ) {
							throw new ContractException( 'budget_exhausted', 'Daily model capacity is exhausted.', array( str_starts_with( $scope, 'actor:' ) ? 'budget_scope_actor_day' : ( str_starts_with( $scope, 'profile:' ) ? 'budget_scope_profile_day' : 'budget_scope_site_day' ), 'budget_metric_' . $metric ) );
						}
					}
				}
				foreach ( array_keys( $scopes ) as $scope ) {
					$db->query( "UPDATE $table SET model_rounds=model_rounds+1,input_tokens=input_tokens+%d,output_tokens=output_tokens+%d,updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND window_start=%s", array( $input_tokens, $output_tokens, $scope, $window ) );
				}
				$budget['model_rounds'] += 1;
				if ( null !== $image_task ) {
					$budget['image_requests'] = (int) ( $budget['image_requests'] ?? 0 ) + 1; }
				$budget['input_tokens']  += $input_tokens;
				$budget['output_tokens'] += $output_tokens;
				$db->query( "UPDATE $runs SET budget_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $budget, JSON_THROW_ON_ERROR ), $lease->internal_id ) );
				$reservations = $db->table( 'usage_reservations' );
				$capacity     = null === $image_task ? null : wp_json_encode(
					array(
						'version' => 1,
						'state'   => 'reserved',
						'task_id' => $image_task,
					)
				);
				$db->query( "INSERT INTO $reservations (public_id,run_id,window_start,scope_keys_json,reserved_input,reserved_output,modality,image_quantity,capacity_json,created_at) VALUES (%s,%d,%s,%s,%d,%d,%s,%d,NULLIF(%s,''),UTC_TIMESTAMP())", array( $reservation_id, $lease->internal_id, $window, json_encode( array_keys( $scopes ), JSON_THROW_ON_ERROR ), $input_tokens, $output_tokens, null === $image_task ? 'text' : 'image', null === $image_task ? 0 : 1, $capacity ?? '' ) );
				return new UsageReservation( $reservation_id, $lease->internal_id, array_keys( $scopes ), $window, $input_tokens, $output_tokens );
			}
		);
	}

	/**
	 * Replace conservative token bounds with provider-reported usage.
	 *
	 * @param UsageReservation $reservation Original durable reservation.
	 * @param int              $input_tokens Actual provider input usage.
	 * @param int              $output_tokens Actual provider output usage.
	 * @throws ContractException Invalid provider counters.
	 */
	public function settle( UsageReservation $reservation, int $input_tokens, int $output_tokens ): void {
		if ( $input_tokens < 0 || $output_tokens < 0 ) {
			throw new ContractException( 'invalid_output', 'Provider returned invalid usage counters.' );
		}
		$this->db->transaction(
			function ( Database $db ) use ( $reservation, $input_tokens, $output_tokens ): void {
				$reservations = $db->table( 'usage_reservations' );
				$stored       = $db->rows( "SELECT actual_input,actual_output,settled_at,modality FROM $reservations WHERE public_id=%s AND run_id=%d FOR UPDATE", array( $reservation->id, $reservation->run_id ) );
				if ( ! $stored ) {
					throw new ContractException( 'invalid_output', 'Usage reservation is unavailable.' );
				}
				if ( 'text' !== $stored[0]['modality'] ) {
					throw new ContractException( 'scope_conflict', 'Image capacity cannot be settled as a text-token request.' ); }
				if ( null !== $stored[0]['settled_at'] ) {
					if ( (int) $stored[0]['actual_input'] !== $input_tokens || (int) $stored[0]['actual_output'] !== $output_tokens ) {
						throw new ContractException( 'invalid_output', 'Usage reservation was already settled differently.' );
					}
					return;
				}
				$runs = $db->table( 'runs' );
				$rows = $db->rows( "SELECT budget_json FROM $runs WHERE id=%d FOR UPDATE", array( $reservation->run_id ) );
				if ( ! $rows ) {
					throw new ContractException( 'cancelled', 'Run disappeared before usage settlement.' );
				}
				$input_delta  = $input_tokens - $reservation->input_tokens;
				$output_delta = $output_tokens - $reservation->output_tokens;
				$table        = $db->table( 'usage_windows' );
				$settlement_scopes = $reservation->scope_keys;
				sort( $settlement_scopes, SORT_STRING );
				foreach ( $settlement_scopes as $scope ) {
					$db->query( "UPDATE $table SET input_tokens=GREATEST(0,input_tokens+%d),output_tokens=GREATEST(0,output_tokens+%d),updated_at=UTC_TIMESTAMP() WHERE scope_key=%s AND window_start=%s", array( $input_delta, $output_delta, $scope, $reservation->window_start ) );
				}
				$budget                  = json_decode( $rows[0]['budget_json'], true, 16, JSON_THROW_ON_ERROR );
				$budget['input_tokens']  = max( 0, (int) $budget['input_tokens'] + $input_delta );
				$budget['output_tokens'] = max( 0, (int) $budget['output_tokens'] + $output_delta );
				$db->query( "UPDATE $runs SET budget_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $budget, JSON_THROW_ON_ERROR ), $reservation->run_id ) );
				$db->query( "UPDATE $reservations SET actual_input=%d,actual_output=%d,settled_at=UTC_TIMESTAMP() WHERE public_id=%s AND settled_at IS NULL", array( $input_tokens, $output_tokens, $reservation->id ) );
				( new RunStore( $db ) )->event_in_transaction( $reservation->run_id, 'usage.updated', (object) $budget );
			}
		);
	}

	/**
	 * Count a malformed/denied tool round against any administrator repair allowance.
	 *
	 * @param Lease $lease Current fenced run.
	 * @return int Updated repair count.
	 * @throws ContractException Repair budget or run authority is unavailable.
	 */
	public function record_repair( Lease $lease ): int {
		return $this->db->transaction(
			function ( Database $db ) use ( $lease ): int {
				$runs = $db->table( 'runs' );
				$rows = $db->rows( "SELECT budget_json FROM $runs WHERE id=%d AND public_id=%s AND state='running' AND lease_owner=%s AND fence=%d AND lease_until>=UTC_TIMESTAMP() FOR UPDATE", array( $lease->internal_id, $lease->run_id, $lease->worker_id, $lease->fence ) );
				if ( ! $rows ) {
					throw new ContractException( 'cancelled', 'Run authority changed before repair accounting.' );
				}
				$budget = json_decode( $rows[0]['budget_json'], true, 16, JSON_THROW_ON_ERROR );
				$count  = (int) ( $budget['repair_rounds'] ?? 0 ) + 1;
				if ( UsageLimits::exceeded( Operations::settings()['run_repair_rounds'], $count ) ) {
					throw new ContractException( 'budget_exhausted', 'Model repair budget is exhausted.', array( 'budget_scope_run', 'budget_metric_repair_rounds' ) );
				}
				$budget['repair_rounds'] = $count;
				$db->query( "UPDATE $runs SET budget_json=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d", array( json_encode( $budget, JSON_THROW_ON_ERROR ), $lease->internal_id ) );
				return $count;
			}
		);
	}
}
