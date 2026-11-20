<?php
/**
 * Class Tasks
 *
 * @since 1.0.0
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Core;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transactional CRM/scheduler/campaign DB ops; persistent caching is impractical for write-heavy or per-request lookups (matches WooCommerce/FluentCRM precedent).


defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tasks class
 */
class Tasks {

	/**
	 * Group
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $group;

	/**
	 * Hook+callback pairs already wrapped, so none is registered twice.
	 *
	 * @var array<string, callable>
	 */
	private $registered_callbacks = array();

	/**
	 * Initialized
	 *
	 * @var boolean
	 */
	private static $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $group Group.
	 */
	public function __construct( $group ) {
		if ( ! self::$initialized ) {
			self::initialize();
		}

		$this->group = $group;
	}

	/**
	 * Initialize
	 *
	 * @return void
	 */
	private static function initialize() {
		self::$initialized = true;
		add_action(
			'action_scheduler_deleted_action',
			function ( $action_id ) {
				self::delete_meta( array( 'action_id' => $action_id ) );
			}
		);
	}

	/**
	 * Get the next timestamp for a scheduled action
	 *
	 * @since 1.0.0
	 *
	 * @param string     $hook Hook name.
	 * @param array|null $args Args passed to hook.
	 * @return integer|false False if not scheduled
	 */
	public function get_next_timestamp( $hook, $args = null ) {
		return NextRunCache::get( "{$this->group}_$hook", $args, $this->group );
	}

	/**
	 * Enqueue async task
	 * Must be called after 'init' action
	 *
	 * @param string $hook Hook name.
	 * @param array  ...$args Args passed to hook.
	 * @return integer|false
	 */
	public function enqueue_async( $hook, ...$args ) {
		// add args meta.
		$meta_id = $this->add_meta( "{$this->group}_$hook", $args );
		if ( ! $meta_id ) {
			return false;
		}

		// add action.
		$action_id = as_enqueue_async_action( "{$this->group}_$hook", compact( 'meta_id' ), $this->group, false, 10 );
		if ( ! $action_id ) {
			return false;
		}

		// assign action to meta.
		$this->update_meta( $meta_id, array( 'action_id' => $action_id ), '%d' );

		return $action_id;
	}

	/**
	 * Enqueu sync task
	 *
	 * @param string $hook Hook name.
	 * @param array  ...$args Args passed to hook.
	 * @return void
	 */
	public function enqueue_sync( $hook, ...$args ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Hook names are prefixed with the group name.
		do_action( "{$this->group}_$hook", ...$args );
	}


	/**
	 * Schedule recurring task
	 *
	 * @since 1.0.0
	 *
	 * @param integer $timestamp Timestamp of run.
	 * @param string  $hook Hook name.
	 * @param array   ...$args Args passed to hook.
	 * @return integer|false
	 */
	public function schedule_single( $timestamp, $hook, ...$args ) {
		// $this->enqueue_sync( $hook, ...$args );
		// return;
		// add args meta.
		$meta_id = $this->add_meta( "{$this->group}_$hook", $args );
		if ( ! $meta_id ) {
			return false;
		}

		// add action.
		$action_id = as_schedule_single_action( $timestamp, "{$this->group}_$hook", compact( 'meta_id' ), $this->group, false, 10 );
		if ( ! $action_id ) {
			return false;
		}

		// assign action to meta.
		$this->update_meta( $meta_id, array( 'action_id' => $action_id ), '%d' );

		return $action_id;
	}

