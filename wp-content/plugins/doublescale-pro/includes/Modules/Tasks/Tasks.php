<?php
/**
 * Class Tasks
 *
 * @since 1.0.0
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks;

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
	 * Every Action Scheduler hook this class touches is stored as
	 * "{$this->group}_{$hook}" — the group is always prepended to the hook name
	 * passed by the caller (see {@see get_next_timestamp()}, {@see schedule_recurring()},
	 * {@see unschedule_all()}, all of which apply the same template).
	 *
	 * Some callers ALSO prefix their hook string with the group name, which
	 * produces a doubled stored hook, e.g. the Support module passes
	 * 'doublescale_support_email_inbound' into the 'doublescale_support' group,
	 * yielding 'doublescale_support_doublescale_support_email_inbound' in
	 * wp_actionscheduler_actions. This is cosmetically ugly but HARMLESS and
	 * INTENTIONALLY LEFT AS-IS: the doubled string is the action's identity key
	 * in production, and schedule/unschedule/lookup all derive it from the same
	 * template, so the contract is self-consistent. Do NOT "tidy" the caller's
	 * hook string to remove the doubling without a one-time migration that
	 * unschedules the old hook name first — renaming it in code alone orphans the
	 * live recurring action (no code path can ever unschedule it) and schedules a
	 * second one under the new name, causing duplicate runs.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $group;

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
	 * @since 1.6.0
	 *
	 * @param string     $hook Hook name.
	 * @param array|null $args Args passed to hook.
	 * @return integer|false False if not scheduled
	 */
	public function get_next_timestamp( $hook, $args = null ) {
		if ( class_exists( \DoubleScale\Core\NextRunCache::class ) ) {
			return \DoubleScale\Core\NextRunCache::get( "{$this->group}_$hook", $args, $this->group );
		}
		return as_next_scheduled_action( "{$this->group}_$hook", $args, $this->group );
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
	 * @since 1.6.0
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
	 * @since 1.6.0
	 *
	 * @param integer $timestamp Timestamp of first run.
	 * @param integer $interval Interval in seconds.
	 * @param string  $hook Hook name.
	 * @param array   ...$args Args passed to hook.
	 * @return integer|false
	 */
	public function schedule_recurring( $timestamp, $interval, $hook, ...$args ) {
		global $wpdb;
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
	 * @param string   $hook Hook name.
	 * @param callable $callback The callback to be run when the action is called.
	 * @return void
	 */
	public function register_callback( $hook, $callback ) {
		add_action( "{$this->group}_$hook", $callback, 10, 999 );
	}

	/**
	 * Unschedule all instances of a recurring or single action.
	 *
	 * Uses the same hook-naming convention as schedule_recurring() and schedule_single():
	 * the full hook name is "{$this->group}_{$hook}" within the group.
	 *
	 * @since 1.6.0
	 *
	 * @param string $hook Hook name (without group prefix).
	 * @return void
	 */
	public function unschedule_all( $hook ) {
		$full_hook = "{$this->group}_{$hook}";
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $full_hook, null, $this->group );
		}
		if ( class_exists( \DoubleScale\Core\NextRunCache::class ) ) {
			\DoubleScale\Core\NextRunCache::forget( $full_hook );
		}
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

		$full_hook    = "{$this->group}_$hook";
		$current_time = gmdate( 'Y-m-d H:i:s' );

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}doublescale_task_meta
				SET last_run = %s
				WHERE ID = (
					SELECT ID FROM (
						SELECT ID
						FROM {$wpdb->prefix}doublescale_task_meta
						WHERE hook = %s
						AND group_slug = %s
						ORDER BY ID DESC
						LIMIT 1
					) AS tmp
				)",
				$current_time,
				$full_hook,
				$this->group
			)
		);

		// Log failure for debugging.
		if ( false === $result ) {
			doublescale_get_logger()->info(
				'Failed to update heartbeat timestamp',
				array(
					'hook'  => $full_hook,
					'group' => $this->group,
					'error' => $wpdb->last_error,
				)
			);
		}

		return (bool) $result;
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

	/**
	 * Clean up old Action Scheduler entries: the free plugin's cleanup, which
	 * only ever touches DoubleScale's own groups ({@see \DoubleScale\Core\Tasks::cleanup_old_tasks()}).
	 * This copy used to purge every plugin's groups whose hooks were not
	 * registered yet, deleting other plugins' queues.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cleanup statistics
	 */
	public static function cleanup_old_tasks() {
		if ( class_exists( \DoubleScale\Core\Tasks::class ) && method_exists( \DoubleScale\Core\Tasks::class, 'is_own_group' ) ) {
			return \DoubleScale\Core\Tasks::cleanup_old_tasks();
		}
		// An older free plugin still has the sweep that deletes other plugins' jobs: do nothing.
		return array();
	}
}
