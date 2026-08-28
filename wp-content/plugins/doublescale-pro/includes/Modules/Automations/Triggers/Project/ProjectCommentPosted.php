<?php
/**
 * Automation trigger: project comment posted.
 *
 * @package DoubleScale\Pro
 */

namespace DoubleScale\Pro\Modules\Automations\Triggers\Project;

use DoubleScale\Modules\Automations\Models\AutomationModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectDiscussionModel;
use DoubleScale\Pro\Modules\Projects\Models\ProjectModel;

defined( 'ABSPATH' ) || exit;

/**
 * ProjectCommentPosted trigger.
 */
class ProjectCommentPosted extends BaseProjectTrigger {

	/**
	 * @var string
	 */
	public $name = 'Project comment posted';

	/**
	 * @var string
	 */
	public $slug = 'project_comment_posted';

	/**
	 * Discussion group (alongside project).
	 *
	 * @var string
	 */
	public $group = 'discussion';

	/**
	 * @var string
	 */
	public $description = 'Fires when a comment or reply is posted on a project. Projects without a client contact cannot enroll.';

	/**
	 * @var array
	 */
	public $attributes = array();

	/**
	 * {@inheritdoc}
	 */
	public function load_hooks(): void {
		add_action( 'doublescale_project_comment_posted', array( $this, 'handle' ), 10, 3 );
	}

	/**
	 * @param mixed $project    Project model.
	 * @param mixed $discussion Discussion model.
	 * @param mixed $parent     Parent discussion or null.
	 */
	public function handle( $project, $discussion = null, $parent = null ): void {
		if ( ! $project instanceof ProjectModel ) {
			return;
		}
		if ( ! $discussion instanceof ProjectDiscussionModel ) {
			return;
		}

		$this->enroll(
			$project,
			array(
				'discussion_id' => (int) $discussion->id,
				'parent_id'     => $parent instanceof ProjectDiscussionModel ? (int) $parent->id : null,
				'is_reply'      => $parent instanceof ProjectDiscussionModel,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_processable( AutomationModel $automation, $args ) {
		return isset( $args['project'] ) && $args['project'] instanceof ProjectModel;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_fields() {
		return array();
	}
}