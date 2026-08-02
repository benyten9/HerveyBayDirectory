<?php
/**
 * Shared base for task automation merge tags.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Tasks\MergeTags\Task;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\TaskContactResolver;
use DoubleScale\Pro\Modules\Tasks\Models\TaskModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractTaskMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'task';

	/**
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * @param mixed $contact Automation contact model.
	 * @return TaskModel|null
	 */
	protected function resolve_task( $contact ): ?TaskModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}

		return TaskContactResolver::resolve_from_automation_contact( $contact );
	}
}
