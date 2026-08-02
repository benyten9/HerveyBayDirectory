<?php
/**
 * Registers task automation rules only when the tasks module is active.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Task;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

final class TaskRuleRegistration {

	/**
	 * @param Rule $rule Task rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'tasks', TaskModel::class );
	}

	/**
	 * Whether task storage is safe to query.
	 */
	public static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'tasks', TaskModel::class );
	}
}
