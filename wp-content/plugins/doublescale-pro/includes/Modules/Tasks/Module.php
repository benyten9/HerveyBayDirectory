<?php
/**
 * Tasks module bootstrap.
 *
 * Owns: Action Scheduler task helper (Tasks), task model, migrations, task
 * REST API, and task reminder scheduling.
 *
 * @package DoubleScale\Pro\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Core\AbstractModule;
use DoubleScale\Core\Container;

final class Module extends AbstractModule {

	public function slug(): string {
		return 'tasks';
	}

	public function label(): string {
		return __( 'Tasks', 'doublescale' );
	}

	public function description(): string {
		return __( 'Task management with reminders, assignments, and scheduling.', 'doublescale' );
	}

	public function version(): string {
		return '1.0.0';
	}

	public function is_toggleable(): bool {
		return true;
	}

	public function dependencies(): array {
		return array( 'core', 'contacts', 'deals', 'custom-fields' );
	}

	/**
	 * Migrations must run after base tables exist. Glob order would run
	 * AddColumnsToSubtasksTable before TaskSubtasksTable (alphabetical).
	 *
	 * @return array<int, string>
	 */
	public function migrations(): array {
		$dir = $this->module_dir() . '/Migrations';

		return array(
			$dir . '/TasksTable.php',
			$dir . '/TaskStatusesTable.php',
			$dir . '/TaskLabelsTable.php',
			$dir . '/TaskLabelRelationshipTable.php',
			$dir . '/TaskRecurrencesTable.php',
			$dir . '/TaskSubtasksTable.php',
			$dir . '/SubtaskGroupsTable.php',
			$dir . '/AddColumnsToSubtasksTable.php',
			$dir . '/AlterSubtasksDueDateToDatetime.php',
			$dir . '/AddNotesToSubtasksTable.php',
		);
	}

	public function register( Container $container ): void {
		$container->singleton(
			Reminders\TaskReminderScheduler::class,
			static fn() => Reminders\TaskReminderScheduler::instance()
		);
		$container->singleton(
			Reminders\TaskAutomationSweeper::class,
			static fn() => Reminders\TaskAutomationSweeper::instance()
		);
		$container->singleton(
			Recurrences\TaskRecurrenceScheduler::class,
			static fn() => Recurrences\TaskRecurrenceScheduler::instance()
		);
		$container->singleton(
			Services\TaskCalendarProvider::class,
			static fn() => new Services\TaskCalendarProvider()
		);
		$container->singleton(
			Services\TaskActivityLogger::class,
			static fn() => new Services\TaskActivityLogger()
		);
	}

	public function restControllers(): array {
		return array(
			Rest\Controllers\RestTaskController::class,
			Rest\Controllers\RestTaskStatusController::class,
		);
	}

	public function boot( Container $container ): void {
		parent::boot( $container );

		Migrations\AddColumnsToSubtasksTable::ensure_columns();
		Migrations\AlterSubtasksDueDateToDatetime::ensure();
		Migrations\AddNotesToSubtasksTable::ensure();
		Migrations\AddStatusIdToTasksTable::ensure();
		Migrations\RenameTaskStageIdToStatusId::ensure();
		Migrations\AddIsProtectedToTaskStatuses::ensure();
		Migrations\AddColumnsToTaskRecurrencesTable::ensure();

		$container->get( Reminders\TaskReminderScheduler::class );
		$container->get( Reminders\TaskAutomationSweeper::class );
		$container->get( Recurrences\TaskRecurrenceScheduler::class );

		// Admin/staff calendar bridge: contributes tasks (on due_date) to the
		// cross-module calendar feed (assigned-scoped for reps; all for managers).
		$container->get( Services\TaskCalendarProvider::class );

		$container->get( Services\TaskActivityLogger::class )->register();

		$this->loadModuleMergeTagFiles();
	}
}
