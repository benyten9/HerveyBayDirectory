<?php
/**
 * Weekly sweep for orphaned soft-association rows.
 *
 * Entity links in this plugin are soft (activity associations, polymorphic
 * tasks, nullable pointer columns) — the database cannot enforce them. Model
 * deleting events clean up the normal paths, but bulk query deletes, direct
 * DB edits, and deletions made while the pro plugin is inactive all bypass
 * model events. This job is the safety net that removes whatever slipped
 * through.
 *
 * @package DoubleScale\Modules\Activities
 */

namespace DoubleScale\Modules\Activities\Services;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Activities\Models\ActivityAssociationModel;

/**
 * AssociationOrphanSweeper class.
 */
class AssociationOrphanSweeper {

	const HOOK     = 'doublescale_association_orphan_sweep';
	const AS_GROUP = 'doublescale';

	/**
	 * Register scheduling and callback.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
		add_action( self::HOOK, array( __CLASS__, 'sweep' ) );
	}

	/**
	 * Schedule the weekly job when Action Scheduler is available.
	 *
	 * Deferred to `init` because Action Scheduler's data store is not
	 * initialized during plugin load (same contract as the SMTP summary email
	 * and Support module schedules).
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( false !== as_next_scheduled_action( self::HOOK, array(), self::AS_GROUP ) ) {
			return;
		}

		try {
			$tz   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$date = new \DateTime( 'next sunday 3am', $tz );
		} catch ( \Exception $e ) {
			$date = new \DateTime( 'next sunday 3am' );
		}

		as_schedule_recurring_action(
			$date->getTimestamp(),
			WEEK_IN_SECONDS,
			self::HOOK,
			array(),
			self::AS_GROUP
		);
	}

	/**
	 * Remove association rows, task rows, and pointer columns that reference
	 * entities which no longer exist.
	 *
	 * @return void
	 */
	public static function sweep(): void {
		global $wpdb;

		$assoc_table = $wpdb->prefix . 'doublescale_activity_associations';
		$removed     = array();

		// Association rows whose entity row is gone. Pro tables are skipped
		// automatically when the pro plugin never created them.
		$entity_tables = array(
			ActivityAssociationModel::ENTITY_TYPE_DEAL     => 'doublescale_deals',
			ActivityAssociationModel::ENTITY_TYPE_CAMPAIGN => 'doublescale_campaigns',
			ActivityAssociationModel::ENTITY_TYPE_TICKET   => 'doublescale_support_tickets',
			ActivityAssociationModel::ENTITY_TYPE_TASK     => 'doublescale_tasks',
			ActivityAssociationModel::ENTITY_TYPE_CONTACT  => 'doublescale_contacts',
			ActivityAssociationModel::ENTITY_TYPE_PROPOSAL => 'doublescale_sales_proposals',
			ActivityAssociationModel::ENTITY_TYPE_INVOICE  => 'doublescale_sales_invoices',
			ActivityAssociationModel::ENTITY_TYPE_PROJECT  => 'doublescale_projects',
		);

		foreach ( $entity_tables as $entity_type => $table ) {
			$entity_table = $wpdb->prefix . $table;
			if ( ! self::table_exists( $entity_table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are plugin constants.
			$count = $wpdb->query(
				$wpdb->prepare(
					"DELETE s FROM {$assoc_table} s LEFT JOIN {$entity_table} e ON e.id = s.entity_id WHERE s.entity_type = %d AND e.id IS NULL",
					$entity_type
				)
			);
			if ( $count ) {
				$removed[ 'associations_type_' . $entity_type ] = (int) $count;
			}
		}

		// Polymorphic tasks whose parent entity is gone, then task child rows
		// whose task is gone (raw deletes here bypass model cascades on purpose:
		// the parents are already missing).
		$tasks_table  = $wpdb->prefix . 'doublescale_tasks';
		$task_parents = array(
			1 => 'doublescale_contacts',
			2 => 'doublescale_deals',
			3 => 'doublescale_projects',
		);
		if ( self::table_exists( $tasks_table ) ) {
			foreach ( $task_parents as $entity_type => $table ) {
				$parent_table = $wpdb->prefix . $table;
				if ( ! self::table_exists( $parent_table ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = $wpdb->query(
					$wpdb->prepare(
						"DELETE t FROM {$tasks_table} t LEFT JOIN {$parent_table} e ON e.id = t.entity_id WHERE t.entity_type = %d AND e.id IS NULL",
						$entity_type
					)
				);
				if ( $count ) {
					$removed[ 'tasks_type_' . $entity_type ] = (int) $count;
				}
			}

			$task_children = array(
				'doublescale_task_subtasks'           => 'task_id',
				'doublescale_task_subtask_groups'     => 'task_id',
				'doublescale_task_label_relationship' => 'task_id',
				'doublescale_task_recurrences'        => 'template_task_id',
			);
			foreach ( $task_children as $table => $column ) {
				$child_table = $wpdb->prefix . $table;
				if ( ! self::table_exists( $child_table ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = $wpdb->query(
					"DELETE c FROM {$child_table} c LEFT JOIN {$tasks_table} t ON t.id = c.{$column} WHERE t.id IS NULL"
				);
				if ( $count ) {
					$removed[ $table ] = (int) $count;
				}
			}
		}

		// Stale pointer columns: detach rather than delete (documents and
		// projects must survive their source entity).
		$pointer_repairs = array(
			array( 'doublescale_projects', 'deal_id', 'doublescale_deals' ),
			array( 'doublescale_sales_invoices', 'proposal_id', 'doublescale_sales_proposals' ),
		);
		foreach ( $pointer_repairs as $repair ) {
			list( $table, $column, $target ) = $repair;
			$source_table                    = $wpdb->prefix . $table;
			$target_table                    = $wpdb->prefix . $target;
			if ( ! self::table_exists( $source_table ) || ! self::table_exists( $target_table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->query(
				"UPDATE {$source_table} s LEFT JOIN {$target_table} t ON t.id = s.{$column} SET s.{$column} = NULL WHERE s.{$column} IS NOT NULL AND t.id IS NULL"
			);
			if ( $count ) {
				$removed[ $table . '.' . $column ] = (int) $count;
			}
		}

		// Activities that lost every association are unreachable by any
		// timeline; drop them once they are old enough to rule out an
		// in-flight create (activity row inserted, association pending).
		$activities_table = $wpdb->prefix . 'doublescale_activities';
		if ( self::table_exists( $activities_table ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->query(
				"DELETE a FROM {$activities_table} a LEFT JOIN {$assoc_table} s ON s.activity_id = a.id WHERE s.id IS NULL AND a.created_at < NOW() - INTERVAL 30 DAY"
			);
			if ( $count ) {
				$removed['activities'] = (int) $count;
			}
		}

		if ( ! empty( $removed ) && function_exists( 'doublescale_get_logger' ) ) {
			doublescale_get_logger()->info(
				'Orphan sweep removed dangling soft-association data',
				array(
					'source'  => 'association-orphan-sweep',
					'removed' => $removed,
				)
			);
		}
	}

	/**
	 * @param string $table Full table name (with prefix).
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		$like = $wpdb->esc_like( $table );

		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
		) === $table;
	}
}
