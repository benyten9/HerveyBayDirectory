<?php
/**
 * Registers deal automation rules only when deal storage is ready.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Rules\Deal;

use DoubleScale\Modules\Automations\Abstracts\Rule;
use DoubleScale\Pro\Modules\Automations\Support\AutomationModuleStorage;
use DoubleScale\Pro\Modules\Deals\Models\PipelineModel;

defined( 'ABSPATH' ) || exit;

final class DealRuleRegistration {

	/**
	 * @param Rule $rule Deal rule instance.
	 */
	public static function register( Rule $rule ): void {
		AutomationModuleStorage::register_rule( $rule, 'deals', PipelineModel::class );
	}

	/**
	 * Whether deal storage is safe to query.
	 */
	public static function storage_ready(): bool {
		return AutomationModuleStorage::is_ready( 'deals', PipelineModel::class );
	}
}
