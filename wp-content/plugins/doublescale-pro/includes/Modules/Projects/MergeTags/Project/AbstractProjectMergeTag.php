<?php
/**
 * Shared base for project automation merge tags.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Projects\MergeTags\Project;

use DoubleScale\Core\MergeTags\Abstracts\MergeTag;
use DoubleScale\Modules\Automations\Models\AutomationContactModel;
use DoubleScale\Pro\Modules\Automations\Support\ProjectContactResolver;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

abstract class AbstractProjectMergeTag extends MergeTag {

	/**
	 * @var string
	 */
	public $group = 'project';

	/**
	 * @var bool
	 */
	public $is_automation = true;

	/**
	 * @param mixed $contact Automation contact model.
	 * @return ProjectModel|null
	 */
	protected function resolve_project( $contact ): ?ProjectModel {
		if ( ! $contact instanceof AutomationContactModel ) {
			return null;
		}

		return ProjectContactResolver::resolve_from_automation_contact( $contact );
	}
}