	/**
	 * Schedule recurring task
	 *
	 * @since 1.0.0
	 *
	 * @param integer $timestamp Timestamp of first run.
	 * @param integer $interval Interval in seconds.
	 * @param string  $hook Hook name.
	 * @param array   ...$args Args passed to hook.
	 * @return integer|false
	 */
	public function schedule_recurring( $timestamp, $interval, $hook, ...$args ) {
		global $wpdb;

		if ( ! $this->meta_table_exists() ) {
			return false;
		}

		$full_hook = "{$this->group}_$hook";

		// Check if meta already exists for this recurring task.
		$existing_meta_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->prefix}doublescale_task_meta
				WHERE hook = %s
				AND group_slug = %s
				ORDER BY ID DESC
				LIMIT 1",
				$full_hook,
				$this->group
			)
		);

		if ( $existing_meta_id ) {
			// Reuse existing meta for recurring tasks.
			$meta_id = $existing_meta_id;

			// Update the args in case they changed.
			$this->update_meta( $meta_id, array( 'value' => maybe_serialize( $args ) ), '%s' );
		} else {
			// Create new meta only if none exists (first-time scheduling).
			$meta_id = $this->add_meta( $full_hook, $args );
			if ( ! $meta_id ) {
				return false;
			}
		}

		if ( as_has_scheduled_action( $full_hook, compact( 'meta_id' ), $this->group ) ) {
			return as_next_scheduled_action( $full_hook, compact( 'meta_id' ), $this->group );
		}

		$wpdb->suppress_errors( true );
		$had_error = false;

		try {
			$result = as_schedule_recurring_action( $timestamp, $interval, $full_hook, compact( 'meta_id' ), $this->group, true );
		} catch ( \Exception $e ) {
			$had_error    = true;
			$error_detail = $e->getMessage();
			$result       = false;
		}

		$db_error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		if ( ! $had_error && $db_error && stripos( $db_error, 'Deadlock' ) !== false ) {
			$had_error    = true;
			$error_detail = $db_error;
			$result       = false;
		}

		if ( $had_error && function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->info(
				'Failed to schedule recurring action (possible deadlock)',
				array(
					'hook'  => $full_hook,
					'group' => $this->group,
					'error' => $error_detail,
				)
			);
		}

		if ( false === $result || 0 === $result ) {
			$existing = as_next_scheduled_action( $full_hook, compact( 'meta_id' ), $this->group );
			return $existing ? $existing : false;
		}

		return $result;
	}

	/**
	 * Register callback
	 *
	 * schedule_single() and enqueue_async() cannot hand models to Action
	 * Scheduler (it stores arguments as JSON), so they stash the real
	 * arguments in task meta and queue only ['meta_id' => N]. Action Scheduler
	 * then fires the hook with array_values() of that, so the callback is
	 * handed the bare meta id N.
	 *
	 * Most callbacks — in free and in Pro — were written against exactly that
	 * and look the meta up themselves, so by default they keep receiving it
	 * unchanged. A callback that wants its real arguments passes
	 * $unwrap_queued_args = true instead of doing its own lookup.
	 *
	 * @param string   $hook               Hook name.
	 * @param callable $callback           The callback to be run when the action is called.
	 * @param bool     $unwrap_queued_args Deliver the queued arguments rather than
	 *                                     the bare meta id Action Scheduler passes.
	 * @return void
	 */
	public function register_callback( $hook, $callback, $unwrap_queued_args = false ) {
		$full_hook = "{$this->group}_$hook";

		// WordPress de-duplicates callbacks by identity, and a fresh closure is
		// always unique — registering the same handler twice (boot order, a
		// rebuilt processor) would then send every message twice. Reuse one
		// wrapper per hook+callback so that de-duplication still works.
		$key = $full_hook . '|' . $this->callback_id( $callback );

		if ( ! isset( $this->registered_callbacks[ $key ] ) ) {
			$queued_hook = $unwrap_queued_args ? $full_hook : null;

			$this->registered_callbacks[ $key ] = function ( ...$args ) use ( $callback, $queued_hook ) {
				$unwrapped = $this->resolve_task_arguments( $args, $queued_hook );

				// A meta row that was cleaned up leaves nothing to run on;
				// calling the callback with junk is worse than skipping.
				if ( null === $unwrapped ) {
					return;
				}

				return $callback( ...$unwrapped );
			};
		}

		add_action( $full_hook, $this->registered_callbacks[ $key ], 10, 999 );
	}

	/**
	 * Stable identity for a callback, so the same one is not wrapped twice.
	 *
	 * @param callable $callback Callback being registered.
	 * @return string
	 */
	private function callback_id( $callback ) {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) ) {
			// Identify the instance, not the class: a processor rebuilt for a
			// new request (or a new test) is a different handler and must be
			// able to register again, or its tasks silently stop running.
			$target = is_object( $callback[0] )
				? spl_object_hash( $callback[0] )
				: (string) $callback[0];

			return $target . '::' . $callback[1];
		}

		return spl_object_hash( (object) $callback );
	}

	/**
	 * Resolve the arguments a queued task should be invoked with.
	 *
	 * @param array       $args        Arguments as the hook received them.
	 * @param string|null $queued_hook Full hook name when the callback opted in
	 *                                 to unwrapping the bare meta id; null otherwise.
	 * @return array|null The real arguments, or null when the meta is gone.
	 */
	private function resolve_task_arguments( array $args, $queued_hook = null ) {
		if ( count( $args ) !== 1 ) {
			return $args;
		}

		// The keyed wrapper, as do_action() and in-process callers pass it.
		// Unwrapped for every hook, as it always has been.
		if ( is_array( $args[0] ) && isset( $args[0]['meta_id'] ) ) {
			$meta = $this->get_meta( (int) $args[0]['meta_id'] );

			return empty( $meta ) ? null : $this->meta_arguments( $meta );
		}

		// The bare id, as Action Scheduler delivers it after array_values().
		// Only for hooks that asked: everything else keeps receiving the id.
		if ( null === $queued_hook || ! $this->looks_like_meta_id( $args[0] ) ) {
			return $args;
		}

		$meta = $this->get_meta( (int) $args[0] );

		// Gone before the action ran: nothing to run on. The opted-in hooks
		// are never fired by hand with a lone integer, so this is a cleaned-up
		// row, not a real argument.
		if ( empty( $meta ) ) {
			return null;
		}

		// Another hook's row — never hand one task's arguments to another.
		if ( ( $meta['hook'] ?? '' ) !== $queued_hook ) {
			return $args;
		}

		return $this->meta_arguments( $meta );
	}

	/**
	 * The callback arguments stored in a task meta row.
	 *
	 * @param array $meta Row from get_meta().
	 * @return array
	 */
	private function meta_arguments( array $meta ) {
		$value = $meta['value'] ?? array();

		return is_array( $value ) ? array_values( $value ) : array( $value );
	}

	/**
	 * Whether a value has the shape of a task meta row id.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	private function looks_like_meta_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0;
		}

		return is_string( $value ) && ctype_digit( $value ) && (int) $value > 0;
	}

	/**
	 * Unschedule all instances of a recurring or single action.
	 *
	 * Uses the same hook-naming convention as schedule_recurring() and schedule_single():
	 * the full hook name is "{$this->group}_{$hook}" within the group.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Hook name (without group prefix).
	 * @return void
	 */
	public function unschedule_all( $hook ) {
		$full_hook = "{$this->group}_{$hook}";
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $full_hook, null, $this->group );
		}
		NextRunCache::forget( $full_hook );
	}

	/**
	 * Add meta
	 *
	 * @param string $hook Hook.
	 * @param mixed  $value Value.
	 * @return integer|false
	 */
	private function add_meta( $hook, $value ) {
		global $wpdb;

		if ( ! $this->meta_table_exists() ) {
			return false;
		}

		$insert = $wpdb->insert(
			"{$wpdb->prefix}doublescale_task_meta",
			array(
				'hook'         => $hook,
				'group_slug'   => $this->group,
				'value'        => maybe_serialize( $value ),
				'date_created' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( ! $insert ) {
			return false;
		}

		return $wpdb->insert_id;
	}

	/**
	 * Whether the task_meta table exists (cached per request).
	 *
	 * Missing after a failed install (e.g. utf8mb4 index too long) — avoid
	 * flooding the error log on every page load.
	 *
	 * @return bool
	 */
	private function meta_table_exists() {
		global $wpdb;

		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		$table = $wpdb->prefix . 'doublescale_task_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot schema probe; caching would hide a mid-request repair.
		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );

		return $exists;
	}

	/**
	 * Update meta
	 *
	 * @param integer      $id Meta id.
	 * @param array        $data Data to be updated.
	 * @param string|array $data_format Data format.
	 * @return boolean
	 */
	private function update_meta( $id, $data, $data_format = '%s' ) {
		global $wpdb;

		return (bool) $wpdb->update( "{$wpdb->prefix}doublescale_task_meta", $data, array( 'ID' => $id ), $data_format, array( '%d' ) );
	}

	/**
	 * Get meta
	 *
	 * @param integer $id Meta id.
	 * @return array
	 */
	private function get_meta( $id ) {
		global $wpdb;

		$meta = $wpdb->get_row(
			$wpdb->prepare(
				"
					SELECT *
					FROM {$wpdb->prefix}doublescale_task_meta
					WHERE ID = %d
				",
				$id
			),
			ARRAY_A
		);

		if ( ! empty( $meta['value'] ) ) {
			$meta['value'] = maybe_unserialize( $meta['value'] );
		}

		return $meta;
	}

	/**
	 * Delete meta
	 *
	 * @param array $where Where.
	 * @return boolean
	 */
	private static function delete_meta( $where ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			"{$wpdb->prefix}doublescale_task_meta",
			$where
		);
	}

	/**
	 * Update heartbeat timestamp after task execution
	 *
	 * This method records when a task last ran successfully,
	 * enabling health monitoring and overdue detection.
	 *
	 * Uses standard SQL with subquery for database portability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Hook name (without group prefix).
	 * @return bool Success.
	 */
	public function update_heartbeat( $hook ) {
		global $wpdb;

		if ( ! $this->meta_table_exists() ) {
			return false;
		}

		$full_hook    = "{$this->group}_$hook";
		$current_time = gmdate( 'Y-m-d H:i:s' );

		$meta_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->prefix}doublescale_task_meta
				WHERE hook = %s
				AND group_slug = %s
				ORDER BY ID DESC
				LIMIT 1",
				$full_hook,
				$this->group
			)
		);

		if ( $meta_id ) {
			$result = $wpdb->update(
				"{$wpdb->prefix}doublescale_task_meta",
				array( 'last_run' => $current_time ),
				array( 'ID' => (int) $meta_id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $result ) {
				doublescale_get_logger()->info(
					'Failed to update heartbeat timestamp',
					array(
						'hook'  => $full_hook,
						'group' => $this->group,
						'error' => $wpdb->last_error,
					)
				);
				return false;
			}

			return true;
		}

		// No row yet — typical right after a plugin update when Action Scheduler
		// actions were cancelled and rescheduled. Insert instead of logging a fake failure.
		$insert = $wpdb->insert(
			"{$wpdb->prefix}doublescale_task_meta",
			array(
				'hook'         => $full_hook,
				'group_slug'   => $this->group,
				'value'        => maybe_serialize( array() ),
				'last_run'     => $current_time,
				'date_created' => $current_time,
			)
		);

		if ( ! $insert && $wpdb->last_error ) {
			doublescale_get_logger()->info(
				'Failed to update heartbeat timestamp',
				array(
					'hook'  => $full_hook,
					'group' => $this->group,
					'error' => $wpdb->last_error,
				)
			);
			return false;
		}

		return (bool) $insert;
	}

	/**
	 * Get heartbeat status for a hook
	 *
	 * Returns last run time for monitoring task health.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Hook name (without group prefix).
	 * @return array|null Heartbeat data with last_run.
	 */
	public function get_heartbeat_status( $hook ) {
		global $wpdb;

		$full_hook = "{$this->group}_$hook";

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT last_run
				FROM {$wpdb->prefix}doublescale_task_meta
				WHERE hook = %s
				AND group_slug = %s
				ORDER BY ID DESC
				LIMIT 1",
				$full_hook,
				$this->group
			),
			ARRAY_A
		);
	}

	/** Action Scheduler groups the cleanup may treat as DoubleScale's own. */
	public const OWN_GROUP_PREFIX = 'doublescale';

	/**
	 * Whether an Action Scheduler group is DoubleScale's. The unnamed group
	 * and every other plugin's are not.
	 *
	 * @param string $slug Group slug.
	 * @return bool
	 */
	public static function is_own_group( string $slug ): bool {
		return 0 === strpos( $slug, self::OWN_GROUP_PREFIX );
	}

	/**
	 * Clean up old Action Scheduler entries and orphaned task meta.
	 *
	 * - Purges DoubleScale's own abandoned groups (every hook without a
	 *   registered callback), never another plugin's: only group slugs starting
	 *   with {@see self::OWN_GROUP_PREFIX}, and never a job still due in the
	 *   future (a module switched off for a while keeps its reminders). Run it
	 *   once every plugin has registered its hooks (cron, or after `wp_loaded`);
	 *   earlier, other code's hooks look unregistered.
	 * - Removes failed actions older than 1 day and completed actions older than
	 *   3 days for all DoubleScale groups.
	 * - Emergency mode: if the table exceeds 10 000 rows the cutoffs shrink to
	 *   1 hour (failed) and 6 hours (complete) to drain any backlog quickly.
	 * - Cleans orphaned claims and orphaned task-meta rows.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cleanup statistics
	 */
	public static function cleanup_old_tasks() {
		global $wpdb;

		$stats = array(
			'actions_deleted'   => 0,
			'orphaned_hooks'    => 0,
			'orphaned_meta'     => 0,
			'orphaned_claims'   => 0,
			'emergency_mode'    => false,
		);

		// ── 1. Purge entirely-orphaned groups ────────────────────────────────────
		// An orphaned group is one where every distinct hook in that group has no
		// registered WordPress callback — meaning the entire group was abandoned
		// (e.g. after a plugin rebrand or removal). We check at the group level so
		// we never accidentally touch a group that still has at least one live hook.
		$all_groups = $wpdb->get_results(
			"SELECT group_id, slug FROM {$wpdb->prefix}actionscheduler_groups",
			ARRAY_A
		);

		$orphaned_group_ids = array();

		foreach ( (array) $all_groups as $group ) {
			// Only DoubleScale's groups. Another plugin's hooks may simply not
			// be registered yet, or only on some requests; its jobs are never
			// ours to delete.
			if ( ! self::is_own_group( (string) $group['slug'] ) ) {
				continue;
			}

			// Check all distinct hooks in the group regardless of status.
			// A group whose every hook has no registered callback is fully orphaned —
			// pending/failed records will never run, and complete records have no
			// owner to audit them, so the entire group can be removed.
			$group_hooks = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT hook
					FROM {$wpdb->prefix}actionscheduler_actions
					WHERE group_id = %d",
					(int) $group['group_id']
				)
			);

			if ( empty( $group_hooks ) ) {
				continue;
			}

			// Only mark as orphaned if EVERY hook in the group has no callback.
			$all_orphaned = true;
			foreach ( $group_hooks as $hook ) {
				if ( has_action( $hook ) ) {
					$all_orphaned = false;
					break;
				}
			}

			if ( $all_orphaned ) {
				$orphaned_group_ids[] = (int) $group['group_id'];
			}
		}

		if ( ! empty( $orphaned_group_ids ) ) {
			$ogid_placeholders = implode( ', ', array_fill( 0, count( $orphaned_group_ids ), '%d' ) );

			// Delete ALL actions from orphaned groups (pending, failed, and complete).
			// Since no callback will ever run for these hooks, keeping completed records
			// only wastes storage — there is no audit value for a group that no longer exists.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$orphaned_hooks_deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE a, l
					FROM {$wpdb->prefix}actionscheduler_actions a
					LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
					WHERE a.group_id IN ($ogid_placeholders)
					AND NOT ( a.status = 'pending' AND a.scheduled_date_gmt > %s )",
					...array_merge( $orphaned_group_ids, array( gmdate( 'Y-m-d H:i:s' ) ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$stats['orphaned_hooks'] = $orphaned_hooks_deleted !== false ? (int) $orphaned_hooks_deleted : 0;

			// Also remove the group rows themselves so they don't show in the
			// admin UI, once no job (a future one kept above) is left in them.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query(
				$wpdb->prepare(
					"DELETE g FROM {$wpdb->prefix}actionscheduler_groups g
					WHERE g.group_id IN ($ogid_placeholders)
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions a WHERE a.group_id = g.group_id )",
					...$orphaned_group_ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// ── 2. Determine cutoffs (emergency vs normal) ────────────────────────
		$total_actions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions" );

		if ( $total_actions > 10000 ) {
			$stats['emergency_mode'] = true;
			$failed_cutoff           = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
			$complete_cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( '-6 hours' ) );
		} else {
			$failed_cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
			$complete_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-3 days' ) );
		}

		// ── 3. Delete old completed/failed actions by DoubleScale groups ──────
		$group_slugs = array(
			'doublescale_campaigns',
			'doublescale_automations',
			'doublescale_daily',
			'doublescale_abandoned_cart',
			'doublescale_forms',
			'doublescale_subscription',
			'doublescale_sales',
			'doublescale_support',
			'doublescale_booking_payment',
			'doublescale_booking_completion',
			'doublescale-push',
			'doublescale_smtp',
		);

		$placeholders = implode( ', ', array_fill( 0, count( $group_slugs ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a dynamically built '%s,%s…' string; Action Scheduler tables are trusted core constants.
		$group_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->prefix}actionscheduler_groups WHERE slug IN ($placeholders)",
				...$group_slugs
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! empty( $group_ids ) ) {
			$group_placeholders = implode( ', ', array_fill( 0, count( $group_ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$deleted_failed = $wpdb->query(
				$wpdb->prepare(
					"DELETE a, l
					FROM {$wpdb->prefix}actionscheduler_actions a
					LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
					WHERE a.group_id IN ($group_placeholders)
					AND a.status = 'failed'
					AND a.scheduled_date_gmt < %s",
					...array_merge( $group_ids, array( $failed_cutoff ) )
				)
			);

			$deleted_complete = $wpdb->query(
				$wpdb->prepare(
					"DELETE a, l
					FROM {$wpdb->prefix}actionscheduler_actions a
					LEFT JOIN {$wpdb->prefix}actionscheduler_logs l ON a.action_id = l.action_id
					WHERE a.group_id IN ($group_placeholders)
					AND a.status = 'complete'
					AND a.scheduled_date_gmt < %s",
					...array_merge( $group_ids, array( $complete_cutoff ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$stats['actions_deleted'] = ( $deleted_failed !== false ? (int) $deleted_failed : 0 )
				+ ( $deleted_complete !== false ? (int) $deleted_complete : 0 );
		}

		// ── 4. Orphaned claims ────────────────────────────────────────────────
		// Removed: the previous global DELETE on actionscheduler_claims could
		// interfere with other plugins' claim lifecycle (e.g. UpdraftPlus,
		// WooCommerce). Action Scheduler's built-in QueueCleaner already handles
		// stale claims via its timeout mechanism — no manual intervention needed.
		$stats['orphaned_claims'] = 0;

		// ── 5. Clean up orphaned task meta ────────────────────────────────────
		$orphaned_meta = $wpdb->query(
			$wpdb->prepare(
				"DELETE tm
				FROM {$wpdb->prefix}doublescale_task_meta tm
				LEFT JOIN {$wpdb->prefix}actionscheduler_actions a ON tm.action_id = a.action_id
				WHERE tm.action_id IS NOT NULL
				AND a.action_id IS NULL
				AND tm.date_created < %s",
				$failed_cutoff
			)
		);

		$stats['orphaned_meta'] = $orphaned_meta !== false ? (int) $orphaned_meta : 0;

		// ── 6. Log results ────────────────────────────────────────────────────
		if ( function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->info(
				__( 'Task cleanup completed', 'doublescale' ),
				array(
					'code'  => 'task_cleanup_completed',
					'stats' => $stats,
				)
			);
		}

		return $stats;
	}
}
