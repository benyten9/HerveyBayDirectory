<?php
/**
 * Pro automation trigger (free plugin): definition only. Runtime hooks ship in DoubleScale Pro.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Modules\Automations\Triggers\Project;

defined( 'ABSPATH' ) || exit;

use DoubleScale\Modules\Automations\Abstracts\TriggerPro;
use DoubleScale\Modules\Automations\Services\TriggersManager;

/**
 * ProjectCommentPosted trigger stub.
 */
class ProjectCommentPosted extends TriggerPro {

	public $name = 'Project comment posted';

	public $slug = 'project_comment_posted';

	public $description = 'Fires when a comment or reply is posted on a project. Projects without a client contact cannot enroll.';

	public $attributes = array();

	public $source = 'projects';

	public $group = 'discussion';
}

TriggersManager::instance()->register( new ProjectCommentPosted() );
