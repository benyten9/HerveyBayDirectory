<?php
/**
 * Registers project automation rules only when the projects module is active.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Project;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

final class ProjectRuleRegistration {

	/**
	 * @param Rule $rule Project rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'projects', ProjectModel::class );
	}

	/**
	 * Whether project storage is safe to query.
	 */
	public static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'projects', ProjectModel::class );
	}
}